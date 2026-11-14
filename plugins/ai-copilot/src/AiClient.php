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
            $timeout,
            \LightPress\Core\SafeHttpClient::PROFILE_LOCAL_AI
        );
    }

    /**
     * Testa a conectividade com o provedor configurado enviando um ping de baixo custo.
     * Suporta passagem de configurações e chaves da tela em tempo real antes de salvar.
     *
     * @param array<string, mixed>|null $overrideConfig Parâmetros temporários da interface
     * @return array{ok: bool, message: string, provider: string, model: string, duration_ms: int}
     */
    public static function testConnection(?array $overrideConfig = null): array
    {
        $start = microtime(true);
        $provider = !empty($overrideConfig['provider']) ? (string)$overrideConfig['provider'] : self::getActiveProvider();
        $model = !empty($overrideConfig['model']) ? (string)$overrideConfig['model'] : self::getModel();
        $timeout = !empty($overrideConfig['timeout']) ? (int)$overrideConfig['timeout'] : self::getTimeout();

        $systemPrompt = 'Você é um assistente de teste de conexão. Responda estritamente em JSON no formato {"ping": "pong"}.';
        $userPrompt = 'Teste de conexão. Retorne pong em JSON.';

        try {
            if ($provider === self::PROVIDER_GEMINI) {
                $apiKey = !empty($overrideConfig['gemini_key']) ? (string)$overrideConfig['gemini_key'] : self::getApiKey(self::PROVIDER_GEMINI);
                if (empty($apiKey)) {
                    throw new RuntimeException('Chave de API do Google Gemini não está preenchida.');
                }
                $res = self::callGemini($model, $systemPrompt, $userPrompt, null, null, $timeout, $apiKey);
            } elseif ($provider === self::PROVIDER_OPENAI) {
                $apiKey = !empty($overrideConfig['openai_key']) ? (string)$overrideConfig['openai_key'] : self::getApiKey(self::PROVIDER_OPENAI);
                if (empty($apiKey)) {
                    throw new RuntimeException('Chave de API da OpenAI não está preenchida.');
                }
                $res = self::callOpenAi('https://api.openai.com/v1', $apiKey, $model, $systemPrompt, $userPrompt, null, null, $timeout);
            } else {
                $endpoint = !empty($overrideConfig['custom_endpoint']) ? (string)$overrideConfig['custom_endpoint'] : self::getCustomEndpoint();
                self::validateEndpointUrl($endpoint);
                $apiKey = !empty($overrideConfig['custom_key']) ? (string)$overrideConfig['custom_key'] : self::getApiKey(self::PROVIDER_CUSTOM);
                $res = self::callOpenAi($endpoint, $apiKey, $model, $systemPrompt, $userPrompt, null, null, $timeout, \LightPress\Core\SafeHttpClient::PROFILE_LOCAL_AI);
            }

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
        int $timeout,
        ?string $overrideApiKey = null
    ): array {
        $apiKey = !empty($overrideApiKey) ? $overrideApiKey : self::getApiKey(self::PROVIDER_GEMINI);
        if (empty($apiKey)) {
            throw new RuntimeException('Chave de API do Google Gemini não está configurada.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent';

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
            'x-goog-api-key: ' . $apiKey,
        ], $jsonPayload, $timeout, \LightPress\Core\SafeHttpClient::PROFILE_STRICT_PUBLIC);

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
        int $timeout,
        string $profile = \LightPress\Core\SafeHttpClient::PROFILE_STRICT_PUBLIC
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

        $response = self::executeCurl($url, $headers, $jsonPayload, $timeout, $profile);

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
     * Executa a requisição cURL delegando para o SafeHttpClient (DNS pinning, mitigação SSRF e TLS).
     *
     * @param list<string> $headers
     */
    private static function executeCurl(
        string $url,
        array $headers,
        string $payload,
        int $timeout,
        string $profile = \LightPress\Core\SafeHttpClient::PROFILE_STRICT_PUBLIC
    ): string {
        $curlOpts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_USERAGENT      => 'LightPress-AiCopilot/1.0',
        ];

        $res = \LightPress\Core\SafeHttpClient::request($url, $curlOpts, $profile);

        if (!$res['ok']) {
            $err = !empty($res['error']) ? $res['error'] : sprintf('API retornou código HTTP %d: %s', $res['status'], substr($res['body'], 0, 300));
            throw new RuntimeException($err);
        }

        return $res['body'];
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
     * Suporta Ollama/vLLM local e na rede local (127.0.0.1 / RFC1918), mas bloqueia rigorosamente
     * serviços de metadados de nuvem (AWS/GCP/Azure/Alibaba/Oracle) em todas as representações (IPv4 e IPv6).
     */
    private static function validateEndpointUrl(string $url): void
    {
        $target = \LightPress\Core\SafeHttpClient::resolveSafeTarget($url, \LightPress\Core\SafeHttpClient::PROFILE_LOCAL_AI);
        if ($target === null) {
            throw new RuntimeException('URL do endpoint personalizado é inválida ou foi bloqueada por políticas defensivas de segurança (SSRF/Metadados de Nuvem).');
        }
    }
}
