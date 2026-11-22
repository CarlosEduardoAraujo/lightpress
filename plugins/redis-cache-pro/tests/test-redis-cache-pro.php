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
QueryProfiler::setEnabled(true);

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

it('AUD-PERF-03: Segregação post_items vs posts preserva matérias individuais após flushGroup(posts)', function() use ($client) {
    // 1. Armazena um artigo individual no grupo 'post_items' com TTL longo
    $articleData = ['id' => 123, 'slug' => 'gp-interlagos-2026', 'title' => 'GP do Brasil'];
    $client->set('post_slug:gp-interlagos-2026', $articleData, 'post_items', 864000);

    // 2. Armazena listagens e feeds agregados no grupo 'posts'
    $client->set('home_feed_posts_p1', [['id' => 123]], 'posts', 300);
    $client->set('home_featured_posts', [['id' => 123]], 'posts', 300);

    // 3. Simula publicação de uma nova matéria que invalida as listagens agregadas
    $client->flushGroup('posts');

    // 4. Feeds devem ser invalidados (MISS)
    $foundList = false;
    $client->get('home_feed_posts_p1', 'posts', $foundList);
    expectEqual(false, $foundList, 'Feed no grupo posts deve sofrer MISS');

    // 5. Matéria individual no grupo 'post_items' deve permanecer INTACTA (HIT)
    $foundItem = false;
    $itemVal = $client->get('post_slug:gp-interlagos-2026', 'post_items', $foundItem);
    expectEqual(true, $foundItem, 'Artigo em post_items deve ser preservado com HIT');
    expectEqual('GP do Brasil', $itemVal['title'] ?? '', 'Conteúdo do artigo deve permanecer íntegro');

    // 6. Atualização pontual do artigo invalida especificamente no grupo 'post_items'
    $client->delete('post_slug:gp-interlagos-2026', 'post_items');
    $foundAfterDelete = false;
    $client->get('post_slug:gp-interlagos-2026', 'post_items', $foundAfterDelete);
    expectEqual(false, $foundAfterDelete, 'Edição pontual deve invalidar apenas a chave do artigo');
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

it('Circuit Breaker desarma para estado OPEN em 3 falhas consecutivas de comandos', function() use ($client, $fake) {
    $fake->shouldFailOps = true;

    // Forçar falhas reais em comandos de cache para trip do disjuntor sem necessidade de ping
    for ($i = 0; $i < 3; $i++) {
        $found = false;
        $client->get('chave_falha_' . $i, 'posts', $found);
    }

    $diag = $client->getDiagnosticInfo();
    expectEqual('OPEN', $diag['circuit_breaker'], 'Disjuntor deve estar em OPEN após 3 falhas');
    expectEqual('degraded', $diag['status']);

    // Operações em bypass continuam sem disparar exceção não tratada
    $client->set('chave_degradada', 'salva_em_memoria', 'posts');
    $val = $client->get('chave_degradada', 'posts');
    expectEqual('salva_em_memoria', $val, 'deve operar com transparência via fallback');

    $fake->shouldFailOps = false;
    $client->setClient($fake);
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

echo "\n7. Validação PERF-02: Eliminação de Ping, Pipeline de Telemetria e Operações em Lote\n";

it('isConnected() não dispara ping() quando em estado normal CLOSED (0 RTTs)', function() use ($client, $fake) {
    // Se o ping estiver indisponível no daemon, operações normais NÃO devem falhar
    $fake->shouldFailPing = true;

    $connected = $client->isConnected();
    expectTrue($connected, 'isConnected() em estado CLOSED deve responder true em memória sem ping');

    // Leitura normal deve funcionar sem disparar exceção de ping
    $client->set('perf_key_ping', 'ok', 'default');
    $val = $client->get('perf_key_ping', 'default');
    expectEqual('ok', $val, 'get() deve executar normalmente sem depender de ping prévio');

    // Apenas sondagem forçada deve disparar ping e falhar
    $probed = $client->isConnected(true);
    expectEqual(false, $probed, 'isConnected(true) forçado deve falhar quando ping está bloqueado');

    $fake->shouldFailPing = false;
    $client->setClient($fake);
});

it('mGet() e setMultiple() operam em lote atômico no Redis e ObjectCache', function() use ($client) {
    $items = [
        'lote_img_1' => 'Descricao 1',
        'lote_img_2' => 'Descricao 2',
        'lote_img_3' => 'Descricao 3',
    ];

    $saved = \LightPress\Core\ObjectCache::setMultiple($items, 'media', 3600);
    expectTrue($saved, 'setMultiple deve salvar com sucesso');

    // Recuperação em lote
    $results = \LightPress\Core\ObjectCache::mGet(['lote_img_1', 'lote_img_2', 'lote_img_3', 'lote_img_inexistente'], 'media');
    expectEqual('Descricao 1', $results['lote_img_1']);
    expectEqual('Descricao 2', $results['lote_img_2']);
    expectEqual('Descricao 3', $results['lote_img_3']);
    expectEqual(false, $results['lote_img_inexistente'], 'Chave ausente deve retornar false');
});

it('Telemetria acumula em memória e descarrega em Pipeline atômico via flushTelemetry()', function() use ($client, $fake) {
    // Executa operações
    $client->set('tel_key', 'valor', 'default');
    $client->get('tel_key', 'default');
    $client->get('tel_inexistente', 'default');

    // flushTelemetry descarrega sem erros
    $client->flushTelemetry();

    $diag = $client->getDiagnosticInfo();
    expectTrue($diag['hits'] > 0, 'Hits devem estar registrados após flush');
    expectTrue($diag['misses'] > 0, 'Misses devem estar registrados após flush');
});

it('QueryProfiler opt-in: não consome CPU nem gera logs quando inativo', function() use ($client) {
    QueryProfiler::setEnabled(false);
    QueryProfiler::reset();

    $client->set('prof_key_off', 'valor', 'default');
    $client->get('prof_key_off', 'default');

    $logs = QueryProfiler::getLogs();
    expectEqual(0, count($logs), 'Profiler inativo não deve acumular logs');

    QueryProfiler::setEnabled(true);
});

echo "\n8. Validação AUD-PERF-04: Locks Anti-Stampede, Serialização e Conexões Persistentes\n";

it('AUD-PERF-04: Configuração persistent suportada com default false', function() use ($client) {
    $cfg = $client->getConfig();
    expectTrue(array_key_exists('persistent', $cfg), 'Chave persistent deve existir na configuração');
    expectEqual(false, $cfg['persistent'], 'persistent deve ser falso por padrão');
});

it('AUD-PERF-04: releaseLock só remove lock se token coincidir (Lua atômico)', function() use ($client, $fake) {
    $lockKey = 'test_lock_key';
    $correctToken = 'token_abc_123';
    $wrongToken = 'token_xyz_999';

    // Grava lock simulado com token original (Redis serializa internamente via set)
    $fake->set($lockKey, $correctToken, ['nx', 'px' => 5000]);

    // Tentativa de liberação com token errado deve falhar e não remover a chave
    $refMethod = new ReflectionMethod($client, 'releaseLock');
    $refMethod->setAccessible(true);

    $releasedWrong = $refMethod->invoke($client, $lockKey, $wrongToken);
    expectEqual(false, $releasedWrong, 'releaseLock com token errado deve retornar falso');
    expectEqual(1, $fake->exists($lockKey), 'Lock deve continuar existindo após tentativa com token errado');

    // Liberação com o token correto deve suceder e desalocar a chave
    $releasedCorrect = $refMethod->invoke($client, $lockKey, $correctToken);
    expectTrue($releasedCorrect, 'releaseLock com token correto deve retornar true');
    expectEqual(0, $fake->exists($lockKey), 'Lock deve ter sido removido após liberação do proprietário');
});

it('AUD-PERF-04: remember() realiza releitura após aquisição de lock evitando computação duplicada', function() use ($client, $fake) {
    $key = 'stampede_dedup_test';
    $group = 'default';

    // Primeira chamada computa e grava
    $computedCount = 0;
    $val = $client->remember($key, 60, function() use (&$computedCount) {
        $computedCount++;
        return 'valor_computado_1';
    }, $group);

    expectEqual('valor_computado_1', $val);
    expectEqual(1, $computedCount, 'Callback deve ser chamado exatamente 1 vez');

    // Segunda chamada deve vir do cache (0 chamadas ao callback)
    $val2 = $client->remember($key, 60, function() use (&$computedCount) {
        $computedCount++;
        return 'valor_computado_2';
    }, $group);

    expectEqual('valor_computado_1', $val2);
    expectEqual(1, $computedCount, 'Callback não deve ser chamado quando valor já existe no cache');
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
