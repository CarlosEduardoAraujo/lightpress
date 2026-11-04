# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado no [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Adicionado
- **Arquitetura e Governança:**
  - Especificação técnica completa no `README.md` com arquitetura limpa, modelo relacional plano e segurança proativa (Security by Design).
  - Licença Open Source MIT em `LICENSE` e padronização `.editorconfig`.
  - Configuração central `config.php` e isolamento de credenciais locais via `config.local.php`.
  - Utilitário de diagnóstico de ambiente `bin/diagnose.php`.

- **Camada de Dados e Persistência:**
  - `core/Database.php`: abstração unificada PDO com prepared statements nativos, desativação de emulação, UTF-8 mb4, timezone UTC e transações atômicas seguras.
  - `bin/schema.sql` e `core/Schema.php`: DDL relacional plano para MariaDB/MySQL e SQLite (users, posts, post_stats, post_revisions, user_reset_tokens, categories, media, media_refs, view_dedupe, cache_purge_jobs, migration_map, redirects).
  - `bin/setup.php`: instalador CLI automático do banco de dados e gerador de credenciais do primeiro administrador.

- **Segurança e RBAC:**
  - `core/Security.php`: escape contextual contra XSS (`e()`), tokens CSRF com comparação em tempo constante, hashing com Argon2id, hardening de sessão (`LIGHTPRESSSESSID`), anonimização de IP por HMAC-SHA-256 e validação de Fetch Metadata / Origin.
  - `core/Authorization.php`: matriz de controle de acesso baseada em papéis (RBAC) com política de negação por padrão para autores, editores e administradores.

- **Roteamento e Entrega Pública:**
  - `core/Router.php`: roteador regex ultrarrápido com normalização de URLs e redirecionamentos canônicos 301.
  - `public/index.php`: Front Controller com injeção de headers HTTP defensivos (CSP, HSTS, X-Frame-Options, X-Content-Type-Options) e renderização das rotas públicas.
  - `public/api/view.php`: micro-endpoint assíncrono para contagem de visualizações sem sessão e com deduplicação de 30 segundos.

- **Pipeline de Mídia e Compilação de Conteúdo:**
  - `core/ImageProcessor.php`: conversão para WebP obrigatório e AVIF opcional, corte 16:9 de thumbnail, 3 resoluções e descarte seguro de originais.
  - `core/BlockCompiler.php`: compilador e sanitizador semântico de blocos JSON para HTML5 com proteção estrita contra XSS.
  - `core/PostService.php`: ciclo de vida de publicações, isolamento de visualizações em `post_stats` e versionamento otimista contra conflitos concorrentes (HTTP 409).
  - `core/CacheManager.php`: purga de arquivos em disco para FastCGI Cache do Nginx baseada em hash MD5 e gerenciamento de jobs pendentes.

- **Painel Administrativo Enxuto (< 50 KiB):**
  - `admin/login.php`: autenticação com Argon2id e proteção contra força bruta.
  - `admin/dashboard.php`: listagem rápida de notícias com contadores de visualizações e controle de sessões revogáveis.
  - `admin/editor.php`: editor de blocos leve em Vanilla JS sem React/Redux.
  - `admin/upload.php`: endpoint autenticado para upload e geração de manifestos de mídia.

- **CLI e Manutenção:**
  - `bin/retry-cache-purge.php`: worker com backoff exponencial para retentativas de purga de cache.
  - `bin/cleanup.php`: rotina de limpeza de tokens, deduplicações expiradas e staging.
  - `bin/migrate-wp.php`: importador auditável do WordPress por lotes com suporte a `--dry-run`.
