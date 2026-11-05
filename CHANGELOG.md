# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado no [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Adicionado
- **Menu Superior Fixo (Sticky Header):** O cabeçalho global `.site-header` foi configurado com `position: sticky; top: 0; z-index: 100;`, mantendo a logo, campo de busca, links de categorias e alternador de tema sempre visíveis e acessíveis durante a navegação, enquanto as notícias e artigos passam normalmente por baixo sem qualquer conflito.

### Corrigido
- **BUG Sobreposição Visual no Scroll da Notícia (`templates/header.php`):** O seletor CSS genérico `header { position: sticky; top: 0; }` estava sendo herdado indevidamente pela tag `<header>` interna do `<article>`, fazendo com que todo o cabeçalho da notícia (título, metadados e barra de compartilhamento) ficasse fixado no topo da tela e sobreposto à imagem e ao texto durante a rolagem da página (especialmente crítico no celular). O cabeçalho global agora utiliza a classe isolada `.site-header` com rolagem estática nativa, eliminando qualquer sobreposição.
- **BUG Redirecionamento 301 em Chamadas POST de API (`core/Router.php`):** Corrigido comportamento do roteador que aplicava redirecionamento 301 de trailing slash (SEO) indiscriminadamente a requisições `POST` em rotas da API (`/api/view/{id}`). Esse 301 forçava os navegadores a transformar o `POST` em `GET /api/view/{id}/`, resultando em erro 404. O roteador agora restringe a canonicalização de barra final estritamente a requisições `GET` e fora do prefixo `/api/`, e o micro-endpoint passa a aceitar variações com ou sem barra final.
- **BUG Erro 500 na Página Inicial (`Database::fetchValue`):** Implementado o método ausente `Database::fetchValue()` utilizado para contagem de registros na paginação da Home e Categorias.
- **Tratamento Global de Exceções e Logs em Produção:** Adicionado bloco `try/catch` de segurança no ponto de entrada (`public/index.php`), gravação automática de falhas no log `/storage/logs/error.log` e template visual limpo `templates/500.php` sem exposição de credenciais ou stack traces sensíveis.
- **Expurgo Abrangente de Cache FastCGI:** 
  - O `PostService` agora invalida automaticamente a Home (`/`), a notícia atual (`/noticia/slug/`), a notícia anterior (em caso de renomeação de slug), a categoria atual, a categoria anterior e os feeds RSS (`/feed.xml` e `/feed`).
  - O `CacheManager` agora expurga simultaneamente as chaves em `https` e `http`, garantindo remoção imediata dos arquivos de cache em disco mesmo quando o servidor opera atrás de proxy reverso da Cloudflare com terminação SSL externa.
- **Ajuste de Content Security Policy (CSP) e Cloudflare Insights:** Adicionado suporte explícito no cabeçalho CSP a scripts embutidos com `'unsafe-inline'`, telemetria da Cloudflare (`static.cloudflareinsights.com` e `cloudflareinsights.com`) e remoção de manipuladores de evento inline no campo de busca (`templates/header.php`) em favor de regras nativas em CSS `:focus`.
- **BUG no Colar do Editor Visual (`cleanWordOrDocsHtml`):** Corrigido erro `TypeError: Cannot read properties of null (reading 'innerHTML')` ao colar conteúdo do Google Docs ou Word, garantindo que o elemento `<body>` nunca seja desestruturado ou removido pelo parser DOM e adicionando tratamento especial para wrappers com `id="docs-internal-guid"`.
- **BUG 1 - Seletor de Arquivos AVIF (`public/admin/media.php`):** Adicionado suporte explícito para seleção de imagens `.avif` e `image/avif` no explorador de arquivos do sistema operacional através do atributo `accept`.
- **BUG 2 - Envio de Imagens Maiores e Diagnóstico de Erros:** 
  - Adicionada detecção robusta para estouro de `upload_max_filesize` e `post_max_size` no PHP com mensagens claras de erro.
  - Elevação automática de `memory_limit` (até 512M) e `max_execution_time` (120s) em `core/ImageProcessor.php` para suporte a fotos de alta resolução (Full HD e 4K).
  - Calibração de `avif_speed` para 8 com bloco de fallback não bloqueante (`try/catch`), garantindo que o conjunto WebP sempre seja gerado com sucesso mesmo em servidores com CPUs modestas.
  - Interface da galeria aprimorada com upload assíncrono via `fetch()`, barra de status em tempo real e retorno imediato de erros visíveis ao usuário.
