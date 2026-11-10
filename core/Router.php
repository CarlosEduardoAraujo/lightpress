<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * Roteador Regex Ultrarrápido e Seguro (Zero Dependências)
 * 
 * Gerencia rotas canônicas públicas, micro-endpoints de API e rotas administrativas
 * com suporte a parâmetros nomeados, canonicalização de URLs e redirecionamentos 301.
 */
class Router
{
    private string $basePath = '';
    private array $routes = [];
    private $notFoundHandler = null;
    private bool $isTesting = false;

    public function __construct(string $basePath = '')
    {
        $this->setBasePath($basePath);
    }

    /**
     * Define o caminho base (subdiretório) onde a aplicação está hospedada.
     */
    public function setBasePath(string $basePath): self
    {
        $trimmed = trim($basePath, '/');
        $this->basePath = $trimmed !== '' ? '/' . $trimmed : '';
        return $this;
    }

    /**
     * Retorna o caminho base atual.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Habilita ou desabilita o modo de testes (evita exit prematuro em testes unitários).
     */
    public function setTestingMode(bool $testing): void
    {
        $this->isTesting = $testing;
    }

    /**
     * Realiza redirecionamento seguro com suporte a subdiretório e isolamento de testes.
     */
    public function redirect(string $url, int $statusCode = 301): void
    {
        if ($this->isTesting) {
            return;
        }

        $target = $url;
        // Prevenir bypasses de Open Redirect via backslash (\), protocolo relativo (//) ou misto (/\)
        if (str_starts_with($target, '//') || str_starts_with($target, '/\\') || str_starts_with($target, '\\')) {
            $target = '/';
        }

        // Se for caminho relativo interno e houver basePath configurado, prefixa automaticamente
        if ($this->basePath !== '' && str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_starts_with($target, '/\\') && !str_starts_with($target, '\\')) {
            if ($target === '/') {
                $target = $this->basePath . '/';
            } elseif (!str_starts_with($target, $this->basePath . '/') && $target !== $this->basePath) {
                $target = $this->basePath . $target;
            }
        }

        header("Location: {$target}", true, $statusCode);
        exit;
    }

    /**
     * Valida se um destino de redirecionamento é seguro contra Open Redirect.
     */
    public function isValidRedirectTarget(string $target): bool
    {
        $target = trim($target);
        if ($target === '') {
            return false;
        }

        // Rejeita tentativas de bypass via backslash ou protocolo relativo (//, /\, \)
        if (str_starts_with($target, '//') || str_starts_with($target, '/\\') || str_starts_with($target, '\\') || str_contains($target, '\\')) {
            return false;
        }

        // Caminhos relativos válidos: devem começar com "/"
        if (str_starts_with($target, '/')) {
            return true;
        }

        // URLs absolutas: valida esquema seguro (apenas http/https) e restringe ao mesmo host
        $scheme = parse_url($target, PHP_URL_SCHEME);
        if ($scheme === false) {
            return false;
        }
        if (is_string($scheme) && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return false;
        }

        $targetHost = parse_url($target, PHP_URL_HOST);
        if ($targetHost === false || !is_string($targetHost)) {
            $targetHost = '';
        }
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        if (!empty($targetHost) && !empty($currentHost)) {
            $cleanCurrentHost = explode(':', $currentHost)[0];
            $cleanTargetHost = explode(':', $targetHost)[0];
            return strcasecmp($cleanTargetHost, $cleanCurrentHost) === 0;
        }

        return false;
    }

    /**
     * Registra rota GET.
     */
    public function get(string $pattern, callable $handler): self
    {
        return $this->addRoute('GET', $pattern, $handler);
    }

