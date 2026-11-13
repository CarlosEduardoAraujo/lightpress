<?php
declare(strict_types=1);

namespace LightPress\Core;

use RuntimeException;
use Throwable;

/**
 * Pipeline de Processamento e Otimização de Mídia (PHP GD Nativo)
 * 
 * Executa conversão determinística para WebP obrigatório e AVIF condicional,
 * re-encoding para eliminação de metadados perigosos, padronização em 3 resoluções
 * e manifesto de variantes validado.
 */
class ImageProcessor
{
    /**
     * Cache estático em memória RAM para o ciclo de vida da requisição atual.
     * @var array<string, string>
     */
    private static array $memoryDescCache = [];

    private string $uploadDir;
    private string $stagingDir;
    private int $maxFileSize;
    private int $maxPixels;
    private int $maxDimension;
    private int $webpQuality;
    private int $avifQuality;
    private int $avifSpeed;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $globalConfig = require dirname(__DIR__) . '/config.php';
            $config = $globalConfig;
        }

        $media = $config['media'] ?? [];
        $this->uploadDir = rtrim($media['upload_dir'] ?? dirname(__DIR__) . '/public/uploads', '/\\');
        $this->stagingDir = rtrim($media['staging_dir'] ?? dirname(__DIR__) . '/storage/staging', '/\\');
        $this->maxFileSize = (int)($media['max_file_size'] ?? 8 * 1024 * 1024);
        $this->maxPixels = (int)($media['max_pixels'] ?? 12_000_000);
        $this->maxDimension = (int)($media['max_dimension'] ?? 8_000);
        $this->webpQuality = (int)($media['webp_quality'] ?? 82);
        $this->avifQuality = (int)($media['avif_quality'] ?? 75);
        $this->avifSpeed = (int)($media['avif_speed'] ?? 6);
    }

    /**
     * Converte uma URL de imagem para a variante AVIF de máxima compressão web.
     */
    public static function toAvifUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }
        if (str_ends_with($url, '.webp')) {
            return substr($url, 0, -5) . '.avif';
        }
        if (str_ends_with($url, '.jpg') || str_ends_with($url, '.png') || str_ends_with($url, '.jpeg')) {
            return preg_replace('~\.(jpe?g|png)$~i', '.avif', $url);
        }
        return $url;
    }

    /**
     * Converte uma URL de imagem para a variante WebP (compatibilidade garantida com redes sociais/Open Graph).
     */
    public static function toWebpUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }
        if (str_ends_with($url, '.avif')) {
            return substr($url, 0, -5) . '.webp';
        }
        if (str_ends_with($url, '.jpg') || str_ends_with($url, '.png') || str_ends_with($url, '.jpeg') || str_ends_with($url, '.ico')) {
            return preg_replace('~\.(jpe?g|png|ico)$~i', '.webp', $url);
        }
        return $url;
    }

    /**
     * Obtém a URL da variante Thumbnail (-thumb.avif ou -thumb.webp, 300x169px) para listagens e painéis leves.
     * Suporta nativamente:
     * 1. Novas imagens com variante -thumb.avif
     * 2. Acervos legados do WordPress com sufixos proporcionais (-300x... ou -299x299)
     * 3. Fallback inteligente e transparente para a imagem original em AVIF
     *
     * @param string $url URL de qualquer variante da imagem
     * @param string $format 'avif' (padrão) ou 'webp'
     * @return string URL apontando diretamente para a melhor versão de miniatura disponível
     */
    public static function toThumbUrl(string $url, string $format = 'avif'): string
    {
        if (empty($url)) {
            return '';
        }

        $targetExt = ($format === 'webp') ? 'webp' : 'avif';

        // Se já tiver -thumb no nome, apenas ajusta a extensão
        if (str_contains($url, '-thumb.')) {
            return ($targetExt === 'webp') ? self::toWebpUrl($url) : self::toAvifUrl($url);
        }

        // Gera candidato padrão SpeedPress: -thumb.avif
        $replaced = preg_replace('~-(1024|1920)\.(webp|avif|jpe?g|png)$~i', "-thumb.{$targetExt}", $url);
        if ($replaced === null || $replaced === $url) {
            $replaced = preg_replace('~\.(webp|avif|jpe?g|png)$~i', "-thumb.{$targetExt}", $url);
        }

        $candidate = $replaced ?? $url;

        // Verificar existência física no sistema de arquivos para compatibilidade híbrida
        $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : (dirname(__DIR__) . '/public');
        $candidatePath = rtrim($docRoot, '/\\') . '/' . ltrim($candidate, '/\\');

        if (file_exists($candidatePath)) {
            return $candidate;
        }

        // Se não encontrar o -thumb físico, busca por variantes proporcionais legadas do WordPress (-300x... ou -299x299)
        $dir = dirname($candidatePath);
        if (is_dir($dir)) {
            $pathInfo = pathinfo($candidatePath);
            $baseWithoutThumb = preg_replace('~-thumb$~', '', $pathInfo['filename']);

            // Testar se existe miniatura proporcional do WordPress (ex: -300x169.avif)
            $matches = glob($dir . '/' . $baseWithoutThumb . '-300x*.' . $targetExt);
            if (!empty($matches)) {
                return dirname($candidate) . '/' . basename($matches[0]);
            }

            // Testar se existe miniatura quadrada do WordPress (ex: -299x299.avif)
            $squareMatch = $dir . '/' . $baseWithoutThumb . '-299x299.' . $targetExt;
            if (file_exists($squareMatch)) {
                return dirname($candidate) . '/' . basename($squareMatch);
            }
        }

        // Fallback transparente: retorna a imagem original em AVIF
        return self::toAvifUrl($url);
    }

    /**
     * Obtém a URL da variante Intermediária / 1024px (-1024.avif ou -1024.webp) para destaques e hero.
     *
     * @param string $url URL de qualquer variante da imagem
     * @param string $format 'avif' (padrão) ou 'webp'
     * @return string URL apontando para a versão intermediária (1024px) ou fallback
     */
    public static function toIntermediateUrl(string $url, string $format = 'avif'): string
    {
        if (empty($url)) {
            return '';
        }

        $targetExt = ($format === 'webp') ? 'webp' : 'avif';

        if (str_contains($url, '-1024.')) {
            return ($targetExt === 'webp') ? self::toWebpUrl($url) : self::toAvifUrl($url);
        }

        $replaced = preg_replace('~-(thumb|1920)\.(webp|avif|jpe?g|png)$~i', "-1024.{$targetExt}", $url);
        if ($replaced === null || $replaced === $url) {
            $replaced = preg_replace('~\.(webp|avif|jpe?g|png)$~i', "-1024.{$targetExt}", $url);
        }

        $candidate = $replaced ?? $url;

        $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : (dirname(__DIR__) . '/public');
        $candidatePath = rtrim($docRoot, '/\\') . '/' . ltrim($candidate, '/\\');

        if (file_exists($candidatePath)) {
            return $candidate;
        }

        // Fallback para a imagem completa em AVIF
        return self::toAvifUrl($url);
    }

    /**
     * Limpa o cache estático em memória da requisição (útil para testes unitários).
     */
    public static function clearMemoryCache(): void
    {
        self::$memoryDescCache = [];
    }

    /** Resolve os metadados atuais; o segundo argumento preserva a API existente. */
    public static function getImageDescription(string $imageUrl, ?string $fallbackAlt = null, ?string $mediaId = null): string
    {
        if (trim((string)$fallbackAlt) !== '') {
            return trim($fallbackAlt);
        }
        $path = self::descriptionPath($imageUrl);
        if ($path === '') {
            return '';
        }
        // O ID faz parte da chave para não reutilizar uma resolução sem vínculo confiável.
        $key = $path . '|' . ($mediaId ?? '');
        self::loadDescriptions([$key => ['path' => $path, 'id' => $mediaId ?? '']]);
        return self::$memoryDescCache[$key];
    }

    /** Usa o mesmo resolvedor da consulta individual, com queries em lote. */
    public static function preloadDescriptions(array $imageUrls): void
    {
        $requests = [];
        foreach ($imageUrls as $url) {
            if (is_string($url) && ($path = self::descriptionPath($url)) !== '') {
                $requests[$path . '|'] = ['path' => $path, 'id' => ''];
            }
        }
        foreach (array_chunk($requests, 100, true) as $chunk) {
            self::loadDescriptions($chunk);
        }
    }

    private static function descriptionPath(string $url): string
    {
        return ltrim((string)(parse_url($url, PHP_URL_PATH) ?? $url), '/');
    }

    private static function descriptionStem(string $path): string
    {
        return (string)preg_replace('~-(1024|1920|thumb|\d+x\d+)$~i', '', pathinfo($path, PATHINFO_FILENAME));
    }

    private static function filenameDescription(string $path): string
    {
        $stem = self::descriptionStem($path);
        return $stem !== '' && !preg_match('/^(image|img|dsc|whatsapp|foto|\d+)$/i', $stem)
            ? trim(ucwords(str_replace(['-', '_'], ' ', $stem))) : '';
    }

    private static function mediaDescription(array $media): string
    {
        foreach (['alt_text', 'caption'] as $field) {
            $text = trim((string)($media[$field] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return self::filenameDescription((string)($media['original_name'] ?? ''));
    }

    /** Confirma pasta e nome base no manifesto antes de aceitar ID ou nome alternativo. */
    private static function manifestMatches(array $media, string $path): bool
    {
        $manifest = json_decode((string)($media['variants_manifest'] ?? ''), true);
        if (!is_array($manifest)) {
            return false;
        }
        $matches = false;
        array_walk_recursive($manifest, static function ($value) use ($path, &$matches): void {
            if (is_string($value) && str_contains($value, 'uploads/')) {
                $candidate = self::descriptionPath($value);
                if (dirname($candidate) === dirname($path)
                    && self::descriptionStem($candidate) === self::descriptionStem($path)) {
                    $matches = true;
                }
            }
        });
        return $matches;
    }

    private static function loadDescriptions(array $requests): void
    {
        $pending = [];
        foreach ($requests as $key => $request) {
            if (array_key_exists($key, self::$memoryDescCache)) {
                continue;
            }
            // Nova versão ignora fallbacks antigos que ficaram persistidos por 24h.
            $cached = ObjectCache::get('media_desc:v2:' . md5($key), 'media');
            if (is_string($cached)) {
                self::$memoryDescCache[$key] = $cached;
            } else {
                $pending[$key] = $request;
            }
        }
        if (!$pending) {
            return;
        }
        $resolved = [];
        try {
            $paths = array_values(array_unique(array_column($pending, 'path')));
            $marks = implode(',', array_fill(0, count($paths), '?'));
            $rows = Database::fetchAll(
                "SELECT mv.url_path, m.id, m.alt_text, m.caption, m.original_name, m.variants_manifest
                 FROM media_variants mv JOIN media m ON m.id = mv.media_id
                 WHERE mv.url_path IN ({$marks})", $paths
            );
            $byPath = array_column($rows, null, 'url_path');
            $names = [];
            $ids = [];
            foreach ($pending as $key => $request) {
                if (isset($byPath[$request['path']]) && $request['id'] === '') {
                    $resolved[$key] = self::mediaDescription($byPath[$request['path']]);
                    continue;
                }
                $names[] = basename($request['path']);
                foreach (['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'] as $extension) {
                    $names[] = self::descriptionStem($request['path']) . '.' . $extension;
                }
                if ($request['id'] !== '') {
                    $ids[] = $request['id'];
                }
            }
            $names = array_values(array_unique($names));
            $params = $names;
            $condition = 'original_name IN (' . implode(',', array_fill(0, count($names), '?')) . ')';
            if ($ids) {
                $ids = array_values(array_unique($ids));
                $condition .= ' OR id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            $candidates = $names ? Database::fetchAll(
                "SELECT id, alt_text, caption, original_name, variants_manifest FROM media WHERE {$condition}", $params
            ) : [];
            foreach ($pending as $key => $request) {
                $path = $request['path'];
                $media = $byPath[$path] ?? null;
                $matches = [];
                foreach ($candidates as $candidate) {
                    if (self::manifestMatches($candidate, $path)) {
                        if ($request['id'] !== '' && $candidate['id'] === $request['id']) {
                            $media = $candidate;
                            $matches = [];
                            break;
                        }
                        $matches[$candidate['id']] = $candidate;
                    }
                }
                if (!$media && count($matches) === 1) {
                    $media = reset($matches);
                }
                if ($media) {
                    $resolved[$key] = self::mediaDescription($media);
                }
            }
        } catch (\Throwable $e) {
            error_log('[ImageProcessor Description Error] ' . $e->getMessage());
        }
        foreach ($pending as $key => $request) {
            $description = $resolved[$key] ?? self::filenameDescription($request['path']);
            self::$memoryDescCache[$key] = $description;
            // Falhas e vínculos ausentes não podem envenenar o cache persistente.
            if (array_key_exists($key, $resolved)) {
                ObjectCache::set('media_desc:v2:' . md5($key), $description, 'media', 86400);
            }
        }
    }

    /** Invalida metadados e agenda a purga das páginas canônicas que exibem a mídia. */
    public static function invalidateMediaMetadata(string $mediaId): void
    {
        self::clearMemoryCache();
        ObjectCache::flushGroup('media');
        ObjectCache::flushGroup('posts');
        try {
            $media = Database::fetchOne('SELECT variants_manifest FROM media WHERE id = ?', [$mediaId]);
            $paths = [];
            $manifest = json_decode((string)($media['variants_manifest'] ?? ''), true) ?? [];
            array_walk_recursive($manifest, static function ($value) use (&$paths): void {
                if (is_string($value) && str_contains($value, 'uploads/')) {
                    $path = self::descriptionPath($value);
                    $paths[] = '/' . $path;
                    $paths[] = $path;
                    $paths[] = $value;
                }
            });
            $paths = array_values(array_unique($paths));
            $params = [$mediaId, $mediaId];
            $condition = 'featured_media_id = ? OR id IN (SELECT post_id FROM media_refs WHERE media_id = ?)';
            if ($paths) {
                $condition .= ' OR featured_image IN (' . implode(',', array_fill(0, count($paths), '?')) . ')';
                $params = array_merge($params, $paths);
            }
            $posts = Database::fetchAll("SELECT slug, category, tags FROM posts WHERE {$condition}", $params);
            $urls = ['/'];
            $count = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE status IN ('published', 'scheduled') AND published_at <= ?", [gmdate('Y-m-d H:i:s')]);
            for ($page = 2; $page <= (int)ceil($count / 12); $page++) {
                $urls[] = '/page/' . $page . '/';
            }
            foreach (array_unique(array_column($posts, 'category')) as $category) {
                // Outras notícias da categoria podem exibir a foto nos relacionados.
                $siblings = Database::fetchAll("SELECT slug FROM posts WHERE category = ? AND status IN ('published', 'scheduled')", [$category]);
                foreach ($siblings as $sibling) {
                    $urls[] = '/' . $sibling['slug'] . '/';
                }
                $urls[] = '/categoria/' . $category . '/';
                for ($page = 2; $page <= (int)ceil(count($siblings) / 12); $page++) {
                    $urls[] = '/categoria/' . $category . '/page/' . $page . '/';
                }
            }
            foreach ($posts as $post) {
                $urls[] = '/' . $post['slug'] . '/';
                foreach (explode(',', (string)$post['tags']) as $tag) {
                    $slug = Security::slugify(trim($tag));
                    if ($slug !== '') {
                        $urls[] = '/tag/' . $slug . '/';
                        $cleanTag = str_replace('-', ' ', $slug);
                        if (Database::getDriver() === 'sqlite') {
                            $tagWhere = "(',' || REPLACE(REPLACE(COALESCE(tags, ''), ', ', ','), ' ,', ',') || ',') LIKE ?";
                            $tagParams = ['%,' . Security::escapeLike($cleanTag) . ',%'];
                        } else {
                            $tagWhere = "FIND_IN_SET(?, REPLACE(COALESCE(tags, ''), ', ', ',')) > 0";
                            $tagParams = [$cleanTag];
                        }
                        $tagCount = (int)Database::fetchValue('SELECT COUNT(*) FROM posts WHERE ' . $tagWhere, $tagParams);
                        for ($page = 2; $page <= (int)ceil($tagCount / 12); $page++) {
                            $urls[] = '/tag/' . $slug . '/page/' . $page . '/';
                        }
                    }
                }
            }
            if ($paths) {
                $marks = implode(',', array_fill(0, count($paths), '?'));
                foreach (Database::fetchAll("SELECT slug FROM pages WHERE featured_image IN ({$marks})", $paths) as $page) {
                    $urls[] = '/' . $page['slug'] . '/';
                }
            }
            $cache = new CacheManager();
            $urls = array_values(array_unique($urls));
            $cache->recordPurgeJob(null, 'media_metadata', $urls);
            // Lotes grandes ficam com o worker para não bloquear a edição da mídia.
            if (count($urls) <= 30) {
                $cache->purgeUrls($urls);
            }
        } catch (\Throwable $e) {
            error_log('[ImageProcessor Metadata Purge Error] ' . $e->getMessage());
        }
    }

    /**
     * Processa um arquivo enviado via upload ($_FILES).
     *
     * @param string $tmpFilePath Caminho temporário do arquivo
     * @param string $originalName Nome original do arquivo enviado
     * @return array Manifesto de variantes geradas
     */
    public function processUpload(string $tmpFilePath, string $originalName): array
    {
        // 0. Elevar temporariamente os limites de execução e memória para manipulação de bitmaps pesados
        $currentLimit = ini_get('memory_limit');
        if ($currentLimit !== false && $currentLimit !== '-1') {
            $bytes = self::parseMemoryBytes((string)$currentLimit);
            if ($bytes < 512 * 1024 * 1024) {
                @ini_set('memory_limit', '512M');
            }
        }
        @set_time_limit(120);

        // 1. Verificação de existência e tamanho
        if (!file_exists($tmpFilePath) || filesize($tmpFilePath) <= 0) {
            throw new RuntimeException("Arquivo de upload vazio ou inválido.", 400);
        }

        $fileSize = filesize($tmpFilePath);
        if ($fileSize > $this->maxFileSize) {
            throw new RuntimeException("O arquivo excede o limite máximo permitido de 8 MiB.", 413);
        }

        // 2. Validação Real do MIME Type via finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpFilePath);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
        if (!in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException("Formato de imagem não suportado ({$mime}).", 415);
        }

        // 3. Inspeção de dimensões e pixels antes de alocar memória
        $imageInfo = @getimagesize($tmpFilePath);
        if (!$imageInfo) {
            throw new RuntimeException("Não foi possível decodificar os metadados da imagem.", 415);
        }

        $origWidth = (int)$imageInfo[0];
        $origHeight = (int)$imageInfo[1];

        if ($origWidth > $this->maxDimension || $origHeight > $this->maxDimension) {
            throw new RuntimeException("Dimensões da imagem excedem o limite de {$this->maxDimension}px por lado.", 413);
        }

        if (($origWidth * $origHeight) > $this->maxPixels) {
            throw new RuntimeException("Resolução da imagem excede o limite de 12 Megapixels.", 413);
        }

        // 4. Carregamento do recurso GD de acordo com o formato
        $source = $this->loadSourceImage($tmpFilePath, $mime);
        if (!$source) {
            throw new RuntimeException("Falha ao decodificar imagem para processamento.", 415);
        }

        // 5. Preparação de diretórios de destino: /public/uploads/{ano}/{mes}/
        $year = gmdate('Y');
        $month = gmdate('m');
        $relativeDir = "/uploads/{$year}/{$month}";
        $targetDir = "{$this->uploadDir}/{$year}/{$month}";

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
            @chmod($targetDir, 0775);
        }
        if (!is_dir($this->stagingDir)) {
            @mkdir($this->stagingDir, 0775, true);
            @chmod($this->stagingDir, 0775);
        }

        // Gerar identificador único de catálogo (media_id)
        $mediaId = bin2hex(random_bytes(16));

        // Gerar nome base semântico preservando o nome original do arquivo
        $pathInfo = pathinfo($originalName);
        $rawBaseName = $pathInfo['filename'] ?? 'imagem';
        $cleanSlug = Security::slugify($rawBaseName);
        if (empty($cleanSlug)) {
            $cleanSlug = 'imagem';
        }
        $cleanSlug = rtrim(mb_substr($cleanSlug, 0, 100), '-');

        // Se já existir qualquer variante com o mesmo nome na pasta de destino, incrementa sufixo numérico (-1, -2, etc.)
        $fileBase = $cleanSlug;
        $counter = 1;
        while (
            file_exists("{$targetDir}/{$fileBase}.webp") || 
            file_exists("{$targetDir}/{$fileBase}.avif") || 
            file_exists("{$targetDir}/{$fileBase}-1024.webp") || 
            file_exists("{$targetDir}/{$fileBase}-1024.avif") || 
            file_exists("{$targetDir}/{$fileBase}-thumb.webp") || 
            file_exists("{$targetDir}/{$fileBase}-thumb.avif") || 
            file_exists("{$targetDir}/{$fileBase}-1920.webp") || 
            file_exists("{$targetDir}/{$fileBase}-1920.avif")
        ) {
            $fileBase = "{$cleanSlug}-{$counter}";
            $counter++;
        }

        $canAvif = function_exists('imageavif');

        // Sanitizar nome original para prevenir injeção em metadados
        $sanitizedOriginalName = mb_substr(trim(strip_tags($originalName)), 0, 200);
        if ($sanitizedOriginalName === '') {
            $sanitizedOriginalName = 'imagem.jpg';
        }

        $manifest = [
            'media_id' => $mediaId,
            'original_name' => $sanitizedOriginalName,
            'original_width' => $origWidth,
            'original_height' => $origHeight,
            'mime_type' => $mime,
            'variants' => [],
        ];

        try {
            // Definição das 3 resoluções padronizadas
            // A imagem original/full não recebe sufixo de resolução (fica apenas o nome original)
            // As variantes intermediária e miniatura recebem -1024 e -thumb
            $specs = [
                'full' => ['max_w' => 1920, 'suffix' => '', 'crop' => false],
                'intermediate' => ['max_w' => 1024, 'suffix' => '1024', 'crop' => false],
                'thumb' => ['max_w' => 300, 'max_h' => 169, 'suffix' => 'thumb', 'crop' => true],
            ];

            foreach ($specs as $key => $spec) {
                // Redimensionar recurso GD
                $resized = $spec['crop']
                    ? $this->cropThumbnail($source, $origWidth, $origHeight, $spec['max_w'], $spec['max_h'])
                    : $this->scaleProportional($source, $origWidth, $origHeight, $spec['max_w']);

                $w = imagesx($resized);
                $h = imagesy($resized);

                $suffixPart = empty($spec['suffix']) ? '' : "-{$spec['suffix']}";

                // Geração do WebP Obrigatório com staging isolado por operação (evita concorrência)
                $webpFilename = "{$fileBase}{$suffixPart}.webp";
                $webpStaging = "{$this->stagingDir}/{$mediaId}_{$webpFilename}";
                $webpTarget = "{$targetDir}/{$webpFilename}";

                imagewebp($resized, $webpStaging, $this->webpQuality);
                // Se a imagem original já for WebP, tiver resolução Full e menor peso que a recodificação, preserva a original
                if ($mime === 'image/webp' && $key === 'full' && $origWidth <= 1920 && filesize($tmpFilePath) < filesize($webpStaging)) {
                    copy($tmpFilePath, $webpStaging);
                }

                if (file_exists($webpStaging) && filesize($webpStaging) > 0) {
                    $webpSize = filesize($webpStaging);
                    if (file_exists($webpTarget)) {
                        @unlink($webpStaging);
                        throw new RuntimeException("Conflito de destino detectado ao salvar variante WebP ({$webpFilename}).", 409);
                    }
                    rename($webpStaging, $webpTarget);
                    @chmod($webpTarget, 0664);
                    $manifest['variants'][$key]['webp'] = [
                        'url' => "{$relativeDir}/{$webpFilename}",
                        'width' => $w,
                        'height' => $h,
                        'mime' => 'image/webp',
                        'size' => $webpSize,
                    ];
                } else {
                    @unlink($webpStaging);
                    throw new RuntimeException("Falha ao gerar variante WebP ({$key}).", 500);
                }

                // Geração do AVIF Opcional com staging isolado por operação
                if ($canAvif) {
                    $avifFilename = "{$fileBase}{$suffixPart}.avif";
                    $avifStaging = "{$this->stagingDir}/{$mediaId}_{$avifFilename}";
                    $avifTarget = "{$targetDir}/{$avifFilename}";

                    try {
                        $avifSuccess = @imageavif($resized, $avifStaging, $this->avifQuality, $this->avifSpeed);
                        // Se a imagem original já for AVIF, tiver resolução Full e menor peso que a recodificação, preserva a original
                        if ($mime === 'image/avif' && $key === 'full' && $origWidth <= 1920 && filesize($tmpFilePath) < filesize($avifStaging)) {
                            copy($tmpFilePath, $avifStaging);
                            $avifSuccess = true;
                        }

                        if ($avifSuccess && file_exists($avifStaging) && filesize($avifStaging) > 0) {
                            $avifSize = filesize($avifStaging);
                            if (file_exists($avifTarget)) {
                                @unlink($avifStaging);
                                throw new RuntimeException("Conflito de destino detectado ao salvar variante AVIF ({$avifFilename}).", 409);
                            }
                            rename($avifStaging, $avifTarget);
                            @chmod($avifTarget, 0664);
                            $manifest['variants'][$key]['avif'] = [
                                'url' => "{$relativeDir}/{$avifFilename}",
                                'width' => $w,
                                'height' => $h,
                                'mime' => 'image/avif',
                                'size' => $avifSize,
                            ];
                        } else {
                            @unlink($avifStaging);
                        }
                    } catch (Throwable $avifErr) {
                        @unlink($avifStaging);
                        error_log("[LightPress AVIF Fallback] " . $avifErr->getMessage());
                    }
                }

                imagedestroy($resized);
            }

            imagedestroy($source);

            // Remover arquivo temporário de origem
            @unlink($tmpFilePath);

            return $manifest;

        } catch (Throwable $e) {
            if (isset($source) && ($source instanceof \GdImage || is_resource($source))) {
                imagedestroy($source);
            }
            throw new RuntimeException("Erro durante a conversão das imagens: " . $e->getMessage(), 500, $e);
        }
    }

    /**
     * Converte string de memória no formato do php.ini (ex: '128M', '1G') em bytes inteiros.
     */
    private static function parseMemoryBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '' || $val === '-1') {
            return PHP_INT_MAX;
        }
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int)substr($val, 0, -1);
        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int)$val,
        };
    }

    /**
     * Carrega a imagem original com base no MIME type real.
     */
    private function loadSourceImage(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : null,
            default => null,
        };
    }

    /**
     * Redimensiona proporcionalmente sem ampliação.
     */
    private function scaleProportional($source, int $origW, int $origH, int $maxW)
    {
        if ($origW <= $maxW) {
            $newW = $origW;
            $newH = $origH;
        } else {
            $newW = $maxW;
            $newH = (int)round(($origH * $maxW) / $origW);
        }

        $dest = imagecreatetruecolor($newW, $newH);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);

        imagecopyresampled($dest, $source, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        return $dest;
    }

    /**
     * Realiza corte centralizado fixo 16:9 sem distorção.
     */
    private function cropThumbnail($source, int $origW, int $origH, int $targetW, int $targetH)
    {
        $dest = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($dest, false);
        imagesavealpha($dest, true);

        $srcRatio = $origW / $origH;
        $targetRatio = $targetW / $targetH;

        if ($srcRatio > $targetRatio) {
            $cropH = $origH;
            $cropW = (int)round($origH * $targetRatio);
            $cropX = (int)round(($origW - $cropW) / 2);
            $cropY = 0;
        } else {
            $cropW = $origW;
            $cropH = (int)round($origW / $targetRatio);
            $cropX = 0;
            $cropY = (int)round(($origH - $cropH) / 2);
        }

        imagecopyresampled($dest, $source, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);
        return $dest;
    }

    /**
     * Registra variantes geradas de uma mídia na tabela media_variants de forma idempotente.
     *
     * @param string $mediaId Identificador da mídia
     * @param array $variants Matriz de variantes com URLs
     */
    public static function registerVariants(string $mediaId, array $variants): void
    {
        if (empty($mediaId) || empty($variants)) {
            return;
        }

        $driver = Database::getDriver();
        $sql = ($driver === 'sqlite')
            ? "INSERT OR IGNORE INTO media_variants (media_id, url_path) VALUES (?, ?)"
            : "INSERT IGNORE INTO media_variants (media_id, url_path) VALUES (?, ?)";

        $urls = [];
        array_walk_recursive($variants, function ($val) use (&$urls) {
            if (is_string($val) && str_contains($val, 'uploads/')) {
                $clean = ltrim(parse_url($val, PHP_URL_PATH) ?? $val, '/');
                if (!empty($clean)) {
                    $urls[$clean] = true;
                }
            }
        });

        foreach (array_keys($urls) as $path) {
            try {
                Database::query($sql, [$mediaId, $path]);
            } catch (\Throwable) {
                // Silencioso se a tabela ainda estiver em transição
            }
        }
    }

    /**
     * Sincroniza vínculos normalizados entre mídias e artigos/revisões na tabela media_refs.
     * Utiliza a tabela indexada media_variants para busca instantânea O(1) sem varredura LIKE.
     */
    public static function syncRefs(int $postId, ?int $revisionId, ?string $html, ?string $featuredImage = null): void
    {
        $text = ($html ?? '') . ' ' . ($featuredImage ?? '');
        if (empty(trim($text)) || $postId <= 0) {
            return;
        }

        if (preg_match_all('~/uploads/[a-zA-Z0-9_\-\./]+~', $text, $matches)) {
            $urls = array_unique($matches[0]);
            foreach ($urls as $url) {
                try {
                    $clean = ltrim(parse_url($url, PHP_URL_PATH) ?? $url, '/');
                    $mediaId = Database::fetchValue(
                        "SELECT media_id FROM media_variants WHERE url_path = ? LIMIT 1",
                        [$clean]
                    );

                    // Fallback rápido por nome de arquivo se a variante não estiver cadastrada
                    if (!$mediaId) {
                        $base = basename($clean);
                        $mediaId = Database::fetchValue(
                            "SELECT id FROM media WHERE original_name = ? LIMIT 1",
                            [$base]
                        );
                    }

                    if ($mediaId) {
                        $sql = (Database::getDriver() === 'sqlite')
                            ? "INSERT OR IGNORE INTO media_refs (media_id, post_id, revision_id) VALUES (?, ?, ?)"
                            : "INSERT IGNORE INTO media_refs (media_id, post_id, revision_id) VALUES (?, ?, ?)";
                        Database::query(
                            $sql,
                            [$mediaId, $postId, $revisionId]
                        );
                    }
                } catch (\Throwable) {
                    // Ignora caso a tabela ou chave já possua o vínculo
                }
            }
        }
    }
}
