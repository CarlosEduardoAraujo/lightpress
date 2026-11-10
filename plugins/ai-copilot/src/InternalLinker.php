<?php
declare(strict_types=1);

namespace LightPress\Plugin\AiCopilot;

use LightPress\Core\Database;
use LightPress\Core\Security;
use RuntimeException;

/**
 * Módulo de Linkagem Interna Inteligente (SEO Interno).
 * 
 * Implementa filtragem determinística no banco de dados e pareamento semântico
 * com verificação estrita de texto âncora (anti-alucinação).
 */
final class InternalLinker
{
    /**
     * Palavras de parada em português que devem ser ignoradas na extração de termos.
     */
    private const STOP_WORDS = [
        'de', 'a', 'o', 'que', 'e', 'do', 'da', 'em', 'um', 'para', 'com', 'não', 'uma',
        'os', 'no', 'se', 'na', 'por', 'mais', 'as', 'dos', 'como', 'mas', 'foi', 'ao',
        'ele', 'das', 'tem', 'à', 'seu', 'sua', 'ou', 'quando', 'muito', 'nos', 'já',
        'está', 'eu', 'também', 'só', 'pelo', 'pela', 'até', 'isso', 'ela', 'entre',
        'depois', 'sem', 'mesmo', 'aos', 'seus', 'quem', 'nas', 'me', 'esse', 'eles',
        'estão', 'você', 'tinha', 'foram', 'essa', 'num', 'nem', 'suas', 'meu', 'às',
        'minha', 'numa', 'pelos', 'elas', 'qual', 'nós', 'lhe', 'deles', 'essas', 'esses',
        'pelas', 'este', 'dele', 'tu', 'te', 'vocês', 'vos', 'lhes', 'meus', 'minhas',
        'sobre', 'após', 'durante', 'antes', 'contra', 'onde', 'neste', 'nesta', 'disse'
    ];

    /**
     * Busca no banco de dados local artigos candidatos reais já publicados.
     *
     * @return list<array{id: int, title: string, slug: string, summary: string, published_at: string}>
     */
    public static function getCandidateArticles(
        int $currentPostId,
        string $title,
        ?string $tags = null,
        ?string $categoryName = null,
        int $limit = 12
    ): array {
        $driver = Database::getDriver();
        $keywords = self::extractKeywords($title, $tags, $categoryName);

        if (empty($keywords)) {
            // Se não houver palavras-chave extraíveis, retorna matérias recentes publicadas
            $rows = Database::fetchAll(
                "SELECT id, title, slug, summary, published_at 
                 FROM posts 
                 WHERE status = 'published' AND id != ? 
                 ORDER BY published_at DESC 
                 LIMIT ?",
                [$currentPostId, $limit]
            );
            return self::normalizeCandidates($rows);
        }

        if ($driver === 'mysql') {
            // Em MySQL, utiliza o índice FULLTEXT idx_fulltext_search (title, summary, tags)
            $searchQuery = implode(' ', $keywords);
            try {
                $rows = Database::fetchAll(
                    "SELECT id, title, slug, summary, published_at,
                            MATCH(title, summary, tags) AGAINST(? IN NATURAL LANGUAGE MODE) AS score
                     FROM posts
                     WHERE status = 'published' AND id != ?
                       AND MATCH(title, summary, tags) AGAINST(? IN NATURAL LANGUAGE MODE) > 0
                     ORDER BY score DESC, published_at DESC
                     LIMIT ?",
                    [$searchQuery, $currentPostId, $searchQuery, $limit]
                );

                if (!empty($rows)) {
                    return self::normalizeCandidates($rows);
                }
            } catch (\Throwable $e) {
                error_log('[InternalLinker] FULLTEXT query fallback: ' . $e->getMessage());
            }
        }

        // Fallback robusto compatível com SQLite e consultas MySQL sem MATCH
        $whereParts = [];
        $params = [$currentPostId];

        $subConds = [];
        foreach (array_slice($keywords, 0, 5) as $kw) {
            $subConds[] = "(title LIKE ? OR tags LIKE ?)";
            $escaped = '%' . Security::escapeLike($kw) . '%';
            $params[] = $escaped;
            $params[] = $escaped;
        }

        $sql = "SELECT id, title, slug, summary, published_at 
                FROM posts 
                WHERE status = 'published' AND id != ?";

        if (!empty($subConds)) {
            $sql .= " AND (" . implode(' OR ', $subConds) . ")";
        }

        $sql .= " ORDER BY published_at DESC LIMIT ?";
        $params[] = $limit;

        $rows = Database::fetchAll($sql, $params);
        return self::normalizeCandidates($rows);
    }

