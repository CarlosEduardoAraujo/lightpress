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
    'all',
    'users-only',
]);

$isDryRun = isset($options['dry-run']);
$wpHost = $options['wp-host'] ?? '127.0.0.1';
$wpPort = (int)($options['wp-port'] ?? 3306);
$wpDb = $options['wp-db'] ?? 'suaint41_speed';
$wpUser = $options['wp-user'] ?? ($config['database']['user'] ?? 'root');
$wpPass = $options['wp-pass'] ?? ($config['database']['password'] ?? '');
$isAll = isset($options['all']);
$limit = $isAll ? 100000 : (int)($options['limit'] ?? 10);
$offset = (int)($options['offset'] ?? 0);
$usersOnly = isset($options['users-only']);

echo "[Configuração de Conexão]\n";
echo "  Modo: " . ($isDryRun ? "DRY-RUN (Simulação - Nenhuma alteração será gravada)" : "PRODUÇÃO / GRAVAÇÃO REAL") . "\n";
echo "  Origem WordPress: {$wpUser}@{$wpHost}:{$wpPort}/{$wpDb}\n";
echo "  Lote de Notícias: " . ($isAll ? "TODAS (até 100.000)" : "{$limit} artigos (Offset: {$offset})") . "\n\n";

// 1. Conexão com a base de dados do WordPress
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

$countEligible = (int)$wpPdo->query("SELECT COUNT(*) FROM {$tblPosts} WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending', 'future', 'trash')")->fetchColumn();
echo "Total geral de artigos elegíveis no WordPress: {$countEligible}\n";
echo "Extraindo lote de {$limit} artigos (Offset {$offset})...\n\n";

$postsStmt = $wpPdo->prepare(
    "SELECT ID, post_author, post_date_gmt, post_date, post_content, post_title, post_excerpt, post_status, post_name, post_modified
     FROM {$tblPosts}
     WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending', 'future', 'trash')
     ORDER BY ID ASC
     LIMIT :limit OFFSET :offset"
);
$postsStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$postsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$postsStmt->execute();
$articles = $postsStmt->fetchAll();

$converted = 0;
$skipped = 0;

