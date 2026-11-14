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
     * Lista de chaves cujos valores contêm credenciais ou dados confidenciais e devem ser cifrados em repouso.
     */
    public const SENSITIVE_KEYS = [
        'ai_copilot_gemini_key',
        'ai_copilot_openai_key',
        'ai_copilot_custom_key',
        'plugin_webhook_sender_urls',
        'redis_cache_pro_settings',
    ];

    /**
     * Deriva a chave binária de 32 bytes (SODIUM_CRYPTO_SECRETBOX_KEYBYTES) a partir de security.encryption_key.
     */
    public static function getEncryptionKey(): ?string
    {
        $rawKey = $GLOBALS['config']['security']['encryption_key'] ?? null;
        if (empty($rawKey) || !is_string($rawKey)) {
            return null;
        }
        return hash('sha256', $rawKey, true);
    }

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

        self::$cache = ObjectCache::remember('all_settings', 86400, function (): array {
            $data = [];
            try {
                self::ensureTable();
                $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
                foreach ($rows as $row) {
                    $data[$row['key']] = $row['value'] ?? '';
                }
            } catch (Throwable) {
                // Fallback caso o banco não esteja acessível
            }
            return $data;
        }, 'settings');

        return self::$cache;
    }

    /**
     * Cifra um valor sensível usando libsodium (se disponível) ou OpenSSL AES-256-GCM.
     */
    public static function encryptValue(string $plaintext): string
    {
        $encKey = self::getEncryptionKey();
        if ($encKey === null) {
            return $plaintext;
        }

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $encKey);
            return 'enc:' . base64_encode($nonce . $ciphertext);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);
            if ($ciphertext !== false) {
                return 'enc:gcm:' . base64_encode($iv . $tag . $ciphertext);
            }
        }

        return $plaintext;
    }

    /**
     * Decifra um valor caso possua o prefixo 'enc:'. Suporta libsodium e OpenSSL AES-256-GCM com fallback suave.
     */
    public static function decryptValue(string $raw): string
    {
        if (!str_starts_with($raw, 'enc:')) {
            return $raw;
        }

        $encKey = self::getEncryptionKey();
        if ($encKey === null) {
            return $raw;
        }

        // Formato OpenSSL AES-256-GCM
        if (str_starts_with($raw, 'enc:gcm:')) {
            if (!function_exists('openssl_decrypt')) {
                return $raw;
            }
            $payload = base64_decode(substr($raw, 8), true);
            if ($payload === false || strlen($payload) < 28) {
                return $raw;
            }
            $iv = substr($payload, 0, 12);
            $tag = substr($payload, 12, 16);
            $ciphertext = substr($payload, 28);
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);
            return ($decrypted !== false) ? $decrypted : $raw;
        }

        // Formato Libsodium secretbox
        if (function_exists('sodium_crypto_secretbox_open')) {
            $payload = base64_decode(substr($raw, 4), true);
            if ($payload !== false && strlen($payload) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $encKey);
                if ($decrypted !== false) {
                    return $decrypted;
                }
            }
        }

        return $raw;
    }

    /**
     * Limpa o cache estático e o cache de objeto de settings.
     */
    public static function flushCache(): void
    {
        self::$cache = null;
        ObjectCache::flushGroup('settings');
    }

    /**
     * Obtém uma configuração específica por chave com decifragem automática se estiver cifrada.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::getAll();
        if (array_key_exists($key, $all) && $all[$key] !== '') {
            return self::decryptValue((string)$all[$key]);
        }
        return $default;
    }

    /**
     * Define ou atualiza uma configuração com cifragem transparente para chaves sensíveis.
     */
    public static function set(string $key, ?string $value): void
    {
        self::ensureTable();
        $driver = Database::getDriver();
        $val = $value !== null ? trim($value) : null;

        // Cifragem em repouso de credenciais se a chave estiver configurada
        if ($val !== null && $val !== '' && in_array($key, self::SENSITIVE_KEYS, true)) {
            $val = self::encryptValue($val);
        }

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

        ObjectCache::flushGroup('settings');
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
