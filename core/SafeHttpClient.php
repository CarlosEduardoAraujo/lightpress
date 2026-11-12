<?php
declare(strict_types=1);

namespace LightPress\Core;

use RuntimeException;
use Throwable;

/**
 * Cliente HTTP Seguro e Validador Centralizado contra SSRF, DNS Rebinding e Acessos Indevidos.
 * Fornece perfis defensivos segregados para webhooks, IA local e aquecimento de cache.
 */
final class SafeHttpClient
{
    public const PROFILE_STRICT_PUBLIC    = 'strict_public';    // Bloqueia loopback, RFC1918 e metadados (Padrão para Webhooks externos/Alertas)
    public const PROFILE_LAN              = 'lan';              // Permite RFC1918/Unique Local; bloqueia loopback e metadados (Webhooks internos n8n)
    public const PROFILE_LOCAL_AI         = 'local_ai';         // Permite 127.0.0.1 e RFC1918; bloqueia rigorosamente metadados de cloud (Ollama/vLLM)
    public const PROFILE_LOCAL_ORIGIN_ONLY = 'local_origin_only'; // Restringe exclusivamente ao host de canonical_origin (WarmupService)

    /**
     * Tabela de mock de resolução DNS para testes automatizados determinísticos.
     * @var array<string, list<string>>|null
     */
    public static ?array $dnsMock = null;

    /**
     * Define ou limpa a tabela de mock DNS para testes.
     * @param array<string, list<string>>|null $mock
     */
    public static function setDnsMock(?array $mock): void
    {
        self::$dnsMock = $mock;
    }

    /**
     * Valida se um IP é seguro de acordo com o perfil de execução solicitado.
     */
    public static function isSafeIp(string $ip, string $profile = self::PROFILE_STRICT_PUBLIC): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // 1. Validação em formato binário para IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = @inet_pton($ip);
            if ($binary === false || strlen($binary) !== 16) {
                return false;
            }
            $hex = bin2hex($binary);

            // Loopback ::1 e Unspecified ::
            if ($hex === '00000000000000000000000000000001' || $hex === '00000000000000000000000000000000') {
                return ($profile === self::PROFILE_LOCAL_AI);
            }

            // Normalização binária de IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96) e NAT64 (64:ff9b::/96)
            // Cobre tanto notação decimal (::ffff:127.0.0.1) quanto hexadecimal pura (::ffff:7f00:1, ::ffff:a9fe:a9fe)
            $isV4Mapped = (substr($hex, 0, 20) === '00000000000000000000' && (substr($hex, 20, 4) === 'ffff' || substr($hex, 20, 4) === '0000'));
            $isNat64    = (substr($hex, 0, 24) === '0064ff9b0000000000000000');

            if ($isV4Mapped || $isNat64) {
                $v4Binary = substr($binary, 12, 4);
                $extractedV4 = @inet_ntop($v4Binary);
                if ($extractedV4 !== false) {
                    return self::isSafeIp($extractedV4, $profile);
                }
                return false;
            }

            // Link-local: fe80::/10 (fe80 a febf) - sempre bloqueado em todos os perfis (incluindo metadados)
            if (preg_match('/^fe[89ab]/i', $hex)) {
                return false;
            }

            // AWS EC2 IPv6 metadata: fd00:ec2::/32
            if (str_starts_with($hex, 'fd000ec2')) {
                return false;
            }

            // Unique local: fc00::/7 (fc00 a fdff)
            if ($profile === self::PROFILE_STRICT_PUBLIC && preg_match('/^f[cd]/i', $hex)) {
                return false;
            }

