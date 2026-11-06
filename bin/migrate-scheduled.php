<?php
declare(strict_types=1);

/**
 * Script CLI de Migração: Suporte a Agendamento de Matérias ('scheduled')
 * Execução: php bin/migrate-scheduled.php
 */

$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\Database;

echo "========================================================\n";
echo "   LightPress - Migração: Suporte a Agendamento de Matérias\n";
echo "========================================================\n\n";

try {
    $pdo = Database::getConnection();
    $driver = $config['database']['driver'] ?? 'mysql';

    echo "[1/3] Conectado ao banco via {$driver}...\n";

    if ($driver === 'mysql') {
        echo "[2/3] Atualizando enum de status na tabela 'posts'...\n";
        $pdo->exec("ALTER TABLE posts MODIFY COLUMN status ENUM('draft', 'pending_review', 'scheduled', 'published', 'archived') NOT NULL DEFAULT 'draft'");
        echo "  [OK] ENUM de posts atualizado com sucesso para incluir 'scheduled'.\n";

        echo "[3/3] Verificando enum de status na tabela 'post_revisions'...\n";
        $pdo->exec("ALTER TABLE post_revisions MODIFY COLUMN status ENUM('draft', 'pending_review', 'applied') NOT NULL DEFAULT 'draft'");
        echo "  [OK] ENUM de post_revisions verificado com sucesso.\n";
    } else {
        echo "[2/2] Driver SQLite não requer alteração de ENUM.\n";
    }

    echo "\n[SUCESSO] Migração concluída com êxito!\n";
} catch (\Throwable $e) {
    echo "\n[AVISO/INFO] " . $e->getMessage() . "\n";
}
