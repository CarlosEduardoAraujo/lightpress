<?php
declare(strict_types=1);

/**
 * Worker CLI de Retentativa de Invalidação de Cache Nginx - LightPress
 * Execução recomendada via Cron a cada minuto:
 * * * * * * php /caminho/lightpress/bin/retry-cache-purge.php >> /dev/null 2>&1
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\CacheManager;
use LightPress\Core\Database;

// Bloqueio contra execuções sobrepostas via flock
$lockFile = $config['paths']['storage'] . '/cache_purge_worker.lock';
$fp = fopen($lockFile, 'c');
if (!$fp) {
    error_log("[retry-cache-purge] Falha ao abrir lock file: {$lockFile}");
    exit(1);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    // Outro processo worker já está em execução
    exit(0);
}

$cacheManager = new CacheManager($config);
$nowUtc = gmdate('Y-m-d H:i:s');

// Promover matérias agendadas vencidas
try {
    $promoted = \LightPress\Core\PostService::promoteScheduledPosts();
    if ($promoted > 0) {
        error_log("[retry-cache-purge] Sucesso: {$promoted} matéria(s) agendada(s) promovida(s) para 'published' e caches purgados.");
    }
} catch (\Throwable $e) {
    error_log("[retry-cache-purge] ERRO ao promover matérias agendadas: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
}

// Buscar até 20 jobs pendentes para reprocessamento
$jobs = Database::fetchAll(
    "SELECT id, post_id, payload_json, attempts 
     FROM cache_purge_jobs 
     WHERE status IN ('pending', 'processing') AND next_run_at <= ? 
     ORDER BY next_run_at ASC 
     LIMIT 20",
    [$nowUtc]
);

if (empty($jobs)) {
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(0);
}

foreach ($jobs as $job) {
    $jobId = (int)$job['id'];
    $attempts = (int)$job['attempts'] + 1;
    $payload = json_decode($job['payload_json'], true) ?? [];
    $urls = $payload['urls'] ?? [];

    if (empty($urls)) {
        Database::query(
            "UPDATE cache_purge_jobs SET status = 'completed', completed_at = ? WHERE id = ?",
            [$nowUtc, $jobId]
        );
        continue;
    }

    $results = $cacheManager->purgeUrls($urls);
    $allSucceeded = !in_array(false, $results, true);

    if ($allSucceeded) {
        Database::query(
            "UPDATE cache_purge_jobs 
             SET status = 'completed', attempts = ?, completed_at = ?, last_error = NULL 
             WHERE id = ?",
            [$attempts, $nowUtc, $jobId]
        );
    } else {
        // Backoff exponencial: tentativa 1 -> +1 min, tentativa 2 -> +5 min, tentativa 3 -> +15 min
        $backoffMinutes = match ($attempts) {
            1 => 1,
            2 => 5,
            default => 15,
        };

        $nextRun = gmdate('Y-m-d H:i:s', time() + ($backoffMinutes * 60));
        $status = ($attempts >= 4) ? 'failed' : 'pending';
        $errorMsg = "Falha ao purgar arquivos: " . json_encode(array_filter($results, fn($v) => !$v));

        Database::query(
            "UPDATE cache_purge_jobs 
             SET status = ?, attempts = ?, next_run_at = ?, last_error = ? 
             WHERE id = ?",
            [$status, $attempts, $nextRun, $errorMsg, $jobId]
        );
    }
}

flock($fp, LOCK_UN);
fclose($fp);
exit(0);