- **Otimização Inteligente para Uploads WebP e AVIF:** 
  - Calibração de qualidade AVIF para 65 (padrão recomendado pela indústria para máxima compressão web).
  - Preservação direta do arquivo original (pass-through) para imagens que já venham em WebP ou AVIF pré-otimizadas quando a recodificação geraria arquivo maior.
  - Exibição consistente do status de redução ou "Formato original otimizado" na biblioteca de mídias para todas as extensões.
- **Métricas de Compressão na Galeria de Mídias:** Adicionada exibição detalhada nos cards de mídia com o peso original e extensão (ex: JPG), peso e resolução das variantes AVIF e WebP, e porcentagem exata de economia/redução alcançada por formato.
- **BUG 3 - Nomes de Imagens Semânticos para SEO:** As variantes em disco e URLs agora preservam com fidelidade o nome original do arquivo sanitizado sem caracteres aleatórios ou hashes (ex: `comparativo-nano-banana-2-1-2-pro-custo-qualidade-ia-1024.avif`), utilizando incremento numérico inteligente (`-1`, `-2`) apenas em caso de colisão de nomes no mesmo mês.
- **Painel Administrativo em `public/admin/`:** Movido o painel administrativo para a raiz web pública (`public/admin/`), garantindo que tanto o servidor de desenvolvimento do PHP (`php -S -t public`) quanto o Nginx sirvam `/admin/login.php`, `/admin/dashboard.php` e demais endpoints sem erros 404.
- **Rotas de Atalho para o Admin:** Adicionadas rotas de conveniência `/admin` e `/admin/` redirecionando automaticamente para `/admin/login.php`.
- **Loop Infinito no `config.php`:** Adicionada proteção estrita contra recursão infinita no carregamento de `config.local.php` caso o arquivo tenha sido copiado integralmente de `config.php`, evitando esgotamento de memória e SIGKILL (`Morto`) no Linux.
- **Instalador com Flag `--fresh` e Validação de Esquema:** Adicionada validação de integridade para detectar conflitos com tabelas legadas preexistentes (ex: tabela `users` externa sem a coluna `role`) e flag `php bin/setup.php --fresh` com `Schema::dropAll()` para recriação limpa.
- **Execução Atômica de DDL MySQL:** Em `Schema::installMysql`, as instruções de criação de tabelas e índices do `schema.sql` agora são executadas instrução por instrução de forma isolada, evitando falhas silenciosas de comandos múltiplos no driver PDO MySQL.
- Adicionado arquivo de modelo limpo `config.local.php.example`.

### Adicionado
- **Layout Panorâmico Expandido para Leitura de Artigos (1750px):**
  - A página de leitura (`templates/post.php`) agora se expande até `1750px`, aproveitando a totalidade de monitores Full HD (1920x1080) e ultrawide com presença imersiva.
  - Capa da notícia (*featured image*) com visual widescreen cinematográfico (até 760px de altura).
  - Tipografia de leitura ajustada (`font-size: 1.22rem; line-height: 1.85;`) com títulos responsivos fluidos (`clamp`).
  - Bloco de Notícias Relacionadas em 4 colunas distribuídas por toda a largura panorâmica com a nova classe `.grid-related`.
- **Layout Panorâmico Full HD (1900px) e Grid Quádruplo de Destaques:**
  - Expansão do container global para `max-width: 1900px;` preenchendo monitores Full HD (1920x1080) e ultrawide com proporções fluidas e fim do excesso de margens laterais.
  - **Hero Quad Grid no Topo da Home:** Substituição do hero card único por uma faixa superior de **4 notícias destacadas** lado a lado em cards 16:10 com overlay degradê escuro, tipografia branca sobreposta, selos de categoria e preenchimento automático (*backfill*) com as últimas matérias publicadas caso existam menos de 4 marcadas como destaque.
  - Exclusão inteligente dos 4 destaques exibidos no topo para não haver repetição desnecessária no grid secundário de publicações.
