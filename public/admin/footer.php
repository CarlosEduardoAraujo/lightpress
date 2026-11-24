<?php
declare(strict_types=1);

/**
 * LightPress - Footer Unificado da Área Administrativa
 */

$adminJsVer = @filemtime(dirname(__DIR__) . '/assets/admin/admin.js') ?: '1';
?>
                </main>
            </div>
        </div>
    <script src="/assets/admin/admin.js?v=<?= $adminJsVer ?>"></script>
</body>
</html>
