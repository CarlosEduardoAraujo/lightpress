<?php
declare(strict_types=1);

/**
 * Tela de Configurações - AI Copilot
 */

use LightPress\Core\Database;
use LightPress\Core\Security;
use LightPress\Core\Settings;
use LightPress\Plugin\AiCopilot\AiClient;

if (!class_exists('LightPress\\Plugin\\AiCopilot\\AiClient', false)) {
    require_once __DIR__ . '/ai-copilot.php';
}

$message = null;
$error = null;

// Salvar Configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['_csrf_token'] ?? '';
    if (!Security::validateCsrfToken($submittedToken)) {
        $error = 'Token de segurança CSRF inválido ou expirado.';
    } elseif (isset($_POST['save_settings'])) {
        $provider = trim((string)($_POST['provider'] ?? 'gemini'));
        if (!in_array($provider, ['gemini', 'openai', 'custom'], true)) {
            $provider = 'gemini';
        }
        Settings::set('ai_copilot_provider', $provider);

        // Gemini Key
        if (!AiClient::isKeyFromConfig('gemini')) {
            $rawGemini = trim((string)($_POST['gemini_key'] ?? ''));
            if ($rawGemini !== '' && !str_contains($rawGemini, '••••')) {
                Settings::set('ai_copilot_gemini_key', $rawGemini);
            } elseif ($rawGemini === '') {
                Settings::set('ai_copilot_gemini_key', '');
            }
        }

        // OpenAI Key
        if (!AiClient::isKeyFromConfig('openai')) {
            $rawOpenai = trim((string)($_POST['openai_key'] ?? ''));
            if ($rawOpenai !== '' && !str_contains($rawOpenai, '••••')) {
                Settings::set('ai_copilot_openai_key', $rawOpenai);
            } elseif ($rawOpenai === '') {
                Settings::set('ai_copilot_openai_key', '');
            }
        }

        // Custom Endpoint & Key
        if (!AiClient::isKeyFromConfig('custom')) {
            $rawCustom = trim((string)($_POST['custom_key'] ?? ''));
            if ($rawCustom !== '' && !str_contains($rawCustom, '••••')) {
                Settings::set('ai_copilot_custom_key', $rawCustom);
            } elseif ($rawCustom === '') {
                Settings::set('ai_copilot_custom_key', '');
            }
        }
        $customEndpoint = trim((string)($_POST['custom_endpoint'] ?? ''));
        Settings::set('ai_copilot_custom_endpoint', $customEndpoint);

        // Modelos
        $model = trim((string)($_POST['model'] ?? ''));
        Settings::set('ai_copilot_model', $model);

        $linkingModel = trim((string)($_POST['linking_model'] ?? ''));
        Settings::set('ai_copilot_linking_model', $linkingModel);

        $visionModel = trim((string)($_POST['vision_model'] ?? ''));
        Settings::set('ai_copilot_vision_model', $visionModel);

        // Módulos
        $linkingEnabled = isset($_POST['linking_enabled']) ? '1' : '0';
        Settings::set('ai_copilot_linking_enabled', $linkingEnabled);

        $visionEnabled = isset($_POST['vision_enabled']) ? '1' : '0';
        Settings::set('ai_copilot_vision_enabled', $visionEnabled);

        $visionMode = trim((string)($_POST['vision_mode'] ?? 'auto'));
        Settings::set('ai_copilot_vision_mode', $visionMode === 'manual' ? 'manual' : 'auto');

        // Timeout
        $timeout = max(3, min(30, (int)($_POST['timeout'] ?? 8)));
        Settings::set('ai_copilot_timeout', (string)$timeout);

        $message = 'Configurações do AI Copilot salvas com sucesso!';
    }
}

// Recupera valores atuais
$currentProvider = AiClient::getActiveProvider();
$geminiKeyFromConfig = AiClient::isKeyFromConfig('gemini');
$openaiKeyFromConfig = AiClient::isKeyFromConfig('openai');
$customKeyFromConfig = AiClient::isKeyFromConfig('custom');

$currentGeminiKey = (string)Settings::get('ai_copilot_gemini_key', '');
$currentOpenAiKey = (string)Settings::get('ai_copilot_openai_key', '');
$currentCustomKey = (string)Settings::get('ai_copilot_custom_key', '');
$currentCustomEndpoint = (string)Settings::get('ai_copilot_custom_endpoint', 'http://127.0.0.1:11434/v1');

