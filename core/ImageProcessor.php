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
            @mkdir($targetDir, 0755, true);
        }
        if (!is_dir($this->stagingDir)) {
            @mkdir($this->stagingDir, 0755, true);
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
        while (file_exists("{$targetDir}/{$fileBase}-1920.webp") || file_exists("{$targetDir}/{$fileBase}-1024.webp")) {
            $fileBase = "{$cleanSlug}-{$counter}";
            $counter++;
        }

        $canAvif = function_exists('imageavif');

        $manifest = [
            'media_id' => $mediaId,
            'original_name' => $originalName,
            'original_width' => $origWidth,
            'original_height' => $origHeight,
            'variants' => [],
        ];

        try {
            // Definição das 3 resoluções padronizadas
            $specs = [
                'full' => ['max_w' => 1920, 'suffix' => '1920', 'crop' => false],
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

                // Geração do WebP Obrigatório
                $webpFilename = "{$fileBase}-{$spec['suffix']}.webp";
                $webpStaging = "{$this->stagingDir}/{$webpFilename}";
                $webpTarget = "{$targetDir}/{$webpFilename}";

                imagewebp($resized, $webpStaging, $this->webpQuality);
                if (file_exists($webpStaging) && filesize($webpStaging) > 0) {
                    rename($webpStaging, $webpTarget);
                    $manifest['variants'][$key]['webp'] = [
                        'url' => "{$relativeDir}/{$webpFilename}",
                        'width' => $w,
                        'height' => $h,
                        'mime' => 'image/webp',
                    ];
                } else {
                    @unlink($webpStaging);
                    throw new RuntimeException("Falha ao gerar variante WebP ({$key}).", 500);
                }

                // Geração do AVIF Opcional (se biblioteca suportada e sem falhas)
                if ($canAvif) {
                    $avifFilename = "{$fileBase}-{$spec['suffix']}.avif";
                    $avifStaging = "{$this->stagingDir}/{$avifFilename}";
                    $avifTarget = "{$targetDir}/{$avifFilename}";

                    try {
                        $avifSuccess = @imageavif($resized, $avifStaging, $this->avifQuality, $this->avifSpeed);
                        if ($avifSuccess && file_exists($avifStaging) && filesize($avifStaging) > 0) {
                            rename($avifStaging, $avifTarget);
                            $manifest['variants'][$key]['avif'] = [
                                'url' => "{$relativeDir}/{$avifFilename}",
                                'width' => $w,
                                'height' => $h,
                                'mime' => 'image/avif',
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
}
