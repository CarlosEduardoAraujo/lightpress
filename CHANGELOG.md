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

- **Roteamento, Syndication e Entrega Pública:**
  - `core/Router.php`: roteador regex ultrarrápido com normalização de URLs e redirecionamentos canônicos 301.
  - `public/index.php`: Front Controller com injeção de cabeçalhos HTTP defensivos (CSP, HSTS, X-Frame-Options, X-Content-Type-Options), rotas públicas, `/feed.xml` (RSS 2.0 padrão) e `/sitemap.xml`.
  - `public/api/view.php`: micro-endpoint assíncrono para contagem de visualizações sem sessão e com deduplicação de 30 segundos.

- **Pipeline de Mídia e Compilação de Conteúdo:**
  - `core/ImageProcessor.php`: conversão para WebP obrigatório e AVIF opcional, corte 16:9 de thumbnail, 3 resoluções e descarte seguro de originais.
  - `core/BlockCompiler.php`: compilador e sanitizador semântico de blocos JSON para HTML5 com proteção estrita contra XSS.
  - `core/PostService.php`: ciclo de vida de publicações, isolamento de visualizações em `post_stats` e versionamento otimista contra conflitos concorrentes (HTTP 409).
  - `core/CacheManager.php`: purga de arquivos em disco para FastCGI Cache do Nginx baseada em hash MD5 e gerenciamento de jobs pendentes.

- **Painel Administrativo Completo (< 50 KiB):**
  - `admin/login.php`: autenticação com Argon2id e proteção contra força bruta.
  - `admin/dashboard.php`: listagem rápida de notícias com contadores de visualizações e controle de sessões revogáveis.
  - `admin/editor.php`: editor de blocos leve em Vanilla JS sem React/Redux.
  - `admin/upload.php`: endpoint autenticado para upload e geração de manifestos de mídia.
  - `admin/categories.php`: módulo administrativo para gestão de categorias.
  - `admin/users.php`: módulo administrativo de controle de equipe e geração de links criptográficos de ativação.
  - `admin/activate.php`: interface de ativação de conta e cadastro de senha segura.

- **Infraestrutura e Configuração Nginx:**
  - `nginx/lightpress.conf`: arquivo modelo pronto para produção contendo maps de bypass, FastCGI Cache em SSD, rate-limiting para views e login, bloqueio total de execução de scripts em `/uploads/` e cabeçalhos defensivos.

- **Testes Automatizados:**
  - `bin/test.php`: suíte leve de testes unitários sem dependências cobrindo sanitização, escape XSS, matriz de RBAC, cálculo da árvore de cache Nginx e roteador.
