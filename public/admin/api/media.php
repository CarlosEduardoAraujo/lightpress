<?php
declare(strict_types=1);

/**
 * Endpoint de API Interna do Painel Administrativo
 * Retorna catálogo de mídias para seleção rápida no editor e modal de fotos.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$config = require dirname(__DIR__, 3) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

$currentUser = Security::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Security::verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token de segurança CSRF inválido ou expirado.']);
        exit;
    }

    $mediaId = trim((string)($_POST['media_id'] ?? ''));
    if ($mediaId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Identificador da imagem (media_id) não informado.']);
        exit;
    }

    $mediaItem = Database::fetchOne("SELECT user_id FROM media WHERE id = ? LIMIT 1", [$mediaId]);
    if (!$mediaItem) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Mídia não encontrada.']);
        exit;
    }

    if (!in_array($currentUser['role'] ?? '', ['admin', 'editor', 'superadmin'], true)
        && (int)($mediaItem['user_id'] ?? 0) !== (int)$currentUser['id']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permissão negada para editar metadados desta mídia.']);
        exit;
    }

    $alt = mb_substr(trim(strip_tags((string)($_POST['alt_text'] ?? ''))), 0, 255);
    $cap = mb_substr(trim(strip_tags((string)($_POST['caption'] ?? ''))), 0, 500);

    Database::query("UPDATE media SET alt_text = ?, caption = ? WHERE id = ?", [$alt, $cap, $mediaId]);
    \LightPress\Core\ImageProcessor::invalidateMediaMetadata($mediaId);

    echo json_encode([
        'ok' => true,
        'media_id' => $mediaId,
        'alt_text' => $alt,
        'caption' => $cap,
    ]);
    exit;
}

$search = Security::getSafeString($_GET, 'q');
$limit = min(max((int)($_GET['limit'] ?? 60), 1), 100);

if (!empty($search)) {
    $driver = Database::getDriver();
    $buildApiWhere = function (bool $tryFulltext) use ($search, $driver): array {
        $clauses = [];
        $p = [];
        if (str_contains($search, '/')) {
            $cleanPath = ltrim((string)(parse_url($search, PHP_URL_PATH) ?? $search), '/');
            $prefixedPath = str_starts_with($cleanPath, 'uploads/') ? $cleanPath : 'uploads/' . $cleanPath;
            $clauses[] = "(id IN (SELECT media_id FROM media_variants WHERE url_path LIKE ?) OR original_name LIKE ? OR id = ?)";
            $p[] = Security::escapeLike($prefixedPath) . '%';
            $p[] = '%' . Security::escapeLike($search) . '%';
            $p[] = $search;
            return [$clauses, $p];
        }

        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', $search) ?: []);
        $fulltextWords = [];
        $shortWords = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 3) {
                $fulltextWords[] = "+{$w}*";
            } elseif (mb_strlen($w) >= 2) {
                $shortWords[] = $w;
            }
        }
        $boolQuery = implode(' ', $fulltextWords);

        if ($tryFulltext && $driver === 'mysql' && !empty($boolQuery)) {
            $clauses[] = "(MATCH(original_name, alt_text, caption, ai_tags) AGAINST(? IN BOOLEAN MODE) OR id = ?)";
            $p[] = $boolQuery;
            $p[] = $search;
            foreach ($shortWords as $sw) {
                $safeSw = '%' . Security::escapeLike($sw) . '%';
                $clauses[] = "(original_name LIKE ? OR alt_text LIKE ? OR caption LIKE ? OR ai_tags LIKE ?)";
                $p[] = $safeSw; $p[] = $safeSw; $p[] = $safeSw; $p[] = $safeSw;
            }
        } else {
            $safeSearch = '%' . Security::escapeLike($search) . '%';
            $clauses[] = "(original_name LIKE ? OR alt_text LIKE ? OR ai_tags LIKE ? OR caption LIKE ? OR id = ?)";
            $p[] = $safeSearch; $p[] = $safeSearch; $p[] = $safeSearch; $p[] = $safeSearch;
            $p[] = $search;
        }
        return [$clauses, $p];
    };

    [$clauses, $params] = $buildApiWhere(true);
    $whereSql = "WHERE " . implode(" AND ", $clauses);
    $items = null;
    try {
        $items = Database::fetchAll(
            "SELECT id, original_name, file_size, variants_manifest, alt_text, caption, ai_tags, created_at 
             FROM media {$whereSql} ORDER BY created_at DESC LIMIT ?",
            array_merge($params, [$limit])
        );
        if (empty($items) && $driver === 'mysql') {
            [$fallbackClauses, $fallbackParams] = $buildApiWhere(false);
            $fallbackWhere = "WHERE " . implode(" AND ", $fallbackClauses);
            $fallbackItems = Database::fetchAll(
                "SELECT id, original_name, file_size, variants_manifest, alt_text, caption, ai_tags, created_at 
                 FROM media {$fallbackWhere} ORDER BY created_at DESC LIMIT ?",
                array_merge($fallbackParams, [$limit])
            );
            if (!empty($fallbackItems)) { $items = $fallbackItems; }
        }
    } catch (\Throwable) {
        $items = null;
    }

    if ($items === null) {
        [$fallbackClauses, $fallbackParams] = $buildApiWhere(false);
        $fallbackWhere = "WHERE " . implode(" AND ", $fallbackClauses);
        $items = Database::fetchAll(
            "SELECT id, original_name, file_size, variants_manifest, alt_text, caption, ai_tags, created_at 
             FROM media {$fallbackWhere} ORDER BY created_at DESC LIMIT ?",
            array_merge($fallbackParams, [$limit])
        );
    }
} else {
    $items = Database::fetchAll(
        "SELECT id, original_name, file_size, variants_manifest, alt_text, caption, ai_tags, created_at 
         FROM media 
         ORDER BY created_at DESC 
         LIMIT ?",
        [$limit]
    );
}

$media = [];
foreach ($items as $row) {
    $variants = json_decode($row['variants_manifest'], true) ?? [];
    $thumbUrl = $variants['thumb']['avif']['url'] ?? ($variants['thumb']['webp']['url'] ?? ($variants['intermediate']['avif']['url'] ?? ($variants['intermediate']['webp']['url'] ?? '')));
    $fullUrl = $variants['intermediate']['avif']['url'] ?? ($variants['intermediate']['webp']['url'] ?? ($variants['full']['avif']['url'] ?? ($variants['full']['webp']['url'] ?? '')));
    $fullHdUrl = $variants['full']['avif']['url'] ?? ($variants['full']['webp']['url'] ?? $fullUrl);
    $w = $variants['full']['avif']['width'] ?? ($variants['full']['webp']['width'] ?? 0);
    $h = $variants['full']['avif']['height'] ?? ($variants['full']['webp']['height'] ?? 0);

    $media[] = [
        'id' => $row['id'],
        'original_name' => $row['original_name'],
        'alt_text' => (string)($row['alt_text'] ?? ''),
        'caption' => (string)($row['caption'] ?? ''),
        'ai_tags' => (string)($row['ai_tags'] ?? ''),
        'file_size' => (int)$row['file_size'],
        'thumb_url' => $thumbUrl,
        'full_url' => $fullUrl,
        'full_hd_url' => $fullHdUrl,
        'width' => $w,
        'height' => $h,
        'created_at' => $row['created_at'],
    ];
}

echo json_encode([
    'ok' => true,
    'total' => count($media),
    'media' => $media,
]);
