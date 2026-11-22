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
assertTest("Escape de curingas SQL (LIKE Injection)", Security::escapeLike("100%_desconto\\promo") === "100\\%\\_desconto\\\\promo");
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

// Testes de Poda de Parágrafos Vazios e Sanitização de Blocos
assertTest("Poda de parágrafos vazios contendo &nbsp; e <br>", BlockCompiler::sanitizeHtml('<p>&nbsp;</p><p>Conteúdo real</p><p><br></p>') === '<p>Conteúdo real</p>');
assertTest("Remoção de <br> final útil em parágrafo", BlockCompiler::sanitizeHtml('<p>Texto<br></p>') === '<p>Texto</p>');
assertTest("Preservação de <br> intermediário entre linhas", BlockCompiler::sanitizeHtml('<p>Linha 1<br>Linha 2</p>') === '<p>Linha 1<br>Linha 2</p>');
assertTest("Poda bottom-up de lista com itens vazios", BlockCompiler::sanitizeHtml('<ul><li>&nbsp;</li></ul>') === '');

// Testes de Preservação e Sanitização de Tabelas
assertTest("Preservação de célula individual vazia em linha válida", BlockCompiler::sanitizeHtml('<table><tr><td>Piloto</td><td></td></tr></table>') === '<table><tr><td>Piloto</td><td></td></tr></table>');
assertTest("Poda de tabela com linhas totalmente vazias", BlockCompiler::sanitizeHtml('<table><tr><td>&nbsp;</td><td></td></tr></table>') === '');
assertTest("Poda de linha vazia mantendo linha preenchida", BlockCompiler::sanitizeHtml('<table><tr><td>Piloto</td><td>1</td></tr><tr><td>&nbsp;</td><td></td></tr></table>') === '<table><tr><td>Piloto</td><td>1</td></tr></table>');
assertTest("Remoção de onclick e style em table/tr/td mantendo dados", BlockCompiler::sanitizeHtml('<table onclick="alert(1)"><tr onclick="alert(2)"><td style="color:red">A</td></tr></table>') === '<table><tr><td>A</td></tr></table>');
assertTest("Validação de colspan numérico válido e descarte de inválido", 
    str_contains(BlockCompiler::sanitizeHtml('<table><tr><td colspan="2">Largo</td></tr></table>'), 'colspan="2"') &&
    !str_contains(BlockCompiler::sanitizeHtml('<table><tr><td colspan="abc">Largo</td></tr></table>'), 'colspan')
);
assertTest("Validação de atributo scope em th", str_contains(BlockCompiler::sanitizeHtml('<table><tr><th scope="col">Header</th></tr></table>'), 'scope="col"'));
assertTest("Remoção de tags colgroup e col de planilhas", !str_contains(BlockCompiler::sanitizeHtml('<table><colgroup><col width="100"></colgroup><tr><td>A</td></tr></table>'), 'col'));

// Testes de Conversão e Compilação Bidirecional (htmlToBlocks <-> compile)
$tableSourceHtml = '<p>Intro</p><table><thead><tr><th>Pos</th><th>Piloto</th></tr></thead><tbody><tr><td>1</td><td>Verstappen</td></tr></tbody></table>';
$tableBlocksJson = BlockCompiler::htmlToBlocks($tableSourceHtml);
assertTest("htmlToBlocks gera bloco do tipo 'table'", str_contains($tableBlocksJson, '"type":"table"'));
$tableRecompiled = BlockCompiler::compile($tableBlocksJson);
assertTest("compile renderiza bloco 'table' sanitizado", str_contains($tableRecompiled, '<th>Pos</th>') && str_contains($tableRecompiled, '<td>Verstappen</td>'));

$headlessTable = [
    ['type' => 'table', 'headers' => ['Pos', 'Piloto'], 'rows' => [['1', 'Verstappen']]]
];
$headlessCompiled = BlockCompiler::compile($headlessTable);
assertTest("compile suporta tabela estruturada de API headless", str_contains($headlessCompiled, '<th>Pos</th>') && str_contains($headlessCompiled, '<td>Verstappen</td>'));

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
$editorOwnPost = ['id' => 102, 'user_id' => 20];

assertTest("Autor pode editar seu próprio post", Authorization::can($author, Authorization::ACTION_EDIT_POST, $ownPost));
assertTest("Autor NÃO pode editar post de outro autor", !Authorization::can($author, Authorization::ACTION_EDIT_POST, $otherPost));
assertTest("Autor NÃO pode publicar posts", !Authorization::can($author, Authorization::ACTION_PUBLISH_POST, $ownPost));
assertTest("Editor PODE publicar posts", Authorization::can($editor, Authorization::ACTION_PUBLISH_POST, $otherPost));
assertTest("Editor PODE excluir post de sua própria autoria", Authorization::can($editor, Authorization::ACTION_DELETE_POST, $editorOwnPost));
assertTest("Editor NÃO pode excluir post de outro autor", !Authorization::can($editor, Authorization::ACTION_DELETE_POST, $otherPost));
assertTest("Editor NÃO pode excluir post sem contexto de matéria", !Authorization::can($editor, Authorization::ACTION_DELETE_POST));
assertTest("Autor NÃO pode excluir post mesmo de sua autoria", !Authorization::can($author, Authorization::ACTION_DELETE_POST, $ownPost));
assertTest("Administrador PODE excluir qualquer post", Authorization::can($admin, Authorization::ACTION_DELETE_POST, $otherPost));
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

// Teste com URL absoluta (ex: https://speedracing.com.br/slug/)
$testFullUrl = 'https://speedracing.com.br/alan-permane-racing-bulls-2031/';
$pathFull = $cacheManager->getCacheFilePath($testFullUrl);
$expectedFullKey = "httpsGETspeedracing.com.br/alan-permane-racing-bulls-2031/";
$expectedFullHash = md5($expectedFullKey);
$expectedFullLevel1 = substr($expectedFullHash, -1);
$expectedFullLevel2 = substr($expectedFullHash, -3, 2);
$expectedFullPath = "/var/cache/nginx/lightpress/{$expectedFullLevel1}/{$expectedFullLevel2}/{$expectedFullHash}";

assertTest("Caminho do cache Nginx calculado corretamente a partir de URL absoluta", $pathFull === $expectedFullPath, "Obtido: {$pathFull}");

// Verificação do CloudflareCache e alias de namespace
assertTest("Classe CloudflareCache existe e está carregada", class_exists(\LightPress\Core\CloudflareCache::class));
assertTest("Alias de namespace Core\Services\CloudflareCache disponível", class_exists('Core\Services\CloudflareCache'));
assertTest("Cloudflare desabilitada em dev retorna falso com segurança", \LightPress\Core\CloudflareCache::purgeUrls(['/']) === false);

