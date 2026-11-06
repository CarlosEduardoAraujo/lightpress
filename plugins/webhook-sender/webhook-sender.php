<?php
declare(strict_types=1);

/**
 * Plugin Name: Webhook Sender
 * Description: Envia automaticamente notificações para servidores e canais via Webhook ao publicar matérias.
 * Version: 1.0.0
 * Author: Carlos Eduardo de Araújo / LightPress
 */

use LightPress\Core\Database;
use LightPress\Core\Hook;
use LightPress\Core\PluginManager;
use LightPress\Core\Security;
use LightPress\Core\Settings;

// 1. Registro do Hook de Ativação (Criação de Tabela Relacional Dedicada)
PluginManager::registerActivationHook('webhook-sender', function (): void {
    $driver = Database::getDriver();
    if ($driver === 'sqlite') {
        Database::query("
            CREATE TABLE IF NOT EXISTS plugin_webhook_sender_logs (
                post_id INTEGER PRIMARY KEY,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                servers_count INTEGER NOT NULL DEFAULT 1,
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
                last_response_codes TEXT NULL,
                CONSTRAINT fk_pws_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
});

// Helper de envio e formatação de webhooks
final class WebhookSenderService
{
    /**
     * Divide a string por vírgulas e/ou quebras de linha e retorna apenas URLs válidas.
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
                preg_match('/^https?:\/\//i', $url)
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
        $siteName = $config['app']['name'] ?? 'SpeedRacing';
        $siteUrl = rtrim($config['app']['canonical_origin'] ?? $config['app']['url'] ?? '', '/');
        if (empty($siteUrl) && isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $siteUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
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
        if (!empty($config['app']['favicon'])) {
            $rawFav = (string)$config['app']['favicon'];
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
    // 1. Verifica se este post já foi enviado anteriormente (evita envios repetidos em edições subsequentes)
    try {
        $alreadySent = Database::fetchValue("SELECT post_id FROM plugin_webhook_sender_logs WHERE post_id = ?", [$postId]);
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
    global $config;
    $payload = WebhookSenderService::buildPayload($postId, $data, $config ?? []);

    // 4. Dispara os webhooks
    $responses = WebhookSenderService::dispatch($urls, $payload);

    // 5. Registra o log no banco relacional
    try {
        Database::query("
            INSERT INTO plugin_webhook_sender_logs (post_id, sent_at, servers_count, last_response_codes)
            VALUES (?, CURRENT_TIMESTAMP, ?, ?)
        ", [
            $postId,
            count($urls),
            json_encode($responses, JSON_UNESCAPED_SLASHES)
        ]);
    } catch (\Throwable $e) {
        error_log("[WebhookSender] Erro ao persistir log de webhook: " . $e->getMessage());
    }
}, 10);
