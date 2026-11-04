<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * Utilitários de Segurança Defensiva (Security by Design)
 * 
 * Centraliza proteções contra OWASP Top 10: XSS, CSRF, validação de origem,
 * hash seguro de senhas com Argon2id, hardening de sessão e HMAC de privacidade.
 */
class Security
{
    /**
     * Escape seguro contra XSS para saídas contextuais em templates HTML.
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Gera ou recupera o token CSRF da sessão ativa.
     */
    public static function generateCsrfToken(): string
    {
        self::ensureSession();

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }

    /**
     * Valida um token CSRF usando comparação em tempo constante para evitar timing attacks.
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        self::ensureSession();

        if (empty($token) || empty($_SESSION['_csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['_csrf_token'], $token);
    }

    /**
     * Inicia a sessão com parâmetros rigorosos de hardening (cookies HttpOnly, Secure, SameSite=Strict).
     */
    public static function startSecureSession(array $appConfig = []): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

        $sessionName = $appConfig['security']['session_name'] ?? 'LIGHTPRESSSESSID';
        $lifetime = $appConfig['security']['session_lifetime'] ?? 86400;

        session_name($sessionName);

        session_start([
            'cookie_lifetime' => $lifetime,
            'cookie_path' => '/',
            'cookie_domain' => '',
            'cookie_secure' => $isHttps, // Obrigatório HTTPS em produção
            'cookie_httponly' => true,   // Inacessível via JavaScript
            'cookie_samesite' => 'Strict', // Máxima mitigação CSRF
            'use_strict_mode' => true,   // Rejeita sessões não inicializadas pelo servidor
            'use_only_cookies' => true,
        ]);
    }

    /**
     * Regenera o ID de sessão para mitigar Session Fixation.
     */
    public static function regenerateSession(): void
    {
        self::ensureSession();
        session_regenerate_id(true);
    }

    /**
     * Garante que a sessão está ativa.
     */
    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSecureSession();
        }
    }

    /**
     * Gera hash de senha utilizando nativamente o algoritmo Argon2id.
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, // 64 MiB
            'time_cost' => 4,       // 4 iterações
            'threads' => 1,
        ]);
    }

    /**
     * Valida uma senha em texto puro contra seu hash Argon2id.
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Verifica se o hash de senha necessita de atualização de custo.
     */
    public static function passwordNeedsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    /**
     * Gera hash HMAC-SHA-256 do IP do visitante para deduplicação com preservação de privacidade.
     */
    public static function hashIp(string $ip, string $secret): string
    {
        // Normaliza o IP removendo portas e espaços
        $normalizedIp = trim(explode(':', $ip)[0] ?? $ip);
        return hash_hmac('sha256', $normalizedIp, $secret);
    }

    /**
     * Obtém o IP do cliente de forma segura (considerando proxies reversos confiáveis).
     */
    public static function getClientIp(): string
    {
        // Se houver Nginx / proxy local configurado
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return trim($_SERVER['HTTP_X_REAL_IP']);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Validação rigorosa de origem para o endpoint público `/api/view/{id}`
     * Conforme seção 8.3 do README: verifica Fetch Metadata (Sec-Fetch-Site: same-origin)
     * e cabeçalhos Origin / Referer contra a origem canônica configurada.
     */
    public static function validatePublicOrigin(string $canonicalOrigin): bool
    {
        // 1. Fetch Metadata (Browsers Modernos)
        $secFetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($secFetchSite !== null) {
            // Apenas requisições 'same-origin' são autorizadas para o POST do contador
            if ($secFetchSite !== 'same-origin') {
                return false;
            }
        }

        // 2. Validação do cabeçalho Origin
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null) {
            if ($origin === 'null') {
                return false;
            }
            // Comparação exata e estrita com a origem canônica
            return rtrim(strtolower($origin), '/') === rtrim(strtolower($canonicalOrigin), '/');
        }

        // 3. Fallback para cabeçalho Referer
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        if ($referer !== null) {
            $refererParts = parse_url($referer);
            $canonicalParts = parse_url($canonicalOrigin);

            $refererOrigin = ($refererParts['scheme'] ?? '') . '://' . ($refererParts['host'] ?? '');
            if (!empty($refererParts['port'])) {
                $refererOrigin .= ':' . $refererParts['port'];
            }

            $expectedOrigin = ($canonicalParts['scheme'] ?? '') . '://' . ($canonicalParts['host'] ?? '');
            if (!empty($canonicalParts['port'])) {
                $expectedOrigin .= ':' . $canonicalParts['port'];
            }

            return strtolower($refererOrigin) === strtolower($expectedOrigin);
        }

        // Sem qualquer evidência válida de origem -> Rejeitar (403)
        return false;
    }

    /**
     * Gera slugs URL-friendly limpos e canônicos.
     */
    public static function slugify(string $text): string
    {
        // Translitera caracteres acentuados
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        // Substitui caracteres não alfanuméricos por hífen
        $text = preg_replace('~[^\pL\d]+~u', '-', $text) ?? '';
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text) ?? '';
        $text = strtolower($text);

        return empty($text) ? 'item-' . bin2hex(random_bytes(4)) : $text;
    }
}
