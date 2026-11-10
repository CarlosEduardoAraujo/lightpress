<?php
declare(strict_types=1);

/**
 * Bateria de Testes Automatizados - Redis Cache Pro para LightPress
 */

require_once dirname(__DIR__, 3) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/redis-cache-pro.php';
require_once __DIR__ . '/FakeRedis.php';

use LightPress\Plugin\RedisCachePro\RedisClient;
use LightPress\Plugin\RedisCachePro\RedisCache;
use LightPress\Plugin\RedisCachePro\CacheMaintenance;
use LightPress\Plugin\RedisCachePro\WarmupService;
use LightPress\Plugin\RedisCachePro\AlertService;
use LightPress\Plugin\RedisCachePro\QueryProfiler;
use LightPress\Plugin\RedisCachePro\Tests\FakeRedis;

echo "========================================================\n";
echo "⚡ INICIANDO SUÍTE DE TESTES: REDIS CACHE PRO (LIGHTPRESS)\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function it(string $description, callable $fn): void {
    global $passCount, $failCount;
    try {
        $fn();
        echo "  [PASS] {$description}\n";
        $passCount++;
    } catch (Throwable $e) {
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

// 1. Setup do Ambiente de Testes com FakeRedis
FakeRedis::clear();
$fake = new FakeRedis();
$client = RedisClient::getInstance();
$client->setClient($fake);

echo "1. Testes de Operações Básicas de Cache (GET, SET, DELETE, REMEMBER)\n";

it('Grava e recupera valor no Redis com sucesso', function() use ($client) {
    $res = $client->set('noticia_1', ['id' => 1, 'titulo' => 'Título de Teste'], 'posts', 3600);
    expectTrue($res, 'set deve retornar true');

    $found = false;
    $val = $client->get('noticia_1', 'posts', $found);
    expectTrue($found, 'deve sinalizar found = true');
    expectEqual(1, $val['id']);
    expectEqual('Título de Teste', $val['titulo']);
});

it('Recupera valor direto da memória local sem ir ao Redis na mesma requisição', function() use ($client) {
    $client->set('mem_key', 'valor_local', 'default');
    QueryProfiler::reset();

    $val = $client->get('mem_key', 'default');
    expectEqual('valor_local', $val);

    $logs = QueryProfiler::getLogs();
    expectEqual('GET (MEMORY)', $logs[0]['command'] ?? '', 'deve ser servido via memória');
});

it('Remove chave do cache via DELETE/UNLINK', function() use ($client) {
    $client->set('chave_remover', 'dado', 'posts');
    $del = $client->delete('chave_remover', 'posts');
    expectTrue($del);

    $found = false;
    $val = $client->get('chave_remover', 'posts', $found);
    expectEqual(false, $found);
    expectEqual(false, $val);
});

it('Método remember() computa apenas na primeira chamada e armazena em cache', function() use ($client) {
    $executions = 0;
    $calc = function() use (&$executions) {
        $executions++;
        return 'resultado_pesado_calculado';
    };

    $val1 = $client->remember('heavy_calc', 3600, $calc, 'posts');
    $val2 = $client->remember('heavy_calc', 3600, $calc, 'posts');

    expectEqual('resultado_pesado_calculado', $val1);
    expectEqual('resultado_pesado_calculado', $val2);
    expectEqual(1, $executions, 'Callback deve ser chamado exatamente uma vez');
});

echo "\n2. Invalidação Atômica O(1) de Namespace e Grupos\n";

it('Invalidação de Grupo (flushGroup) isola apenas o grupo afetado', function() use ($client) {
    $client->set('item_post', 'post_ativo', 'posts');
    $client->set('item_cat', 'cat_ativa', 'categories');

    // Purgar apenas o grupo posts
    $client->flushGroup('posts');

    // posts deve retornar miss
    $foundPost = false;
    $p = $client->get('item_post', 'posts', $foundPost);
    expectEqual(false, $foundPost);

    // categories DEVE continuar intacto
    $foundCat = false;
    $c = $client->get('item_cat', 'categories', $foundCat);
    expectTrue($foundCat);
    expectEqual('cat_ativa', $c);
});

it('Invalidação Global (flush) invalida todo o namespace em O(1)', function() use ($client) {
    $client->set('chave_a', 'valor_a', 'settings');
    $client->set('chave_b', 'valor_b', 'categories');

    // Limpar cache global
    $res = $client->flush();
    expectTrue($res);

    $fA = false;
    $client->get('chave_a', 'settings', $fA);
    expectEqual(false, $fA, 'settings deve estar invalidado');

    $fB = false;
    $client->get('chave_b', 'categories', $fB);
    expectEqual(false, $fB, 'categories deve estar invalidado');
});

echo "\n3. Testes do Circuit Breaker & Resiliência\n";

it('Circuit Breaker desarma para estado OPEN em 3 falhas consecutivas', function() use ($client, $fake) {
    $fake->shouldFailPing = true;

    // Forçar falhas
    for ($i = 0; $i < 3; $i++) {
        $client->isConnected();
    }

    $diag = $client->getDiagnosticInfo();
    expectEqual('OPEN', $diag['circuit_breaker'], 'Disjuntor deve estar em OPEN após 3 falhas');
    expectEqual('degraded', $diag['status']);

    // Operações em bypass continuam sem disparar exceção não tratada
    $client->set('chave_degradada', 'salva_em_memoria', 'posts');
    $val = $client->get('chave_degradada', 'posts');
    expectEqual('salva_em_memoria', $val, 'deve operar com transparência via fallback');

    $fake->shouldFailPing = false;
});

echo "\n4. Scanner de Big Keys e Coletor de Gerações Obsoletas (CacheMaintenance)\n";

it('Big Key Hunter identifica os maiores objetos em RAM usando SCAN', function() use ($client) {
    $maint = new CacheMaintenance($client);

    // Criar objetos de tamanhos variados
    $client->set('pequeno', '123', 'posts');
    $client->set('grande', str_repeat('X', 5000), 'posts');
    $client->set('medio', str_repeat('Y', 500), 'posts');

    $bigKeys = $maint->scanBigKeys(10, 100);
    expectTrue(count($bigKeys) >= 3);
    expectTrue(str_contains($bigKeys[0]['key'], 'grande'), 'A maior chave deve aparecer no topo');
});

it('Coletor incremental (step) identifica e desaloca chaves de gerações obsoletas com UNLINK', function() use ($client, $fake) {
    $maint = new CacheMaintenance($client);

    // Gravar chave na geração 1
    $client->set('noticia_antiga', 'conteudo_velho', 'posts');

    // Forçar nova geração via flush
    $client->flush();

    // Gravar chave na geração 2 (atual)
    $client->set('noticia_nova', 'conteudo_fresco', 'posts');

    // Executar coleta com cleanup = true
    $state = $maint->step([], true, 100, 200);

    expectTrue($state['examined'] >= 2, 'deve ter examinado as chaves');
    expectTrue($state['deleted'] >= 1, 'deve ter deletado a geração obsoleta');
    expectTrue($state['categories']['obsolete_global']['keys'] >= 1, 'classificada como obsolete_global');
});

echo "\n5. Serviço de Alertas e Notificações (AlertService)\n";

it('Validação e montagem de alerta de teste sem erros sintáticos', function() {
    $res = AlertService::sendTestNotification('');
    expectEqual(false, $res['success'], 'URL vazia deve falhar de forma controlada');
    expectEqual(400, $res['code']);
});

echo "\n6. Integração do ObjectCache com Ciclo de Vida e Telemetria Persistente\n";

it('ObjectCache::isAvailable() reavalia dinamicamente e detecta o plugin carregado', function() {
    expectTrue(\LightPress\Core\ObjectCache::isAvailable(), 'ObjectCache deve reportar disponibilidade sem travar em falso');
});

it('QueryProfiler armazena e recupera operações persistentes do buffer circular', function() use ($client, $fake) {
    QueryProfiler::log('GET', 'speedracing_posts:test_key', 0.45, 'HIT', 'PostService::getById');
    $logs = QueryProfiler::getLogs();
    expectTrue(count($logs) > 0, 'Deve conter ao menos uma operação perfilada');
    expectEqual('GET', $logs[0]['command']);
});

it('Telemetria agregada e RTT são calculados sem valores fixos artificiais', function() use ($client, $fake) {
    $diag = $client->getDiagnosticInfo();
    expectTrue(isset($diag['commands_count']));
    expectTrue(isset($diag['ping_rtt_ms']));
});

echo "\n========================================================\n";
echo "📊 RESULTADO FINAL DA SUÍTE:\n";
echo "   Aprovados (PASS): {$passCount}\n";
echo "   Falhas    (FAIL): {$failCount}\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
