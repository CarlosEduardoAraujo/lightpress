<?php
declare(strict_types=1);

namespace LightPress\Core;

use Throwable;

/**
 * Serviço de Invalidação Seletiva de Cache via API v4 da Cloudflare.
 * 
 * Executa purga seletiva de URLs específicas (Home, feeds, matéria e categoria)
 * sem derrubar o cache global de assets estáticos (imagens, CSS e JS).
 * 
 * Suporta também o namespace alias: Core\Services\CloudflareCache
 */
class CloudflareCache
{
    /**
     * Obtém as configurações consolidadas do sistema.
     *
     * @return array
     */
    public static function getConfig(): array
    {
        if (!empty($GLOBALS['config']) && is_array($GLOBALS['config'])) {
            return $GLOBALS['config'];
        }

        $baseDir = dirname(__DIR__);
        if (file_exists($baseDir . '/config.php')) {
            $cfg = require $baseDir . '/config.php';
            if (is_array($cfg)) {
                return $cfg;
            }
        }

        if (file_exists($baseDir . '/config.local.php')) {
            $cfg = require $baseDir . '/config.local.php';
            if (is_array($cfg)) {
                return $cfg;
            }
        }

        return [];
    }

    /**
     * Purga URLs específicas na Cloudflare (Purga Seletiva).
     *
     * @param string[] $urls Lista de URLs completas ou URIs relativas
     * @return bool
     */
    public static function purgeUrls(array $urls): bool
    {
        $config = self::getConfig();

        if (empty($config['cloudflare']['enabled']) || empty($config['cloudflare']['api_token']) || empty($config['cloudflare']['zone_id'])) {
            return false;
        }

        $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'https://www.speedracing.com.br', '/');
        $zoneId = trim((string)$config['cloudflare']['zone_id']);
        $token  = trim((string)$config['cloudflare']['api_token']);
        $timeout = (int)($config['cloudflare']['timeout'] ?? 5);

        // Normalização rigorosa das URLs (Cloudflare exige protocolo e domínio completos)
        $normalizedUrls = [];
        foreach ($urls as $url) {
            $u = trim((string)$url);
            if ($u === '') {
                continue;
            }

            if (preg_match('#^https?://#i', $u)) {
                $normalizedUrls[] = $u;
            } else {
                $normalizedUrls[] = $canonicalOrigin . '/' . ltrim($u, '/');
            }
        }

        $normalizedUrls = array_values(array_unique($normalizedUrls));
        if (empty($normalizedUrls)) {
            return true;
        }

        // A API v4 da Cloudflare aceita no máximo 30 arquivos por requisição
        $chunks = array_chunk($normalizedUrls, 30);
        $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";
        $allSuccess = true;

        foreach ($chunks as $chunk) {
            $payload = json_encode(['files' => $chunk], JSON_UNESCAPED_SLASHES);

            $ch = curl_init($endpoint);
            if ($ch === false) {
                error_log("[CloudflareCache] Falha ao inicializar cURL para purga.");
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || !empty($curlError)) {
                error_log("[CloudflareCache] Erro de rede cURL ({$httpCode}): {$curlError}");
                $allSuccess = false;
                continue;
            }

            $data = json_decode($response, true);
            $isSuccess = ($httpCode === 200 && !empty($data['success']));

            if (!$isSuccess) {
                $errorMsg = 'Erro desconhecido';
                if (!empty($data['errors']) && is_array($data['errors'])) {
                    $messages = array_map(function ($err) {
                        return is_array($err) ? ($err['message'] ?? json_encode($err)) : (string)$err;
                    }, $data['errors']);
                    $errorMsg = implode('; ', $messages);
                }
                error_log("[CloudflareCache] Falha na API Cloudflare (HTTP {$httpCode}): {$errorMsg}. Payload: {$payload}");
                $allSuccess = false;
            }
        }

