<?php
declare(strict_types=1);

/**
 * Micro-API REST de Ingestão de Matérias (Fallback Físico)
 * Endpoint: POST /api/v1/posts.php ou POST /api/v1/posts
 */

use LightPress\Core\PluginManager;
use LightPress\Plugin\ApiPublisher\ApiPublisherController;

$rootDir = dirname(__DIR__, 3);
$config = require $rootDir . '/core/bootstrap.php';

// Guarda estrita de isolamento: se o plugin estiver desativado no painel, encerra com 404
if (!PluginManager::isActive('api-publisher')) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'ok' => false,
        'error' => 'Endpoint de API indisponível ou plugin desativado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Carregar componentes do plugin caso ainda não carregados
if (!class_exists(ApiPublisherController::class, false)) {
    require_once $rootDir . '/plugins/api-publisher/api-publisher.php';
}

// Delegar execução ao Controller
ApiPublisherController::handleCreatePost();
