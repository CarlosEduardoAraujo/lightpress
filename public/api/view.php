<?php
declare(strict_types=1);

/**
 * Micro-endpoint assíncrono para contabilização de visualizações (views)
 * Rota: POST /api/view/{id} ou /api/view.php?id={id} (para posts)
 *       POST /api/view/page/{id} ou /api/view.php?type=page&id={id} (para páginas institucionais)
 * 
 * Regras estritas:
 * 1. Não inicia sessão e não emite cookies (evita conflitos com cache do Nginx).
 * 2. Retorna sempre Cache-Control: no-store.
 * 3. Validação rigorosa de origem (Sec-Fetch-Site: same-origin, Origin / Referer).
 * 4. Deduplicação atômica transacional de 30 segundos por IP/item via HMAC.
 * 5. Incremento atômico exclusivo na tabela correspondente (`post_stats.views` ou `pages.views`).
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
use LightPress\Core\Schema;
use LightPress\Core\Security;

// 1. Validação de Origem (Prevenção contra requisições cross-site)
$canonicalOrigin = $config['app']['canonical_origin'] ?? 'http://localhost:8080';
if (!Security::validatePublicOrigin($canonicalOrigin)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// 2. Extração e validação do Tipo e ID
$type = ($_GET['type'] ?? '') === 'page' ? 'page' : 'post';
$targetId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($targetId <= 0 && isset($_SERVER['REQUEST_URI'])) {
    if (preg_match('~/api/view/(?:(page|post)/)?([0-9]+)~', $_SERVER['REQUEST_URI'], $matches)) {
        if (!empty($matches[1]) && $matches[1] === 'page') {
            $type = 'page';
        }
        $targetId = (int)$matches[2];
    }
}

if ($targetId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Target ID']);
    exit;
}

$nowUtc = gmdate('Y-m-d H:i:s');
$throttleSeconds = (int)($config['security']['rate_limit']['view_throttle_seconds'] ?? 30);
$expiresAt = gmdate('Y-m-d H:i:s', time() + $throttleSeconds);

$ip = Security::getClientIp($config['security']['trusted_proxies'] ?? null);
$secret = (string)($config['security']['hmac_secret'] ?? '');
if (empty($secret)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}
$ipHash = Security::hashIp($ip, $secret);

// Para páginas institucionais, garantir o esquema de view_dedupe antes de iniciar a transação (evita commit implícito no MySQL)
if ($type === 'page') {
    Schema::ensureViewDedupeSchema(Database::getConnection(), Database::getDriver());
}

try {
    $recorded = Database::transaction(function (\PDO $pdo) use ($type, $targetId, $ipHash, $expiresAt, $nowUtc): bool {
        // IDs negativos no view_dedupe são reservados para páginas institucionais para evitar colisões com IDs de posts
        $dedupeId = ($type === 'page') ? -$targetId : $targetId;

        if ($type === 'page') {
            $page = Database::fetchOne(
                "SELECT id FROM pages WHERE id = ? AND status = 'published' LIMIT 1",
                [$targetId]
            );
            if (!$page) {
                return false;
            }
        } else {
            // Verificar se o artigo existe e está plenamente publicado (ou agendado com horário atingido)
            $post = Database::fetchOne(
                "SELECT id FROM posts 
                 WHERE id = ? AND status IN ('published', 'scheduled') AND published_at IS NOT NULL AND published_at <= ?
                 LIMIT 1",
                [$targetId, $nowUtc]
            );

            if (!$post) {
                return false;
            }
        }

        // Verificar janela de deduplicação ativa com lock pessimista no MySQL (evita race condition sob concorrência)
        $forUpdate = Database::getDriver() === 'mysql' ? ' FOR UPDATE' : '';
        $dedupe = Database::fetchOne(
            "SELECT expires_at FROM view_dedupe WHERE post_id = ? AND ip_hash = ?{$forUpdate}",
            [$dedupeId, $ipHash]
        );

        if ($dedupe && $dedupe['expires_at'] > $nowUtc) {
            // Já visualizado recentemente dentro da janela -> Não incrementa
            return true;
        }

        // Renovar ou inserir registro de deduplicação (portável MySQL / SQLite)
        if (Database::getDriver() === 'sqlite') {
            Database::query(
                "INSERT INTO view_dedupe (post_id, ip_hash, expires_at)
                 VALUES (?, ?, ?)
                 ON CONFLICT(post_id, ip_hash) DO UPDATE SET expires_at = excluded.expires_at",
                [$dedupeId, $ipHash, $expiresAt]
            );
        } else {
            Database::query(
                "INSERT INTO view_dedupe (post_id, ip_hash, expires_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)",
                [$dedupeId, $ipHash, $expiresAt]
            );
        }

        // Incremento atômico direto na tabela correta
        if ($type === 'page') {
            Database::query(
                "UPDATE pages SET views = views + 1 WHERE id = ?",
                [$targetId]
            );
        } else {
            Database::query(
                "UPDATE post_stats SET views = views + 1 WHERE post_id = ?",
                [$targetId]
            );
        }

        return true;
    });

    if (!$recorded) {
        http_response_code(404);
        echo json_encode(['error' => 'Target not found or not published']);
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
