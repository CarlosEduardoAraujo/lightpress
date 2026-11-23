<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\PostService;
use LightPress\Core\Hook;
use LightPress\Core\ObjectCache;

echo "========================================================\n";
echo "   Testes Unitários: Database::afterCommit (AUD-ARCH-02)\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();

// 1. Execução imediata fora de transação
$executedOutside = false;
Database::afterCommit(function () use (&$executedOutside) {
    $executedOutside = true;
});

if ($executedOutside) {
    echo "  [PASS] afterCommit executa imediatamente quando fora de transação.\n";
} else {
    echo "  [FAIL] afterCommit NÃO executou imediatamente fora de transação!\n";
    exit(1);
}

// 2. Execução postergada até o commit da transação raiz
$order = [];
Database::transaction(function () use (&$order) {
    $order[] = 'dentro_transacao_inicio';
    Database::afterCommit(function () use (&$order) {
        $order[] = 'callback_pos_commit';
    });
    $order[] = 'dentro_transacao_fim';
});
$order[] = 'fora_transacao';

$expectedOrder = ['dentro_transacao_inicio', 'dentro_transacao_fim', 'callback_pos_commit', 'fora_transacao'];
if ($order === $expectedOrder) {
    echo "  [PASS] afterCommit executa estritamente após o commit da transação.\n";
} else {
    echo "  [FAIL] Ordem de execução incorreta: " . json_encode($order) . "\n";
    exit(1);
}

// 3. Descarte de callbacks em caso de Rollback
$executedOnRollback = false;
try {
    Database::transaction(function () use (&$executedOnRollback) {
        Database::afterCommit(function () use (&$executedOnRollback) {
            $executedOnRollback = true;
        });
        throw new \RuntimeException("Simulação de falha transacional para rollback.");
    });
} catch (\RuntimeException $e) {
    // Esperado
}

if (!$executedOnRollback) {
    echo "  [PASS] afterCommit é descartado e NÃO executa quando ocorre rollback.\n";
} else {
    echo "  [FAIL] afterCommit executou indevidamente mesmo após rollback!\n";
    exit(1);
}

// 4. Transações aninhadas (closure interna com outer transaction)
$nestedOrder = [];
Database::transaction(function () use (&$nestedOrder) {
    $nestedOrder[] = 'outer_inicio';
    Database::transaction(function () use (&$nestedOrder) {
        $nestedOrder[] = 'inner_inicio';
        Database::afterCommit(function () use (&$nestedOrder) {
            $nestedOrder[] = 'callback_pos_commit_aninhado';
        });
        $nestedOrder[] = 'inner_fim';
    });
    $nestedOrder[] = 'outer_fim';
});

$expectedNestedOrder = ['outer_inicio', 'inner_inicio', 'inner_fim', 'outer_fim', 'callback_pos_commit_aninhado'];
if ($nestedOrder === $expectedNestedOrder) {
    echo "  [PASS] afterCommit em transações aninhadas só executa após o commit final da transação externa.\n";
} else {
    echo "  [FAIL] Ordem de transação aninhada incorreta: " . json_encode($nestedOrder) . "\n";
    exit(1);
}

// 5. Isolamento de exceções entre callbacks (um callback com erro não impede os demais)
$callback1Executed = false;
$callback2Executed = false;
Database::transaction(function () use (&$callback1Executed, &$callback2Executed) {
    Database::afterCommit(function () use (&$callback1Executed) {
        $callback1Executed = true;
        throw new \Exception("Erro simulado no webhook 1");
    });
    Database::afterCommit(function () use (&$callback2Executed) {
        $callback2Executed = true;
    });
});

if ($callback1Executed && $callback2Executed) {
    echo "  [PASS] Falha em um callback pós-commit não interrompe os callbacks subsequentes.\n";
} else {
    echo "  [FAIL] Exceção em callback interrompeu os demais callbacks pós-commit!\n";
    exit(1);
}

// 6. Teste de integração com PostService::publishOrUpdate em rollback
$hookTriggered = false;
Hook::addAction('post_published', function () use (&$hookTriggered) {
    $hookTriggered = true;
});

// Criar um post de teste para validação
$postService = new PostService();
$adminUser = Database::fetchOne("SELECT id FROM users WHERE role = 'admin' LIMIT 1") ?: ['id' => 1];
$userId = (int)$adminUser['id'];

$revId = null;
$createdPostId = $postService->createPost([
    'title' => 'Artigo Teste AfterCommit ' . time(),
    'content_html' => '<p>Conteúdo de teste</p>',
    'status' => 'draft',
], $userId, $revId);

$createdPost = Database::fetchOne("SELECT * FROM posts WHERE id = ?", [$createdPostId]);
$knownVersion = (int)$createdPost['version'];

// Simular reversão com transação externa que faz rollback
try {
    Database::transaction(function () use ($postService, $createdPostId, $userId, $knownVersion) {
        $postService->publishOrUpdate($createdPostId, [
            'title' => 'Artigo Atualizado Teste',
            'status' => 'published',
            'content_html' => '<p>Conteúdo publicado</p>',
        ], $userId, $knownVersion);

        // Força erro antes de commitar a transação externa
        throw new \RuntimeException("Falha antes do commit externo");
    });
} catch (\RuntimeException) {
    // Esperado
}

if (!$hookTriggered) {
    echo "  [PASS] publishOrUpdate dentro de transação com rollback NÃO disparou webhooks/purgas.\n";
} else {
    echo "  [FAIL] publishOrUpdate disparou hook prematuramente antes do commit externo!\n";
    exit(1);
}

// Limpeza do post de teste
Database::query("DELETE FROM post_revisions WHERE post_id = ?", [$createdPostId]);
Database::query("DELETE FROM post_stats WHERE post_id = ?", [$createdPostId]);
Database::query("DELETE FROM posts WHERE id = ?", [$createdPostId]);

echo "\n>>> TODOS OS TESTES DE AFTER-COMMIT FORAM APROVADOS! <<<\n";
