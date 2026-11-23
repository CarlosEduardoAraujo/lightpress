<?php
declare(strict_types=1);

/**
 * Suíte de Testes Automatizados - Validação do Passo 6
 * (AUD-ARCH-04 & AUD-SEC-05: Idempotência, Rate-Limit Segregado, Validações e Preview)
 * Execução: php bin/test-passo6-api-preview.php
 */

require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;
use LightPress\Plugin\ApiPublisher\ApiPublisherService;

echo "========================================================\n";
echo "   Testes Passo 6: API Idempotência, Rate-Limit & Preview\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}" . ($detail !== '' ? " ({$detail})" : "") . "\n";
        $failed++;
    }
}

$testIp = '198.51.100.42';

// 1. Testar Segregação de Rate Limit (AUD-SEC-05)
// Limpar qualquer histórico prévio
Security::clearLoginFailures($testIp, 'login');
Security::clearLoginFailures($testIp, 'api_publisher');

assertTest("Inicialmente login não está bloqueado", Security::checkLoginRateLimit($testIp, 5, 900, 'login') === null);
assertTest("Inicialmente api_publisher não está bloqueado", Security::checkLoginRateLimit($testIp, 5, 900, 'api_publisher') === null);

// Simular 5 falhas na API
for ($i = 0; $i < 5; $i++) {
    Security::recordLoginFailure($testIp, 5, 900, 'api_publisher');
}

assertTest("Após 5 falhas na API, api_publisher ESTÁ bloqueado", Security::checkLoginRateLimit($testIp, 5, 900, 'api_publisher') !== null);
assertTest("Após 5 falhas na API, o login web NÃO ESTÁ bloqueado (baldes segregados)", Security::checkLoginRateLimit($testIp, 5, 900, 'login') === null);

// Limpeza
Security::clearLoginFailures($testIp, 'api_publisher');
assertTest("clearLoginFailures('api_publisher') limpa apenas o balde da API", Security::checkLoginRateLimit($testIp, 5, 900, 'api_publisher') === null);

// 2. Testar Idempotência sem Registro Órfão (AUD-ARCH-04 & Diretriz 1.2)
ApiPublisherService::ensureTablesSchema();

// Contar posts antes do teste
$initialPostsCount = (int)Database::fetchValue("SELECT COUNT(*) FROM posts");

$externalKey = 'test_idemp_' . uniqid();
$postData = [
    'title' => 'Post Idempotente Teste',
    'content_html' => '<p>Conteúdo de teste</p>',
    'content_json' => '[]',
    'category' => 'geral',
];

// Primeiro envio: deve criar o post
$result1 = ApiPublisherService::createIngestedPost($postData, 1, $externalKey);
assertTest("Primeiro envio cria rascunho com sucesso", !empty($result1['id']) && $result1['status'] === 'draft');
assertTest("Primeiro envio não contém flag idempotent", empty($result1['idempotent']));

$postsCountAfterFirst = (int)Database::fetchValue("SELECT COUNT(*) FROM posts");
assertTest("Exatamente 1 post inserido no banco", $postsCountAfterFirst === $initialPostsCount + 1);

// Segundo envio concorrente com a mesma chave externa:
// Disparar createIngestedPost com a mesma chave. A violação UNIQUE em external_key
// é capturada FORA da transação; o rollback da transação reverte o novo post
// e o método retorna o post existente com idempotent=true.
$result2 = ApiPublisherService::createIngestedPost($postData, 1, $externalKey);
assertTest("Segundo envio com mesma chave retorna idempotent=true", !empty($result2['idempotent']) && $result2['idempotent'] === true);
assertTest("Segundo envio retorna o mesmo post_id original", $result2['id'] === $result1['id']);

$postsCountAfterSecond = (int)Database::fetchValue("SELECT COUNT(*) FROM posts");
assertTest("Total de posts permanece inalterado (zero rascunhos duplicados órfãos!)", $postsCountAfterSecond === $postsCountAfterFirst);

// 3. Testar Renderização de preview.php (AUD-ARCH-04 Item 2)
// Inspecionar o código de preview.php para garantir ausência de DOCTYPE próprio
$previewCode = (string)file_get_contents(dirname(__DIR__) . '/public/admin/preview.php');
assertTest("preview.php NÃO contém <!DOCTYPE html> duplicado", !str_contains($previewCode, '<!DOCTYPE html>'));
assertTest("preview.php NÃO contém <html> nem <body> duplicados", !str_contains($previewCode, '<html') && !str_contains($previewCode, '<body'));
assertTest("preview.php carrega revisão pendente ou específica", str_contains($previewCode, 'post_revisions'));

// Limpar dados do teste
Database::query("DELETE FROM plugin_api_ingest_log WHERE external_key = ?", [$externalKey]);
Database::query("DELETE FROM posts WHERE id = ?", [$result1['id']]);

echo "\n========================================================\n";
echo "Total de Testes: " . ($passed + $failed) . "\n";
echo "Aprovados:       {$passed}\n";
echo "Falhas:          {$failed}\n\n";

if ($failed === 0) {
    echo ">>> TODOS OS TESTES DO PASSO 6 FORAM APROVADOS! <<<\n";
    exit(0);
} else {
    echo ">>> HOUVE FALHAS NA VALIDAÇÃO DO PASSO 6! <<<\n";
    exit(1);
}