- **Botões Rápidos de Compartilhamento Social (100% Leves e Sem Rastreadores):**
  - Implementada barra de compartilhamento com botões otimizados para **WhatsApp**, **X (Twitter)**, **Facebook**, **Telegram** e botão interativo **Copiar Link**.
  - O botão Copiar Link utiliza a Clipboard API nativa com feedback visual instantâneo (*"✓ Link Copiado!"*) e fallback seguro.
  - Zero dependência externa ou SDKs de terceiros, garantindo máxima velocidade de carregamento, total conformidade com a política CSP e respeito à privacidade dos leitores (sem scripts de rastreamento).
- **Módulo de Notícias Relacionadas com 4 Artigos:**
  - Ampliação do bloco de retenção de leitura na página de artigo para 4 matérias recomendadas em linha com grid responsivo de até 1400px.
- **URLs Canônicas de Notícias na Raiz (`/{slug}/`):** 
  - Notícias agora possuem links diretos de alto impacto na raiz do domínio (ex: `https://lightpress.suainternet.com/gp-sao-paulo-2026/`).
  - Redirecionamento permanente HTTP 301 automático de `/noticia/{slug}/` e `/noticia/{slug}` para `/{slug}/`, garantindo integridade de links antigos e retrocompatibilidade total.
  - Canonicalização automática de barra final (`/`) no roteador para URLs amigáveis.
- **Priorização Nativa de AVIF no Frontend e WebP para Redes Sociais:**
  - O frontend passa a requisitar primordialmente variantes `.avif` de máxima compressão web para renderização no navegador.
  - Metadados de compartilhamento social (`<!-- Open Graph & Twitter Cards -->` em `core/Seo.php`) preservam estritamente o formato `.webp`, garantindo exibição de cards ricos no X/Twitter, WhatsApp, Facebook e LinkedIn (que ainda não suportam AVIF).
- **Redesign Editorial Moderno do Portal (Opção 1):**
  - **Navegação Dinâmica e Dark Mode:** Header com categorias ativas do banco, busca animada em CSS puro e alternador de tema Claro/Escuro (Dark Mode) persistido no `localStorage`.
  - **Home Page de Alto Impacto:** Manchete principal Hero em proporção 16:9, grid de matérias secundárias com cards responsivos e paginação numérica limpa.
  - **Página de Artigo Completa:** Indicador de tempo estimado de leitura (*ex: ⏱️ 3 min*), bloco de biografia e avatar do autor, e módulo de **Notícias Relacionadas** (3 matérias recomendadas da mesma categoria).
  - **Listagens Paginadas de Categorias:** Rota `/categoria/{slug}/page/{page}/` com navegação rápida de páginas.
- **Campo Slug / URL Permanente no Editor:** Adicionado campo de edição de slug com prévia em tempo real da URL permanente (`/noticia/slug/`), botão de geração automática via título (`Auto 🪄`), digitação customizada livre e resolução inteligente de colisões com incremento numérico (`-2`, `-3`) no `PostService`.
- **Editor de Notícias com Suporte a Rich Text e Colar do Google Docs / Word:**
  - Área visual de edição com suporte a colar direto de editores de texto externos (Google Docs, Microsoft Word, LibreOffice), eliminando estilos proprietários sujos (`MsoNormal`, `style="..."`, `class="..."`, tags Office) e preservando formatação semântica limpa (`h2`, `h3`, `p`, `strong`, `em`, `blockquote`, `ul`, `ol`, `a`).
  - Barra de ferramentas rica e leve (< 20 KiB em Vanilla JS puro) com botões de formatação, links seguros (`rel="noopener noreferrer"`) e alternador de código HTML.
  - Sincronização automática entre o HTML sanitizado em `content_html` e a estrutura de blocos em `content_json`.
- **Modal de Seleção de Mídias Estilo WordPress (`/admin/editor.php`):**
  - Seletor de imagens em modal overlay para Imagem de Destaque e para inserção direta de fotos no conteúdo da matéria.
  - Filtro em tempo real por nome, grade responsiva com miniaturas e upload de novas fotos direto do modal sem sair da página de edição.
  - Prévia visual 16:9 da imagem de destaque com botão de remoção rápida.
- **Campo Palavra-chave Principal (Primary Focus Keyword):** Adicionado campo dedicado no formulário de edição e integrado ao `PostService` e DDL para estratégia de SEO.
- **Endpoint de API Interna de Mídias (`public/admin/api/media.php`):** Fornece listagem paginada e busca rápida de fotos cadastradas para consumo do editor e sidecars.
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
