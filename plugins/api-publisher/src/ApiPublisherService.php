<?php
declare(strict_types=1);

namespace LightPress\Plugin\ApiPublisher;

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\PostService;
use LightPress\Core\Security;
use RuntimeException;

/**
 * Serviço Central do Plugin API Publisher & Application Passwords
 * 
 * Gerencia o ciclo de vida de senhas de aplicação, autenticação em tempo constante,
 * allowlist de IPs, verificação RBAC, idempotência atômica e auditoria.
 */
final class ApiPublisherService
{
    private static bool $schemaChecked = false;

    /**
     * Garante a integridade e criação das tabelas para SQLite e MySQL/MariaDB.
     */
    public static function ensureTablesSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $driver = Database::getDriver();
            if ($driver === 'sqlite') {
                Database::query("
                    CREATE TABLE IF NOT EXISTS plugin_app_passwords (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        name TEXT NOT NULL,
                        key_prefix TEXT NOT NULL,
                        secret_hash TEXT NOT NULL,
                        allowed_ips TEXT NULL,
                        is_revoked INTEGER NOT NULL DEFAULT 0,
                        last_used_at DATETIME NULL,
                        last_ip TEXT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                    );
                ");
                Database::query("CREATE INDEX IF NOT EXISTS idx_pap_prefix ON plugin_app_passwords (key_prefix);");
                Database::query("CREATE INDEX IF NOT EXISTS idx_pap_user ON plugin_app_passwords (user_id);");

                Database::query("
                    CREATE TABLE IF NOT EXISTS plugin_api_ingest_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        external_key TEXT NOT NULL UNIQUE,
                        post_id INTEGER NOT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
                    );
                ");
                Database::query("CREATE INDEX IF NOT EXISTS idx_pail_key ON plugin_api_ingest_log (external_key);");
            } else {
                Database::query("
                    CREATE TABLE IF NOT EXISTS plugin_app_passwords (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id SMALLINT UNSIGNED NOT NULL,
                        name VARCHAR(100) NOT NULL,
                        key_prefix VARCHAR(32) NOT NULL,
                        secret_hash VARCHAR(255) NOT NULL,
                        allowed_ips TEXT NULL,
                        is_revoked TINYINT(1) NOT NULL DEFAULT 0,
                        last_used_at DATETIME NULL,
                        last_ip VARCHAR(45) NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_pap_prefix (key_prefix),
                        INDEX idx_pap_user (user_id),
                        CONSTRAINT fk_pap_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                Database::query("
                    CREATE TABLE IF NOT EXISTS plugin_api_ingest_log (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        external_key VARCHAR(191) NOT NULL UNIQUE,
                        post_id INT UNSIGNED NOT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_pail_key (external_key),
                        CONSTRAINT fk_pail_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
            }
        } catch (\Throwable $e) {
            error_log('[ApiPublisher] Falha ao verificar/instalar schema: ' . $e->getMessage());
        }
    }

    /**
     * Gera uma nova Senha de Aplicação, armazenando o hash Argon2id e prefixo indexado.
     * Retorna a senha em texto claro uma única vez para exibição.
     *
     * @return array{id: int, name: string, username: string, full_password: string, key_prefix: string}
     */
    public static function generatePassword(int $userId, string $name, ?string $allowedIps = null): array
    {
        self::ensureTablesSchema();

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('O nome da aplicação é obrigatório.', 400);
        }

        $user = Database::fetchOne("SELECT id, username, is_active FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            throw new RuntimeException('Usuário selecionado não encontrado.', 404);
        }
        if (empty($user['is_active'])) {
            throw new RuntimeException('O usuário associado está inativo.', 400);
        }

        // Formato: lp_app_{8 chars prefix}_{32 chars secret}
        $prefixRandom = bin2hex(random_bytes(4)); // 8 chars hex
        $keyPrefix = 'lp_app_' . $prefixRandom;
        $secretRandom = bin2hex(random_bytes(16)); // 32 chars hex
        $fullPassword = "{$keyPrefix}_{$secretRandom}";

        $secretHash = Security::hashPassword($fullPassword);

        // Limpeza de IPs
        $cleanIps = null;
        if (!empty($allowedIps)) {
            $ipList = preg_split('/[\s,;]+/', trim($allowedIps));
            if (is_array($ipList)) {
                $filtered = array_values(array_filter(array_map('trim', $ipList), fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false));
                if (!empty($filtered)) {
                    $cleanIps = implode(',', $filtered);
                }
            }
        }

        Database::query(
            "INSERT INTO plugin_app_passwords (user_id, name, key_prefix, secret_hash, allowed_ips, is_revoked, created_at)
             VALUES (?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)",
            [$userId, $name, $keyPrefix, $secretHash, $cleanIps]
        );

        $id = (int)Database::lastInsertId();

        return [
            'id' => $id,
            'name' => $name,
            'username' => (string)$user['username'],
            'full_password' => $fullPassword,
            'key_prefix' => $keyPrefix,
        ];
    }

    /**
     * Extrai as credenciais HTTP Basic Auth inspecionando em cascata:
     * 1. $_SERVER['PHP_AUTH_USER'] e $_SERVER['PHP_AUTH_PW']
     * 2. $_SERVER['HTTP_AUTHORIZATION']
     * 3. $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
     *
     * @return array{username: string, password: string}|null
     */
    public static function extractBasicAuthCredentials(): ?array
    {
        // 1. PHP_AUTH_* preenchido nativamente
        if (!empty($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            return [
                'username' => trim((string)$_SERVER['PHP_AUTH_USER']),
                'password' => (string)$_SERVER['PHP_AUTH_PW'],
            ];
        }

        // 2. Cabeçalhos HTTP_AUTHORIZATION ou REDIRECT_HTTP_AUTHORIZATION
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (empty($authHeader)) {
            return null;
        }

        if (stripos($authHeader, 'Basic ') === 0) {
            $encoded = substr($authHeader, 6);
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
                return [
                    'username' => trim($user),
                    'password' => $pass,
                ];
            }
        }

        // Suporte opcional a Bearer token contendo a senha de aplicação
        if (stripos($authHeader, 'Bearer ') === 0) {
            $token = trim(substr($authHeader, 7));
            return [
                'username' => '',
                'password' => $token,
            ];
        }

        return null;
    }

    /**
     * Autentica uma requisição via Senha de Aplicação seguindo rigorosa ordem de validação defensiva:
     * 1. Rate Limit global/IP -> HTTP 429
     * 2. Busca O(1) por key_prefix + verificação Argon2id em tempo constante -> HTTP 401
     * 3. Checagem de revogação, conta ativa e correspondência de username -> HTTP 401
     * 4. Validação da allowlist de IP configurada na chave -> HTTP 403
     * 5. Avaliação de RBAC (Authorization::ACTION_CREATE_POST) -> HTTP 403
     *
     * @return array{user: array, password_id: int}
     * @throws RuntimeException com status code HTTP contextual
     */
    public static function authenticate(?string $providedUser, ?string $providedPassword, string $clientIp): array
    {
        self::ensureTablesSchema();

        // 1. Rate Limiting por IP (Mitigação de Força Bruta)
        $rateLimitLock = Security::checkLoginRateLimit($clientIp, 5, 900);
        if ($rateLimitLock !== null) {
            throw new RuntimeException("Muitas tentativas falhas de autenticação. Tente novamente em {$rateLimitLock} segundos.", 429);
        }

        $password = $providedPassword ?? '';
        if ($password === '') {
            Security::verifyPassword('dummy', Security::DUMMY_ARGON2ID_HASH);
            Security::recordLoginFailure($clientIp, 5, 900);
            throw new RuntimeException('Credenciais de autenticação ausentes.', 401);
        }

        // 2. Extração do prefixo público (formato esperado: lp_app_{8hex}_{32hex})
        $keyPrefix = '';
        if (preg_match('/^(lp_app_[a-f0-9]{8})_/i', $password, $matches)) {
            $keyPrefix = strtolower($matches[1]);
        }

        if ($keyPrefix === '') {
            // Senha sem o formato canônico: executa hash dummy para tempo constante e registra falha
            Security::verifyPassword('dummy', Security::DUMMY_ARGON2ID_HASH);
            Security::recordLoginFailure($clientIp, 5, 900);
            throw new RuntimeException('Credenciais de autenticação inválidas.', 401);
        }

        // Busca O(1) indexada por key_prefix
        $row = Database::fetchOne(
            "SELECT p.id, p.user_id, p.name, p.key_prefix, p.secret_hash, p.allowed_ips, p.is_revoked,
                    u.username as owner_username, u.name as owner_name, u.role as owner_role, u.is_active as owner_active
             FROM plugin_app_passwords p
             JOIN users u ON u.id = p.user_id
             WHERE p.key_prefix = ?
             LIMIT 1",
            [$keyPrefix]
        );

        if (!$row) {
            Security::verifyPassword('dummy', Security::DUMMY_ARGON2ID_HASH);
            Security::recordLoginFailure($clientIp, 5, 900);
            throw new RuntimeException('Credenciais de autenticação inválidas.', 401);
        }

        // Verificação Argon2id em tempo constante
        if (!Security::verifyPassword($password, (string)$row['secret_hash'])) {
            Security::recordLoginFailure($clientIp, 5, 900);
            throw new RuntimeException('Credenciais de autenticação inválidas.', 401);
        }

        // 3. Verificação de Revogação
        if (!empty($row['is_revoked'])) {
            Security::recordLoginFailure($clientIp, 5, 900);
            throw new RuntimeException('Esta Senha de Aplicação foi revogada.', 401);
        }

        // Verificação de Conta Ativa
        if (empty($row['owner_active'])) {
            Security::recordLoginFailure($clientIp, 5, 900);
            throw new RuntimeException('A conta de usuário associada a esta chave está inativa.', 401);
        }

        // Correspondência do Username (se enviado no Basic Auth)
        if (!empty($providedUser) && strcasecmp(trim($providedUser), (string)$row['owner_username']) !== 0) {
            Security::recordLoginFailure($clientIp, 5, 900);
            throw new RuntimeException('O usuário informado não corresponde ao titular desta Senha de Aplicação.', 401);
        }

        // 4. Validação da Allowlist de IP (se configurada na chave)
        if (!empty($row['allowed_ips'])) {
            $allowed = array_filter(array_map('trim', explode(',', (string)$row['allowed_ips'])));
            if (!empty($allowed) && !in_array($clientIp, $allowed, true)) {
                error_log(sprintf('[ApiPublisher] Bloqueio por Allowlist: IP "%s" não autorizado para a chave "%s"', $clientIp, $keyPrefix));
                throw new RuntimeException('Acesso negado: IP de origem não autorizado para esta chave de aplicação.', 403);
            }
        }

        // 5. Avaliação de RBAC
        $userObj = [
            'id' => (int)$row['user_id'],
            'username' => (string)$row['owner_username'],
            'name' => (string)$row['owner_name'],
            'role' => (string)$row['owner_role'],
            'is_active' => (int)$row['owner_active'],
        ];

        if (!Authorization::can($userObj, Authorization::ACTION_CREATE_POST)) {
            throw new RuntimeException('O usuário titular desta chave não possui permissão para criar publicações.', 403);
        }

        // Sucesso comprovado: limpa falhas de rate limit do IP
        Security::clearLoginFailures($clientIp);

        // Atualização assíncrona do último uso e IP
        try {
            Database::query(
                "UPDATE plugin_app_passwords SET last_used_at = CURRENT_TIMESTAMP, last_ip = ? WHERE id = ?",
                [$clientIp, (int)$row['id']]
            );
        } catch (\Throwable $e) {
            error_log('[ApiPublisher] Falha ao atualizar last_used_at: ' . $e->getMessage());
        }

        return [
            'user' => $userObj,
            'password_id' => (int)$row['id'],
            'app_name' => (string)$row['name'],
        ];
    }

    /**
     * Revoga imediatamente uma Senha de Aplicação.
     */
    public static function revokePassword(int $passwordId): bool
    {
        self::ensureTablesSchema();
        $stmt = Database::query("UPDATE plugin_app_passwords SET is_revoked = 1 WHERE id = ?", [$passwordId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Exclui em definitivo uma Senha de Aplicação.
     */
    public static function deletePassword(int $passwordId): bool
    {
        self::ensureTablesSchema();
        $stmt = Database::query("DELETE FROM plugin_app_passwords WHERE id = ?", [$passwordId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Consulta se um external_id já foi ingerido anteriormente e retorna o post correspondente.
     *
     * @return array{post_id: int, slug: string, title: string, status: string, category: string, user_id: int}|null
     */
    public static function checkIdempotency(string $externalKey): ?array
    {
        self::ensureTablesSchema();
        $cleanKey = trim($externalKey);
        if ($cleanKey === '') {
            return null;
        }

        $row = Database::fetchOne(
            "SELECT l.post_id, p.slug, p.title, p.status, p.category, p.user_id
             FROM plugin_api_ingest_log l
             JOIN posts p ON p.id = l.post_id
             WHERE l.external_key = ?
             LIMIT 1",
            [$cleanKey]
        );

        if (!$row) {
            return null;
        }

        return [
            'post_id' => (int)$row['post_id'],
            'slug' => (string)$row['slug'],
            'title' => (string)$row['title'],
            'status' => (string)$row['status'],
            'category' => (string)$row['category'],
            'user_id' => (int)$row['user_id'],
        ];
    }

    /**
     * Cria o rascunho via PostService e vincula a chave externa em transação atômica única.
     *
     * @param array $postData Dados formatados para PostService::createPost()
     * @param int $userId ID do autor vinculado
     * @param string|null $externalKey Chave externa de idempotência (ex: reddit_id)
     * @return array{id: int, slug: string, status: string, category: string, author_id: int}
     */
    public static function createIngestedPost(array $postData, int $userId, ?string $externalKey = null): array
    {
        self::ensureTablesSchema();
        $postService = new PostService();

        return Database::transaction(function () use ($postService, $postData, $userId, $externalKey): array {
            // 1. Criar post via PostService (força draft, is_featured=0, post_stats e post_revisions)
            $createdRevId = null;
            $postId = $postService->createPost($postData, $userId, $createdRevId);

            // 2. Registrar no log de idempotência se houver chave externa
            if (!empty($externalKey)) {
                Database::query(
                    "INSERT INTO plugin_api_ingest_log (external_key, post_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
                    [trim($externalKey), $postId]
                );
            }

            // Buscar slug resolvido
            $slug = (string)Database::fetchValue("SELECT slug FROM posts WHERE id = ? LIMIT 1", [$postId]);
            $category = (string)Database::fetchValue("SELECT category FROM posts WHERE id = ? LIMIT 1", [$postId]) ?: 'geral';

            return [
                'id' => $postId,
                'slug' => $slug,
                'status' => 'draft',
                'category' => $category,
                'author_id' => $userId,
            ];
        });
    }

    /**
     * Lista todas as senhas de aplicação para a tabela administrativa.
     */
    public static function listPasswords(): array
    {
        self::ensureTablesSchema();
        return Database::fetchAll("
            SELECT p.id, p.name, p.key_prefix, p.allowed_ips, p.is_revoked, p.last_used_at, p.last_ip, p.created_at,
                   u.id as user_id, u.name as author_name, u.username as author_username, u.role as author_role
            FROM plugin_app_passwords p
            JOIN users u ON u.id = p.user_id
            ORDER BY p.id DESC
        ");
    }

    /**
     * Retorna os últimos rascunhos ingeridos via API para auditoria no painel.
     */
    public static function getRecentLogs(int $limit = 20): array
    {
        self::ensureTablesSchema();
        $limit = max(1, min(100, $limit));
        return Database::fetchAll("
            SELECT l.id as log_id, l.external_key, l.created_at as ingested_at,
                   p.id as post_id, p.title, p.slug, p.status, p.category,
                   u.name as author_name
            FROM plugin_api_ingest_log l
            JOIN posts p ON p.id = l.post_id
            JOIN users u ON u.id = p.user_id
            ORDER BY l.id DESC
            LIMIT ?
        ", [$limit]);
    }
}
