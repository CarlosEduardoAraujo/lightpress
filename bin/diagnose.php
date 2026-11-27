<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Acesso proibido via web.\n";
    exit(1);
}

/**
 * Ferramenta CLI de Diagnóstico de Ambiente - LightPress
 * Execução: php bin/diagnose.php
 */

$config = require __DIR__ . '/../config.php';

echo "========================================================\n";
echo "   LightPress / SpeedPress - Diagnóstico de Ambiente    \n";
echo "========================================================\n\n";

$errors = [];
$warnings = [];

// 1. Versão do PHP
$phpVersion = PHP_VERSION;
echo "[PHP] Versão atual: {$phpVersion}\n";
if (version_compare($phpVersion, '8.4.0', '>=')) {
    echo "  [OK] PHP 8.4+ detectado.\n";
} elseif (version_compare($phpVersion, '8.2.0', '>=')) {
    echo "  [AVISO] PHP {$phpVersion} detectado. A especificação recomenda PHP 8.4 para recursos completos.\n";
    $warnings[] = "Versão do PHP ({$phpVersion}) inferior a 8.4.0.";
} else {
    echo "  [ERRO] PHP 8.2+ é requerido minimamente para desenvolvimento. Versão atual não suportada.\n";
    $errors[] = "PHP desatualizado: {$phpVersion}.";
}

// 2. Extensões Obrigatórias
$requiredExtensions = [
    'pdo' => 'Camada de banco de dados unificada',
    'gd' => 'Processamento de imagens',
    'fileinfo' => 'Validação segura de MIME types reais',
    'mbstring' => 'Manipulação de strings UTF-8 / multi-byte',
    'dom' => 'Sanitização HTML e compilação de blocos (DOMDocument)',
    'curl' => 'Integrações HTTP seguras (Cloudflare, Webhooks, IA)',
    'session' => 'Gestão de sessões administrativas',
    'json' => 'Manipulação e validação do esquema de blocos',
];

echo "\n[Extensões Obrigatórias]\n";
foreach ($requiredExtensions as $ext => $desc) {
    if (extension_loaded($ext)) {
        echo "  [OK] {$ext} ({$desc})\n";
    } else {
        echo "  [ERRO] Extensão '{$ext}' AUSENTE! ({$desc})\n";
        $errors[] = "Extensão PHP ausente: {$ext}";
    }
}

// Drivers de Banco de Dados
echo "\n[Drivers de Banco de Dados]\n";
$hasMysql = extension_loaded('pdo_mysql');
$hasSqlite = extension_loaded('pdo_sqlite');

if ($hasMysql) {
    echo "  [OK] pdo_mysql disponível.\n";
} else {
    echo "  [AVISO] pdo_mysql não carregado.\n";
}

if ($hasSqlite) {
    echo "  [OK] pdo_sqlite disponível.\n";
} else {
    echo "  [INFO] pdo_sqlite não carregado.\n";
}

if (!$hasMysql && !$hasSqlite) {
    $errors[] = "Nenhum driver PDO suportado (pdo_mysql ou pdo_sqlite) está disponível.";
}

// 3. Capacidades de Imagem (GD, WebP e AVIF)
echo "\n[Pipeline de Imagem - PHP GD]\n";
if (extension_loaded('gd')) {
    $gdInfo = gd_info();
    
    // WebP (Obrigatório)
    $hasWebpRead = !empty($gdInfo['WebP Support']);
    $hasWebpWrite = function_exists('imagewebp');
    if ($hasWebpRead && $hasWebpWrite) {
        echo "  [OK] WebP: Suporte a leitura e escrita ativo.\n";
    } else {
        echo "  [ERRO] WebP: Suporte não disponível no GD! Uploads serão bloqueados.\n";
        $errors[] = "GD não possui suporte a WebP.";
    }

    // AVIF (Opcional com fallback)
    $hasAvifRead = !empty($gdInfo['AVIF Support']) || function_exists('imagecreatefromavif');
    $hasAvifWrite = function_exists('imageavif');
    if ($hasAvifRead && $hasAvifWrite) {
        echo "  [OK] AVIF: Suporte a leitura e escrita ativo (libavif detectada).\n";
    } else {
        echo "  [AVISO] AVIF: Suporte ausente ou incompleto no GD. Fallback para WebP será ativado.\n";
        $warnings[] = "AVIF não disponível; o sistema funcionará exclusivamente com WebP.";
    }
} else {
    echo "  [ERRO] GD não está instalado!\n";
}

// 4. Hashing de Senhas (Argon2id)
echo "\n[Criptografia e Senhas]\n";
if (defined('PASSWORD_ARGON2ID')) {
    echo "  [OK] PASSWORD_ARGON2ID suportado nativamente.\n";
} else {
    echo "  [ERRO] PASSWORD_ARGON2ID não suportado neste ambiente PHP.\n";
    $errors[] = "Algoritmo Argon2id não suportado.";
}

// 5. Permissões de Diretórios
echo "\n[Permissões do Sistema de Arquivos]\n";
$writablePaths = [
    'storage/staging' => $config['media']['staging_dir'],
    'storage/logs' => $config['paths']['storage'] . '/logs',
    'storage/migration' => $config['paths']['storage'] . '/migration',
    'public/uploads' => $config['media']['upload_dir'],
];

$isDirWritable = function (string $dir): bool {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $testFile = rtrim($dir, '/\\') . '/.lp_perm_' . bin2hex(random_bytes(4));
        $written = @file_put_contents($testFile, 'test');
        if ($written !== false) {
            @unlink($testFile);
            return true;
        }
        return false;
    }
    return is_writable($dir);
};