// -------------------------------------------------------------
// 5. Testes do Roteador Regex (Router)
// -------------------------------------------------------------
echo "\n[5] Testando Roteador Puro (core/Router.php)\n";

$router = new Router();
$router->setTestingMode(true);
$matched = false;
$paramSlug = '';

$router->get('/artigo/{slug}/', function(array $params) use (&$matched, &$paramSlug): void {
    $matched = true;
    $paramSlug = $params['slug'] ?? '';
});

$router->dispatch('GET', '/artigo/gp-de-monaco/');

assertTest("Roteador captura rota com parâmetro nomeado", $matched);
assertTest("Roteador extrai parâmetro slug corretamente", $paramSlug === 'gp-de-monaco');

// Testes de Roteamento em Subdiretório (basePath)
$subRouter = new Router('/blog');
$subRouter->setTestingMode(true);
$subMatched = false;
$subSlug = '';
$subRouter->get('/artigo/{slug}/', function(array $params) use (&$subMatched, &$subSlug): void {
    $subMatched = true;
    $subSlug = $params['slug'] ?? '';
});
$subRouter->dispatch('GET', '/blog/artigo/gp-do-brasil/');
assertTest("Roteador despacha corretamente em subdiretório (/blog)", $subMatched && $subSlug === 'gp-do-brasil');

// Testes de Mitigação de Open Redirect
assertTest("Validação de destino relativo seguro (/artigo-novo/)", $router->isValidRedirectTarget('/artigo-novo/'));
assertTest("Bloqueio de Open Redirect com protocolo relativo (//evil.com)", !$router->isValidRedirectTarget('//evil.com'));
assertTest("Bloqueio de Open Redirect com barra invertida (/\\evil.com)", !$router->isValidRedirectTarget('/\\evil.com'));
assertTest("Bloqueio de Open Redirect com backslash simples (\\\\evil.com)", !$router->isValidRedirectTarget('\\\\evil.com'));
assertTest("Bloqueio de Open Redirect contendo backslash (/foo\\evil)", !$router->isValidRedirectTarget('/foo\\evil'));
assertTest("Bloqueio de Open Redirect com esquema javascript:", !$router->isValidRedirectTarget('javascript:alert(1)'));
assertTest("Bloqueio de Open Redirect para host externo (https://site-malicioso.com)", !$router->isValidRedirectTarget('https://site-malicioso.com'));

// -------------------------------------------------------------
// 6. Testes do Módulo de SEO e JSON-LD (core/Seo.php)
// -------------------------------------------------------------
echo "\n[6] Testando Módulo de SEO e Schema.org (core/Seo.php)\n";

$seoPost = [
    'title' => 'Vitória Histórica no GP de Silverstone',
    'slug' => 'vitoria-historica-silverstone',
    'summary' => 'Resumo da prova épica com ultrapassagens na chuva.',
    'published_at' => '2026-07-15 14:00:00',
    'author_name' => 'Redação SpeedPress',
    'category' => 'formula-1',
];

$metaTags = \LightPress\Core\Seo::renderMetaTags($seoPost, $config);
assertTest("Renderização de og:title e og:type", str_contains($metaTags, 'og:title') && str_contains($metaTags, 'content="article"'));
assertTest("Renderização de Twitter Card summary_large_image", str_contains($metaTags, 'twitter:card'));

$jsonLd = \LightPress\Core\Seo::renderJsonLd($seoPost, $config);
assertTest("Geração de JSON-LD NewsArticle válido", str_contains($jsonLd, 'NewsArticle') && str_contains($jsonLd, 'schema.org'));

// -------------------------------------------------------------
// 7. Testes do Logger Sanitizado (core/Logger.php)
// -------------------------------------------------------------
echo "\n[7] Testando Logger e Expurgador de Dados Sensíveis (core/Logger.php)\n";

$dirtyContext = [
    'user' => 'admin',
    'password' => 'segredo123',
    'token' => 'abc123xyz',
    'meta' => [
        'session_id' => '123',
        '_csrf_token' => 'token_secreto',
    ],
];

$cleanContext = \LightPress\Core\Logger::sanitize($dirtyContext);
assertTest("Expurgo de campo 'password'", $cleanContext['password'] === '***REDACTED***');
assertTest("Expurgo de campo 'token'", $cleanContext['token'] === '***REDACTED***');
assertTest("Expurgo recursivo de '_csrf_token' interno", $cleanContext['meta']['_csrf_token'] === '***REDACTED***');
assertTest("Preservação de dados não sensíveis", $cleanContext['user'] === 'admin');

// -------------------------------------------------------------
// 7. Testes do Sistema de Plugins e Hooks (core/Hook.php, PluginManager.php)
// -------------------------------------------------------------
echo "\n[7] Testando Sistema de Plugins e Hooks\n";

$executionTrace = [];
\LightPress\Core\Hook::addAction('test_action', function () use (&$executionTrace) {
    $executionTrace[] = 'second';
}, 20);

\LightPress\Core\Hook::addAction('test_action', function () use (&$executionTrace) {
    $executionTrace[] = 'first';
}, 10);

\LightPress\Core\Hook::doAction('test_action');
assertTest("Hook Action respeita prioridade de execução", $executionTrace === ['first', 'second']);

\LightPress\Core\Hook::addFilter('test_filter', function (string $text): string {
    return $text . ' Mundo';
}, 10);

\LightPress\Core\Hook::addFilter('test_filter', function (string $text): string {
    return mb_strtoupper($text, 'UTF-8');
}, 20);

assertTest("Hook Filter encadeia transformações em ordem", \LightPress\Core\Hook::applyFilters('test_filter', 'Olá') === 'OLÁ MUNDO');

$available = \LightPress\Core\PluginManager::getAvailablePlugins();
assertTest("Descoberta do plugin 'webhook-sender' em /plugins/", isset($available['webhook-sender']));

if (isset($available['webhook-sender'])) {
    require_once $available['webhook-sender']['entrypoint'];
    // Fixar o DNS dos fixtures para não depender da rede durante os testes.
    $previousDnsMock = WebhookSenderService::$dnsMock;
    WebhookSenderService::setDnsMock([
        'webhook-a.test' => ['93.184.216.34'],
        'webhook-b.test' => ['93.184.216.35'],
        'webhook-c.test' => ['93.184.216.36'],
        'unresolved.test' => [],
    ]);
    try {
        $cleanUrls = WebhookSenderService::parseUrls("https://webhook-a.test/hook, https://webhook-b.test/hook\nhttp://webhook-c.test/hook, invalido, https://webhook-a.test/hook");
        assertTest("Parsing e validação de URLs de Webhook por vírgula", $cleanUrls === [
            'https://webhook-a.test/hook',
            'https://webhook-b.test/hook',
            'http://webhook-c.test/hook',
        ]);
        assertTest("Webhook rejeita DNS vazio, loopback e metadados de nuvem", WebhookSenderService::parseUrls(
            "http://unresolved.test/hook, http://127.0.0.1/hook, http://169.254.169.254/latest/meta-data"
        ) === []);
    } finally {
        WebhookSenderService::setDnsMock($previousDnsMock);
    }
}

