<?php
declare(strict_types=1);

namespace LightPress\Plugin\AiCopilot;

use LightPress\Core\Settings;
use RuntimeException;

/**
 * Cliente HTTP unificado e ultraleve para APIs de Inteligência Artificial.
 * 
 * Suporta Google Gemini API, OpenAI API e endpoints compatíveis com OpenAI (Ollama, vLLM, LocalAI).
 * Implementado com curl nativo do PHP, timeout rígido e zero dependências externas.
 */
final class AiClient
{
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_CUSTOM = 'custom';

    /**
     * Retorna o provedor ativo configurado.
     */
    public static function getActiveProvider(): string
    {
        $prov = (string)Settings::get('ai_copilot_provider', self::PROVIDER_GEMINI);
        if (!in_array($prov, [self::PROVIDER_GEMINI, self::PROVIDER_OPENAI, self::PROVIDER_CUSTOM], true)) {
            return self::PROVIDER_GEMINI;
        }
        return $prov;
    }

    /**
     * Retorna a chave de API para o provedor solicitado (ou ativo).
     * Dá prioridade a constantes em config.local.php antes da tabela settings.
     */
    public static function getApiKey(?string $provider = null): string
    {
        $p = $provider ?? self::getActiveProvider();

        if ($p === self::PROVIDER_GEMINI) {
            if (defined('AI_COPILOT_GEMINI_API_KEY') && !empty(constant('AI_COPILOT_GEMINI_API_KEY'))) {
                return (string)constant('AI_COPILOT_GEMINI_API_KEY');
            }
            return (string)Settings::get('ai_copilot_gemini_key', '');
        }

        if ($p === self::PROVIDER_OPENAI) {
            if (defined('AI_COPILOT_OPENAI_API_KEY') && !empty(constant('AI_COPILOT_OPENAI_API_KEY'))) {
                return (string)constant('AI_COPILOT_OPENAI_API_KEY');
            }
            return (string)Settings::get('ai_copilot_openai_key', '');
        }

        if ($p === self::PROVIDER_CUSTOM) {
            if (defined('AI_COPILOT_CUSTOM_API_KEY') && !empty(constant('AI_COPILOT_CUSTOM_API_KEY'))) {
                return (string)constant('AI_COPILOT_CUSTOM_API_KEY');
            }
            return (string)Settings::get('ai_copilot_custom_key', '');
        }

        return '';
    }

    /**
     * Verifica se existe chave configurada (via constante ou settings).
     */
    public static function hasApiKey(?string $provider = null): bool
    {
        $p = $provider ?? self::getActiveProvider();
        if ($p === self::PROVIDER_CUSTOM) {
            // Em provedores locais como Ollama, a chave pode ser vazia/opcional
            return true;
        }
        return !empty(self::getApiKey($p));
    }

    /**
     * Retorna se a chave de API veio de constante em config.local.php.
     */
    public static function isKeyFromConfig(?string $provider = null): bool
    {
        $p = $provider ?? self::getActiveProvider();
        if ($p === self::PROVIDER_GEMINI && defined('AI_COPILOT_GEMINI_API_KEY')) {
            return true;
        }
        if ($p === self::PROVIDER_OPENAI && defined('AI_COPILOT_OPENAI_API_KEY')) {
            return true;
        }
        if ($p === self::PROVIDER_CUSTOM && defined('AI_COPILOT_CUSTOM_API_KEY')) {
            return true;
        }
        return false;
    }

    /**
     * Retorna o endpoint base para provedor customizado.
     */
    public static function getCustomEndpoint(): string
    {
        if (defined('AI_COPILOT_CUSTOM_ENDPOINT') && !empty(constant('AI_COPILOT_CUSTOM_ENDPOINT'))) {
            return rtrim((string)constant('AI_COPILOT_CUSTOM_ENDPOINT'), '/');
        }
        return rtrim((string)Settings::get('ai_copilot_custom_endpoint', 'http://127.0.0.1:11434/v1'), '/');
    }

    /**
     * Retorna o modelo padrão ou override de módulo.
     */
    public static function getModel(?string $moduleKey = null): string
    {
        $prov = self::getActiveProvider();

        if ($moduleKey) {
            $override = (string)Settings::get("ai_copilot_{$moduleKey}_model", '');
            if (!empty($override)) {
                return $override;
            }
        }

        $defaultModel = (string)Settings::get('ai_copilot_model', '');
        if (!empty($defaultModel)) {
            return $defaultModel;
        }

        return match ($prov) {
            self::PROVIDER_GEMINI => 'gemini-2.5-flash',
            self::PROVIDER_OPENAI => 'gpt-4o-mini',
            default => 'llama3',
        };
    }

    /**
     * Retorna o tempo limite de rede configurado (em segundos).
     */
    public static function getTimeout(): int
    {
        $t = (int)Settings::get('ai_copilot_timeout', '8');
        return max(3, min(30, $t));
    }