    /**
     * Processa a matéria atual com o LLM e gera sugestões estruturadas e validadas.
     *
     * @return array{ok: bool, suggestions: list<array<string, mixed>>, candidates_count: int, message?: string}
     */
    public static function suggestLinks(
        int $currentPostId,
        string $title,
        string $contentText,
        ?string $tags = null,
        ?string $categoryName = null
    ): array {
        // Remove tags HTML para análise textual limpa
        $plainText = trim(strip_tags($contentText));
        if (mb_strlen($plainText) < 50) {
            return [
                'ok' => false,
                'suggestions' => [],
                'candidates_count' => 0,
                'message' => 'O texto da matéria é muito curto para sugerir links internos com precisão.',
            ];
        }

        // 1. Busca determinística de candidatos no banco
        $candidates = self::getCandidateArticles($currentPostId, $title, $tags, $categoryName, 12);
        if (empty($candidates)) {
            return [
                'ok' => true,
                'suggestions' => [],
                'candidates_count' => 0,
                'message' => 'Nenhum artigo publicado relevante foi encontrado no banco para cruzamento.',
            ];
        }

        $candidatesMap = [];
        $candidatesJsonList = [];
        foreach ($candidates as $cand) {
            $candidatesMap[$cand['slug']] = $cand;
            $candidatesJsonList[] = [
                'id' => $cand['id'],
                'slug' => $cand['slug'],
                'title' => $cand['title'],
                'summary' => $cand['summary'],
                'published_at' => substr($cand['published_at'], 0, 10),
            ];
        }

        // 2. Montagem dos Prompts
        $systemPrompt = <<<PROMPT
Você é um editor sênior de jornalismo esportivo e automobilismo responsável pelo SEO interno do portal.
Sua missão é selecionar de 2 a 4 artigos da lista de candidatos reais fornecida e indicar como linká-los no texto atual.

DIRETRIZES INEGOCIÁVEIS:
1. Grounding Estrito: Você SÓ pode recomendar artigos presentes na lista "ARTIGOS CANDIDATOS DO BANCO". JAMAIS invente slugs ou links.
2. Texto Âncora Preciso: Para a opção "anchor_text", você DEVE selecionar um trecho EXATO (de 2 a 6 palavras) que JÁ EXISTA literalmente no texto da matéria em edição. Não altere maiúsculas, pontuação ou gênero.
3. Bloco "Leia Também": Para cada artigo, forneça também uma chamada atraente pronta para bloco separado (ex: "Leia também: Título da Notícia").
4. Formato de Saída: Responda EXCLUSIVAMENTE em formato JSON com o seguinte schema:
{
  "suggestions": [
    {
      "slug": "slug-do-artigo",
      "article_id": 123,
      "title": "Título do artigo selecionado",
      "relevance_reason": "Breve justificativa editorial (1 frase)",
      "anchor_text": "trecho exato de 2 a 6 palavras presente na matéria",
      "related_callout": "Leia também: Título atraente"
    }
  ]
}
PROMPT;

        $userPrompt = "MATÉRIA ATUAL EM EDIÇÃO:\n";
        $userPrompt .= "Título: " . $title . "\n";
        if (!empty($categoryName)) {
            $userPrompt .= "Categoria: " . $categoryName . "\n";
        }
        if (!empty($tags)) {
            $userPrompt .= "Tags: " . $tags . "\n";
        }
        $userPrompt .= "Texto:\n" . mb_substr($plainText, 0, 4000) . "\n\n";
        $userPrompt .= "ARTIGOS CANDIDATOS DO BANCO (Fonte Única da Verdade):\n";
        $userPrompt .= json_encode($candidatesJsonList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        // 3. Execução da chamada de IA
        $aiResponse = AiClient::chatJson($systemPrompt, $userPrompt, null, null, 'linking');

        $rawSuggestions = $aiResponse['suggestions'] ?? [];
        if (!is_array($rawSuggestions)) {
            $rawSuggestions = [];
        }

        // 4. Validação e Sanitização Estrita
        $validated = [];
        foreach ($rawSuggestions as $sug) {
            $slug = trim((string)($sug['slug'] ?? ''));
            if (!isset($candidatesMap[$slug])) {
                // Descarta qualquer artigo alucinado que não constava no banco
                continue;
            }

            $cand = $candidatesMap[$slug];
            $anchor = trim((string)($sug['anchor_text'] ?? ''));
            $callout = trim((string)($sug['related_callout'] ?? ''));
            if (empty($callout)) {
                $callout = 'Leia também: ' . $cand['title'];
            }

            // Validação estrita de substring da âncora
            $anchorValid = false;
            if (!empty($anchor) && mb_stripos($plainText, $anchor) !== false) {
                $anchorValid = true;
            }

            $validated[] = [
                'article_id' => $cand['id'],
                'slug' => $cand['slug'],
                'title' => $cand['title'],
                'url' => '/' . ltrim($cand['slug'], '/') . '/',
                'relevance_reason' => trim((string)($sug['relevance_reason'] ?? 'Artigo correlacionado')),
                'anchor_text' => $anchor,
                'anchor_valid' => $anchorValid,
                'related_callout' => $callout,
                'published_at' => $cand['published_at'],
            ];
        }

        return [
            'ok' => true,
            'suggestions' => $validated,
            'candidates_count' => count($candidates),
        ];
    }

    /**
     * Extrai termos de busca significativos a partir do título, tags e categoria.
     *
     * @return list<string>
     */
    private static function extractKeywords(string $title, ?string $tags, ?string $categoryName): array
    {
        $raw = $title . ' ' . ($tags ?? '') . ' ' . ($categoryName ?? '');
        $raw = mb_strtolower($raw, 'UTF-8');
        // Remove pontuações e caracteres especiais
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $raw);
        $tokens = preg_split('/\s+/u', (string)$clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $keywords = [];
        foreach ($tokens as $token) {
            $t = trim($token);
            if (mb_strlen($t) >= 3 && !in_array($t, self::STOP_WORDS, true)) {
                $keywords[$t] = true;
            }
        }

        return array_slice(array_keys($keywords), 0, 10);
    }

    /**
     * Normaliza a lista de artigos candidatos retornados da consulta SQL.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, title: string, slug: string, summary: string, published_at: string}>
     */
    private static function normalizeCandidates(array $rows): array
    {
        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'id' => (int)$r['id'],
                'title' => (string)$r['title'],
                'slug' => (string)$r['slug'],
                'summary' => (string)($r['summary'] ?? ''),
                'published_at' => (string)($r['published_at'] ?? ''),
            ];
        }
        return $result;
    }
}
