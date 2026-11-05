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
            $url = "{$canonicalOrigin}/";
            $image = "{$canonicalOrigin}/assets/og-default.jpg";
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
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $siteName,
                    'url' => $canonicalOrigin,
                ],
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
                'url' => "{$canonicalOrigin}/",
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => "{$canonicalOrigin}/?q={search_term_string}",
                    'query-input' => 'required name=search_term_string',
                ],
            ];
        }

        return '<script type="application/ld+json">' . "\n"
            . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n" . '</script>';
    }
}