    /**
     * Envia uma mensagem para o modelo de IA e exige retorno em JSON estruturado.
     *
     * @param string $systemPrompt Instruções de persona e formato de saída
     * @param string $userPrompt Conteúdo da matéria ou solicitação do usuário
     * @param string|null $imageBase64 Imagem codificada em Base64 (para análise multimodal)
     * @param string|null $imageMime Tipo MIME da imagem (ex: 'image/webp')
     * @param string|null $moduleKey Identificador do módulo ('linking' ou 'vision') para modelo específico
     * @return array<string, mixed> JSON decodificado retornado pelo modelo
     * @throws RuntimeException Em caso de erro de rede, timeout ou resposta inválida
     */
    public static function chatJson(
        string $systemPrompt,
        string $userPrompt,
        ?string $imageBase64 = null,
        ?string $imageMime = 'image/webp',
        ?string $moduleKey = null
    ): array {
        $provider = self::getActiveProvider();
        $model = self::getModel($moduleKey);
        $timeout = self::getTimeout();

        if ($provider === self::PROVIDER_GEMINI) {
            return self::callGemini($model, $systemPrompt, $userPrompt, $imageBase64, $imageMime, $timeout);
        }

        if ($provider === self::PROVIDER_OPENAI) {
            return self::callOpenAi(
                'https://api.openai.com/v1',
                self::getApiKey(self::PROVIDER_OPENAI),
                $model,
                $systemPrompt,
                $userPrompt,
                $imageBase64,
                $imageMime,
                $timeout
            );
        }

        // Custom / OpenAI-compatible
        $customEndpoint = self::getCustomEndpoint();
        self::validateEndpointUrl($customEndpoint);
        return self::callOpenAi(
            $customEndpoint,
            self::getApiKey(self::PROVIDER_CUSTOM),
            $model,
            $systemPrompt,
            $userPrompt,
            $imageBase64,
            $imageMime,
            $timeout
        );
    }

    /**
     * Testa a conectividade com o provedor configurado enviando um ping de baixo custo.
     *
     * @return array{ok: bool, message: string, provider: string, model: string, duration_ms: int}
     */
    public static function testConnection(): array
    {
        $start = microtime(true);
        $provider = self::getActiveProvider();
        $model = self::getModel();

        try {
            $res = self::chatJson(
                'Você é um assistente de teste de conexão. Responda estritamente em JSON no formato {"ping": "pong"}.',
                'Teste de conexão. Retorne pong em JSON.',
                null,
                null,
                null
            );

            $durationMs = (int)round((microtime(true) - $start) * 1000);
            return [
                'ok' => true,
                'message' => 'Conexão estabelecida com sucesso! Resposta recebida em ' . $durationMs . 'ms.',
                'provider' => $provider,
                'model' => $model,
                'duration_ms' => $durationMs,
                'response' => $res,
            ];
        } catch (\Throwable $e) {
            $durationMs = (int)round((microtime(true) - $start) * 1000);
            return [
                'ok' => false,
                'message' => 'Falha ao conectar com o provedor: ' . $e->getMessage(),
                'provider' => $provider,
                'model' => $model,
                'duration_ms' => $durationMs,
            ];
        }
    }

