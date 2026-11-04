<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * Utilitário de Logs Estruturados e Sanitizados (Privacy & Security First)
 * 
 * Registra eventos técnicos, erros operacionais e alertas de segurança
 * com expurgo obrigatório de credenciais, hashes, tokens e dados sensíveis.
 */
class Logger
{
    private static ?string $logDir = null;

    private static array $sensitiveKeys = [
        'password', 'password_hash', 'token', 'token_hash', 'secret',
        'hmac_secret', 'authorization', 'cookie', '_csrf_token', 'credit_card'
    ];

    /**
     * Define o diretório de logs.
     */
    public static function init(string $dir): void
    {
        self::$logDir = rtrim($dir, '/\\');
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function security(string $message, array $context = []): void
    {
        $context['ip'] = Security::getClientIp();
        $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        self::log('SECURITY', $message, $context);
    }

    /**
     * Grava a entrada de log sanitizada no arquivo.
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        if (self::$logDir === null) {
            self::$logDir = dirname(__DIR__) . '/storage/logs';
        }

        $sanitizedContext = self::sanitize($context);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $entry = sprintf(
            "[%s] [%s] %s %s\n",
            $timestamp,
            $level,
            $message,
            !empty($sanitizedContext) ? json_encode($sanitizedContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );

        $date = gmdate('Y-m-d');
        $logFile = self::$logDir . "/lightpress-{$date}.log";

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Remove recursivamente dados sensíveis dos contextos de log.
     */
    public static function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);

            if (in_array($lowerKey, self::$sensitiveKeys, true)) {
                $clean[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $clean[$key] = self::sanitize($value);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
}
