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
                'table' => self::compileTable($block),
                'image' => self::compileImage($block, $isFirstImage),
                'video', 'youtube' => self::compileVideo($block),
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
            'img', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
            'iframe'
        ];

        // Tags ativas/perigosas cujo nó e todo o conteúdo interno devem ser eliminados
        $dangerousTags = [
            'script', 'style', 'object', 'embed', 'applet', 'meta',
            'link', 'form', 'input', 'textarea', 'button', 'select', 'option',
            'svg', 'math', 'base', 'colgroup', 'col'
        ];

        // Whitelist estrita de atributos autorizados por tag
        $allowedAttributes = [
            'a' => ['href', 'target', 'rel', 'title'],
            'img' => ['src', 'alt', 'width', 'height', 'loading', 'title', 'srcset'],
            'source' => ['srcset', 'media', 'type'],
            'blockquote' => ['cite'],
            'th' => ['colspan', 'rowspan', 'scope'],
            'td' => ['colspan', 'rowspan'],
            'figure' => ['class'],
            'iframe' => ['src', 'title', 'width', 'height', 'frameborder', 'allowfullscreen', 'loading', 'referrerpolicy', 'sandbox'],
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
        self::pruneEmptyDomNodes($root);

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
                        $safeUrl = ($tagName === 'img' && $attrName === 'src')
                            ? self::sanitizeImageUrl($val)
                            : self::sanitizeUrl($val);
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

                            $safeCandUrl = self::sanitizeImageUrl($candUrl);
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
                    } elseif (in_array($attrName, ['colspan', 'rowspan'], true)) {
                        $num = (int)$val;
                        if ($num > 1 && $num <= 50) {
                            $child->setAttribute($attrName, (string)$num);
                        } else {
                            $attrsToRemove[] = $attrName;
                        }
                    } elseif ($attrName === 'scope') {
                        $val = strtolower($val);
                        if (in_array($val, ['col', 'row', 'colgroup', 'rowgroup'], true)) {
                            $child->setAttribute('scope', $val);
                        } else {
                            $attrsToRemove[] = $attrName;
                        }
                    } elseif ($tagName === 'figure' && $attrName === 'class') {
                        $parts = preg_split('/\s+/', strtolower($val));
                        $safeClasses = array_intersect($parts, ['video-container', 'video-embed', 'align-center', 'align-left', 'align-right']);
                        if (empty($safeClasses)) {
                            $attrsToRemove[] = $attrName;
                        } else {
                            $child->setAttribute('class', implode(' ', $safeClasses));
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
                $target = strtolower($child->getAttribute('target'));
                $rel = $child->getAttribute('rel');
                $relParts = array_filter(explode(' ', strtolower($rel)));

                if ($target === '_blank') {
                    // Garante estritamente noopener e noreferrer contra reverse-tabnabbing (SEC-08)
                    if (!in_array('noopener', $relParts, true)) {
                        $relParts[] = 'noopener';
                    }
                    if (!in_array('noreferrer', $relParts, true)) {
                        $relParts[] = 'noreferrer';
                    }
                    $child->setAttribute('rel', implode(' ', $relParts));
                } elseif (empty($rel)) {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
            } elseif ($tagName === 'img') {
                $src = $child->getAttribute('src');
                if ($src === '') {
                    $node->removeChild($child);
                    continue;
                }
                $child->setAttribute('loading', 'lazy');
            } elseif ($tagName === 'iframe') {
                $src = $child->getAttribute('src');
                $ytId = self::extractYouTubeId($src);
                if ($ytId === null) {
                    $node->removeChild($child);
                    continue;
                }

                $startSec = self::extractYouTubeStartTime($src);
                $embedSrc = "https://www.youtube-nocookie.com/embed/{$ytId}";
                if ($startSec !== null && $startSec > 0) {
                    $embedSrc .= "?start={$startSec}";
                }
                $child->setAttribute('src', $embedSrc);

                $title = trim($child->getAttribute('title'));
                $child->setAttribute('title', !empty($title) ? Security::e(strip_tags($title)) : 'Vídeo do YouTube');
                $child->setAttribute('frameborder', '0');
                $child->setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
                $child->setAttribute('allowfullscreen', 'allowfullscreen');
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                $child->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');

                // Garante que o iframe esteja encapsulado em <figure class="video-container">
                if (strtolower($node->nodeName) !== 'figure') {
                    $figure = $node->ownerDocument->createElement('figure');
                    $figure->setAttribute('class', 'video-container');
                    $node->replaceChild($figure, $child);
                    $figure->appendChild($child);
                } elseif (strtolower($node->nodeName) === 'figure' && !$node->hasAttribute('class')) {
                    $node->setAttribute('class', 'video-container');
                }
            }

            // Processar filhos recursivamente
            self::cleanDomNode($child, $allowedTags, $dangerousTags, $allowedAttributes);
        }
    }

    /**
     * Remove nós e quebras residuais vazios do DOM de baixo para cima (bottom-up),
     * garantindo a integridade semântica de parágrafos, listas e tabelas.
     */
    private static function pruneEmptyDomNodes(\DOMElement $root): void
    {
        // 1. Remover <br> que seja o último filho útil de blocos de texto ou células
        foreach (['p', 'h2', 'h3', 'h4', 'li', 'td', 'th'] as $tag) {
            $elements = iterator_to_array($root->getElementsByTagName($tag));
            foreach ($elements as $el) {
                while ($el->lastChild && $el->lastChild->nodeType === XML_TEXT_NODE && trim(str_replace(["\xc2\xa0", "\u{00A0}", "\u{200B}", '&nbsp;'], ' ', $el->lastChild->textContent)) === '') {
                    $el->removeChild($el->lastChild);
                }
                if ($el->lastChild && $el->lastChild->nodeType === XML_ELEMENT_NODE && strtolower($el->lastChild->nodeName) === 'br') {
                    $el->removeChild($el->lastChild);
                }
            }
        }

        // 2. Podar itens de lista (li) vazios
        $lis = iterator_to_array($root->getElementsByTagName('li'));
        foreach (array_reverse($lis) as $li) {
            if (self::isElementEmptyOfContentAndMedia($li)) {
                $li->parentNode?->removeChild($li);
            }
        }

        // 3. Podar listas vazias (ul, ol) - validando contagem de <li> (ignora nós de texto/quebras de indentação)
        foreach (['ul', 'ol'] as $listTag) {
            $lists = iterator_to_array($root->getElementsByTagName($listTag));
            foreach (array_reverse($lists) as $list) {
                if ($list->getElementsByTagName('li')->length === 0) {
                    $list->parentNode?->removeChild($list);
                }
            }
        }

        // 4. Podar linhas de tabela (tr) onde TODAS as células sejam vazias
        // (preservando células vazias isoladas em linhas válidas com outros dados)
        $trs = iterator_to_array($root->getElementsByTagName('tr'));
        foreach (array_reverse($trs) as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && in_array(strtolower($child->nodeName), ['th', 'td'], true)) {
                    $cells[] = $child;
                }
            }
            if (empty($cells)) {
                $tr->parentNode?->removeChild($tr);
                continue;
            }
            $allCellsEmpty = true;
            foreach ($cells as $cell) {
                if ($cell instanceof \DOMElement && !self::isElementEmptyOfContentAndMedia($cell)) {
                    $allCellsEmpty = false;
                    break;
                }
            }
            if ($allCellsEmpty) {
                $tr->parentNode?->removeChild($tr);
            }
        }

        // 5. Podar seções de tabela (thead, tbody, tfoot) vazias - validando contagem de <tr>
        foreach (['thead', 'tbody', 'tfoot'] as $secTag) {
            $sections = iterator_to_array($root->getElementsByTagName($secTag));
            foreach (array_reverse($sections) as $sec) {
                if ($sec->getElementsByTagName('tr')->length === 0) {
                    $sec->parentNode?->removeChild($sec);
                }
            }
        }

        // 6. Podar tabelas vazias (table) - validando contagem de <tr>
        $tables = iterator_to_array($root->getElementsByTagName('table'));
        foreach (array_reverse($tables) as $table) {
            if ($table->getElementsByTagName('tr')->length === 0) {
                $table->parentNode?->removeChild($table);
            }
        }

        // 7. Podar elementos de bloco de texto vazios (p, h2, h3, h4, blockquote, caption)
        foreach (['p', 'h2', 'h3', 'h4', 'blockquote', 'caption'] as $tag) {
            $blocks = iterator_to_array($root->getElementsByTagName($tag));
            foreach (array_reverse($blocks) as $block) {
                if (self::isElementEmptyOfContentAndMedia($block)) {
                    $block->parentNode?->removeChild($block);
                }
            }
        }

        // 8. Remover <br> soltos que sejam filhos diretos da raiz
        $directChildren = iterator_to_array($root->childNodes);
        foreach ($directChildren as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'br') {
                $root->removeChild($child);
            }
        }
    }

    /**
     * Verifica se um elemento DOM está desprovido de texto significativo e elementos multimídia.
     */
    private static function isElementEmptyOfContentAndMedia(\DOMElement $element): bool
    {
        foreach (['img', 'picture', 'figure', 'table', 'hr', 'iframe', 'video', 'audio', 'source'] as $mTag) {
            if ($element->getElementsByTagName($mTag)->length > 0) {
                return false;
            }
        }

        $text = $element->textContent;
        $normalized = str_replace(["\xc2\xa0", "\u{00A0}", "\u{200B}", '&nbsp;', "\t", "\n", "\r"], ' ', $text);
        return trim($normalized) === '';
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
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="lp-blocks-root">' . $sanitized . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementById('lp-blocks-root');
        if (!$root) {
            return '[]';
        }

        $blocks = [];
        foreach ($root->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
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
                    $innerHtml .= (string)$dom->saveHTML($child);
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
            } elseif ($tag === 'table') {
                $tableHtml = (string)$dom->saveHTML($node);
                $blocks[] = ['type' => 'table', 'content' => trim($tableHtml)];
            } elseif ($tag === 'figure') {
                $iframes = $node->getElementsByTagName('iframe');
                if ($iframes->length > 0) {
                    $iframe = $iframes->item(0);
                    if ($iframe instanceof \DOMElement) {
                        $src = $iframe->getAttribute('src');
                        $ytId = self::extractYouTubeId($src);
                        if (!empty($ytId)) {
                            $captionNode = $node->getElementsByTagName('figcaption');
                            $captionEl = $captionNode->length > 0 ? $captionNode->item(0) : null;
                            $caption = $captionEl !== null ? trim($captionEl->textContent) : '';
                            $start = self::extractYouTubeStartTime($src);
                            $blockData = [
                                'type' => 'video',
                                'provider' => 'youtube',
                                'video_id' => $ytId,
                                'url' => "https://www.youtube-nocookie.com/embed/{$ytId}",
                                'caption' => $caption,
                            ];
                            if ($start !== null && $start > 0) {
                                $blockData['start'] = $start;
                            }
                            $blocks[] = $blockData;
                            continue;
                        }
                    }
                }
                $imgs = $node->getElementsByTagName('img');
                if ($imgs->length > 0) {
                    $img = $imgs->item(0);
                    if ($img instanceof \DOMElement) {
                        $url = $img->getAttribute('src');
                        $alt = $img->getAttribute('alt');
                        $captionNode = $node->getElementsByTagName('figcaption');
                        $captionEl = $captionNode->length > 0 ? $captionNode->item(0) : null;
                        $caption = $captionEl !== null ? trim($captionEl->textContent) : '';
                        $blocks[] = ['type' => 'image', 'url' => $url, 'caption' => $caption, 'alt' => $alt];
                    }
                }
            } elseif ($tag === 'iframe') {
                $src = $node->getAttribute('src');
                $ytId = self::extractYouTubeId($src);
                if (!empty($ytId)) {
                    $start = self::extractYouTubeStartTime($src);
                    $blockData = [
                        'type' => 'video',
                        'provider' => 'youtube',
                        'video_id' => $ytId,
                        'url' => "https://www.youtube-nocookie.com/embed/{$ytId}",
                        'caption' => '',
                    ];
                    if ($start !== null && $start > 0) {
                        $blockData['start'] = $start;
                    }
                    $blocks[] = $blockData;
                }
            } elseif ($tag === 'img') {
                $url = $node->getAttribute('src');
                $alt = $node->getAttribute('alt');
                $blocks[] = ['type' => 'image', 'url' => $url, 'caption' => $alt];
            }
        }

        if (empty($blocks)) {
            $blocks[] = ['type' => 'paragraph', 'content' => $sanitized];
        }

        return json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
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
     * Compila tabelas estruturadas ou HTML sanitizado.
     */
    private static function compileTable(array $block): string
    {
        // 1. Se possuir conteúdo HTML direto
        if (!empty($block['content']) || !empty($block['html'])) {
            $rawHtml = (string)($block['content'] ?? $block['html']);
            return self::sanitizeHtml($rawHtml);
        }

        // 2. Se for estrutura de linhas / cabeçalhos de API headless
        $rows = $block['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            return '';
        }

        $headers = $block['headers'] ?? [];
        $tableHtml = ["<table>"];

        if (is_array($headers) && !empty($headers)) {
            $tableHtml[] = "  <thead>\n    <tr>";
            foreach ($headers as $h) {
                $safeH = self::sanitizeInline((string)$h);
                $tableHtml[] = "      <th>{$safeH}</th>";
            }
            $tableHtml[] = "    </tr>\n  </thead>";
        }

        $tableHtml[] = "  <tbody>";
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row)) {
                continue;
            }
            $tableHtml[] = "    <tr>";
            foreach ($row as $cell) {
                $safeCell = self::sanitizeInline((string)$cell);
                $tableHtml[] = "      <td>{$safeCell}</td>";
            }
            $tableHtml[] = "    </tr>";
        }
        $tableHtml[] = "  </tbody>";
        $tableHtml[] = "</table>";

        return self::sanitizeHtml(implode("\n", $tableHtml));
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
        if (empty($rawAlt) && !empty($url)) {
            $rawAlt = ImageProcessor::getImageDescription($url);
        }
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
                        $hoverText = !empty($rawAlt) ? $rawAlt : (!empty($block['caption']) ? strip_tags((string)$block['caption']) : '');
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

        $hoverText = !empty($rawAlt) ? $rawAlt : (!empty($block['caption']) ? strip_tags((string)$block['caption']) : '');
        $hoverAttr = Security::e(trim($hoverText));

        $html = "<figure>";
        $html .= "<img src=\"{$url}\" alt=\"{$altAttr}\" title=\"{$hoverAttr}\"{$lazyAttr}>";
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
        $url = htmlspecialchars_decode(trim($url), ENT_QUOTES | ENT_SUBSTITUTE);
        if ($url === '') {
            return '';
        }

        // Bloquear caracteres de controle, quebras de linha e tabulações (bypasses em parsers)
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return '';
        }

        // Bloquear espaços, aspas, crases e delimitadores de tags (< >)
        if (preg_match('/[\s<>"\'`]/', $url)) {
            return '';
        }

        // Bloquear caminhos protocol-relative (//evil.com) ou barra invertida (/\evil.com)
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return '';
        }

        // Caminhos internos seguros iniciando com /
        if (str_starts_with($url, '/')) {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        }

        // Apenas http:// e https:// autorizados para imagens
        if (stripos($url, 'https://') === 0 || stripos($url, 'http://') === 0) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return '';
            }
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        }

        return '';
    }

    /**
     * Valida esquemas de URLs autorizados (https, http, mailto, tel ou caminhos relativos internos).
     * Rejeita caracteres de controle, caminhos protocol-relative (//) e esquemas perigosos (javascript:, data:).
     */
    public static function sanitizeUrl(string $url): string
    {
        $url = htmlspecialchars_decode(trim($url), ENT_QUOTES | ENT_SUBSTITUTE);
        if ($url === '') {
            return '';
        }

        // Bloquear caracteres de controle, quebras de linha e tabulações (bypasses em parsers)
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return '';
        }

        // Bloquear espaços, aspas, crases e delimitadores de tags (< >)
        if (preg_match('/[\s<>"\'`]/', $url)) {
            return '';
        }

        // Bloquear caminhos protocol-relative (//evil.com)
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return '';
        }

        // Âncoras internas seguras (#servicos, #topo, #secao-1)
        if (str_starts_with($url, '#')) {
            if (preg_match('/^#[a-zA-Z0-9_\-\.\:\/]*$/', $url)) {
                return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            }
            return '';
        }

        // Caminhos internos seguros iniciando com /
        if (str_starts_with($url, '/')) {
            return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
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
                return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            }
        }

        return '';
    }

    /**
     * Compila bloco de vídeo (YouTube) para elemento semântico e responsivo <figure class="video-container">.
     */
    private static function compileVideo(array $block): string
    {
        $url = trim($block['url'] ?? '');
        $videoId = trim($block['video_id'] ?? '');
        if (empty($videoId) && !empty($url)) {
            $videoId = self::extractYouTubeId($url);
        }
        if (empty($videoId) || !preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId)) {
            return '';
        }

        $startSec = !empty($block['start']) && is_numeric($block['start'])
            ? (int)$block['start']
            : self::extractYouTubeStartTime($url);

        $embedUrl = "https://www.youtube-nocookie.com/embed/{$videoId}";
        if ($startSec !== null && $startSec > 0) {
            $embedUrl .= "?start={$startSec}";
        }

        $caption = !empty($block['caption']) ? self::sanitizeInline((string)$block['caption']) : '';

        $html = '<figure class="video-container">';
        $html .= '<iframe src="' . $embedUrl . '" title="Vídeo do YouTube" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>';
        if (!empty($caption)) {
            $html .= '<figcaption>' . $caption . '</figcaption>';
        }
        $html .= '</figure>';

        return $html;
    }

    /**
     * Extrai o ID único de 11 caracteres de qualquer URL ou embed do YouTube.
     * Retorna null se a URL não pertencer a um domínio autorizado do YouTube ou não tiver ID válido.
     */
    public static function extractYouTubeId(string $url): ?string
    {
        $url = htmlspecialchars_decode(trim($url), ENT_QUOTES | ENT_SUBSTITUTE);
        if ($url === '') {
            return null;
        }

        // Se for tag iframe completa
        if (str_contains($url, '<iframe')) {
            if (preg_match('/src=["\']([^"\']+)["\']/i', $url, $m)) {
                $url = $m[1];
            }
        }

        // Limpeza de aspas residuais ou colchetes angulares
        $url = trim($url, " \t\n\r\0\x0B<>\"'");

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            $parsed = parse_url('https://' . ltrim($url, '/'));
        }

        if (!$parsed || empty($parsed['host'])) {
            return null;
        }

        $host = strtolower($parsed['host']);
        $path = $parsed['path'] ?? '';

        // Validação estrita de domínios autorizados do YouTube
        $isYouTubeHost = (
            $host === 'youtu.be' ||
            $host === 'youtube.com' ||
            str_ends_with($host, '.youtube.com') ||
            $host === 'youtube-nocookie.com' ||
            str_ends_with($host, '.youtube-nocookie.com')
        );

        if (!$isYouTubeHost) {
            return null;
        }

        // 1. youtu.be/VIDEO_ID
        if ($host === 'youtu.be') {
            $trimmedPath = trim($path, '/');
            $parts = explode('/', $trimmedPath);
            $id = $parts[0] ?? '';
            if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $id)) {
                return $id;
            }
            return null;
        }

        // 2. youtube.com / youtube-nocookie.com
        // /watch?v=VIDEO_ID (ou com outros parâmetros)
        if ($path === '/watch' && !empty($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            $v = $queryParams['v'] ?? '';
            if (is_string($v) && preg_match('/^[a-zA-Z0-9_-]{11}$/', $v)) {
                return $v;
            }
        }

        // /embed/ID, /shorts/ID, /live/ID, /v/ID
        if (preg_match('#^/(?:embed|shorts|live|v)/([a-zA-Z0-9_-]{11})#i', $path, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extrai o tempo de início do vídeo (em segundos) caso informado na URL (parâmetros t ou start).
     */
    public static function extractYouTubeStartTime(string $url): ?int
    {
        $url = htmlspecialchars_decode(trim($url), ENT_QUOTES | ENT_SUBSTITUTE);
        $parsed = parse_url($url);
        if (!$parsed) {
            return null;
        }

        $query = $parsed['query'] ?? '';
        parse_str($query, $params);

        $t = $params['t'] ?? $params['start'] ?? null;
        if ($t === null) {
            return null;
        }

        $tStr = trim((string)$t);
        if (is_numeric($tStr)) {
            $sec = (int)$tStr;
            return $sec > 0 ? $sec : null;
        }

        // Formato como 2m15s ou 1h20m30s
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i', $tStr, $matches)) {
            $h = (int)($matches[1] ?? 0);
            $m = (int)($matches[2] ?? 0);
            $s = (int)($matches[3] ?? 0);
            $total = ($h * 3600) + ($m * 60) + $s;
            return $total > 0 ? $total : null;
        }

        return null;
    }
}
