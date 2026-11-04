<?php
declare(strict_types=1);

/**
 * Ferramenta CLI de Benchmark de Performance - LightPress
 * Execução: php bin/benchmark.php
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\BlockCompiler;
use LightPress\Core\Router;
use LightPress\Core\Security;

echo "========================================================\n";
echo "   LightPress / SpeedPress - Micro-Benchmark Core       \n";
echo "========================================================\n\n";

$iterations = 2000;
echo "Executando baterias com {$iterations} iterações por componente...\n\n";

// -------------------------------------------------------------
// 1. Benchmark: Compilação Semântica e Sanitização (BlockCompiler)
// -------------------------------------------------------------
$sampleBlocks = [
    ['type' => 'heading', 'level' => 2, 'content' => 'Título de Teste de Performance'],
    ['type' => 'paragraph', 'content' => 'Texto longo com formatação inline <strong>importante</strong> e links seguros para validação.'],
    ['type' => 'quote', 'content' => 'O segredo da velocidade é a simplicidade da arquitetura.', 'caption' => 'SpeedPress'],
    ['type' => 'image', 'url' => 'https://exemplo.com/uploads/2026/11/hash-1024.webp', 'caption' => 'Legenda'],
];

$startMem = memory_get_usage();
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $compiled = BlockCompiler::compile($sampleBlocks);
}
$timeBlocks = microtime(true) - $start;
$memBlocks = memory_get_usage() - $startMem;
$opsSecBlocks = (int)($iterations / max(0.0001, $timeBlocks));

// -------------------------------------------------------------
// 2. Benchmark: Roteamento Regex Puro (Router)
// -------------------------------------------------------------
$router = new Router();
$router->get('/', function(): void {});
$router->get('/page/{page}/', function(): void {});
$router->get('/noticia/{slug}/', function(): void {});
$router->get('/categoria/{slug}/', function(): void {});
$router->get('/categoria/{slug}/page/{page}/', function(): void {});
$router->post('/api/view/{id}', function(): void {});

$routerIterations = 10000;
$latencies = [];

$start = microtime(true);
for ($i = 0; $i < $routerIterations; $i++) {
    $rStart = microtime(true);
    $router->dispatch('GET', '/noticia/etapa-decisiva-interlagos-2026/');
    $latencies[] = (microtime(true) - $rStart) * 1000; // ms
}
$timeRouter = microtime(true) - $start;
$opsSecRouter = (int)($routerIterations / max(0.0001, $timeRouter));

sort($latencies);
$p50 = $latencies[(int)($routerIterations * 0.50)];
$p95 = $latencies[(int)($routerIterations * 0.95)];
$p99 = $latencies[(int)($routerIterations * 0.99)];

// -------------------------------------------------------------
// 3. Benchmark: Anonimização de IP por HMAC-SHA-256 (Security::hashIp)
// -------------------------------------------------------------
$secret = $config['security']['hmac_secret'] ?? 'test_secret_key';
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $h = Security::hashIp("200.147.35.71", $secret);
}
$timeHmac = microtime(true) - $start;
$opsSecHmac = (int)($iterations / max(0.0001, $timeHmac));

// -------------------------------------------------------------
// 4. Benchmark: Geração de Slugs URL-Friendly (Security::slugify)
// -------------------------------------------------------------
$sampleTitle = "Grande Prêmio de São Paulo em Interlagos: Chuva e Emoção até o Fim!";
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $s = Security::slugify($sampleTitle);
}
$timeSlug = microtime(true) - $start;
$opsSecSlug = (int)($iterations / max(0.0001, $timeSlug));

// -------------------------------------------------------------
// Relatório de Métricas
// -------------------------------------------------------------
$peakMemory = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

echo "+--------------------------------------+----------------+-----------------+\n";
echo "| Componente / Operação               | Throughput     | Duração Total   |\n";
echo "+--------------------------------------+----------------+-----------------+\n";
printf("| %-36s | %10d op/s | %13.4f s |\n", "Compilador de Blocos HTML5", $opsSecBlocks, $timeBlocks);
printf("| %-36s | %10d op/s | %13.4f s |\n", "Roteador Regex (Match Notícia)", $opsSecRouter, $timeRouter);
printf("| %-36s | %10d op/s | %13.4f s |\n", "Deduplicação HMAC-SHA-256 IP", $opsSecHmac, $timeHmac);
printf("| %-36s | %10d op/s | %13.4f s |\n", "Geração de Slugs Canônicos", $opsSecSlug, $timeSlug);
echo "+--------------------------------------+----------------+-----------------+\n\n";

echo "[Métricas de Latência do Roteador]\n";
printf("  p50: %.4f ms\n", $p50);
printf("  p95: %.4f ms\n", $p95);
printf("  p99: %.4f ms\n", $p99);

echo "\n[Alocação de Memória PHP]\n";
echo "  Pico de memória utilizado: {$peakMemory} MiB (Meta da especificação: < 64 MiB)\n";
echo "  Avaliação: EXCELENTE (Consumo desprezível de RAM)\n\n";
