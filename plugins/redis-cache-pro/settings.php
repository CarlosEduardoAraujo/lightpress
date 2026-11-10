<?php
declare(strict_types=1);

/**
 * Painel Administrativo e Roteador AJAX - Redis Cache Pro
 */

use LightPress\Core\AdminNav;
use LightPress\Core\Security;
use LightPress\Core\Settings;
use LightPress\Plugin\RedisCachePro\RedisClient;
use LightPress\Plugin\RedisCachePro\CacheMaintenance;
use LightPress\Plugin\RedisCachePro\WarmupService;
use LightPress\Plugin\RedisCachePro\AlertService;
use LightPress\Plugin\RedisCachePro\QueryProfiler;

// Instanciar cliente e serviços
$client = RedisClient::getInstance();
$maintenance = new CacheMaintenance($client);
$warmup = new WarmupService($client);

// 1. Processamento de Requisições AJAX (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['_csrf_token'] ?? '';
    if (!Security::validateCsrfToken($submittedToken)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido ou expirado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    switch ($action) {
        case 'flush_cache':
            $res = $client->flush();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $res,
                'message' => $res ? 'Cache global invalidado com sucesso em O(1)!' : 'Falha ao invalidar cache global.',
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'purge_group':
            $group = trim((string)($_POST['group'] ?? ''));
            if ($group === '') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Grupo não informado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $res = $client->flushGroup($group);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $res,
                'message' => $res ? "Grupo '{$group}' invalidado com sucesso!" : "Falha ao purgar grupo '{$group}'.",
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'run_warmup':
            $savedRaw = Settings::get('redis_cache_pro_settings', '[]');
            $savedSettings = json_decode($savedRaw, true) ?: [];
            $stats = $warmup->run($savedSettings);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Pré-aquecimento executado com sucesso!',
                'stats'   => $stats,
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'scan_big_keys':
            $items = $maintenance->scanBigKeys(15, 1000);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'items'   => $items,
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'delete_single_key':
            $key = trim((string)($_POST['key'] ?? ''));
            $res = $maintenance->deleteSingleKey($key);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $res,
                'message' => $res ? 'Chave desalocada via UNLINK com sucesso.' : 'Falha ao remover chave.',
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'test_webhook':
            $webhookUrl = trim((string)($_POST['webhook_url'] ?? ''));
            $alertEmail = trim((string)($_POST['alert_email'] ?? ''));
            $testRes = AlertService::sendTestNotification($webhookUrl, $alertEmail);
            header('Content-Type: application/json');
            echo json_encode($testRes, JSON_UNESCAPED_UNICODE);
            exit;

        case 'save_settings':
            $currentRaw = Settings::get('redis_cache_pro_settings', '[]');
            $currentSettings = json_decode($currentRaw, true) ?: [];

            // Campos da tela
            $fields = [
                'host', 'port', 'password', 'database', 'split_transients_db',
                'timeout', 'prefix', 'maxttl', 'stampede_protection', 'serializer',
                'compression', 'warmup_posts', 'warmup_categories', 'warmup_custom_urls',
                'webhook_url', 'alert_email', 'memory_threshold', 'notify_circuit_breaker',
                'notify_memory_spike', 'enabled'
            ];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $val = $_POST[$field];
                    if (in_array($field, ['port', 'database', 'warmup_posts', 'memory_threshold'], true)) {
                        $val = (int)$val;
                    } elseif (in_array($field, ['stampede_protection', 'warmup_categories', 'notify_circuit_breaker', 'notify_memory_spike', 'enabled'], true)) {
                        $val = !empty($val);
                    } elseif ($field === 'timeout') {
                        $val = (float)$val;
                    } elseif ($field === 'split_transients_db') {
                        $val = $val !== '' ? (int)$val : null;
                    }
                    $currentSettings[$field] = $val;
                }
            }

            Settings::set('redis_cache_pro_settings', json_encode($currentSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Configurações atualizadas com sucesso!'], JSON_UNESCAPED_UNICODE);
            exit;
    }
}

