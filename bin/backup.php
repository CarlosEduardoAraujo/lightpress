<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Acesso proibido via web.\n";
    exit(1);
}

/**
 * Utilitário CLI de Backup e Exportação de Dados em Streaming (NDJSON) - LightPress
 * Execução: php bin/backup.php
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;

$options = getopt('', ['sqlite::', 'file::']);
if (!empty($options['sqlite'])) {
    Database::init(['driver' => 'sqlite', 'sqlite_path' => (string)$options['sqlite']]);
}

echo "========================================================\n";
echo "   LightPress / SpeedPress - Utilitário de Backup (NDJSON)\n";
echo "========================================================\n\n";

$backupDir = $config['paths']['storage'] . '/migration';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

$timestamp = gmdate('Y-m-d_His');
$useGz = function_exists('gzopen');
$extension = $useGz ? 'ndjson.gz' : 'ndjson';
$backupFile = !empty($options['file']) ? (string)$options['file'] : "{$backupDir}/backup_{$timestamp}.{$extension}";

$start = microtime(true);
$gz = $useGz ? @gzopen($backupFile, 'wb9') : @fopen($backupFile, 'wb');
if (!$gz) {
    echo "[ERRO] Não foi possível criar o arquivo de backup em: {$backupFile}\n";
    exit(1);
}

/**
 * Escreve um registro serializado em JSON com quebra de linha no stream.
 *
 * @param array<string, mixed> $data
 */
$writeLine = function (array $data) use ($gz, $useGz): void {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if ($useGz) {
        gzwrite($gz, $json);
    } else {
        fwrite($gz, $json);
    }
};

// 1. Linha de cabeçalho / metadados do backup
$writeLine([
    '_meta' => [
        'app' => 'LightPress',
        'format' => 'ndjson',
        'version' => '2.0',
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]
]);

// 2. Especificação canônica das 15 tabelas relacionais e estratégia de paginação
$tableSpecs = [
    'users'                      => ['type' => 'keyset_int', 'pk' => 'id'],
    'categories'                 => ['type' => 'keyset_int', 'pk' => 'id'],
    'posts'                      => ['type' => 'keyset_int', 'pk' => 'id'],
    'post_stats'                 => ['type' => 'keyset_int', 'pk' => 'post_id'],
    'post_revisions'             => ['type' => 'keyset_int', 'pk' => 'id'],
    'pages'                      => ['type' => 'keyset_int', 'pk' => 'id'],
    'settings'                   => ['type' => 'offset',     'order' => '`key` ASC'],
    'media'                      => ['type' => 'keyset_str', 'pk' => 'id'],
    'media_variants'             => ['type' => 'keyset_int', 'pk' => 'id'],
    'media_refs'                 => ['type' => 'keyset_int', 'pk' => 'id'],
    'redirects'                  => ['type' => 'keyset_int', 'pk' => 'id'],
    'migration_map'              => ['type' => 'offset',     'order' => '`source`, `entity_type`, `source_id` ASC'],
    'plugin_app_passwords'       => ['type' => 'keyset_int', 'pk' => 'id'],
    'plugin_api_ingest_log'      => ['type' => 'keyset_int', 'pk' => 'id'],
    'plugin_webhook_sender_logs' => ['type' => 'keyset_int', 'pk' => 'post_id'],
];

$pdo = Database::getConnection();
$driver = Database::getDriver();

// Configura unbuffered queries no MySQL para garantir consumo de memória estritamente constante
if ($driver === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
}

$chunkSize = 500;
$totalRecords = 0;

echo "Iniciando exportação em streaming por lotes de {$chunkSize} registros...\n\n";

foreach ($tableSpecs as $table => $spec) {
    try {
        // Checagem de existência da tabela no banco atual
        $testStmt = $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
        if ($testStmt !== false) {
            $testStmt->closeCursor();
        }
    } catch (\Throwable $e) {
        // Tabela opcional não instalada (ex: plugins)
        echo "  [INFO] Tabela '{$table}' não existe no ambiente atual. Ignorada.\n";
        continue;
    }

    // Marcador explícito de início de tabela (garante esvaziamento correto de tabelas vazias no restore)
    $writeLine(['_table_start' => $table]);

    $tableCount = 0;
    $type = $spec['type'];

    if ($type === 'keyset_int') {
        $pk = $spec['pk'];
        $lastId = 0;
        $sql = "SELECT * FROM `{$table}` WHERE `{$pk}` > ? ORDER BY `{$pk}` ASC LIMIT {$chunkSize}";
        $stmt = $pdo->prepare($sql);

        while (true) {
            $stmt->execute([$lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $writeLine(['_table' => $table, '_row' => $row]);
                $lastId = (int)$row[$pk];
                $tableCount++;
            }

            if (count($rows) < $chunkSize) {
                break;
            }
        }
    } elseif ($type === 'keyset_str') {
        $pk = $spec['pk'];
        $lastId = '';
        $sql = "SELECT * FROM `{$table}` WHERE `{$pk}` > ? ORDER BY `{$pk}` ASC LIMIT {$chunkSize}";
        $stmt = $pdo->prepare($sql);

        while (true) {
            $stmt->execute([$lastId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $writeLine(['_table' => $table, '_row' => $row]);
                $lastId = (string)$row[$pk];
                $tableCount++;
            }

            if (count($rows) < $chunkSize) {
                break;
            }
        }
    } else { // offset para tabelas sem MEDIUMTEXT (settings e migration_map)
        $order = $spec['order'];
        $offset = 0;
        $sql = "SELECT * FROM `{$table}` ORDER BY {$order} LIMIT {$chunkSize} OFFSET ";

        while (true) {
            $stmt = $pdo->query($sql . $offset);
            if (!$stmt) {
                break;
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $writeLine(['_table' => $table, '_row' => $row]);
                $tableCount++;
            }

            $offset += count($rows);
            if (count($rows) < $chunkSize) {
                break;
            }
        }
    }

    $totalRecords += $tableCount;
    echo "  [OK] Tabela '{$table}': {$tableCount} registros exportados.\n";
}

// Restaura buffered queries se alterado
if ($driver === 'mysql' && defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
}

if ($useGz) {
    gzclose($gz);
} else {
    fclose($gz);
}

@chmod($backupFile, 0600);

$elapsed = round(microtime(true) - $start, 3);
$fileSizeKb = round(filesize($backupFile) / 1024, 2);
$peakMemMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

echo "\n========================================================\n";
echo "   Backup Concluído com Sucesso!\n";
echo "========================================================\n";
echo "Arquivo gerado:  {$backupFile}\n";
echo "Total exportado: {$totalRecords} registros em 15 tabelas\n";
echo "Tamanho total:   {$fileSizeKb} KiB\n";
echo "Pico de memória: {$peakMemMb} MiB\n";
echo "Tempo decorrido: {$elapsed}s\n\n";
