<?php
declare(strict_types=1);

namespace LightPress\Plugin\AiCopilot;

use LightPress\Core\Database;
use LightPress\Core\Security;
use RuntimeException;

/**
 * Módulo de Visão Computacional para Catálogo de Mídias.
 * 
 * Envia variantes compactadas WebP (1024px intermediate) para o modelo multimodal
 * e gera metadados de acessibilidade (alt_text), legendas jornalísticas (caption)
 * e tags visuais (ai_tags).
 */
final class MediaVision
{
    /**
     * Analisa uma mídia existente pelo ID e grava os metadados gerados pela IA no banco de dados.
     *
     * @return array{ok: bool, media_id: string, alt_text: string, caption: string, ai_tags: string}
     */
    public static function describeMedia(string $mediaId): array
    {
        $media = Database::fetchOne("SELECT * FROM media WHERE id = ? LIMIT 1", [$mediaId]);
        if (!$media) {
            throw new RuntimeException("Mídia não encontrada no banco de dados.");
        }

        $manifest = json_decode((string)$media['variants_manifest'], true) ?? [];
        $filePath = self::resolveLocalFilePath($manifest);
        if (!$filePath || !file_exists($filePath)) {
            throw new RuntimeException("Arquivo físico da imagem não foi localizado em disco.");
        }

        $fileBytes = file_get_contents($filePath);
        if ($fileBytes === false || strlen($fileBytes) === 0) {
            throw new RuntimeException("Falha ao ler bytes da imagem.");
        }

        $mimeType = str_ends_with(strtolower($filePath), '.avif') ? 'image/avif' : 'image/webp';
        $base64 = base64_encode($fileBytes);
        $originalName = (string)$media['original_name'];

        $systemPrompt = <<<PROMPT
Você é um editor fotográfico especializado em jornalismo esportivo e automobilismo (LightPress).
Analise a imagem com rigor factual e retorne uma descrição para acessibilidade, uma legenda jornalística e tags visuais em português do Brasil.

REGRAS DE FORMATAÇÃO:
1. "alt_text": Descrição visual objetiva para deficientes visuais e Google Imagens (máximo 125 caracteres). Ex: "Carro da Ferrari sob chuva contornando curva do S do Senna".
2. "caption": Sugestão de legenda jornalística rica para a tag <figcaption> (máximo 250 caracteres).
3. "ai_tags": Lista de 5 a 8 termos técnicos e descritivos separados por vírgula em minúsculas (ex: "fórmula 1, ferrari, chuva, interlagos, monoposto, curva").
4. Responda ESTRITAMENTE em formato JSON com o schema:
{
  "alt_text": "...",
  "caption": "...",
  "ai_tags": "..."
}
PROMPT;

        $userPrompt = "Nome original do arquivo enviado: " . $originalName . "\nDescreva visualmente esta imagem conforme as instruções.";

        $aiResponse = AiClient::chatJson($systemPrompt, $userPrompt, $base64, $mimeType, 'vision');

        $altText = mb_substr(trim(strip_tags((string)($aiResponse['alt_text'] ?? ''))), 0, 255);
        $caption = mb_substr(trim(strip_tags((string)($aiResponse['caption'] ?? ''))), 0, 500);
        $aiTags = mb_substr(trim(strip_tags((string)($aiResponse['ai_tags'] ?? ''))), 0, 500);

        if (empty($altText)) {
            $altText = pathinfo($originalName, PATHINFO_FILENAME);
        }
        if (empty($caption)) {
            $caption = $altText;
        }

        // Persiste no banco de dados
        Database::query(
            "UPDATE media SET alt_text = ?, caption = ?, ai_tags = ? WHERE id = ?",
            [$altText, $caption, $aiTags, $mediaId]
        );

        return [
            'ok' => true,
            'media_id' => $mediaId,
            'alt_text' => $altText,
            'caption' => $caption,
            'ai_tags' => $aiTags,
        ];
    }

    /**
     * Atualiza manualmente os metadados de uma mídia sem acionar a IA.
     *
     * @return array{ok: bool, media_id: string}
     */
    public static function saveMediaMeta(string $mediaId, ?string $altText, ?string $caption, ?string $aiTags): array
    {
        $alt = mb_substr(trim(strip_tags((string)$altText)), 0, 255);
        $cap = mb_substr(trim(strip_tags((string)$caption)), 0, 500);
        $tags = mb_substr(trim(strip_tags((string)$aiTags)), 0, 500);

        Database::query(
            "UPDATE media SET alt_text = ?, caption = ?, ai_tags = ? WHERE id = ?",
            [$alt, $cap, $tags, $mediaId]
        );

        return [
            'ok' => true,
            'media_id' => $mediaId,
        ];
    }

    /**
     * Resolve o caminho físico em disco da variante mais econômica e adequada da imagem.
     * Prioridade: intermediate WebP (1024px) -> thumb WebP (300px) -> full WebP.
     */
    private static function resolveLocalFilePath(array $variants): ?string
    {
        $root = dirname(__DIR__, 3); // C:\Web\lightpress

        $candidates = [
            $variants['intermediate']['webp']['url'] ?? null,
            $variants['thumb']['webp']['url'] ?? null,
            $variants['full']['webp']['url'] ?? null,
            $variants['intermediate']['avif']['url'] ?? null,
            $variants['thumb']['avif']['url'] ?? null,
            $variants['full']['avif']['url'] ?? null,
        ];

        foreach ($candidates as $relUrl) {
            if (empty($relUrl) || !is_string($relUrl)) {
                continue;
            }

            // Normaliza URL relativa (ex: /uploads/2026/11/foto.webp)
            $cleanRel = ltrim($relUrl, '/\\');
            $fullPath = $root . '/public/' . $cleanRel;

            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }
}
