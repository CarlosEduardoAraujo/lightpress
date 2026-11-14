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

foreach ($writablePaths as $label => $path) {
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    if (is_writable($path)) {
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
