<?php
declare(strict_types=1);

/**
 * Utilitário CLI de Backup e Exportação de Dados - LightPress
 * Execução: php bin/backup.php
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;

echo "========================================================\n";
echo "   LightPress / SpeedPress - Utilitário de Backup       \n";
echo "========================================================\n\n";

$backupDir = $config['paths']['storage'] . '/migration';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

$timestamp = gmdate('Y-m-d_His');
$backupFile = "{$backupDir}/backup_{$timestamp}.json";
$gzFile = "{$backupFile}.gz";

echo "Iniciando exportação completa das tabelas relacionais...\n";

$tables = [
    'users', 'categories', 'posts', 'post_stats', 'post_revisions',
    'media', 'media_refs', 'redirects'
];

$exportData = [
    'app' => 'LightPress',
    'version' => '1.0.0',
    'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'tables' => [],
];

$start = microtime(true);

foreach ($tables as $table) {
    try {
        $rows = Database::fetchAll("SELECT * FROM {$table}");
        $exportData['tables'][$table] = $rows;
        echo "  [OK] Tabela '{$table}': " . count($rows) . " registros exportados.\n";
    } catch (\Throwable $e) {
        echo "  [AVISO] Tabela '{$table}' não encontrada ou vazia.\n";
    }
}

$json = json_encode($exportData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Gravar e compactar com gzip
if (function_exists('gzencode')) {
    $compressed = gzencode($json, 9);
    file_put_contents($gzFile, $compressed);
    $finalPath = $gzFile;
} else {
    file_put_contents($backupFile, $json);
    $finalPath = $backupFile;
}

$elapsed = round(microtime(true) - $start, 3);
$fileSizeKb = round(filesize($finalPath) / 1024, 2);

echo "\n========================================================\n";
echo "   Backup Concluído com Sucesso!\n";
echo "========================================================\n";
echo "Arquivo gerado: {$finalPath}\n";
echo "Tamanho total:  {$fileSizeKb} KiB\n";
echo "Tempo decorrido: {$elapsed}s\n\n";