// 2. Renderização da Interface Administrativa
$diagnostics = $client->getDiagnosticInfo();
$historyData = $client->get24HourTelemetry();
$commandLogs = QueryProfiler::getLogs();
$settings = $diagnostics['config'] ?? [];

$pageTitle = 'Redis Cache Pro · LightPress';
$activePage = 'plugin-settings';

$pluginCss = file_exists(__DIR__ . '/assets/css/admin.css') ? (string)file_get_contents(__DIR__ . '/assets/css/admin.css') : '';
$extraHead = '<link rel="stylesheet" href="/assets/plugins/redis-cache-pro/css/admin.css">';
if ($pluginCss !== '') {
    $extraHead .= "\n    <style>\n" . $pluginCss . "\n    </style>";
}

require dirname(__DIR__, 2) . '/public/admin/header.php';
?>

<div class="container" style="max-width: var(--admin-max-width, 1900px);">
<div class="rcp-wrap" style="margin-top: 0; max-width: 100%;">
    <!-- Header do Painel -->
    <header class="rcp-header">
        <div class="rcp-header-brand">
            <div class="rcp-logo-badge">⚡</div>
            <div>
                <h1 class="rcp-title">Redis Cache Pro <span class="rcp-edition-tag">Ultra Edition</span></h1>
                <p class="rcp-subtitle">Motor Empresarial de Cache Persistente em Memória RAM com UNLINK e Proteção Dogpile</p>
            </div>
        </div>

        <div class="rcp-header-actions">
            <button id="rcp-btn-warmup" class="rcp-btn rcp-btn-secondary" type="button" title="Pré-aquecer opções, posts e páginas">
                <span class="rcp-icon">🔥</span> Pré-aquecer (Warmup)
            </button>
            <button id="rcp-btn-flush" class="rcp-btn rcp-btn-primary" type="button">
                <span class="rcp-icon">⚡</span> Limpar Cache Completo
            </button>
        </div>
    </header>

    <!-- Navegação de Abas -->
    <nav class="rcp-tabs">
        <button class="rcp-tab-btn is-active" data-tab="tab-overview" type="button">Visão Geral & Telemetria 24h</button>
        <button class="rcp-tab-btn" data-tab="tab-metrics" type="button">Comandos em Tempo Real</button>
        <button class="rcp-tab-btn" data-tab="tab-config" type="button">Configuração & Pre-warming</button>
        <button class="rcp-tab-btn" data-tab="tab-groups" type="button">Grupos & Big Key Hunter</button>
        <button class="rcp-tab-btn" data-tab="tab-alerts" type="button">Alertas & Incidentes</button>
    </nav>

    <!-- ABA 1: Visão Geral & Telemetria -->
    <div id="tab-overview" class="rcp-tab-content is-active">
        <div class="rcp-grid rcp-grid-4">
            <!-- Radial Gauge Card -->
            <div class="rcp-card rcp-card-gauge">
                <div class="rcp-card-header">
                    <span class="rcp-card-title">Hit Ratio (Taxa de Acerto)</span>
                    <span class="rcp-badge rcp-badge-success">Persistente</span>
                </div>
                <div class="rcp-gauge-wrapper">
                    <svg class="rcp-radial-gauge" viewBox="0 0 120 120">
                        <circle class="rcp-gauge-bg" cx="60" cy="60" r="50"></circle>
                        <circle class="rcp-gauge-fill" cx="60" cy="60" r="50"
                                stroke-dasharray="314.15"
                                stroke-dashoffset="<?= Security::e((string)(314.15 - (314.15 * ($diagnostics['hit_ratio'] / 100)))) ?>"></circle>
                    </svg>
                    <div class="rcp-gauge-text">
                        <span class="rcp-gauge-value"><?= Security::e((string)$diagnostics['hit_ratio']) ?>%</span>
                        <span class="rcp-gauge-label">Cache Hits</span>
                    </div>
                </div>
                <div class="rcp-card-footer">
                    <span><strong>Hits:</strong> <?= number_format($diagnostics['hits']) ?></span>
                    <span><strong>Misses:</strong> <?= number_format($diagnostics['misses']) ?></span>
                </div>
            </div>

            <!-- Status de Conexão Card -->
            <div class="rcp-card">
                <div class="rcp-card-header">
                    <span class="rcp-card-title">Status da Conexão</span>
                    <?php if ($diagnostics['status'] === 'connected'): ?>
                        <span class="rcp-badge rcp-badge-success"><span class="rcp-dot pulse"></span> Conectado</span>
                    <?php elseif ($diagnostics['status'] === 'degraded'): ?>
                        <span class="rcp-badge rcp-badge-warning"><span class="rcp-dot"></span> Degradado (Bypass)</span>
                    <?php else: ?>
                        <span class="rcp-badge rcp-badge-danger"><span class="rcp-dot"></span> Desconectado</span>
                    <?php endif; ?>
                </div>
                <div class="rcp-stat-large">
                    <?= $diagnostics['status'] === 'connected' ? 'Operacional' : 'Modo Seguro' ?>
                </div>
                <p class="rcp-card-desc">
                    Circuit Breaker: <strong><?= Security::e($diagnostics['circuit_breaker']) ?></strong>
                </p>
                <div class="rcp-card-footer">
                    <span>Dogpile Lock: <strong><?= $diagnostics['stampede_active'] ? 'Ativo (NX Lock)' : 'Inativo' ?></strong></span>
                    <span>Split DB: <strong><?= $diagnostics['split_db_active'] ? 'Ativo' : 'Desativado' ?></strong></span>
                </div>
            </div>

            <!-- Latência Média Card -->
            <div class="rcp-card">
                <div class="rcp-card-header">
                    <span class="rcp-card-title">Latência Média</span>
                    <span class="rcp-badge rcp-badge-info">Tempo RTT</span>
                </div>
                <div class="rcp-stat-large">
                    <span><?= Security::e((string)$diagnostics['avg_latency_ms']) ?></span> <span class="rcp-unit">ms</span>
                </div>
                <p class="rcp-card-desc">Tempo de resposta entre o motor PHP e o daemon Redis.</p>
                <div class="rcp-card-footer">
                    <span>Comandos nesta requisição: <strong><?= (int)$diagnostics['commands_count'] ?></strong></span>
                </div>
            </div>

            <!-- Consumo de Memória Card -->
            <div class="rcp-card">
                <div class="rcp-card-header">
                    <span class="rcp-card-title">Consumo de Memória</span>
                    <span class="rcp-badge rcp-badge-purple">RAM</span>
                </div>
                <div class="rcp-stat-large">
                    <span><?= Security::e((string)$diagnostics['used_memory']) ?></span>
                </div>
                <p class="rcp-card-desc">Pico registrado: <strong><?= Security::e((string)$diagnostics['peak_memory']) ?></strong></p>
                <div class="rcp-card-footer">
                    <span>Redis Server: <strong>v<?= Security::e((string)$diagnostics['redis_version']) ?></strong></span>
                    <span>Limite: <strong><?= Security::e((string)$diagnostics['max_memory']) ?></strong></span>
                </div>
            </div>
        </div>

        <!-- Gráfico de 24 Horas -->
        <div class="rcp-card" style="margin-bottom: 24px;">
            <div class="rcp-card-header">
                <div>
                    <span class="rcp-card-title">Histórico de Performance das Últimas 24 Horas</span>
                    <p style="margin:4px 0 0;font-size:12px;color:var(--rcp-text-muted);">Taxa de acertos (%), volume de comandos e latência média em tempo real.</p>
                </div>
                <span class="rcp-badge rcp-badge-success">Métricas em Tempo Real</span>
            </div>
            <div class="rcp-chart-container" style="width: 100%; overflow: hidden;">
                <div id="rcp-history-chart" class="rcp-chart-wrapper" style="min-height: 200px; width: 100%; display: flex; flex-direction: column;">
                    <div style="color:var(--rcp-text-muted); padding: 40px; text-align: center;">Carregando métricas temporais...</div>
                </div>
            </div>
        </div>

        <!-- Destaques Arquiteturais -->
        <div class="rcp-features-grid">
            <div class="rcp-feature-box">
                <div class="rcp-feature-icon">🛡️</div>
                <div>
                    <h4>Proteção contra Cache Stampede (Dogpile Lock)</h4>
                    <p>Usa locks atômicos <code>SET NX PX 5000</code> para impedir que requisições concorrentes sobrecarreguem o banco de dados regenerando a mesma chave simultaneamente.</p>
                </div>
            </div>

            <div class="rcp-feature-box">
                <div class="rcp-feature-icon">🚀</div>
                <div>
                    <h4>Invalidação Atômica O(1) por Namespace</h4>
                    <p>Ao limpar o cache completo, o motor apenas atualiza o token de versão global da aplicação. Gerações anteriores tornam-se inacessíveis instantaneamente sem uso do bloqueante <code>FLUSHDB</code>.</p>
                </div>
            </div>

            <div class="rcp-feature-box">
                <div class="rcp-feature-icon">🔥</div>
                <div>
                    <h4>Pré-aquecimento Automático (Pre-warming)</h4>
                    <p>Recarrega configurações globais, matérias recentes e categorias críticas diretamente no Redis para eliminar cold-cache e manter o tempo de resposta mínimo.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ABA 2: Comandos em Tempo Real -->
    <div id="tab-metrics" class="rcp-tab-content">
        <div class="rcp-card">
            <div class="rcp-card-header">
                <span class="rcp-card-title">Rastreamento de Comandos Executados (Query Profiler)</span>
                <span class="rcp-badge rcp-badge-info">Últimas 30 operações</span>
            </div>
            <div class="rcp-table-responsive">
                <table class="rcp-table">
                    <thead>
                        <tr>
                            <th>Comando</th>
                            <th>Chave / Argumento</th>
                            <th>Origem (Caller)</th>
                            <th>Duração (ms)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($commandLogs)): ?>
                            <?php foreach ($commandLogs as $cmd): ?>
                                <tr>
                                    <td><span class="rcp-code-tag"><?= Security::e($cmd['command']) ?></span></td>
                                    <td><code class="rcp-code-key"><?= Security::e($cmd['key']) ?></code></td>
                                    <td><span style="font-size:11px;color:var(--rcp-text-muted);"><?= Security::e($cmd['caller']) ?></span></td>
                                    <td><strong><?= Security::e((string)$cmd['duration']) ?> ms</strong></td>
                                    <td><span class="rcp-badge rcp-badge-success"><?= Security::e($cmd['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center;padding:24px;color:var(--rcp-text-muted);">
                                    Nenhum comando registrado nesta requisição ou cache operando em bypass.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ABA 3: Configuração & Pre-warming -->
    <div id="tab-config" class="rcp-tab-content">
        <div class="rcp-grid rcp-grid-2">
            <!-- Tabela de Diagnóstico do Ambiente -->
            <div class="rcp-card">
                <div class="rcp-card-header">
                    <span class="rcp-card-title">Diagnóstico do Servidor & Drivers</span>
                </div>
                <table class="rcp-table rcp-table-striped">
                    <tbody>
                        <tr>
                            <td><strong>Extensão PhpRedis (C-Driver)</strong></td>
                            <td>
                                <?php if ($diagnostics['phpredis_loaded']): ?>
                                    <span class="rcp-badge rcp-badge-success">Instalada (v<?= Security::e($diagnostics['phpredis_version']) ?>)</span>
                                <?php else: ?>
                                    <span class="rcp-badge rcp-badge-danger">Ausente (Requer php-redis)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Suporte Zstandard (zstd)</strong></td>
                            <td>
                                <?= $diagnostics['zstd_support'] ? '<span class="rcp-badge rcp-badge-success">Disponível</span>' : '<span class="rcp-badge rcp-badge-warning">Não compilada</span>' ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Suporte LZ4</strong></td>
                            <td>
                                <?= $diagnostics['lz4_support'] ? '<span class="rcp-badge rcp-badge-success">Disponível</span>' : '<span class="rcp-badge rcp-badge-warning">Não compilada</span>' ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Serializador Igbinary</strong></td>
                            <td>
                                <?= $diagnostics['igbinary_support'] ? '<span class="rcp-badge rcp-badge-success">Disponível</span>' : '<span class="rcp-badge rcp-badge-warning">Padrão PHP</span>' ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Proteção Dogpile Stampede</strong></td>
                            <td>
                                <span class="rcp-badge rcp-badge-success">Ativa (SET NX)</span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Total de Chaves Estimadas</strong></td>
                            <td>
                                <strong><?= number_format($diagnostics['keys_count']) ?></strong> chaves
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Formulário de Parâmetros de Conexão e Warmup -->
            <div class="rcp-card">
                <div class="rcp-card-header">
                    <span class="rcp-card-title">Configurações de Conexão & Pre-warming</span>
                </div>
                <form id="rcp-settings-form">
                    <div class="rcp-form-group">
                        <label class="rcp-label">Servidor Redis (Host / Socket)</label>
                        <input type="text" name="host" class="rcp-input" value="<?= Security::e((string)($settings['host'] ?? '127.0.0.1')) ?>" required>
                        <span class="rcp-help">Ex: 127.0.0.1 ou socket /var/run/redis/redis.sock</span>
                    </div>

                    <div style="display:flex;gap:1rem;">
                        <div class="rcp-form-group" style="flex:1;">
                            <label class="rcp-label">Porta</label>
                            <input type="number" name="port" class="rcp-input" value="<?= Security::e((string)($settings['port'] ?? 6379)) ?>" required>
                        </div>
                        <div class="rcp-form-group" style="flex:1;">
                            <label class="rcp-label">Database Index</label>
                            <input type="number" name="database" class="rcp-input" min="0" max="15" value="<?= Security::e((string)($settings['database'] ?? 0)) ?>" required>
                        </div>
                    </div>

                    <div class="rcp-form-group">
                        <label class="rcp-label">Senha de Autenticação (AUTH)</label>
                        <input type="password" name="password" class="rcp-input" value="<?= Security::e((string)($settings['password'] ?? '')) ?>" placeholder="Deixe em branco se não houver senha">
                    </div>

                    <div class="rcp-form-group">
                        <label class="rcp-label">Prefixo de Chave (Namespace)</label>
                        <input type="text" name="prefix" class="rcp-input" value="<?= Security::e((string)($settings['prefix'] ?? 'lp_cache:')) ?>" required>
                    </div>

                    <div class="rcp-form-group">
                        <label class="rcp-label">Posts Recentes para Pré-aquecer</label>
                        <input type="number" name="warmup_posts" class="rcp-input" min="0" max="50" value="<?= Security::e((string)($settings['warmup_posts'] ?? 10)) ?>">
                    </div>

                    <div class="rcp-form-group">
                        <label class="rcp-checkbox-label">
                            <input type="checkbox" name="warmup_categories" value="1" <?= !empty($settings['warmup_categories']) ? 'checked' : '' ?>>
                            Pré-aquecer Categorias e Taxonomias
                        </label>
                    </div>

                    <div class="rcp-form-group">
                        <label class="rcp-label">URLs Adicionais para Pré-aquecer (uma por linha)</label>
                        <textarea name="warmup_custom_urls" class="rcp-textarea" rows="2" placeholder="https://seusite.com/categoria/noticias/"><?= Security::e((string)($settings['warmup_custom_urls'] ?? '')) ?></textarea>
                    </div>

                    <button type="submit" class="rcp-btn rcp-btn-primary">Salvar Configurações</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ABA 4: Grupos & Big Key Hunter -->
    <div id="tab-groups" class="rcp-tab-content">
        <!-- Big Key Hunter Box -->
        <div class="rcp-card" style="margin-bottom: 24px;">
            <div class="rcp-card-header">
                <div>
                    <span class="rcp-card-title">Scanner de Chaves Gigantes (Big Key Hunter)</span>
                    <p style="margin:4px 0 0;font-size:12px;color:var(--rcp-text-muted);">Localize os 15 maiores objetos armazenados na memória RAM usando cursor não-bloqueante <code>SCAN</code></p>
                </div>
                <button id="rcp-btn-scan-big-keys" class="rcp-btn rcp-btn-secondary" type="button">
                    🔍 Escanear Maiores Chaves
                </button>
            </div>
            <div class="rcp-table-responsive">
                <table class="rcp-table">
                    <thead>
                        <tr>
                            <th>Chave no Redis</th>
                            <th>Tipo</th>
                            <th>Tamanho em RAM</th>
                            <th>TTL Restante</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody id="rcp-big-keys-tbody">
                        <tr>
                            <td colspan="5" style="text-align:center;padding:24px;color:var(--rcp-text-muted);">
                                Clique em <strong>"Escanear Maiores Chaves"</strong> para iniciar a análise em tempo real.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Purga Granular de Grupos -->
        <div class="rcp-card">
            <div class="rcp-card-header">
                <span class="rcp-card-title">Limpeza Granular de Grupos de Cache</span>
                <span class="rcp-badge rcp-badge-info">Invalidação Atômica O(1)</span>
            </div>
            <p style="margin-bottom:15px;color:var(--rcp-text-muted);font-size:0.85rem;">
                Invalide apenas os objetos de um grupo de dados específico sem afetar os outros grupos ou a aplicação global.
            </p>
            <div class="rcp-groups-grid">
                <?php
                $groups = [
                    'settings'   => 'Configurações Globais do LightPress',
                    'posts'      => 'Matérias, Notícias e Slugs',
                    'categories' => 'Categorias e Seções Editoriais',
                    'users'      => 'Sessões e Perfis de Autores',
                    'transient'  => 'Consultas Externas e Transientes Voláteis',
                ];
                foreach ($groups as $gKey => $gLabel):
                ?>
                    <div class="rcp-group-item">
                        <div>
                            <strong><?= Security::e($gKey) ?></strong>
                            <div class="rcp-group-desc"><?= Security::e($gLabel) ?></div>
                        </div>
                        <button class="rcp-btn rcp-btn-secondary rcp-btn-purge-group" data-group="<?= Security::e($gKey) ?>" type="button">
                            Purgar Grupo
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ABA 5: Alertas & Incidentes -->
    <div id="tab-alerts" class="rcp-tab-content">
        <div class="rcp-grid rcp-grid-2">
            <!-- Disjuntor Card -->
            <div class="rcp-card">
                <div class="rcp-card-header">
                    <span class="rcp-card-title">Resiliência & Circuit Breaker</span>
                </div>
                <div style="background:#f8fafc;padding:1rem;border-radius:8px;border:1px solid var(--rcp-border);margin-bottom:1rem;">
                    <div style="font-size:0.85rem;color:var(--rcp-text-muted);">Estado Atual do Disjuntor</div>
                    <div style="font-size:1.5rem;font-weight:800;color:<?= $diagnostics['circuit_breaker'] === 'CLOSED' ? '#10b981' : '#ef4444' ?>;">
                        <?= Security::e($diagnostics['circuit_breaker']) ?>
                    </div>
                    <p style="font-size:0.8rem;color:var(--rcp-text-muted);margin:0.4rem 0 0;">
                        CLOSED = Operação normal. OPEN = Modo de proteção ativo (failover transparente no MySQL).
                    </p>
                </div>
                <p style="font-size:0.82rem;color:var(--rcp-text-muted);line-height:1.5;">
                    O Circuit Breaker monitora exceções de timeout e recusa de conexão. Se o Redis parar inesperadamente, ele desarma em 3 falhas consecutivas e isola o Redis por 30 segundos, permitindo que o LightPress continue respondendo sem travar.
                </p>
            </div>

            <!-- Canais de Notificação Form -->
            <div class="rcp-card">
                <div class="rcp-card-header">
                    <span class="rcp-card-title">Canais de Notificação de Incidentes</span>
                </div>
                <form id="rcp-alerts-form">
                    <div class="rcp-form-group">
                        <label class="rcp-label">URL do Webhook (Discord / Slack / Teams / n8n)</label>
                        <input type="url" name="webhook_url" class="rcp-input" placeholder="https://discord.com/api/webhooks/..." value="<?= Security::e((string)($settings['webhook_url'] ?? '')) ?>">
                        <span class="rcp-help">Dispara mensagens ricas com embeds em caso de queda do Redis ou pico de memória.</span>
                    </div>

                    <div class="rcp-form-group">
                        <label class="rcp-label">E-mail para Alertas de Emergência</label>
                        <input type="email" name="alert_email" class="rcp-input" placeholder="admin@seusite.com" value="<?= Security::e((string)($settings['alert_email'] ?? '')) ?>">
                        <span class="rcp-help">Receberá avisos se o disjuntor for ativado.</span>
                    </div>

                    <div class="rcp-form-group">
                        <label class="rcp-label">Limite para Alerta de Consumo de RAM (% de Maxmemory)</label>
                        <input type="number" name="memory_threshold" class="rcp-input" min="50" max="99" value="<?= Security::e((string)($settings['memory_threshold'] ?? 90)) ?>">
                    </div>

                    <div class="rcp-form-group">
                        <label class="rcp-checkbox-label">
                            <input type="checkbox" name="notify_circuit_breaker" value="1" <?= !empty($settings['notify_circuit_breaker']) ? 'checked' : '' ?>>
                            Notificar imediatamente se o Circuit Breaker for acionado
                        </label>
                    </div>

                    <div class="rcp-form-group">
                        <label class="rcp-checkbox-label">
                            <input type="checkbox" name="notify_memory_spike" value="1" <?= !empty($settings['notify_memory_spike']) ? 'checked' : '' ?>>
                            Notificar em caso de pico de memória RAM
                        </label>
                    </div>

                    <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                        <button type="submit" class="rcp-btn rcp-btn-primary">Salvar Alertas</button>
                        <button id="rcp-btn-test-webhook" type="button" class="rcp-btn rcp-btn-secondary">🔔 Enviar Teste de Notificação</button>
                    </div>
                    <div id="rcp-alerts-feedback" style="margin-top:10px;font-size:12px;display:none;"></div>
                </form>
            </div>
        </div>
    </div>
</div>
</div>

<script>
    window.rcpCsrfToken = "<?= Security::e($csrfToken) ?>";
    window.rcpHistoryData = <?= json_encode($historyData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/plugins/redis-cache-pro/js/admin.js"></script>
<?php
$pluginJs = file_exists(__DIR__ . '/assets/js/admin.js') ? (string)file_get_contents(__DIR__ . '/assets/js/admin.js') : '';
if ($pluginJs !== ''): ?>
<script>
if (!window.rcpScriptLoaded) {
    window.rcpScriptLoaded = true;
    <?= $pluginJs ?>
}
</script>
<?php endif; ?>

<?php require dirname(__DIR__, 2) . '/public/admin/footer.php'; ?>
