<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Acesso proibido via web.\n";
    exit(1);
}

/**
 * Utilitário CLI de Restauração de Backup em Streaming (NDJSON) e Legado (JSON) - LightPress
 * Execução: php bin/restore.php [--file=storage/migration/backup_XXXX.ndjson.gz]
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ObjectCache;

$options = getopt('', ['file::', 'sqlite::']);
$file = $options['file'] ?? null;
if (!empty($options['sqlite'])) {
    Database::init(['driver' => 'sqlite', 'sqlite_path' => (string)$options['sqlite']]);
}

echo "========================================================\n";
echo "   LightPress / SpeedPress - Restauração de Backup      \n";
echo "========================================================\n\n";

$migrationDir = $config['paths']['storage'] . '/migration';

if (!$file || !file_exists($file)) {
    // Busca os backups mais recentes cobrindo formatos .ndjson(.gz) e .json(.gz)
    $files = glob("{$migrationDir}/backup_*.*json*");
    if (!empty($files)) {
        rsort($files);
        $file = $files[0];
        echo "Nenhum arquivo informado. Selecionado o backup mais recente:\n  -> {$file}\n\n";
    } else {
        echo "[ERRO] Nenhum arquivo de backup encontrado em {$migrationDir}.\n";
        echo "Uso: php bin/restore.php --file=caminho/do/backup.ndjson.gz\n";
        exit(1);
    }
}

echo "Lendo arquivo de backup: {$file}...\n";

$start = microtime(true);
$isGz = str_ends_with($file, '.gz');
$gz = $isGz ? @gzopen($file, 'rb') : @fopen($file, 'rb');

if (!$gz) {
    echo "[ERRO] Não foi possível abrir o arquivo para leitura.\n";
    exit(1);
}

// 1. Detecção automática de formato: NDJSON em streaming vs JSON legado
$firstLine = $isGz ? gzgets($gz) : fgets($gz);
$isNdjson = false;
$meta = null;

if ($firstLine !== false) {
    $firstDecoded = json_decode(trim($firstLine), true);
    if (is_array($firstDecoded) && (isset($firstDecoded['_meta']) || isset($firstDecoded['_table_start']))) {
        $isNdjson = true;
        $meta = $firstDecoded['_meta'] ?? [];
    }
}

$allowedTables = [
    'users', 'categories', 'posts', 'post_stats', 'post_revisions',
    'pages', 'settings', 'media', 'media_variants', 'media_refs',
    'redirects', 'migration_map', 'plugin_app_passwords', 'plugin_api_ingest_log',
    'plugin_webhook_sender_logs'
];

$ephemeralTables = [
    'user_reset_tokens', 'view_dedupe', 'cache_purge_jobs'
];

$restoredCounts = [];
$totalRows = 0;

$driver = Database::getDriver();
$pdo = Database::getConnection();

// Desativa integridade referencial ANTES da transação (obrigatório no SQLite, pois PRAGMA foreign_keys = OFF é ignorado dentro de transação ativa)
if ($driver === 'sqlite') {
    $pdo->exec("PRAGMA foreign_keys = OFF;");
} else {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
}

try {
    try {
        Database::transaction(function (PDO $pdo) use (
            $gz, $isGz, $isNdjson, $firstLine, $meta, $file,
            $allowedTables, $ephemeralTables, &$restoredCounts, &$totalRows
        ): void {
            // Limpa preventivamente as tabelas efêmeras para não deixar registros órfãos
            foreach ($ephemeralTables as $ephemTable) {
                try {
                    $pdo->exec("DELETE FROM `{$ephemTable}`;");
                } catch (\Throwable) {
                    // Tabela opcional não existe no ambiente
                }
            }

        $tableExistsCache = [];
        $checkTableExists = function (string $table) use ($pdo, &$tableExistsCache): bool {
            if (isset($tableExistsCache[$table])) {
                return $tableExistsCache[$table];
            }
            try {
                $test = $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
                if ($test !== false) {
                    $test->closeCursor();
                    $tableExistsCache[$table] = true;
                    return true;
                }
            } catch (\Throwable) {
                $tableExistsCache[$table] = false;
            }
            return false;
        };

        if ($isNdjson) {
            // =========================================================
            // FORMATO 2.0: STREAMING NDJSON (MEMÓRIA CONSTANTE < 32 MiB)
            // =========================================================
            echo "Formato identificado: NDJSON v2.0 (Streaming)\n";
            echo "Data de exportação:   " . ($meta['exported_at'] ?? 'Data desconhecida') . "\n";
            echo "Iniciando restauração transacional em streaming...\n\n";

            $clearedTables = [];
            $stmtCache = []; // Cache local de PDOStatement por assinatura de colunas

            $processLine = function (string $rawLine) use (
                $pdo, $allowedTables, $checkTableExists, &$clearedTables, &$stmtCache, &$restoredCounts, &$totalRows
            ): void {
                $line = trim($rawLine);
                if ($line === '') {
                    return;
                }

                $record = json_decode($line, true);
                if (!is_array($record)) {
                    return;
                }

                // A. Marcador de início de tabela
                if (isset($record['_table_start'])) {
                    $table = (string)$record['_table_start'];
                    if (!in_array($table, $allowedTables, true) || !$checkTableExists($table)) {
                        return;
                    }
                    if (!isset($clearedTables[$table])) {
                        $pdo->exec("DELETE FROM `{$table}`;");
                        $clearedTables[$table] = true;
                        $restoredCounts[$table] = 0;
                    }
                    return;
                }

                // B. Linha de dados
                if (isset($record['_table'], $record['_row']) && is_array($record['_row'])) {
                    $table = (string)$record['_table'];
                    $row = $record['_row'];

                    if (!in_array($table, $allowedTables, true) || !$checkTableExists($table)) {
                        return;
                    }

                    // Se a tabela ainda não foi esvaziada (ex: backup sem marcador _table_start)
                    if (!isset($clearedTables[$table])) {
                        $pdo->exec("DELETE FROM `{$table}`;");
                        $clearedTables[$table] = true;
                        $restoredCounts[$table] = 0;
                    }

                    $columns = array_keys($row);
                    $colSig = implode(',', $columns);

                    if (!isset($stmtCache[$table][$colSig])) {
                        $escapedCols = array_map(fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', $columns);
                        $colList = implode(', ', $escapedCols);
                        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
                        $stmtCache[$table][$colSig] = $pdo->prepare($sql);
                    }

                    $stmt = $stmtCache[$table][$colSig];
                    $stmt->execute(array_values($row));

                    $restoredCounts[$table] = ($restoredCounts[$table] ?? 0) + 1;
                    $totalRows++;
                }
            };

            // Processa a primeira linha lida na detecção (se não for apenas _meta)
            if (!empty($firstLine) && !isset($firstDecoded['_meta'])) {
                $processLine($firstLine);
            }

            // Itera linha a linha no stream comprimido
            while (($line = ($isGz ? gzgets($gz) : fgets($gz))) !== false) {
                $processLine($line);
            }

        } else {
            // =========================================================
            // FORMATO 1.0: RETROCOMPATIBILIDADE COM JSON LEGADO
            // =========================================================
            echo "Formato identificado: JSON Legado v1.0\n";

            if ($isGz) {
                gzclose($gz);
                $rawContent = file_get_contents($file);
                $json = function_exists('gzdecode') ? (string)gzdecode($rawContent) : $rawContent;
            } else {
                fclose($gz);
                $json = (string)file_get_contents($file);
            }

            $data = json_decode($json, true);
            if (!is_array($data) || empty($data['tables'])) {
                throw new RuntimeException("Arquivo de backup corrompido ou formato inválido.");
            }

            echo "Data de exportação:   " . ($data['exported_at'] ?? 'Data desconhecida') . "\n";
            echo "Iniciando restauração transacional...\n\n";

            foreach ($data['tables'] as $table => $rows) {
                if (!in_array($table, $allowedTables, true) || !$checkTableExists($table)) {
                    continue;
                }

                // Esvazia sempre a tabela, mesmo que $rows esteja vazio no backup
                $pdo->exec("DELETE FROM `{$table}`;");
                $restoredCounts[$table] = 0;

                if (!is_array($rows) || empty($rows)) {
                    continue;
                }

                $columns = array_keys($rows[0]);
                $escapedCols = array_map(fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', $columns);
                $colList = implode(', ', $escapedCols);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
                $stmt = $pdo->prepare($sql);

                $count = 0;
                foreach ($rows as $row) {
                    $stmt->execute(array_values($row));
                    $count++;
                    $totalRows++;
                }

                $restoredCounts[$table] = $count;
            }
        }
    });
} finally {
    // Reativa checagem de foreign keys mesmo em caso de erro ou sucesso
    if ($driver === 'sqlite') {
        $pdo->exec("PRAGMA foreign_keys = ON;");
    } else {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    }
}

    if (is_resource($gz)) {
        if ($isGz) {
            @gzclose($gz);
        } else {
            @fclose($gz);
        }
    }

    echo "\n========================================================\n";
    echo "   Restauração Concluída com Sucesso!\n";
    echo "========================================================\n";
    foreach ($restoredCounts as $table => $count) {
        echo "  [OK] Tabela '{$table}': {$count} registros restaurados.\n";
    }

    $elapsed = round(microtime(true) - $start, 3);
    $peakMemMb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

    echo "\nTotal de registros restaurados: {$totalRows}\n";
    echo "Pico de memória: {$peakMemMb} MiB\n";
    echo "Tempo decorrido: {$elapsed}s\n";

    // Invalidação geral de cache pós-restauração
    if (class_exists(ObjectCache::class) && ObjectCache::isAvailable()) {
        ObjectCache::flush();
        echo "\n[OK] Cache de objetos em memória (Redis/APCu) invalidado.\n";
    }

    echo "\n[AVISO] Recomenda-se executar a purga completa de cache HTML:\n  php bin/purge-cache.php --all\n\n";

} catch (\Throwable $e) {
    if (is_resource($gz)) {
        if ($isGz) {
            @gzclose($gz);
        } else {
            @fclose($gz);
        }
    }
    echo "\n[ERRO FATAL] Falha durante a restauração: " . $e->getMessage() . "\n";
    echo "Todas as alterações foram revertidas automaticamente (Rollback).\n\n";
    exit(1);
}