        return $allSuccess;
    }

    /**
     * Disparo automático após salvar/publicar matéria.
     * Limpa a Home, os feeds, o sitemap, a URL da matéria e a URL da categoria.
     *
     * @param string $postUrl URL completa ou relativa da matéria (ex: '/alan-permane-racing-bulls-2031/' ou 'https://www.speedracing.com.br/alan-permane-racing-bulls-2031/')
     * @param string|null $categoryUrl URL completa ou relativa da categoria (ex: '/categoria/f1/')
     * @return bool
     */
    public static function purgeOnPostPublish(string $postUrl, ?string $categoryUrl = null): bool
    {
        $config = self::getConfig();
        $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'https://www.speedracing.com.br', '/');

        // Normalização da URL do post
        $postUrl = trim($postUrl);
        if (!preg_match('#^https?://#i', $postUrl)) {
            $postUrl = $canonicalOrigin . '/' . ltrim($postUrl, '/');
        }

        $urlsToPurge = [
            $canonicalOrigin . '/',
            $canonicalOrigin . '/feed.xml',
            $canonicalOrigin . '/feed/',
            $canonicalOrigin . '/sitemap.xml',
            $postUrl,
        ];

        if (!empty($categoryUrl)) {
            $categoryUrl = trim($categoryUrl);
            if (!preg_match('#^https?://#i', $categoryUrl)) {
                $categoryUrl = $canonicalOrigin . '/' . ltrim($categoryUrl, '/');
            }
            $urlsToPurge[] = $categoryUrl;
        }

        $res = self::purgeUrls($urlsToPurge);

        // Sincroniza também com o cache Nginx FastCGI local em disco
        try {
            $cacheMgr = new CacheManager($config);
            $cacheMgr->purgeUrls($urlsToPurge);
        } catch (Throwable $e) {}

        return $res;
    }

    /**
     * Purga total de todo o cache da zona na Cloudflare (Purge Everything).
     * Recomendado apenas para mudanças estruturais de layout ou migrações.
     *
     * @return bool
     */
    public static function purgeEverything(): bool
    {
        $config = self::getConfig();

        if (empty($config['cloudflare']['enabled']) || empty($config['cloudflare']['api_token']) || empty($config['cloudflare']['zone_id'])) {
            return false;
        }

        $zoneId = trim((string)$config['cloudflare']['zone_id']);
        $token  = trim((string)$config['cloudflare']['api_token']);
        $timeout = (int)($config['cloudflare']['timeout'] ?? 10);

        $endpoint = "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache";
        $payload = json_encode(['purge_everything' => true]);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode((string)$response, true);
            return !empty($data['success']);
        }

        error_log("[CloudflareCache] Purge Everything falhou com HTTP {$httpCode}: {$response}");
        return false;
    }

    /**
     * Testa a conectividade com a API da Cloudflare e valida as credenciais configuradas.
     *
     * @return array{success: bool, http_code: int, message: string}
     */
    public static function testConnection(): array
    {
        $config = self::getConfig();

        if (empty($config['cloudflare']['api_token'])) {
            return [
                'success' => false,
                'http_code' => 0,
                'message' => 'API Token não configurado em config.local.php.',
            ];
        }

        if (empty($config['cloudflare']['zone_id'])) {
            return [
                'success' => false,
                'http_code' => 0,
                'message' => 'Zone ID não configurado em config.local.php.',
            ];
        }

        $zoneId = trim((string)$config['cloudflare']['zone_id']);
        $token  = trim((string)$config['cloudflare']['api_token']);

        // 1. Testa validação do token
        $ch = curl_init("https://api.cloudflare.com/client/v4/user/tokens/verify");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $tokenData = json_decode((string)$response, true);
        if ($httpCode !== 200 || empty($tokenData['success'])) {
            $err = $tokenData['errors'][0]['message'] ?? 'Token inválido ou expirado';
            return [
                'success' => false,
                'http_code' => $httpCode,
                'message' => "Falha na verificação do token: {$err}",
            ];
        }

        // 2. Testa acesso à zona específica
        $ch = curl_init("https://api.cloudflare.com/client/v4/zones/{$zoneId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $zoneData = json_decode((string)$response, true);
        if ($httpCode === 200 && !empty($zoneData['success'])) {
            $zoneName = $zoneData['result']['name'] ?? 'desconhecida';
            return [
                'success' => true,
                'http_code' => 200,
                'message' => "Conexão bem-sucedida! Zona identificada: {$zoneName} (ID: {$zoneId}).",
            ];
        }

        $err = $zoneData['errors'][0]['message'] ?? 'Zona não encontrada ou sem permissão';
        return [
            'success' => false,
            'http_code' => $httpCode,
            'message' => "Falha ao acessar zona {$zoneId}: {$err}",
        ];
    }
}

// Compatibilidade com o alias alternativo de namespace Core\Services\CloudflareCache
if (!class_exists('Core\\Services\\CloudflareCache', false)) {
    class_alias(CloudflareCache::class, 'Core\\Services\\CloudflareCache');
}
