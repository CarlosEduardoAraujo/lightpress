<?php
declare(strict_types=1);

namespace LightPress\Core;

use InvalidArgumentException;

/**
 * Compilador Semântico e Sanitizador de Blocos JSON -> HTML5
 * 
 * Atua como um portão estrito de segurança (Security Gate), transformando
 * a representação JSON em HTML semântico limpo, seguro e responsivo.
 * Rejeita qualquer tag ou atributo perigoso (XSS, onload=, javascript:, etc.).
 */
class BlockCompiler
{
    /**
     * Compila uma estrutura JSON de blocos ou HTML rico para HTML5 seguro.
     *
     * @param string|array $input JSON string, array já decodificado ou HTML do editor
     * @return string HTML5 sanitizado
     */
    public static function compile(string|array $input): string
    {
        if (is_string($input)) {
            $trimmed = trim($input);
            if (!str_starts_with($trimmed, '[') && !str_starts_with($trimmed, '{')) {
                // Conteúdo enviado diretamente como HTML semântico do editor rico
                return self::sanitizeHtml($trimmed);
            }
            $data = json_decode($input, true);
            if (!is_array($data)) {
                return self::sanitizeHtml($input);
            }
        } else {
            $data = $input;
        }

        // Suporta tanto array direto de blocos quanto payload com versionamento: ['blocks' => [...]]
        $blocks = $data['blocks'] ?? $data;
        if (!is_array($blocks)) {
            return '';
        }

        $html = [];
        $isFirstImage = true;

        foreach ($blocks as $block) {
            if (!is_array($block) || empty($block['type'])) {
                continue;
            }

            $compiled = match ($block['type']) {
                'heading' => self::compileHeading($block),
                'paragraph' => self::compileParagraph($block),
                'list' => self::compileList($block),
                'quote' => self::compileQuote($block),
                'image' => self::compileImage($block, $isFirstImage),
                default => '', // Blocos desconhecidos são sumariamente ignorados
            };

            if (!empty($compiled)) {
                $html[] = $compiled;
                if ($block['type'] === 'image') {
                    $isFirstImage = false;
                }
            }
        }

        return implode("\n", $html);
    }

    /**
     * Sanitiza conteúdo HTML permitindo apenas tags semânticas limpas e eliminando classes,
     * estilos sujos do Word/Google Docs e qualquer vetor de script malicioso (XSS).
     */
    public static function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // 1. Remover tags proibidas e vetores ativos
        $clean = preg_replace('~<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>~i', '', $html);
        $clean = preg_replace('~<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>~i', '', $clean);
        $clean = preg_replace('~<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>~i', '', $clean);
        $clean = preg_replace('~<form\b[^<]*(?:(?!<\/form>)<[^<]*)*<\/form>~i', '', $clean);

        // 2. Filtrar apenas tags semânticas do jornalismo moderno
        $allowedTags = '<h2><h3><h4><p><br><hr><strong><b><em><i><u><s><blockquote><cite><ul><ol><li><a><figure><picture><source><img><figcaption>';
        $clean = strip_tags($clean, $allowedTags);