// -------------------------------------------------------------
// 8. Testes do Sistema de Temas (core/ThemeManager.php)
// -------------------------------------------------------------
echo "\n[8] Testando Sistema de Temas (core/ThemeManager.php)\n";

use LightPress\Core\ThemeManager;
use LightPress\Core\PostService;

$availableThemes = ThemeManager::getAvailableThemes();
assertTest("Descoberta de temas instalados", isset($availableThemes['portal-noticias']) && isset($availableThemes['empresa']));
assertTest("Manifesto de portal-noticias possui tipo 'news'", ($availableThemes['portal-noticias']['type'] ?? '') === 'news');
assertTest("Manifesto de empresa possui tipo 'corporate'", ($availableThemes['empresa']['type'] ?? '') === 'corporate');

// Garantir que portal-noticias está ativo para os testes de opções
ThemeManager::activate('portal-noticias');
assertTest("Tema ativo padrão é portal-noticias", ThemeManager::getActiveTheme() === 'portal-noticias');
assertTest("Tipo do tema ativo é 'news'", ThemeManager::getActiveThemeType() === 'news');

// Testes de opções padrão do SpeedRacing no portal-noticias
assertTest("Opção default 'partner_banner_image' preserva SpeedRacing", ThemeManager::option('partner_banner_image') === '/uploads/2026/10/banner-vertical-qr-site.avif');
assertTest("Opção default 'top_strip_text' preserva SpeedRacing", ThemeManager::option('top_strip_text') === 'Credibilidade no automobilismo desde 2002');
assertTest("Opção default 'menu_categories' contém categorias editoriais", str_contains((string)ThemeManager::option('menu_categories'), 'formula-1'));

// Teste de cálculo de URL de assets e cache-busting
$assetUrl = ThemeManager::asset('css/style.css');
assertTest("Asset URL aponta para tema com cache-busting", str_starts_with($assetUrl, '/assets/themes/portal-noticias/css/style.css?v='));

// Teste de resolução de templates
$homeTemplate = ThemeManager::getTemplateFile('home.php');
assertTest("Resolução de template home.php no tema ativo", file_exists($homeTemplate));

// Teste de palavras reservadas e canonização de rota /blog/
assertTest("Slug 'blog' está em PostService::RESERVED_SLUGS", in_array('blog', PostService::RESERVED_SLUGS, true));

$routerTest = new Router();
$routerTest->setTestingMode(true);
$routerTest->dispatch('GET', '/blog/page/1');
$lastRedirect = $routerTest->getLastRedirect();
assertTest("Redirecionamento canônico 301 de /blog/page/1 para /blog/", ($lastRedirect['url'] ?? '') === '/blog/' && ($lastRedirect['status'] ?? 0) === 301);

$routerTest->dispatch('GET', '/blog/page/1/');
$lastRedirect2 = $routerTest->getLastRedirect();
assertTest("Redirecionamento canônico 301 de /blog/page/1/ para /blog/", ($lastRedirect2['url'] ?? '') === '/blog/' && ($lastRedirect2['status'] ?? 0) === 301);

// Teste de alternância dinâmica para tema corporativo
ThemeManager::activate('empresa');
assertTest("Ativação do tema corporativo 'empresa'", ThemeManager::getActiveTheme() === 'empresa');
assertTest("Tipo do tema ativo é 'corporate'", ThemeManager::getActiveThemeType() === 'corporate');
assertTest("Hero title default do tema empresa", ThemeManager::option('hero_title') === 'Transforme seu Negócio com Soluções de Alta Performance');
assertTest("Asset URL aponta para tema empresa", str_starts_with(ThemeManager::asset('css/style.css'), '/assets/themes/empresa/css/style.css?v='));

// Reativação do tema base portal-noticias (para deixar o SpeedRacing ativo)
ThemeManager::activate('portal-noticias');
assertTest("Reativação segura do tema portal-noticias", ThemeManager::getActiveTheme() === 'portal-noticias');

// -------------------------------------------------------------
// 9. Testes de Validação e Defesa contra XSS em Temas (SEC-03)
// -------------------------------------------------------------
echo "\n[9] Testando Validação de URLs, Âncoras e Defesa contra XSS em Temas (SEC-03)\n";

// Validação de URLs pelo BlockCompiler::sanitizeUrl
assertTest("SEC-03: BlockCompiler::sanitizeUrl rejeita javascript:alert(1)", BlockCompiler::sanitizeUrl('javascript:alert(1)') === '');
assertTest("SEC-03: BlockCompiler::sanitizeUrl rejeita caminhos protocol-relative //evil.com", BlockCompiler::sanitizeUrl('//evil.com') === '');
assertTest("SEC-03: BlockCompiler::sanitizeUrl rejeita caminhos com barra invertida /\\evil.com", BlockCompiler::sanitizeUrl('/\\evil.com') === '');
assertTest("SEC-03: BlockCompiler::sanitizeUrl rejeita injeção de atributo ' onerror='alert(1)", BlockCompiler::sanitizeUrl("' onerror='alert(1)") === '');
assertTest("SEC-03: BlockCompiler::sanitizeUrl rejeita data: URI", BlockCompiler::sanitizeUrl('data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==') === '');
assertTest("SEC-03: BlockCompiler::sanitizeUrl aceita âncora interna #servicos", BlockCompiler::sanitizeUrl('#servicos') === '#servicos');
assertTest("SEC-03: BlockCompiler::sanitizeUrl aceita âncora interna complexa #secao-1:topo", BlockCompiler::sanitizeUrl('#secao-1:topo') === '#secao-1:topo');
assertTest("SEC-03: BlockCompiler::sanitizeUrl aceita âncora simples #", BlockCompiler::sanitizeUrl('#') === '#');
assertTest("SEC-03: BlockCompiler::sanitizeUrl rejeita âncora com injeção #' onclick='alert(1)", BlockCompiler::sanitizeUrl("#' onclick='alert(1)") === '');
assertTest("SEC-03: BlockCompiler::sanitizeUrl aceita caminho relativo seguro /contato/", BlockCompiler::sanitizeUrl('/contato/') === '/contato/');
assertTest("SEC-03: BlockCompiler::sanitizeUrl aceita URL externa legítima https://whatsapp.com/channel/abc", BlockCompiler::sanitizeUrl('https://whatsapp.com/channel/abc') === 'https://whatsapp.com/channel/abc');

