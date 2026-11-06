<?php
declare(strict_types=1);

/**
 * Rotina CLI de Limpeza e Manutenção Periódica - LightPress
 * Execução recomendada via Cron diariamente:
 * 0 3 * * * php /caminho/lightpress/bin/cleanup.php >> /dev/null 2>&1
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;

$nowUtc = gmdate('Y-m-d H:i:s');
echo "[LightPress Cleanup] Iniciando rotina de limpeza em {$nowUtc} (UTC)...\n";

// 1. Limpeza de registros expirados de deduplicação de visualizações
try {
    $stmt = Database::query("DELETE FROM view_dedupe WHERE expires_at < ?", [$nowUtc]);
    $deletedDedupe = $stmt->rowCount();
    echo "  - Registros de view_dedupe expirados removidos: {$deletedDedupe}\n";
} catch (\Throwable $e) {
    echo "  [ERRO] Falha ao limpar view_dedupe: " . $e->getMessage() . "\n";
}

// 2. Limpeza de tokens de ativação e redefinição expirados
try {
    $stmt = Database::query("DELETE FROM user_reset_tokens WHERE expires_at < ? OR used_at IS NOT NULL", [$nowUtc]);
    $deletedTokens = $stmt->rowCount();
    echo "  - Tokens de redefinição expirados/usados removidos: {$deletedTokens}\n";
} catch (\Throwable $e) {
    echo "  [ERRO] Falha ao limpar tokens: " . $e->getMessage() . "\n";
}

// 3. Limpeza de arquivos temporários no diretório de staging mais antigos que 2 horas
$stagingDir = $config['media']['staging_dir'] ?? dirname(__DIR__) . '/storage/staging';
$staleTime = time() - (2 * 3600);
$deletedFiles = 0;

if (is_dir($stagingDir)) {
    $files = scandir($stagingDir);
    if ($files) {
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.gitkeep') {
                continue;
            }
            $filePath = $stagingDir . '/' . $file;
            if (is_file($filePath) && filemtime($filePath) < $staleTime) {
                if (@unlink($filePath)) {
                    $deletedFiles++;
                }
            }
        }
    }
}
echo "  - Arquivos temporários residuais de staging removidos: {$deletedFiles}\n";

// 4. Promoção automática de artigos agendados cujo horário já foi atingido
try {
    $promoted = \LightPress\Core\PostService::promoteScheduledPosts();

    echo "  - Artigos agendados promovidos para publicação: {$promoted}\n";
} catch (\Throwable $e) {
    echo "  [ERRO] Falha ao promover artigos agendados: " . $e->getMessage() . "\n";
}

// 5. Limpeza de arquivos de rate limit de login expirados
$logDir = $config['paths']['storage'] . '/logs';
$deletedRateLimits = 0;
if (is_dir($logDir)) {
    foreach (glob($logDir . '/ratelimit_login_*.json') as $rlFile) {
        if (filemtime($rlFile) < (time() - 3600)) {
            if (@unlink($rlFile)) {
                $deletedRateLimits++;
            }
        }
    }
}
echo "  - Arquivos de rate-limit expirados removidos: {$deletedRateLimits}\n";

echo "[LightPress Cleanup] Concluído com sucesso.\n";
