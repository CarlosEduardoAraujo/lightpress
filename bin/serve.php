<?php
declare(strict_types=1);

/**
 * Utilitário CLI para Servidor de Desenvolvimento Local
 * Execução: php bin/serve.php [--port=8080] [--host=localhost]
 */

$options = getopt('', ['port::', 'host::']);
$host = $options['host'] ?? '127.0.0.1';
$port = (int)($options['port'] ?? 8080);

$publicDir = realpath(dirname(__DIR__) . '/public');

echo "========================================================\n";
echo "   SpeedPress / LightPress - Servidor de Desenvolvimento \n";
echo "========================================================\n\n";

echo "Iniciando servidor local do PHP 8.4...\n";
echo "URL Pública:    http://{$host}:{$port}/\n";
echo "Painel Admin:   http://{$host}:{$port}/admin/login.php\n";
echo "Web Root:       {$publicDir}\n\n";
echo "Pressione Ctrl+C para encerrar o servidor.\n\n";

// Executa o servidor embutido do PHP com limites calibrados para uploads de mídia (12M) e processamento GD (512M)
$command = sprintf(
    'php -d upload_max_filesize=12M -d post_max_size=16M -d memory_limit=512M -S %s:%d -t "%s"',
    escapeshellarg($host),
    $port,
    $publicDir
);

passthru($command);
