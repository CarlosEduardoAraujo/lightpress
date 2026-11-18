<?php
declare(strict_types=1);

namespace LightPress\Plugin\ApiPublisher;

/**
 * Parser de Markdown Semântico Leve (Zero-Dependency)
 * 
 * Converte Markdown jornalístico gerado por automações/IA (n8n, feeds) para HTML semântico limpo.
 * Segue a regra do LightPress: converte '#' e '##' para <h2> (pois o H1 da página é reservado ao título).
 * O resultado é posteriormente submetido ao BlockCompiler::sanitizeHtml() e BlockCompiler::htmlToBlocks().
 */
final class MarkdownParser
{
    /**
     * Converte texto Markdown para HTML semântico.
     */
    public static function parse(string $markdown): string
    {
        $text = trim($markdown);
        if ($text === '') {
            return '';
        }

        // Normalizar quebras de linha para Unix (\n)
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Separar em blocos por linhas em branco consecutivas
        $rawBlocks = preg_split('/\n{2,}/', $text);
        if (!is_array($rawBlocks)) {
            $rawBlocks = [$text];
        }

        $htmlBlocks = [];

        foreach ($rawBlocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            // 1. Linha horizontal (--- ou ***)
            if (preg_match('/^(\-{3,}|\*{3,})$/', $block)) {
                $htmlBlocks[] = '<hr>';
                continue;
            }

            // 2. Cabeçalhos (# e ## -> h2, ### -> h3, #### -> h4)
            if (preg_match('/^(#{1,4})\s+(.+)$/m', $block, $matches)) {
                $level = strlen($matches[1]);
                $headingTag = match ($level) {
                    1, 2 => 'h2', // # e ## normalizados para h2 conforme regra editorial
                    3 => 'h3',
                    4 => 'h4',
                    default => 'h2',
                };
                $headingContent = self::parseInline(trim($matches[2]));
                $htmlBlocks[] = "<{$headingTag}>{$headingContent}</{$headingTag}>";
                continue;
            }

            // 3. Citação (Blockquote: linhas começando com '>')
            if (str_starts_with($block, '>')) {
                $quoteLines = [];
                $lines = explode("\n", $block);
                foreach ($lines as $line) {
                    $quoteLines[] = trim(preg_replace('/^>\s?/', '', $line) ?? $line);
                }
                $quoteContent = self::parseInline(implode(' ', $quoteLines));
                $htmlBlocks[] = "<blockquote><p>{$quoteContent}</p></blockquote>";
                continue;
            }

            // 4. Lista Não Ordenada (* ou -)
            if (preg_match('/^[\*\-]\s+/m', $block)) {
                $items = [];
                $lines = explode("\n", $block);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/^[\*\-]\s+(.+)$/', $line, $liMatch)) {
                        $items[] = '<li>' . self::parseInline(trim($liMatch[1])) . '</li>';
                    }
                }
                if (!empty($items)) {
                    $htmlBlocks[] = '<ul>' . implode('', $items) . '</ul>';
                    continue;
                }
            }

            // 5. Lista Ordenada (1., 2., etc.)
            if (preg_match('/^\d+\.\s+/m', $block)) {
                $items = [];
                $lines = explode("\n", $block);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/^\d+\.\s+(.+)$/', $line, $liMatch)) {
                        $items[] = '<li>' . self::parseInline(trim($liMatch[1])) . '</li>';
                    }
                }
                if (!empty($items)) {
                    $htmlBlocks[] = '<ol>' . implode('', $items) . '</ol>';
                    continue;
                }
            }

            // 6. Parágrafo padrão
            // Se houver quebras de linha simples internas dentro do parágrafo, transforma em espaço ou <br>
            $lines = explode("\n", $block);
            $paragraphContent = self::parseInline(implode(' ', array_map('trim', $lines)));
            $htmlBlocks[] = "<p>{$paragraphContent}</p>";
        }

        return implode("\n", $htmlBlocks);
    }

    /**
     * Processa elementos inline: negrito, itálico, links.
     */
    public static function parseInline(string $text): string
    {
        // 1. Links: [texto](url)
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function (array $m): string {
            $linkText = $m[1];
            $url = $m[2];
            // Sanitização básica de esquema de URL no inline (http, https ou relativo)
            if (!preg_match('/^(https?:\/\/|\/|#)/i', $url)) {
                $url = 'https://' . $url;
            }
            $cleanUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return "<a href=\"{$cleanUrl}\">{$linkText}</a>";
        }, $text) ?? $text;

        // 2. Negrito: **texto** ou __texto__
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__(.*?)__/s', '<strong>$1</strong>', $text) ?? $text;

        // 3. Itálico: *texto* ou _texto_ (evitando conflito com tags HTML)
        $text = preg_replace('/(?<!\*)\*(?!\*)(.*?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<!_)_(?!_)(.*?)(?<!_)_(?!_)/s', '<em>$1</em>', $text) ?? $text;

        return $text;
    }
}
