<?php
declare(strict_types=1);

namespace LightPress\Core {
    class PluginManager { public static function registerActivationHook($p, $cb) {} }
    class Hook { public static function addAction($h, $cb) {} }
}

namespace {
    require_once __DIR__ . '/../plugins/webhook-sender/webhook-sender.php';
    WebhookSenderService::setDnsMock([
        'discord.com' => ['162.159.135.232'],
        'meuservidor.com' => ['93.184.216.34'],
        'insecure.test' => ['93.184.216.35'],
        'malicious-rebinding.com' => ['162.159.135.232', '169.254.169.254'],
        'ipv6-loopback.com' => ['::1'],
    ]);

    $tests = [
        '192.168.3.165:5678 should be allowed' => WebhookSenderService::isSafeUrl('http://192.168.3.165:5678/webhook') === true,
        '127.0.0.1 should be blocked' => WebhookSenderService::isSafeUrl('http://127.0.0.1:8080/webhook') === false,
        '::1 should be blocked' => WebhookSenderService::isSafeUrl('http://[::1]:8080/webhook') === false,
        '169.254.169.254 should be blocked' => WebhookSenderService::isSafeUrl('http://169.254.169.254/latest/meta-data') === false,
        'metadata.google.internal should be blocked' => WebhookSenderService::isSafeUrl('http://metadata.google.internal/computeMetadata/v1') === false,
        'malicious rebinding should be blocked' => WebhookSenderService::isSafeUrl('http://malicious-rebinding.com/hook') === false,
        'ipv6 loopback should be blocked' => WebhookSenderService::isSafeUrl('http://ipv6-loopback.com/hook') === false,
        'discord.com should be allowed' => WebhookSenderService::isSafeUrl('https://discord.com/api/webhooks/123/abc') === true,
    ];

    $failed = 0;
    foreach ($tests as $desc => $result) {
        if ($result) {
            echo "  [PASS] {$desc}\n";
        } else {
            echo "  [FAIL] {$desc}\n";
            $failed++;
        }
    }

    if ($failed === 0) {
        echo "\n>>> TODOS OS TESTES SSRF PASSARAM COM SUCESSO! <<<\n";
        exit(0);
    }
    exit(1);
}
