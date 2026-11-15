<?php
declare(strict_types=1);

require __DIR__ . '/header.php';
?>

<div style="text-align: center; padding: 5rem 0;">
    <h1 style="font-size: 4rem; font-weight: 800; color: #ef4444; margin-bottom: 1rem;">500</h1>
    <h2 style="font-size: 1.75rem; font-weight: 700; color: var(--primary); margin-bottom: 1rem;">Instabilidade Temporária</h2>
    <p style="color: var(--text-muted); max-width: 500px; margin: 0 auto 2rem auto;">
        Nosso servidor encontrou uma inconsistência ao processar esta requisição. O evento foi registrado para análise da equipe técnica.
    </p>
    <a href="/" style="display: inline-block; padding: 0.75rem 1.5rem; background: var(--primary-accent); color: white; text-decoration: none; border-radius: var(--radius); font-weight: 600;">
        Voltar para a Página Inicial
    </a>
</div>

<?php require __DIR__ . '/footer.php'; ?>
