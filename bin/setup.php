<?php
declare(strict_types=1);

/**
 * Script CLI de Instalação e Migração do Banco de Dados - LightPress
 * Execução: php bin/setup.php
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Schema;
use LightPress\Core\Security;

echo "========================================================\n";
echo "   LightPress / SpeedPress - Instalação de Banco de Dados\n";
echo "========================================================\n\n";

$driver = $config['database']['driver'] ?? 'mysql';
echo "[1/3] Conectando ao banco de dados via driver '{$driver}'...\n";

try {
    $pdo = Database::getConnection();
    echo "  [OK] Conexão estabelecida com sucesso.\n";
} catch (\Throwable $e) {
    echo "  [ERRO] Falha ao conectar: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n[2/3] Instalando tabelas e índices do esquema...\n";
try {
    Schema::install($pdo, $driver);
    echo "  [OK] Tabelas e índices criados com sucesso:\n";
    echo "       - users, categories, posts, post_stats, post_revisions\n";
    echo "       - user_reset_tokens, media, media_refs, view_dedupe\n";
    echo "       - cache_purge_jobs, migration_map, redirects\n";
} catch (\Throwable $e) {
    echo "  [ERRO] Falha ao criar tabelas: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n[3/3] Verificando existência de usuário Administrador...\n";
try {
    $admin = Database::fetchOne("SELECT id, username, email FROM users WHERE role = 'admin' LIMIT 1");
    if ($admin) {
        echo "  [INFO] Administrador já existente: {$admin['username']} ({$admin['email']}).\n";
    } else {
        // Criar administrador inicial seguro
        $defaultUser = 'admin';
        $defaultEmail = 'admin@lightpress.local';
        // Gera senha inicial aleatória segura de 16 caracteres
        $defaultPassword = bin2hex(random_bytes(8));
        $passwordHash = Security::hashPassword($defaultPassword);

        Database::query(
            "INSERT INTO users (name, username, email, password_hash, role, is_active, password_reset_required) 
             VALUES (?, ?, ?, ?, 'admin', 1, 0)",
            ['Administrador', $defaultUser, $defaultEmail, $passwordHash]
        );

        echo "  [SUCESSO] Usuário administrador padrão criado:\n";
        echo "  ---------------------------------------------------\n";
        echo "  Usuário: {$defaultUser}\n";
        echo "  E-mail:  {$defaultEmail}\n";
        echo "  Senha:   {$defaultPassword}\n";
        echo "  ---------------------------------------------------\n";
        echo "  IMPORTANTE: Guarde esta senha e altere-a no primeiro login!\n";
    }
} catch (\Throwable $e) {
    echo "  [ERRO] Falha ao configurar usuário administrador: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n========================================================\n";
echo "   Instalação concluída com sucesso!\n";
echo "========================================================\n\n";