foreach ($writablePaths as $label => $path) {
    if ($isDirWritable($path)) {
        echo "  [OK] {$label} tem permissão de escrita.\n";
    } else {
        echo "  [ERRO] {$label} ({$path}) NÃO tem permissão de escrita!\n";
        $errors[] = "Diretório sem permissão de escrita: {$label}";
    }
}

// 6. Diretório de Cache FastCGI Nginx
echo "\n[Nginx FastCGI Cache]\n";
$cachePath = $config['cache']['cache_path'];
if (is_dir($cachePath)) {
    if (is_writable($cachePath)) {
        echo "  [OK] {$cachePath} acessível e gravável pelo processo atual.\n";
    } else {
        echo "  [AVISO] {$cachePath} existe mas NÃO é gravável pelo usuário atual.\n";
        $warnings[] = "Permissão de escrita ausente em {$cachePath} (normal se executado fora do servidor de produção).";
    }
} else {
    echo "  [INFO] {$cachePath} não encontrado localmente (esperado em ambiente Linux/Nginx em produção).\n";
}

// 6b. OPcache e Path Cache
echo "\n[OPcache & Path Cache]\n";
$opcacheLoaded = extension_loaded('Zend OPcache') || function_exists('opcache_get_status');
if ($opcacheLoaded) {
    $opEnable = ini_get('opcache.enable');
    $opCli = ini_get('opcache.enable_cli');
    $opStatus = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    echo "  [OK] Extensão OPcache carregada (opcache.enable={$opEnable}, opcache.enable_cli={$opCli}).\n";
    if (is_array($opStatus) && !empty($opStatus['opcache_enabled'])) {
        $hitRate = $opStatus['opcache_statistics']['opcache_hit_rate'] ?? 0.0;
        $scripts = $opStatus['opcache_statistics']['num_cached_scripts'] ?? 0;
        echo "  [OK] OPcache ativo no SAPI atual (scripts={$scripts}, hit_rate=" . round((float)$hitRate, 2) . "%).\n";
    } else {
        echo "  [INFO] OPcache inativo no SAPI CLI (normal se opcache.enable_cli=0; valide no PHP-FPM via /admin/system.php).\n";
    }
} else {
    echo "  [AVISO] Extensão Zend OPcache não carregada neste runtime PHP.\n";
    $warnings[] = "Extensão OPcache não detectada no PHP CLI atual.";
}

// 6c. Redis Cache Pro & Extensão phpredis
echo "\n[Redis Cache Pro (ObjectCache)]\n";
if (extension_loaded('redis')) {
    echo "  [OK] Extensão phpredis carregada (v" . (phpversion('redis') ?: '?') . ").\n";
} else {
    echo "  [AVISO] Extensão phpredis não carregada neste ambiente PHP.\n";
    $warnings[] = "Extensão phpredis ausente no PHP atual (ObjectCache operará em fallback sem Redis).";
}

// 7. Chave HMAC Anti-Abuso (Segurança)
echo "\n[Chave Secreta HMAC (Anti-Abuso de Views)]\n";
$hmacSecret = (string)($config['security']['hmac_secret'] ?? '');
if ($hmacSecret === 'ALTERAR_PARA_UMA_CHAVE_ALEATORIA_DE_64_CHARS_EM_PRODUCAO' || strlen($hmacSecret) < 32) {
    echo "  [AVISO CRÍTICO] hmac_secret é a chave padrão ou possui menos de 32 caracteres!\n";
    echo "  -> Qualquer pessoa ou robô pode forjar contagem de visualizações (/api/view.php).\n";
    echo "  -> Configure 'security' => ['hmac_secret' => '" . bin2hex(random_bytes(32)) . "'] em config.local.php.\n";
    $warnings[] = "Chave de segurança hmac_secret vulnerável (padrão ou < 32 caracteres). Configure uma chave forte em config.local.php.";
} else {
    echo "  [OK] hmac_secret configurado com comprimento seguro (" . strlen($hmacSecret) . " caracteres).\n";
}

// 8. Chave de Criptografia em Repouso (Segurança - AUD-SEC-01)
echo "\n[Chave de Criptografia em Repouso (security.encryption_key)]\n";
$encKey = (string)($config['security']['encryption_key'] ?? '');
if (empty($encKey) || strlen(trim($encKey)) < 32) {
    echo "  [AVISO] security.encryption_key não está configurada ou possui menos de 32 caracteres.\n";
    echo "  -> Segredos sensíveis (tokens de IA, webhooks, credenciais do Redis) não poderão ser cifrados em repouso no banco de dados.\n";
    echo "  -> Configure 'security' => ['encryption_key' => '...'] em config.local.php.\n";
    $warnings[] = "Chave security.encryption_key ausente ou com baixa entropia (< 32 chars). Configure em config.local.php.";
} else {
    echo "  [OK] security.encryption_key configurada com comprimento seguro (" . strlen(trim($encKey)) . " caracteres).\n";
}

// Resumo Final
echo "\n========================================================\n";
echo "   Resumo do Diagnóstico\n";
echo "========================================================\n";
if (empty($errors)) {
    echo "Status: APROVADO PARA DESENVOLVIMENTO " . (empty($warnings) ? "SEM RESSALVAS!" : "COM AVISOS.") . "\n";
    if (!empty($warnings)) {
        echo "\nAvisos:\n";
        foreach ($warnings as $w) {
            echo " - {$w}\n";
        }
    }
} else {
    echo "Status: REPROVADO COM ERROS CRÍTICOS:\n";
    foreach ($errors as $e) {
        echo " [X] {$e}\n";
    }
}
echo "\n";