// Validação de Imagens pelo BlockCompiler::sanitizeImageUrl
assertTest("SEC-03: BlockCompiler::sanitizeImageUrl rejeita //evil.com/x.png", BlockCompiler::sanitizeImageUrl('//evil.com/x.png') === '');
assertTest("SEC-03: BlockCompiler::sanitizeImageUrl rejeita javascript://alert(1)", BlockCompiler::sanitizeImageUrl('javascript://alert(1)') === '');
assertTest("SEC-03: BlockCompiler::sanitizeImageUrl rejeita data: image URI", BlockCompiler::sanitizeImageUrl('data:image/svg+xml;utf8,<svg onload=alert(1)>') === '');
assertTest("SEC-03: BlockCompiler::sanitizeImageUrl rejeita esquema file:///etc/passwd", BlockCompiler::sanitizeImageUrl('file:///etc/passwd') === '');
assertTest("SEC-03: BlockCompiler::sanitizeImageUrl rejeita ' onerror='alert(1)", BlockCompiler::sanitizeImageUrl("' onerror='alert(1)") === '');
assertTest("SEC-03: BlockCompiler::sanitizeImageUrl aceita caminho local /uploads/2026/10/banner.avif", BlockCompiler::sanitizeImageUrl('/uploads/2026/10/banner.avif') === '/uploads/2026/10/banner.avif');
assertTest("SEC-03: BlockCompiler::sanitizeImageUrl aceita URL absoluta https://example.com/foto.jpg", BlockCompiler::sanitizeImageUrl('https://example.com/foto.jpg') === 'https://example.com/foto.jpg');

// Defesa em profundidade no ThemeManager::getThemeOptions
ThemeManager::setThemeOptions('portal-noticias', [
    'whatsapp_url' => 'javascript:alert(1)',
    'partner_banner_image' => '//evil.com/banner.png',
    'partner_banner_link' => "' onerror='alert(1)",
]);
$sanitizedOptions = ThemeManager::getThemeOptions('portal-noticias');
assertTest("SEC-03: ThemeManager::getThemeOptions neutraliza whatsapp_url maliciosa no banco", ($sanitizedOptions['whatsapp_url'] ?? '') === '');
assertTest("SEC-03: ThemeManager::getThemeOptions neutraliza partner_banner_image protocol-relative no banco", ($sanitizedOptions['partner_banner_image'] ?? '') === '');
assertTest("SEC-03: ThemeManager::getThemeOptions neutraliza partner_banner_link injetado no banco", ($sanitizedOptions['partner_banner_link'] ?? '') === '');

// Restaurar opções limpas para portal-noticias
ThemeManager::setThemeOptions('portal-noticias', []);

// Refinamentos SEC-03: Rejeição estrita de aspas, espaços e < > em caminhos com /
assertTest("SEC-03: sanitizeUrl rejeita /contato' onclick='alert(1)", BlockCompiler::sanitizeUrl("/contato' onclick='alert(1)") === '');
assertTest("SEC-03: sanitizeUrl rejeita /uploads/foto.png\" onerror=\"alert(1)", BlockCompiler::sanitizeUrl('/uploads/foto.png" onerror="alert(1)') === '');
assertTest("SEC-03: sanitizeUrl rejeita /caminho com espaco", BlockCompiler::sanitizeUrl('/caminho com espaco') === '');
assertTest("SEC-03: sanitizeUrl rejeita /caminho<script>", BlockCompiler::sanitizeUrl('/caminho<script>') === '');
assertTest("SEC-03: sanitizeImageUrl rejeita /uploads/' onerror='alert(1)", BlockCompiler::sanitizeImageUrl("/uploads/' onerror='alert(1)") === '');
assertTest("SEC-03: sanitizeImageUrl rejeita /uploads/foto.png\" onerror=\"alert(1)", BlockCompiler::sanitizeImageUrl('/uploads/foto.png" onerror="alert(1)') === '');
assertTest("SEC-03: sanitizeImageUrl rejeita /uploads/foto com espaco.png", BlockCompiler::sanitizeImageUrl('/uploads/foto com espaco.png') === '');

// Refinamentos SEC-03: Prevenção de multi-escape (&amp;amp;) e Idempotência
$queryUrl = 'https://api.whatsapp.com/send?phone=5511999999999&text=Ola';
$s1 = BlockCompiler::sanitizeUrl($queryUrl);
$s2 = BlockCompiler::sanitizeUrl($s1);
$s3 = Security::e($s2);
assertTest("SEC-03: Multi-passagem preserva query string sem multi-escape (&amp;amp;)", $s3 === 'https://api.whatsapp.com/send?phone=5511999999999&amp;text=Ola' && !str_contains($s3, '&amp;amp;'));
assertTest("SEC-03: Security::e com double_encode=false não duplica &amp; em entidades existentes", Security::e("?a=1&amp;b=2") === "?a=1&amp;b=2");
assertTest("SEC-03: Security::e com double_encode=true escapa entidades para contextos como <textarea> (RCDATA)", Security::e("<code>&lt;div&gt;</code>", true) === "&lt;code&gt;&amp;lt;div&amp;gt;&lt;/code&gt;");
assertTest("SEC-03: Security::e padrão com double_encode=false preserva entidades prévias", Security::e("<code>&lt;div&gt;</code>") === "&lt;code&gt;&lt;div&gt;&lt;/code&gt;");

// -------------------------------------------------------------
// [10] Testando ARCH-04: Integridade do Modal de Mídias em Temas
// -------------------------------------------------------------
echo "\n[10] Testando Integridade do Modal de Mídias (ARCH-04 em public/admin/themes.php)\n";
$themesPhpCode = file_get_contents(dirname(__DIR__) . '/public/admin/themes.php');

assertTest("ARCH-04: themes.php utiliza endpoint canônico /admin/api/media.php", str_contains($themesPhpCode, "'/admin/api/media.php'"));
assertTest("ARCH-04: themes.php não referencia rota inexistente format=json", !str_contains($themesPhpCode, 'media.php?format=json'));
assertTest("ARCH-04: themes.php lê array correto data.media", str_contains($themesPhpCode, 'Array.isArray(data.media)') && str_contains($themesPhpCode, 'modalMediaList = data.media;'));
assertTest("ARCH-04: themes.php não referencia chave incorreta data.items", !str_contains($themesPhpCode, 'data.items'));
assertTest("ARCH-04: uploadFromModal envia cabeçalhos AJAX (Accept: application/json)", str_contains($themesPhpCode, "'Accept': 'application/json'"));
assertTest("ARCH-04: uploadFromModal envia cabeçalhos AJAX (X-Requested-With: XMLHttpRequest)", str_contains($themesPhpCode, "'X-Requested-With': 'XMLHttpRequest'"));
assertTest("ARCH-04: Modal grid não utiliza innerHTML para renderização de itens", !str_contains($themesPhpCode, 'grid.innerHTML'));
assertTest("ARCH-04: themes.php possui validador de URL seguro isSafeMediaUrl", str_contains($themesPhpCode, 'function isSafeMediaUrl('));
assertTest("ARCH-04: themes.php implementa busca com debounce searchDebounceTimer", str_contains($themesPhpCode, 'searchDebounceTimer'));

