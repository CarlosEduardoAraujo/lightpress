# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado no [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Corrigido
- **BUG 1 - Seletor de Arquivos AVIF (`public/admin/media.php`):** Adicionado suporte explícito para seleção de imagens `.avif` e `image/avif` no explorador de arquivos do sistema operacional através do atributo `accept`.
- **BUG 2 - Envio de Imagens Maiores e Diagnóstico de Erros:** 
  - Adicionada detecção robusta para estouro de `upload_max_filesize` e `post_max_size` no PHP com mensagens claras de erro.
  - Elevação automática de `memory_limit` (até 512M) e `max_execution_time` (120s) em `core/ImageProcessor.php` para suporte a fotos de alta resolução (Full HD e 4K).
  - Calibração de `avif_speed` para 8 com bloco de fallback não bloqueante (`try/catch`), garantindo que o conjunto WebP sempre seja gerado com sucesso mesmo em servidores com CPUs modestas.
  - Interface da galeria aprimorada com upload assíncrono via `fetch()`, barra de status em tempo real e retorno imediato de erros visíveis ao usuário.
- **BUG 3 - Nomes de Imagens Semânticos para SEO:** As variantes em disco e URLs agora recebem o nome original limpo e slugificado com hash curto único (ex: `piloto-ferrari-2026-a1b2c3d4-1024.webp`), substituindo os nomes genéricos de 32 caracteres hexadecimais.
- **Painel Administrativo em `public/admin/`:** Movido o painel administrativo para a raiz web pública (`public/admin/`), garantindo que tanto o servidor de desenvolvimento do PHP (`php -S -t public`) quanto o Nginx sirvam `/admin/login.php`, `/admin/dashboard.php` e demais endpoints sem erros 404.
- **Rotas de Atalho para o Admin:** Adicionadas rotas de conveniência `/admin` e `/admin/` redirecionando automaticamente para `/admin/login.php`.
- **Loop Infinito no `config.php`:** Adicionada proteção estrita contra recursão infinita no carregamento de `config.local.php` caso o arquivo tenha sido copiado integralmente de `config.php`, evitando esgotamento de memória e SIGKILL (`Morto`) no Linux.
- **Instalador com Flag `--fresh` e Validação de Esquema:** Adicionada validação de integridade para detectar conflitos com tabelas legadas preexistentes (ex: tabela `users` externa sem a coluna `role`) e flag `php bin/setup.php --fresh` com `Schema::dropAll()` para recriação limpa.
- **Execução Atômica de DDL MySQL:** Em `Schema::installMysql`, as instruções de criação de tabelas e índices do `schema.sql` agora são executadas instrução por instrução de forma isolada, evitando falhas silenciosas de comandos múltiplos no driver PDO MySQL.
- Adicionado arquivo de modelo limpo `config.local.php.example`.

### Adicionado
- **Arquitetura, Governança e CI/CD:**
  - Especificação técnica completa no `README.md` com arquitetura limpa, modelo relacional plano e segurança proativa (Security by Design).
  - Pipeline de CI/CD automatizado via GitHub Actions em `.github/workflows/ci.yml` (PHP 8.4, Linting de sintaxe, diagnóstico, testes unitários, migração de banco e seeder).
  - Licença Open Source MIT em `LICENSE` e padronização `.editorconfig`.
  - Guia de contribuição da comunidade em `CONTRIBUTING.md`.
  - Guia completo de implantação em produção em `docs/DEPLOYMENT.md` (Linux, Nginx, PHP 8.4-FPM, SSL e Cron).
  - Configuração central `config.php` e isolamento de credenciais locais via `config.local.php`.

- **Camada de Dados, Persistência, Backup e Restauração:**
  - `core/Database.php`: abstração unificada PDO com prepared statements nativos, desativação de emulação, UTF-8 mb4, timezone UTC e transações atômicas seguras.
  - `bin/schema.sql` e `core/Schema.php`: DDL relacional plano para MariaDB/MySQL e SQLite (users, posts, post_stats, post_revisions, user_reset_tokens, categories, media, media_refs, view_dedupe, cache_purge_jobs, migration_map, redirects).
  - `bin/setup.php`: instalador CLI automático do banco de dados e gerador de credenciais do primeiro administrador.
  - `bin/seed.php`: gerador de dados de demonstração em lotes transacionais para testes e benchmarks de grande escala.
  - `bin/backup.php`: ferramenta de exportação completa do banco de dados compactada em gzip (`.json.gz`).
  - `bin/restore.php`: utilitário CLI transacional de restauração e rollback automático a partir de backups compactados.

- **Segurança, RBAC e Auditoria:**
  - `core/Security.php`: escape contextual contra XSS (`e()`), tokens CSRF com comparação em tempo constante, hashing com Argon2id, hardening de sessão (`LIGHTPRESSSESSID`), anonimização de IP por HMAC-SHA-256 e validação de Fetch Metadata / Origin.
  - `core/Authorization.php`: matriz de controle de acesso baseada em papéis (RBAC) com política de negação por padrão para autores, editores e administradores.
  - `core/Logger.php`: sistema de logs técnicos e auditoria com expurgo recursivo e compulsório de senhas, tokens e segredos em `/storage/logs/`.
  - `public/.htaccess`: fallback defensivo contra execução de scripts em uploads para ambientes Apache/LiteSpeed.

