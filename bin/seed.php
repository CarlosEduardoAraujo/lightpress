<?php
declare(strict_types=1);

/**
 * Utilitário CLI de População de Dados (Seeder) em Alta Performance
 * 
 * Execução:
 * php bin/seed.php --count=100
 * php bin/seed.php --count=50000 (para benchmark de grande acervo)
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\BlockCompiler;
use LightPress\Core\Database;
use LightPress\Core\Security;

$options = getopt('', ['count::']);
$count = max(1, (int)($options['count'] ?? 50));

echo "========================================================\n";
echo "   LightPress / SpeedPress - Seeder de Demonstração     \n";
echo "========================================================\n\n";

echo "Iniciando geração de {$count} publicações com blocos semânticos...\n";

$categories = [
    ['slug' => 'formula-1', 'name' => 'Fórmula 1'],
    ['slug' => 'motogp', 'name' => 'MotoGP'],
    ['slug' => 'wec-endurance', 'name' => 'WEC & Endurance'],
    ['slug' => 'stock-car', 'name' => 'Stock Car Pro Series'],
    ['slug' => 'rally', 'name' => 'Rally & Dakar'],
];

// 1. Garantir que as categorias existam
echo "[1/3] Semeando categorias de automobilismo...\n";
foreach ($categories as $cat) {
    if (Database::getDriver() === 'sqlite') {
        Database::query(
            "INSERT INTO categories (slug, name, is_active) VALUES (?, ?, 1)
             ON CONFLICT(slug) DO UPDATE SET name = excluded.name",
            [$cat['slug'], $cat['name']]
        );
    } else {
        Database::query(
            "INSERT INTO categories (slug, name, is_active) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name)",
            [$cat['slug'], $cat['name']]
        );
    }
}

// 2. Garantir autores de demonstração
echo "[2/3] Semeando equipe editorial...\n";
$authors = [
    ['name' => 'Carlos Eduardo', 'username' => 'carlos', 'email' => 'carlos@speedpress.local', 'role' => 'admin'],
    ['name' => 'Mariana Silva', 'username' => 'mariana', 'email' => 'mariana@speedpress.local', 'role' => 'editor'],
    ['name' => 'Lucas Ferreira', 'username' => 'lucas', 'email' => 'lucas@speedpress.local', 'role' => 'author'],
];

$authorIds = [];
$sampleHash = Security::hashPassword('speedpress123');

foreach ($authors as $auth) {
    if (Database::getDriver() === 'sqlite') {
        Database::query(
            "INSERT INTO users (name, username, email, password_hash, role, is_active, session_version)
             VALUES (?, ?, ?, ?, ?, 1, 1)
             ON CONFLICT(email) DO UPDATE SET role = excluded.role",
            [$auth['name'], $auth['username'], $auth['email'], $sampleHash, $auth['role']]
        );
    } else {
        Database::query(
            "INSERT INTO users (name, username, email, password_hash, role, is_active, session_version)
             VALUES (?, ?, ?, ?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE role = VALUES(role)",
            [$auth['name'], $auth['username'], $auth['email'], $sampleHash, $auth['role']]
        );
    }
    $u = Database::fetchOne("SELECT id FROM users WHERE username = ?", [$auth['username']]);
    if ($u) {
        $authorIds[] = (int)$u['id'];
    }
}

if (empty($authorIds)) {
    $authorIds = [1];
}

// 3. Semeando posts em lotes transacionais de 500
echo "[3/3] Semeando {$count} artigos e estatísticas...\n";

$sampleTitles = [
    "Treinos Livres: Novo pacote aerodinâmico surpreende nos setores de alta velocidade",
    "Pole position histórica definida por milésimos na última volta cronometrada",
    "Estratégia de parada única garante vitória espetacular após largada na chuva",
    "Análise Técnica: Como o assoalho com efeito solo redefiniu a temporada",
    "Novo regulamento de motores para 2026: Combustível 100% sustentável e eletrificação",
    "Bastidores do paddock: Negociações de pilotos agitam o mercado para o próximo ano",
    "Disputa eletrizante roda a roda até a linha de chegada emite recorde de audiência",
    "Superando os limites do carro: Equipe celebra pódio inesperado após largar do pitlane",
];

$batchSize = 500;
$batches = (int)ceil($count / $batchSize);
$startTime = microtime(true);
$totalInserted = 0;

for ($b = 0; $b < $batches; $b++) {
    $currentBatchCount = min($batchSize, $count - $totalInserted);

    Database::transaction(function (\PDO $pdo) use ($currentBatchCount, $authorIds, $categories, $sampleTitles, $b, &$totalInserted): void {
        for ($i = 1; $i <= $currentBatchCount; $i++) {
            $num = $totalInserted + $i;
            $cat = $categories[array_rand($categories)];
            $authorId = $authorIds[array_rand($authorIds)];
            $baseTitle = $sampleTitles[array_rand($sampleTitles)];
            $title = "{$baseTitle} #{$num}";
            $slug = Security::slugify("{$title}-{$num}");
            $summary = "Confira a análise aprofundada dos resultados, estratégias de corrida e os principais destaques da etapa número {$num}.";
            $isFeatured = ($num % 25 === 0) ? 1 : 0;
            $randomViews = random_int(10, 85000);

            // Gerar blocos JSON estruturados
            $blocks = [
                ['type' => 'heading', 'level' => 2, 'content' => "Destaques e Análise da Etapa"],
                ['type' => 'paragraph', 'content' => "Em um fim de semana marcado por reviravoltas climáticas e disputas intensas, os pilotos demonstraram controle absoluto em condições extremas de aderência."],
                ['type' => 'quote', 'content' => "O carro estava perfeito na janela ideal de pneus, conseguimos extrair cada décimo possível.", 'caption' => "Declaração pós-corrida"],
                ['type' => 'paragraph', 'content' => "A consistência de ritmo no segundo stint foi o fator determinante para a consolidação da liderança, mantendo o desgaste sob controle."],
            ];

            $contentJson = json_encode(['version' => 1, 'blocks' => $blocks]);
            $contentHtml = BlockCompiler::compile($blocks);

            // Data retroativa distribuída ao longo dos últimos 180 dias
            $secondsAgo = random_int(0, 180 * 86400);
            $pubDate = gmdate('Y-m-d H:i:s', time() - $secondsAgo);

            // Inserir artigo
            Database::query(
                "INSERT INTO posts (
                    user_id, slug, title, summary, category, content_json, content_html,
                    status, is_featured, version, seo_title, seo_description, published_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    'published', ?, 1, ?, ?, ?, ?
                )",
                [
                    $authorId, $slug, $title, $summary, $cat['slug'], $contentJson, $contentHtml,
                    $isFeatured, $title, $summary, $pubDate, $pubDate
                ]
            );

            $postId = (int)Database::lastInsertId();

            // Inserir estatística
            Database::query(
                "INSERT INTO post_stats (post_id, views) VALUES (?, ?)",
                [$postId, $randomViews]
            );
        }
        $totalInserted += $currentBatchCount;
    });

    echo "  Lote " . ($b + 1) . "/{$batches} concluído ({$totalInserted}/{$count} artigos)...\n";
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "\n========================================================\n";
echo "   População concluída com sucesso!\n";
echo "========================================================\n";
echo "Total de artigos inseridos: {$totalInserted}\n";
echo "Tempo de processamento:     {$elapsed}s\n";
echo "Média:                      " . round($totalInserted / max(0.01, $elapsed)) . " posts/segundo\n\n";