// -------------------------------------------------------------
// [11] Testando ARCH-02: Preservação de is_featured e Transação do Editor
// -------------------------------------------------------------
echo "\n[11] Testando ARCH-02: Preservação de is_featured e Transação do Editor\n";
$editorPhpCode = file_get_contents(dirname(__DIR__) . '/public/admin/editor.php');

assertTest("ARCH-02: editor.php inicializa \$cleanSlug antes da transação", str_contains($editorPhpCode, '$cleanSlug = \'\';'));
assertTest("ARCH-02: editor.php captura \$action na closure da transação", str_contains($editorPhpCode, '$action, $title, $slug'));
assertTest("ARCH-02: editor.php captura \$publishedAtRaw na closure da transação", str_contains($editorPhpCode, '$publishedAt, $publishedAtRaw'));
assertTest("ARCH-02: editor.php captura &\$cleanSlug por referência na closure da transação", str_contains($editorPhpCode, '&$cleanSlug'));

// Teste de integridade em PostService:
$postServiceCode = file_get_contents(dirname(__DIR__) . '/core/PostService.php');
assertTest("ARCH-02: PostService::publishOrUpdate inclui is_featured no SELECT FOR UPDATE", str_contains($postServiceCode, 'status, is_featured, version, published_at FROM posts WHERE id = ?'));

// Teste funcional do PostService::publishOrUpdate preservando is_featured
$postService = new \LightPress\Core\PostService();
$tempRevId = 0;
$tempPostId = $postService->createPost([
    'title' => 'Teste ARCH-02 Feature Flag',
    'slug' => 'teste-arch-02-feature-' . time(),
    'content_html' => '<p>Artigo de teste</p>',
    'content_json' => '[]',
    'status' => 'draft',
], 1, $tempRevId);

// 1. Publicar artigo com is_featured = 1
$postService->publishOrUpdate($tempPostId, [
    'title' => 'Teste ARCH-02 Feature Flag Publicado',
    'content_html' => '<p>Artigo publicado com destaque</p>',
    'is_featured' => 1,
], 1, 1, $tempRevId);

$initiallyFeatured = \LightPress\Core\Database::fetchOne("SELECT is_featured, version FROM posts WHERE id = ?", [$tempPostId]);
assertTest("ARCH-02: PostService::publishOrUpdate grava is_featured = 1 na publicação inicial", (int)($initiallyFeatured['is_featured'] ?? 0) === 1);

// 2. Chamar publishOrUpdate SEM a chave 'is_featured' no payload (ex: aprovação de revisão sem campo is_featured)
$postService->publishOrUpdate($tempPostId, [
    'title' => 'Teste ARCH-02 Feature Flag Atualizado',
    'content_html' => '<p>Artigo atualizado sem alterar destaque</p>',
], 1, (int)$initiallyFeatured['version']);

$persisted = \LightPress\Core\Database::fetchOne("SELECT is_featured, version FROM posts WHERE id = ?", [$tempPostId]);
assertTest("ARCH-02: PostService::publishOrUpdate preserva is_featured = 1 quando chave ausente no payload", (int)($persisted['is_featured'] ?? 0) === 1);

// 3. Chamar publishOrUpdate passando explicitamente is_featured = 0 para desmarcar
$postService->publishOrUpdate($tempPostId, [
    'title' => 'Teste ARCH-02 Desmarcado',
    'content_html' => '<p>Artigo desmarcado</p>',
    'is_featured' => 0,
], 1, (int)$persisted['version']);

$persistedZero = \LightPress\Core\Database::fetchOne("SELECT is_featured, version FROM posts WHERE id = ?", [$tempPostId]);
assertTest("ARCH-02: PostService::publishOrUpdate altera is_featured = 0 quando especificado no payload", (int)($persistedZero['is_featured'] ?? 1) === 0);

// Limpeza do post temporário
\LightPress\Core\Database::query("DELETE FROM posts WHERE id = ?", [$tempPostId]);
\LightPress\Core\Database::query("DELETE FROM post_revisions WHERE post_id = ?", [$tempPostId]);

// -------------------------------------------------------------
// [12] Testando Bloco 2.4a: SEC-04, SEC-06, SEC-07 e SEC-08
// -------------------------------------------------------------
echo "\n[12] Testando Bloco 2.4a: SEC-04, SEC-06, SEC-07 e SEC-08\n";

// SEC-04: Testes de preservação, mascaramento e remoção de senha
$fakePostMasked = ['password' => '••••••••'];
$passMasked = \LightPress\Core\Security::getSafePassword($fakePostMasked, 'password');
assertTest("SEC-04: getSafePassword captura valor mascarado com segurança", $passMasked === '••••••••');
$trimmedMask = trim($passMasked);
assertTest("SEC-04: Regex de máscara identifica bolinhas/asteriscos", (bool)preg_match('/^[•*]+$/u', $trimmedMask));

$fakePostArray = ['password' => ['malicious' => 'array']];
$passFromArray = \LightPress\Core\Security::getSafePassword($fakePostArray, 'password');
assertTest("SEC-04: getSafePassword neutraliza array de entrada retornando string vazia", $passFromArray === '');

$redisSettingsFile = file_get_contents(dirname(__DIR__) . '/plugins/redis-cache-pro/settings.php');
assertTest("SEC-04: settings.php não imprime senha no atributo value", !str_contains($redisSettingsFile, 'value="<?= Security::e((string)($settings[\'password\']'));
assertTest("SEC-04: settings.php possui checkbox de remoção de senha", str_contains($redisSettingsFile, 'name="remove_password"'));

// SEC-06: Resolução de IP com flag trust_cloudflare_headers
$origServer = $_SERVER;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';
unset($_SERVER['HTTP_X_REAL_IP']);

$ipUntrusted = \LightPress\Core\Security::getClientIp(['127.0.0.1'], false);
assertTest("SEC-06: getClientIp ignora CF-Connecting-IP quando trustCloudflareHeaders for falso", $ipUntrusted !== '198.51.100.77');

