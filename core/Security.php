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
     * Alias para verifyCsrfToken para compatibilidade de nomenclatura.
     */
    public static function validateCsrfToken(?string $token): bool
    {
        return self::verifyCsrfToken($token);
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
     * Trata IPv4 e IPv6 de forma consistente sem cortes de sub-redes.
     */
    public static function hashIp(string $ip, string $secret): string
    {
        $ip = trim($ip);

        // Se for IPv4 com porta (ex: "192.168.1.1:8080"), remove a porta
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:\d+$/', $ip)) {
            $ip = explode(':', $ip)[0];
        }

        // Normalização canônica via inet_pton
        $packed = @inet_pton($ip);
        if ($packed !== false) {
            $normalizedIp = inet_ntop($packed);
        } else {
            $normalizedIp = $ip;
        }

        return hash_hmac('sha256', $normalizedIp, $secret);
    }

    /**
     * Obtém o IP do cliente com proteção estrita contra spoofing de cabeçalhos.
     * Só avalia cabeçalhos HTTP_X_FORWARDED_FOR, HTTP_X_REAL_IP ou HTTP_CF_CONNECTING_IP
     * se a conexão física ($_SERVER['REMOTE_ADDR']) partir de um proxy reverso confiável.
     *
     * @param string[]|null $trustedProxies Lista de IPs de proxies confiáveis
     */
    public static function getClientIp(?array $trustedProxies = null): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $trusted = $trustedProxies 
            ?? ($GLOBALS['config']['security']['trusted_proxies'] ?? ['127.0.0.1', '::1']);

        // Se a requisição não partiu de um proxy confiável, utiliza estritamente o endereço de conexão
        if (!in_array($remoteAddr, $trusted, true)) {
            return filter_var($remoteAddr, FILTER_VALIDATE_IP) ?: '127.0.0.1';
        }

        // 1. Cabeçalho Cloudflare (quando aplicável)
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
                return $cfIp;
            }
        }

        // 2. Cabeçalho X-Real-IP configurado pelo Nginx
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $realIp = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($realIp, FILTER_VALIDATE_IP)) {
                return $realIp;
            }
        }

        // 3. Cabeçalho X-Forwarded-For (lido da direita para a esquerda - rightmost untrusted IP)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = array_reverse(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            foreach ($ips as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    if (!in_array($candidate, $trusted, true)) {
                        return $candidate;
                    }
                }
            }
            foreach ($ips as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ?: '127.0.0.1';
    }

    /**
     * Verifica se o IP está bloqueado por excesso de tentativas de login (Rate-Limiting).
     *
     * @return int|null Retorna os segundos restantes de bloqueio ou null se liberado.
     */
    public static function checkLoginRateLimit(string $ip, int $maxAttempts = 5, int $lockoutSeconds = 900): ?int
    {
        $ipHash = hash('sha256', $ip);
        $file = dirname(__DIR__) . '/storage/logs/ratelimit_login_' . substr($ipHash, 0, 16) . '.json';

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            @unlink($file);
            return null;
        }

        $now = time();
        $attempts = (int)($data['attempts'] ?? 0);
        $firstAttempt = (int)($data['first_attempt'] ?? $now);
        $lockoutUntil = (int)($data['lockout_until'] ?? 0);

        if ($lockoutUntil > $now) {
            return $lockoutUntil - $now;
        }

        if (($now - $firstAttempt) > $lockoutSeconds) {
            @unlink($file);
            return null;
        }

        if ($attempts >= $maxAttempts) {
            return max(1, $lockoutSeconds - ($now - $firstAttempt));
        }

        return null;
    }

    /**
     * Registra uma tentativa falha de login para o IP.
     */
    public static function recordLoginFailure(string $ip, int $maxAttempts = 5, int $lockoutSeconds = 900): void
    {
        $ipHash = hash('sha256', $ip);
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/ratelimit_login_' . substr($ipHash, 0, 16) . '.json';

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }

        try {
            flock($fp, LOCK_EX);
            $size = @filesize($file);
            $raw = ($size && $size > 0) ? (string)fread($fp, $size) : '';
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = ['attempts' => 0, 'first_attempt' => time(), 'lockout_until' => 0];
            }

            $now = time();
            if (($now - ((int)($data['first_attempt'] ?? $now))) > $lockoutSeconds) {
                $data['attempts'] = 0;
                $data['first_attempt'] = $now;
                $data['lockout_until'] = 0;
            }

            $data['attempts'] = ((int)($data['attempts'] ?? 0)) + 1;
            $data['last_attempt'] = $now;

            if ($data['attempts'] >= $maxAttempts) {
                $data['lockout_until'] = $now + $lockoutSeconds;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Limpa o histórico de tentativas falhas de login após sucesso.
     */
    public static function clearLoginFailures(string $ip): void
    {
        $ipHash = hash('sha256', $ip);
        $file = dirname(__DIR__) . '/storage/logs/ratelimit_login_' . substr($ipHash, 0, 16) . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Valida de forma rigorosa e centralizada a autenticação para todas as páginas e APIs do painel.
     * Verifica:
     * 1. Presença de $_SESSION['user_id']
     * 2. Existência do usuário no banco
     * 3. Conta ativa (is_active == 1)
     * 4. Validade da versão de sessão (session_version coincide com o registro no banco)
     * 5. Restrição opcional por papéis ($requiredRoles)
     * 6. Restrição opcional por ação RBAC ($requiredAction via Authorization::can)
     *
     * @param string|null $requiredAction
     * @param string[]|null $requiredRoles
     * @param array|null $config
     * @return array<string, mixed>
     */
    public static function requireAuth(?string $requiredAction = null, ?array $requiredRoles = null, ?array $config = null): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::startSecureSession($config ?? []);
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $sessionVersion = (int)($_SESSION['session_version'] ?? 0);

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

        if ($userId <= 0) {
            if ($isAjax) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Não autenticado.']);
                exit;
            }
            header('Location: /admin/login.php');
            exit;
        }

        $user = Database::fetchOne(
            "SELECT id, name, username, email, role, bio, password_hash, is_active, session_version 
             FROM users WHERE id = ? LIMIT 1",
            [$userId]
        );

        if (!$user || empty($user['is_active']) || (int)$user['session_version'] !== $sessionVersion) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            @session_destroy();

            if ($isAjax) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Sessão revogada ou conta inativa. Faça login novamente.']);
                exit;
            }
            header('Location: /admin/login.php?revoked=1');
            exit;
        }

        if ($requiredRoles !== null && !in_array($user['role'], $requiredRoles, true)) {
            if ($isAjax) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Acesso negado para o seu perfil.']);
                exit;
            }
            http_response_code(403);
            die("Acesso negado: seu perfil ({$user['role']}) não tem permissão para acessar esta área.");
        }

        if ($requiredAction !== null && !Authorization::can($user, $requiredAction)) {
            if ($isAjax) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Acesso negado para esta operação.']);
                exit;
            }
            http_response_code(403);
            die("Acesso negado: você não possui permissão para executar esta ação.");
        }

        return $user;
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
