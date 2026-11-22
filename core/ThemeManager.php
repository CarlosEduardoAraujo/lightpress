<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * LightPress / SpeedPress - Gerenciador Central de Temas
 *
 * Gerencia ciclo de vida dos temas, varredura declarativa de diretórios,
 * sincronização de assets estáticos com o Nginx, resolução dinâmica de templates
 * com fallback seguro e helpers de opções/assets.
 */
final class ThemeManager
{
    private static ?string $themesDir = null;
    private static ?string $publicThemesDir = null;
    private static ?string $activeTheme = null;
    private static ?array $cachedOptions = null;
    private static ?array $availableThemes = null;

    public static function getThemesDir(): string
    {
        if (self::$themesDir === null) {
            self::$themesDir = dirname(__DIR__) . '/themes';
        }
        return self::$themesDir;
    }

    public static function getPublicThemesDir(): string
    {
        if (self::$publicThemesDir === null) {
            self::$publicThemesDir = dirname(__DIR__) . '/public/assets/themes';
        }
        return self::$publicThemesDir;
    }

    /**
     * Lista todos os temas instalados no diretório /themes/ que possuem theme.json válido.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAvailableThemes(): array
    {
        if (self::$availableThemes !== null) {
            return self::$availableThemes;
        }

        $dir = self::getThemesDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            return [];
        }

        $themes = [];
        $entries = scandir($dir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($dir . '/' . $entry)) {
                continue;
            }

            $manifestPath = $dir . '/' . $entry . '/theme.json';
            if (!file_exists($manifestPath)) {
                continue;
            }

            try {
                $content = file_get_contents($manifestPath);
                if ($content === false) {
                    continue;
                }
                $meta = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($meta) || empty($meta['id']) || empty($meta['name'])) {
                    continue;
                }

                $meta['dir'] = $dir . '/' . $entry;
                $meta['type'] = $meta['type'] ?? 'news'; // 'news' ou 'corporate'
                $meta['options'] = is_array($meta['options'] ?? null) ? $meta['options'] : [];
                $themes[$meta['id']] = $meta;
            } catch (\Throwable $e) {
                error_log(sprintf('[ThemeManager] Erro ao ler manifesto de tema em %s: %s', $entry, $e->getMessage()));
            }
        }

        self::$availableThemes = $themes;
        return $themes;
    }

    /**
     * Retorna o slug do tema ativo configurado no banco de dados.
     * Padrão defensivo: 'portal-noticias'.
     */
    public static function getActiveTheme(): string
    {
        if (self::$activeTheme !== null) {
            return self::$activeTheme;
        }

        try {
            $slug = Settings::get('active_theme', 'portal-noticias');
            $slug = trim((string)$slug);
            if (empty($slug)) {
                $slug = 'portal-noticias';
            }
        } catch (\Throwable) {
            $slug = 'portal-noticias';
        }

        self::$activeTheme = $slug;
        return self::$activeTheme;
    }

    /**
     * Retorna o diretório absoluto do tema ativo.
     * Faz fallback para o diretório de templates legado se a pasta do tema não existir.
     */
    public static function getActiveThemeDir(): string
    {
        $slug = self::getActiveTheme();
        $themeDir = self::getThemesDir() . '/' . $slug;
        if (is_dir($themeDir)) {
            return $themeDir;
        }

        // Fallback 1: portal-noticias
        $baseDir = self::getThemesDir() . '/portal-noticias';
        if (is_dir($baseDir)) {
            return $baseDir;
        }

        // Fallback 2: /templates/ raiz
        $legacyDir = dirname(__DIR__) . '/templates';
        if (is_dir($legacyDir)) {
            return $legacyDir;
        }

        return $themeDir;
    }

    /**
     * Retorna o tipo declarativo do tema ativo ('news' ou 'corporate').
     */
    public static function getActiveThemeType(): string
    {
        $themes = self::getAvailableThemes();
        $active = self::getActiveTheme();

        if (isset($themes[$active]['type'])) {
            return (string)$themes[$active]['type'];
        }

        return 'news';
    }