    /**
     * Registra rota POST.
     */
    public function post(string $pattern, callable $handler): self
    {
        return $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * Adiciona uma rota ao mapa.
     */
    public function addRoute(string $method, string $pattern, callable $handler): self
    {
        // Normaliza o padrão
        $regex = preg_replace('~\{([a-zA-Z0-9_]+)\}~', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#u';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * Define o manipulador para resposta 404 (Not Found).
     */
    public function setNotFound(callable $handler): self
    {
        $this->notFoundHandler = $handler;
        return $this;
    }

    /**
     * Despacha a requisição atual com base no método e URI recebidos.
     */
    public function dispatch(string $method, string $uri): mixed
    {
        $method = strtoupper($method);
        // Trata HEAD como GET para efeitos de roteamento
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        // Isola o caminho da Query String tratando retorno false em URIs malformadas
        $parsedPath = parse_url($uri, PHP_URL_PATH);
        $path = (is_string($parsedPath) && $parsedPath !== '') ? $parsedPath : '/';

        // Prevenir bypasses de Open Redirect via backslash (\), protocolo relativo (//) ou misto (/\)
        if (str_starts_with($path, '//') || str_starts_with($path, '/\\') || str_starts_with($path, '\\')) {
            $path = '/';
        }

        // Remove prefixo de subdiretório (basePath), se configurado
        if ($this->basePath !== '') {
            if (str_starts_with($path, $this->basePath . '/')) {
                $path = substr($path, strlen($this->basePath));
            } elseif ($path === $this->basePath) {
                $path = '/';
            }
        }

        // Redirecionamento Canônico de Paginação: /page/1/ ou /page/1 -> / (301)
        if ($path === '/page/1' || $path === '/page/1/') {
            $this->redirect('/', 301);
            return null;
        }

        // Canonicalização de Categoria: /categoria/{slug}/page/1 -> /categoria/{slug}/ (301)
        if (preg_match('~^/categoria/([^/]+)/page/1/?$~', $path, $matches)) {
            $this->redirect('/categoria/' . $matches[1] . '/', 301);
            return null;
        }

        // Redirecionamento de compatibilidade: /noticia/{slug} -> /{slug}/ (301)
        if (preg_match('~^/noticia/([^/]+)/?$~', $path, $matches)) {
            $this->redirect('/' . $matches[1] . '/', 301);
            return null;
        }

        // Canonicalização de barra final para rotas públicas amigáveis (apenas requisições GET e fora de /api/)
        if ($method === 'GET' && !str_starts_with($path, '/api/') && $path !== '/' && !str_ends_with($path, '/') && !preg_match('~\.[a-zA-Z0-9]+$~', $path)) {
            // Prevenir bypasses de Open Redirect via backslash ou barras duplas antes da canonicalização
            if (str_starts_with($path, '//') || str_starts_with($path, '/\\') || str_starts_with($path, '\\')) {
                $path = '/';
            }
            $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
            $this->redirect($path . '/' . $queryString, 301);
            return null;
        }

        // Busca rota correspondente
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }
                return call_user_func($route['handler'], $params);
            }
        }

        // Verificar tabela de redirecionamentos (ex: URLs legadas migradas do WordPress)
        try {
            $pathHash = hash('sha256', $path);
            $redirect = Database::fetchOne(
                "SELECT target_path, status_code FROM redirects WHERE path_hash = ? LIMIT 1",
                [$pathHash]
            );

            if ($redirect) {
                $target = (string)($redirect['target_path'] ?? '');
                $code = (int)($redirect['status_code'] ?? 301);

                // Mitigação de Open Redirect: só redireciona se for caminho interno seguro ou mesmo host
                if ($this->isValidRedirectTarget($target)) {
                    $this->redirect($target, $code);
                    return null;
                }
            }
        } catch (\Throwable $e) {
            // Caso ocorra falha de conexão, prossegue para o manipulador 404
        }

        // Nenhuma rota encontrada -> 404
        if ($this->notFoundHandler !== null) {
            return call_user_func($this->notFoundHandler);
        }

        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 - Página não encontrada.";
        return null;
    }
}
