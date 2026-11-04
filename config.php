<?php
declare(strict_types=1);

/**
 * LightPress / SpeedPress - Configuração Central do Sistema
 * 
 * Este arquivo define parâmetros fundamentais de infraestrutura, banco de dados,
 * cache e segurança. Em produção, este arquivo deve ter permissões restritas (chmod 600 ou 640).
 */

// Garantir que a aplicação opera estritamente em UTC
date_default_timezone_set('UTC');

$config = [
    // Identificação e Ambiente
    'app' => [
        'name' => 'LightPress',
        'env' => 'production', // 'development' ou 'production'
        'debug' => false,
        'canonical_origin' => 'http://localhost:8080', // Ex: https://seudominio.com.br (sem barra final)
        'display_timezone' => 'America/Sao_Paulo',
    ],

    // Banco de Dados Primário (MariaDB 10.6+ / MySQL 8.0+ ou SQLite)
    'database' => [
        'driver' => 'mysql', // 'mysql' ou 'sqlite'
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'lightpress_speedracing',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'sqlite_path' => __DIR__ . '/storage/database.sqlite',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
        ],
    ],

    // FastCGI Cache do Nginx
    'cache' => [
        'enabled' => true,
        'cache_path' => '/var/cache/nginx/lightpress',
        'levels' => '1:2',
        'default_ttl' => 3600, // 60 minutos
        'list_ttl' => 300,      // 5 minutos para paginações/feeds
        'key_zone' => 'LIGHTPRESS',
    ],

    // Pipeline de Mídia (PHP GD)
    'media' => [
        'upload_dir' => __DIR__ . '/public/uploads',
        'staging_dir' => __DIR__ . '/storage/staging',
        'max_file_size' => 8 * 1024 * 1024, // 8 MiB
        'max_pixels' => 12_000_000,          // 12 Megapixels
        'max_dimension' => 8_000,            // 8.000 px por lado
        'webp_quality' => 82,
        'avif_quality' => 75,
        'avif_speed' => 6,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif'],
        'resolutions' => [
            'full' => ['width' => 1920, 'height' => null, 'crop' => false],
            'intermediate' => ['width' => 1024, 'height' => null, 'crop' => false],
            'thumb' => ['width' => 300, 'height' => 169, 'crop' => true],
        ],
    ],

    // Segurança e Criptografia
    'security' => [
        'hmac_secret' => 'ALTERAR_PARA_UMA_CHAVE_ALEATORIA_DE_64_CHARS_EM_PRODUCAO',
        'session_name' => 'LIGHTPRESSSESSID',
        'session_lifetime' => 86400, // 24 horas
        'rate_limit' => [
            'login_max_attempts' => 5,
            'login_lockout_seconds' => 900, // 15 minutos
            'view_throttle_seconds' => 30,  // Janela de deduplicação por IP/artigo
        ],
    ],

    // Caminhos do Sistema
    'paths' => [
        'root' => __DIR__,
        'core' => __DIR__ . '/core',
        'storage' => __DIR__ . '/storage',
        'templates' => __DIR__ . '/templates',
        'admin' => __DIR__ . '/public/admin',
        'public' => __DIR__ . '/public',
    ],
];

// Carregar sobreposições locais (ambiente de desenvolvimento, credenciais locais) se existirem
// Proteção contra recursão infinita caso config.local.php tenha sido copiado de config.php
if (basename(__FILE__) === 'config.php' && file_exists(__DIR__ . '/config.local.php')) {
    $localConfigFile = realpath(__DIR__ . '/config.local.php');
    if ($localConfigFile && $localConfigFile !== realpath(__FILE__)) {
        $localConfig = require $localConfigFile;
        if (is_array($localConfig)) {
            $config = array_replace_recursive($config, $localConfig);
        }
    }
}

return $config;
