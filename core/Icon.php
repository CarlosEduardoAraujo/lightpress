<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * Provedor de Ícones SVG Ultraleves (Zero Font Download, 0ms Latency)
 * 
 * Substitui o download de fontes pesadas como Material Symbols (>1.2MB)
 * por SVGs embutidos que pesam meros bytes e carregam instantaneamente
 * em 100% dos navegadores e dispositivos (Windows, Mac, iOS, Android, Linux).
 */
class Icon
{
    /**
     * Renderiza o SVG correspondente ao nome solicitado.
     */
    public static function svg(string $name, string $class = 'w-4 h-4', int $size = 20): string
    {
        $svgBody = match ($name) {
            // Busca
            'search' => '<circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="m20 20-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            
            // Modo Claro (Sol)
            'light_mode', 'sun' => '<circle cx="12" cy="12" r="4" fill="currentColor"/><path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41m14.14-14.14-1.41 1.41" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            
            // Modo Escuro (Lua)
            'dark_mode', 'moon' => '<path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-3.14-9.8A9 9 0 0 0 12 3z" fill="currentColor"/>',
            
            // Idioma / Globo
            'language' => '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" stroke="currentColor" stroke-width="1.8"/>',
            
            // Usuário / Perfil
            'person' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2"/>',
            
            // Em Alta / Trending
            'trending_up' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="16 7 22 7 22 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // E-mail / Newsletter
            'mail', 'mark_email_read', 'mark_email_unread' => '<rect width="18" height="14" x="3" y="5" rx="2" stroke="currentColor" stroke-width="2"/><path d="m3 7 9 6 9-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // Compartilhar
            'share' => '<circle cx="18" cy="5" r="3" stroke="currentColor" stroke-width="2"/><circle cx="6" cy="12" r="3" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="19" r="3" stroke="currentColor" stroke-width="2"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49" stroke="currentColor" stroke-width="2"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49" stroke="currentColor" stroke-width="2"/>',
            
            // Link Copiar
            'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            
            // Checkmark (Sucesso Copiado)
            'check' => '<polyline points="20 6 9 17 4 12" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // Seta para a Direita
            'arrow_forward', 'chevron_right' => '<path d="m9 18 6-6-6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // Calendário / Data
            'calendar_today' => '<rect width="18" height="18" x="3" y="4" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2"/>',
            
            // Relógio / Tempo de leitura
            'schedule' => '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            
            // Verificado (Selo Oficial FIA / SpeedRacing)
            'verified' => '<path d="M12 2l2.4 2.4 3.4-.4 1.2 3.2 3 1.6-1 3.2 1 3.2-3 1.6-1.2 3.2-3.4-.4L12 22l-2.4-2.4-3.4.4-1.2-3.2-3-1.6 1-3.2-1-3.2 3-1.6 1.2-3.2 3.4.4L12 2z" fill="currentColor"/><path d="m9 12 2 2 4-4" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // Home
            'home' => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><polyline points="9 22 9 12 15 12 15 22" stroke="currentColor" stroke-width="2"/>',
            
            // Câmera (Instagram)
            'photo_camera' => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="13" r="3" stroke="currentColor" stroke-width="2"/>',
            
            // Abrir em nova aba
            'open_in_new' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // Anúncio / Megafone (Banner Parceiro)
            'campaign' => '<path d="m3 11 15-5v12L3 13v-2zM18 8l4-2v12l-4-2M11 13v6a2 2 0 0 1-2 2H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // Temporada / Troféu
            'military_tech', 'recommend' => '<path d="M6 9H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h2M18 9h2a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-2M4 5h16v4a6 6 0 0 1-6 6h-4a6 6 0 0 1-6-6V5zM12 15v4M8 19h8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // Nota do editor / Lápis
            'edit_note' => '<path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // Chat / WhatsApp
            'chat' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            
            // Telegram / Enviar
            'send' => '<line x1="22" x2="11" y1="2" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polygon points="22 2 15 22 11 13 2 9 22 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',

            default => '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/>',
        };

        return sprintf(
            '<svg viewBox="0 0 24 24" fill="none" class="inline-block shrink-0 align-middle %s" width="%d" height="%d" aria-hidden="true">%s</svg>',
            htmlspecialchars($class, ENT_QUOTES, 'UTF-8'),
            $size,
            $size,
            $svgBody
        );
    }
}
