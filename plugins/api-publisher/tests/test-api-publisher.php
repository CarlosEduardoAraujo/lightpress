<?php
declare(strict_types=1);

/**
 * Bateria de Testes Automatizados - API Publisher & Application Passwords
 * Execução: php plugins/api-publisher/tests/test-api-publisher.php
 */

require_once dirname(__DIR__, 3) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/api-publisher.php';

use LightPress\Core\Authorization;
use LightPress\Core\BlockCompiler;
use LightPress\Core\Database;
use LightPress\Core\PostService;
use LightPress\Core\Security;
use LightPress\Plugin\ApiPublisher\ApiPublisherService;
use LightPress\Plugin\ApiPublisher\MarkdownParser;

echo "====================================================================\n";
echo "⚡ INICIANDO SUÍTE DE TESTES: API PUBLISHER & APPLICATION PASSWORDS\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function it(string $description, callable $fn): void {
    global $passCount, $failCount;
    try {
        $fn();
        echo "  [PASS] {$description}\n";
        $passCount++;
    } catch (\Throwable $e) {
        echo "  [FAIL] {$description}\n";
        echo "         -> Erro: {$e->getMessage()}\n";
        echo "         -> Linha: {$e->getFile()}:{$e->getLine()}\n";
        $failCount++;
    }
}

function expectEqual(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException("Falha na asserção: esperado " . var_export($expected, true) . ", recebido " . var_export($actual, true) . ($msg ? " ({$msg})" : ''));
    }
}

function expectTrue(mixed $actual, string $msg = ''): void {
    if ($actual !== true) {
        throw new RuntimeException("Falha na asserção: esperado true, recebido " . var_export($actual, true) . ($msg ? " ({$msg})" : ''));
    }
}

// -------------------------------------------------------------
// SEÇÃO 1: TESTES DO PARSER DE MARKDOWN E BLINDAGEM XSS
// -------------------------------------------------------------
echo "1. Testes de Markdown Semântico & Sanitização\n";

it('Normaliza cabeçalhos # e ## para <h2> (evita H1 duplo)', function (): void {
    $md = "# Título Principal da IA\n\n## Subtítulo Seção\n\n### Sub-seção H3\n\n#### Detalhe H4";
    $html = MarkdownParser::parse($md);
    
    expectTrue(str_contains($html, '<h2>Título Principal da IA</h2>'), 'H1 deve virar H2');
    expectTrue(str_contains($html, '<h2>Subtítulo Seção</h2>'), 'H2 deve virar H2');
    expectTrue(str_contains($html, '<h3>Sub-seção H3</h3>'), 'H3 deve virar H3');
    expectTrue(str_contains($html, '<h4>Detalhe H4</h4>'), 'H4 deve virar H4');
});

it('Converte listas não ordenadas, listas ordenadas e citações', function (): void {
    $md = "* Item 1\n* Item 2\n\n1. Primeiro\n2. Segundo\n\n> Citação importante da fonte";
    $html = MarkdownParser::parse($md);

    expectTrue(str_contains($html, '<ul><li>Item 1</li><li>Item 2</li></ul>'), 'Lista não ordenada');
    expectTrue(str_contains($html, '<ol><li>Primeiro</li><li>Segundo</li></ol>'), 'Lista ordenada');
    expectTrue(str_contains($html, '<blockquote><p>Citação importante da fonte</p></blockquote>'), 'Blockquote');
});

it('Processa elementos inline (negrito, itálico e links com sanitização)', function (): void {
    $md = "Texto com **negrito**, *itálico* e um [link seguro](https://speedracing.com.br).";
    $html = MarkdownParser::parse($md);

    expectTrue(str_contains($html, '<strong>negrito</strong>'));
    expectTrue(str_contains($html, '<em>itálico</em>'));
    expectTrue(str_contains($html, '<a href="https://speedracing.com.br">link seguro</a>'));
});

it('Blindagem contra vetores XSS no pipeline Markdown + BlockCompiler', function (): void {
    $maliciousMd = "Texto normal\n\n<script>alert('xss')</script>\n\n<iframe src='malicious.com'></iframe>\n\n[Link XSS](javascript:alert(1))";
    $rawHtml = MarkdownParser::parse($maliciousMd);
    $safeHtml = BlockCompiler::sanitizeHtml($rawHtml);

    expectTrue(!str_contains($safeHtml, '<script>'), 'Scripts devem ser expurgados');
    expectTrue(!str_contains($safeHtml, '<iframe>'), 'iFrames devem ser expurgados');
    expectTrue(!str_contains($safeHtml, 'javascript:'), 'Esquema javascript: deve ser neutralizado');
});