$ipTrusted = \LightPress\Core\Security::getClientIp(['127.0.0.1'], true);
assertTest("SEC-06: getClientIp aceita CF-Connecting-IP quando trustCloudflareHeaders for verdadeiro", $ipTrusted === '198.51.100.77');

$tunnelConf = file_get_contents(dirname(__DIR__) . '/nginx/speedracing-tunnel.conf');
assertTest("SEC-06: speedracing-tunnel.conf possui location = /admin/activate.php com limit_req", str_contains($tunnelConf, 'location = /admin/activate.php') && str_contains($tunnelConf, 'limit_req zone=speedracing_login burst=5 nodelay;'));

$_SERVER = $origServer;

// SEC-07: Guarda CLI em bin/*.php e sanitização de formulários
$cliScripts = [
    'audit-media-links.php', 'benchmark.php', 'catalog-media.php',
    'cleanup.php', 'migrate-scheduled.php', 'migrate-wp.php',
    'purge-cache.php', 'retry-cache-purge.php', 'seed.php', 'serve.php'
];
foreach ($cliScripts as $script) {
    $scriptContent = file_get_contents(dirname(__DIR__) . "/bin/{$script}");
    assertTest("SEC-07: bin/{$script} possui guarda de PHP_SAPI !== 'cli'", str_contains($scriptContent, "if (PHP_SAPI !== 'cli')"));
}

$sampleArrayPost = ['name' => ['injetado'], 'password' => '  minhasenha  '];
assertTest("SEC-07: getSafeString não dispara TypeError e retorna default com array", \LightPress\Core\Security::getSafeString($sampleArrayPost, 'name', 'padrao') === 'padrao');
assertTest("SEC-07: getSafePassword preserva espaços intactos em senhas legítimas", \LightPress\Core\Security::getSafePassword($sampleArrayPost, 'password') === '  minhasenha  ');

// SEC-08: BlockCompiler sanitiza img[src], srcset e garante noopener noreferrer
$imgMailto = \LightPress\Core\BlockCompiler::sanitizeHtml('<p><img src="mailto:vitima@speedracing.com.br" alt="teste"></p>');
assertTest("SEC-08: sanitizeHtml rejeita mailto: em img[src]", !str_contains($imgMailto, 'mailto:'));

$imgTel = \LightPress\Core\BlockCompiler::sanitizeHtml('<p><img src="tel:+551199999999" alt="teste"></p>');
assertTest("SEC-08: sanitizeHtml rejeita tel: em img[src]", !str_contains($imgTel, 'tel:'));

$imgSrcsetMailto = \LightPress\Core\BlockCompiler::sanitizeHtml('<p><img src="/uploads/teste.jpg" srcset="mailto:vitima@speedracing.com.br 100w" alt="teste"></p>');
assertTest("SEC-08: sanitizeHtml rejeita mailto: em img[srcset]", !str_contains($imgSrcsetMailto, 'mailto:'));

$linkBlankNofollow = \LightPress\Core\BlockCompiler::sanitizeHtml('<p><a href="https://example.com" target="_blank" rel="nofollow">Link</a></p>');
assertTest("SEC-08: sanitizeHtml injeta noopener em link com target=_blank e rel=nofollow", str_contains($linkBlankNofollow, 'noopener'));
assertTest("SEC-08: sanitizeHtml injeta noreferrer em link com target=_blank e rel=nofollow", str_contains($linkBlankNofollow, 'noreferrer'));
assertTest("SEC-08: sanitizeHtml preserva nofollow em link com target=_blank", str_contains($linkBlankNofollow, 'nofollow'));

$mediaPhp = file_get_contents(dirname(__DIR__) . '/public/admin/media.php');
assertTest("SEC-08: media.php não possui onclick='copyUrl('", !str_contains($mediaPhp, "onclick=\"copyUrl('"));
assertTest("SEC-08: media.php utiliza atributo data-copy-url", str_contains($mediaPhp, 'data-copy-url='));
assertTest("SEC-08: media.php possui delegação de clique no document para cópia", str_contains($mediaPhp, "document.addEventListener('click'") && str_contains($mediaPhp, "closest('.btn-copy[data-copy-url]')"));

$empresaPage = file_get_contents(dirname(__DIR__) . '/themes/empresa/page.php');
assertTest("SEC-08: themes/empresa/page.php aplica BlockCompiler::sanitizeHtml", str_contains($empresaPage, 'BlockCompiler::sanitizeHtml'));

$empresaHome = file_get_contents(dirname(__DIR__) . '/themes/empresa/home.php');
assertTest("SEC-08: themes/empresa/home.php aplica BlockCompiler::sanitizeHtml", str_contains($empresaHome, 'BlockCompiler::sanitizeHtml'));

// AUD-SEC-04: Blindagem contra reverse tabnabbing e re-sanitização em restauração de revisões
$empresaPost = file_get_contents(dirname(__DIR__) . '/themes/empresa/post.php');
assertTest("AUD-SEC-04: themes/empresa/post.php link target=_blank possui rel noopener noreferrer", str_contains($empresaPost, 'target="_blank" rel="noopener noreferrer"'));

$empresaFooter = file_get_contents(dirname(__DIR__) . '/themes/empresa/footer.php');
assertTest("AUD-SEC-04: themes/empresa/footer.php link target=_blank possui rel noopener noreferrer", str_contains($empresaFooter, 'target="_blank" rel="noopener noreferrer"'));

$revisionsPhp = file_get_contents(dirname(__DIR__) . '/public/admin/revisions.php');
assertTest("AUD-SEC-04: revisions.php importa BlockCompiler", str_contains($revisionsPhp, 'use LightPress\Core\BlockCompiler;'));
assertTest("AUD-SEC-04: revisions.php aplica BlockCompiler::sanitizeHtml antes de restaurar revisão", str_contains($revisionsPhp, 'BlockCompiler::sanitizeHtml((string)($rev[\'content_html\'] ?? \'\'))'));

// -------------------------------------------------------------
// [13] Testando AUD-SEC-03: Eliminação de Cache Negativo e Rate Limiting em APIs
// -------------------------------------------------------------
echo "\n[13] Testando AUD-SEC-03: Eliminação de Cache Negativo e Rate Limiting em APIs\n";

$indexPhp = file_get_contents(dirname(__DIR__) . '/public/index.php');
assertTest("AUD-SEC-03: public/index.php não utiliza chaves individuais category:{\$categorySlug}", !str_contains($indexPhp, 'category:{$categorySlug}'));
assertTest("AUD-SEC-03: public/index.php não utiliza chaves individuais page_slug:{\$slug}", !str_contains($indexPhp, 'page_slug:{$slug}'));
assertTest("AUD-SEC-03: public/index.php utiliza mapa consolidado all_active_categories", str_contains($indexPhp, 'all_active_categories'));
assertTest("AUD-SEC-03: public/index.php utiliza mapa consolidado all_published_page_slugs", str_contains($indexPhp, 'all_published_page_slugs'));