    /**
     * Carrega as opções de um tema específico, mesclando defaults do theme.json com valores salvos em settings.
     *
     * @return array<string, mixed>
     */
    public static function getThemeOptions(string $slug): array
    {
        $themes = self::getAvailableThemes();
        $defaults = [];
        $metaOptions = [];

        if (isset($themes[$slug]['options']) && is_array($themes[$slug]['options'])) {
            $metaOptions = $themes[$slug]['options'];
            foreach ($themes[$slug]['options'] as $key => $opt) {
                if (isset($opt['default'])) {
                    $defaults[$key] = $opt['default'];
                }
            }
        }

        $rawSaved = Settings::get('theme_options_' . $slug, '{}');
        $saved = [];
        if (!empty($rawSaved)) {
            try {
                $decoded = json_decode($rawSaved, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $saved = $decoded;
                }
            } catch (\Throwable) {
                $saved = [];
            }
        }

        $merged = array_merge($defaults, $saved);

        // Defesa em profundidade: sanitização baseada no tipo declarado no manifesto do tema
        foreach ($merged as $key => $val) {
            $optType = $metaOptions[$key]['type'] ?? null;
            if ($optType === 'url') {
                $merged[$key] = BlockCompiler::sanitizeUrl((string)$val);
            } elseif ($optType === 'image') {
                $merged[$key] = BlockCompiler::sanitizeImageUrl((string)$val);
            }
        }

        return $merged;
    }

    /**
     * Helper para desenvolvedores de temas: recupera o valor de uma opção do tema ativo.
     */
    public static function option(string $key, mixed $default = null): mixed
    {
        if (self::$cachedOptions === null) {
            $activeSlug = self::getActiveTheme();
            self::$cachedOptions = self::getThemeOptions($activeSlug);
        }

        if (array_key_exists($key, self::$cachedOptions)) {
            return self::$cachedOptions[$key];
        }

        return $default;
    }

    /**
     * Persiste as opções personalizadas de um tema na tabela settings e invalida caches.
     *
     * @param array<string, mixed> $options
     */
    public static function setThemeOptions(string $slug, array $options): void
    {
        $json = json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        Settings::set('theme_options_' . $slug, $json);

        // Limpar caches em memória e Redis
        ObjectCache::flushGroup('settings');
        ObjectCache::flushGroup('posts');
        ObjectCache::flushGroup('post_items');
        ObjectCache::flushGroup('pages');
        self::$cachedOptions = null;

        // Disparar purga de cache Nginx na Home e Blog
        try {
            $cacheManager = new CacheManager();
            $cacheManager->purgeUrls(['/', '/blog/']);
        } catch (\Throwable) {
            // Silencioso em caso de ambiente local/CLI
        }
    }

    /**
     * Ativa um tema, sincronizando assets e atualizando a configuração global.
     */
    public static function activate(string $slug): void
    {
        $available = self::getAvailableThemes();
        if (!isset($available[$slug])) {
            throw new \RuntimeException(sprintf('Tema "%s" não encontrado no sistema.', $slug));
        }

        // Sincronizar assets estáticos para o Nginx (/public/assets/themes/{slug}/)
        self::syncThemeAssets($slug, $available[$slug]['dir']);

        // Persistir tema ativo
        Settings::set('active_theme', $slug);
        self::$activeTheme = $slug;
        self::$cachedOptions = null;

        // Limpeza de cache profunda
        ObjectCache::flush();

        try {
            $cacheManager = new CacheManager();
            $cacheManager->purgeUrls(['/', '/blog/', '/feed', '/feed.xml', '/sitemap.xml']);
        } catch (\Throwable) {
        }
    }

