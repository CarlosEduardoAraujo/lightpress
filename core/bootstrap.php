<?php
declare(strict_types=1);

/**
 * LightPress / SpeedPress - Inicializador Core (Bootstrap)
 */

// Garantir UTC estrito
date_default_timezone_set('UTC');

// Autoloader PSR-4 leve para classes do Core (sem dependência externa de Composer)
spl_autoload_register(function (string $class): void {
    $prefix = 'LightPress\\Core\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Carregar Configurações Globais
$configFile = dirname(__DIR__) . '/config.php';
if (!file_exists($configFile)) {
    throw new RuntimeException("Arquivo de configuração ausente: {$configFile}");
}

$config = require $configFile;

// Inicializar Camada de Banco de Dados
\LightPress\Core\Database::init($config['database']);

// Aplicar Configurações Dinâmicas do Banco de Dados
\LightPress\Core\Settings::applyToConfig($config);

// Configuração de Erros
if (!empty($config['app']['debug'])) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

ini_set('log_errors', '1');
$logDir = $config['paths']['storage'] . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/error.log');

return $config;
