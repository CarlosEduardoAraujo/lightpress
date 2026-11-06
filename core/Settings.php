<?php
declare(strict_types=1);

namespace LightPress\Core;

use PDO;
use Throwable;

/**
 * Gerenciador Central de Configurações Dinâmicas do Site.
 * Armazena pares chave-valor no banco de dados com cache em memória e fallback seguro.
 */
class Settings
{
    private static ?array $cache = null;

    /**
     * Garante a existência da tabela `settings` no banco e faz seed com valores padrão.
     */
    public static function ensureTable(): void
    {
        $driver = Database::getDriver();
        if ($driver === 'sqlite') {
            Database::query("CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now')),
                updated_at TEXT NOT NULL DEFAULT (DATETIME('now'))
            );");
        } else {
            Database::query("CREATE TABLE IF NOT EXISTS `settings` (
                `key` VARCHAR(64) NOT NULL PRIMARY KEY,
                `value` MEDIUMTEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }

        // Se a tabela estiver vazia, semeia as opções padrão
        try {
            $count = (int)Database::fetchValue("SELECT COUNT(*) FROM settings");
            if ($count === 0) {
                $defaults = [
                    'site_title' => 'SpeedRacing',
                    'site_description' => 'Automobilismo mundial e nacional - Credibilidade em notícias desde 2002',
                    'site_favicon' => '',
                    'site_timezone' => 'America/Sao_Paulo',
                ];
                foreach ($defaults as $k => $v) {
                    Database::query("REPLACE INTO `settings` (`key`, `value`) VALUES (?, ?)", [$k, $v]);
                }
            }
        } catch (Throwable) {
            // Em caso de tabela recém-criada ou erro transitório
        }
    }

    /**
     * Carrega todas as configurações do banco com cache na mesma requisição.
     *
     * @return array<string, string>
     */
    public static function getAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];
        try {
            self::ensureTable();
            $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
            foreach ($rows as $row) {
                self::$cache[$row['key']] = $row['value'] ?? '';
            }
        } catch (Throwable) {
            // Fallback caso o banco não esteja acessível
        }

        return self::$cache;
    }

    /**
     * Obtém uma configuração específica por chave.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::getAll();
        if (array_key_exists($key, $all) && $all[$key] !== '') {
            return $all[$key];
        }
        return $default;
    }

    /**
     * Define ou atualiza uma configuração.
     */
    public static function set(string $key, ?string $value): void
    {
        self::ensureTable();
        $driver = Database::getDriver();
        $val = $value !== null ? trim($value) : null;

        if ($driver === 'sqlite') {
            Database::query(
                "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, DATETIME('now')) 
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
                [$key, $val]
            );
        } else {
            Database::query(
                "REPLACE INTO `settings` (`key`, `value`, `updated_at`) VALUES (?, ?, NOW())",
                [$key, $val]
            );
        }

        if (self::$cache !== null) {
            self::$cache[$key] = $val ?? '';
        }
    }

    /**
     * Define múltiplas configurações em lote.
     *
     * @param array<string, ?string> $pairs
     */
    public static function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            self::set($key, $value);
        }
    }

    /**
     * Aplica as configurações do banco na array de configuração global do sistema ($config).
     */
    public static function applyToConfig(array &$config): void
    {
        try {
            $settings = self::getAll();

            if (!empty($settings['site_title'])) {
                $config['app']['name'] = $settings['site_title'];
            }
            if (!empty($settings['site_description'])) {
                $config['app']['description'] = $settings['site_description'];
            }
            if (isset($settings['site_favicon'])) {
                $config['app']['favicon'] = $settings['site_favicon'];
            }
            if (!empty($settings['site_timezone'])) {
                $config['app']['display_timezone'] = $settings['site_timezone'];
            }
        } catch (Throwable) {
            // Em caso de falha de conexão inicial, preserva $config padrão
        }
    }
}
