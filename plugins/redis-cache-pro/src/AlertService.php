<?php
declare(strict_types=1);

namespace LightPress\Plugin\RedisCachePro;

use LightPress\Core\Settings;
use Throwable;

/**
 * Serviço de Alertas e Notificações de Incidentes via Webhook e E-mail.
 */
final class AlertService
{
    private static int $lastAlertTime = 0;
    private static int $cooldownSeconds = 300; // 5 minutos de intervalo entre alertas repetidos

    /**
     * Dispara alerta de acionamento do Circuit Breaker.
     */
    public static function notifyCircuitBreaker(string $reason, array $settings): void
    {
        if (empty($settings['notify_circuit_breaker'])) {
            return;
        }

        if (time() - self::$lastAlertTime < self::$cooldownSeconds) {
            return;
        }
        self::$lastAlertTime = time();

        $siteTitle = Settings::get('site_title', 'LightPress');
        $siteUrl = rtrim($GLOBALS['config']['app']['canonical_origin'] ?? 'http://localhost', '/');

        $title = "🚨 [Alerta Crítico] Redis Circuit Breaker Acionado no {$siteTitle}";
        $description = "O disjuntor do **Redis Cache Pro** foi acionado (estado OPEN) devido a falhas consecutivas de comunicação com o servidor Redis.\n\n"
            . "**Motivo:** {$reason}\n"
            . "**Ação Automática:** O LightPress ativou o modo de bypass/fallback transparente para manter o site 100% operacional sem interrupções.\n"
            . "**Horário:** " . date('Y-m-d H:i:s') . " UTC";

        self::dispatch($title, $description, 0xef4444, $settings, [
            ['name' => 'Site', 'value' => $siteUrl, 'inline' => true],
            ['name' => 'Status do Cache', 'value' => 'Degradado (Bypass)', 'inline' => true],
            ['name' => 'Tentativa de Recuperação', 'value' => 'A cada 30 segundos', 'inline' => true],
        ]);
    }

    /**
     * Dispara alerta de pico de memória RAM no Redis.
     */
    public static function notifyMemorySpike(string $usedMemory, string $maxMemory, float $percentage, array $settings): void
    {
        if (empty($settings['notify_memory_spike'])) {
            return;
        }

        if (time() - self::$lastAlertTime < self::$cooldownSeconds) {
            return;
        }
        self::$lastAlertTime = time();

        $siteTitle = Settings::get('site_title', 'LightPress');
        $siteUrl = rtrim($GLOBALS['config']['app']['canonical_origin'] ?? 'http://localhost', '/');

        $title = "⚠️ [Alerta de Memória] Redis atingiu {$percentage}% da RAM no {$siteTitle}";
        $description = "O consumo de memória do servidor Redis ultrapassou o limite seguro configurado no **Redis Cache Pro**.\n\n"
            . "**Memória Utilizada:** {$usedMemory}\n"
            . "**Limite de Memória (maxmemory):** {$maxMemory}\n"
            . "**Porcentagem Ocupada:** " . round($percentage, 1) . "%\n"
            . "**Horário:** " . date('Y-m-d H:i:s') . " UTC";

        self::dispatch($title, $description, 0xf59e0b, $settings, [
            ['name' => 'Site', 'value' => $siteUrl, 'inline' => true],
            ['name' => 'Ação Recomendada', 'value' => 'Verifique o Big Key Hunter ou expanda o maxmemory no redis.conf', 'inline' => false],
        ]);
    }

