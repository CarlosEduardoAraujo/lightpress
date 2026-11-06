<?php
declare(strict_types=1);

/**
 * Utilitário CLI de Restauração de Backup - LightPress
 * Execução: php bin/restore.php --file=storage/migration/backup_XXXX.json.gz
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;

$options = getopt('', ['file::']);
$file = $options['file'] ?? null;

echo "========================================================\n";
echo "   LightPress / SpeedPress - Restauração de Backup      \n";
echo "========================================================\n\n";

if (!$file || !file_exists($file)) {
    // Procurar o backup mais recente se nenhum arquivo for passado
    $migrationDir = $config['paths']['storage'] . '/migration';
    $files = glob("{$migrationDir}/backup_*.json*");
    if (!empty($files)) {
        rsort($files);
        $file = $files[0];
        echo "Nenhum arquivo informado. Selecionado o backup mais recente:\n  -> {$file}\n\n";
    } else {
        echo "[ERRO] Nenhum arquivo de backup encontrado em {$migrationDir}.\n";
        echo "Uso: php bin/restore.php --file=caminho/do/backup.json.gz\n";
        exit(1);
    }
}

echo "Lendo arquivo de backup: {$file}...\n";

$rawContent = file_get_contents($file);
if (str_ends_with($file, '.gz')) {
    if (!function_exists('gzdecode')) {
        echo "[ERRO] Extensão Zlib não disponível para descompactar arquivo .gz.\n";
        exit(1);
    }
    $json = gzdecode($rawContent);
} else {
    $json = $rawContent;
}

$data = json_decode((string)$json, true);
if (!is_array($data) || empty($data['tables'])) {
    echo "[ERRO] Formato de backup inválido ou corrompido.\n";
    exit(1);
}

echo "Backup gerado em: " . ($data['exported_at'] ?? 'Data desconhecida') . "\n";
echo "Iniciando restauração transacional...\n\n";

$restoredCounts = [];

$allowedTables = [
    'users', 'categories', 'posts', 'post_stats', 'post_revisions',
    'pages', 'settings', 'media', 'media_refs', 'redirects'
];

try {
    Database::transaction(function (\PDO $pdo) use ($data, $allowedTables, &$restoredCounts): void {
        $driver = Database::getDriver();
        if ($driver === 'sqlite') {
            $pdo->exec("PRAGMA foreign_keys = OFF;");
        } else {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        }

        foreach ($data['tables'] as $table => $rows) {
            if (!in_array($table, $allowedTables, true)) {
                continue;
            }
            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            // Limpar tabela atual
            $pdo->exec("DELETE FROM `{$table}`;");

            $columns = array_keys($rows[0]);
            $escapedCols = array_map(function($c) { return '`' . str_replace('`', '``', $c) . '`'; }, $columns);
            $colList = implode(', ', $escapedCols);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));

            $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
            $stmt = $pdo->prepare($sql);

            $count = 0;
            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
                $count++;
            }

            $restoredCounts[$table] = $count;
            echo "  [OK] Tabela '{$table}': {$count} registros restaurados.\n";
        }

        if ($driver === 'sqlite') {
            $pdo->exec("PRAGMA foreign_keys = ON;");
        } else {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        }
    });

    echo "\n========================================================\n";
    echo "   Restauração Concluída com Sucesso!\n";
    echo "========================================================\n\n";

} catch (\Throwable $e) {
    echo "\n[ERRO FATAL] Falha durante a restauração: " . $e->getMessage() . "\n";
    echo "As alterações foram revertidas automaticamente (Rollback).\n\n";
    exit(1);
}
