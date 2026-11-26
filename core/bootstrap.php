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

// Disponibilizar antecipadamente para o ciclo de inicialização (evita falhas de descriptografia prematura)
$GLOBALS['config'] = $config;

// Configuração Imediata de Tratamento de Erros e Logs
$logDeprecations = !empty($config['app']['log_deprecations'])
    || in_array(strtolower((string)(getenv('LP_LOG_DEPRECATIONS') ?: '')), ['1', 'true', 'yes', 'on'], true);

if (!empty($config['app']['debug'])) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting($logDeprecations ? E_ALL : (E_ALL & ~E_DEPRECATED));
}

ini_set('log_errors', '1');
$logDir = ($config['paths']['storage'] ?? (dirname(__DIR__) . '/storage')) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
if (!empty($config['app']['error_log']) && is_string($config['app']['error_log'])) {
    ini_set('error_log', $config['app']['error_log']);
} else {
    ini_set('error_log', $logDir . '/error.log');
}

// Inicializar Camada de Configurações Dinâmicas e Chave de Criptografia
\LightPress\Core\Settings::init($config['security']['encryption_key'] ?? null);

// Validação defensiva de segurança em ambiente de produção (fail-fast HTTP)
if (PHP_SAPI !== 'cli' && ($config['app']['env'] ?? '') === 'production') {
    $hmacSecret = (string)($config['security']['hmac_secret'] ?? '');
    $encKey = trim((string)($config['security']['encryption_key'] ?? ''));
    $invalidHmac = empty($hmacSecret) || $hmacSecret === 'ALTERAR_PARA_UMA_CHAVE_ALEATORIA_DE_64_CHARS_EM_PRODUCAO' || strlen($hmacSecret) < 32;
    $invalidEncKey = $encKey === ''
        || strlen($encKey) < 32
        || in_array($encKey, \LightPress\Core\Settings::DEFAULT_ENCRYPTION_PLACEHOLDERS, true);

    if ($invalidHmac || $invalidEncKey) {
        http_response_code(500);
        if ($invalidHmac) {
            error_log('[LightPress Security] Chave hmac_secret ausente, padrão ou menor que 32 caracteres em ambiente de produção.');
        }
        if ($invalidEncKey) {
            error_log('[LightPress Security] Chave security.encryption_key ausente, padrão ou menor que 32 caracteres em ambiente de produção.');
        }

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

// Configuração do Tema Ativo
$config['paths']['themes'] = dirname(__DIR__) . '/themes';
$activeTheme = \LightPress\Core\ThemeManager::getActiveTheme();
$config['theme'] = [
    'active' => $activeTheme,
    'dir' => \LightPress\Core\ThemeManager::getActiveThemeDir(),
    'type' => \LightPress\Core\ThemeManager::getActiveThemeType(),
];
$config['paths']['templates'] = \LightPress\Core\ThemeManager::getActiveThemeDir();

// Atualizar globalmente com os dados consolidados do tema e banco
$GLOBALS['config'] = $config;

// Carregar Plugins Ativos
\LightPress\Core\PluginManager::loadActivePlugins();

return $config;
