<?php
declare(strict_types=1);

/**
 * Utilitário CLI de Migração Auditável do WordPress -> SpeedPress
 * 
 * Execução:
 * php bin/migrate-wp.php --dry-run --limit=10
 * php bin/migrate-wp.php --limit=10
 * php bin/migrate-wp.php --limit=100
 * php bin/migrate-wp.php --all
 */

$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\BlockCompiler;
use LightPress\Core\Database;
use LightPress\Core\Security;

echo "========================================================\n";
echo "   SpeedPress - Migrador Auditável do WordPress CLI    \n";
echo "========================================================\n\n";

// Analisar opções da linha de comando
$options = getopt('', [
    'dry-run',
    'wp-host::',
    'wp-port::',
    'wp-db::',
    'wp-user::',
    'wp-pass::',
    'prefix::',
    'limit::',
    'offset::',
    'chunk::',
    'all',
    'users-only',
    'asc',
    'verbose',
    'skip-existing',
    'post-id::',
]);

$isDryRun = isset($options['dry-run']);
$singlePostId = isset($options['post-id']) ? (int)$options['post-id'] : null;
$wpHost = $options['wp-host'] ?? ($config['database']['host'] ?? '127.0.0.1');
$wpPort = (int)($options['wp-port'] ?? ($config['database']['port'] ?? 3306));
$wpDb = $options['wp-db'] ?? 'suaint41_speed';
$wpUser = $options['wp-user'] ?? ($config['database']['username'] ?? ($config['database']['user'] ?? 'root'));
$wpPass = $options['wp-pass'] ?? ($config['database']['password'] ?? ($config['database']['pass'] ?? ''));
$isAll = isset($options['all']);
$limit = $singlePostId ? 1 : ($isAll ? 100000 : (int)($options['limit'] ?? 10));
$offset = (int)($options['offset'] ?? 0);
$chunkSize = (int)($options['chunk'] ?? 500);
if ($chunkSize < 50) $chunkSize = 50;
if ($chunkSize > 2000) $chunkSize = 2000;
$usersOnly = isset($options['users-only']);
$order = isset($options['asc']) ? 'ASC' : 'DESC';
$isVerbose = ($limit <= 20) || isset($options['verbose']) || ($singlePostId !== null);
$skipExisting = isset($options['skip-existing']);

echo "[Configuração de Conexão]\n";
echo "  Modo: " . ($isDryRun ? "DRY-RUN (Simulação - Nenhuma alteração será gravada)" : "PRODUÇÃO / GRAVAÇÃO REAL") . "\n";
echo "  Origem WordPress: {$wpUser}@{$wpHost}:{$wpPort}/{$wpDb}\n";
if ($singlePostId) {
    echo "  Alvo Específico: WP Artigo ID #{$singlePostId}\n";
} else {
    echo "  Ordenação: " . ($order === 'DESC' ? "Mais recentes primeiro (ID DESC)" : "Mais antigas primeiro (ID ASC)") . "\n";
    echo "  Lote de Notícias: " . ($isAll ? "TODAS (até 100.000)" : "{$limit} artigos (Offset: {$offset})") . "\n";
}
echo "  Modo de Exibição: " . ($isVerbose ? "Detalhado (Card a Card)" : "Alta Performance (Barra de Progresso com lotes de {$chunkSize})") . "\n";
if ($skipExisting) {
    echo "  Otimização: Pular artigos já migrados (--skip-existing ATIVADO)\n";
}
echo "\n";

// 1. Conexão com a base de dados do WordPress e Atualização de Schema
if (!$isDryRun) {
    try {
        $lpPdo = Database::getConnection();
        $driver = Database::getDriver();
        if ($driver === 'mysql') {
            $lpPdo->exec("ALTER TABLE posts MODIFY COLUMN title VARCHAR(500) NOT NULL");
            $lpPdo->exec("ALTER TABLE posts MODIFY COLUMN tags TEXT NULL");
            $lpPdo->exec("ALTER TABLE posts MODIFY COLUMN summary TEXT NULL");
            $lpPdo->exec("ALTER TABLE posts MODIFY COLUMN featured_image VARCHAR(500) NULL");
            $lpPdo->exec("ALTER TABLE posts MODIFY COLUMN seo_title VARCHAR(255) NULL");
            $lpPdo->exec("ALTER TABLE posts MODIFY COLUMN seo_description VARCHAR(500) NULL");
            $lpPdo->exec("ALTER TABLE posts MODIFY COLUMN focus_keyword VARCHAR(150) NULL");
            echo "[SpeedPress DB] Estrutura da tabela 'posts' verificada e atualizada com sucesso (title VARCHAR(500), tags TEXT, summary TEXT).\n";
        }
    } catch (\Throwable $e) {
        echo "[Aviso SpeedPress DB] Falha ao ajustar colunas: " . $e->getMessage() . "\n";
    }
}