    /**
     * Envia notificação de teste para validar a URL do Webhook informada.
     *
     * @return array{success: bool, message: string, code: int}
     */
    public static function sendTestNotification(string $webhookUrl, ?string $alertEmail = null): array
    {
        if (empty($webhookUrl) && empty($alertEmail)) {
            return ['success' => false, 'message' => 'Nenhuma URL de webhook ou e-mail fornecido.', 'code' => 400];
        }

        $siteTitle = Settings::get('site_title', 'LightPress');
        $siteUrl = rtrim($GLOBALS['config']['app']['canonical_origin'] ?? 'http://localhost', '/');

        $title = "🔔 Teste de Notificação - Redis Cache Pro";
        $description = "Esta é uma mensagem de verificação gerada pelo painel administrativo do **Redis Cache Pro** no **{$siteTitle}**.\n\nSe você recebeu este alerta, seus canais de notificação e webhooks estão configurados e prontos para operação!";

        $settings = [
            'webhook_url' => $webhookUrl,
            'alert_email' => $alertEmail ?? '',
        ];

        return self::dispatch($title, $description, 0x10b981, $settings, [
            ['name' => 'Origem', 'value' => $siteUrl, 'inline' => true],
            ['name' => 'Data / Hora', 'value' => date('d/m/Y H:i:s') . ' UTC', 'inline' => true],
            ['name' => 'Status', 'value' => 'Conexão Operacional', 'inline' => true],
        ]);
    }

    /**
     * Despacha o alerta para o Webhook e/ou E-mail configurados.
     *
     * @param array<int, array{name: string, value: string, inline?: bool}> $fields
     * @return array{success: bool, message: string, code: int}
     */
    private static function dispatch(string $title, string $description, int $color, array $settings, array $fields = []): array
    {
        $webhookUrl = trim((string)($settings['webhook_url'] ?? ''));
        $alertEmail = trim((string)($settings['alert_email'] ?? ''));
        $success = false;
        $responseCode = 0;
        $errorMsg = '';

        // 1. Envio via Webhook (Discord / Slack / Teams / n8n / Custom)
        if (!empty($webhookUrl) && filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            $siteTitle = Settings::get('site_title', 'LightPress');
            
            // Payload universal (compatível com embeds de Discord e Webhooks HTTP padrão)
            $payload = [
                'username' => 'Redis Cache Pro (' . $siteTitle . ')',
                'content'  => "**{$title}**\n{$description}",
                'embeds'   => [
                    [
                        'title'       => $title,
                        'description' => $description,
                        'color'       => $color,
                        'fields'      => $fields,
                        'footer'      => [
                            'text' => 'Redis Cache Pro · LightPress Enterprise Cache',
                        ],
                        'timestamp'   => gmdate('c'),
                    ],
                ],
                'event'    => 'redis_incident_alert',
            ];

            try {
                $jsonData = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $res = \LightPress\Core\SafeHttpClient::request($webhookUrl, [
                    CURLOPT_POST       => true,
                    CURLOPT_POSTFIELDS => $jsonData,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'User-Agent: LightPress-RedisCachePro/1.0',
                    ],
                    CURLOPT_TIMEOUT        => 6,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ], \LightPress\Core\SafeHttpClient::PROFILE_STRICT_PUBLIC);

                $responseCode = $res['status'];
                if ($res['ok']) {
                    $success = true;
                } else {
                    $errorMsg = "HTTP {$responseCode}: " . ($res['error'] ?: substr($res['body'], 0, 150));
                }
            } catch (Throwable $e) {
                $errorMsg = $e->getMessage();
            }
        }

        // 2. Envio via E-mail padrão PHP mail() se configurado
        if (!empty($alertEmail) && filter_var($alertEmail, FILTER_VALIDATE_EMAIL)) {
            $headers = [
                'From: ' . 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: LightPress-RedisCachePro',
            ];
            $body = "{$title}\n\n{$description}\n\nDetalhes:\n";
            foreach ($fields as $field) {
                $body .= "- {$field['name']}: {$field['value']}\n";
            }
            @mail($alertEmail, $title, $body, implode("\r\n", $headers));
            $success = true;
        }

        if ($success) {
            return ['success' => true, 'message' => 'Notificação despachada com sucesso!', 'code' => $responseCode ?: 200];
        }

        return [
            'success' => false,
            'message' => 'Falha ao enviar notificação: ' . ($errorMsg ?: 'Destino não configurado ou inacessível.'),
            'code'    => $responseCode ?: 500,
        ];
    }
}