// -------------------------------------------------------------
// SEÇÃO 2: SCHEMA DDL, GERAÇÃO E CICLO DE VIDA DE SENHAS
// -------------------------------------------------------------
echo "\n2. Testes de Banco de Dados, Senhas de Aplicação e RBAC\n";

ApiPublisherService::ensureTablesSchema();

// Criar ou recuperar usuário de teste para os ensaios
$testUser = Database::fetchOne("SELECT id, username, role FROM users WHERE username = 'test_api_author' LIMIT 1");
if (!$testUser) {
    Database::query(
        "INSERT INTO users (name, username, email, password_hash, role, is_active, created_at)
         VALUES ('Autor Teste API', 'test_api_author', 'api_author@test.local', ?, 'author', 1, CURRENT_TIMESTAMP)",
        [Security::hashPassword('SenhaTemporaria123!')]
    );
    $testUserId = (int)Database::lastInsertId();
} else {
    $testUserId = (int)$testUser['id'];
}

$createdPasswordData = null;

it('Criação e integridade das tabelas relacionais do plugin', function (): void {
    $driver = Database::getDriver();
    if ($driver === 'sqlite') {
        $pap = Database::fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='plugin_app_passwords'");
        $pail = Database::fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='plugin_api_ingest_log'");
        expectTrue(!empty($pap), 'Tabela plugin_app_passwords deve existir');
        expectTrue(!empty($pail), 'Tabela plugin_api_ingest_log deve existir');
    } else {
        $pap = Database::fetchOne("SHOW TABLES LIKE 'plugin_app_passwords'");
        $pail = Database::fetchOne("SHOW TABLES LIKE 'plugin_api_ingest_log'");
        expectTrue(!empty($pap));
        expectTrue(!empty($pail));
    }
});

it('Gera Senha de Aplicação com prefixo público O(1) e hash Argon2id', function () use ($testUserId, &$createdPasswordData): void {
    $res = ApiPublisherService::generatePassword($testUserId, 'n8n-test-app', '127.0.0.1, 192.168.1.50');
    $createdPasswordData = $res;

    expectEqual('n8n-test-app', $res['name']);
    expectEqual('test_api_author', $res['username']);
    expectTrue(str_starts_with($res['full_password'], 'lp_app_'));
    expectTrue(str_starts_with($res['key_prefix'], 'lp_app_'));

    // Conferir que o banco NÃO contém a senha em texto claro
    $row = Database::fetchOne("SELECT key_prefix, secret_hash, allowed_ips FROM plugin_app_passwords WHERE id = ?", [$res['id']]);
    expectTrue($row !== false);
    expectEqual($res['key_prefix'], $row['key_prefix']);
    expectTrue($row['secret_hash'] !== $res['full_password'], 'O segredo nunca deve ser salvo em texto claro');
    expectTrue(Security::verifyPassword($res['full_password'], $row['secret_hash']), 'Hash Argon2id deve validar a chave');
});

it('Autentica com sucesso usando username e senha de aplicação válidos', function () use (&$createdPasswordData): void {
    $auth = ApiPublisherService::authenticate(
        $createdPasswordData['username'],
        $createdPasswordData['full_password'],
        '127.0.0.1'
    );

    expectEqual('test_api_author', $auth['user']['username']);
    expectEqual($createdPasswordData['id'], $auth['password_id']);
    expectEqual('n8n-test-app', $auth['app_name']);
});

it('Rejeita autenticação quando o username informado não corresponde ao titular da chave', function () use (&$createdPasswordData): void {
    try {
        ApiPublisherService::authenticate(
            'outro_usuario_qualquer',
            $createdPasswordData['full_password'],
            '127.0.0.1'
        );
        throw new RuntimeException('Deveria ter falhado por divergência de username');
    } catch (RuntimeException $e) {
        expectEqual(401, $e->getCode());
        expectTrue(str_contains($e->getMessage(), 'não corresponde ao titular'));
    }
});

it('Bloqueia acesso com HTTP 403 quando o IP não consta na allowlist', function () use (&$createdPasswordData): void {
    try {
        ApiPublisherService::authenticate(
            $createdPasswordData['username'],
            $createdPasswordData['full_password'],
            '203.0.113.99' // IP não listado em '127.0.0.1, 192.168.1.50'
        );
        throw new RuntimeException('Deveria ter falhado por IP não autorizado');
    } catch (RuntimeException $e) {
        expectEqual(403, $e->getCode());
        expectTrue(str_contains($e->getMessage(), 'IP de origem não autorizado'));
    }
});

