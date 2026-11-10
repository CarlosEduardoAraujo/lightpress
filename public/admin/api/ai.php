<?php
declare(strict_types=1);

/**
 * Endpoint de API Interna do Painel Administrativo para Recursos de IA.
 * 
 * Processa requisições seguras de sugestão de linkagem interna e análise de imagens
 * com checagem rigorosa de autenticação, permissões e token CSRF.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$config = require dirname(__DIR__, 3) . '/core/bootstrap.php';

use LightPress\Core\PluginManager;
use LightPress\Core\Security;
use LightPress\Core\Settings;
use LightPress\Plugin\AiCopilot\AiClient;
use LightPress\Plugin\AiCopilot\InternalLinker;
use LightPress\Plugin\AiCopilot\MediaVision;

if (!class_exists(AiClient::class, false)) {
    $pluginMain = dirname(__DIR__, 3) . '/plugins/ai-copilot/ai-copilot.php';
    if (file_exists($pluginMain)) {
        require_once $pluginMain;
    }
}

// 1. Autenticação obrigatória
$currentUser = Security::requireAuth();

// 2. Validação estrita de token CSRF
$csrfToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token de segurança CSRF inválido ou expirado.']);
    exit;
}

// 3. Verificação de status do plugin
if (!PluginManager::isActive('ai-copilot')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'O plugin AI Copilot não está ativo no sistema.']);
    exit;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    switch ($action) {
        case 'test_connection':
            // Restrito a administradores
            if (!in_array($currentUser['role'] ?? '', ['admin', 'superadmin'], true)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Apenas administradores podem testar a conexão com a IA.']);
                exit;
            }

            $test = AiClient::testConnection();
            echo json_encode($test);
            break;

        case 'suggest_links':
            if (Settings::get('ai_copilot_linking_enabled', '1') !== '1') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'O módulo de linkagem interna inteligente está desativado nas configurações.']);
                exit;
            }

            $postId = (int)($_POST['post_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $contentText = (string)($_POST['content_text'] ?? '');
            $tags = trim((string)($_POST['tags'] ?? ''));
            $categoryName = trim((string)($_POST['category_name'] ?? ''));

            if (empty($title)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'O título da matéria é obrigatório para pesquisar artigos correlacionados.']);
                exit;
            }

            $result = InternalLinker::suggestLinks($postId, $title, $contentText, $tags, $categoryName);
            echo json_encode($result);
            break;

        case 'describe_media':
            if (Settings::get('ai_copilot_vision_enabled', '1') !== '1') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'O módulo de visão computacional está desativado nas configurações.']);
                exit;
            }

            $mediaId = trim((string)($_POST['media_id'] ?? ''));
            if (empty($mediaId)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Identificador da imagem (media_id) não informado.']);
                exit;
            }

            $result = MediaVision::describeMedia($mediaId);
            echo json_encode($result);
            break;

        case 'save_media_meta':
            $mediaId = trim((string)($_POST['media_id'] ?? ''));
            $altText = (string)($_POST['alt_text'] ?? '');
            $caption = (string)($_POST['caption'] ?? '');
            $aiTags = (string)($_POST['ai_tags'] ?? '');

            if (empty($mediaId)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Identificador da imagem não informado.']);
                exit;
            }

            $result = MediaVision::saveMediaMeta($mediaId, $altText, $caption, $aiTags);
            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ação de IA desconhecida ou não informada.']);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
