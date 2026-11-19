<?php
declare(strict_types=1);

/**
 * LightPress / SpeedPress - Painel de Gestão e Personalização de Temas
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\AdminNav;
use LightPress\Core\BlockCompiler;
use LightPress\Core\Database;
use LightPress\Core\Security;
use LightPress\Core\ThemeManager;

// Acesso estritamente restrito a Administradores
$currentUser = Security::requireAuth(null, ['admin']);
$csrfToken = Security::generateCsrfToken();

$message = null;
$error = null;

// Processar ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = Security::getSafeString($_POST, '_csrf_token');
    if (!Security::validateCsrfToken($submittedToken)) {
        $error = 'Token de segurança CSRF inválido ou expirado.';
    } else {
        $action = trim($_POST['action'] ?? '');
        $themeSlug = trim($_POST['theme'] ?? '');

        if ($action === 'activate') {
            if (empty($themeSlug)) {
                $error = 'Nenhum tema foi especificado para ativação.';
            } else {
                try {
                    ThemeManager::activate($themeSlug);
                    $message = "Tema \"{$themeSlug}\" ativado com sucesso!";
                } catch (\Throwable $e) {
                    $error = "Erro ao ativar o tema: " . $e->getMessage();
                }
            }
        } elseif ($action === 'save_options') {
            $availableThemes = ThemeManager::getAvailableThemes();
            if (!isset($availableThemes[$themeSlug])) {
                $error = 'Tema inválido para personalização.';
            } else {
                $themeMeta = $availableThemes[$themeSlug];
                $submittedOptions = $_POST['options'] ?? [];
                $cleanOptions = [];

                if (is_array($submittedOptions) && !empty($themeMeta['options'])) {
                    foreach ($themeMeta['options'] as $key => $optMeta) {
                        $rawVal = $submittedOptions[$key] ?? '';
                        $type = $optMeta['type'] ?? 'text';

                        if ($type === 'text' || $type === 'color') {
                            $cleanOptions[$key] = trim((string)$rawVal);
                        } elseif ($type === 'textarea') {
                            $cleanOptions[$key] = trim((string)$rawVal);
                        } elseif ($type === 'url') {
                            $cleanOptions[$key] = BlockCompiler::sanitizeUrl((string)$rawVal);
                        } elseif ($type === 'image') {
                            $cleanOptions[$key] = BlockCompiler::sanitizeImageUrl((string)$rawVal);
                        } elseif ($type === 'select') {
                            $cleanOptions[$key] = trim((string)$rawVal);
                        } elseif ($type === 'boolean') {
                            $cleanOptions[$key] = !empty($rawVal) && ($rawVal === '1' || $rawVal === 'true' || $rawVal === 'yes');
                        } else {
                            $cleanOptions[$key] = trim((string)$rawVal);
                        }
                    }
                }

                try {
                    ThemeManager::setThemeOptions($themeSlug, $cleanOptions);
                    $message = "Configurações do tema salvas com sucesso!";
                } catch (\Throwable $e) {
                    $error = "Erro ao salvar opções do tema: " . $e->getMessage();
                }
            }
        }
    }
}

$availableThemes = ThemeManager::getAvailableThemes();
$activeThemeSlug = ThemeManager::getActiveTheme();
$customizeSlug = Security::getSafeString($_GET, 'customize');

// Carregar páginas publicadas para seletores de página estática
$publishedPages = [];
try {
    $publishedPages = Database::fetchAll("SELECT id, title, slug FROM pages WHERE status = 'published' ORDER BY title ASC");
} catch (\Throwable) {
    $publishedPages = [];
}

$pageTitle = !empty($customizeSlug) ? 'Personalizar Tema' : 'Gerenciador de Temas';
$activePage = 'themes';
require __DIR__ . '/header.php';
?>
<style>
    .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: 0 1px 3px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 2rem; }
    
    /* Grid da Galeria de Temas */
    .themes-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .theme-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius, 12px); overflow: hidden; display: flex; flex-col: column; box-shadow: 0 2px 4px rgba(0,0,0,0.04); transition: transform 0.2s, box-shadow 0.2s; position: relative; }
    .theme-card:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(0,0,0,0.08); }
    .theme-card.active { border: 2px solid var(--primary-accent); }
    
    .theme-preview-box { height: 160px; background: linear-gradient(135deg, #1e293b, #0f172a); display: flex; align-items: center; justify-content: center; color: #fff; padding: 1.5rem; text-align: center; position: relative; }
    .theme-preview-box.type-news { background: linear-gradient(135deg, #111418, #b30400); }
    .theme-preview-box.type-corporate { background: linear-gradient(135deg, #0f172a, #1e40af); }
    
    .theme-type-badge { position: absolute; top: 12px; left: 12px; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); color: #fff; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; padding: 0.25rem 0.6rem; border-radius: 9999px; letter-spacing: 0.05em; }
    .theme-active-badge { position: absolute; top: 12px; right: 12px; background: #22c55e; color: #fff; font-size: 0.75rem; font-weight: 800; padding: 0.25rem 0.65rem; border-radius: 9999px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 0.3rem; }
    
    .theme-card-body { padding: 1.25rem; display: flex; flex-direction: column; flex-grow: 1; }
    .theme-card-title { font-size: 1.15rem; font-weight: 800; color: var(--text); margin-bottom: 0.25rem; }
    .theme-card-meta { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.75rem; }
    .theme-card-desc { font-size: 0.88rem; color: var(--text); line-height: 1.45; margin-bottom: 1.25rem; flex-grow: 1; }
    .theme-card-actions { display: flex; gap: 0.5rem; align-items: center; border-top: 1px solid var(--border); padding-top: 1rem; }
    
    /* Formulário do Personalizador */
    .form-group { margin-bottom: 1.5rem; }
    .form-label { display: block; font-weight: 700; font-size: 0.88rem; color: var(--text); margin-bottom: 0.4rem; }
    .form-help { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem; }
    .form-control { width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); font-size: 0.9rem; }
    .form-control:focus { outline: none; border-color: var(--primary-accent); }
    
    .image-preview-wrapper { display: flex; align-items: center; gap: 1rem; margin-top: 0.5rem; }
    .image-preview-thumb { width: 80px; height: 80px; border-radius: 8px; border: 1px solid var(--border); object-fit: cover; background: var(--surface); }
    .image-preview-empty { width: 80px; height: 80px; border-radius: 8px; border: 1px dashed var(--border); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--text-muted); background: var(--bg); }
    
    /* Alertas */
    .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    [data-theme="dark"] .alert-success { background: #064e3b; color: #6ee7b7; }
    [data-theme="dark"] .alert-error { background: #7f1d1d; color: #fca5a5; }

    /* Modal de Mídia */
    .modal-backdrop { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; display: none; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
    .modal-container { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius, 12px); width: 90%; max-width: 860px; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3); }
    .modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 1.5rem; overflow-y: auto; flex-grow: 1; }
    .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .modal-media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 0.85rem; margin-top: 1rem; }
    .modal-media-item { border: 2px solid transparent; border-radius: 8px; overflow: hidden; cursor: pointer; aspect-ratio: 1; position: relative; background: var(--bg); transition: border-color 0.15s, transform 0.15s; }
    .modal-media-item:hover { transform: scale(1.02); }
    .modal-media-item.selected { border-color: var(--primary-accent); }
    .modal-media-item img { width: 100%; height: 100%; object-fit: cover; }
</style>

<div class="container">
    <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

    <?php if (!empty($customizeSlug) && isset($availableThemes[$customizeSlug])): 
        $targetTheme = $availableThemes[$customizeSlug];
        $currentOptions = ThemeManager::getThemeOptions($customizeSlug);
    ?>
        <!-- MODO DE PERSONALIZAÇÃO -->
        <div class="header-section">
            <div>
                <a href="/admin/themes.php" style="color: var(--primary-accent); text-decoration: none; font-weight: 700; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.5rem;">
                    &larr; Voltar para Galeria de Temas
                </a>
                <h1 style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em;">
                    🎨 Personalizar Tema: <?= Security::e($targetTheme['name']) ?>
                </h1>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem;">
                    Configure títulos, chamadas, links sociais, mídias e comportamento da página inicial.
                </p>
            </div>
        </div>

        <form method="POST" action="/admin/themes.php?customize=<?= urlencode($customizeSlug) ?>">
            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_options">
            <input type="hidden" name="theme" value="<?= Security::e($customizeSlug) ?>">

            <div class="card" style="padding: 2rem;">
                <?php if (empty($targetTheme['options'])): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem 0;">
                        Este tema não declarou opções personalizáveis em seu manifesto <code>theme.json</code>.
                    </p>
                <?php else: ?>
                    <?php foreach ($targetTheme['options'] as $key => $opt): 
                        $val = $currentOptions[$key] ?? ($opt['default'] ?? '');
                        $type = $opt['type'] ?? 'text';
                        $label = $opt['label'] ?? ucfirst(str_replace('_', ' ', $key));
                        $help = $opt['description'] ?? '';
                    ?>
                        <div class="form-group">
                            <label class="form-label" for="opt_<?= Security::e($key) ?>">
                                <?= Security::e($label) ?>
                            </label>

                            <?php if ($type === 'text'): ?>
                                <input type="text" class="form-control" id="opt_<?= Security::e($key) ?>" name="options[<?= Security::e($key) ?>]" value="<?= Security::e((string)$val) ?>">

                            <?php elseif ($type === 'url'): ?>
                                <input type="text" inputmode="url" class="form-control" id="opt_<?= Security::e($key) ?>" name="options[<?= Security::e($key) ?>]" value="<?= Security::e(BlockCompiler::sanitizeUrl((string)$val)) ?>" placeholder="https://... ou /caminho ou #ancora">

                            <?php elseif ($type === 'textarea'): ?>
                                <textarea class="form-control" id="opt_<?= Security::e($key) ?>" name="options[<?= Security::e($key) ?>]" rows="3"><?= Security::e((string)$val) ?></textarea>

                            <?php elseif ($type === 'color'): ?>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="color" id="picker_<?= Security::e($key) ?>" value="<?= Security::e((string)$val) ?>" onchange="document.getElementById('opt_<?= Security::e($key) ?>').value = this.value;" style="height: 40px; width: 50px; padding: 0; border: none; cursor: pointer; background: transparent;">
                                    <input type="text" class="form-control" style="width: 130px;" id="opt_<?= Security::e($key) ?>" name="options[<?= Security::e($key) ?>]" value="<?= Security::e((string)$val) ?>" oninput="document.getElementById('picker_<?= Security::e($key) ?>').value = this.value;">
                                </div>

                            <?php elseif ($type === 'boolean'): ?>
                                <select class="form-control" id="opt_<?= Security::e($key) ?>" name="options[<?= Security::e($key) ?>]" style="max-width: 200px;">
                                    <option value="1" <?= !empty($val) ? 'selected' : '' ?>>Sim / Ativado</option>
                                    <option value="0" <?= empty($val) ? 'selected' : '' ?>>Não / Desativado</option>
                                </select>

                            <?php elseif ($type === 'select'): ?>
                                <select class="form-control" id="opt_<?= Security::e($key) ?>" name="options[<?= Security::e($key) ?>]">
                                    <?php if ($key === 'home_static_page_id'): ?>
                                        <option value="">-- Padrão (Sem Página Estática Vinculada) --</option>
                                        <?php foreach ($publishedPages as $pg): ?>
                                            <option value="<?= (int)$pg['id'] ?>" <?= ((string)$val === (string)$pg['id']) ? 'selected' : '' ?>>
                                                <?= Security::e($pg['title']) ?> (/<?= Security::e($pg['slug']) ?>/)
                                            </option>
                                        <?php endforeach; ?>
                                    <?php elseif (!empty($opt['choices']) && is_array($opt['choices'])): ?>
                                        <?php foreach ($opt['choices'] as $cVal => $cLabel): ?>
                                            <option value="<?= Security::e((string)$cVal) ?>" <?= ((string)$val === (string)$cVal) ? 'selected' : '' ?>>
                                                <?= Security::e((string)$cLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>

                            <?php elseif ($type === 'image'): 
                                $safeImgVal = BlockCompiler::sanitizeImageUrl((string)$val);
                            ?>
                                <input type="hidden" id="opt_<?= Security::e($key) ?>" name="options[<?= Security::e($key) ?>]" value="<?= Security::e($safeImgVal) ?>">
                                <div class="image-preview-wrapper">
                                    <div id="preview_box_<?= Security::e($key) ?>">
                                        <?php if (!empty($safeImgVal)): ?>
                                            <img src="<?= Security::e($safeImgVal) ?>" class="image-preview-thumb" id="img_preview_<?= Security::e($key) ?>" alt="Preview">
                                        <?php else: ?>
                                            <div class="image-preview-empty" id="empty_preview_<?= Security::e($key) ?>">📷</div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                                        <button type="button" class="btn btn-secondary" onclick="openMediaModalFor('opt_<?= Security::e($key) ?>')">
                                            📷 Escolher da Biblioteca de Mídias
                                        </button>
                                        <button type="button" class="btn btn-secondary" style="color: #dc2626; font-size: 0.8rem; padding: 0.2rem 0.5rem;" onclick="clearImageField('opt_<?= Security::e($key) ?>')">
                                            Remover Imagem
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($help)): ?>
                                <p class="form-help"><?= Security::e($help) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div style="margin-top: 2rem; border-top: 1px solid var(--border); padding-top: 1.5rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <a href="/admin/themes.php" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.75rem; font-weight: 700;">
                        💾 Salvar Alterações
                    </button>
                </div>
            </div>
        </form>

    <?php else: ?>
        <!-- MODO DE GALERIA DE TEMAS -->
        <div class="header-section">
            <div>
                <h1 style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em;">Gerenciador de Temas</h1>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem;">
                    Alterne instantaneamente o layout da plataforma entre <strong>Portal de Notícias</strong> e <strong>Site Institucional / Empresa</strong>.
                </p>
            </div>
            <div>
                <a href="/" target="_blank" class="btn btn-secondary">
                    🌐 Visualizar Site Atual
                </a>
            </div>
        </div>

        <?php if (empty($availableThemes)): ?>
            <div class="card" style="padding: 3rem; text-align: center; color: var(--text-muted);">
                <p style="font-size: 1.1rem; font-weight: 600;">Nenhum tema encontrado na pasta <code>/themes/</code>.</p>
            </div>
        <?php else: ?>
            <div class="themes-grid">
                <?php foreach ($availableThemes as $slug => $theme): 
                    $isActive = ($slug === $activeThemeSlug);
                    $themeType = $theme['type'] ?? 'news';
                ?>
                    <div class="theme-card <?= $isActive ? 'active' : '' ?>">
                        <div class="theme-preview-box type-<?= Security::e($themeType) ?>">
                            <span class="theme-type-badge">
                                <?= $themeType === 'corporate' ? '🏢 Corporativo' : '📰 Notícias' ?>
                            </span>

                            <?php if ($isActive): ?>
                                <span class="theme-active-badge">✓ Ativo</span>
                            <?php endif; ?>

                            <div style="font-weight: 800; font-size: 1.4rem; letter-spacing: -0.02em;">
                                <?= Security::e($theme['name']) ?>
                            </div>
                        </div>

                        <div class="theme-card-body">
                            <div class="theme-card-title"><?= Security::e($theme['name']) ?></div>
                            <div class="theme-card-meta">
                                Versão <?= Security::e($theme['version'] ?? '1.0.0') ?> 
                                <?php if (!empty($theme['author'])): ?>
                                    • Por <?= Security::e($theme['author']) ?>
                                <?php endif; ?>
                            </div>

                            <p class="theme-card-desc">
                                <?= Security::e($theme['description'] ?? 'Sem descrição informada.') ?>
                            </p>

                            <div class="theme-card-actions">
                                <a href="/admin/themes.php?customize=<?= urlencode($slug) ?>" class="btn btn-secondary" style="flex-grow: 1; text-align: center;">
                                    🎨 Personalizar
                                </a>

                                <?php if (!$isActive): ?>
                                    <form method="POST" action="/admin/themes.php" style="margin: 0; padding: 0;">
                                        <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="theme" value="<?= Security::e($slug) ?>">
                                        <button type="submit" class="btn btn-primary" onclick="return confirm('Deseja realmente ativar o tema <?= Security::e(addslashes($theme['name'])) ?>?');">
                                            Ativar Tema
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Modal da Biblioteca de Mídias (Reutilizável e Integrado) -->
<div id="mediaModal" class="modal-backdrop">
    <div class="modal-container">
        <div class="modal-header">
            <h3 style="font-size: 1.15rem; font-weight: 700; margin: 0;">Biblioteca de Mídias</h3>
            <button type="button" class="btn btn-secondary" onclick="closeMediaModal()" style="padding: 0.3rem 0.6rem;">&times;</button>
        </div>
        <div style="padding: 0.75rem 1.5rem; background: var(--bg); border-bottom: 1px solid var(--border); display: flex; gap: 1rem; align-items: center;">
            <input type="text" id="modalSearchInput" placeholder="Buscar imagem..." class="form-control" style="max-width: 300px; padding: 0.4rem 0.75rem;" oninput="filterMediaItems(this.value)">
            <label class="btn btn-secondary" style="cursor: pointer; margin: 0; font-size: 0.85rem;">
                ⬆️ Enviar Nova Foto
                <input type="file" id="modalUploadInput" style="display: none;" accept="image/*" onchange="uploadFromModal(this)">
            </label>
            <span id="modalLoading" style="font-size: 0.85rem; color: var(--text-muted); display: none;">Carregando...</span>
        </div>
        <div class="modal-body">
            <div id="modalGrid" class="modal-media-grid"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeMediaModal()">Cancelar</button>
            <button type="button" class="btn btn-primary" id="btnSelectMedia" onclick="applyMediaSelection()" disabled>Usar Imagem Selecionada</button>
        </div>
    </div>
</div>

<script>
    var currentTargetFieldId = null;
    var modalMediaList = [];
    var selectedMediaItem = null;

    function openMediaModalFor(fieldId) {
        currentTargetFieldId = fieldId;
        document.getElementById('mediaModal').style.display = 'flex';
        loadMediaCatalog();
    }

    function closeMediaModal() {
        document.getElementById('mediaModal').style.display = 'none';
        selectedMediaItem = null;
        document.getElementById('btnSelectMedia').disabled = true;
    }

    function clearImageField(fieldId) {
        document.getElementById(fieldId).value = '';
        var previewBox = document.getElementById('preview_box_' + fieldId.replace('opt_', ''));
        if (previewBox) {
            previewBox.innerHTML = '';
            var emptyDiv = document.createElement('div');
            emptyDiv.className = 'image-preview-empty';
            emptyDiv.textContent = '📷';
            previewBox.appendChild(emptyDiv);
        }
    }

    function loadMediaCatalog() {
        var loadingEl = document.getElementById('modalLoading');
        loadingEl.style.display = 'inline';
        fetch('/admin/media.php?format=json')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                loadingEl.style.display = 'none';
                if (data.ok && Array.isArray(data.items)) {
                    modalMediaList = data.items;
                    renderModalGrid(modalMediaList);
                }
            })
            .catch(function(err) {
                loadingEl.style.display = 'none';
                console.error('Erro ao carregar catálogo de mídias:', err);
            });
    }

    function renderModalGrid(items) {
        var grid = document.getElementById('modalGrid');
        grid.innerHTML = '';
        if (!items || items.length === 0) {
            grid.innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 2rem;">Nenhuma foto encontrada.</p>';
            return;
        }

        items.forEach(function(item) {
            var card = document.createElement('div');
            card.className = 'modal-media-item';
            var img = document.createElement('img');
            img.src = item.thumb_url || item.full_url;
            img.loading = 'lazy';
            card.appendChild(img);

            card.onclick = function() {
                document.querySelectorAll('.modal-media-item').forEach(function(el) { el.classList.remove('selected'); });
                card.classList.add('selected');
                selectedMediaItem = item;
                document.getElementById('btnSelectMedia').disabled = false;
            };

            card.ondblclick = function() {
                selectedMediaItem = item;
                applyMediaSelection();
            };

            grid.appendChild(card);
        });
    }

    function filterMediaItems(query) {
        query = query.toLowerCase().trim();
        if (!query) {
            renderModalGrid(modalMediaList);
            return;
        }
        var filtered = modalMediaList.filter(function(it) {
            return (it.original_name || '').toLowerCase().includes(query);
        });
        renderModalGrid(filtered);
    }

    function uploadFromModal(input) {
        if (!input.files || input.files.length === 0) return;
        var file = input.files[0];
        var formData = new FormData();
        formData.append('media_file', file);
        formData.append('_csrf_token', '<?= Security::e($csrfToken) ?>');

        var loadingEl = document.getElementById('modalLoading');
        loadingEl.style.display = 'inline';
        loadingEl.innerText = 'Enviando foto...';

        fetch('/admin/media.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            input.value = '';
            if (data.ok && data.media) {
                loadMediaCatalog();
            } else {
                alert('Erro no envio da imagem: ' + (data.error || 'Falha ao processar'));
                loadingEl.style.display = 'none';
            }
        })
        .catch(function(err) {
            alert('Erro no envio da imagem: ' + err.message);
            loadingEl.style.display = 'none';
        });
    }

    function applyMediaSelection() {
        if (!selectedMediaItem || !currentTargetFieldId) return;
        var url = selectedMediaItem.full_url || selectedMediaItem.thumb_url;
        document.getElementById(currentTargetFieldId).value = url;

        var key = currentTargetFieldId.replace('opt_', '');
        var previewBox = document.getElementById('preview_box_' + key);
        if (previewBox) {
            previewBox.innerHTML = '';
            var img = document.createElement('img');
            img.src = url;
            img.className = 'image-preview-thumb';
            img.alt = 'Preview';
            previewBox.appendChild(img);
        }

        closeMediaModal();
    }

    window.onclick = function(event) {
        var modal = document.getElementById('mediaModal');
        if (event.target === modal) {
            closeMediaModal();
        }
    };
</script>

<?php require __DIR__ . '/footer.php'; ?>
