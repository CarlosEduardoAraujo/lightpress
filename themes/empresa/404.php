<?php
declare(strict_types=1);

require __DIR__ . '/header.php';
?>

<main class="corp-section">
    <div class="corp-container" style="text-align: center; padding: 6rem 1rem;">
        <div style="font-size: 5rem; font-weight: 900; color: var(--corp-primary); line-height: 1; margin-bottom: 1rem;">
            404
        </div>
        <h1 class="corp-section-title" style="margin-bottom: 1rem;">Página Não Encontrada</h1>
        <p class="corp-section-sub" style="max-width: 500px; margin: 0 auto 2.5rem;">
            O endereço que você tentou acessar não existe, foi renomeado ou está temporariamente indisponível.
        </p>
        <div style="display: flex; justify-content: center; gap: 1rem;">
            <a href="/" class="corp-btn corp-btn-primary">Ir para a Página Inicial</a>
            <a href="/blog/" class="corp-btn corp-btn-secondary">Acessar o Blog</a>
        </div>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