    /**
     * Executa chamada à API do Google Gemini.
     */
    private static function callGemini(
        string $model,
        string $systemPrompt,
        string $userPrompt,
        ?string $imageBase64,
        ?string $imageMime,
        int $timeout
    ): array {
        $apiKey = self::getApiKey(self::PROVIDER_GEMINI);
        if (empty($apiKey)) {
            throw new RuntimeException('Chave de API do Google Gemini não está configurada.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

        $parts = [];
        // Instrução de sistema + prompt do usuário
        $parts[] = [
            'text' => "INSTRUÇÃO DO SISTEMA:\n" . $systemPrompt . "\n\nSOLICITAÇÃO DO USUÁRIO:\n" . $userPrompt,
        ];

        if (!empty($imageBase64)) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $imageMime ?: 'image/webp',
                    'data' => $imageBase64,
                ],
            ];
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature' => 0.2,
            ],
        ];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = self::executeCurl($url, [
            'Content-Type: application/json',
        ], $jsonPayload, $timeout);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta inválida do Gemini: corpo não é JSON válido.');
        }

        if (isset($data['error'])) {
            $errMessage = $data['error']['message'] ?? 'Erro desconhecido do Gemini';
            throw new RuntimeException('Erro na API do Gemini: ' . $errMessage);
        }

        $rawText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($rawText === null) {
            throw new RuntimeException('Gemini não retornou nenhum texto estruturado no candidato.');
        }

        return self::extractAndDecodeJson($rawText);
    }

    /**
     * Executa chamada a endpoints compatíveis com a especificação OpenAI Chat Completions.
     */
    private static function callOpenAi(
        string $baseUrl,
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        ?string $imageBase64,
        ?string $imageMime,
        int $timeout
    ): array {
        $url = rtrim($baseUrl, '/') . '/chat/completions';

        $userContent = [];
        $userContent[] = [
            'type' => 'text',
            'text' => $userPrompt,
        ];

        if (!empty($imageBase64)) {
            $mime = $imageMime ?: 'image/webp';
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $mime . ';base64,' . $imageBase64,
                ],
            ];
        }

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.2,
        ];

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = self::executeCurl($url, $headers, $jsonPayload, $timeout);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta inválida da OpenAI: corpo não é JSON válido.');
        }

        if (isset($data['error'])) {
            $errMessage = is_array($data['error']) ? ($data['error']['message'] ?? 'Erro desconhecido') : (string)$data['error'];
            throw new RuntimeException('Erro na API da OpenAI: ' . $errMessage);
        }

        $rawText = $data['choices'][0]['message']['content'] ?? null;
        if ($rawText === null) {
            throw new RuntimeException('Modelo não retornou mensagem de resposta.');
        }

        return self::extractAndDecodeJson($rawText);
    }

    /**
     * Executa a requisição cURL com opções seguras e tratamento de exceção.
     *
     * @param list<string> $headers
     */
    private static function executeCurl(string $url, array $headers, string $payload, int $timeout): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'LightPress-AiCopilot/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        if ($curlErrNo !== 0) {
            if ($curlErrNo === CURLE_OPERATION_TIMEDOUT) {
                throw new RuntimeException(sprintf('Tempo limite esgotado (%ds) aguardando resposta da IA.', $timeout));
            }
            throw new RuntimeException(sprintf('Erro de conexão cURL (%d): %s', $curlErrNo, $curlErr));
        }

        if ($httpCode >= 400) {
            $msg = sprintf('API retornou código HTTP %d: %s', $httpCode, substr((string)$response, 0, 300));
            throw new RuntimeException($msg);
        }

        return (string)$response;
    }

    /**
     * Decodifica o texto JSON do modelo, limpando eventuais blocos markdown (```json ... ```).
     */
    private static function extractAndDecodeJson(string $text): array
    {
        $clean = trim($text);

        // Remove blocos de formatação markdown se houver
        if (str_starts_with($clean, '```json')) {
            $clean = substr($clean, 7);
        } elseif (str_starts_with($clean, '```')) {
            $clean = substr($clean, 3);
        }

        if (str_ends_with($clean, '```')) {
            $clean = substr($clean, 0, -3);
        }

        $clean = trim($clean);

        try {
            $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable) {
            // Se falhar a decodificação estrita, tenta encontrar chaves JSON {}
            if (preg_match('/\{[\s\S]*\}/', $clean, $matches)) {
                $subDecoded = json_decode($matches[0], true);
                if (is_array($subDecoded)) {
                    return $subDecoded;
                }
            }
        }

        throw new RuntimeException('A IA não retornou um JSON estruturado válido. Texto: ' . substr($clean, 0, 150));
    }

    /**
     * Valida URLs de endpoints personalizados contra SSRF, DNS Rebinding e metadados de nuvem.
     */
    private static function validateEndpointUrl(string $url): void
    {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host']) || empty($parsed['scheme'])) {
            throw new RuntimeException('URL do endpoint personalizado é inválida.');
        }

        // Bloqueia esquemas não-HTTP(S)
        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            throw new RuntimeException('Apenas protocolos HTTP e HTTPS são permitidos para endpoints de IA.');
        }

        $host = strtolower($parsed['host']);

        // Bloqueia expressamente AWS/GCP/Azure metadata service por nome de host
        if ($host === '169.254.169.254' || str_contains($host, 'metadata.google.internal') || str_starts_with($host, 'fe80:') || str_starts_with($host, 'fd00:ec2:')) {
            throw new RuntimeException('Acesso a serviços de metadados de nuvem é estritamente proibido.');
        }

        // Resolução de IP para mitigar DNS Rebinding contra faixas de metadados de nuvem
        $resolvedIp = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($resolvedIp);
            if ($ipLong !== false) {
                // 169.254.0.0/16 (Link-local / AWS / GCP / Azure metadata 169.254.169.254)
                $mask16 = ip2long('255.255.0.0');
                if (($ipLong & $mask16) === (ip2long('169.254.0.0') & $mask16)) {
                    throw new RuntimeException('Acesso a serviços de metadados de nuvem (169.254.0.0/16) é estritamente proibido.');
                }
                // 100.64.0.0/10 (Carrier-Grade NAT / Alibaba Cloud metadata 100.100.100.200)
                $mask10 = ip2long('255.192.0.0');
                if (($ipLong & $mask10) === (ip2long('100.64.0.0') & $mask10)) {
                    throw new RuntimeException('Acesso a endereços da faixa Carrier-Grade / Cloud Metadata (100.64.0.0/10) é estritamente proibido.');
                }
            }
        }
    }
}
