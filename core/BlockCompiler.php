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
     * Utiliza DOMDocument com whitelist estrita de tags e atributos (imune a evasões de parser/regex).
     */
    public static function sanitizeHtml(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        // Whitelist estrita de tags autorizadas no jornalismo e páginas
        $allowedTags = [
            'h2', 'h3', 'h4', 'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's',
            'blockquote', 'cite', 'ul', 'ol', 'li', 'a', 'figure', 'picture', 'source',
            'img', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td'
        ];

        // Tags ativas/perigosas cujo nó e todo o conteúdo interno devem ser eliminados
        $dangerousTags = [
            'script', 'style', 'iframe', 'object', 'embed', 'applet', 'meta',
            'link', 'form', 'input', 'textarea', 'button', 'select', 'option',
            'svg', 'math', 'base'
        ];

        // Whitelist estrita de atributos autorizados por tag
        $allowedAttributes = [
            'a' => ['href', 'target', 'rel', 'title'],
            'img' => ['src', 'alt', 'width', 'height', 'loading', 'title', 'srcset'],
            'source' => ['srcset', 'media', 'type'],
            'blockquote' => ['cite'],
            'th' => ['colspan', 'rowspan', 'scope'],
            'td' => ['colspan', 'rowspan'],
        ];

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        // Container com declaração UTF-8 explícita para evitar má interpretação de acentuação
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="lp-sanitizer-root">' . $trimmed . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementById('lp-sanitizer-root');
        if (!$root) {
            return '';
        }

        self::cleanDomNode($root, $allowedTags, $dangerousTags, $allowedAttributes);

        $cleanHtml = '';
        foreach ($root->childNodes as $child) {
            $cleanHtml .= $dom->saveHTML($child);
        }

        return trim($cleanHtml);
    }

    /**
     * Limpa recursivamente nós do DOMDocument aplicando whitelist de tags e atributos.
     *
     * @param \DOMNode $node
     * @param string[] $allowedTags
     * @param string[] $dangerousTags
     * @param array<string, string[]> $allowedAttributes
     */
    private static function cleanDomNode(\DOMNode $node, array $allowedTags, array $dangerousTags, array $allowedAttributes): void
    {
        $children = [];
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $node->removeChild($child);
                continue;
            }

            /** @var \DOMElement $child */
            $tagName = strtolower($child->nodeName);

            // 1. Tags perigosas -> remover nó e conteúdo
            if (in_array($tagName, $dangerousTags, true)) {
                $node->removeChild($child);
                continue;
            }

            // 2. Tags não autorizadas -> desembrulhar conteúdo (manter texto/filhos, remover tag)
            if (!in_array($tagName, $allowedTags, true)) {
                self::cleanDomNode($child, $allowedTags, $dangerousTags, $allowedAttributes);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            // 3. Tag permitida -> inspecionar e filtrar atributos
            if ($child->hasAttributes()) {
                $attrsToRemove = [];
                $validAttrs = $allowedAttributes[$tagName] ?? [];

                foreach ($child->attributes as $attr) {
                    $attrName = strtolower($attr->nodeName);

                    // Rejeitar qualquer atributo fora da whitelist (style, id, class, on*, etc.)
                    if (!in_array($attrName, $validAttrs, true)) {
                        $attrsToRemove[] = $attrName;
                        continue;
                    }

                    $val = trim($attr->nodeValue ?? '');

                    if (in_array($attrName, ['href', 'src', 'cite'], true)) {
                        $safeUrl = self::sanitizeUrl($val);
                        if ($safeUrl === '') {
                            $attrsToRemove[] = $attrName;
                        } else {
                            // DOMDocument::setAttribute faz o escape nativo ao salvar (saveHTML).
                            // Decodifica entidades prévias (ex: &amp;) para evitar duplo escape (&amp;amp;).
                            $child->setAttribute($attrName, html_entity_decode($safeUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        }
                    } elseif ($attrName === 'srcset') {
                        // Validação estrita de cada candidato em srcset (ex: "/img.webp 1024w, /img-2x.webp 2x")
                        $candidates = explode(',', $val);
                        $safeCandidates = [];
                        foreach ($candidates as $cand) {
                            $cand = trim($cand);
                            if ($cand === '') {
                                continue;
                            }
                            $parts = preg_split('/\s+/', $cand, 2);
                            $candUrl = $parts[0] ?? '';
                            $candDesc = $parts[1] ?? '';

                            $safeCandUrl = self::sanitizeUrl($candUrl);
                            if ($safeCandUrl !== '') {
                                $rawCandUrl = html_entity_decode($safeCandUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                // Validar descritor para conter apenas especificações válidas (ex: 1024w, 2x, 1.5x)
                                if ($candDesc !== '' && !preg_match('/^[0-9.]+[wx]$/i', $candDesc)) {
                                    continue;
                                }
                                $safeCandidates[] = $candDesc !== '' ? "{$rawCandUrl} {$candDesc}" : $rawCandUrl;
                            }
                        }

                        if (empty($safeCandidates)) {
                            $attrsToRemove[] = $attrName;
                        } else {
                            $child->setAttribute('srcset', implode(', ', $safeCandidates));
                        }
                    } elseif ($attrName === 'target') {
                        $val = strtolower($val);
                        if (!in_array($val, ['_blank', '_self'], true)) {
                            $attrsToRemove[] = $attrName;
                        } else {
                            $child->setAttribute('target', $val);
                        }
                    } elseif ($attrName === 'rel') {
                        $parts = array_filter(explode(' ', strtolower($val)));
                        $safeRel = array_intersect($parts, ['noopener', 'noreferrer', 'nofollow']);
                        if (empty($safeRel)) {
                            $attrsToRemove[] = $attrName;
                        } else {
                            $child->setAttribute('rel', implode(' ', $safeRel));
                        }
                    }
                }

                foreach ($attrsToRemove as $removeName) {
                    $child->removeAttribute($removeName);
                }
            }

            // Regras obrigatórias específicas
            if ($tagName === 'a') {
                $href = $child->getAttribute('href');
                if ($href === '') {
                    // Higieniza recursivamente os nós descendentes ANTES de desembrulhar
                    // para evitar que tags/atributos perigosos escapem da sanitização (LP-02)
                    self::cleanDomNode($child, $allowedTags, $dangerousTags, $allowedAttributes);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                $rel = $child->getAttribute('rel');
                if (empty($rel)) {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
            } elseif ($tagName === 'img') {
                $src = $child->getAttribute('src');
                if ($src === '') {
                    $node->removeChild($child);
                    continue;
                }
                $child->setAttribute('loading', 'lazy');
            }

            // Processar filhos recursivamente
            self::cleanDomNode($child, $allowedTags, $dangerousTags, $allowedAttributes);
        }
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
                        $alt = $img->getAttribute('alt');
                        $captionNode = $node->getElementsByTagName('figcaption');
                        $caption = $captionNode->length > 0 ? trim($captionNode->item(0)->textContent) : '';
                        $blocks[] = ['type' => 'image', 'url' => $url, 'caption' => $caption, 'alt' => $alt];
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
        $captionHtml = self::sanitizeInline($block['caption'] ?? '');
        
        // Escape contextual estrito para atributo alt (LP-03)
        $rawAlt = !empty($block['alt']) ? (string)$block['alt'] : strip_tags($block['caption'] ?? '');
        $altAttr = Security::e(trim(strip_tags($rawAlt)));
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
                            $vUrl = self::sanitizeImageUrl((string)$variants[$resKey]['avif']['url']);
                            $vWidth = (int)($variants[$resKey]['avif']['width'] ?? 0);
                            if (!empty($vUrl) && $vWidth > 0) {
                                $avifSrcset[] = "{$vUrl} {$vWidth}w";
                            }
                        }
                        if (!empty($variants[$resKey]['webp']['url'])) {
                            $vUrl = self::sanitizeImageUrl((string)$variants[$resKey]['webp']['url']);
                            $vWidth = (int)($variants[$resKey]['webp']['width'] ?? 0);
                            if (!empty($vUrl) && $vWidth > 0) {
                                $webpSrcset[] = "{$vUrl} {$vWidth}w";
                                if (empty($defaultSrc) || $resKey === 'intermediate') {
                                    $defaultSrc = $vUrl;
                                }
                            }
                        }
                    }

                    if (!empty($defaultSrc)) {
                        $hoverText = !empty($block['caption']) ? strip_tags((string)$block['caption']) : $rawAlt;
                        $hoverAttr = Security::e(trim($hoverText));
                        $html = "<figure>\n  <picture>\n";
                        if (!empty($avifSrcset)) {
                            $html .= '    <source type="image/avif" srcset="' . implode(', ', $avifSrcset) . "\">\n";
                        }
                        if (!empty($webpSrcset)) {
                            $html .= '    <source type="image/webp" srcset="' . implode(', ', $webpSrcset) . "\">\n";
                        }
                        $html .= "    <img src=\"{$defaultSrc}\" alt=\"{$altAttr}\" title=\"{$hoverAttr}\"{$lazyAttr}>\n";
                        $html .= "  </picture>";
                        $capText = !empty($captionHtml) ? $captionHtml : $altAttr;
                        if (!empty($capText)) {
                            $html .= "\n  <figcaption class=\"sr-only\">{$capText}</figcaption>";
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
        $url = self::sanitizeImageUrl($url);
        if (empty($url)) {
            return '';
        }

        $hoverText = !empty($block['caption']) ? strip_tags((string)$block['caption']) : $rawAlt;
        $hoverAttr = Security::e(trim($hoverText));

        $html = "<figure>";
        $html .= "<img src=\"{$url}\" alt=\"{$altAttr}\" title=\"{$hoverAttr}\"{$lazyAttr}>";
        $capText = !empty($captionHtml) ? $captionHtml : $altAttr;
        if (!empty($capText)) {
            $html .= "<figcaption class=\"sr-only\">{$capText}</figcaption>";
        }
        $html .= "</figure>";

        return $html;
    }

    /**
     * Sanitiza formatação inline permitindo somente tags seguras (strong, em, a).
     */
    private static function sanitizeInline(string $text): string
    {
        return self::sanitizeHtml($text);
    }

    /**
     * Valida e sanitiza estritamente URLs destinadas a imagens (featured_image, favicon, avatares, <img>, <source>).
     * Aceita exclusivamente caminhos internos seguros iniciados por / (sem // ou /\)
     * ou URLs absolutas com esquemas estritos https:// ou http://.
     * Rejeita caracteres de controle, mailto:, tel:, javascript:, data: e esquemas perigosos.
     */
    public static function sanitizeImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Bloquear caracteres de controle, quebras de linha e tabulações (bypasses em parsers)
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return '';
        }

        // Bloquear caminhos protocol-relative (//evil.com) ou barra invertida (/\evil.com)
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return '';
        }

        // Caminhos internos seguros iniciando com /
        if (str_starts_with($url, '/')) {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Apenas http:// e https:// autorizados para imagens
        if (stripos($url, 'https://') === 0 || stripos($url, 'http://') === 0) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return '';
            }
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return '';
    }

    /**
     * Valida esquemas de URLs autorizados (https, http, mailto, tel ou caminhos relativos internos).
     * Rejeita caracteres de controle, caminhos protocol-relative (//) e esquemas perigosos (javascript:, data:).
     */
    public static function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Bloquear caracteres de controle, quebras de linha e tabulações (bypasses em parsers)
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return '';
        }

        // Bloquear caminhos protocol-relative (//evil.com)
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return '';
        }

        // Caminhos internos seguros iniciando com /
        if (str_starts_with($url, '/')) {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        // Esquemas permitidos
        $allowedProtocols = ['https://', 'http://', 'mailto:', 'tel:'];
        foreach ($allowedProtocols as $protocol) {
            if (stripos($url, $protocol) === 0) {
                if (str_starts_with(strtolower($url), 'http')) {
                    if (!filter_var($url, FILTER_VALIDATE_URL)) {
                        return '';
                    }
                }
                return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return '';
    }
}