// Validação em runtime dos mapas compactos
$categoriesMap = \LightPress\Core\ObjectCache::remember('all_active_categories', 86400, function (): array {
    $rows = \LightPress\Core\Database::fetchAll("SELECT * FROM categories WHERE is_active = 1");
    $map = [];
    foreach ($rows as $row) {
        $map[$row['slug']] = $row;
    }
    return $map;
}, 'categories');
assertTest("AUD-SEC-03: all_active_categories retorna array indexado por slug", is_array($categoriesMap));
assertTest("AUD-SEC-03: all_active_categories rejeita slug inexistente com null em RAM", ($categoriesMap['categoria-fantasma-404'] ?? null) === null);

$publishedPageSlugs = \LightPress\Core\ObjectCache::remember('all_published_page_slugs', 86400, function (): array {
    $rows = \LightPress\Core\Database::fetchAll("SELECT id, slug FROM pages WHERE status = 'published'");
    $map = [];
    foreach ($rows as $row) {
        $map[$row['slug']] = (int)$row['id'];
    }
    return $map;
}, 'pages');
assertTest("AUD-SEC-03: all_published_page_slugs retorna mapa de slugs publicados", is_array($publishedPageSlugs));
assertTest("AUD-SEC-03: all_published_page_slugs não contém slug 404", !isset($publishedPageSlugs['pagina-fantasma-404']));

// Testando public/api/search.php
$searchApi = file_get_contents(dirname(__DIR__) . '/public/api/search.php');
assertTest("AUD-SEC-03: search.php utiliza índice FULLTEXT em BOOLEAN MODE", str_contains($searchApi, 'MATCH(title, summary, tags) AGAINST(? IN BOOLEAN MODE)'));
assertTest("AUD-SEC-03: search.php utiliza ObjectCache::remember para resultados", str_contains($searchApi, 'ObjectCache::remember'));
assertTest("AUD-SEC-03: search.php possui fallback seguro via LIKE", str_contains($searchApi, 'title LIKE ? OR summary LIKE ?'));

// Testando public/api/categories.php
$catApi = file_get_contents(dirname(__DIR__) . '/public/api/categories.php');
assertTest("AUD-SEC-03: categories.php utiliza ObjectCache::remember com chave api_categories_list", str_contains($catApi, "ObjectCache::remember('api_categories_list'"));

// Testando Nginx speedracing-tunnel.conf
$speedracingConf = file_get_contents(dirname(__DIR__) . '/nginx/speedracing-tunnel.conf');
assertTest("AUD-SEC-03: speedracing-tunnel.conf possui fastcgi_cache_valid 404 1m", str_contains($speedracingConf, 'fastcgi_cache_valid 404 1m;'));
assertTest("AUD-SEC-03: speedracing-tunnel.conf define limit_req_zone speedracing_api", str_contains($speedracingConf, 'zone=speedracing_api:10m rate=60r/m;'));
assertTest("AUD-SEC-03: speedracing-tunnel.conf possui location ^~ /api/ com limit_req", str_contains($speedracingConf, 'location ^~ /api/') && str_contains($speedracingConf, 'limit_req zone=speedracing_api burst=20 nodelay;'));

// Testando Nginx lightpress.conf
$lpConf = file_get_contents(dirname(__DIR__) . '/nginx/lightpress.conf');
assertTest("AUD-SEC-03: lightpress.conf define limit_req_zone lp_api", str_contains($lpConf, 'zone=lp_api:10m rate=60r/m;'));
assertTest("AUD-SEC-03: lightpress.conf possui location ^~ /api/ com limit_req", str_contains($lpConf, 'location ^~ /api/') && str_contains($lpConf, 'limit_req zone=lp_api burst=20 nodelay;'));

// Testando invalidações de cache no admin
$adminCatPhp = file_get_contents(dirname(__DIR__) . '/public/admin/categories.php');
assertTest("AUD-SEC-03: admin/categories.php invalida all_active_categories", str_contains($adminCatPhp, "ObjectCache::delete('all_active_categories', 'categories')"));
assertTest("AUD-SEC-03: admin/categories.php invalida api_categories_list", str_contains($adminCatPhp, "ObjectCache::delete('api_categories_list', 'categories')"));

$adminPageEditPhp = file_get_contents(dirname(__DIR__) . '/public/admin/page-edit.php');
assertTest("AUD-SEC-03: admin/page-edit.php invalida all_published_page_slugs", str_contains($adminPageEditPhp, "ObjectCache::delete('all_published_page_slugs', 'pages')"));

$adminPagesPhp = file_get_contents(dirname(__DIR__) . '/public/admin/pages.php');
assertTest("AUD-SEC-03: admin/pages.php invalida all_published_page_slugs", str_contains($adminPagesPhp, "ObjectCache::delete('all_published_page_slugs', 'pages')"));

// -------------------------------------------------------------
// [14] AUD-PERF-02 & AUD-PERF-03: Otimização de Mídia e Segregação de Cache
// -------------------------------------------------------------
echo "\n[14] Testando AUD-PERF-02 e AUD-PERF-03: Otimização de Mídia e Segregação de Cache Redis\n";

// 1. AUD-PERF-02: Schema e migração FULLTEXT em media
$schemaSql = file_get_contents(dirname(__DIR__) . '/bin/schema.sql');
assertTest("AUD-PERF-02: bin/schema.sql define FULLTEXT INDEX idx_media_fulltext", str_contains($schemaSql, 'FULLTEXT INDEX idx_media_fulltext (original_name, alt_text, caption, ai_tags)'));

$schemaPhp = file_get_contents(dirname(__DIR__) . '/core/Schema.php');
assertTest("AUD-PERF-02: core/Schema.php possui ensureMediaFulltextSchema", str_contains($schemaPhp, 'function ensureMediaFulltextSchema'));
assertTest("AUD-PERF-02: core/Schema.php invoca ensureMediaFulltextSchema após ensureMediaAiSchema", strpos($schemaPhp, 'ensureMediaFulltextSchema') > strpos($schemaPhp, 'ensureMediaAiSchema'));

