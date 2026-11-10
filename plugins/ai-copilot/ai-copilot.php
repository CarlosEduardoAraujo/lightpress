<?php
declare(strict_types=1);

/**
 * Plugin Name: AI Copilot
 * Description: Assistente de Inteligência Artificial para linkagem interna inteligente e visão computacional na biblioteca de mídias.
 * Version: 1.0.0
 * Author: LightPress Team
 */

use LightPress\Core\Database;
use LightPress\Core\PluginManager;
use LightPress\Core\Schema;

// 1. Autoloader PSR-4 isolado para o namespace do plugin
spl_autoload_register(function (string $class): void {
    $prefix = 'LightPress\\Plugin\\AiCopilot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// 2. Hook de Ativação do Plugin (Garante migração idempotente de schema)
PluginManager::registerActivationHook('ai-copilot', function (): void {
    try {
        $pdo = Database::getConnection();
        $driver = Database::getDriver();
        Schema::ensureMediaAiSchema($pdo, $driver);
    } catch (\Throwable $e) {
        error_log('[AI Copilot] Erro na ativação do plugin: ' . $e->getMessage());
    }
});