it('Revogação instantânea invalida a chave de imediato com HTTP 401', function () use (&$createdPasswordData): void {
    // Revoga a senha
    ApiPublisherService::revokePassword($createdPasswordData['id']);

    try {
        ApiPublisherService::authenticate(
            $createdPasswordData['username'],
            $createdPasswordData['full_password'],
            '127.0.0.1'
        );
        throw new RuntimeException('Deveria ter falhado por chave revogada');
    } catch (RuntimeException $e) {
        expectEqual(401, $e->getCode());
        expectTrue(str_contains($e->getMessage(), 'revogada'));
    }
});

// -------------------------------------------------------------
// SEÇÃO 3: INGESTÃO ATÔMICA, IDEMPOTÊNCIA E RASCUNHO EXCLUSIVO
// -------------------------------------------------------------
echo "\n3. Testes de Ingestão Atômica, Idempotência e PostService\n";

// Criar nova senha ativa para testes de ingestão
$ingestPassword = ApiPublisherService::generatePassword($testUserId, 'n8n-ingest-bot');

it('Cria rascunho com sucesso forçando status=draft, post_stats zerado e post_revisions', function () use ($testUserId): void {
    $redditId = 't3_' . bin2hex(random_bytes(6));
    $postData = [
        'title' => 'Matéria Exclusiva de Teste Ingestão n8n',
        'summary' => 'Resumo resumido da notícia',
        'category' => 'geral',
        'tags' => 'f1, automacao, n8n',
        'content_json' => '[{"type":"paragraph","content":"Parágrafo do artigo."}]',
        'content_html' => '<p>Parágrafo do artigo.</p>',
        'seo_title' => 'Matéria Exclusiva - SEO',
        'seo_description' => 'Descrição SEO',
        'focus_keyword' => 'ingestão n8n',
    ];

    $result = ApiPublisherService::createIngestedPost($postData, $testUserId, $redditId);

    expectEqual('draft', $result['status'], 'Status DEVE ser estritamente draft');
    expectEqual('geral', $result['category'], 'Categoria padrão geral');
    expectEqual($testUserId, $result['author_id']);
    expectTrue($result['id'] > 0);

    // Conferir no banco
    $savedPost = Database::fetchOne("SELECT status, is_featured, published_at FROM posts WHERE id = ?", [$result['id']]);
    expectEqual('draft', $savedPost['status']);
    expectEqual(0, (int)$savedPost['is_featured']);
    expectEqual(null, $savedPost['published_at']);

    // Conferir post_stats
    $views = Database::fetchValue("SELECT views FROM post_stats WHERE post_id = ?", [$result['id']]);
    expectEqual(0, (int)$views, 'Views devem ser iniciadas com zero');

    // Conferir post_revisions
    $revCount = (int)Database::fetchValue("SELECT COUNT(*) FROM post_revisions WHERE post_id = ?", [$result['id']]);
    expectTrue($revCount >= 1, 'Snapshot inicial de revisão deve existir');

    // Testar idempotência
    $idempotentCheck = ApiPublisherService::checkIdempotency($redditId);
    expectTrue($idempotentCheck !== null, 'Deveria encontrar rascunho existente pela external_key');
    expectEqual($result['id'], $idempotentCheck['post_id']);
});

it('Reenvio de mesmo external_id é idempotente e devolve rascunho sem duplicar', function () use ($testUserId): void {
    $sharedRedditId = 't3_shared_' . bin2hex(random_bytes(4));

    $postData = [
        'title' => 'Artigo Idempotência 1',
        'summary' => 'Resumo 1',
        'category' => 'geral',
        'tags' => 'ia',
        'content_json' => '[]',
        'content_html' => '<p>Conteúdo</p>',
    ];

    // Primeira ingestão
    $first = ApiPublisherService::createIngestedPost($postData, $testUserId, $sharedRedditId);
    $firstId = $first['id'];

    // Segunda chamada simulando o nó n8n disparado novamente com o mesmo reddit_id
    $existing = ApiPublisherService::checkIdempotency($sharedRedditId);
    expectTrue($existing !== null);
    expectEqual($firstId, $existing['post_id']);

    // Verificar se não há post duplicado com aquele ID externo
    $count = (int)Database::fetchValue("SELECT COUNT(*) FROM plugin_api_ingest_log WHERE external_key = ?", [$sharedRedditId]);
    expectEqual(1, $count, 'Deve haver exatamente 1 registro de log para a chave externa');
});

// Limpeza das chaves e posts de teste criados
try {
    Database::query("DELETE FROM plugin_app_passwords WHERE user_id = ?", [$testUserId]);
    Database::query("DELETE FROM posts WHERE user_id = ?", [$testUserId]);
} catch (\Throwable) {}

echo "\n====================================================================\n";
echo "📊 RESULTADO DA SUÍTE: {$passCount} PASS, {$failCount} FAIL\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