try {
    $dsn = "mysql:host={$wpHost};port={$wpPort};dbname={$wpDb};charset=utf8mb4";
    $wpPdo = new PDO($dsn, $wpUser, $wpPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    echo "[OK] Conexão com o banco do WordPress '{$wpDb}' estabelecida com sucesso.\n";
} catch (\Throwable $e) {
    echo "[ERRO] Não foi possível conectar ao banco do WordPress: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Detecção Automática do Prefixo das Tabelas do WordPress
$candidatePrefixes = ['wp_speedracing_', 'wp_speedracing', 'wp_'];
if (!empty($options['prefix'])) {
    array_unshift($candidatePrefixes, $options['prefix']);
}

$wpPrefix = null;
foreach ($candidatePrefixes as $cand) {
    try {
        $check = $wpPdo->query("SHOW TABLES LIKE '{$cand}posts'")->fetchColumn();
        if ($check) {
            $wpPrefix = $cand;
            break;
        }
    } catch (\Throwable $e) {
        continue;
    }
}

if (!$wpPrefix) {
    // Listar tabelas disponíveis para auxiliar diagnóstico
    $tables = $wpPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "[ERRO] Não foi possível encontrar a tabela 'posts' no banco do WordPress.\n";
    echo "Tabelas encontradas no banco:\n";
    foreach (array_slice($tables, 0, 15) as $t) {
        echo "  - {$t}\n";
    }
    echo "Informe o prefixo exato via argumento: --prefix=seu_prefixo_\n";
    exit(1);
}

echo "[OK] Prefixo de tabelas identificado: '{$wpPrefix}'\n\n";

$tblPosts = "{$wpPrefix}posts";
$tblUsers = "{$wpPrefix}users";
$tblUsermeta = "{$wpPrefix}usermeta";
$tblPostmeta = "{$wpPrefix}postmeta";
$tblTerms = "{$wpPrefix}terms";
$tblTermTax = "{$wpPrefix}term_taxonomy";
$tblTermRel = "{$wpPrefix}term_relationships";

// Detecção avançada de tabelas do Independent Analytics e estatísticas
$allTables = $wpPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$iaPagesTable = null;
$iaViewsTable = null;
$iaResourcesTable = null;
$iaResourcePostIdCol = null;
$analyticsTables = [];

foreach ($allTables as $tblName) {
    $tLower = strtolower($tblName);
    if (str_contains($tLower, 'ia_') || str_contains($tLower, 'analytics') || str_contains($tLower, 'view')) {
        $analyticsTables[] = $tblName;
    }
    if (str_ends_with($tLower, 'independent_analytics_views') || str_ends_with($tLower, 'ia_views')) {
        $iaViewsTable = $tblName;
    }
    if (str_ends_with($tLower, 'independent_analytics_resources') || str_ends_with($tLower, 'ia_resources')) {
        $iaResourcesTable = $tblName;
    }
    if (str_ends_with($tLower, 'independent_analytics_pages') || str_ends_with($tLower, 'ia_pages')) {
        $iaPagesTable = $tblName;
    }
}

if (!empty($analyticsTables)) {
    echo "[Info Analytics] Tabelas de estatísticas encontradas no banco:\n";
    foreach ($analyticsTables as $at) {
        echo "  - {$at}\n";
    }
}
if ($iaViewsTable) {
    try {
        $viewsCols = $wpPdo->query("SHOW COLUMNS FROM {$iaViewsTable}")->fetchAll(PDO::FETCH_COLUMN);
        echo "[Info Analytics] Tabela de visualizações: {$iaViewsTable} (" . implode(', ', $viewsCols) . ")\n";
    } catch (\Throwable $e) {}
}
if ($iaResourcesTable) {
    try {
        $resCols = $wpPdo->query("SHOW COLUMNS FROM {$iaResourcesTable}")->fetchAll(PDO::FETCH_COLUMN);
        echo "[Info Analytics] Tabela de recursos: {$iaResourcesTable} (" . implode(', ', $resCols) . ")\n";
        foreach (['singular_id', 'post_id', 'resource_id', 'object_id', 'wp_id'] as $cand) {
            if (in_array($cand, $resCols, true)) {
                $iaResourcePostIdCol = $cand;
                break;
            }
        }
        if ($iaResourcePostIdCol) {
            echo "[Info Analytics] Coluna identificadora do artigo em '{$iaResourcesTable}': '{$iaResourcePostIdCol}'\n";
        }
    } catch (\Throwable $e) {}
}

// Detecção de plugin secundário (Post Views Counter / SPVC)
$pvcTable = null;
$pvcPostIdCol = null;
$pvcCountCol = null;
foreach ($allTables as $tblName) {
    if (str_contains(strtolower($tblName), 'post_views_count')) {
        $pvcTable = $tblName;
        break;
    }
}
if ($pvcTable) {
    try {
        $pvcCols = $wpPdo->query("SHOW COLUMNS FROM {$pvcTable}")->fetchAll(PDO::FETCH_COLUMN);
        echo "[Info Analytics] Tabela Post Views Counter: {$pvcTable} (" . implode(', ', $pvcCols) . ")\n";
        foreach (['post_id', 'id', 'post_ID', 'object_id'] as $cand) {
            if (in_array($cand, $pvcCols, true)) {
                $pvcPostIdCol = $cand;
                break;
            }
        }
        foreach (['count', 'views', 'post_views', 'views_count'] as $cand) {
            if (in_array($cand, $pvcCols, true)) {
                $pvcCountCol = $cand;
                break;
            }
        }
    } catch (\Throwable $e) {}
}
echo "\n";

// 3. Migração e Mapeamento de Usuários
echo "========================================================\n";
echo "  [Etapa 1] Migração dos Usuários e Autores\n";
echo "========================================================\n";

$wpUsers = $wpPdo->query("SELECT ID, user_login, user_email, display_name, user_registered, user_pass FROM {$tblUsers}")->fetchAll();
echo "Total de usuários encontrados no WordPress: " . count($wpUsers) . "\n";

$userMap = []; // [wp_user_id => lp_user_id]

foreach ($wpUsers as $u) {
    $wpUid = (int)$u['ID'];
    $login = trim($u['user_login']);
    $email = trim($u['user_email']);
    $name = !empty($u['display_name']) ? trim($u['display_name']) : $login;
    $registered = !empty($u['user_registered']) ? $u['user_registered'] : date('Y-m-d H:i:s');
    $passHash = (string)$u['user_pass'];

    // Buscar biografia no usermeta
    $bioStmt = $wpPdo->prepare("SELECT meta_value FROM {$tblUsermeta} WHERE user_id = ? AND meta_key = 'description' LIMIT 1");
    $bioStmt->execute([$wpUid]);
    $bio = (string)($bioStmt->fetchColumn() ?: '');

    // Definição de Papel (Role)
    // carlos -> admin (DONO)
    // robsonmiranda -> editor
    // demais -> author
    $loginLower = strtolower($login);
    if ($loginLower === 'carlos') {
        $role = 'admin';
    } elseif ($loginLower === 'robsonmiranda' || str_contains($loginLower, 'robson')) {
        $role = 'editor';
    } else {
        $role = 'author';
    }

    echo "  > WP Usuário #{$wpUid}: {$login} ({$email}) -> Role: {$role}";

    if ($isDryRun) {
        $userMap[$wpUid] = 1;
        echo " [SIMULAÇÃO OK]\n";
        continue;
    }

    // Verificar se já existe no LightPress (por username ou email)
    $existing = Database::fetchOne(
        "SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1",
        [$login, $email]
    );

    if ($existing) {
        $lpUid = (int)$existing['id'];
        // Atualizar papel se necessário
        Database::query("UPDATE users SET role = ?, bio = IF(bio IS NULL OR bio = '', ?, bio) WHERE id = ?", [$role, $bio, $lpUid]);
        $userMap[$wpUid] = $lpUid;
        echo " -> Vinculado ao SpeedPress ID #{$lpUid}\n";
    } else {
        // Inserir novo usuário
        // Se a senha for hash MD5 ou PHPass do WordPress ($P$ ou $2y$ ou Argon2), mantemos compatível
        $secureHash = str_starts_with($passHash, '$argon2id$') ? $passHash : Security::hashPassword(bin2hex(random_bytes(10)));
        
        Database::query(
            "INSERT INTO users (name, username, email, password_hash, role, is_active, password_reset_required, session_version, bio, created_at)
             VALUES (?, ?, ?, ?, ?, 1, 0, 1, ?, ?)",
            [$name, $login, $email, $secureHash, $role, $bio, $registered]
        );
        $lpUid = (int)Database::lastInsertId();
        $userMap[$wpUid] = $lpUid;
        echo " -> CRIADO com SpeedPress ID #{$lpUid}\n";
    }
}

if ($usersOnly) {
    echo "\n[OK] Migração de usuários concluída com sucesso (--users-only).\n";
    exit(0);
}

// 4. Migração das Notícias (Lote Piloto)
echo "\n========================================================\n";
echo "  [Etapa 2] Migração de Notícias e Conteúdo Relacionado\n";
echo "========================================================\n";

if ($singlePostId) {
    $countEligible = 1;
    $totalTarget = 1;
    echo "Processando artigo avulso: WP ID #{$singlePostId}...\n\n";
} else {
    $countEligible = (int)$wpPdo->query("SELECT COUNT(*) FROM {$tblPosts} WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending', 'future', 'trash')")->fetchColumn();
    echo "Total geral de artigos elegíveis no WordPress: {$countEligible}\n";
    $totalTarget = min($limit, $countEligible);
    echo "Artigos a processar nesta execução: {$totalTarget} (Offset {$offset}, Lotes de {$chunkSize})...\n\n";
}

// 4.1 Pré-compilação e Otimização de Consultas (Prepared Statements Reuse)
$taxStmt = $wpPdo->prepare(
    "SELECT t.name, t.slug, tt.taxonomy 
     FROM {$tblTermRel} tr
     JOIN {$tblTermTax} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
     JOIN {$tblTerms} t ON t.term_id = tt.term_id
     WHERE tr.object_id = ?"
);
$metaThumbStmt = $wpPdo->prepare("SELECT meta_value FROM {$tblPostmeta} WHERE post_id = ? AND meta_key = '_thumbnail_id' LIMIT 1");
$fileStmt = $wpPdo->prepare("SELECT meta_value FROM {$tblPostmeta} WHERE post_id = ? AND meta_key = '_wp_attached_file' LIMIT 1");
$guidStmt = $wpPdo->prepare("SELECT guid FROM {$tblPosts} WHERE ID = ? LIMIT 1");

$seoStmt = $wpPdo->prepare(
    "SELECT meta_key, meta_value FROM {$tblPostmeta} 
     WHERE post_id = ? AND meta_key IN (
         '_siteseo_titles_title', '_seopress_titles_title', '_yoast_wpseo_title',
         '_siteseo_titles_desc', '_seopress_titles_desc', '_yoast_wpseo_metadesc',
         '_siteseo_analysis_target_kw', '_seopress_analysis_target_kw', '_yoast_wpseo_focuskw'
     )"
);

$iaStmt = null;
if ($iaViewsTable && $iaResourcesTable && $iaResourcePostIdCol) {
    try {
        $iaStmt = $wpPdo->prepare(
            "SELECT COUNT(*) FROM {$iaViewsTable} v 
             JOIN {$iaResourcesTable} r ON r.id = v.resource_id 
             WHERE r.{$iaResourcePostIdCol} = ?"
        );
    } catch (\Throwable $e) {}
}

$iaDirectStmt = null;
if ($iaViewsTable) {
    try {
        $iaDirectStmt = $wpPdo->prepare("SELECT COUNT(*) FROM {$iaViewsTable} WHERE resource_id = ?");
    } catch (\Throwable $e) {}
}

$pvcStmt = null;
if ($pvcTable && $pvcPostIdCol && $pvcCountCol) {
    try {
        $pvcStmt = $wpPdo->prepare("SELECT SUM({$pvcCountCol}) FROM {$pvcTable} WHERE {$pvcPostIdCol} = ?");
    } catch (\Throwable $e) {}
}

$viewsStmt = $wpPdo->prepare("SELECT meta_value FROM {$tblPostmeta} WHERE post_id = ? AND meta_key IN ('views', 'post_views_count', '_post_views_count', 'ia_views') LIMIT 1");

// Pré-carregar categorias existentes e preparar statements do SpeedPress
$knownCategories = [];
$checkMapStmt = null;
$updatePostStmt = null;
$insertPostStmt = null;
$upsertStatsStmt = null;
$insertMapStmt = null;
$insertRedirectStmt = null;
$insertCatStmt = null;

if (!$isDryRun) {
    try {
        $existingCats = Database::fetchAll("SELECT slug FROM categories");
        foreach ($existingCats as $ec) {
            $knownCategories[$ec['slug']] = true;
        }
    } catch (\Throwable $e) {}

    $lpConn = Database::getConnection();
    $insertCatStmt = $lpConn->prepare("INSERT IGNORE INTO categories (slug, name, is_active) VALUES (?, ?, 1)");
    $checkMapStmt = $lpConn->prepare("SELECT target_id FROM migration_map WHERE source = 'wordpress' AND entity_type = 'post' AND source_id = ? LIMIT 1");
    $updatePostStmt = $lpConn->prepare(
        "UPDATE posts SET 
            user_id = ?, slug = ?, title = ?, summary = ?, category = ?, tags = ?,
            featured_image = ?, content_json = ?, content_html = ?, status = ?,
            is_featured = ?,
            seo_title = ?, seo_description = ?, focus_keyword = ?, published_at = ?, updated_at = ?
         WHERE id = ?"
    );
    $insertPostStmt = $lpConn->prepare(
        "INSERT INTO posts (
            user_id, slug, title, summary, category, tags, featured_image,
            content_json, content_html, status, is_featured, version,
            seo_title, seo_description, focus_keyword, published_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, 1,
            ?, ?, ?, ?, ?
        )"
    );
    $upsertStatsStmt = $lpConn->prepare(
        "INSERT INTO post_stats (post_id, views) VALUES (?, ?) ON DUPLICATE KEY UPDATE views = ?"
    );
    $insertMapStmt = $lpConn->prepare(
        "INSERT INTO migration_map (source, entity_type, source_id, target_id, status) VALUES ('wordpress', 'post', ?, ?, 'completed')"
    );
    $insertRedirectStmt = $lpConn->prepare(
        "INSERT IGNORE INTO redirects (old_path, path_hash, target_path, status_code) VALUES (?, ?, ?, 301)"
    );
}

// 4.2 Execução em Streaming por Chunks de Memória Controlada
if ($singlePostId) {
    $postsQuery = $wpPdo->prepare(
        "SELECT ID, post_author, post_date_gmt, post_date, post_content, post_title, post_excerpt, post_status, post_name, post_modified
         FROM {$tblPosts}
         WHERE ID = :single_id
         LIMIT 1"
    );
    $postsQuery->bindValue(':single_id', $singlePostId, PDO::PARAM_INT);
} else {
    $postsQuery = $wpPdo->prepare(
        "SELECT ID, post_author, post_date_gmt, post_date, post_content, post_title, post_excerpt, post_status, post_name, post_modified
         FROM {$tblPosts}
         WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending', 'future', 'trash')
         ORDER BY ID {$order}
         LIMIT :limit OFFSET :offset"
    );
}

$processed = 0;
$converted = 0;
$skipped = 0;
$alreadyExisted = 0;
$currentOffset = $offset;
$startTime = microtime(true);

while ($processed < $totalTarget) {
    if ($singlePostId) {
        $postsQuery->execute();
        $articles = $postsQuery->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $batchLimit = min($chunkSize, $totalTarget - $processed);
        $postsQuery->bindValue(':limit', $batchLimit, PDO::PARAM_INT);
        $postsQuery->bindValue(':offset', $currentOffset, PDO::PARAM_INT);
        $postsQuery->execute();
        $articles = $postsQuery->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($articles)) {
        break;
    }

    foreach ($articles as $p) {
        $wpPostId = (int)$p['ID'];

        // Otimização: pular registros já migrados se --skip-existing estiver ativo
        if ($skipExisting && !$isDryRun && $checkMapStmt) {
            $checkMapStmt->execute([(string)$wpPostId]);
            if ($checkMapStmt->fetchColumn()) {
                $alreadyExisted++;
                $processed++;
                $currentOffset++;
                continue;
            }
        }

        $title = trim($p['post_title']);
        if (empty($title)) {
            $title = "Publicação #{$wpPostId}";
        }

        $slug = !empty($p['post_name']) ? Security::slugify($p['post_name']) : Security::slugify($title);
        $rawContent = (string)$p['post_content'];
        $summary = !empty($p['post_excerpt']) ? trim($p['post_excerpt']) : null;
        $wpAuthorId = (int)$p['post_author'];
        $targetUserId = $userMap[$wpAuthorId] ?? (reset($userMap) ?: 1);

        // Mapeamento de Status
        $status = match ($p['post_status']) {
            'publish' => 'published',
            'future' => 'scheduled',
            'pending' => 'pending_review',
            'trash' => 'archived',
            default => 'draft',
        };

        $publishedAt = ($status === 'published' || $status === 'scheduled')
            ? (!empty($p['post_date_gmt']) && $p['post_date_gmt'] !== '0000-00-00 00:00:00' ? $p['post_date_gmt'] : $p['post_date'])
            : null;

        $updatedAt = !empty($p['post_modified']) && $p['post_modified'] !== '0000-00-00 00:00:00' ? $p['post_modified'] : ($publishedAt ?: date('Y-m-d H:i:s'));

        // Taxonomias (Categorias, Tags e Destaques)
        $taxStmt->execute([$wpPostId]);
        $termsData = $taxStmt->fetchAll();

        $categorySlug = 'geral';
        $categoriesList = [];
        $tagsList = [];
        $isFeatured = 0;

        foreach ($termsData as $tRow) {
            if ($tRow['taxonomy'] === 'category') {
                $catSlug = Security::slugify($tRow['slug']);
                if (in_array($catSlug, ['destaque', 'destaques', 'destaques-home', 'featured'], true)) {
                    $isFeatured = 1;
                    continue;
                }
                if (!empty($catSlug) && !in_array($catSlug, ['sem-categoria', 'uncategorized'], true)) {
                    $categoriesList[] = [
                        'slug' => $catSlug,
                        'name' => trim($tRow['name']),
                    ];
                }
            } elseif ($tRow['taxonomy'] === 'post_tag') {
                $tagName = trim($tRow['name']);
                if (!empty($tagName)) {
                    $tagsList[] = $tagName;
                }
            }
        }

        // Categoria principal e secundárias
        if (!empty($categoriesList)) {
            $primaryCat = array_shift($categoriesList);
            $categorySlug = $primaryCat['slug'];
            if (!$isDryRun && empty($knownCategories[$primaryCat['slug']]) && $insertCatStmt) {
                try {
                    $insertCatStmt->execute([$primaryCat['slug'], $primaryCat['name']]);
                    $knownCategories[$primaryCat['slug']] = true;
                } catch (\Throwable $e) {}
            }
            foreach ($categoriesList as $secCat) {
                $tagsList[] = $secCat['name'];
                if (!$isDryRun && empty($knownCategories[$secCat['slug']]) && $insertCatStmt) {
                    try {
                        $insertCatStmt->execute([$secCat['slug'], $secCat['name']]);
                        $knownCategories[$secCat['slug']] = true;
                    } catch (\Throwable $e) {}
                }
            }
        }

        $tagsString = !empty($tagsList) ? implode(', ', array_unique($tagsList)) : null;

        // Imagem Destacada
        $metaThumbStmt->execute([$wpPostId]);
        $thumbAttachmentId = (int)$metaThumbStmt->fetchColumn();

        $featuredImage = null;
        if ($thumbAttachmentId > 0) {
            $fileStmt->execute([$thumbAttachmentId]);
            $attachedFile = $fileStmt->fetchColumn();

            if (!empty($attachedFile)) {
                $featuredImage = "/uploads/" . ltrim($attachedFile, '/');
            } else {
                $guidStmt->execute([$thumbAttachmentId]);
                $guid = (string)$guidStmt->fetchColumn();
                if (!empty($guid)) {
                    $parsed = parse_url($guid, PHP_URL_PATH);
                    if ($parsed && str_contains($parsed, '/uploads/')) {
                        $featuredImage = substr($parsed, strpos($parsed, '/uploads/'));
                    }
                }
            }
        }

        // SEO
        $seoStmt->execute([$wpPostId]);
        $seoRows = $seoStmt->fetchAll();
        $seoTitle = null;
        $seoDesc = null;
        $focusKw = null;

        foreach ($seoRows as $sr) {
            $k = $sr['meta_key'];
            $v = trim((string)$sr['meta_value']);
            if (empty($v)) continue;

            if (in_array($k, ['_siteseo_titles_title', '_seopress_titles_title', '_yoast_wpseo_title'], true) && empty($seoTitle)) {
                $seoTitle = $v;
            } elseif (in_array($k, ['_siteseo_titles_desc', '_seopress_titles_desc', '_yoast_wpseo_metadesc'], true) && empty($seoDesc)) {
                $seoDesc = $v;
            } elseif (in_array($k, ['_siteseo_analysis_target_kw', '_seopress_analysis_target_kw', '_yoast_wpseo_focuskw'], true) && empty($focusKw)) {
                $focusKw = $v;
            }
        }

        // Limpeza de Conteúdo Legado e Blocos
        $cleanHtml = $rawContent;
        $cleanHtml = preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', $cleanHtml);
        $cleanHtml = preg_replace('/\[caption[^\]]*\](.*?)\[\/caption\]/is', '$1', $cleanHtml);
        $cleanHtml = preg_replace('/\[\/?[a-zA-Z0-9_-]+[^\]]*\]/', '', $cleanHtml);

        $sanitizedHtml = BlockCompiler::sanitizeHtml($cleanHtml);
        $contentJson = BlockCompiler::htmlToBlocks($sanitizedHtml);

        // Resumo Automático se vazio
        if (empty($summary) && !empty($sanitizedHtml)) {
            $plainText = strip_tags($sanitizedHtml);
            $plainText = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plainText = preg_replace('/\s+/', ' ', $plainText);
            $plainText = trim($plainText);
            if (!empty($plainText)) {
                $summary = mb_strlen($plainText, 'UTF-8') > 180 
                    ? mb_substr($plainText, 0, 177, 'UTF-8') . '...' 
                    : $plainText;
            }
        }

        // Visualizações (Independent Analytics / PVC / Postmeta)
        $views = 0;
        if ($iaStmt) {
            try {
                $iaStmt->execute([$wpPostId]);
                $views = (int)$iaStmt->fetchColumn();
            } catch (\Throwable $e) {}
        }
        if ($views === 0 && $iaDirectStmt) {
            try {
                $iaDirectStmt->execute([$wpPostId]);
                $directCount = (int)$iaDirectStmt->fetchColumn();
                if ($directCount > 0) $views = $directCount;
            } catch (\Throwable $e) {}
        }
        if ($views === 0 && $pvcStmt) {
            try {
                $pvcStmt->execute([$wpPostId]);
                $pvcCount = (int)$pvcStmt->fetchColumn();
                if ($pvcCount > 0) $views = $pvcCount;
            } catch (\Throwable $e) {}
        }
        if ($views === 0) {
            try {
                $viewsStmt->execute([$wpPostId]);
                $metaViews = $viewsStmt->fetchColumn();
                if ($metaViews !== false && is_numeric($metaViews)) {
                    $views = (int)$metaViews;
                }
            } catch (\Throwable $e) {}
        }

        // Exibição Detalhada (se modo verbose ou lote pequeno <= 20)
        if ($isVerbose) {
            $blockCount = 0;
            if (!empty($contentJson)) {
                $decodedBlocks = json_decode($contentJson, true);
                if (is_array($decodedBlocks)) $blockCount = count($decodedBlocks);
            }
            $contentChars = mb_strlen(strip_tags($sanitizedHtml), 'UTF-8');
            $featuredBadge = $isFeatured ? " [⭐ DESTAQUE DA HOME]" : "";

            echo "--------------------------------------------------------\n";
            echo "WP #{$wpPostId}: {$title}{$featuredBadge}\n";
            echo "  Slug: /{$slug}/\n";
            echo "  Categoria: {$categorySlug} | Tags: " . ($tagsString ?: '(nenhuma)') . "\n";
            echo "  Autor ID: #{$targetUserId} | Status: {$status} | Data: " . ($publishedAt ?: 'N/A') . " | Views: {$views}\n";
            if (!empty($summary)) {
                $previewSummary = mb_strlen($summary, 'UTF-8') > 90 ? mb_substr($summary, 0, 87, 'UTF-8') . '...' : $summary;
                echo "  Resumo: \"{$previewSummary}\"\n";
            } else {
                echo "  Resumo: (sem resumo)\n";
            }
            echo "  Conteúdo: {$contentChars} caracteres | {$blockCount} blocos de editor\n";
            echo "  Foto Capa: " . ($featuredImage ?: '(sem imagem)') . "\n";
            if ($seoTitle || $focusKw) {
                echo "  SEO: [Kw: {$focusKw}] Title: {$seoTitle}\n";
            }
        }

        if ($isDryRun) {
            $converted++;
            $processed++;
            $currentOffset++;
            continue;
        }

        // Gravação Transacional de Alta Velocidade no SpeedPress
        try {
            Database::transaction(function (\PDO $lpPdo) use (
                $wpPostId, $targetUserId, $slug, $title, $summary, $categorySlug, $tagsString,
                $featuredImage, $contentJson, $sanitizedHtml, $status, $isFeatured, $publishedAt, $updatedAt,
                $seoTitle, $seoDesc, $focusKw, $views,
                $checkMapStmt, $updatePostStmt, $insertPostStmt, $upsertStatsStmt, $insertMapStmt, $insertRedirectStmt
            ): void {
                $checkMapStmt->execute([(string)$wpPostId]);
                $existingTargetId = $checkMapStmt->fetchColumn();

                if ($existingTargetId) {
                    $targetId = (int)$existingTargetId;
                    $updatePostStmt->execute([
                        $targetUserId, $slug, $title, $summary, $categorySlug, $tagsString,
                        $featuredImage, $contentJson, $sanitizedHtml, $status,
                        $isFeatured,
                        $seoTitle, $seoDesc, $focusKw, $publishedAt, $updatedAt,
                        $targetId
                    ]);
                    $upsertStatsStmt->execute([$targetId, $views, $views]);
                    return;
                }

                $insertPostStmt->execute([
                    $targetUserId, $slug, $title, $summary, $categorySlug, $tagsString,
                    $featuredImage, $contentJson, $sanitizedHtml, $status, $isFeatured,
                    $seoTitle, $seoDesc, $focusKw, $publishedAt, $updatedAt
                ]);
                $newPostId = (int)$lpPdo->lastInsertId();

                $upsertStatsStmt->execute([$newPostId, $views, $views]);
                $insertMapStmt->execute([(string)$wpPostId, (string)$newPostId]);

                $oldPath = "/?p={$wpPostId}";
                $pathHash = hash('sha256', $oldPath);
                $insertRedirectStmt->execute([$oldPath, $pathHash, "/{$slug}/"]);
            });

            if ($isVerbose) {
                echo "  -> [SUCESSO] Migrado com sucesso para o SpeedPress!\n";
            }
            $converted++;
        } catch (\Throwable $e) {
            if ($isVerbose) {
                echo "  -> [ERRO] Falha ao gravar WP #{$wpPostId}: " . $e->getMessage() . "\n";
            } else {
                echo "\n  -> [ERRO] WP #{$wpPostId}: " . $e->getMessage() . "\n";
            }
            $skipped++;
        }

        $processed++;
        $currentOffset++;

        // Barra de Progresso em Tempo Real (para lotes médios e grandes)
        if (!$isVerbose) {
            if ($processed % 25 === 0 || $processed === $totalTarget) {
                $elapsed = microtime(true) - $startTime;
                $speed = $elapsed > 0 ? round($processed / $elapsed, 1) : 0;
                $percent = round(($processed / $totalTarget) * 100, 1);
                $remainingSec = $speed > 0 ? round(($totalTarget - $processed) / $speed) : 0;
                $remMin = floor($remainingSec / 60);
                $remSec = $remainingSec % 60;
                $remStr = sprintf("%02dm%02ds", $remMin, $remSec);
                
                echo "\r[Migrando] {$processed}/{$totalTarget} ({$percent}%) | Velocidade: {$speed} art/s | Sucessos: {$converted} | Erros: {$skipped} | Restante: ~{$remStr}   ";
                flush();
            }
        }
    }

    gc_collect_cycles();
}

$totalElapsed = microtime(true) - $startTime;
$avgSpeed = $totalElapsed > 0 ? round($processed / $totalElapsed, 1) : 0;
$totalMin = floor($totalElapsed / 60);
$totalSec = round($totalElapsed - ($totalMin * 60));

echo "\n\n========================================================\n";
echo "   Resumo da Migração SpeedPress\n";
echo "========================================================\n";
echo "Tempo Total: {$totalMin}m {$totalSec}s (Média: {$avgSpeed} artigos/segundo)\n";
echo "Artigos processados: {$processed}\n";
echo "Migrados / Atualizados: {$converted}\n";
if ($alreadyExisted > 0) {
    echo "Pulados já existentes: {$alreadyExisted}\n";
}
echo "Falhas / Ignorados: {$skipped}\n";
echo "========================================================\n\n";

if ($isDryRun) {
    echo "Execute sem a flag --dry-run para aplicar a gravação real:\n";
    echo "php bin/migrate-wp.php --limit=1000\n\n";
}
