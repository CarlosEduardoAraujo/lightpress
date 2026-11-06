<?php
declare(strict_types=1);
use LightPress\Core\Icon;
use LightPress\Core\Security;
?>
        </div>
    </main>

    <footer class="w-full bg-carbon-black text-pure-white mt-space-xl pt-space-xl pb-space-lg border-t border-track-asphalt">
        <div class="max-w-[1900px] mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-xl">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-space-lg">
                <div class="lg:col-span-2 flex flex-col gap-space-sm">
                    <div class="flex items-center gap-space-sm">
                        <span class="font-headline-sm text-headline-sm uppercase tracking-tighter text-pure-white">SPEED<span class="text-racing-red">RACING</span></span>
                    </div>
                    <p class="font-body-md text-body-md text-secondary-fixed max-w-md"><?= Security::e(!empty($config['app']['description']) ? $config['app']['description'] : 'Automobilismo mundial e nacional com rigor técnico, telemetria e bastidores. Credibilidade ininterrupta em notícias desde 2002.') ?></p>
                    <div class="flex items-center gap-space-xs text-data-telemetry font-data-telemetry text-flag-yellow">
                        <?= Icon::svg('verified', 'text-flag-yellow', 18) ?>
                        <span>Cobertura FIA • CBA • IMSA • ACO</span>
                    </div>
                </div>

                <div class="flex flex-col gap-space-xs">
                    <span class="font-label-category text-label-category uppercase tracking-widest text-racing-red">Fórmulas</span>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/categoria/formula-1/">Fórmula 1</a>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/categoria/formula-2/">Fórmula 2</a>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/categoria/formula-e/">Fórmula E</a>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/categoria/indycar/">IndyCar Series</a>
                </div>

                <div class="flex flex-col gap-space-xs">
                    <span class="font-label-category text-label-category uppercase tracking-widest text-racing-red">Turismo &amp; Endurance</span>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/categoria/stockcar/">Stock Car Pro Series</a>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/categoria/nascar/">NASCAR Cup</a>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/categoria/wec/">FIA WEC / 24h Le Mans</a>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/categoria/motovelocidade/">Motovelocidade</a>
                </div>

                <div class="flex flex-col gap-space-xs">
                    <span class="font-label-category text-label-category uppercase tracking-widest text-racing-red">Institucional</span>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/nossa-equipe/">Nossa Equipe Editorial</a>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/codigo-de-etica/">Código de Ética</a>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/politica-de-privacidade/">Política de Privacidade</a>
                    <a class="font-body-sm text-body-sm text-surface-container hover:text-racing-red transition-colors" href="/contato/">Fale Conosco / Anuncie</a>
                </div>
            </div>

            <div class="w-full h-px bg-track-asphalt"></div>

            <div class="flex flex-col sm:flex-row items-center justify-between gap-space-sm font-label-meta text-label-meta text-secondary-fixed-dim">
                <span>© 2002–<?= date('Y') ?> <?= Security::e($config['app']['name'] ?? 'SpeedRacing') ?> Comunicação Ltda. Todos os direitos reservados.</span>
                <div class="flex items-center gap-space-md">
                    <a class="hover:text-pure-white transition-colors" href="/termos-de-uso/">Termos de Uso</a>
                    <a class="hover:text-pure-white transition-colors" href="/politica-de-privacidade/">Privacidade</a>
                    <a class="hover:text-pure-white transition-colors" href="/feed.xml">RSS Feed</a>
                </div>
            </div>
        </div>
    </footer>
    <?php \LightPress\Core\Hook::doAction('frontend_footer'); ?>
</body>
</html>
