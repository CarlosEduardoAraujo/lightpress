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
    if (strncmp($prefix, $class, $len) === 0) {
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
        return;
    }

    // Suporte a Core\Services\ para helpers de infraestrutura (ex: CloudflareCache)
    $servicesPrefix = 'Core\\Services\\';
    $sLen = strlen($servicesPrefix);
    if (strncmp($servicesPrefix, $class, $sLen) === 0) {
        $className = substr($class, $sLen);
        $file = $baseDir . str_replace('\\', '/', $className) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Carregar Configurações Globais
$configFile = dirname(__DIR__) . '/config.php';
if (!file_exists($configFile)) {
    throw new RuntimeException("Arquivo de configuração ausente: {$configFile}");
}

$config = require $configFile;

// Validação defensiva de segurança em ambiente de produção (fail-fast HTTP)
if (PHP_SAPI !== 'cli' && ($config['app']['env'] ?? '') === 'production') {
    $hmacSecret = (string)($config['security']['hmac_secret'] ?? '');
    if (empty($hmacSecret) || $hmacSecret === 'ALTERAR_PARA_UMA_CHAVE_ALEATORIA_DE_64_CHARS_EM_PRODUCAO' || strlen($hmacSecret) < 32) {
        http_response_code(500);
        error_log('[LightPress Security] Chave hmac_secret ausente, padrão ou menor que 32 caracteres em ambiente de produção.');

        $isJson = false;
        foreach (headers_list() as $hdr) {
            if (stripos($hdr, 'Content-Type: application/json') !== false) {
                $isJson = true;
                break;
            }
        }
        if (!$isJson && isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            $isJson = true;
        }

        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Erro interno de configuração do servidor.'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo '500 - Erro interno de configuração do servidor.';
        }
        exit;
    }
}

// Inicializar Camada de Banco de Dados
\LightPress\Core\Database::init($config['database']);

// Aplicar Configurações Dinâmicas do Banco de Dados
\LightPress\Core\Settings::applyToConfig($config);

// Disponibilizar globalmente para helpers de infraestrutura
$GLOBALS['config'] = $config;

// Carregar Plugins Ativos
\LightPress\Core\PluginManager::loadActivePlugins();

// Configuração de Erros
if (!empty($config['app']['debug'])) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

ini_set('log_errors', '1');
$logDir = $config['paths']['storage'] . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/error.log');

return $config;
