<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * LightPress / SpeedPress - Gerenciador Central de Plugins
 *
 * Gerencia ciclo de vida, varredura de diretórios, carregamento seguro de plugins ativos,
 * sincronização de assets estáticos com o Nginx e integração de menus administrativos.
 */
final class PluginManager
{
    private static ?string $pluginsDir = null;
    private static ?string $publicAssetsDir = null;

    /**
     * @var array<string, callable>
     */
    private static array $activationHooks = [];

    /**
     * @var array<string, callable>
     */
    private static array $deactivationHooks = [];

    /**
     * @var array<string, bool>
     */
    private static array $loadedPlugins = [];

    public static function getPluginsDir(): string
    {
        if (self::$pluginsDir === null) {
            self::$pluginsDir = dirname(__DIR__) . '/plugins';
        }
        return self::$pluginsDir;
    }

    public static function getPublicAssetsDir(): string
    {
        if (self::$publicAssetsDir === null) {
            self::$publicAssetsDir = dirname(__DIR__) . '/public/assets/plugins';
        }
        return self::$publicAssetsDir;
    }

    /**
     * Lista todos os plugins instalados no diretório /plugins/ que possuem manifesto válido.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAvailablePlugins(): array
    {
        $dir = self::getPluginsDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            return [];
        }

        $plugins = [];
        $entries = scandir($dir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($dir . '/' . $entry)) {
                continue;
            }

            $manifestPath = $dir . '/' . $entry . '/plugin.json';
            $mainFile = $dir . '/' . $entry . '/' . $entry . '.php';

            if (!file_exists($manifestPath) || !file_exists($mainFile)) {
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

                $meta['entrypoint'] = $mainFile;
                $meta['dir'] = $dir . '/' . $entry;
                $plugins[$meta['id']] = $meta;
            } catch (\Throwable $e) {
                error_log(sprintf('[PluginManager] Erro ao ler manifesto de %s: %s', $entry, $e->getMessage()));
            }
        }

        return $plugins;
    }

    /**
     * Retorna a lista de slugs de plugins ativos persistidos em settings.
     *
     * @return list<string>
     */
    public static function getActivePluginSlugs(): array
    {
        $raw = Settings::get('active_plugins', '[]');
        if (empty($raw)) {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Verifica se um determinado plugin está ativo.
     */
    public static function isActive(string $slug): bool
    {
        return in_array($slug, self::getActivePluginSlugs(), true);
    }

    /**
     * Carrega de forma segura os arquivos principais de todos os plugins ativos.
     */
    public static function loadActivePlugins(): void
    {
        $activeSlugs = self::getActivePluginSlugs();
        if (empty($activeSlugs)) {
            return;
        }

        $available = self::getAvailablePlugins();

        foreach ($activeSlugs as $slug) {
            if (!isset($available[$slug])) {
                continue;
            }

            $entrypoint = $available[$slug]['entrypoint'];
            if (file_exists($entrypoint) && empty(self::$loadedPlugins[$slug])) {
                try {
                    require_once $entrypoint;
                    self::$loadedPlugins[$slug] = true;
                } catch (\Throwable $e) {
                    error_log(sprintf('[PluginManager] Falha ao carregar plugin "%s": %s', $slug, $e->getMessage()));
                }
            }
        }

        Hook::doAction('plugins_loaded');
    }

    /**
     * Registra um callback executado na ativação do plugin.
     */
    public static function registerActivationHook(string $slug, callable $callback): void
    {
        self::$activationHooks[$slug] = $callback;
    }

    /**
     * Registra um callback executado na desativação do plugin.
     */
    public static function registerDeactivationHook(string $slug, callable $callback): void
    {
        self::$deactivationHooks[$slug] = $callback;
    }

    /**
     * Ativa um plugin, executando seus hooks de setup, vinculando assets e salvando o estado.
     *
     * @throws \RuntimeException
     */
    public static function activate(string $slug): void
    {
        $available = self::getAvailablePlugins();
        if (!isset($available[$slug])) {
            throw new \RuntimeException(sprintf('Plugin "%s" não encontrado no sistema.', $slug));
        }

        $active = self::getActivePluginSlugs();
        if (in_array($slug, $active, true)) {
            return; // Já ativo
        }

        // Carrega o arquivo do plugin para registrar hooks de ativação
        $entrypoint = $available[$slug]['entrypoint'];
        if (file_exists($entrypoint)) {
            require_once $entrypoint;
        }

        // Executa callback de ativação se houver
        if (isset(self::$activationHooks[$slug])) {
            (self::$activationHooks[$slug])();
        }

        // Sincronizar assets estáticos para o Nginx (/public/assets/plugins/{slug}/)
        self::syncPluginAssets($slug, $available[$slug]['dir']);

        // Persistir novo estado
        $active[] = $slug;
        Settings::set('active_plugins', json_encode(array_values(array_unique($active)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Desativa um plugin, executando limpeza e removendo assets públicos.
     *
     * @throws \RuntimeException
     */
    public static function deactivate(string $slug): void
    {
        $available = self::getAvailablePlugins();
        $active = self::getActivePluginSlugs();

        if (!in_array($slug, $active, true)) {
            return; // Já desativado
        }

        // Carrega o entrypoint para obter o callback de desativação
        if (isset($available[$slug]['entrypoint']) && file_exists($available[$slug]['entrypoint'])) {
            require_once $available[$slug]['entrypoint'];
        }

        // Executa callback de desativação
        if (isset(self::$deactivationHooks[$slug])) {
            try {
                (self::$deactivationHooks[$slug])();
            } catch (\Throwable $e) {
                error_log(sprintf('[PluginManager] Erro no hook de desativação de "%s": %s', $slug, $e->getMessage()));
            }
        }

        // Limpar assets públicos vinculados
        self::removePluginAssets($slug);

        // Atualizar lista de ativos
        $active = array_values(array_filter($active, fn($s) => $s !== $slug));
        Settings::set('active_plugins', json_encode($active, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Sincroniza ou cria link simbólico dos assets estáticos do plugin para /public/assets/plugins/{slug}/.
     */
    private static function syncPluginAssets(string $slug, string $pluginDir): void
    {
        $sourceAssets = $pluginDir . '/assets';
        if (!is_dir($sourceAssets)) {
            return;
        }

        $targetDir = self::getPublicAssetsDir();
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $destAssets = $targetDir . '/' . $slug;

        // Se for possível criar symlink, tenta. Caso contrário, copia arquivos recursivamente.
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
     * Remove o diretório de assets públicos do plugin na desativação.
     */
    private static function removePluginAssets(string $slug): void
    {
        $destAssets = self::getPublicAssetsDir() . '/' . $slug;
        if (is_link($destAssets)) {
            @unlink($destAssets);
        } elseif (is_dir($destAssets)) {
            self::deleteDirectory($destAssets);
        }
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

    private static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::deleteDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Retorna os plugins ativos que declaram ter tela de configurações no admin.
     *
     * @return list<array{id: string, name: string, admin_title: string}>
     */
    public static function getActiveMenuPlugins(): array
    {
        $active = self::getActivePluginSlugs();
        if (empty($active)) {
            return [];
        }

        $available = self::getAvailablePlugins();
        $menuItems = [];

        foreach ($active as $slug) {
            if (isset($available[$slug]) && !empty($available[$slug]['admin_settings'])) {
                $menuItems[] = [
                    'id' => $slug,
                    'name' => (string)($available[$slug]['name'] ?? $slug),
                    'admin_title' => (string)($available[$slug]['admin_title'] ?? $available[$slug]['name'] ?? $slug),
                ];
            }
        }

        return $menuItems;
    }
}
