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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido. Use POST.']);
    exit;
}

$config = require dirname(__DIR__, 3) . '/core/bootstrap.php';

use LightPress\Core\Database;
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

$action = Security::getSafeString($_GET, 'action');
if ($action === '') {
    $action = Security::getSafeString($_POST, 'action');
}

try {
    switch ($action) {
        case 'test_connection':
            // Restrito a administradores
            if (!in_array($currentUser['role'] ?? '', ['admin', 'superadmin'], true)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Apenas administradores podem testar a conexão com a IA.']);
                exit;
            }

            $overrideConfig = [];
            if (!empty($_POST['provider'])) {
                $overrideConfig['provider'] = trim((string)$_POST['provider']);
            }
            if (!empty($_POST['model'])) {
                $overrideConfig['model'] = trim((string)$_POST['model']);
            }
            if (!empty($_POST['gemini_key'])) {
                $overrideConfig['gemini_key'] = trim((string)$_POST['gemini_key']);
            }
            if (!empty($_POST['openai_key'])) {
                $overrideConfig['openai_key'] = trim((string)$_POST['openai_key']);
            }
            if (!empty($_POST['custom_endpoint'])) {
                $overrideConfig['custom_endpoint'] = trim((string)$_POST['custom_endpoint']);
            }
            if (!empty($_POST['custom_key'])) {
                $overrideConfig['custom_key'] = trim((string)$_POST['custom_key']);
            }
            if (!empty($_POST['timeout'])) {
                $overrideConfig['timeout'] = (int)$_POST['timeout'];
            }

            $test = AiClient::testConnection(!empty($overrideConfig) ? $overrideConfig : null);
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

            // RBAC: Se o usuário for author, só pode descrever suas próprias mídias
            $mediaItem = Database::fetchOne("SELECT user_id FROM media WHERE id = ? LIMIT 1", [$mediaId]);
            if (!$mediaItem) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Mídia não encontrada.']);
                exit;
            }
            if (!in_array($currentUser['role'] ?? '', ['admin', 'editor', 'superadmin'], true) && (int)($mediaItem['user_id'] ?? 0) !== (int)$currentUser['id']) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Permissão negada. Você só pode acionar a IA para imagens enviadas por você.']);
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

            // RBAC: Se o usuário for author, só pode editar metadados de suas próprias mídias
            $mediaItem = Database::fetchOne("SELECT user_id FROM media WHERE id = ? LIMIT 1", [$mediaId]);
            if (!$mediaItem) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Mídia não encontrada.']);
                exit;
            }
            if (!in_array($currentUser['role'] ?? '', ['admin', 'editor', 'superadmin'], true) && (int)($mediaItem['user_id'] ?? 0) !== (int)$currentUser['id']) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Permissão negada. Você só pode gerenciar metadados de imagens enviadas por você.']);
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
    error_log('[LightPress AI API Error] ' . $e->getMessage());
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int)$e->getCode() : 500;
    http_response_code($code);

    $clientMsg = ($code < 500 && !($e instanceof \PDOException))
        ? $e->getMessage()
        : 'Erro interno ao processar requisição de inteligência artificial.';

    echo json_encode([
        'ok' => false,
        'error' => $clientMsg,
    ]);
}