            return true;
        }

        // 2. Validação para IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return false;
            }

            // Loopback: 127.0.0.0/8
            if (($long & 0xFF000000) === 0x7F000000) {
                return ($profile === self::PROFILE_LOCAL_AI);
            }

            // Endereços "Este host na rede": 0.0.0.0/8
            if (($long & 0xFF000000) === 0x00000000) {
                return false;
            }

            // CGNAT / Alibaba Cloud Metadata: 100.64.0.0/10 (ex: 100.100.100.200)
            if (($long & 0xFFC00000) === (int)ip2long('100.64.0.0')) {
                return false;
            }

            // Link-local / Cloud Metadata: 169.254.0.0/16
            if (($long & 0xFFFF0000) === (int)ip2long('169.254.0.0')) {
                return false;
            }

            // IETF Protocol Assignments / Oracle Cloud Metadata: 192.0.0.0/24 (ex: 192.0.0.192)
            if (($long & 0xFFFFFF00) === (int)ip2long('192.0.0.0')) {
                return false;
            }

            // Multicast e Reservado (Classe D e E): 224.0.0.0/4 e 240.0.0.0/4 (>= 224.0.0.0)
            if ((($long >> 24) & 0xFF) >= 224) {
                return false;
            }

            // Broadcast: 255.255.255.255
            if ($long === -1 || $ip === '255.255.255.255') {
                return false;
            }

            // No modo estritamente público, rejeita RFC1918 (10/8, 172.16/12, 192.168/16)
            if ($profile === self::PROFILE_STRICT_PUBLIC) {
                return (bool)filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                );
            }

            return true;
        }

        return false;
    }

    /**
     * Resolve endereços IP (A e AAAA) para um host.
     * @return list<string>
     */
    public static function resolveHostIps(string $host): array
    {
        $host = strtolower(trim($host));
        if (self::$dnsMock !== null && isset(self::$dnsMock[$host])) {
            return self::$dnsMock[$host];
        }

        $cleanIp = trim($host, '[]');
        if (filter_var($cleanIp, FILTER_VALIDATE_IP)) {
            return [$cleanIp];
        }
        if ($host === 'localhost') {
            return ['127.0.0.1'];
        }

        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ip'])) {
                        $ips[] = $r['ip'];
                    } elseif (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }

        if (empty($ips)) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Valida a URL e resolve os IPs de destino de forma atômica (única resolução).
     * Retorna array com os dados e o IP validado para fixação (pinning), ou null se inválida/insegura.
     *
     * @param string $url
     * @param string $profile
     * @param string|null $canonicalOrigin
     * @return array{url: string, host: string, scheme: string, port: int, pinned_ip: ?string}|null
     */
    public static function resolveSafeTarget(
        string $url,
        string $profile = self::PROFILE_STRICT_PUBLIC,
        ?string $canonicalOrigin = null
    ): ?array {
        $url = trim($url);
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return null;
        }

        $parsedPort = parse_url($url, PHP_URL_PORT);
        if ($parsedPort !== null && ($parsedPort < 1 || $parsedPort > 65535)) {
            return null;
        }
        $port = (int)($parsedPort ?? ($scheme === 'https' ? 443 : 80));

        // Perfil LOCAL_ORIGIN_ONLY: Valida estritamente contra o canonical_origin
        if ($profile === self::PROFILE_LOCAL_ORIGIN_ONLY) {
            $canon = $canonicalOrigin ?? ($GLOBALS['config']['app']['canonical_origin'] ?? '');
            if (empty($canon)) {
                return null;
            }
            $cScheme = strtolower((string)parse_url($canon, PHP_URL_SCHEME));
            $cHost   = strtolower((string)parse_url($canon, PHP_URL_HOST));
            $cPort   = (int)(parse_url($canon, PHP_URL_PORT) ?? ($cScheme === 'https' ? 443 : 80));

            if ($scheme !== $cScheme || strtolower($host) !== $cHost || $port !== $cPort) {
                return null;
            }

            // Para o canonical_origin do próprio site, permite resolução local (hairpinning /etc/hosts)
            $ips = self::resolveHostIps($host);

            return [
                'url'       => $url,
                'host'      => $host,
                'scheme'    => $scheme,
                'port'      => $port,
                'pinned_ip' => $ips[0] ?? null,
            ];
        }

        // Bloqueio explícito de metadados conhecidos por nome de host em todos os perfis
        $lowerHost = strtolower($host);
        if ($lowerHost === 'metadata.google.internal' || $lowerHost === 'instance-data') {
            return null;
        }

        // Bloqueio de localhost em perfis que não sejam LOCAL_AI
        if ($profile !== self::PROFILE_LOCAL_AI && $lowerHost === 'localhost') {
            return null;
        }

        // Se for um endereço IP direto (IPv4 ou IPv6 com ou sem colchetes)
        $cleanIp = trim($host, '[]');
        if (filter_var($cleanIp, FILTER_VALIDATE_IP)) {
            if (!self::isSafeIp($cleanIp, $profile)) {
                return null;
            }
            return [
                'url'       => $url,
                'host'      => $host,
                'scheme'    => $scheme,
                'port'      => $port,
                'pinned_ip' => null,
            ];
        }

        // Para domínios/hostnames: executa a resolução DNS UMA ÚNICA VEZ
        $ips = self::resolveHostIps($host);
        if (empty($ips)) {
            return null;
        }

        // Valida TODOS os endereços retornados na resolução
        foreach ($ips as $ip) {
            if (!self::isSafeIp($ip, $profile)) {
                return null;
            }
        }

        return [
            'url'       => $url,
            'host'      => $host,
            'scheme'    => $scheme,
            'port'      => $port,
            'pinned_ip' => $ips[0],
        ];
    }

    /**
     * Valida de forma rápida se uma URL é segura sob o perfil informado.
     */
    public static function isSafeUrl(
        string $url,
        string $profile = self::PROFILE_STRICT_PUBLIC,
        ?string $canonicalOrigin = null
    ): bool {
        return self::resolveSafeTarget($url, $profile, $canonicalOrigin) !== null;
    }

    /**
     * Executa requisição HTTP segura com DNS Pinning, bloqueio de redirecionamentos e verificação TLS.
     *
     * @param string $url URL alvo
     * @param array<int|string, mixed> $curlOptions Opções adicionais do cURL
     * @param string $profile Perfil de segurança
     * @param string|null $canonicalOrigin Origem canônica obrigatória se PROFILE_LOCAL_ORIGIN_ONLY
     * @return array{ok: bool, status: int, body: string, error: string, info: array<string, mixed>}
     */
    public static function request(
        string $url,
        array $curlOptions = [],
        string $profile = self::PROFILE_STRICT_PUBLIC,
        ?string $canonicalOrigin = null
    ): array {
        $resolved = self::resolveSafeTarget($url, $profile, $canonicalOrigin);
        if ($resolved === null) {
            return [
                'ok'     => false,
                'status' => 0,
                'body'   => '',
                'error'  => 'Destino bloqueado pelas políticas defensivas de segurança (SSRF).',
                'info'   => [],
            ];
        }

        $ch = curl_init($resolved['url']);
        if ($ch === false) {
            return [
                'ok'     => false,
                'status' => 0,
                'body'   => '',
                'error'  => 'Falha ao inicializar recurso cURL.',
                'info'   => [],
            ];
        }

        $defaultOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false, // Nunca seguir redirecionamentos automáticos
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
        ];

        // DNS Pinning atômico: vincula o hostname ao IP já resolvido e auditado
        if (!empty($resolved['pinned_ip'])) {
            $pinnedIp = $resolved['pinned_ip'];
            $formattedIp = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;
            $defaultOpts[CURLOPT_RESOLVE] = ["{$resolved['host']}:{$resolved['port']}:{$formattedIp}"];
        }

        // Mesclar opções adicionais passadas pelo chamador
        $finalOpts = $curlOptions + $defaultOpts;
        curl_setopt_array($ch, $finalOpts);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $info = (array)curl_getinfo($ch);
        curl_close($ch);

        // Verificação defensiva em profundidade do IP efetivamente conectado
        if (!empty($primaryIp) && $profile !== self::PROFILE_LOCAL_ORIGIN_ONLY) {
            if (!self::isSafeIp($primaryIp, $profile)) {
                return [
                    'ok'     => false,
                    'status' => 0,
                    'body'   => '',
                    'error'  => 'Conexão abortada: IP conectado não atende às políticas de segurança.',
                    'info'   => $info,
                ];
            }
        }

        $isOk = ($status >= 200 && $status < 300) && ($body !== false);

        return [
            'ok'     => $isOk,
            'status' => $status,
            'body'   => is_string($body) ? $body : '',
            'error'  => $curlError,
            'info'   => $info,
        ];
    }
}