        // 3. Remover atributos de eventos (on*), estilos inline (MsoNormal, font-size, etc.) e IDs
        $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/\s+class\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
        $clean = preg_replace('/\s+id\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);

        // 4. Sanitizar links <a> para evitar javascript: e adicionar rel="noopener noreferrer"
        $clean = preg_replace_callback('~<a\s+([^>]+)>(.*?)</a>~i', function (array $m): string {
            $attributes = $m[1];
            $linkText = $m[2];

            if (preg_match('~href=[\'"]([^\'"]+)[\'"]~i', $attributes, $hrefMatch)) {
                $safeHref = self::sanitizeUrl($hrefMatch[1]);
                if (!empty($safeHref)) {
                    return "<a href=\"{$safeHref}\" rel=\"noopener noreferrer\" target=\"_blank\">{$linkText}</a>";
                }
            }
            return $linkText;
        }, $clean);

        // 5. Sanitizar tags <img> para garantir URLs válidas e lazy loading
        $clean = preg_replace_callback('~<img\s+([^>]+)>~i', function (array $m): string {
            $attributes = $m[1];
            $src = '';
            $alt = '';

            if (preg_match('~src=[\'"]([^\'"]+)[\'"]~i', $attributes, $srcMatch)) {
                $src = self::sanitizeUrl($srcMatch[1]);
            }
            if (preg_match('~alt=[\'"]([^\'"]*)[\'"]~i', $attributes, $altMatch)) {
                $alt = htmlspecialchars($altMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            if (empty($src)) {
                return '';
            }

            return "<img src=\"{$src}\" alt=\"{$alt}\" loading=\"lazy\">";
        }, $clean);

        return trim($clean);
    }

    /**
     * Converte HTML rico em blocos estruturados JSON para preservação de integridade em APIs headless.
     */
    public static function htmlToBlocks(string $html): string
    {
        $sanitized = self::sanitizeHtml($html);
        if ($sanitized === '') {
            return '[]';
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $sanitized, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $blocks = [];
        if ($dom->childNodes) {
            foreach ($dom->childNodes as $node) {
                if ($node->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }

                $tag = strtolower($node->nodeName);
                if ($tag === 'h2') {
                    $blocks[] = ['type' => 'heading', 'level' => 2, 'content' => trim($node->textContent)];
                } elseif ($tag === 'h3') {
                    $blocks[] = ['type' => 'heading', 'level' => 3, 'content' => trim($node->textContent)];
                } elseif ($tag === 'p') {
                    $innerHtml = '';
                    foreach ($node->childNodes as $child) {
                        $innerHtml .= $node->ownerDocument->saveHTML($child);
                    }
                    $trimmed = trim($innerHtml);
                    if ($trimmed !== '') {
                        $blocks[] = ['type' => 'paragraph', 'content' => $trimmed];
                    }
                } elseif ($tag === 'blockquote') {
                    $blocks[] = ['type' => 'quote', 'content' => trim($node->textContent)];
                } elseif ($tag === 'ul' || $tag === 'ol') {
                    $items = [];
                    foreach ($node->childNodes as $li) {
                        if ($li->nodeName === 'li') {
                            $items[] = trim($li->textContent);
                        }
                    }
                    if (!empty($items)) {
                        $blocks[] = ['type' => 'list', 'style' => $tag === 'ol' ? 'ordered' : 'bullet', 'items' => $items];
                    }
                } elseif ($tag === 'figure') {
                    $imgs = $node->getElementsByTagName('img');
                    if ($imgs->length > 0) {
                        $img = $imgs->item(0);
                        $url = $img->getAttribute('src');
                        $captionNode = $node->getElementsByTagName('figcaption');
                        $caption = $captionNode->length > 0 ? trim($captionNode->item(0)->textContent) : '';
                        $blocks[] = ['type' => 'image', 'url' => $url, 'caption' => $caption];
                    }
                } elseif ($tag === 'img') {
                    $url = $node->getAttribute('src');
                    $alt = $node->getAttribute('alt');
                    $blocks[] = ['type' => 'image', 'url' => $url, 'caption' => $alt];
                }
            }
        }

        if (empty($blocks)) {
            $blocks[] = ['type' => 'paragraph', 'content' => $sanitized];
        }

        return json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Compila títulos (h2 e h3).
     */
    private static function compileHeading(array $block): string
    {
        $level = (int)($block['level'] ?? 2);
        $tag = ($level === 3) ? 'h3' : 'h2'; // Apenas h2 e h3 permitidos no corpo
        $text = self::sanitizeInline($block['content'] ?? $block['text'] ?? '');

        return "<{$tag}>{$text}</{$tag}>";
    }

    /**
     * Compila parágrafos.
     */
    private static function compileParagraph(array $block): string
    {
        $text = self::sanitizeInline($block['content'] ?? $block['text'] ?? '');
        if (trim($text) === '') {
            return '';
        }
        return "<p>{$text}</p>";
    }

    /**
     * Compila listas (ordenadas ou não ordenadas).
     */
    private static function compileList(array $block): string
    {
        $style = ($block['style'] ?? '') === 'ordered' ? 'ol' : 'ul';
        $items = $block['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            return '';
        }

        $listHtml = ["<{$style}>"];
        foreach ($items as $item) {
            $content = is_array($item) ? ($item['content'] ?? '') : (string)$item;
            $text = self::sanitizeInline($content);
            if (trim($text) !== '') {
                $listHtml[] = "  <li>{$text}</li>";
            }
        }
        $listHtml[] = "</{$style}>";

        return implode("\n", $listHtml);
    }

    /**
     * Compila citações (blockquote).
     */
    private static function compileQuote(array $block): string
    {
        $text = self::sanitizeInline($block['text'] ?? $block['content'] ?? '');
        $caption = self::sanitizeInline($block['caption'] ?? '');

        $html = "<blockquote><p>{$text}</p>";
        if (!empty($caption)) {
            $html .= "<cite>{$caption}</cite>";
        }
        $html .= "</blockquote>";

        return $html;
    }

    /**
     * Compila imagens com suporte nativo a <picture>, AVIF, WebP e lazy loading.
     * Conforme seção 4.5 e 6.1 do README: se 'media_id' for fornecido, consulta o manifesto
     * confiável gravado no servidor para montar as tags <source> com srcset real.
     */
    private static function compileImage(array $block, bool $isFirstImage): string
    {
        $mediaId = trim($block['media_id'] ?? '');
        $url = trim($block['url'] ?? '');
        $caption = self::sanitizeInline($block['caption'] ?? '');
        $alt = self::sanitizeInline($block['alt'] ?? $caption);
        $lazyAttr = $isFirstImage ? '' : ' loading="lazy"';

        // 1. Tentar renderizar a partir do catálogo confiável de mídias (media_id)
        if (!empty($mediaId)) {
            try {
                $media = Database::fetchOne(
                    "SELECT variants_manifest, original_name FROM media WHERE id = ? LIMIT 1",
                    [$mediaId]
                );

                if ($media) {
                    $variants = json_decode($media['variants_manifest'], true) ?? [];
                    $avifSrcset = [];
                    $webpSrcset = [];
                    $defaultSrc = '';

                    // Resoluções disponíveis
                    foreach (['full', 'intermediate', 'thumb'] as $resKey) {
                        if (!empty($variants[$resKey]['avif']['url'])) {
                            $avifSrcset[] = "{$variants[$resKey]['avif']['url']} {$variants[$resKey]['avif']['width']}w";
                        }
                        if (!empty($variants[$resKey]['webp']['url'])) {
                            $webpSrcset[] = "{$variants[$resKey]['webp']['url']} {$variants[$resKey]['webp']['width']}w";
                            if (empty($defaultSrc) || $resKey === 'intermediate') {
                                $defaultSrc = $variants[$resKey]['webp']['url'];
                            }
                        }
                    }

                    if (!empty($defaultSrc)) {
                        $html = "<figure>\n  <picture>\n";
                        if (!empty($avifSrcset)) {
                            $html .= '    <source type="image/avif" srcset="' . implode(', ', $avifSrcset) . "\">\n";
                        }
                        if (!empty($webpSrcset)) {
                            $html .= '    <source type="image/webp" srcset="' . implode(', ', $webpSrcset) . "\">\n";
                        }
                        $html .= "    <img src=\"{$defaultSrc}\" alt=\"{$alt}\"{$lazyAttr}>\n";
                        $html .= "  </picture>";
                        if (!empty($caption)) {
                            $html .= "\n  <figcaption>{$caption}</figcaption>";
                        }
                        $html .= "\n</figure>";
                        return $html;
                    }
                }
            } catch (\Throwable $e) {
                // Em caso de falha de banco no compilador, prossegue para o fallback por URL
            }
        }

        // 2. Fallback por URL direta
        if (empty($url)) {
            return '';
        }

        // Sanitização estrita de URL da imagem
        $url = self::sanitizeUrl($url);
        if (empty($url)) {
            return '';
        }

        $html = "<figure>";
        $html .= "<img src=\"{$url}\" alt=\"{$alt}\"{$lazyAttr}>";
        if (!empty($caption)) {
            $html .= "<figcaption>{$caption}</figcaption>";
        }
        $html .= "</figure>";

        return $html;
    }

    /**
     * Sanitiza formatação inline permitindo somente tags seguras (strong, em, a).
     */
    private static function sanitizeInline(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        // Remove tags perigosas e permite apenas tags semânticas básicas
        $stripped = strip_tags($text, '<strong><b><em><i><a>');

        // Tratamento seguro de links <a> para evitar javascript: e target abusivo
        return preg_replace_callback('~<a\s+([^>]+)>(.*?)</a>~i', function (array $m): string {
            $attributes = $m[1];
            $linkText = $m[2];

            if (preg_match('~href=[\'"]([^\'"]+)[\'"]~i', $attributes, $hrefMatch)) {
                $safeHref = self::sanitizeUrl($hrefMatch[1]);
                if (!empty($safeHref)) {
                    return "<a href=\"{$safeHref}\" rel=\"noopener noreferrer\">{$linkText}</a>";
                }
            }
            return $linkText;
        }, $stripped) ?? '';
    }

    /**
     * Valida esquemas de URLs autorizados (https, http, mailto ou caminhos relativos internos).
     */
    public static function sanitizeUrl(string $url): string
    {
        $url = trim($url);

        // Caminhos internos
        if (str_starts_with($url, '/')) {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Esquemas permitidos
        $allowedProtocols = ['http://', 'https://', 'mailto:'];
        foreach ($allowedProtocols as $protocol) {
            if (stripos($url, $protocol) === 0) {
                return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return '';
    }
}
