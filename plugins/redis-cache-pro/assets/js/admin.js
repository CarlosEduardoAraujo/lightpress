/**
 * Redis Cache Pro - Interatividade do Painel Administrativo
 */
(function() {
    'use strict';

    // Gerenciador de Abas
    const tabBtns = document.querySelectorAll('.rcp-tab-btn');
    const tabContents = document.querySelectorAll('.rcp-tab-content');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-tab');
            tabBtns.forEach(b => b.classList.remove('is-active'));
            tabContents.forEach(c => c.classList.remove('is-active'));
            btn.classList.add('is-active');
            const targetContent = document.getElementById(targetId);
            if (targetContent) {
                targetContent.classList.add('is-active');
            }
        });
    });

    // Utilitário de Toast de Notificação
    function showToast(message, type = 'success') {
        let toast = document.getElementById('rcp-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'rcp-toast';
            toast.className = 'rcp-toast';
            document.body.appendChild(toast);
        }
        toast.className = `rcp-toast is-${type}`;
        toast.textContent = message;
        toast.classList.add('is-show');

        setTimeout(() => {
            toast.classList.remove('is-show');
        }, 4000);
    }

    // Helper de Requisição AJAX
    async function apiRequest(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('_csrf_token', window.rcpCsrfToken || '');
        for (const [key, val] of Object.entries(data)) {
            formData.append(key, typeof val === 'object' ? JSON.stringify(val) : val);
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`Erro HTTP ${response.status}`);
            }

            return await response.json();
        } catch (err) {
            return { success: false, message: err.message };
        }
    }

    // Ação: Limpar Cache Completo
    const btnFlush = document.getElementById('rcp-btn-flush');
    if (btnFlush) {
        btnFlush.addEventListener('click', async () => {
            if (!confirm('Deseja realmente limpar todo o cache de objetos desta instalação?')) {
                return;
            }
            btnFlush.disabled = true;
            btnFlush.innerHTML = '⚡ Limpando...';

            const res = await apiRequest('flush_cache');
            btnFlush.disabled = false;
            btnFlush.innerHTML = '<span class="rcp-icon">⚡</span> Limpar Cache Completo';

            if (res.success) {
                showToast(res.message || 'Cache de objetos invalidado em O(1) com sucesso!');
                setTimeout(() => window.location.reload(), 800);
            } else {
                showToast(res.message || 'Falha ao limpar o cache.', 'error');
            }
        });
    }

    // Ação: Pré-aquecer Cache (Warmup)
    const btnWarmup = document.getElementById('rcp-btn-warmup');
    if (btnWarmup) {
        btnWarmup.addEventListener('click', async () => {
            btnWarmup.disabled = true;
            btnWarmup.innerHTML = '🔥 Aquecendo...';

            const res = await apiRequest('run_warmup');
            btnWarmup.disabled = false;
            btnWarmup.innerHTML = '<span class="rcp-icon">🔥</span> Pré-aquecer (Warmup)';

            if (res.success) {
                const stats = res.stats || {};
                const msg = `Aquecimento concluído! ${stats.settings_count || 0} opções, ${stats.posts_count || 0} posts e ${stats.categories_count || 0} categorias carregadas em ${stats.duration_ms || 0}ms.`;
                showToast(msg);
            } else {
                showToast(res.message || 'Falha ao pré-aquecer cache.', 'error');
            }
        });
    }

    // Ação: Purgar Grupo Granular
    document.querySelectorAll('.rcp-btn-purge-group').forEach(btn => {
        btn.addEventListener('click', async () => {
            const group = btn.getAttribute('data-group');
            if (!group) return;

            btn.disabled = true;
            const originalText = btn.textContent;
            btn.textContent = 'Purgando...';

            const res = await apiRequest('purge_group', { group });
            btn.disabled = false;
            btn.textContent = originalText;

            if (res.success) {
                showToast(`Grupo "${group}" invalidado com sucesso!`);
            } else {
                showToast(res.message || 'Falha ao purgar grupo.', 'error');
            }
        });
    });

    // Ação: Escanear Maiores Chaves (Big Key Hunter)
    const btnScanBigKeys = document.getElementById('rcp-btn-scan-big-keys');
    const bigKeysTbody = document.getElementById('rcp-big-keys-tbody');

    if (btnScanBigKeys && bigKeysTbody) {
        btnScanBigKeys.addEventListener('click', async () => {
            btnScanBigKeys.disabled = true;
            btnScanBigKeys.innerHTML = '🔍 Escaneando...';
            bigKeysTbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--rcp-text-muted);">Realizando varredura não-bloqueante SCAN no Redis...</td></tr>';

            const res = await apiRequest('scan_big_keys');
            btnScanBigKeys.disabled = false;
            btnScanBigKeys.innerHTML = '🔍 Escanear Maiores Chaves';

            if (!res.success || !res.items || res.items.length === 0) {
                bigKeysTbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--rcp-text-muted);">Nenhuma chave expressiva encontrada no escopo deste prefixo.</td></tr>';
                return;
            }

            let html = '';
            res.items.forEach(item => {
                const ttlLabel = item.ttl > 0 ? `${item.ttl}s` : (item.ttl === -1 ? 'Infinito (sem TTL)' : 'Expirada');
                html += `
                    <tr>
                        <td><code class="rcp-code-key">${escapeHtml(item.key)}</code></td>
                        <td><span class="rcp-code-tag">${escapeHtml(item.type)}</span></td>
                        <td><strong>${escapeHtml(item.size_human)}</strong></td>
                        <td>${escapeHtml(ttlLabel)}</td>
                        <td>
                            <button type="button" class="rcp-btn rcp-btn-danger rcp-btn-del-key" data-key="${escapeHtml(item.key)}" style="padding:0.3rem 0.6rem;font-size:0.75rem;">
                                UNLINK
                            </button>
                        </td>
                    </tr>
                `;
            });
            bigKeysTbody.innerHTML = html;

            // Vincular botões de exclusão individual
            document.querySelectorAll('.rcp-btn-del-key').forEach(delBtn => {
                delBtn.addEventListener('click', async () => {
                    const key = delBtn.getAttribute('data-key');
                    if (!confirm(`Remover via UNLINK a chave "${key}"?`)) return;

                    delBtn.disabled = true;
                    delBtn.textContent = '...';
                    const delRes = await apiRequest('delete_single_key', { key });
                    if (delRes.success) {
                        showToast(`Chave removida com sucesso!`);
                        delBtn.closest('tr').remove();
                    } else {
                        delBtn.disabled = false;
                        delBtn.textContent = 'UNLINK';
                        showToast(delRes.message || 'Falha ao remover chave.', 'error');
                    }
                });
            });
        });
    }

    // Ação: Testar Webhook de Alerta
    const btnTestWebhook = document.getElementById('rcp-btn-test-webhook');
    if (btnTestWebhook) {
        btnTestWebhook.addEventListener('click', async () => {
            const form = document.getElementById('rcp-alerts-form');
            if (!form) return;

            const webhookUrl = form.querySelector('[name="webhook_url"]')?.value || '';
            const alertEmail = form.querySelector('[name="alert_email"]')?.value || '';

            if (!webhookUrl && !alertEmail) {
                showToast('Informe uma URL de Webhook ou e-mail de alerta antes de testar.', 'error');
                return;
            }

            btnTestWebhook.disabled = true;
            btnTestWebhook.textContent = '🔔 Enviando...';

            const res = await apiRequest('test_webhook', { webhook_url: webhookUrl, alert_email: alertEmail });
            btnTestWebhook.disabled = false;
            btnTestWebhook.textContent = '🔔 Enviar Notificação de Teste';

            const feedback = document.getElementById('rcp-alerts-feedback');
            if (feedback) {
                feedback.style.display = 'block';
                feedback.style.color = res.success ? '#10b981' : '#ef4444';
                feedback.textContent = res.message || (res.success ? 'Notificação enviada com sucesso!' : 'Falha no disparo.');
            }

            showToast(res.message || 'Teste concluído!', res.success ? 'success' : 'error');
        });
    }

    // Ação: Salvar Formulários de Configuração e Alertas via AJAX
    ['rcp-settings-form', 'rcp-alerts-form'].forEach(formId => {
        const form = document.getElementById(formId);
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Salvando...';
                }

                const formData = new FormData(form);
                const dataObj = {};
                formData.forEach((value, key) => {
                    dataObj[key] = value;
                });

                const res = await apiRequest('save_settings', dataObj);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Salvar Configurações';
                }

                if (res.success) {
                    showToast(res.message || 'Configurações salvas com sucesso!');
                } else {
                    showToast(res.message || 'Falha ao salvar configurações.', 'error');
                }
            });
        }
    });

    // Renderizador Dinâmico de Gráfico SVG de Histórico 24h
    function render24HourChart(historyData) {
        const container = document.getElementById('rcp-history-chart');
        if (!container || !historyData || historyData.length === 0) return;

        const width = 1100;
        const height = 240;
        const padding = { top: 25, right: 30, bottom: 35, left: 50 };
        const chartW = width - padding.left - padding.right;
        const chartH = height - padding.top - padding.bottom;

        // Calcular valores máximos para escala
        let maxCommands = 10;
        historyData.forEach(d => {
            if (d.commands > maxCommands) maxCommands = d.commands;
        });

        // Pontos de coordenadas
        const stepX = chartW / (historyData.length - 1);
        const pointsRatio = [];
        const pointsCmd = [];

        historyData.forEach((d, i) => {
            const x = padding.left + (i * stepX);
            // Hit ratio escala 0% - 100%
            const yRatio = padding.top + chartH - ((d.hit_ratio / 100) * chartH);
            pointsRatio.push({ x, y: yRatio, data: d });

            // Comandos escala 0 - maxCommands
            const yCmd = padding.top + chartH - ((d.commands / maxCommands) * chartH);
            pointsCmd.push({ x, y: yCmd, data: d });
        });

        // Construir Paths SVG
        let pathRatioStr = pointsRatio.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ');
        let areaRatioStr = `${pathRatioStr} L ${pointsRatio[pointsRatio.length - 1].x.toFixed(1)} ${padding.top + chartH} L ${pointsRatio[0].x.toFixed(1)} ${padding.top + chartH} Z`;

        let pathCmdStr = pointsCmd.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ');

        // Gerar linhas de grade horizontais
        let gridLines = '';
        for (let i = 0; i <= 4; i++) {
            const y = padding.top + (chartH * (i / 4));
            const pct = 100 - (i * 25);
            gridLines += `
                <line x1="${padding.left}" y1="${y}" x2="${width - padding.right}" y2="${y}" stroke="#e2e8f0" stroke-dasharray="3,3" />
                <text x="${padding.left - 10}" y="${y + 4}" fill="#94a3b8" font-size="10" text-anchor="end">${pct}%</text>
            `;
        }

        // Rótulos do eixo X (a cada 3 horas)
        let xLabels = '';
        historyData.forEach((d, i) => {
            if (i % 3 === 0 || i === historyData.length - 1) {
                const x = padding.left + (i * stepX);
                xLabels += `<text x="${x}" y="${height - 10}" fill="#94a3b8" font-size="10" text-anchor="middle">${escapeHtml(d.label)}</text>`;
            }
        });

        // SVG Interativo
        container.innerHTML = `
            <svg viewBox="0 0 ${width} ${height}" style="width:100%;height:auto;overflow:visible;">
                <defs>
                    <linearGradient id="rcpAreaGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#10b981" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#10b981" stop-opacity="0.02"/>
                    </linearGradient>
                </defs>
                
                ${gridLines}
                ${xLabels}

                <!-- Área e Linha Hit Ratio -->
                <path d="${areaRatioStr}" fill="url(#rcpAreaGrad)"/>
                <path d="${pathRatioStr}" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>

                <!-- Linha de Comandos -->
                <path d="${pathCmdStr}" fill="none" stroke="#6366f1" stroke-width="1.8" stroke-dasharray="4,3" stroke-linecap="round"/>

                <!-- Pontos Interativos com Tooltip -->
                ${pointsRatio.map(p => `
                    <circle cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="4" fill="#ffffff" stroke="#10b981" stroke-width="2" style="cursor:pointer;">
                        <title>${escapeHtml(p.data.label)}: Hit Ratio ${p.data.hit_ratio}% (${p.data.hits} hits / ${p.data.misses} misses, ${p.data.commands} cmds, ${p.data.avg_latency_ms}ms)</title>
                    </circle>
                `).join('')}
            </svg>
            <div style="display:flex;justify-content:center;gap:2rem;margin-top:0.75rem;font-size:0.8rem;color:var(--rcp-text-muted);">
                <span style="display:inline-flex;align-items:center;gap:0.4rem;">
                    <span style="display:inline-block;width:12px;height:3px;background:#10b981;border-radius:2px;"></span> Taxa de Acerto (Hit Ratio %)
                </span>
                <span style="display:inline-flex;align-items:center;gap:0.4rem;">
                    <span style="display:inline-block;width:12px;height:2px;background:#6366f1;border-radius:2px;border-top:1px dashed #6366f1;"></span> Volume de Comandos Executados
                </span>
            </div>
        `;
    }

    // Inicializar gráfico se os dados estiverem disponíveis
    if (window.rcpHistoryData) {
        render24HourChart(window.rcpHistoryData);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]);
    }

})();
