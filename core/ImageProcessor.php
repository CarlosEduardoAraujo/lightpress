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
     * Resolve o texto descritivo real da imagem (ALT / TITLE) a partir dos metadados da mídia.
     * Retorna estritamente metadados da imagem (alt_text ou caption da IA, ou nome do arquivo amigável).
     * NUNCA retorna o título da matéria jornalística.
     *
     * @param string $imageUrl URL da imagem
     * @param ?string $fallbackAlt Texto alternativo opcional se já conhecido
     * @return string Descrição visual relacionada estritamente à imagem
     */
    public static function getImageDescription(string $imageUrl, ?string $fallbackAlt = null): string
    {
        if (!empty($fallbackAlt)) {
            return trim($fallbackAlt);
        }

        if (empty($imageUrl)) {
            return '';
        }

        $cleanPath = parse_url($imageUrl, PHP_URL_PATH) ?? $imageUrl;
        $cleanPath = ltrim((string)$cleanPath, '/');
        $baseName = pathinfo($cleanPath, PATHINFO_FILENAME);
        $cleanBase = preg_replace('~-(1024|1920|thumb|\d+x\d+)$~i', '', $baseName);

        try {
            $likePath = '%' . Security::escapeLike($cleanPath) . '%';
            $likeEscaped = '%' . Security::escapeLike(str_replace('/', '\/', $cleanPath)) . '%';
            $likeBase = '%' . Security::escapeLike((string)$cleanBase) . '%';

            $media = Database::fetchOne(
                "SELECT alt_text, caption, original_name FROM media 
                 WHERE variants_manifest LIKE ? 
                    OR variants_manifest LIKE ? 
                    OR original_name LIKE ? 
                    OR variants_manifest LIKE ? 
                 ORDER BY (alt_text IS NOT NULL AND alt_text != '') DESC, id DESC 
                 LIMIT 1",
                [$likePath, $likeEscaped, $likeBase, $likeBase]
            );

            if ($media) {
                if (!empty($media['alt_text'])) {
                    return trim((string)$media['alt_text']);
                }
                if (!empty($media['caption'])) {
                    return trim((string)$media['caption']);
                }
                if (!empty($media['original_name'])) {
                    $origBase = pathinfo((string)$media['original_name'], PATHINFO_FILENAME);
                    $cleanOrig = preg_replace('~-(1024|1920|thumb|\d+x\d+)$~i', '', $origBase);
                    if (!preg_match('/^(image|img|dsc|whatsapp|foto|\d+)$/i', (string)$cleanOrig)) {
                        return trim(ucwords(str_replace(['-', '_'], ' ', (string)$cleanOrig)));
                    }
                }
            }
        } catch (\Throwable $e) {
            // Em caso de falha de banco, prossegue para extração de nome
        }

        // Formata o nome do arquivo da imagem como descrição amigável se não for genérico
        if (!empty($cleanBase) && !preg_match('/^(image|img|dsc|whatsapp|foto|\d+)$/i', (string)$cleanBase)) {
            return trim(ucwords(str_replace(['-', '_'], ' ', (string)$cleanBase)));
        }

        return '';
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

        // Se já existir arquivo com o mesmo nome na pasta de destino, incrementa sufixo numérico (-1, -2, etc.)
        $fileBase = $cleanSlug;
        $counter = 1;
        while (
            file_exists("{$targetDir}/{$fileBase}.webp") || 
            file_exists("{$targetDir}/{$fileBase}.avif") || 
            file_exists("{$targetDir}/{$fileBase}-1920.webp") || 
            file_exists("{$targetDir}/{$fileBase}-1024.webp")
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
     * Sincroniza vínculos normalizados entre mídias e artigos/revisões na tabela media_refs.
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
                    $mediaId = Database::fetchValue(
                        "SELECT id FROM media WHERE variants_manifest LIKE ? LIMIT 1",
                        ['%' . Security::escapeLike($url) . '%']
                    );
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
