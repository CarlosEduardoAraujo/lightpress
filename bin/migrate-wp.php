<?php
declare(strict_types=1);

/**
 * Utilitário CLI de Migração Auditável do WordPress -> LightPress
 * 
 * Execução:
 * php bin/migrate-wp.php --dry-run
 * php bin/migrate-wp.php --wp-db=banco_wp --wp-user=root --wp-pass=senha --limit=100
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\BlockCompiler;
use LightPress\Core\Database;
use LightPress\Core\Security;

echo "========================================================\n";
echo "   LightPress - Migrador Auditável do WordPress CLI    \n";
echo "========================================================\n\n";

// Analisar opções da linha de comando
$options = getopt('', [
    'dry-run',
    'wp-host::',
    'wp-port::',
    'wp-db::',
    'wp-user::',
    'wp-pass::',
    'limit::',
    'offset::',
]);

$isDryRun = isset($options['dry-run']);
$wpHost = $options['wp-host'] ?? '127.0.0.1';
$wpPort = (int)($options['wp-port'] ?? 3306);
$wpDb = $options['wp-db'] ?? 'wordpress';
$wpUser = $options['wp-user'] ?? 'root';
$wpPass = $options['wp-pass'] ?? '';
$limit = (int)($options['limit'] ?? 100);
$offset = (int)($options['offset'] ?? 0);

echo "[Opções de Execução]\n";
echo "  Modo: " . ($isDryRun ? "DRY-RUN (Simulação - Nenhuma alteração será gravada)" : "PRODUÇÃO") . "\n";
echo "  Origem WordPress: {$wpUser}@{$wpHost}:{$wpPort}/{$wpDb}\n";
echo "  Lote: {$limit} artigos (Offset: {$offset})\n\n";

if ($isDryRun) {
    echo "[AVISO] Executando em modo de simulação (--dry-run). Conexões serão validadas.\n\n";
}

// Conexão somente-leitura com a base de dados do WordPress
try {
    $dsn = "mysql:host={$wpHost};port={$wpPort};dbname={$wpDb};charset=utf8mb4";
    $wpPdo = new PDO($dsn, $wpUser, $wpPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    echo "[OK] Conexão com o banco do WordPress estabelecida com sucesso.\n\n";
} catch (\Throwable $e) {
    echo "[ERRO] Não foi possível conectar ao banco do WordPress: " . $e->getMessage() . "\n";
    echo "Dica: Passe as credenciais corretas: --wp-db=nome_do_banco --wp-user=usuario --wp-pass=senha\n";
    exit(1);
}

// 1. Contagem de posts elegíveis no WordPress
$totalPostsStmt = $wpPdo->query("SELECT COUNT(*) FROM wp_posts WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending', 'trash')");
$totalPosts = (int)$totalPostsStmt->fetchColumn();
echo "Total de posts encontrados no WordPress: {$totalPosts}\n";

// 2. Leitura por lotes
$stmt = $wpPdo->prepare(
    "SELECT ID, post_author, post_date_gmt, post_date, post_content, post_title, post_excerpt, post_status, post_name
     FROM wp_posts 
     WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending', 'trash')
     ORDER BY ID ASC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$wpPosts = $stmt->fetchAll();

echo "Processando lote de " . count($wpPosts) . " artigos...\n";

$converted = 0;
$skipped = 0;

foreach ($wpPosts as $p) {
    $wpId = (int)$p['ID'];
    $title = trim($p['post_title']);
    $slug = !empty($p['post_name']) ? Security::slugify($p['post_name']) : Security::slugify($title);
    $rawContent = $p['post_content'];
    $summary = !empty($p['post_excerpt']) ? $p['post_excerpt'] : null;

    // Converter status
    $status = match ($p['post_status']) {
        'publish' => 'published',
        'pending' => 'pending_review',
        'trash' => 'archived',
        default => 'draft',
    };

    $publishedAt = ($status === 'published')
        ? (!empty($p['post_date_gmt']) && $p['post_date_gmt'] !== '0000-00-00 00:00:00' ? $p['post_date_gmt'] : $p['post_date'])
        : null;

    // Conversão de conteúdo (estruturação básica para blocos JSON)
    $blocks = [];
    $paragraphs = explode("\n\n", trim(strip_tags($rawContent, '<p><a><strong><em><h2><h3>')));
    foreach ($paragraphs as $para) {
        $para = trim($para);
        if (!empty($para)) {
            $blocks[] = ['type' => 'paragraph', 'content' => $para];
        }
    }

    $contentJson = json_encode(['version' => 1, 'blocks' => $blocks]);
    $contentHtml = BlockCompiler::compile($blocks);

    if ($isDryRun) {
        echo "  [SIMULAÇÃO] WP #{$wpId} -> '{$title}' ({$slug}) [{$status}]\n";
        $converted++;
        continue;
    }

    // Modo Real: Gravação Transacional no LightPress
    try {
        Database::transaction(function (\PDO $lpPdo) use ($wpId, $title, $slug, $summary, $status, $publishedAt, $contentJson, $contentHtml): void {
            // Verificar se já foi migrado
            $existing = Database::fetchOne(
                "SELECT target_id FROM migration_map WHERE source = 'wordpress' AND entity_type = 'post' AND source_id = ?",
                [(string)$wpId]
            );

            if ($existing) {
                return; // Já importado anteriormente
            }

            // Inserir artigo
            Database::query(
                "INSERT INTO posts (
                    user_id, slug, title, summary, category, content_json, content_html,
                    status, is_featured, version, published_at, updated_at
                ) VALUES (
                    1, ?, ?, ?, 'geral', ?, ?,
                    ?, 0, 1, ?, CURRENT_TIMESTAMP
                )",
                [$slug, $title, $summary, $contentJson, $contentHtml, $status, $publishedAt]
            );

            $newPostId = (int)Database::lastInsertId();

            // Inicializar estatísticas
            Database::query("INSERT INTO post_stats (post_id, views) VALUES (?, 0)", [$newPostId]);

            // Gravar mapa de migração
            Database::query(
                "INSERT INTO migration_map (source, entity_type, source_id, target_id, status)
                 VALUES ('wordpress', 'post', ?, ?, 'completed')",
                [(string)$wpId, (string)$newPostId]
            );

            // Gravar redirecionamento canônico 301 do permalink original
            $oldPath = "/?p={$wpId}";
            $pathHash = hash('sha256', $oldPath);
            Database::query(
                "INSERT IGNORE INTO redirects (old_path, path_hash, target_path, status_code)
                 VALUES (?, ?, ?, 301)",
                [$oldPath, $pathHash, "/noticia/{$slug}/"]
            );
        });
        $converted++;
    } catch (\Throwable $e) {
        echo "  [ERRO] Falha ao migrar WP #{$wpId}: " . $e->getMessage() . "\n";
        $skipped++;
    }
}

echo "\n========================================================\n";
echo "   Resumo da Migração\n";
echo "========================================================\n";
echo "Total processado: " . count($wpPosts) . "\n";
echo "Convertidos com sucesso: {$converted}\n";
echo "Ignorados / Pendentes: {$skipped}\n\n";
