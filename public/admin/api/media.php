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

Security::startSecureSession($config);

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Acesso não autorizado']);
    exit;
}

$search = trim($_GET['q'] ?? '');
$limit = min(max((int)($_GET['limit'] ?? 60), 1), 100);

if (!empty($search)) {
    $items = Database::fetchAll(
        "SELECT id, original_name, file_size, variants_manifest, created_at 
         FROM media 
         WHERE original_name LIKE ? 
         ORDER BY created_at DESC 
         LIMIT ?",
        ['%' . $search . '%', $limit]
    );
} else {
    $items = Database::fetchAll(
        "SELECT id, original_name, file_size, variants_manifest, created_at 
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
