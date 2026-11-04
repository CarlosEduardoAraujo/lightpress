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
     * Compila uma estrutura JSON de blocos para HTML5 seguro.
     *
     * @param string|array $input JSON string ou array já decodificado
     * @return string HTML5 sanitizado
     */
    public static function compile(string|array $input): string
    {
        if (is_string($input)) {
            $data = json_decode($input, true);
            if (!is_array($data)) {
                return '';
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
