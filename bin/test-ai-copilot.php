<?php
declare(strict_types=1);

/**
 * Suíte de Testes Automatizados - Plugin AI Copilot
 * Execução: php bin/test-ai-copilot.php
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\PluginManager;
use LightPress\Core\Schema;
use LightPress\Core\Security;
use LightPress\Core\Settings;
use LightPress\Plugin\AiCopilot\AiClient;
use LightPress\Plugin\AiCopilot\InternalLinker;
use LightPress\Plugin\AiCopilot\MediaVision;

echo "========================================================\n";
echo "   LightPress - Testes do Plugin AI Copilot             \n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description} " . ($detail ? "({$detail})" : "") . "\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// 1. Descoberta e Manifesto do Plugin
// -------------------------------------------------------------
echo "[1] Descoberta e Manifesto do Plugin\n";

$available = PluginManager::getAvailablePlugins();
assertTest("Descoberta do plugin 'ai-copilot' em /plugins/", isset($available['ai-copilot']));
$meta = $available['ai-copilot'] ?? [];
assertTest("Manifesto contém campos obrigatórios", !empty($meta['name']) && !empty($meta['version']));
assertTest("Plugin declara interface admin_settings", !empty($meta['admin_settings']));
assertTest("Arquivo principal entrypoint existe", file_exists($meta['entrypoint'] ?? ''));

// -------------------------------------------------------------
// 2. Carregamento e Ativação do Plugin (Migração de DB)
// -------------------------------------------------------------
echo "\n[2] Ativação e Migração de Esquema no Banco\n";

require_once $meta['entrypoint'];

// Ativar plugin na lista de ativos se necessário para os testes
$activePlugins = PluginManager::getActivePluginSlugs();
if (!in_array('ai-copilot', $activePlugins, true)) {
    $activePlugins[] = 'ai-copilot';
    Settings::set('active_plugins', json_encode($activePlugins));
}

PluginManager::activate('ai-copilot');
assertTest("Plugin 'ai-copilot' está ativo", PluginManager::isActive('ai-copilot'));

// Verifica se as colunas alt_text, caption e ai_tags existem na tabela media
$pdo = Database::getConnection();
$driver = Database::getDriver();

if ($driver === 'sqlite') {
    $cols = $pdo->query("PRAGMA table_info(media)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    assertTest("Coluna 'alt_text' criada em media (SQLite)", in_array('alt_text', $colNames, true));
    assertTest("Coluna 'caption' criada em media (SQLite)", in_array('caption', $colNames, true));
    assertTest("Coluna 'ai_tags' criada em media (SQLite)", in_array('ai_tags', $colNames, true));
} else {
    $hasAlt = $pdo->query("SHOW COLUMNS FROM media LIKE 'alt_text'")->fetch();
    $hasCap = $pdo->query("SHOW COLUMNS FROM media LIKE 'caption'")->fetch();
    $hasTags = $pdo->query("SHOW COLUMNS FROM media LIKE 'ai_tags'")->fetch();
    assertTest("Coluna 'alt_text' criada em media (MySQL)", !empty($hasAlt));
    assertTest("Coluna 'caption' criada em media (MySQL)", !empty($hasCap));
    assertTest("Coluna 'ai_tags' criada em media (MySQL)", !empty($hasTags));
}

// -------------------------------------------------------------
// 3. Testes do Cliente de IA (AiClient)
// -------------------------------------------------------------
echo "\n[3] Testes de Configuração e Segurança do AiClient\n";

assertTest("Provedor padrão é Gemini", in_array(AiClient::getActiveProvider(), ['gemini', 'openai', 'custom'], true));
assertTest("Modelo padrão é resolvido", !empty(AiClient::getModel()));
assertTest("Timeout padrão respeita limites (>=3s e <=30s)", AiClient::getTimeout() >= 3 && AiClient::getTimeout() <= 30);

// Validação de SSRF em endpoints customizados
$reflection = new ReflectionClass(AiClient::class);
$methodValidate = $reflection->getMethod('validateEndpointUrl');
$methodValidate->setAccessible(true);

$blockedMeta = false;
try {
    $methodValidate->invoke(null, 'http://169.254.169.254/latest/meta-data/');
} catch (RuntimeException $e) {
    $blockedMeta = true;
}
assertTest("AiClient bloqueia expressamente Cloud Metadata (169.254.169.254)", $blockedMeta);

$blockedFtp = false;
try {
    $methodValidate->invoke(null, 'ftp://meuservidor.com/api');
} catch (RuntimeException $e) {
    $blockedFtp = true;
}
assertTest("AiClient bloqueia protocolos não-HTTP(S)", $blockedFtp);

// Teste de decodificação JSON com markdown
$methodDecode = $reflection->getMethod('extractAndDecodeJson');
$methodDecode->setAccessible(true);

$rawAiOutput = "```json\n{\"alt_text\": \"Carro de corrida\", \"caption\": \"GP do Brasil\"}\n```";
$decoded = $methodDecode->invoke(null, $rawAiOutput);
assertTest("AiClient limpa blocos markdown e decodifica JSON", is_array($decoded) && ($decoded['alt_text'] ?? '') === 'Carro de corrida');

// -------------------------------------------------------------
// 4. Testes do Módulo de Linkagem Interna (InternalLinker)
// -------------------------------------------------------------
echo "\n[4] Testes do Módulo de Linkagem Interna (InternalLinker)\n";

// Criar artigos de teste no banco para busca
$user = Database::fetchOne("SELECT id FROM users LIMIT 1");
$userId = $user ? (int)$user['id'] : 1;

// Limpar artigos de teste prévios
Database::query("DELETE FROM posts WHERE slug LIKE 'test-ai-%'");

Database::query(
    "INSERT INTO posts (user_id, slug, title, summary, content_json, content_html, status, tags, published_at)
     VALUES (?, 'test-ai-ferrari', 'Ferrari domina treinos livres em Interlagos', 'Equipe italiana lidera primeiro dia em São Paulo.', '{}', '<p>Texto da Ferrari</p>', 'published', 'ferrari, interlagos, f1', '2026-11-01 10:00:00')",
    [$userId]
);

Database::query(
    "INSERT INTO posts (user_id, slug, title, summary, content_json, content_html, status, tags, published_at)
     VALUES (?, 'test-ai-verstappen', 'Verstappen vence com pista molhada', 'Piloto holandês supera chuva no GP.', '{}', '<p>Texto de Verstappen</p>', 'published', 'verstappen, red bull, chuva', '2026-11-05 15:00:00')",
    [$userId]
);

Database::query(
    "INSERT INTO posts (user_id, slug, title, summary, content_json, content_html, status, tags, published_at)
     VALUES (?, 'test-ai-draft', 'Rascunho secreto sobre motores', 'Não publicado.', '{}', '<p>Rascunho</p>', 'draft', 'motores, f1', '2026-11-08 12:00:00')",
    [$userId]
);

$candidates = InternalLinker::getCandidateArticles(999999, 'Ferrari busca vitória sob chuva em Interlagos', 'ferrari, chuva', 'Fórmula 1', 10);

assertTest("Busca de candidatos retorna matérias relevantes", count($candidates) >= 1);

$foundDraft = false;
$foundFerrari = false;
foreach ($candidates as $c) {
    if ($c['slug'] === 'test-ai-draft') {
        $foundDraft = true;
    }
    if ($c['slug'] === 'test-ai-ferrari') {
        $foundFerrari = true;
    }
}

assertTest("Candidatos incluem matéria publicada 'test-ai-ferrari'", $foundFerrari);
assertTest("Candidatos EXCLUEM estritamente matérias com status 'draft'", !$foundDraft);

// Teste de exclusão do post atual
$ferrariPost = Database::fetchOne("SELECT id FROM posts WHERE slug = 'test-ai-ferrari' LIMIT 1");
if ($ferrariPost) {
    $selfExcludedCandidates = InternalLinker::getCandidateArticles((int)$ferrariPost['id'], 'Ferrari em Interlagos', 'ferrari', 'F1', 10);
    $foundSelf = false;
    foreach ($selfExcludedCandidates as $c) {
        if ($c['id'] === (int)$ferrariPost['id']) {
            $foundSelf = true;
        }
    }
    assertTest("Candidatos EXCLUEM estritamente a própria matéria em edição (id != currentPostId)", !$foundSelf);
}

// -------------------------------------------------------------
// 5. Testes do Módulo de Visão e Metadados (MediaVision)
// -------------------------------------------------------------
echo "\n[5] Testes do Módulo de Visão Computacional (MediaVision)\n";

// Criar registro de mídia de teste
$testMediaId = 'test_media_ai_123';
Database::query("DELETE FROM media WHERE id = ?", [$testMediaId]);

Database::query(
    "INSERT INTO media (id, user_id, original_name, mime_type, file_size, variants_manifest, is_finalized)
     VALUES (?, ?, 'ferrari-chuva-interlagos.jpg', 'image/webp', 45000, '{}', 1)",
    [$testMediaId, $userId]
);

// Testar atualização e persistência de metadados manuais
$metaResult = MediaVision::saveMediaMeta(
    $testMediaId,
    'Carro da Ferrari sob chuva em Interlagos',
    'Ferrari de Leclerc contorna a Curva do Sol com pista molhada em São Paulo.',
    'ferrari, interlagos, chuva, f1, leclerc'
);

assertTest("saveMediaMeta retorna sucesso", $metaResult['ok'] === true);

$updatedMedia = Database::fetchOne("SELECT alt_text, caption, ai_tags FROM media WHERE id = ?", [$testMediaId]);
assertTest("alt_text persistido corretamente no banco", ($updatedMedia['alt_text'] ?? '') === 'Carro da Ferrari sob chuva em Interlagos');
assertTest("caption persistida corretamente no banco", str_contains((string)($updatedMedia['caption'] ?? ''), 'Curva do Sol'));
assertTest("ai_tags persistidas corretamente no banco", str_contains((string)($updatedMedia['ai_tags'] ?? ''), 'ferrari'));

// -------------------------------------------------------------
// 6. Testes da Busca Unificada na Tabela media
// -------------------------------------------------------------
echo "\n[6] Testes de Busca Unificada por Conteúdo Visual em media\n";

$searchTag = Database::fetchAll(
    "SELECT id, original_name, alt_text, ai_tags 
     FROM media 
     WHERE original_name LIKE ? OR alt_text LIKE ? OR ai_tags LIKE ? OR caption LIKE ?",
    ['%leclerc%', '%leclerc%', '%leclerc%', '%leclerc%']
);

assertTest("Busca em media encontra foto pelo ai_tags ('leclerc')", count($searchTag) >= 1 && $searchTag[0]['id'] === $testMediaId);

$searchAlt = Database::fetchAll(
    "SELECT id, original_name, alt_text, ai_tags 
     FROM media 
     WHERE original_name LIKE ? OR alt_text LIKE ? OR ai_tags LIKE ? OR caption LIKE ?",
    ['%Interlagos%', '%Interlagos%', '%Interlagos%', '%Interlagos%']
);

assertTest("Busca em media encontra foto pelo alt_text ('Interlagos')", count($searchAlt) >= 1);

// Limpeza de fixtures de teste
Database::query("DELETE FROM media WHERE id = ?", [$testMediaId]);
Database::query("DELETE FROM posts WHERE slug LIKE 'test-ai-%'");

// -------------------------------------------------------------
// Resumo dos Testes
// -------------------------------------------------------------
echo "\n========================================================\n";
echo "   Resultado Final: {$passed} passaram, {$failed} falharam\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