$currentModel = (string)Settings::get('ai_copilot_model', '');
$currentLinkingModel = (string)Settings::get('ai_copilot_linking_model', '');
$currentVisionModel = (string)Settings::get('ai_copilot_vision_model', '');

$linkingEnabled = Settings::get('ai_copilot_linking_enabled', '1') === '1';
$visionEnabled = Settings::get('ai_copilot_vision_enabled', '1') === '1';
$visionMode = Settings::get('ai_copilot_vision_mode', 'auto');
$currentTimeout = AiClient::getTimeout();

if (!function_exists('maskKey')) {
    function maskKey(string $key): string {
        if (empty($key)) return '';
        $len = strlen($key);
        if ($len <= 8) return str_repeat('•', $len);
        return substr($key, 0, 6) . '••••••••' . substr($key, -4);
    }
}

$pageTitle = 'Configurações - AI Copilot';
$activePage = 'plugin-settings';
require dirname(__DIR__, 2) . '/public/admin/header.php';
?>
<style>
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 2rem; }
    .form-group { margin-bottom: 1.25rem; }
    .form-group label { display: block; font-weight: 700; font-size: 0.88rem; margin-bottom: 0.4rem; color: var(--text); }
    .form-group .hint { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.35rem; }
    .input-control, select { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.92rem; background: var(--bg); color: var(--text); outline: none; }
    .input-control:focus, select:focus { border-color: var(--primary-accent); }
    .input-control:disabled { opacity: 0.65; cursor: not-allowed; background: var(--border); }
    
    .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    [data-theme="dark"] .alert-success { background: #064e3b; color: #6ee7b7; }
    [data-theme="dark"] .alert-error { background: #7f1d1d; color: #fca5a5; }

    .badge-status { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; }
    .badge-active { background: #dcfce7; color: #166534; }
    .badge-config { background: #e0f2fe; color: #0369a1; }
    [data-theme="dark"] .badge-active { background: #064e3b; color: #6ee7b7; }
    [data-theme="dark"] .badge-config { background: #0c4a6e; color: #7dd3fc; }

    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
    @media(max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
</style>

<div class="container" style="max-width: 960px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem;">
        <div>
            <a href="/admin/plugins.php" style="color: var(--primary-accent); text-decoration: none; font-size: 0.85rem; font-weight: 700;">&larr; Voltar para Plugins</a>
            <h1 style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; margin-top: 0.35rem;">
                ✨ AI Copilot
            </h1>
        </div>
        <span class="badge-status badge-active" style="padding: 0.4rem 0.75rem; font-size: 0.82rem;">v1.0.0 Ativo</span>
    </div>

    <?php if ($message): ?>
        <div class="alert-success"><?= Security::e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-error"><?= Security::e($error) ?></div>
    <?php endif; ?>

    <div id="testAlert" style="display: none; margin-bottom: 1.5rem;"></div>

    <form method="POST" action="">
        <input type="hidden" name="_csrf_token" id="csrfToken" value="<?= Security::e($csrfToken) ?>">

        <!-- Bloco 1: Provedor e Credenciais -->
        <div class="card">
            <h2 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 1.25rem;">Provedor de Inteligência Artificial</h2>
            
            <div class="form-group">
                <label for="provider">Selecione o Provedor Principal</label>
                <select name="provider" id="provider" onchange="switchProviderFields(this.value)">
                    <option value="gemini" <?= $currentProvider === 'gemini' ? 'selected' : '' ?>>Google Gemini API (Recomendado)</option>
                    <option value="openai" <?= $currentProvider === 'openai' ? 'selected' : '' ?>>OpenAI API (GPT-4o / GPT-4o-mini)</option>
                    <option value="custom" <?= $currentProvider === 'custom' ? 'selected' : '' ?>>Endpoint Local / Compatível com OpenAI (Ollama, vLLM, n8n)</option>
                </select>
                <div class="hint">A chave e o modelo padrão serão selecionados automaticamente com base no provedor ativo.</div>
            </div>

            <!-- Campos Google Gemini -->
            <div id="fields_gemini" style="display: <?= $currentProvider === 'gemini' ? 'block' : 'none' ?>;">
                <div class="form-group">
                    <label for="gemini_key">
                        Chave de API do Google Gemini
                        <?php if ($geminiKeyFromConfig): ?>
                            <span class="badge-status badge-config">🔒 Definida em config.local.php</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="gemini_key" id="gemini_key" class="input-control" 
                           value="<?= $geminiKeyFromConfig ? '••••••••••••••••••••••••' : Security::e(maskKey($currentGeminiKey)) ?>" 
                           <?= $geminiKeyFromConfig ? 'disabled' : '' ?>
                           placeholder="AIzaSy...">
                    <div class="hint">Obtenha gratuitamente no Google AI Studio (aistudio.google.com).</div>
                </div>
            </div>

            <!-- Campos OpenAI -->
            <div id="fields_openai" style="display: <?= $currentProvider === 'openai' ? 'block' : 'none' ?>;">
                <div class="form-group">
                    <label for="openai_key">
                        Chave de API da OpenAI
                        <?php if ($openaiKeyFromConfig): ?>
                            <span class="badge-status badge-config">🔒 Definida em config.local.php</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="openai_key" id="openai_key" class="input-control" 
                           value="<?= $openaiKeyFromConfig ? '••••••••••••••••••••••••' : Security::e(maskKey($currentOpenAiKey)) ?>" 
                           <?= $openaiKeyFromConfig ? 'disabled' : '' ?>
                           placeholder="sk-...">
                </div>
            </div>

            <!-- Campos Custom / Ollama -->
            <div id="fields_custom" style="display: <?= $currentProvider === 'custom' ? 'block' : 'none' ?>;">
                <div class="form-group">
                    <label for="custom_endpoint">URL Base do Endpoint</label>
                    <input type="text" name="custom_endpoint" id="custom_endpoint" class="input-control" 
                           value="<?= Security::e($currentCustomEndpoint) ?>" 
                           placeholder="http://192.168.1.50:11434/v1">
                    <div class="hint">Ex: <code>http://127.0.0.1:11434/v1</code> para Ollama local. Suporta servidores na rede local privada.</div>
                </div>
                <div class="form-group">
                    <label for="custom_key">Chave de API (Opcional)</label>
                    <input type="password" name="custom_key" id="custom_key" class="input-control" 
                           value="<?= $customKeyFromConfig ? '••••••••••••••••••••••••' : Security::e(maskKey($currentCustomKey)) ?>" 
                           <?= $customKeyFromConfig ? 'disabled' : '' ?>
                           placeholder="Chave se requerida pelo servidor">
                </div>
            </div>

            <!-- Modelo Geral e Timeout -->
            <div class="grid-2">
                <div class="form-group">
                    <label for="model">Nome do Modelo Padrão</label>
                    <input type="text" name="model" id="model" class="input-control" 
                           value="<?= Security::e($currentModel) ?>" 
                           placeholder="Padrão: gemini-2.5-flash ou gpt-4o-mini">
                    <div class="hint">Deixe em branco para usar o modelo recomendado padrão.</div>
                </div>
                <div class="form-group">
                    <label for="timeout">Timeout de Rede (segundos)</label>
                    <input type="number" name="timeout" id="timeout" class="input-control" min="3" max="30" 
                           value="<?= (int)$currentTimeout ?>">
                    <div class="hint">Padrão curto (8s) para manter os workers do PHP-FPM ágeis.</div>
                </div>
            </div>

            <!-- Botão Testar Conexão -->
            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end;">
                <button type="button" id="btnTestConnection" class="btn btn-secondary" onclick="testConnection()">
                    ⚡ Testar Conexão com a IA
                </button>
            </div>
        </div>

        <!-- Bloco 2: Módulo de Linkagem Interna -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 style="font-size: 1.15rem; font-weight: 700; margin: 0;">🔗 Módulo 1: Linkagem Interna Inteligente (SEO)</h2>
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 700; font-size: 0.9rem;">
                    <input type="checkbox" name="linking_enabled" value="1" <?= $linkingEnabled ? 'checked' : '' ?>>
                    Ativar no Editor
                </label>
            </div>
            <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 1.25rem;">
                Auxilia redatores a encontrar matérias antigas relevantes no acervo com pré-filtragem determinística FULLTEXT e ancoragem semântica em 1 clique.
            </p>
            <div class="form-group">
                <label for="linking_model">Modelo Específico para Linkagem (Opcional)</label>
                <input type="text" name="linking_model" id="linking_model" class="input-control" 
                       value="<?= Security::e($currentLinkingModel) ?>" 
                       placeholder="Vazio herda o modelo padrão">
            </div>
        </div>

        <!-- Bloco 3: Módulo de Visão Computacional -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 style="font-size: 1.15rem; font-weight: 700; margin: 0;">👁️ Módulo 2: Visão Computacional em Mídias</h2>
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 700; font-size: 0.9rem;">
                    <input type="checkbox" name="vision_enabled" value="1" <?= $visionEnabled ? 'checked' : '' ?>>
                    Ativar Visão Computacional
                </label>
            </div>
            <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 1.25rem;">
                Gera descrições visuais para acessibilidade (<code>alt_text</code>), legendas jornalísticas (<code>caption</code>) e palavras-chave (<code>ai_tags</code>) usando imagens compactadas WebP 1024px.
            </p>
            <div class="grid-2">
                <div class="form-group">
                    <label for="vision_mode">Modo de Acionamento no Upload Novo</label>
                    <select name="vision_mode" id="vision_mode">
                        <option value="auto" <?= $visionMode === 'auto' ? 'selected' : '' ?>>Automático em Background (pós-upload)</option>
                        <option value="manual" <?= $visionMode === 'manual' ? 'selected' : '' ?>>Apenas Sob Demanda (via botão "✨ Descrever")</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="vision_model">Modelo Específico para Visão (Opcional)</label>
                    <input type="text" name="vision_model" id="vision_model" class="input-control" 
                           value="<?= Security::e($currentVisionModel) ?>" 
                           placeholder="Vazio herda o modelo padrão">
                </div>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 1rem;">
            <button type="submit" name="save_settings" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 0.95rem; font-weight: 700;">
                Salvar Configurações
            </button>
        </div>
    </form>
</div>

<script>
function switchProviderFields(provider) {
    document.getElementById('fields_gemini').style.display = provider === 'gemini' ? 'block' : 'none';
    document.getElementById('fields_openai').style.display = provider === 'openai' ? 'block' : 'none';
    document.getElementById('fields_custom').style.display = provider === 'custom' ? 'block' : 'none';
}

function testConnection() {
    const btn = document.getElementById('btnTestConnection');
    const alertBox = document.getElementById('testAlert');
    const csrfToken = document.getElementById('csrfToken').value;

    btn.disabled = true;
    btn.innerText = '⏳ Testando conexão...';
    alertBox.style.display = 'none';

    fetch('/admin/api/ai.php?action=test_connection', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrfToken
        },
        body: new URLSearchParams({
            '_csrf_token': csrfToken
        })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = '⚡ Testar Conexão com a IA';
        alertBox.style.display = 'block';

        alertBox.textContent = '';
        const strong = document.createElement('strong');

        if (data.ok) {
            alertBox.className = 'alert-success';
            strong.textContent = 'Sucesso! ';
            alertBox.appendChild(strong);
            const msg = (data.message || 'Conexão estabelecida') + ' (Modelo: ' + (data.model || '') + ', ' + (data.duration_ms || 0) + 'ms)';
            alertBox.appendChild(document.createTextNode(msg));
        } else {
            alertBox.className = 'alert-error';
            strong.textContent = 'Erro: ';
            alertBox.appendChild(strong);
            const errorMsg = String(data.error || data.message || 'Falha ao conectar com o provedor.');
            alertBox.appendChild(document.createTextNode(errorMsg));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerText = '⚡ Testar Conexão com a IA';
        alertBox.style.display = 'block';
        alertBox.className = 'alert-error';
        alertBox.textContent = '';
        const strong = document.createElement('strong');
        strong.textContent = 'Erro de Requisição: ';
        alertBox.appendChild(strong);
        alertBox.appendChild(document.createTextNode(err.message || 'Falha na comunicação.'));
    });
}
</script>

<?php require dirname(__DIR__, 2) . '/public/admin/footer.php'; ?>