- **SEO, Roteamento Inteligente, Busca e Headless APIs:**
  - `core/Router.php`: roteador regex ultrarrápido com normalização de URLs, redirecionamentos canônicos 301 e verificação automática da tabela `redirects` (preservando links legados do WordPress).
  - `core/Seo.php`: gerador de metadados Open Graph (`og:*`), Twitter Cards e Schema.org JSON-LD (`NewsArticle` e `WebSite`) para Google Notícias.
  - `public/index.php`: Front Controller com injeção de cabeçalhos HTTP defensivos (CSP, HSTS, X-Frame-Options, X-Content-Type-Options), rotas públicas, busca pública (`/busca`), `/feed.xml` (RSS 2.0 padrão) e `/sitemap.xml`.
  - `public/api/search.php`: micro-API de busca instantânea (live search / autocomplete) com limite e filtros.
  - `public/api/view.php`: micro-endpoint assíncrono para contagem de visualizações sem sessão e com deduplicação de 30 segundos.
  - `public/api/posts.php` e `public/api/categories.php`: micro-APIs de leitura pública em JSON com suporte a paginação e filtros (Headless ready).
  - Templates com suporte automático a **Dark Mode** (`prefers-color-scheme: dark`), barra de busca no cabeçalho e página de resultados em `templates/search.php`.

- **Pipeline de Mídia e Galeria de Fotos:**
  - `core/ImageProcessor.php`: conversão para WebP obrigatório e AVIF opcional, corte 16:9 de thumbnail, 3 resoluções e descarte seguro de originais.
  - `admin/media.php`: galeria e biblioteca de mídias com upload direto, prévia WebP, cópia rápida de URLs formatadas e proteção contra remoção de imagens ativas.
  - `core/BlockCompiler.php`: compilador e sanitizador semântico de blocos JSON para HTML5 com proteção estrita contra XSS e suporte a `<picture>` com `srcset`.
  - `core/PostService.php`: ciclo de vida de publicações, isolamento de visualizações em `post_stats` e versionamento otimista contra conflitos concorrentes (HTTP 409).
  - `core/CacheManager.php`: purga de arquivos em disco para FastCGI Cache do Nginx baseada em hash MD5 e gerenciamento de jobs pendentes.

- **Painel Administrativo Completo (< 50 KiB):**
  - `admin/login.php`: autenticação com Argon2id e proteção contra força bruta.
  - `admin/dashboard.php`: listagem rápida de notícias com navegação integrada, alternância de destaque com um clique, contadores de visualizações e controle de sessões revogáveis.
  - `admin/editor.php`: editor de blocos leve em Vanilla JS sem React/Redux com botão de prévia privada e histórico de versões.
  - `admin/preview.php`: ambiente de prévia privada autenticada para rascunhos com `Cache-Control: private, no-store`.
  - `admin/revisions.php`: visualizador de snapshots históricos e botão para restaurar revisões passadas.
  - `admin/profile.php`: gerenciamento de dados de perfil e troca de senha com Argon2id e invalidação de sessões anteriores.
  - `admin/upload.php`: endpoint autenticado para upload e geração de manifestos de mídia.
  - `admin/categories.php`: módulo administrativo para gestão de categorias.
  - `admin/users.php`: módulo administrativo de controle de equipe e geração de links criptográficos de ativação.
  - `admin/activate.php`: interface de ativação de conta e cadastro de senha segura.
  - `admin/system.php`: painel de saúde da infraestrutura, métricas em tempo real, armazenamento e status do OPcache/GD.

- **Infraestrutura, Testes e Ferramentas CLI:**
  - `nginx/lightpress.conf`: arquivo modelo pronto para produção contendo maps de bypass, FastCGI Cache em SSD, rate-limiting para views e login, bloqueio total de execução de scripts em `/uploads/` e cabeçalhos defensivos.
  - `bin/benchmark.php`: suíte de medição de throughput (ops/s) e latências p50/p95/p99 do compilador, roteador e criptografia.
  - `bin/diagnose.php`: diagnóstico completo de ambiente e extensões PHP.
  - `bin/serve.php`: utilitário para servidor local de desenvolvimento embutido.
  - `bin/retry-cache-purge.php`: worker com backoff exponencial para retentativas de purga de cache.
  - `bin/cleanup.php`: rotina de limpeza de tokens, deduplicações expiradas e staging.
  - `bin/migrate-wp.php`: importador auditável do WordPress por lotes com suporte a `--dry-run`.
  - `bin/test.php`: suíte leve de testes unitários sem dependências cobrindo sanitização, escape XSS, matriz de RBAC, cálculo da árvore de cache Nginx, roteador, SEO e Logger.
