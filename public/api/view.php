<?php
declare(strict_types=1);

/**
 * Micro-endpoint assíncrono para contabilização de visualizações (views)
 * Rota: POST /api/view/{id} ou /api/view.php?id={id}
 * 
 * Regras estritas:
 * 1. Não inicia sessão e não emite cookies (evita conflitos com cache do Nginx).
 * 2. Retorna sempre Cache-Control: no-store.
 * 3. Validação rigorosa de origem (Sec-Fetch-Site: same-origin, Origin / Referer).
 * 4. Deduplicação atômica transacional de 30 segundos por IP/artigo via HMAC.
 * 5. Incremento atômico exclusivo na tabela `post_stats.views`.
 */

// Headers obrigatórios de entrega dinâmica
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

// Apenas método POST é autorizado
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Carregar bootstrap do core
$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

// 1. Validação de Origem (Prevenção contra requisições cross-site)
$canonicalOrigin = $config['app']['canonical_origin'] ?? 'http://localhost:8080';
if (!Security::validatePublicOrigin($canonicalOrigin)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// 2. Extração e validação do ID do Artigo
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($postId <= 0 && isset($_SERVER['REQUEST_URI'])) {
    if (preg_match('~/api/view/([0-9]+)~', $_SERVER['REQUEST_URI'], $matches)) {
        $postId = (int)$matches[1];
    }
}

if ($postId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Post ID']);
    exit;
}

$nowUtc = gmdate('Y-m-d H:i:s');
$throttleSeconds = (int)($config['security']['rate_limit']['view_throttle_seconds'] ?? 30);
$expiresAt = gmdate('Y-m-d H:i:s', time() + $throttleSeconds);

$ip = Security::getClientIp($config['security']['trusted_proxies'] ?? null);
$secret = $config['security']['hmac_secret'] ?? 'lightpress_default_secret';
$ipHash = Security::hashIp($ip, $secret);

try {
    $recorded = Database::transaction(function (\PDO $pdo) use ($postId, $ipHash, $expiresAt, $nowUtc): bool {
        // Verificar se o artigo existe e está plenamente publicado
        $post = Database::fetchOne(
            "SELECT id FROM posts 
             WHERE id = ? AND status = 'published' AND published_at IS NOT NULL AND published_at <= ?
             LIMIT 1",
            [$postId, $nowUtc]
        );

        if (!$post) {
            return false;
        }

        // Verificar janela de deduplicação ativa
        $dedupe = Database::fetchOne(
            "SELECT expires_at FROM view_dedupe WHERE post_id = ? AND ip_hash = ?",
            [$postId, $ipHash]
        );

        if ($dedupe && $dedupe['expires_at'] > $nowUtc) {
            // Já visualizado recentemente dentro da janela -> Não incrementa
            return true;
        }

        // Renovar ou inserir registro de deduplicação
        Database::query(
            "INSERT INTO view_dedupe (post_id, ip_hash, expires_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)",
            [$postId, $ipHash, $expiresAt]
        );

        // Incremento atômico direto em post_stats
        Database::query(
            "UPDATE post_stats SET views = views + 1 WHERE post_id = ?",
            [$postId]
        );

        return true;
    });

    if (!$recorded) {
        http_response_code(404);
        echo json_encode(['error' => 'Post not found or not published']);
        exit;
    }

    // 204 No Content é a resposta mais eficiente para beacons e fetches em segundo plano
    http_response_code(204);
    exit;

} catch (\Throwable $e) {
    error_log("[ViewCounter Error] " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
}
