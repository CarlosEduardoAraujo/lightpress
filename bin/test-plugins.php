<?php
declare(strict_types=1);

/**
 * Suíte de Testes do Sistema de Plugins e Webhooks - LightPress
 * Execução: php bin/test-plugins.php
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Hook;
use LightPress\Core\PluginManager;
use LightPress\Core\Security;

echo "========================================================\n";
echo "   LightPress - Testes do Sistema de Plugins & Hooks    \n";
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
// 1. Testes de Actions e Filters (core/Hook.php)
// -------------------------------------------------------------
echo "[1] Testando Sistema de Hooks (core/Hook.php)\n";

$executionTrace = [];
Hook::addAction('test_action', function () use (&$executionTrace) {
    $executionTrace[] = 'second';
}, 20);

Hook::addAction('test_action', function () use (&$executionTrace) {
    $executionTrace[] = 'first';
}, 10);

Hook::doAction('test_action');
assertTest("Hook Action respeita prioridade de execução", $executionTrace === ['first', 'second']);

Hook::addFilter('test_filter', function (string $text): string {
    return $text . ' Mundo';
}, 10);

Hook::addFilter('test_filter', function (string $text): string {
    return strtoupper($text);
}, 20);

$filtered = Hook::applyFilters('test_filter', 'Olá');
assertTest("Hook Filter encadeia transformações em ordem", $filtered === 'OLÁ MUNDO');

// -------------------------------------------------------------
// 2. Testes de Descoberta de Plugins (core/PluginManager.php)
// -------------------------------------------------------------
echo "\n[2] Testando Gerenciador de Plugins (core/PluginManager.php)\n";

$available = PluginManager::getAvailablePlugins();
assertTest("Descoberta do plugin 'webhook-sender' em /plugins/", isset($available['webhook-sender']));
assertTest("Manifesto contém campos obrigatórios", !empty($available['webhook-sender']['name']) && !empty($available['webhook-sender']['version']));
assertTest("Plugin declara interface admin_settings", !empty($available['webhook-sender']['admin_settings']));

// -------------------------------------------------------------
// 3. Testes do Plugin Webhook Sender (Parsing de URLs e Payload)
// -------------------------------------------------------------
echo "\n[3] Testando Plugin Webhook Sender\n";

require_once $available['webhook-sender']['entrypoint'];

$inputUrls = "https://discord.com/api/webhooks/123/abc, https://meuservidor.com/webhook\nhttp://insecure.test/hook, invalido, ftp://teste.com";
$cleanUrls = WebhookSenderService::parseUrls($inputUrls);

assertTest("Separador por vírgula e quebra de linha", count($cleanUrls) === 3);
assertTest("Filtra protocolos inválidos e strings corrompidas", !in_array('invalido', $cleanUrls, true) && !in_array('ftp://teste.com', $cleanUrls, true));
assertTest("Aceita HTTP e HTTPS válidos", in_array('https://discord.com/api/webhooks/123/abc', $cleanUrls, true) && in_array('http://insecure.test/hook', $cleanUrls, true));

// Teste de montagem do payload
$mockPost = [
    'title'          => 'GP do Brasil de F1: Verstappen vence sob chuva',
    'slug'           => 'gp-do-brasil-f1-verstappen-vence-chuva',
    'summary'        => 'Em corrida histórica em Interlagos, piloto holandês dá show de pilotagem.',
    'category'       => 'f1',
    'tags'           => 'f1,interlagos,verstappen',
    'featured_image' => '/uploads/2026/11/verstappen-chuva-1024.webp',
    'published_at'   => '2026-11-06 14:00:00',
];

$payload = WebhookSenderService::buildPayload(101, $mockPost, $config);

assertTest("Payload contém embeds para Discord", !empty($payload['embeds'][0]['title']));
assertTest("Embed contém URL canônica correta", str_contains($payload['embeds'][0]['url'], 'gp-do-brasil-f1-verstappen-vence-chuva'));
assertTest("Embed contém cor hexadecimal padrão", isset($payload['embeds'][0]['color']));
assertTest("Payload contém dados universais estruturados", isset($payload['post']['id']) && $payload['post']['id'] === 101);

// -------------------------------------------------------------
// Relatório Final
// -------------------------------------------------------------
echo "\n========================================================\n";
echo "Total de Testes: " . ($passed + $failed) . "\n";
echo "Aprovados:       {$passed}\n";
echo "Falhas:          {$failed}\n\n";

if ($failed === 0) {
    echo ">>> TODOS OS TESTES DE PLUGINS PASSARAM COM SUCESSO! <<<\n\n";
    exit(0);
} else {
    echo ">>> ALGUNS TESTES FALHARAM! <<<\n\n";
    exit(1);
}