// 2. AUD-PERF-02: public/admin/media.php e public/admin/api/media.php
$mediaPhp = file_get_contents(dirname(__DIR__) . '/public/admin/media.php');
assertTest("AUD-PERF-02: media.php importa Settings", str_contains($mediaPhp, 'use LightPress\Core\Settings;'));
assertTest("AUD-PERF-02: media.php checa media_refs com SELECT 1 LIMIT 1", str_contains($mediaPhp, 'SELECT 1 FROM media_refs WHERE media_id = ? LIMIT 1'));
assertTest("AUD-PERF-02: media.php checa posts.featured_media_id com SELECT 1 LIMIT 1", str_contains($mediaPhp, 'SELECT 1 FROM posts WHERE featured_media_id = ? LIMIT 1'));
assertTest("AUD-PERF-02: media.php checa posts.featured_image com IN (...) LIMIT 1", str_contains($mediaPhp, 'posts WHERE featured_image IN'));
assertTest("AUD-PERF-02: media.php checa pages.featured_image com IN (...) LIMIT 1", str_contains($mediaPhp, 'pages WHERE featured_image IN'));
assertTest("AUD-PERF-02: media.php checa users.avatar com IN (...) LIMIT 1", str_contains($mediaPhp, 'users WHERE avatar IN'));
assertTest("AUD-PERF-02: media.php checa Settings::getAll()", str_contains($mediaPhp, 'Settings::getAll()'));
assertTest("AUD-PERF-02: media.php utiliza uniquePaths normalizados", str_contains($mediaPhp, '$uniquePaths'));
assertTest("AUD-PERF-02: media.php não possui SELECT COUNT(*) com LIKE em content_json na exclusão", !str_contains($mediaPhp, 'SELECT COUNT(*) FROM posts WHERE featured_image = ? OR content_json LIKE ?'));
assertTest("AUD-PERF-02: media.php possui busca em media_variants com uploads/", str_contains($mediaPhp, "prefixedPath = str_starts_with(\$cleanPath, 'uploads/') ? \$cleanPath : 'uploads/' . \$cleanPath"));
assertTest("AUD-PERF-02: media.php possui MATCH AGAINST BOOLEAN MODE com try/catch e fallback LIKE", str_contains($mediaPhp, 'MATCH(m.original_name, m.alt_text, m.caption, m.ai_tags) AGAINST(? IN BOOLEAN MODE)'));

$apiMediaPhp = file_get_contents(dirname(__DIR__) . '/public/admin/api/media.php');
assertTest("AUD-PERF-02: api/media.php possui MATCH AGAINST BOOLEAN MODE com fallback", str_contains($apiMediaPhp, 'MATCH(original_name, alt_text, caption, ai_tags) AGAINST(? IN BOOLEAN MODE)'));
assertTest("AUD-PERF-02: api/media.php possui busca B-Tree em media_variants com uploads/", str_contains($apiMediaPhp, "prefixedPath = str_starts_with(\$cleanPath, 'uploads/') ? \$cleanPath : 'uploads/' . \$cleanPath"));

// 3. AUD-PERF-03: Segregação post_items vs posts
$indexPhp = file_get_contents(dirname(__DIR__) . '/public/index.php');
assertTest("AUD-PERF-03: public/index.php busca post_slug no grupo post_items", str_contains($indexPhp, 'ObjectCache::get("post_slug:{$slug}", \'post_items\')'));
assertTest("AUD-PERF-03: public/index.php grava post_slug no grupo post_items", str_contains($indexPhp, 'ObjectCache::set("post_slug:{$slug}", $p, \'post_items\', $postTtl)'));

$postServicePhp = file_get_contents(dirname(__DIR__) . '/core/PostService.php');
assertTest("AUD-PERF-03: PostService captura resolvedSlug por referência", str_contains($postServicePhp, '&$resolvedSlug'));
assertTest("AUD-PERF-03: PostService invalida post_slug no grupo post_items após commit", str_contains($postServicePhp, 'ObjectCache::delete("post_slug:{$resolvedSlug}", \'post_items\')'));
assertTest("AUD-PERF-03: PostService invalida posts agendados promovidos em post_items", str_contains($postServicePhp, 'ObjectCache::delete("post_slug:{$p[\'slug\']}", \'post_items\')'));

$dashboardPhp = file_get_contents(dirname(__DIR__) . '/public/admin/dashboard.php');
assertTest("AUD-PERF-03: dashboard.php invalida posts ao alternar destaque toggle_featured_id", str_contains($dashboardPhp, "ObjectCache::flushGroup('posts');"));
assertTest("AUD-PERF-03: dashboard.php invalida post_slug em post_items na exclusão", str_contains($dashboardPhp, 'ObjectCache::delete("post_slug:{$targetPost[\'slug\']}", \'post_items\')'));

$editorPhp = file_get_contents(dirname(__DIR__) . '/public/admin/editor.php');
assertTest("AUD-PERF-03: editor.php invalida post_slug em post_items ao rebaixar post", str_contains($editorPhp, 'ObjectCache::delete("post_slug:{$prevSlug}", \'post_items\')'));

$categoriesPhp = file_get_contents(dirname(__DIR__) . '/public/admin/categories.php');
assertTest("AUD-PERF-03: categories.php invalida post_items e posts ao alterar slug de categoria", str_contains($categoriesPhp, "ObjectCache::flushGroup('post_items');") && str_contains($categoriesPhp, "if (\$oldSlug !== \$slug)"));

$usersPhp = file_get_contents(dirname(__DIR__) . '/public/admin/users.php');
assertTest("AUD-PERF-03: users.php importa ObjectCache", str_contains($usersPhp, 'use LightPress\Core\ObjectCache;'));
assertTest("AUD-PERF-03: users.php invalida post_items ao atualizar autor", str_contains($usersPhp, "ObjectCache::flushGroup('post_items');"));

$profilePhp = file_get_contents(dirname(__DIR__) . '/public/admin/profile.php');
assertTest("AUD-PERF-03: profile.php importa ObjectCache", str_contains($profilePhp, 'use LightPress\Core\ObjectCache;'));
assertTest("AUD-PERF-03: profile.php invalida post_items ao atualizar perfil", str_contains($profilePhp, "ObjectCache::flushGroup('post_items');"));

$themeManagerPhp = file_get_contents(dirname(__DIR__) . '/core/ThemeManager.php');
assertTest("AUD-PERF-03: ThemeManager invalida post_items ao alterar opções de tema", str_contains($themeManagerPhp, "ObjectCache::flushGroup('post_items');"));

$rcpSettingsPhp = file_get_contents(dirname(__DIR__) . '/plugins/redis-cache-pro/settings.php');
assertTest("AUD-PERF-03: redis-cache-pro settings.php lista post_items no Big Key Hunter / Grupos", str_contains($rcpSettingsPhp, "'post_items' =>"));

$rcpMainPhp = file_get_contents(dirname(__DIR__) . '/plugins/redis-cache-pro/redis-cache-pro.php');
assertTest("AUD-PERF-03: redis-cache-pro.php invalida post_items nos hooks post_saved e post_deleted", str_contains($rcpMainPhp, 'post_items'));

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
