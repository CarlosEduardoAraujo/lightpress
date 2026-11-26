<?php
declare(strict_types=1);

/**
 * Testes de Regressão - Correções da Auditoria 20261125-gh-anti.md
 * Execução: php bin/test-audit-sec-perf.php
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\BlockCompiler;
use LightPress\Core\Security;
use LightPress\Core\Settings;

echo "========================================================\n";
echo "   LightPress - Validação Auditoria (SEC-01/03/05, PERF-03)\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertAuditTest(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$label}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
        $failed++;
    }
}

// 1. SEC-01: Sanitização estrita de <iframe> em BlockCompiler::sanitizeHtml()
echo "[1] SEC-01: Sanitização de <iframe>\n";

$evilIframe = '<p>Texto</p><iframe src="https://evil.example.com/phish" allow="camera *; microphone *; geolocation *"></iframe>';
$cleanEvil = BlockCompiler::sanitizeHtml($evilIframe);
assertAuditTest(
    'Remove iframe de domínio arbitrário não-YouTube',
    !str_contains($cleanEvil, '<iframe') && str_contains($cleanEvil, 'Texto'),
    $cleanEvil
);

$ytIframe = '<iframe src="https://www.youtube.com/watch?v=dQw4w9WgXcQ" allow="camera *; autoplay" width="560" height="315"></iframe>';
$cleanYt = BlockCompiler::sanitizeHtml($ytIframe);
assertAuditTest(
    'Preserva iframe do YouTube normalizado para youtube-nocookie.com',
    str_contains($cleanYt, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'),
    $cleanYt
);
assertAuditTest(
    'Remove atributo allow arbitrário (camera *) e aplica sandbox seguro no iframe YouTube',
    !str_contains($cleanYt, 'camera') && str_contains($cleanYt, 'sandbox="allow-scripts allow-same-origin allow-presentation"'),
    $cleanYt
);

// 2. SEC-03: Placeholder e fail-fast em Settings::encryptValue()
echo "\n[2] SEC-03: encryption_key placeholder e fail-fast em produção\n";

$origEnv = $GLOBALS['config']['app']['env'] ?? 'development';
$origKey = $GLOBALS['config']['security']['encryption_key'] ?? null;

Settings::reset();
$GLOBALS['config']['security']['encryption_key'] = 'MUDAESSAINFORMACAOANTESDESUBIROSITE';
assertAuditTest(
    'Placeholder padrão é tratado como chave ausente (null) em getEncryptionKey()',
    Settings::getEncryptionKey() === null
);

$GLOBALS['config']['app']['env'] = 'production';
$threwOnMissingKey = false;
try {
    Settings::encryptValue('segredo-api');
} catch (\RuntimeException) {
    $threwOnMissingKey = true;
}
assertAuditTest(
    'Settings::encryptValue() lança RuntimeException em produção sem chave válida',
    $threwOnMissingKey
);

$GLOBALS['config']['app']['env'] = $origEnv;
$GLOBALS['config']['security']['encryption_key'] = $origKey;
Settings::init($origKey);

// 3. SEC-05: Rate limit por conta (scope login_acct)
echo "\n[3] SEC-05: Rate limit por identificador de conta\n";

$acctKey = 'acct_' . hash('sha256', 'audit_test_user@example.com');
Security::clearLoginFailures($acctKey, 'login_acct');
assertAuditTest(
    'Conta sem falhas não está bloqueada',
    Security::checkLoginRateLimit($acctKey, 3, 60, 'login_acct') === null
);

Security::recordLoginFailure($acctKey, 3, 60, 'login_acct');
Security::recordLoginFailure($acctKey, 3, 60, 'login_acct');
Security::recordLoginFailure($acctKey, 3, 60, 'login_acct');
$remaining = Security::checkLoginRateLimit($acctKey, 3, 60, 'login_acct');
assertAuditTest(
    'Conta é bloqueada após atingir o limite de falhas',
    $remaining !== null && $remaining > 0
);
Security::clearLoginFailures($acctKey, 'login_acct');
assertAuditTest(
    'clearLoginFailures() limpa o bloqueio da conta',
    Security::checkLoginRateLimit($acctKey, 3, 60, 'login_acct') === null
);

echo "\nResultado: {$passed} passaram, {$failed} falharam.\n";
exit($failed > 0 ? 1 : 0);
