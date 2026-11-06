<?php
declare(strict_types=1);

/**
 * LightPress / SpeedPress - Container Seguro para Telas de Configuração de Plugins
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\AdminNav;
use LightPress\Core\PluginManager;
use LightPress\Core\Security;

// Acesso estritamente restrito a Administradores
$currentUser = Security::requireAuth(null, ['admin']);
$csrfToken = Security::generateCsrfToken();

$pluginSlug = trim((string)($_GET['plugin'] ?? ''));
if (empty($pluginSlug) || !preg_match('/^[a-z0-9\-_]+$/i', $pluginSlug)) {
    http_response_code(400);
    die('Identificador de plugin inválido.');
}

$available = PluginManager::getAvailablePlugins();
if (!isset($available[$pluginSlug])) {
    http_response_code(404);
    die('Plugin não encontrado.');
}

if (!PluginManager::isActive($pluginSlug)) {
    http_response_code(403);
    die('O plugin precisa estar ativo para acessar suas configurações.');
}

$plugin = $available[$pluginSlug];
$settingsFile = $plugin['dir'] . '/settings.php';

if (!file_exists($settingsFile)) {
    http_response_code(404);
    die('Este plugin não disponibiliza tela de configurações.');
}

// Carrega a tela de configurações do plugin dentro do escopo seguro
require $settingsFile;
