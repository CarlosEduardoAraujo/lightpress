<?php
declare(strict_types=1);

/**
 * Plugin Name: Webhook Sender
 * Description: Envia automaticamente notificações para servidores e canais via Webhook ao publicar matérias.
 * Version: 1.0.1
 * Author: Carlos Eduardo de Araújo / LightPress
 */

use LightPress\Core\Database;
use LightPress\Core\Hook;
use LightPress\Core\PluginManager;
use LightPress\Core\Security;
use LightPress\Core\Settings;

// 1. Registro do Hook de Ativação (Criação de Tabela Relacional Dedicada)
PluginManager::registerActivationHook('webhook-sender', function (): void {
    WebhookSenderService::ensureTableSchema();
});

// Helper de envio e formatação de webhooks
final class WebhookSenderService
{
    /**
     * Garante a criação da tabela e integridade da coluna is_success de forma transparente.
     */
    public static function ensureTableSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $driver = Database::getDriver();
            if ($driver === 'sqlite') {
                Database::query("
                    CREATE TABLE IF NOT EXISTS plugin_webhook_sender_logs (
                        post_id INTEGER PRIMARY KEY,
                        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        servers_count INTEGER NOT NULL DEFAULT 1,
                        is_success INTEGER NOT NULL DEFAULT 0,
                        last_response_codes TEXT NULL,
                        FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
                    )
                ");
            } else {
                Database::query("
                    CREATE TABLE IF NOT EXISTS plugin_webhook_sender_logs (
                        post_id INT UNSIGNED PRIMARY KEY,
                        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        servers_count INT UNSIGNED NOT NULL DEFAULT 1,
                        is_success TINYINT(1) NOT NULL DEFAULT 0,
                        last_response_codes TEXT NULL,
                        CONSTRAINT fk_pws_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                // Auto-migração caso a tabela tenha sido criada em versão anterior sem is_success
                $cols = Database::fetchAll("SHOW COLUMNS FROM plugin_webhook_sender_logs LIKE 'is_success'");
                if (empty($cols)) {
                    Database::query("ALTER TABLE plugin_webhook_sender_logs ADD COLUMN is_success TINYINT(1) NOT NULL DEFAULT 0 AFTER servers_count");
                }
            }
        } catch (\Throwable $e) {
            error_log("[WebhookSender] Erro ao verificar schema: " . $e->getMessage());
        }
    }

    /**
     * Valida se uma URL é segura para envio de webhooks, mitigando ataques de SSRF
     * (bloqueia IPs de loopback, faixas privadas, link-local e metadados de nuvem).
     */
    public static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }

        // Bloqueio explícito de localhost e metadados de cloud conhecidos
        $lowerHost = strtolower($host);
        if ($lowerHost === 'localhost' || $lowerHost === 'metadata.google.internal') {
            return false;
        }

        // Se for um endereço IP direto, valida faixas privadas/reservadas
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool)filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        // Para domínios/hostnames, resolve todos os registros de IP (IPv4)
        $ips = @gethostbynamel($host);
        if ($ips === false || empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            $isValidPublicIp = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if (!$isValidPublicIp) {
                return false;
            }
        }

        return true;
    }

    /**
     * Divide a string por vírgulas e/ou quebras de linha e retorna apenas URLs válidas e seguras.
     *
     * @return list<string>
     */
    public static function parseUrls(string $input): array
    {
        // Normaliza quebras de linha para vírgula
        $normalized = str_replace(["\r\n", "\r", "\n"], ',', $input);
        $parts = explode(',', $normalized);
        $clean = [];

        foreach ($parts as $url) {
            $url = trim($url);
            if (
                !empty($url) &&
                filter_var($url, FILTER_VALIDATE_URL) &&
                self::isSafeUrl($url)
            ) {
                $clean[] = $url;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Monta o payload rico compatível com Discord, Slack e listeners genéricos de webhook.
     */
    public static function buildPayload(int $postId, array $post, array $config): array
    {
        $cfg = !empty($config) ? $config : ($GLOBALS['config'] ?? []);
        $siteName = $cfg['app']['name'] ?? 'SpeedRacing';
        
        // Suporte explícito a canonical_origin (essencial em cron / terminal onde $_SERVER['HTTP_HOST'] não existe)
        $canonicalOrigin = $cfg['app']['canonical_origin'] ?? $cfg['app']['url'] ?? '';
        if (!empty($canonicalOrigin)) {
            $siteUrl = rtrim($canonicalOrigin, '/');
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $siteUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        } else {
            $siteUrl = 'https://www.speedracing.com.br';
        }

        $title = (string)($post['title'] ?? 'Nova publicação');
        $slug = (string)($post['slug'] ?? '');
        $postUrl = !empty($slug) ? "{$siteUrl}/{$slug}/" : "{$siteUrl}/";

        $summary = !empty($post['summary']) ? (string)$post['summary'] : '';
        if (empty($summary) && !empty($post['content_html'])) {
            $summary = trim(strip_tags((string)$post['content_html']));
            if (mb_strlen($summary) > 250) {
                $summary = mb_substr($summary, 0, 247) . '...';
            }
        }

        // Formatação da imagem destacada
        $thumbnailUrl = null;
        if (!empty($post['featured_image'])) {
            $rawImg = (string)$post['featured_image'];
            if (str_starts_with($rawImg, 'http://') || str_starts_with($rawImg, 'https://')) {
                $thumbnailUrl = $rawImg;
            } else {
                $thumbnailUrl = $siteUrl . '/' . ltrim($rawImg, '/');
            }
        }

        // Ícone / Favicon do site
        $siteIconUrl = null;
        if (!empty($cfg['app']['favicon'])) {
            $rawFav = (string)$cfg['app']['favicon'];
            $siteIconUrl = (str_starts_with($rawFav, 'http://') || str_starts_with($rawFav, 'https://'))
                ? $rawFav
                : $siteUrl . '/' . ltrim($rawFav, '/');
        }

        // Embed rico (estilo Discord)
        $embed = [
            'title'       => $title,
            'type'        => 'rich',
            'description' => strip_tags($summary),
            'url'         => $postUrl,
            'color'       => hexdec('e10600'), // Vermelho característico SpeedRacing
            'footer'      => [
                'text' => 'Publicado em ' . $siteName,
            ],
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if (!empty($thumbnailUrl)) {
            $embed['image'] = ['url' => $thumbnailUrl];
        }

        $payload = [
            'username'   => $siteName,
            'content'    => '📰 **Nova notícia publicada!**',
            'embeds'     => [$embed],
            // Dados estruturados universais para microserviços e outros webhooks
            'event'      => 'post_published',
            'post'       => [
                'id'           => $postId,
                'title'        => $title,
                'slug'         => $slug,
                'url'          => $postUrl,
                'summary'      => $summary,
                'category'     => $post['category'] ?? 'geral',
                'tags'         => $post['tags'] ?? '',
                'image'        => $thumbnailUrl,
                'published_at' => $post['published_at'] ?? gmdate('Y-m-d H:i:s'),
            ],
        ];

        if (!empty($siteIconUrl)) {
            $payload['avatar_url'] = $siteIconUrl;
        }

        return $payload;
    }

    /**
     * Envia o payload via POST HTTP para uma lista de URLs com timeout seguro.
     *
     * @param list<string> $urls
     * @param array $payload
     * @return array<string, int> Mapeamento de URL para código de status retornado
     */
    public static function dispatch(array $urls, array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            error_log('[WebhookSender] Falha ao serializar payload JSON.');
            return [];
        }

        $results = [];

        foreach ($urls as $url) {
            if (!self::isSafeUrl($url)) {
                error_log("[WebhookSender] URL bloqueada por política de segurança SSRF: {$url}");
                $results[$url] = 400;
                continue;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonPayload,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'User-Agent: LightPress-WebhookSender/1.0',
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => 0,
            ]);

            curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!empty($curlError)) {
                error_log("[WebhookSender] Erro ao enviar para {$url}: {$curlError}");
            }

            $results[$url] = $httpCode;
        }

        return $results;
    }
}

