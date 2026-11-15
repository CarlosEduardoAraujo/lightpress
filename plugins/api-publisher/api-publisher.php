<?php
declare(strict_types=1);

/**
 * Plugin Name: API Publisher & Application Passwords
 * Description: Ingestão segura de matérias automatizadas via REST API como rascunhos para revisão editorial com Senhas de Aplicação revogáveis.
 * Version: 1.0.0
 * Author: Carlos Eduardo de Araújo / LightPress
 */

use LightPress\Core\Hook;
use LightPress\Core\PluginManager;
use LightPress\Core\Router;
use LightPress\Plugin\ApiPublisher\ApiPublisherController;
use LightPress\Plugin\ApiPublisher\ApiPublisherService;

// Carregamento dos componentes internos do plugin
require_once __DIR__ . '/src/MarkdownParser.php';
require_once __DIR__ . '/src/ApiPublisherService.php';
require_once __DIR__ . '/src/ApiPublisherController.php';

// 1. Hook de Ativação do Plugin (Garante a criação do schema de banco)
PluginManager::registerActivationHook('api-publisher', function (): void {
    ApiPublisherService::ensureTablesSchema();
});

// 2. Registro Dinâmico de Rotas da API no Roteador Central
Hook::addAction('register_routes', function (Router $router): void {
    $handler = [ApiPublisherController::class, 'handleCreatePost'];
    $router->post('/api/v1/posts', $handler);
    $router->post('/api/v1/posts/', $handler);
    $router->post('/api/v1/drafts', $handler);
    $router->post('/api/v1/drafts/', $handler);
});
