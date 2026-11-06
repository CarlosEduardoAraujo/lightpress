<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * Gerador de Metadados de SEO, Open Graph e Schema.org JSON-LD
 * 
 * Produz marcação estruturada para indexação no Google Notícias e redes sociais
 * sem carregar bibliotecas ou plugins de terceiros.
 */
class Seo
{
    /**
     * Gera o bloco de metadados Open Graph e Twitter Cards para o <head>.
     *
     * @param array{
     *   title: string,
     *   summary?: string,
     *   featured_image?: string,
     *   published_at?: string,
     *   updated_at?: string,
     *   author_name?: string,
     *   category?: string,
     *   slug?: string
     * }|null $post
     * @param array $appConfig
     * @return string
     */
    public static function renderMetaTags(?array $post, array $appConfig): string
    {
        $siteName = $appConfig['app']['name'] ?? 'SpeedPress';
        $canonicalOrigin = rtrim($appConfig['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

        if ($post !== null) {
            $title = !empty($post['seo_title']) ? $post['seo_title'] : $post['title'];
            $description = !empty($post['seo_description']) ? $post['seo_description'] : ($post['summary'] ?? '');
            $url = "{$canonicalOrigin}/" . Security::e($post['slug'] ?? '') . '/';
            $rawImage = !empty($post['featured_image']) ? (str_starts_with($post['featured_image'], 'http') ? $post['featured_image'] : "{$canonicalOrigin}{$post['featured_image']}") : '';
            // Forçar WebP para Open Graph e Twitter Cards (redes sociais como X/Twitter ainda não aceitam AVIF)
            $image = !empty($rawImage) ? ImageProcessor::toWebpUrl($rawImage) : '';
            $type = 'article';
        } else {
            $title = $siteName . ' - ' . (!empty($appConfig['app']['description']) ? $appConfig['app']['description'] : 'Portal de Notícias');
            $description = !empty($appConfig['app']['description']) ? $appConfig['app']['description'] : 'Notícias, coberturas em tempo real e análises aprofundadas com máxima velocidade.';
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $url = "{$canonicalOrigin}" . (str_starts_with($requestUri, '/') ? $requestUri : "/{$requestUri}");

            // Para Home, Categorias, Tags e demais páginas não-artigo:
            // og:image e twitter:image devem ser o mesmo do favicon e SEMPRE .webp
            $faviconRaw = $appConfig['app']['favicon'] ?? '';
            if (!empty($faviconRaw)) {
                $webpFavicon = ImageProcessor::toWebpUrl($faviconRaw);
            } else {
                $webpFavicon = '/favicon.webp';
            }
            if (!str_ends_with(strtolower($webpFavicon), '.webp')) {
                $webpFavicon = preg_replace('~\.[a-z0-9]+$~i', '.webp', $webpFavicon);
            }
            $image = str_starts_with($webpFavicon, 'http') ? $webpFavicon : "{$canonicalOrigin}{$webpFavicon}";
            $type = 'website';
        }

        $html = [];
        $html[] = '<meta property="og:site_name" content="' . Security::e($siteName) . '">';
        $html[] = '<meta property="og:title" content="' . Security::e($title) . '">';
        $html[] = '<meta property="og:description" content="' . Security::e($description) . '">';
        $html[] = '<meta property="og:url" content="' . Security::e($url) . '">';
        $html[] = '<meta property="og:type" content="' . Security::e($type) . '">';
        if (!empty($image)) {
            $html[] = '<meta property="og:image" content="' . Security::e($image) . '">';
        }

        // Twitter Cards
        $html[] = '<meta name="twitter:card" content="summary_large_image">';
        $html[] = '<meta name="twitter:title" content="' . Security::e($title) . '">';
        $html[] = '<meta name="twitter:description" content="' . Security::e($description) . '">';
        if (!empty($image)) {
            $html[] = '<meta name="twitter:image" content="' . Security::e($image) . '">';
        }

        // Metadados específicos de Artigo
        if ($post !== null && !empty($post['published_at'])) {
            $html[] = '<meta property="article:published_time" content="' . date('c', strtotime($post['published_at'])) . '">';
            $html[] = '<meta property="article:modified_time" content="' . date('c', strtotime($post['updated_at'] ?? $post['published_at'])) . '">';
            if (!empty($post['author_name'])) {
                $html[] = '<meta property="article:author" content="' . Security::e($post['author_name']) . '">';
            }
            if (!empty($post['category'])) {
                $html[] = '<meta property="article:section" content="' . Security::e($post['category']) . '">';
            }
        }

        return implode("\n    ", $html);
    }

    /**
     * Gera o script JSON-LD (NewsArticle ou WebSite) para o Google.
     *
     * @param array|null $post
     * @param array $appConfig
     * @return string
     */
    public static function renderJsonLd(?array $post, array $appConfig): string
    {
        $siteName = $appConfig['app']['name'] ?? 'SpeedPress';
        $canonicalOrigin = rtrim($appConfig['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

        if ($post !== null) {
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'NewsArticle',
                'mainEntityOfPage' => [
                    '@type' => 'WebPage',
                    '@id' => "{$canonicalOrigin}/{$post['slug']}/",
                ],
                'headline' => $post['title'],
                'description' => $post['summary'] ?? '',
                'datePublished' => date('c', strtotime($post['published_at'] ?? 'now')),
                'dateModified' => date('c', strtotime($post['updated_at'] ?? $post['published_at'] ?? 'now')),
                'author' => [
                    '@type' => 'Person',
                    'name' => $post['author_name'] ?? 'Redação',
                ],
                'publisher' => array_merge([
                    '@type' => 'Organization',
                    'name' => $siteName,
                    'url' => $canonicalOrigin,
                ], !empty($appConfig['app']['favicon']) ? [
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => str_starts_with($appConfig['app']['favicon'], 'http')
                            ? $appConfig['app']['favicon']
                            : "{$canonicalOrigin}{$appConfig['app']['favicon']}",
                    ],
                ] : []),
            ];

            if (!empty($post['featured_image'])) {
                $imgUrl = str_starts_with($post['featured_image'], 'http')
                    ? $post['featured_image']
                    : "{$canonicalOrigin}{$post['featured_image']}";
                $schema['image'] = [$imgUrl];
            }
        } else {
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $siteName,
                'description' => $appConfig['app']['description'] ?? '',
                'url' => "{$canonicalOrigin}/",
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => "{$canonicalOrigin}/busca/?q={search_term_string}",
                    'query-input' => 'required name=search_term_string',
                ],
            ];
        }

        $json = json_encode(
            $schema,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">' . "\n"
            . $json
            . "\n" . '</script>';
    }
}