// 2. Registro do Evento de Publicação de Notícia
Hook::addAction('post_published', function (int $postId, array $data, ?array $current = null, bool $isFirstPublish = true): void {
    // Assegura estrutura de tabela atualizada
    WebhookSenderService::ensureTableSchema();

    // 1. Verifica se este post já foi entregue com SUCESSO anteriormente (evita envios repetidos)
    try {
        $alreadySent = Database::fetchValue(
            "SELECT post_id FROM plugin_webhook_sender_logs WHERE post_id = ? AND is_success = 1",
            [$postId]
        );
        if (!empty($alreadySent)) {
            return;
        }
    } catch (\Throwable $e) {
        error_log("[WebhookSender] Erro ao consultar histórico de envio: " . $e->getMessage());
    }

    // 2. Recupera URLs cadastradas
    $rawUrls = (string)Settings::get('plugin_webhook_sender_urls', '');
    $urls = WebhookSenderService::parseUrls($rawUrls);

    if (empty($urls)) {
        return; // Nenhum servidor configurado
    }

    // 3. Monta o payload universal
    $payload = WebhookSenderService::buildPayload($postId, $data, $GLOBALS['config'] ?? []);

    // 4. Dispara os webhooks
    $responses = WebhookSenderService::dispatch($urls, $payload);

    // 5. Avalia se houve sucesso na entrega (ao menos um servidor com HTTP 2xx)
    $successCount = 0;
    foreach ($responses as $code) {
        if ($code >= 200 && $code < 300) {
            $successCount++;
        }
    }
    $isSuccess = ($successCount > 0) ? 1 : 0;

    // 6. Registra ou atualiza o log no banco relacional (compatível com MySQL e SQLite)
    // Se falhar (is_success = 0), fica registrado para visualização no painel,
    // mas NÃO bloqueia retentativas futuras quando o post for reprocessado ou re-salvo!
    try {
        if (Database::getDriver() === 'sqlite') {
            Database::query("
                INSERT INTO plugin_webhook_sender_logs (post_id, sent_at, servers_count, is_success, last_response_codes)
                VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?)
                ON CONFLICT(post_id) DO UPDATE SET
                    sent_at = CURRENT_TIMESTAMP,
                    servers_count = excluded.servers_count,
                    is_success = excluded.is_success,
                    last_response_codes = excluded.last_response_codes
            ", [
                $postId,
                count($urls),
                $isSuccess,
                json_encode($responses, JSON_UNESCAPED_SLASHES)
            ]);
        } else {
            Database::query("
                INSERT INTO plugin_webhook_sender_logs (post_id, sent_at, servers_count, is_success, last_response_codes)
                VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    sent_at = CURRENT_TIMESTAMP,
                    servers_count = VALUES(servers_count),
                    is_success = VALUES(is_success),
                    last_response_codes = VALUES(last_response_codes)
            ", [
                $postId,
                count($urls),
                $isSuccess,
                json_encode($responses, JSON_UNESCAPED_SLASHES)
            ]);
        }
    } catch (\Throwable $e) {
        error_log("[WebhookSender] Erro ao persistir log de webhook: " . $e->getMessage());
    }
}, 10);