foreach ($articles as $p) {
    $wpPostId = (int)$p['ID'];
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

    // 4.1 Mapeamento de Categorias e Tags (Taxonomias do WordPress)
    $taxStmt = $wpPdo->prepare(
        "SELECT t.name, t.slug, tt.taxonomy 
         FROM {$tblTermRel} tr
         JOIN {$tblTermTax} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
         JOIN {$tblTerms} t ON t.term_id = tt.term_id
         WHERE tr.object_id = ?"
    );
    $taxStmt->execute([$wpPostId]);
    $termsData = $taxStmt->fetchAll();

    $categorySlug = 'geral';
    $tagsList = [];

    foreach ($termsData as $tRow) {
        if ($tRow['taxonomy'] === 'category') {
            $catCandidate = Security::slugify($tRow['slug']);
            if (!empty($catCandidate) && $catCandidate !== 'sem-categoria' && $catCandidate !== 'uncategorized') {
                $categorySlug = $catCandidate;
                
                // Se não for simulação, garantir que a categoria existe no SpeedPress
                if (!$isDryRun) {
                    Database::query(
                        "INSERT IGNORE INTO categories (slug, name, is_active) VALUES (?, ?, 1)",
                        [$categorySlug, trim($tRow['name'])]
                    );
                }
            }
        } elseif ($tRow['taxonomy'] === 'post_tag') {
            $tagName = trim($tRow['name']);
            if (!empty($tagName)) {
                $tagsList[] = $tagName;
            }
        }
    }

    $tagsString = !empty($tagsList) ? implode(', ', array_unique($tagsList)) : null;

    // 4.2 Imagem Destacada (Featured Image / Thumbnail)
    $metaThumbStmt = $wpPdo->prepare("SELECT meta_value FROM {$tblPostmeta} WHERE post_id = ? AND meta_key = '_thumbnail_id' LIMIT 1");
    $metaThumbStmt->execute([$wpPostId]);
    $thumbAttachmentId = (int)$metaThumbStmt->fetchColumn();

    $featuredImage = null;
    if ($thumbAttachmentId > 0) {
        // Buscar arquivo anexo
        $fileStmt = $wpPdo->prepare("SELECT meta_value FROM {$tblPostmeta} WHERE post_id = ? AND meta_key = '_wp_attached_file' LIMIT 1");
        $fileStmt->execute([$thumbAttachmentId]);
        $attachedFile = $fileStmt->fetchColumn();

        if (!empty($attachedFile)) {
            $featuredImage = "/uploads/" . ltrim($attachedFile, '/');
        } else {
            // Tentar guid do attachment
            $guidStmt = $wpPdo->prepare("SELECT guid FROM {$tblPosts} WHERE ID = ? LIMIT 1");
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

    // 4.3 SEO - Suporte ao SiteSEO Pro / SEOPress / Yoast
    $seoStmt = $wpPdo->prepare(
        "SELECT meta_key, meta_value FROM {$tblPostmeta} 
         WHERE post_id = ? AND meta_key IN (
             '_siteseo_titles_title', '_seopress_titles_title', '_yoast_wpseo_title',
             '_siteseo_titles_desc', '_seopress_titles_desc', '_yoast_wpseo_metadesc',
             '_siteseo_analysis_target_kw', '_seopress_analysis_target_kw', '_yoast_wpseo_focuskw'
         )"
    );
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

    // 4.4 Limpeza de Conteúdo Legado (Gutenberg, shortcodes do WP e Sanitização)
    $cleanHtml = $rawContent;
    // Remover comentários de bloco Gutenberg
    $cleanHtml = preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', $cleanHtml);
    // Remover shortcodes de caption do WordPress [caption ...]...[/caption]
    $cleanHtml = preg_replace('/\[caption[^\]]*\](.*?)\[\/caption\]/is', '$1', $cleanHtml);
    // Remover outros shortcodes genéricos [shortcode]
    $cleanHtml = preg_replace('/\[\/?[a-zA-Z0-9_-]+[^\]]*\]/', '', $cleanHtml);

    // Sanitizar via BlockCompiler
    $sanitizedHtml = BlockCompiler::sanitizeHtml($cleanHtml);
    $contentJson = BlockCompiler::htmlToBlocks($sanitizedHtml);

    // 4.5 Visualizações (Views)
    $views = 0;
    // Tentar ler postmeta de visualizações
    $viewsStmt = $wpPdo->prepare("SELECT meta_value FROM {$tblPostmeta} WHERE post_id = ? AND meta_key IN ('views', 'post_views_count', '_post_views_count') LIMIT 1");
    $viewsStmt->execute([$wpPostId]);
    $metaViews = $viewsStmt->fetchColumn();
    if ($metaViews !== false && is_numeric($metaViews)) {
        $views = (int)$metaViews;
    }

    echo "--------------------------------------------------------\n";
    echo "WP #{$wpPostId}: {$title}\n";
    echo "  Slug: /{$slug}/\n";
    echo "  Categoria: {$categorySlug} | Tags: " . ($tagsString ?: '(nenhuma)') . "\n";
    echo "  Autor ID: #{$targetUserId} | Status: {$status} | Data: " . ($publishedAt ?: 'N/A') . "\n";
    echo "  Foto Capa: " . ($featuredImage ?: '(sem imagem)') . "\n";
    if ($seoTitle || $focusKw) {
        echo "  SEO: [Kw: {$focusKw}] Title: {$seoTitle}\n";
    }

    if ($isDryRun) {
        $converted++;
        continue;
    }

    // Gravação Atômica Transacional no SpeedPress
    try {
        Database::transaction(function (\PDO $lpPdo) use (
            $wpPostId, $targetUserId, $slug, $title, $summary, $categorySlug, $tagsString,
            $featuredImage, $contentJson, $sanitizedHtml, $status, $publishedAt, $updatedAt,
            $seoTitle, $seoDesc, $focusKw, $views
        ): void {
            // Verificar se já foi migrado
            $existing = Database::fetchOne(
                "SELECT target_id FROM migration_map WHERE source = 'wordpress' AND entity_type = 'post' AND source_id = ? LIMIT 1",
                [(string)$wpPostId]
            );

            if ($existing) {
                // Atualizar dados do artigo existente
                $targetId = (int)$existing['target_id'];
                Database::query(
                    "UPDATE posts SET 
                        user_id = ?, slug = ?, title = ?, summary = ?, category = ?, tags = ?,
                        featured_image = ?, content_json = ?, content_html = ?, status = ?,
                        seo_title = ?, seo_description = ?, focus_keyword = ?, published_at = ?, updated_at = ?
                     WHERE id = ?",
                    [
                        $targetUserId, $slug, $title, $summary, $categorySlug, $tagsString,
                        $featuredImage, $contentJson, $sanitizedHtml, $status,
                        $seoTitle, $seoDesc, $focusKw, $publishedAt, $updatedAt,
                        $targetId
                    ]
                );
                return;
            }

            // Inserir novo artigo
            Database::query(
                "INSERT INTO posts (
                    user_id, slug, title, summary, category, tags, featured_image,
                    content_json, content_html, status, is_featured, version,
                    seo_title, seo_description, focus_keyword, published_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, 0, 1,
                    ?, ?, ?, ?, ?
                )",
                [
                    $targetUserId, $slug, $title, $summary, $categorySlug, $tagsString,
                    $featuredImage, $contentJson, $sanitizedHtml, $status,
                    $seoTitle, $seoDesc, $focusKw, $publishedAt, $updatedAt
                ]
            );

            $newPostId = (int)Database::lastInsertId();

            // Gravar post_stats com views do WordPress
            Database::query(
                "INSERT INTO post_stats (post_id, views) VALUES (?, ?) ON DUPLICATE KEY UPDATE views = ?",
                [$newPostId, $views, $views]
            );

            // Gravar mapa de migração
            Database::query(
                "INSERT INTO migration_map (source, entity_type, source_id, target_id, status)
                 VALUES ('wordpress', 'post', ?, ?, 'completed')",
                [(string)$wpPostId, (string)$newPostId]
            );

            // Redirecionamento canônico 301 caso o permalink original do WP tenha sido /?p=123
            $oldPath = "/?p={$wpPostId}";
            $pathHash = hash('sha256', $oldPath);
            Database::query(
                "INSERT IGNORE INTO redirects (old_path, path_hash, target_path, status_code)
                 VALUES (?, ?, ?, 301)",
                [$oldPath, $pathHash, "/{$slug}/"]
            );
        });

        echo "  -> [SUCESSO] Migrado com sucesso para o SpeedPress!\n";
        $converted++;
    } catch (\Throwable $e) {
        echo "  -> [ERRO] Falha ao gravar WP #{$wpPostId}: " . $e->getMessage() . "\n";
        $skipped++;
    }
}

echo "\n========================================================\n";
echo "   Resumo da Migração\n";
echo "========================================================\n";
echo "Lote solicitado: {$limit}\n";
echo "Artigos processados: " . count($articles) . "\n";
echo "Migrados com sucesso: {$converted}\n";
echo "Falhas / Ignorados: {$skipped}\n";
echo "========================================================\n\n";

if ($isDryRun) {
    echo "Execute sem a flag --dry-run para aplicar a gravação real:\n";
    echo "php bin/migrate-wp.php --limit=10\n\n";
}
