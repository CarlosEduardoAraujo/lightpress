<?php
declare(strict_types=1);

/**
 * Suíte de Testes Automatizados Leve e Sem Dependências - LightPress
 * Execução: php bin/test.php
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\BlockCompiler;
use LightPress\Core\CacheManager;
use LightPress\Core\Router;
use LightPress\Core\Security;

echo "========================================================\n";
echo "   LightPress / SpeedPress - Suíte de Testes Unitários  \n";
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
// 1. Testes de Segurança e Sanitização (Security)
// -------------------------------------------------------------
echo "[1] Testando Segurança e Escape (core/Security.php)\n";

assertTest("Escape HTML básico", Security::e("<h1>Título & 'teste'</h1>") === "&lt;h1&gt;T&iacute;tulo &amp; &#039;teste&#039;&lt;/h1&gt;" || Security::e("<h1>Título & 'teste'</h1>") === "&lt;h1&gt;Título &amp; &#039;teste&#039;&lt;/h1&gt;");
assertTest("Escape de valor nulo", Security::e(null) === "");
assertTest("Geração de Slug limpo", Security::slugify("Notícia Incrível de Fórmula 1!") === "noticia-incrivel-de-formula-1");
assertTest("HMAC IP Hash não vazio e 64 caracteres hexadecimais", strlen(Security::hashIp("192.168.1.100", "segredo")) === 64);
assertTest("HMAC IP Hash determinístico", Security::hashIp("192.168.1.100", "segredo") === Security::hashIp("192.168.1.100", "segredo"));

// -------------------------------------------------------------
// 2. Testes de Sanitização do Compilador de Blocos (BlockCompiler)
// -------------------------------------------------------------
echo "\n[2] Testando Compilador Semântico e Defesa contra XSS (core/BlockCompiler.php)\n";

$blocks = [
    ['type' => 'heading', 'level' => 2, 'content' => 'Título Seguro'],
    ['type' => 'paragraph', 'content' => 'Texto com <strong>negrito</strong> e <script>alert("xss")</script> perigoso.'],
    ['type' => 'image', 'url' => 'https://exemplo.com/foto.webp', 'caption' => 'Legenda'],
];

$compiled = BlockCompiler::compile($blocks);

assertTest("Compilação de Heading h2", str_contains($compiled, '<h2>Título Seguro</h2>'));
assertTest("Bloqueio de tag <script> em parágrafos", !str_contains($compiled, '<script>'));
assertTest("Preservação de tag semântica <strong>", str_contains($compiled, '<strong>negrito</strong>'));
assertTest("Compilação de imagem com <figure>", str_contains($compiled, '<figure><img src="https://exemplo.com/foto.webp"'));

// Teste contra URLs maliciosas com javascript:
$maliciousBlock = [
    ['type' => 'paragraph', 'content' => '<a href="javascript:alert(1)">Clique Aqui</a>'],
];
$maliciousCompiled = BlockCompiler::compile($maliciousBlock);
assertTest("Neutralização de link com javascript:", !str_contains($maliciousCompiled, 'javascript:'));

// -------------------------------------------------------------
// 3. Testes da Matriz de Controle de Acesso (RBAC - Authorization)
// -------------------------------------------------------------
echo "\n[3] Testando Matriz de Autorização (core/Authorization.php)\n";

$author = ['id' => 10, 'role' => 'author', 'is_active' => 1];
$editor = ['id' => 20, 'role' => 'editor', 'is_active' => 1];
$admin = ['id' => 1, 'role' => 'admin', 'is_active' => 1];
$inactiveUser = ['id' => 5, 'role' => 'admin', 'is_active' => 0];

$ownPost = ['id' => 100, 'user_id' => 10];
$otherPost = ['id' => 101, 'user_id' => 99];

assertTest("Autor pode editar seu próprio post", Authorization::can($author, Authorization::ACTION_EDIT_POST, $ownPost));
assertTest("Autor NÃO pode editar post de outro autor", !Authorization::can($author, Authorization::ACTION_EDIT_POST, $otherPost));
assertTest("Autor NÃO pode publicar posts", !Authorization::can($author, Authorization::ACTION_PUBLISH_POST, $ownPost));
assertTest("Editor PODE publicar posts", Authorization::can($editor, Authorization::ACTION_PUBLISH_POST, $otherPost));
assertTest("Editor NÃO pode gerenciar usuários", !Authorization::can($editor, Authorization::ACTION_MANAGE_USERS));
assertTest("Administrador PODE gerenciar usuários", Authorization::can($admin, Authorization::ACTION_MANAGE_USERS));
assertTest("Usuário inativo NÃO possui permissões mesmo sendo admin", !Authorization::can($inactiveUser, Authorization::ACTION_MANAGE_USERS));

// -------------------------------------------------------------
// 4. Testes de Cálculo de Hash do Nginx Cache (CacheManager)
// -------------------------------------------------------------
echo "\n[4] Testando Invalidação de Cache Nginx (core/CacheManager.php)\n";

$cacheManager = new CacheManager([
    'cache' => ['cache_path' => '/var/cache/nginx/lightpress', 'enabled' => true],
    'app' => ['canonical_origin' => 'https://speedracing.com.br'],
]);

$testUri = '/noticia/campeonato-2026/';
$path = $cacheManager->getCacheFilePath($testUri);

$expectedKey = "httpsGETspeedracing.com.br/noticia/campeonato-2026/";
$expectedHash = md5($expectedKey);
$expectedLevel1 = substr($expectedHash, -1);
$expectedLevel2 = substr($expectedHash, -3, 2);
$expectedFull = "/var/cache/nginx/lightpress/{$expectedLevel1}/{$expectedLevel2}/{$expectedHash}";

assertTest("Caminho do cache Nginx levels=1:2 calculado com precisão", $path === $expectedFull, "Obtido: {$path}");

// -------------------------------------------------------------
// 5. Testes do Roteador Regex (Router)
// -------------------------------------------------------------
echo "\n[5] Testando Roteador Puro (core/Router.php)\n";

$router = new Router();
$matched = false;
$paramSlug = '';

$router->get('/noticia/{slug}/', function(array $params) use (&$matched, &$paramSlug): void {
    $matched = true;
    $paramSlug = $params['slug'] ?? '';
});

$router->dispatch('GET', '/noticia/gp-de-monaco/');

assertTest("Roteador captura rota com parâmetro nomeado", $matched);
assertTest("Roteador extrai parâmetro slug corretamente", $paramSlug === 'gp-de-monaco');

// -------------------------------------------------------------
// Relatório Final
// -------------------------------------------------------------
echo "\n========================================================\n";
echo "   Resultado Final dos Testes\n";
echo "========================================================\n";
echo "Total de Testes: " . ($passed + $failed) . "\n";
echo "Aprovados:       {$passed}\n";
echo "Falhas:          {$failed}\n\n";

if ($failed === 0) {
    echo ">>> TODOS OS TESTES FORAM APROVADOS COM SUCESSO! <<<\n\n";
    exit(0);
} else {
    echo ">>> ALGUNS TESTES FALHARAM! <<<\n\n";
    exit(1);
}