    /**
     * Sincroniza ou cria link simbólico dos assets estáticos do tema para /public/assets/themes/{slug}/.
     */
    public static function syncThemeAssets(string $slug, string $themeDir): void
    {
        $sourceAssets = $themeDir . '/assets';
        if (!is_dir($sourceAssets)) {
            return;
        }

        $targetParent = self::getPublicThemesDir();
        if (!is_dir($targetParent)) {
            @mkdir($targetParent, 0775, true);
        }

        $destAssets = $targetParent . '/' . $slug;

        if (is_link($destAssets)) {
            @unlink($destAssets);
        }

        $symlinkCreated = false;
        try {
            $symlinkCreated = @symlink($sourceAssets, $destAssets);
        } catch (\Throwable) {
            $symlinkCreated = false;
        }

        if (!$symlinkCreated) {
            self::copyDirectory($sourceAssets, $destAssets);
        }
    }

    /**
     * Retorna a URL pública com cache-busting (?v=timestamp) do asset do tema ativo.
     * Possui fallback defensivo para /assets/{relativePath} caso o asset não esteja na pasta do tema.
     */
    public static function asset(string $relativePath): string
    {
        $cleanPath = ltrim($relativePath, '/');
        $activeSlug = self::getActiveTheme();

        // 1. Verifica no diretório público sincronizado do tema
        $publicThemeAsset = self::getPublicThemesDir() . '/' . $activeSlug . '/' . $cleanPath;
        if (file_exists($publicThemeAsset)) {
            $version = filemtime($publicThemeAsset);
            return '/assets/themes/' . $activeSlug . '/' . $cleanPath . '?v=' . $version;
        }

        // 2. Verifica no diretório fonte do tema
        $sourceThemeAsset = self::getActiveThemeDir() . '/assets/' . $cleanPath;
        if (file_exists($sourceThemeAsset)) {
            $version = filemtime($sourceThemeAsset);
            return '/assets/themes/' . $activeSlug . '/' . $cleanPath . '?v=' . $version;
        }

        // 3. Fallback defensivo para assets legados em /public/assets/
        $legacyAsset = dirname(__DIR__) . '/public/assets/' . $cleanPath;
        if (file_exists($legacyAsset)) {
            $version = filemtime($legacyAsset);
            return '/assets/' . $cleanPath . '?v=' . $version;
        }

        return '/assets/themes/' . $activeSlug . '/' . $cleanPath;
    }

    /**
     * Localiza o arquivo de template no tema ativo com fallback para o tema base.
     */
    public static function getTemplateFile(string $template): string
    {
        $cleanTemplate = ltrim($template, '/');

        // 1. Procura no diretório do tema ativo
        $activeFile = self::getActiveThemeDir() . '/' . $cleanTemplate;
        if (file_exists($activeFile)) {
            return $activeFile;
        }

        // 2. Fallback 1: Tema base portal-noticias
        $baseFile = self::getThemesDir() . '/portal-noticias/' . $cleanTemplate;
        if (file_exists($baseFile)) {
            return $baseFile;
        }

        // 3. Fallback 2: /templates/ legado
        $legacyFile = dirname(__DIR__) . '/templates/' . $cleanTemplate;
        if (file_exists($legacyFile)) {
            return $legacyFile;
        }

        // 4. Fallback contextual seguro para blog.php -> home.php
        if ($cleanTemplate === 'blog.php') {
            return self::getTemplateFile('home.php');
        }

        return $activeFile;
    }

    private static function copyDirectory(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            @mkdir($dst, 0775, true);
        }

        $dir = opendir($src);
        if (!$dir) {
            return;
        }

        while (false !== ($file = readdir($dir))) {
            if ($file !== '.' && $file !== '..') {
                if (is_dir($src . '/' . $file)) {
                    self::copyDirectory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    /**
     * Reseta estados em cache estático (usado principalmente na suíte de testes).
     */
    public static function reset(): void
    {
        self::$activeTheme = null;
        self::$cachedOptions = null;
        self::$availableThemes = null;
    }
}
