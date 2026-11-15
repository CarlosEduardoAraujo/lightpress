<?php
declare(strict_types=1);

/**
 * Micro-API REST de Ingestão de Matérias (Fallback Físico)
 * Endpoint: POST /api/v1/posts.php ou POST /api/v1/posts
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\PluginManager;
use LightPress\Plugin\ApiPublisher\ApiPublisherController;

// Guarda estrita de isolamento: se o plugin estiver desativado no painel, encerra com 404
if (!PluginManager::isActive('api-publisher')) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Endpoint de API indisponível ou plugin desativado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Carregar componentes do plugin caso ainda não carregados
if (!class_exists(ApiPublisherController::class, false)) {
    require_once dirname(__DIR__, 2) . '/plugins/api-publisher/api-publisher.php';
}

// Delegar execução ao Controller
ApiPublisherController::handleCreatePost();
