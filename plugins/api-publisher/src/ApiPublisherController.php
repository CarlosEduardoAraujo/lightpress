<?php
declare(strict_types=1);

namespace LightPress\Plugin\ApiPublisher;

use LightPress\Core\BlockCompiler;
use LightPress\Core\PluginManager;
use LightPress\Core\Security;
use RuntimeException;

/**
 * Controller REST do Endpoint de Ingestão de Matérias
 * 
 * Endpoint: POST /api/v1/posts (e alias /api/v1/drafts)
 * Compatível com o nó HTTP Request do n8n e com o workflow Reddit - IA.json.
 */
final class ApiPublisherController
{
    /**
     * Ponto de entrada para requisições de criação de rascunhos.
     */
    public static function handleCreatePost(): void
    {
        // 1. Garantir que o plugin está ativo
        if (!PluginManager::isActive('api-publisher')) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Endpoint de API não encontrado ou inativo.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 2. Cabeçalhos HTTP de Segurança e Não-Cache
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        // 3. Validação do Método HTTP
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (strtoupper($method) !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode(['ok' => false, 'error' => 'Método não permitido. Utilize POST.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 4. Extração e Validação de Credenciais (Basic Auth)
        $credentials = ApiPublisherService::extractBasicAuthCredentials();
        if ($credentials === null) {
            http_response_code(401);
            header('WWW-Authenticate: Basic realm="LightPress API Publisher"');
            echo json_encode(['ok' => false, 'error' => 'Autenticação obrigatória via HTTP Basic Auth.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $clientIp = Security::getClientIp();

        try {
            $auth = ApiPublisherService::authenticate(
                $credentials['username'] !== '' ? $credentials['username'] : null,
                $credentials['password'],
                $clientIp
            );
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $httpCode = ($code >= 400 && $code <= 499) ? $code : 401;
            http_response_code($httpCode);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 5. Leitura e Decodificação do Payload
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Corpo da requisição vazio.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Limite defensivo de payload (2 MB)
        if (strlen($rawBody) > 2 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => 'O payload enviado excede o limite máximo permitido de 2 MB.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Payload JSON malformado ou inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 6. Mapeamento de Campos com Suporte Nativo aos Aliases do workflow Reddit - IA.json
        $title = trim((string)($input['title'] ?? $input['titulo'] ?? ''));
        $summary = trim((string)($input['summary'] ?? $input['resumo'] ?? ''));
        $content = (string)($input['content'] ?? $input['texto_completo'] ?? '');
        $contentFormat = strtolower(trim((string)($input['content_format'] ?? 'markdown')));
        $focusKeyword = trim((string)($input['focus_keyword'] ?? $input['primary_focus_keyword'] ?? ''));
        $seoTitle = trim((string)($input['seo_title'] ?? ''));
        $seoDescription = trim((string)($input['seo_description'] ?? ''));
        $slug = trim((string)($input['slug'] ?? ''));
        $tagsInput = $input['tags'] ?? null;

        // Idempotency: external_id, reddit_id ou header Idempotency-Key
        $externalKey = trim((string)($input['external_id'] ?? $input['reddit_id'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')));

        // 7. Validação de Campos Obrigatórios
        if ($title === '' || trim($content) === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Os campos "title" (ou "titulo") e "content" (ou "texto_completo") são obrigatórios.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $canonicalOrigin = rtrim($GLOBALS['config']['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

        // 8. Verificação de Idempotência
        if ($externalKey !== '') {
            $existing = ApiPublisherService::checkIdempotency($externalKey);
            if ($existing !== null) {
                $editUrl = "{$canonicalOrigin}/admin/editor.php?id={$existing['post_id']}";
                http_response_code(200);
                echo json_encode([
                    'ok' => true,
                    'idempotent' => true,
                    'id' => $existing['post_id'],
                    'slug' => $existing['slug'],
                    'status' => $existing['status'],
                    'category' => $existing['category'],
                    'author_id' => $existing['user_id'],
                    'edit_url' => $editUrl,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // 9. Normalização de Tags (aceita array de strings ou string com vírgulas)
        $cleanTags = null;
        if (is_array($tagsInput)) {
            $tagList = array_values(array_filter(array_map('trim', $tagsInput), fn($t) => $t !== ''));
            if (!empty($tagList)) {
                $cleanTags = implode(', ', $tagList);
            }
        } elseif (is_string($tagsInput) && trim($tagsInput) !== '') {
            $cleanTags = trim($tagsInput);
        }

        // 10. Processamento de Conteúdo: Markdown -> HTML Semântico -> Sanitização XSS -> Blocos JSON
        if ($contentFormat === 'markdown') {
            $parsedHtml = MarkdownParser::parse($content);
            $contentHtml = BlockCompiler::sanitizeHtml($parsedHtml);
            $contentJson = BlockCompiler::htmlToBlocks($contentHtml);
        } else {
            $contentHtml = BlockCompiler::sanitizeHtml($content);
            $contentJson = BlockCompiler::htmlToBlocks($contentHtml);
        }

        // 11. Montagem do Payload para o Core PostService
        $postData = [
            'title' => $title,
            'slug' => $slug !== '' ? $slug : null,
            'summary' => $summary !== '' ? $summary : null,
            'category' => 'geral', // Categoria automática de triagem
            'tags' => $cleanTags,
            'content_json' => $contentJson,
            'content_html' => $contentHtml,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription !== '' ? $seoDescription : null,
            'focus_keyword' => $focusKeyword !== '' ? $focusKeyword : null,
        ];

        // 12. Ingestão Atômica com PostService e Log de Idempotência
        try {
            $authorId = (int)$auth['user']['id'];
            $result = ApiPublisherService::createIngestedPost(
                $postData,
                $authorId,
                $externalKey !== '' ? $externalKey : null
            );

            $editUrl = "{$canonicalOrigin}/admin/editor.php?id={$result['id']}";

            http_response_code(201);
            echo json_encode([
                'ok' => true,
                'id' => $result['id'],
                'slug' => $result['slug'],
                'status' => 'draft',
                'category' => $result['category'],
                'author_id' => $result['author_id'],
                'edit_url' => $editUrl,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;

        } catch (\Throwable $e) {
            error_log('[ApiPublisher] Erro ao criar rascunho: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'Falha interna ao gravar o rascunho. Os detalhes foram registrados nos logs.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
