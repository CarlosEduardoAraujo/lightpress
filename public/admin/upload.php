<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Endpoint de Upload Assíncrono de Mídia
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

$currentUser = Security::requireAuth();

// 2. Verificação de CSRF
$csrfToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido ou expirado']);
    exit;
}

// 3. Validação de payload e limites do servidor
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'O arquivo enviado excede o limite máximo permitido pelo servidor (post_max_size no php.ini).']);
    exit;
}

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nenhum arquivo enviado']);
    exit;
}

$uploadErr = $_FILES['image']['error'] ?? UPLOAD_ERR_OK;
if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'O arquivo excede o limite máximo permitido pelo servidor de 8 MiB.']);
    exit;
} elseif ($uploadErr !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['image']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falha no recebimento do arquivo de imagem (código: ' . $uploadErr . ').']);
    exit;
}

$processor = new ImageProcessor($config);

try {
    $manifest = $processor->processUpload(
        $_FILES['image']['tmp_name'],
        $_FILES['image']['name']
    );

    // Persistir no catálogo de mídia
    $mediaId = $manifest['media_id'];
    $userId = (int)$_SESSION['user_id'];
    $originalName = $manifest['original_name'];
    $fileSize = (int)$_FILES['image']['size'];
    $variantsJson = json_encode($manifest['variants'], JSON_THROW_ON_ERROR);

    Database::query(
        "INSERT INTO media (id, user_id, original_name, mime_type, file_size, variants_manifest, is_finalized)
         VALUES (?, ?, ?, ?, ?, ?, 1)",
        [$mediaId, $userId, $originalName, 'image/webp', $fileSize, $variantsJson]
    );

    // Mapeamento direto de variantes O(1)
    ImageProcessor::registerVariants($mediaId, $manifest['variants'] ?? []);

    echo json_encode([
        'ok' => true,
        'media' => $manifest,
    ]);

} catch (\Throwable $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
