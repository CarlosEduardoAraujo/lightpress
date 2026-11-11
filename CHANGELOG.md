# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado no [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Otimização de Banco de Dados: Eliminação de Gargalo de Mídia, Batch Loading & Cache Defensivo — 2026-11-10

- **Eliminação Integral de Varredura de Tabela e Filesort (`Creating sort index`):**
  - Substituição da consulta lenta não-indexada `SELECT alt_text, caption, original_name FROM media WHERE variants_manifest LIKE ? ... ORDER BY ... LIMIT 1` por consulta direta e indexada via B-Tree em tempo $< 0.1\text{ ms}$.
  - Criação da tabela indexada `media_variants` (`url_path VARCHAR(255) UNIQUE`, `media_id VARCHAR(32)` com `ON DELETE CASCADE`), mapeando todas as URLs e variantes geradas diretamente para o registro de mídia pai.
  - Adição da coluna `posts.featured_media_id VARCHAR(32) NULL` com índice `idx_featured_media` para relacionamento relacional direto entre artigos e suas imagens de destaque.
  - Implementação de auto-migração dinâmica em `Schema::ensureMediaVariantsSchema()` compatível nativamente com MariaDB/MySQL e SQLite.
- **Carregamento em Lote no Front Controller (`ImageProcessor::preloadDescriptions()`):**
  - Implementação de método de pré-carregamento em lote que coleta todas as URLs de imagens da página e executa **uma única consulta SQL agregada** (`WHERE url_path IN (...)`), eliminando o problema de queries N+1 durante a renderização de loops de cards nos templates.
  - Instrumentação completa das rotas públicas em `public/index.php`: Home (`/` e `/page/{page}/`), Categorias, Tags, Busca e Artigo Individual (imagem principal + notícias relacionadas).
- **Arquitetura de Cache em Dois Níveis com Fallback Total:**
  - **Nível 1 (RAM Local da Requisição):** Armazenamento em array estático `$memoryDescCache` no `ImageProcessor`, memorizando inclusive imagens inexistentes/vazias para garantir 0 consultas repetidas no mesmo request.
  - **Nível 2 (Persistência via `ObjectCache`):** Armazenamento no grupo `'media'` com TTL de 24 horas no Redis Cache Pro.
  - **Fallback Gracioso e Autônomo:** Em ambientes sem o plugin Redis Cache Pro ou com o daemon Redis inoperante, o sistema opera normalmente e sem avisos via banco relacional indexado e memória local.
  - **Invalidação Reativa:** Disparo automático de `ObjectCache::flushGroup('media')` ao editar metadados (`public/admin/api/ai.php` e `plugins/ai-copilot/src/MediaVision.php`) ou ao excluir mídias (`public/admin/media.php`).
- **Utilitários e Sincronização Automática:**
  - Novo script CLI `bin/migrate-media-variants.php` para extração em lote dos manifestos JSON existentes e backfill seguro de `posts.featured_media_id`.
  - Otimização de `ImageProcessor::syncRefs()`, substituindo varredura `LIKE` por busca exata em `media_variants`.
  - Registro automático e idempotente de novas variantes geradas durante uploads (`public/admin/upload.php` e `bin/catalog-media.php`) via `ImageProcessor::registerVariants()`.
- **Estratificação Inteligente de TTL por Idade & Renovação em Atualização:**
  - Implementação de política dinâmica de cache no Front Controller (`public/index.php`) para artigos individuais (`/{slug}/`):
    - **Matérias recentes (publicadas ou atualizadas há até 24h):** TTL fixado em **1 dia (86.400 segundos)** no Redis (`ObjectCache`) e no Nginx (`X-Accel-Expires: 86400`).
    - **Matérias de arquivo (mais de 24h sem modificações):** TTL estendido para **10 dias (864.000 segundos)**, congelando o acervo histórico no Nginx e liberando 100% da CPU e do banco de dados para matérias do dia.
    - **Renovação Automática na Edição:** Ao atualizar qualquer matéria no painel administrativo, o `PostService` purga o cache atômico e, na próxima leitura, o TTL é reinicializado automaticamente para 1 dia.
  - Atualização do parâmetro `inactive=10d` e `max_size=10g` na zona `fastcgi_cache_path` em `nginx/lightpress.conf` para suportar o ciclo de 10 dias sem descarte prematuro de arquivos em disco.
- **Blindagem do Nginx FastCGI Cache (`nginx/lightpress.conf`):**
  - Remoção do bypass incondicional para query strings (`$query_string != ""`). Parâmetros comuns de campanhas publicitárias e redes sociais (`utm_*`, `fbclid`, `gclid`, etc.) agora são atendidos diretamente pelo cache do Nginx, protegendo o PHP e o banco de acessos em massa.
  - Ativação das diretivas de mitigação contra Cache Stampede: `fastcgi_cache_use_stale error timeout updating invalid_header http_500 http_503;` e `fastcgi_cache_background_update on;`.

### Plugin redis-cache-pro: Motor Corporativo de Cache Persistente em Memória RAM com PhpRedis — 2026-11-10

- **Correção da Inicialização do Cache & Ciclo de Vida do ObjectCache:**
  - Correção de congelamento falso-positivo de disponibilidade em `ObjectCache::isAvailable()` causado por variável estática `$checked` que avaliava `false` na leitura inicial de `Settings::applyToConfig()` antes do carregamento dos plugins.
  - Adição do método pontual `PluginManager::loadPlugin('redis-cache-pro')` com guarda de recursão `$bootstrapping` para inicialização segura e sem ciclo fechado entre configurações, lista de plugins ativos e cache.
  - Com a correção, todas as consultas do front-end (`home_featured_posts`, `home_feed_posts_p1`, `post_slug:{slug}`, etc.) e `all_settings` passam a ser cacheadas imediatamente no Redis.
- **Telemetria Persistente & RTT Real:**
  - Métricas agregadas persistentes em hash `telemetry:aggregate` no Redis, eliminando exibição de 0 hits / 0 misses na abertura da página administrativa e calculando Taxa de Acerto e Comandos globais do site.
  - Medição de latência real de ida e volta (RTT) via `ping()` direto ao daemon Redis, substituindo o fallback estático de 0.35ms.
- **Query Profiler com Buffer Circular no Redis:**
  - Persistência das últimas 30 operações globais do site em lista circular capped (`lPush` + `lTrim 0 29`) em `profiler:recent_logs`, permitindo visualizar no painel administrativo as consultas executadas por visitantes do front-end em tempo real.
- **Terceiro Plugin Oficial (`plugins/redis-cache-pro/`):**
  - Implementação completa do plugin **Redis Cache Pro** (`redis-cache-pro`), fornecendo um motor de cache de objetos persistente em memória RAM com comunicação nativa de altíssima performance via extensão C `phpredis`, compatível com TCP, Unix Domain Socket, Sentinel e Cluster.
- **Invalidação Atômica em $O(1)$ (Eliminação de `FLUSHDB` e `KEYS *`):**
  - **Invalidação Global por Namespace:** Geração de novos hashes randômicos de versão global em `lp_cache:global:version`, tornando instantaneamente obsoletas todas as chaves da instalação sem travar o daemon Redis.
  - **Invalidação Granular por Grupo:** Versionamento atômico individual por grupo de dados (`posts`, `settings`, `categories`, `users`, `transient`) em `group:{group}:version`, permitindo limpar apenas o grupo de posts ao publicar notícias sem desalocar opções ou categorias já aquecidas.
  - **Desalocação Não-Bloqueante com `UNLINK`:** Substituição de `DEL` síncrono por `UNLINK` assíncrono no kernel do Redis, eliminando pausas na CPU causadas por chaves volumosas.
- **Disjuntor de Alta Resiliência (Circuit Breaker):**
  - Monitoramento contínuo de exceções e timeouts no Redis.
  - Após 3 falhas consecutivas, o disjuntor desarma para o estado `OPEN`, acionando automaticamente o modo de bypass seguro durante 30 segundos de cooldown.
  - As requisições são atendidas transparentemente pelo banco relacional e cache efêmero em memória da requisição, impedindo que quedas do Redis derrubem ou travem o portal.
  - Recuperação gradual em estado `HALF_OPEN` antes de restabelecer o modo normal `CLOSED`.
- **Proteção contra Cache Stampede (Dogpile Lock):**
  - Lock distribuído atômico `SET NX PX 5000` via método `RedisCache::remember()`.
  - Impede a recomputação concorrente destrutiva de consultas pesadas quando uma chave expira sob alto tráfego.
- **Separação de Bancos (Split Database):**
  - Suporte a direcionar dados efêmeros e voláteis (transientes e sessões) para um banco secundário do Redis (ex: DB 1), preservando o banco principal (DB 0) para posts e configurações.
- **Codecs Modernos de Alta Velocidade:**
  - Suporte ao serializador `igbinary` com fallback para o serializador padrão PHP.
  - Suporte a algoritmos rápidos de compressão `zstd` (Zstandard) e `lz4` se compilados na extensão `phpredis`.
- **Precedência de Configuração e Sobrescrita Segura (`WP_REDIS_CONFIG`):**
  - Suporte para definição da constante `WP_REDIS_CONFIG` em `config.local.php` e `config.local.php.example`, permitindo controle de infraestrutura em nível de arquivo com fallback para a tabela `settings`.
- **Painel Administrativo Completo com 5 Abas (`/admin/plugin-settings.php?plugin=redis-cache-pro`):**
  - **Aba 1 (Visão Geral & Telemetria 24h):** Radial Gauge animado de Hit Ratio (%), status da conexão, latência RTT em ms, consumo e pico de memória RAM e gráfico vetorial interativo em SVG das últimas 24 horas.
  - **Aba 2 (Comandos em Tempo Real):** Query Profiler em tempo real das últimas 30 operações com comando, chave, origem do chamador, duração (ms) e status.
  - **Aba 3 (Configuração & Pre-warming):** Parâmetros de conexão do servidor Redis e rotinas de pré-aquecimento inteligente para configurações globais, matérias recentes e URLs personalizadas.
  - **Aba 4 (Grupos & Big Key Hunter):** Scanner incremental de chaves pesadas via cursor `SCAN` identificando os 15 maiores objetos em RAM com botão para remoção individual via `UNLINK` e purga granular por grupo.
  - **Aba 5 (Alertas & Incidentes):** Disparo de webhooks ricos em formato embed (Discord, Slack, Teams, n8n) e alertas por e-mail em caso de pico de RAM ou disparo do Circuit Breaker, com botão de teste imediato de notificação.
- **Facade Pública do LightPress (`\LightPress\Plugin\RedisCachePro\RedisCache`):**
  - Métodos estáticos: `get()`, `set()`, `delete()`, `remember()`, `flushGroup()`, `flush()`, `isConnected()` e `client()`.
  - Invalidação automática via listeners de eventos do CMS (`post_saved`, `post_deleted`, `settings_updated`).
- **Suíte de Testes Automatizados:**
  - Criação de dublê determinístico do PhpRedis em memória (`plugins/redis-cache-pro/tests/FakeRedis.php`) e script de validação de regressão (`plugins/redis-cache-pro/tests/test-redis-cache-pro.php`).
- **Camada de Abstração Defensiva no Core (`core/ObjectCache.php`):**
  - Implementação do adapter seguro `ObjectCache` com detecção de disponibilidade (`ObjectCache::isAvailable()`), verificando dinamicamente se o plugin `redis-cache-pro` está ativo via `PluginManager::isActive('redis-cache-pro')`.
  - **Zero Dependência & 100% Desacoplado:** Se o plugin estiver inativo ou o Redis indisponível, todas as chamadas (`remember`, `get`, `set`, `flushGroup`) executam diretamente o callback do banco sem erros ou exceções em produção.
  - **Integração em `core/Settings.php`:** Cache automático de todas as configurações do sistema no grupo `settings` por 24h em `Settings::getAll()` com purga em $O(1)$ ao salvar em `Settings::set()`, eliminando 100% das consultas repetitivas ao banco.
  - **Integração em `public/index.php`:** Cache seguro de matérias individuais (`post_slug:{slug}`), notícias relacionadas (`related:{category}:{id}`), destaques da Home e categorias com expurgo atômico instantâneo.
  - **Integração em `core/PostService.php` e `public/admin/categories.php`:** Invalidação em $O(1)$ dos grupos `posts` e `categories` imediatamente após a publicação, agendamento, atualização ou exclusão de conteúdos.
- **Sincronização de Assets Públicos:**
  - Arquivos CSS e JS sincronizados para entrega estática direta pelo Nginx em `/public/assets/plugins/redis-cache-pro/`.

### Remoção de `<figcaption>` e Correção de Descrição Real de Imagens (ALT/TITLE) — 2026-11-10

- **Remoção Completa de `<figcaption>` no Frontend Público:**
  - Remoção de qualquer tag `<figcaption>` e `<figcaption class="sr-only">` abaixo das imagens em todas as páginas públicas (`templates/post.php`, `templates/home.php`, `templates/category.php`, `templates/tag.php`, `templates/search.php`, `templates/page.php`) e no compilador de blocos (`core/BlockCompiler.php`), eliminando vazamentos de texto abaixo da foto.
- **Resolução Exclusiva de Descrição da Imagem (`core/ImageProcessor::getImageDescription`):**
  - Implementação de resolvedor de metadados visuais em `ImageProcessor::getImageDescription($imageUrl)` com busca normalizada na tabela `media` por caminhos unescaped e escaped no JSON `variants_manifest` e nome base do arquivo.
  - Ordem estrita de precedência: `alt_text` (IA/Editor) -> `caption` (IA/Editor) -> `original_name` formatado -> nome de arquivo limpo formatado (ignorando prefixos genéricos como `img`, `dsc`, `whatsapp` e números).
  - **Eliminação Total de Alucinação do Título da Notícia:** Nenhuma imagem do portal mais recebe o título da matéria (`$post['title']`) em seus atributos `alt` ou `title`. Ao passar o mouse sobre a imagem (hover tooltip), o texto exibido descreve rigorosamente o conteúdo da foto.
- **Correção de Importação de `ImageProcessor` em `public/index.php` (Erro 500 ao Abrir Notícia):**
  - Adicionada a declaração explícita `use LightPress\Core\ImageProcessor;` e chamada totalmente qualificada `\LightPress\Core\ImageProcessor::getImageDescription()` no Front Controller (`public/index.php`), solucionando a exceção fatal `Error: Class "ImageProcessor" not found` capturada pelo tratador global de erros (500 Instabilidade Temporária) ao acessar páginas de notícias.
- **Páginas e Templates Atualizados:**
  - `templates/post.php`: Destaque Hero e Notícias Relacionadas utilizam descrição da foto nos atributos `alt` e `title`, sem `<figcaption>`.
  - `templates/home.php`: Destaque Principal (Main Lead), Notícias Secundárias e Feed de Notícias exibem descrição real da imagem em `alt` e `title`.
  - `templates/category.php`, `templates/tag.php`, `templates/search.php` e `templates/page.php`: Listagens e páginas institucionais com `alt` e `title` contextuais da imagem sem poluição de `<figcaption>`.
  - `core/BlockCompiler.php`: Geração de `<figure>` e `<picture>` com `title` e `alt` resolvidos da mídia, sem injeção de `<figcaption class="sr-only">`.

### Plugin ai-copilot: Linkagem Interna Inteligente e Visão Computacional em Mídias — 2026-11-10

- **Segundo Plugin Oficial (`plugins/ai-copilot/`):**
  - Implementação completa do plugin `ai-copilot` (Fase 1), adicionando inteligência artificial generativa e multimodal de altíssimo nível para auxílio editorial, com zero dependências externas (sem Composer ou pacotes pesados) e zero impacto no frontend público (cache FastCGI 100% preservado para leitores).
- **Ajustes Finos de Segurança e Lógica no Plugin ai-copilot:**
  - `MediaVision::resolveLocalFilePath()`: Validação estrita via `realpath()` e `str_starts_with()`, garantindo que apenas variantes contidas no diretório `/public/uploads/` sejam lidas em disco (mitigação contra Path Traversal / Arbitrary File Read).
  - `InternalLinker::findCandidates()`: Filtro temporal `status IN ('published', 'scheduled') AND published_at <= ?` com `$nowUtc`, garantindo que apenas artigos já públicos sejam recomendados para linkagem interna.
  - `settings.php` (`testConnection()`): Eliminação de `innerHTML` no retorno do teste de conexão, migrando para manipulação segura via DOM nativo (`textContent`, `createElement`, `createTextNode`) contra XSS.
  - `AiClient::executeCurl()` e `validateEndpointUrl()`: Injeção de `CURLOPT_FOLLOWLOCATION => false` e `CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS`, além de resolução de IP (`gethostbyname`) com bloqueio de faixas de metadados de nuvem (`169.254.0.0/16` e `100.64.0.0/10`) para prevenção contra DNS Rebinding e SSRF.
  - `public/admin/api/ai.php` (Método POST Obrigatório): Bloqueio imediato com HTTP 405 de qualquer requisição que não seja POST antes mesmo do carregamento de sessão e banco.
  - `public/admin/api/ai.php` (RBAC & Anti-IDOR): Checagem de propriedade de mídias (`user_id === currentUser['id']`) para usuários com perfil `author` em `describe_media` e `save_media_meta`.
  - `settings.php` e `AiClient::testConnection()` (Teste em Tempo Real): Envio dos campos da tela (`provider`, `model`, `gemini_key`, `openai_key`, `custom_endpoint`, `custom_key`, `timeout`) no payload do botão de teste de conexão, permitindo auditar a conectividade da IA imediatamente antes de salvar no banco de dados.
- **Cliente HTTP Multiprovedor Leve (`plugins/ai-copilot/src/AiClient.php`):**
  - Conexão nativa via cURL com suporte unificado a Google Gemini API (`gemini-2.5-flash`), OpenAI API (`gpt-4o-mini`) e endpoints compatíveis com OpenAI locais ou em rede privada (Ollama, vLLM, n8n).
  - Precedência de credenciais: prioridade para constantes em `config.local.php` (`AI_COPILOT_GEMINI_API_KEY`, etc.), mantendo as chaves fora do banco de dados, com fallback mascarado para a tabela `settings`.
  - Blindagem estrita contra SSRF com bloqueio explícito de metadados em nuvem (`169.254.169.254`), faixas de loopback e protocolos inseguros.
  - Parser tolerante a markdown com limpeza de delimitadores ````json```` e extração robusta de JSON.
  - Correção de tipagem estrita em `Settings::get()`: envio de `'8'` (string) no fallback de timeout em `AiClient::getTimeout()`, prevenindo `TypeError` sob `declare(strict_types=1);` ao carregar a tela de configurações.
- **Módulo 1: Linkagem Interna Inteligente (SEO Interno) (`src/InternalLinker.php`):**
  - Pré-filtragem determinística ultrarrápida (<5 ms) no MySQL via índice FULLTEXT `idx_fulltext_search (title, summary, tags)` com seleção de 10 a 15 artigos candidatos reais já publicados.
  - Filtros estritos: exclusão de matérias em rascunho (`status = 'published'`) e exclusão da própria matéria em edição (`id != currentPostId`).
  - Grounding estrito: o banco de dados é a única fonte da verdade, impedindo alucinação de URLs.
  - Pareamento semântico com validação obrigatória de substring da âncora (`mb_stripos`): se o trecho sugerido não existir literalmente no texto da matéria, a sugestão é convertida automaticamente para o bloco "Leia Também".
  - Inserção em 1 clique no `#editorCanvas` via manipulação segura da árvore DOM ou bloco de parágrafo `<p class="post-related-link">`.
- **Módulo 2: Visão Computacional no Catálogo de Mídias (`src/MediaVision.php`):**
  - Leitura da variante compactada `intermediate` (1024px WebP, ~25 a 50 KiB) em disco e codificação em Base64, proporcionando extrema economia de tokens visuais e velocidade de resposta.
  - Prompt editorial especializado em jornalismo esportivo e automobilismo em português do Brasil.
  - Extração e persistência de `alt_text` (acessibilidade e Google Imagens, até 125 chars), `caption` (legenda rica para `<figcaption>`, até 250 chars) e `ai_tags` (palavras-chave visuais separadas por vírgula).
  - Enriquecimento não-bloqueante: uploads físicos concluem instantaneamente e a análise visual roda em background via JavaScript quando configurada no modo automático.
- **Evolução do Esquema Relacional e Busca por Conteúdo Visual (`core/Schema.php` e `bin/schema.sql`):**
  - Adição das colunas `alt_text VARCHAR(255) NULL`, `caption VARCHAR(500) NULL` e `ai_tags VARCHAR(500) NULL` na tabela `media`.
  - Método `Schema::ensureMediaAiSchema()` para auto-migração idempotente em MySQL e SQLite no hook de ativação do plugin.
  - Atualização da busca em `public/admin/media.php` e `public/admin/api/media.php` incluindo `OR m.alt_text LIKE ? OR m.ai_tags LIKE ? OR m.caption LIKE ?`, viabilizando a localização instantânea de fotos antigas pelo conteúdo visual retratado.
- **Painel Administrativo do Plugin (`plugins/ai-copilot/settings.php`):**
  - Interface em `/admin/plugin-settings.php?plugin=ai-copilot` com seleção dinâmica de provedores, modelos, timeout e ativação por módulo.
  - Botão interativo **"⚡ Testar Conexão com a IA"** para auditoria imediata de credenciais e latência em milissegundos.
- **Integração e Refinamentos nas Telas do Painel Administrativo (`editor.php`, `page-edit.php`, `media.php`):**
  - `media.php`: Adição do botão **"✏️ Editar Metadados"** em todos os cartões da biblioteca de fotos com modal interativo (`#editMediaModal`) para edição imediata de Texto Alternativo, Legenda e Palavras-chave Visuais, com botão **"✨ Gerar com IA"** integrado e salvamento assíncrono via `/admin/api/ai.php?action=save_media_meta` atualizando o DOM em tempo real.
  - `public/admin/api/media.php` e `media.php`: Cláusulas `WHERE` de busca expandidas cobrindo `original_name`, `alt_text`, `ai_tags`, `caption` e `variants_manifest`.
  - `editor.php` e `page-edit.php` (Modal de Mídias): Rodapé do modal atualizado para exibir campos editáveis rápidos de Texto Alternativo (`alt_text`) e Legenda (`caption`) ao selecionar qualquer imagem, preenchidos com os dados da IA e acompanhados de botão **"✨ Gerar com IA"** sob demanda. Na inserção da foto, são utilizados estritamente o `alt_text` e a legenda revisados em `<figure><img alt="..."><figcaption>...</figcaption></figure>`, eliminando o nome bruto do arquivo (`original_name`).
  - `editor.php` (Linkagem Interna IA): Botão da barra de ferramentas atualizado para **"🔗 Links Internos IA"**. Ação de "Inserir 'Leia Também'" padronizada para injetar a tag limpa `<p><strong>Leia também:</strong> <a href="/slug/">Título da Matéria</a></p>` compatível com o `BlockCompiler`, posicionada exatamente **abaixo do parágrafo sugerido** onde o termo-âncora foi identificado (ou após o parágrafo do cursor ativo), com rolagem suave e destaque temporário.
  - `core/BlockCompiler.php`: Preservação do atributo `alt` das tags `<img>` dentro de `<figure>` no compilador de blocos, garantindo compatibilidade bidirecional de acessibilidade e legendas.
- **Endpoint Seguro de API (`public/admin/api/ai.php`):**
  - Roteamento restrito com autenticação (`Security::requireAuth()`) e validação de token CSRF (`Security::verifyCsrfToken()`) para todas as ações do plugin.
- **Suíte de Testes Automatizados (`bin/test-ai-copilot.php`):**
  - Validação de manifesto, ciclo de vida, migração de colunas no SQLite/MySQL, segurança de SSRF no `AiClient`, consultas FULLTEXT no `InternalLinker` e persistência de metadados no `MediaVision`.

### Feed de Notícias Unificado e Thumbnails nos Destaques Secundários da Home — 2026-11-10

- **Feed de Notícias Unificado na Home (`public/index.php`):**
  - Removida a cláusula de exclusão (`$excludeSql` com `NOT IN`) na consulta do feed principal de "Últimas Notícias". Todas as matérias publicadas passam a ser listadas em ordem cronológica decrescente, incluindo os artigos que também ocupam o mosaico de destaques do Hero.
  - Alinhamento de paginação: eliminação do descasamento de contagem e offsets entre a Home (`/`) e as páginas subsequentes (`/page/{page}/`), assegurando consistência na navegação.
- **Thumbnails nos Destaques Secundários (`templates/home.php`):**
  - Implementação de miniaturas compactas (`w-24 h-24 sm:w-28 sm:h-24 shrink-0 rounded-lg`) à direita em cada um dos 3 cards secundários da coluna lateral (4 colunas) do Hero da Home.
  - Reorganização do layout interno em modelo horizontal (`flex-row`), reservando a coluna esquerda para metadados (categoria, horário e títulos com `line-clamp-2 sm:line-clamp-3`).
  - Preservação estrita do alinhamento vertical e nivelamento com a coluna de 8 colunas do Destaque Master (`items-stretch` nos ~540px), sem risco de estouro de altura ou deslocamento do feed inferior.
  - Renderização de imagens de alta performance com `ImageProcessor::toThumbUrl()` em formato AVIF com fallback para WebP, efeito de zoom suave ao passar o cursor e estado de contingência ("Sem foto") para publicações sem imagem cadastrada.

### Hardening de SSRF, Defesa de Roteamento, Metadados em Revisões e Multi-Banco — 2026-11-09

- **Blindagem de SSRF no WebhookSender (`plugins/webhook-sender/webhook-sender.php`):**
  - Adicionado bloqueio a faixas de CGNAT e Alibaba Cloud Metadata (`100.64.0.0/10`), IETF Protocol Assignments e Oracle Cloud Metadata (`192.0.0.0/24`), além de faixas de Multicast e Reservado (`>= 224.0.0.0` - Classes D e E) no validador `isSafeIp()`.
  - Formatação canônica de endereços IPv6 com colchetes (`[ipv6]`) na diretiva `CURLOPT_RESOLVE` em `dispatch()`, prevenindo falhas de resolução ambígua em conexões cURL.
- **Resiliência do Roteador contra TypeError (`core/Router.php`):**
  - Tratamento defensivo do retorno `false` de `parse_url()` em `dispatch()` e em `isValidRedirectTarget()` para esquema e host de destino, prevenindo exceções fatais de `TypeError` sob `declare(strict_types=1);` com URIs malformadas.
- **Preservação de HTML Compilado e Metadados Editoriais (`public/admin/revisions.php` e `core/PostService.php`):**
  - Em `revisions.php`: Garantia de fallback automático para `content_html` original da tabela `post_revisions` caso o payload desserializado não contenha a chave antes da chamada `$postService->publishOrUpdate()`.
  - Em `core/PostService.php` (`createPost()`): Inclusão de `content_html`, `tags`, `featured_image`, `seo_title`, `seo_description` e `focus_keyword` no snapshot JSON de `post_revisions`, assegurando paridade e preservação de metadados.
- **Compatibilidade Multi-Banco SQLite e MySQL (`templates/header.php` e `core/ImageProcessor.php`):**
  - Em `templates/header.php`: Substituição de `DATE_SUB(NOW(), INTERVAL 24 HOUR)` por parâmetro preparado com `gmdate('Y-m-d H:i:s', time() - 86400)`, garantindo funcionamento portátil entre SQLite e MySQL.
  - Em `core/ImageProcessor.php` (`syncRefs()`): Alternância dinâmica entre `INSERT OR IGNORE INTO` (SQLite) e `INSERT IGNORE INTO` (MySQL) via inspeção de `Database::getDriver()`.
- **Testes de Regressão Automatizados (`bin/test_security_fixes.py`):**
  - Adição da seção `[14] Testando Hardening do WebhookSender, Router, Restauração e Multi-Banco`, elevando a suíte de testes de segurança para 70 verificações aprovadas com 100% de sucesso.

### Fluxo Editorial para Autores (pending_review) e Sincronização de CSS do Admin — 2026-11-09

- **Fluxo de Submissão Editorial para Autores (`public/admin/editor.php`):**
  - Implementado o ciclo de vida e estado `pending_review` para colaboradores com perfil de autor (`author`): ao submeterem novos artigos ou salvarem rascunhos com `action === 'submit_review'` (ou quando desprovidos da permissão `Authorization::ACTION_PUBLISH_POST`), o artigo transita de `draft` para `pending_review`.
  - O snapshot gravado em `post_revisions` registra fidedignamente o status da submissão com `$revInsertStatus = ($statusVal === 'pending_review') ? 'pending_review' : 'draft'`, mantendo a rastreabilidade do histórico editorial.
  - Atualização ergonômica das mensagens de retorno para *"Artigo enviado para revisão editorial com sucesso!"* e tratamento do feedback visual via parâmetro de URL `submitted=1`.
  - Renderização condicional do botão principal no editor: editores e administradores visualizam *"Publicar Agora"*, *"Atualizar Publicação"* ou *"Salvar Agendamento"*, enquanto autores visualizam o botão primário *"📤 Enviar para Revisão Editorial"*.
- **Sincronização e Padronização de Classes CSS no Painel Administrativo:**
  - `public/admin/system.php`: Adição das classes estruturais e semânticas `.grid-details`, `.card-title`, `.info-row`, `.badge-ok`, `.badge-warn`, `.badge-err` e variantes completas para modo escuro (`[data-theme="dark"]`).
  - `public/admin/plugins.php`: Adição das classes `.badge-active`, `.badge-inactive`, `.btn-danger` e variantes completas para tema escuro.
  - `public/admin/users.php`: Adição das classes `.token-box` e `.token-input` (com suporte dark theme) para renderização consistente e estilizada de caixas de links de ativação com validade de 24h.
  - `public/admin/settings.php`: Adição de classes utilitárias e de layout de componentes (`.page-header`, `.page-title`, `.page-desc`, `.card`, `.section-title`, `.section-desc`, `.browser-tab-mockup`, `.tab-bar`, `.tab-icon`, `.tab-title`, `.tab-close`, `.icon-sizes`, `.icon-box`, `.modal-content`, `.modal-grid`), assegurando uniformidade visual e interoperabilidade de nomenclatura.
- **Suíte de Testes Automatizados (`bin/test_security_fixes.py`):**
  - Adição da seção `[13] Testando Fluxo de Revisão Editorial (pending_review) e CSS do Painel Administrativo`, validando a máquina de estados de rascunhos, a gravação de revisões, o condicionamento de botões por permissão e a presença de todas as classes CSS, elevando a cobertura para 60 testes automatizados com 100% de sucesso.

### Roteamento Canônico e Contabilização de Views em Páginas Institucionais — 2026-11-09

- **Template de Páginas Institucionais (`templates/page.php`):**
  - Migração do disparo assíncrono de beacon/fetch da URL bloqueada `/api/view.php?type=page&id=` para o endpoint canônico `/api/view/page/{id}`.
- **Roteador Principal (`public/index.php`):**
  - Registro dedicado das rotas `POST /api/view/page/{id}` e `POST /api/view/page/{id}/`, com precedência de correspondência antes do roteamento genérico de notícias, garantindo passagem limpa de `type=page` para o processador de views.
- **Esquema Relacional e Auto-migração (`core/Schema.php`, `bin/schema.sql` e `public/api/view.php`):**
  - Adequação da tabela `view_dedupe`: remoção da restrição de chave estrangeira com a tabela `posts` e definição de `post_id BIGINT NOT NULL` (assinado).
  - Viabilização do armazenamento de identificadores negativos para páginas institucionais (`$dedupeId = -$targetId`), prevenindo colisões com IDs de posts e eliminando erros de constraint `FOREIGN KEY` e estouro de `INT UNSIGNED` no MySQL e SQLite.
  - Implementação de `Schema::ensureViewDedupeSchema()` para auto-migração segura e idempotente em bases MySQL e SQLite existentes, executada automaticamente em `ensurePagesTable()`, `installMysql()`, `installSqlite()` e diretamente em `public/api/view.php` antes de abrir `Database::transaction()` para requisições de páginas.
  - Mitigação de cache FastCGI: mesmo que o visitante receba a página estática diretamente do cache do Nginx sem acionar o PHP, o primeiro disparo do beacon de visualização auto-migra a tabela fora da transação, evitando erros e prevenindo commits implícitos de DDL no MySQL.
- **Testes de Regressão Automatizados (`bin/test_security_fixes.py`):**
  - Adição da suíte `[10] Testando Contador de Views de Páginas Institucionais e Schema view_dedupe`, cobrindo rotas, template, schema DDL, invocação pré-transacional em `view.php` e deduplicação atômica com IDs negativos, totalizando 53 testes com 100% de aprovação.


### Proteção do Último Administrador e Defesa contra Path Traversal na Mídia — 2026-11-09

- **Gestão de Usuários e Concorrência Atômica (`public/admin/users.php`):**
  - Implementada trava atômica contra rebaixamento do último administrador ativo: ao editar qualquer usuário (`action === 'edit'`), a verificação de outros administradores ativos (`COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?`) é executada dentro de `Database::transaction()`, acompanhada de bloqueio pessimista `FOR UPDATE` no MySQL. Caso não haja outro administrador ativo, a transação sofre rollback imediato com mensagem de erro, neutralizando condições de corrida onde administradores concorrentes tentem rebaixar um ao outro.
  - Implementada trava atômica contra desativação/bloqueio do último administrador ativo: no fluxo de alternância de status (`toggle_id`), a inspeção do usuário alvo e a contagem de administradores ativos são executadas dentro de `Database::transaction()` com bloqueio pessimista `FOR UPDATE` no MySQL antes de aplicar qualquer alteração.
- **Biblioteca de Mídias e Prevenção de Path Traversal (`public/admin/media.php`):**
  - Implementada validação defensiva contra Path Traversal (`/../`) na exclusão física de arquivos de mídia: resolução canônica de caminho via `realpath()` tanto para o diretório configurado de uploads (`$config['media']['upload_dir']`) quanto para os caminhos de variantes (`$localPath`).
  - Adição mandatória da barra delimitadora ao final do diretório base (`$baseUploadDir = rtrim(..., '/') . '/'`), prevenindo falsos positivos por prefixo parcial em diretórios irmãos (ex: `/public/uploads_backup/arquivo.webp`).
  - Validação explícita com `is_file($resolvedLocalPath)` assegurando que apenas arquivos regulares sejam removidos via `@unlink()`.
- **Testes de Regressão de Segurança (`bin/test_security_fixes.py`):**
  - Adicionadas suítes `[8] Testando Trava contra Desativação ou Rebaixamento do Último Administrador` e `[9] Testando Validação de Caminho Seguro na Exclusão de Mídia`, cobrindo atomicidade com `FOR UPDATE`, trailing slash em diretórios irmãos (`uploads_backup`) e integridade estrutural, totalizando 45 verificações com 100% de aprovação.


### Blindagem contra Exposição de Token CSRF no Logout e Destaques — 2026-11-09

- **Navegação Administrativa (`core/AdminNav.php`):**
  - Migração do botão "Sair" de um link `<a href="...">` para um formulário HTML dedicado `<form method="POST" action="/admin/dashboard.php">` com token CSRF e parâmetro de ação enviados como campos ocultos (`<input type="hidden">`).
  - Preservação da estética visual do botão de logout sem bordas ou fundo, garantindo ergonomia sem expor credenciais temporárias ou tokens na URL.
- **Dashboard Administrativo (`public/admin/dashboard.php`):**
  - Remoção completa da aceitação de parâmetros via `$_GET` para encerramento de sessão (`action=logout`) e alternância de artigos em destaque (`toggle_featured_id`).
  - Ambas as operações agora exigem estritamente `$_SERVER['REQUEST_METHOD'] === 'POST'` e validação obrigatória do token CSRF via `$_POST['_csrf_token']`.
  - Eliminação do risco de vazamento de tokens de sessão ativa em históricos de navegadores, logs de acesso HTTP (`access.log` do Nginx) e cabeçalhos `Referer`.
- **Testes de Regressão de Segurança (`bin/test_security_fixes.py`):**
  - Inclusão da suíte de verificação automatizada `[7] Testando Prevenção de Exposição de CSRF via GET no Logout e Destaques`, cobrindo integridade do formulário em `AdminNav.php` e recusa de mutações via GET em `dashboard.php`.


### Recuperação de migrações e restauração histórica — 2026-11-09

- **SQLite:** rollback da transação criada pela migração em caso de falha, restauração do valor original de `PRAGMA foreign_keys` em `finally` e propagação da exceção. Migração necessária durante uma transação externa é recusada sem confirmar ou desfazer o trabalho do chamador.
- **MySQL:** inicialização do serviço e migrações antes da transação de restauração, evitando que `ALTER TABLE` cause commit implícito durante a operação editorial. O serviço recusa DDL necessário em transações abertas e propaga erros de migração.
- **Histórico:** separação entre revisão ativa conhecida para concorrência e snapshot histórico escolhido para aplicação. O formulário transporta versão e revisão conhecidas; a transação revalida ambas e responde HTTP 409 se o estado mudou. Após sucesso, atualiza os identificadores do formulário. Apenas o snapshot escolhido é marcado como `applied`; outros rascunhos pendentes tornam-se `superseded`.
- **Testes:** adição de `bin/test-revision-safety.php`, com bancos SQLite em memória, classes reais e sem configuração ou dados locais da aplicação. Inclusão do teste na CI com PHP 8.4.
- **Validação local:** cinco testes de migração passaram, além das verificações de sintaxe disponíveis. A integração editorial foi explicitamente pulada no PHP 7.2 local; execução completa em PHP 8.4 e fluxos HTTP/MySQL ainda não confirmados nesta etapa.
- **Documentação:** atualização do README com contratos de migração, restauração, comandos de teste e limites de validação. A pendência de procedência de `CF-Connecting-IP` permanece aberta.

### Otimização e Usabilidade da Biblioteca de Mídias (`public/admin/media.php`)
- **Área de Arrastar e Soltar (Drag & Drop Zone) e Upload em Lote:**
  - Modernização do card de envio de imagens (`#dropZone`) para suporte total a arrastar e soltar (Drag and Drop), além de clique nativo em qualquer ponto da área.
  - Feedback visual dinâmico com realce de bordas, cores da identidade oficial (`#E10600`) e tema escuro (`.card-upload.dragover`).
  - Suporte a envio individual e em lote sequencial para múltiplos arquivos soltos de uma vez, com validação de extensões aceitas (JPG, PNG, WebP e AVIF) e do teto de 8 MiB por imagem.
  - Atualização assíncrona imediata da galeria via AJAX (`triggerLiveSearch(1)`) após o término dos uploads, dispensando recarregamento de página.
  - Prevenção no navegador contra abertura indesejada de imagens soltas fora da área demarcada.
- **Ordenação Padrão por Mídias Recentes (`DESC`):**
  - Inversão da ordenação padrão na galeria de mídias do painel administrativo (`$filterOrder = 'desc'`), garantindo que uploads recentes e fotos novas apareçam no topo imediatamente (`m.created_at DESC, m.id DESC`).
  - Atualização do menu suspenso `<select name="order">` com a opção "Recentes primeiro" selecionada por padrão e mantendo a opção "Antigas primeiro" para navegação histórica do acervo.
  - Sincronização do script JavaScript de busca em tempo real (`triggerLiveSearch`) e paginação assíncrona, tratando `'desc'` como estado padrão e omitindo o parâmetro redundante na URL para manter links limpos.

### Correções de Segurança e Hardening Arquitetural (Auditoria SEC-01 a SEC-06)
- **Revogação de Tokens de Redefinição e Ciclo de Ativação Segura (SEC-01):**
  - Validação estrita de `purpose = 'activation'` em `public/admin/activate.php` tanto no lookup quanto na transação com bloqueio `FOR UPDATE`.
  - Verificação de elegibilidade da conta (`is_active = 0` e `password_reset_required = 1`), impedindo reativação indevida de contas bloqueadas administrativamente.
  - Invalidação atômica de todos os tokens pendentes em `user_reset_tokens` na alteração administrativa de senhas e bloqueio de usuários (`public/admin/users.php`), na alteração de senha no perfil (`public/admin/profile.php`) e na conclusão da ativação da conta.
- **Defesa Contra SSRF em Webhooks com Resolução Atômica e Normalização Binária (SEC-02):**
  - Implementação de `WebhookSenderService::resolveSafeTarget()`, que unifica a validação de URLs e a resolução DNS em uma única etapa atômica, rejeitando resoluções vazias e eliminando consultas DNS secundárias que possibilitavam ataques de DNS Rebinding / TOCTOU. O endereço validado é fixado de forma estrita no cURL via `CURLOPT_RESOLVE => ["$host:$port:$approvedIp"]`.
  - Normalização binária de IPv6 (`inet_pton` de 16 bytes), decodificando e bloqueando representações hexadecimais de loopback e metadados de nuvem via IPv4-mapped (`::ffff:7f00:1`, `::ffff:a9fe:a9fe`), IPv4-compatible e NAT64 (`64:ff9b::/96`).
  - Suporte completo a portas customizadas (ex: 5678) e destinos em rede local (RFC1918 como `http://192.168.3.165:5678`), viabilizando integrações internas de automação (n8n/Zapier local) com segurança.
  - Bloqueio rígido de endereços de metadados de nuvem (`169.254.169.254`, `metadata.google.internal`), faixas link-local (`169.254.0.0/16`, `fe80::/10`), loopback (`127.0.0.0/8`, `::1`) e validação defensiva do IP de destino conectado (`CURLINFO_PRIMARY_IP`).
  - Adição de tabela de mock DNS (`WebhookSenderService::setDnsMock()`) para execução determinística e reproduzível da suíte de testes.
- **Blindagem do Contador de Visualizações e Rate Limiting no Nginx (SEC-03):**
  - Bloqueio do acesso direto ao script `location = /api/view.php` via Nginx com retorno HTTP 404, forçando o tráfego exclusivamente através da rota canônica `^~ /api/view/`, sujeita ao limitador defensivo de requisições `limit_req zone=lp_views burst=10 nodelay`.
- **Prevenção de DOM-based XSS nos Alertas de Upload (`public/admin/media.php`):**
  - Remoção de `innerHTML` na exibição dinâmica de alertas e mensagens de erro contendo nomes de arquivos (`file.name`).
  - Utilização estrita de `textContent` e construção estruturada via elementos DOM (`document.createElement('div')`) e `dynamicAlert.replaceChildren()`, em conformidade com as diretrizes da OWASP, neutralizando payloads de injeção em nomes de imagem.
- **Isolamento, Concorrência Otimista e Recarregamento de Rascunhos Privados no Editor (SEC-04):**
  - Reformulação do fluxo editorial em `public/admin/editor.php`: ao acionar "Salvar Rascunho" em artigos com status `published` ou `scheduled`, as alterações são salvas como revisões privadas em `post_revisions` (`draft` ou `pending_review`), preservando a linha pública da tabela `posts` e prevenindo vazamentos de conteúdo inacabado e divergências com o FastCGI Cache.
  - Concorrência otimista antecipada: validação de versão (`(int)$current['version'] !== $knownVersion`) executada estritamente antes de criar ou salvar qualquer revisão privada, impedindo que submissões baseadas em versões desatualizadas sobrescrevam revisões ou avancem `base_version` de forma espúria (retorno HTTP 409).
  - Controle de concorrência de rascunhos via `known_revision_id`: detecção de alterações concorrentes tanto no salvamento de rascunhos quanto na ação de publicar (`PostService::publishOrUpdate`), rejeitando submissões desatualizadas com HTTP 409 caso outro autor tenha gravado um rascunho recente da versão atual da matéria.
  - Primeira publicação e aprovação de revisões sem falsos positivos: `createPost()` fornece o identificador `$createdRevId` da revisão inicial recém-criada (com propagação por referência dentro da transação), permitindo que a publicação direta no editor (`editor.php`) e a aprovação no histórico (`revisions.php`) forneçam o `known_revision_id` correspondente sem disparar conflito espúrio de concorrência.
  - Publicação segura de matérias em rascunho: `PostService::publishOrUpdate()` passa a restringir o bloqueio por revisão desconhecida (`known_revision_id === 0`) exclusivamente a matérias já no ar ou agendadas (`published`/`scheduled`), permitindo a publicação instantânea de novas notícias e artigos em rascunho (`draft`) de primeira, sem exigir recarregamento via dashboard.
  - Vinculação de revisão conhecida em artigos em rascunho: o carregamento do editor agora localiza a revisão ativa para qualquer artigo existente (incluindo `status = 'draft'`), preenchendo `known_revision_id` no formulário para garantir publicações diretas seguras.
  - Marcação granular de revisões aplicadas e obsoletas: na publicação explícita, apenas a revisão conhecida (`known_revision_id`) é marcada como `'applied'`, enquanto outras revisões pendentes anteriores são arquivadas como `'superseded'`.
  - Suporte ao estado `superseded` no esquema e auto-migração nos dois bancos: inclusão formal de `'superseded'` no `ENUM` (`bin/schema.sql`) e no `CHECK constraint` do SQLite (`core/Schema.php`), acompanhado de auto-migração atômica e idempotente em `Schema::ensureRevisionsSchema()` para bancos existentes em MySQL e SQLite sem interrupções nem perda de dados históricos.
  - Desbloqueio e isolamento de revisões de versões anteriores: as buscas de concorrência de rascunhos agora filtram estritamente pela versão atual (`base_version = (int)$current['version']`), impedindo que revisões antigas de versões anteriores bloqueiem novos rascunhos ou publicações após o artigo ter avançado de versão.
  - Proteção contra sobreposição por revisões antigas: verificação de paridade de versão base (`(int)$latestRev['base_version'] === (int)$post['version']`) antes de carregar revisões pendentes. Caso a matéria pública tenha avançado, a revisão obsoleta é ignorada e um aviso em destaque é apresentado ao editor.
  - Preservação de corpo intencionalmente vazio: distinção rigorosa entre campos ausentes e conteúdos vazios (`array_key_exists` / `isset`) no carregamento do editor e em `core/PostService.php`. Garantia de que artigos limpos mantenham `content_json = '[]'`, evitando que fallbacks de compilação restaurem textos previamente deletados.
  - Recarregamento automático no editor do rascunho de revisão salvo mais recente, sobrepondo os campos no formulário e no canvas visual com banner informativo, permitindo continuar a edição contínua do texto recém-salvo sem perda de conteúdo.
  - Atualização do conteúdo público reservada à ação explícita de publicação (`publish`), marcando a revisão aplicada como `applied` e as demais revisões pendentes como `superseded`.
  - Purga automática no FastCGI Cache e na API da Cloudflare para a URL atual e para o slug anterior (`$current['slug']`) em caso de renomeação de matéria.
- **Imposição de Prazos de Sessão no Servidor (SEC-05):**
  - Implementação de verificação de prazo absoluto (24 horas / `session_lifetime`) e tempo limite de inatividade (2 horas / `session_idle_timeout`) diretamente no servidor no método `Security::requireAuth()` (`core/Security.php`).
  - Destruição da sessão no servidor, revogação de cookies e redirecionamento para `login.php?expired=1` ao atingir qualquer limite de expiração.
  - Inicialização dos marcadores `$_SESSION['auth_created_at']` e `$_SESSION['last_activity_at']` no login bem-sucedido (`public/admin/login.php`) e renovação de atividade em requisições autorizadas.
- **Resolução Segura de IP do Cliente e Compatibilidade com Cloudflare Tunnel (SEC-06):**
  - Atualização de `Security::getClientIp()` (`core/Security.php`) para priorizar o cabeçalho `X-Real-IP` fornecido por proxy reverso local confiável, mitigando spoofing via `CF-Connecting-IP` injetado pelo cliente.
  - Preservação transparente do reconhecimento de `CF-Connecting-IP` quando o tráfego tem origem em túneis do Cloudflare Tunnel.
- **Endurecimento de Ações Administrativas contra Vazamento de Tokens em URLs:**
  - Migração de todas as mutações e exclusões em `public/admin/pages.php`, `public/admin/categories.php`, `public/admin/dashboard.php`, `public/admin/media.php` e `public/admin/users.php` para requisições estritamente via `POST` com validação de `_csrf_token`.
- **Higiene e Determinismo do Repositório:**
  - Remoção de `package-lock.json` do `.gitignore` para assegurar inventário de dependências auditável e reproduzível.

### Correções Críticas, Mitigação de SSRF e Paridade Multi-banco
- **Correção de ParseError Fatal em `public/admin/activate.php`:**
  - Remoção de aspa dupla excedente na query de bloqueio com `FOR UPDATE`, restabelecendo o carregamento e ativação de contas.
- **Correção de SyntaxError em `public/admin/media.php`:**
  - Remoção de chave `}` órfã e chamada duplicada de `updateThemeIcon()` dentro da tag `<script>`, restabelecendo o upload assíncrono e scripts da biblioteca de mídias.
- **Implementação dos Métodos `Security::destroySession()` e `Security::getCurrentUser()`:**
  - Adição em `core/Security.php` de encerramento seguro de sessão com invalidação de cookies e recuperação dos dados do usuário logado, eliminando potenciais erros fatais em `dashboard.php` e `header.php`.
- **Proteção Contra SSRF no Plugin `webhook-sender`:**
  - Implementação de `WebhookSenderService::isSafeUrl()` com validação de scheme (apenas HTTP/HTTPS), proibição de `localhost` e metadados de nuvem, resolução de DNS (`gethostbynamel()`) e bloqueio rigoroso de faixas de IP privadas (`FILTER_FLAG_NO_PRIV_RANGE`) e reservadas (`FILTER_FLAG_NO_RES_RANGE`).
  - Configuração estrita de cURL com `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS` e `CURLOPT_REDIR_PROTOCOLS => 0`.
- **Validação Específica de Imagens (`BlockCompiler::sanitizeImageUrl`) e Prevenção de Injeção de Atributos:**
  - Criação de `BlockCompiler::sanitizeImageUrl()` que restringe URLs de imagens a caminhos locais iniciados por `/` (sem `//` ou `/\`) e `http://` / `https://`, bloqueando esquemas como `mailto:`, `tel:` e `javascript:`.
  - Aplicação na sanitização de `featured_image`, `site_favicon` e `avatar` (`editor.php`, `page-edit.php`, `settings.php`, `users.php`).
  - Sanitização e escape seguro de todas as variantes e dimensões extraídas do JSON `variants_manifest` em `BlockCompiler::compileImage()`.
- **Escape de Wildcards em Proteção de Exclusão de Mídia e Busca (`media.php` e `api/media.php`):**
  - Aplicação de `Security::escapeLike()` em todas as checagens relacionais e filtros de busca por nome e manifesto.
- **Compatibilidade Plena com SQLite no Plugin `webhook-sender`:**
  - Implementação condicional de `ON CONFLICT(post_id) DO UPDATE SET ...` para SQLite e `ON DUPLICATE KEY UPDATE ...` para MySQL ao registrar logs de disparo.
- **Eliminação de Falsos Positivos na Busca por Tags e Escape de Curingas SQL:**
  - Refatoração da listagem pública de tags (`/tag/{tag}/` e `/tag/{tag}/page/{page}/`) em `public/index.php`. Uso de `FIND_IN_SET(?, REPLACE(COALESCE(p.tags, ''), ', ', ','))` no MySQL e delimitadores seguros no SQLite, garantindo que buscas por termos curtos (ex: `car`) não retornem falsos positivos contendo apenas substrings (ex: `stockcar` ou `carlos`).
- **Desacoplamento e Idempotência de Views de Páginas Institucionais:**
  - Remoção de `UPDATE pages SET views = views + 1` no GET de `public/index.php`, prevenindo escritas em disco e garantindo cache FastCGI de 3600 segundos limpo.
  - Implementação de rastreamento assíncrono via `navigator.sendBeacon` para `/api/view.php?type=page&id={id}`, com deduplicação atômica por IP em janela de 30 segundos.

### Upload de Mídia via Drag-and-Drop e Auto-Seleção nos Editores (Posts e Páginas)
- **Área de Arrastar e Soltar (Drag & Drop Zone):**
  - Adição de área de soltura dedicada no modal da Biblioteca de Mídias em `public/admin/editor.php` e `public/admin/page-edit.php` com o texto *"Solte arquivos aqui para enviar ou Enviar nova foto"*.
  - Suporte completo a drag-and-drop tanto na zona visual tracejada quanto em todo o diálogo do modal, com feedback visual em tempo real (`.modal-drop-zone.dragover`).
  - Suporte a clique direto para abrir o seletor nativo de arquivos do sistema operacional.
- **Auto-Seleção Imediata após Upload:**
  - Após o upload da imagem e a geração em segundo plano dos formatos modernos WebP e AVIF, a lista de mídias é recarregada e a imagem recém-enviada é **selecionada automaticamente**.
  - O card da nova imagem recebe destaque visual imediato (`.selected`), rola suavemente para o campo de visão (`scrollIntoView`), os metadados (nome, tamanho em KiB e dimensões em pixels) são populados no rodapé do modal e o botão **"Confirmar Seleção"** é habilitado na hora, proporcionando fluxo de trabalho contínuo e ágil.
- **Validação e Paridade em Temas:**
  - Validação de extensões no lado do cliente (WebP, AVIF, JPEG, PNG).
  - Estilização responsiva com suporte completo ao Dark Mode (`[data-theme="dark"] .modal-drop-zone`).

### Hardening de Segurança, Concorrência e Resiliência Arquitetural
- **Prevenção Contra Injeção de Curingas SQL (LIKE Injection):**
  - Implementação do método utilitário centralizado `Security::escapeLike(string $value): string` (`core/Security.php`), tratando os caracteres de controle do SQL (`%`, `_` e `\`).
  - Aplicação obrigatória em `public/api/search.php`, na busca pública de fallback em `public/index.php`, na sincronização de mídias em `core/ImageProcessor.php` (`syncRefs`), e nos filtros do painel administrativo (`public/admin/dashboard.php` e `public/admin/pages.php`), mitigando riscos de Full Table Scan não intencional (DoS) e falso matching de arquivos com underline.
- **Resolução de Race Condition no Rate-Limiting de Login (Brute Force):**
  - Refatoração dos métodos `Security::checkLoginRateLimit()` e `Security::recordLoginFailure()` (`core/Security.php`) para operar com sincronização rigorosa de arquivos via `flock()`.
  - A leitura agora adquire trava compartilhada (`LOCK_SH`), aguardando conclusões de escrita antes da desserialização do JSON e prevenindo leituras de arquivos truncados a 0 bytes.
  - Eliminação da exclusão acidental do arquivo de log (`unlink`) em casos de leitura de conteúdo vazio sob alta concorrência paralela.
- **Prevenção de Publicação Acidental e Fallback 1970 em Datas Inválidas:**
  - Correção em `public/admin/editor.php` e `core/PostService.php` para validar se o parsing de data de agendamento não resulta em `false` ou timestamp com ano 1970 (`01/01/1970 00:00:00`).
  - Prevenção do erro de lógica em que strings de data corrompidas eram formatadas como timestamp 0, fazendo o `PostService` considerar a data menor que `NOW()` e publicar a matéria instantaneamente em vez de retê-la.
- **Sincronização de Status ao Salvar Rascunho com Data Limpa:**
  - Correção no fluxo de atualização manual de rascunhos em `public/admin/editor.php`: se um editor limpar o campo `published_at` e salvar a matéria como rascunho, o `status` é explicitamente revertido para `'draft'`.
  - Eliminação de registros "fantasmas" (`status = 'published'` com `published_at IS NULL`) que ficavam invisíveis para leitores e APIs públicas.
  - Invalidação automática e imediata de cache (`CacheManager::purgeUrls`) para a matéria, home e categoria caso uma notícia pública seja despublicada para rascunho.
- **Blindagem Contra Edge-Case de Open Redirect via Backslash no Roteador:**
  - Normalização no método `Router::dispatch()` e na canonicalização de barra final para neutralizar caminhos maliciosos iniciados com `\`, `/\` ou `//`.
  - Tratamento em profundidade em `Router::redirect()` e `Router::isValidRedirectTarget()`, rejeitando destinos que utilizem barras invertidas para enganar clientes HTTP e navegadores legados.
- **Defesa Contra Redirecionamentos Abertos (Open Redirect / CWE-601):**
  - Implementação do validador estrito `Router::isValidRedirectTarget(string $target): bool` (`core/Router.php`).
  - Bloqueio de ataques via evasão de protocolo relativo (`//site-malicioso.com`), barras invertidas (`/\site-malicioso.com`), esquemas não permitidos (`javascript:`, `data:`) e redirecionamentos para hosts externos não autorizados.
  - Despacho da tabela de redirecionamentos (`redirects`) integrado a `$this->redirect()`, garantindo conformidade com o ambiente de testes e com as diretrizes de segurança defensiva.
- **Otimização Algorítmica e Prevenção de DoS na Geração de Slugs (`resolveUniqueSlug`):**
  - Eliminação do loop sequencial `while (true)` em `core/PostService.php`, que executava centenas de queries individuais em cascata ($O(N)$) na existência de publicações homônimas.
  - Implementação de algoritmo em dois passos com resolução em lote: checagem rápida em 1 query para slugs livres e, em caso de colisão, leitura agrupada de todos os prefixos correspondentes em uma única query (`slug = ? OR slug LIKE ?`).
  - Cálculo do maior sufixo numérico em memória (ex: `artigo-150` -> `artigo-151`), com teto de até 10 tentativas e fallback pseudoaleatório seguro (`bin2hex(random_bytes(3))`).
- **Suporte Nativo a Subdiretórios (`base_path`) no Roteador:**
  - Adição de propriedade e configuração de `$basePath` em `core/Router.php`, permitindo hospedar o CMS em subpastas (ex: `https://dominio.com/blog/`).
  - Suporte nos arquivos de configuração (`config.php` e `config.local.php.example`) e autodetecção inteligente em `public/index.php` baseada em `canonical_origin`.
  - Normalização automática de caminhos no método `dispatch()` e prefixação dinâmica de URLs internas em `Router::redirect()`, impedindo que canonicalizações e redirecionamentos 301 expulsem o usuário para fora do subdiretório.
- **Normalização de Parâmetros Nomeados no Wrapper de Banco de Dados (`Database::query`):**
  - Atualização em `core/Database.php` para prefixar automaticamente os dois pontos (`:`) em chaves de arrays associativos (`['id' => 1]` -> `:id`), resolvendo incompatibilidade estrita do método `PDO::bindValue()` com parâmetros nomeados sem prefixo.
- **Saneamento e Escape HTML no Editor JavaScript (XSS Contextual):**
  - Aprimoramento da função JavaScript `escapeHtml()` em `public/admin/editor.php` e `public/admin/page-edit.php`, cobrindo obrigatoriamente aspas duplas (`&quot;`) e aspas simples (`&#039;`).
  - Sanitização obrigatória da propriedade `selectedMediaItem.full_url` antes de concatená-la ao atributo `src` na inserção de imagens via `applyMediaSelection()`.
- **Expansão da Suíte de Testes Automatizados (`bin/test.php`):**
  - Inclusão de testes unitários para escape de wildcards SQL (`Security::escapeLike`), validação de rotas em subdiretórios com `Router` e verificação de regras de segurança contra Open Redirect.

### Ordenação Cronológica e Paginação Completa da Busca Pública (`/busca/`)
- **Ordenação por Recência (`published_at DESC`):**
  - Substituição da ordenação prioritária por pontuação estática de relevância (`ORDER BY score DESC`) pela ordenação cronológica decrescente (`ORDER BY p.published_at DESC`), garantindo que as notícias mais recentes sempre apareçam no topo dos resultados da pesquisa.
- **Paginação Completa e Totalizador Real de Resultados:**
  - Remoção do teto rígido de 30 resultados (`LIMIT 30`), implementando paginação fluida de 12 itens por página com `LIMIT ? OFFSET ?`.
  - Implementação de contagem atômica de matérias (`COUNT(*)`) para exibição precisa do volume total de notícias encontradas no badge de status do cabeçalho de busca (ex: *"540 resultados encontrados"*).
  - Adição de barra de paginação minimalista responsiva em `templates/search.php`, em total harmonia estética com as listagens de categorias e tags, mantendo o termo consultado na URL (`/busca/?q=...&page=...`).
  - Criação de rota de compatibilidade `/busca/page/{page}/` com redirecionamento canônico 301 para a busca paginada.

### Correção de Rota e Redirecionamento da Busca Pública (`/busca/`)
- **Resolução de Erro 404 em Pesquisas (`/busca/?q=...`):**
  - Registro da rota de busca com barra final (`/busca/` em `public/index.php`), alinhando com a canonicalização e padrão de rotas públicas do sistema (`Router.php`).
  - Prevenção do conflito em que `/busca/` caía na rota genérica de artigos individuais `/{slug}/` e retornava erro 404 por `busca` ser uma palavra reservada.
  - Padronização dos links e formulários geradores para evitar redirecionamentos desnecessários 301:
    - Atualização do atributo `action="/busca/"` no formulário de pesquisa do cabeçalho global (`templates/header.php`).
    - Atualização do redirecionamento de busca na raiz para `/busca/?q=...` (`public/index.php`).
    - Atualização da URL de `potentialAction` (Schema.org / SearchAction) para `/busca/?q={search_term_string}` (`core/Seo.php`).

### Expansão Panorâmica do Layout Público (`1900px`)
- **Aumento da Largura Máxima do Container (Home, Categorias, Tags, Posts e Navegação Geral):**
  - Atualização do container central e dos blocos estruturais de navegação pública de `max-w-[1280px]` para `max-w-[1900px]`, ampliando substancialmente o espaço útil horizontal para notícias, mosaicos de matérias, manchetes e barras laterais em monitores Full HD, Quad HD e Ultrawide.
  - Alinhamento de todos os componentes do cabeçalho global (`templates/header.php`): Top Strip de credibilidade/newsletter, barra de navegação principal (logo, menu de categorias e busca) e faixa de ticker "Em Alta".
  - Expansão do invólucro principal de conteúdo (`<main>` em `templates/header.php`) para `max-w-[1900px]`, aplicando-se uniformemente a todas as páginas sem estar logado (Home, Categorias, Tags, Postagens, Páginas Institucionais, Busca e Páginas de Erro).
  - Alinhamento do container do rodapé (`templates/footer.php`) para `max-w-[1900px]`, mantendo harmonia geométrica perfeita entre o topo, o corpo e a base do site.
  - Recompilação dos estilos Tailwind CSS de produção (`public/assets/style.css`), gerando a nova regra utilitária `.max-w-\[1900px\] { max-width: 1900px; }`.

### Modernização e Paridade Visual da Edição de Páginas Institucionais (`public/admin/page-edit.php`)
- **Paridade Estrutural com o Editor de Notícias:**
  - Reconstrução completa da tela de inclusão e edição de páginas institucionais para adotar layout, componentes, responsividade e experiência de uso rigorosamente idênticos ao editor de notícias (`public/admin/editor.php`).
- **Canvas Visual Direto e Limpador de Ctrl+V (Docs / Word):**
  - Implementação de área de escrita contínua `contenteditable="true"` (`.editor-canvas`) com suporte a alternância em tempo real para código-fonte `</> HTML` via textarea de fallback.
  - Sanitizador de colagem inteligente que intercepta o evento `paste` e remove automaticamente estilos inline poluídos, spans vazios, classes proprietárias do Word (`MsoNormal`), metadados de cabeçalho e wrappers de GUID do Google Docs, preservando apenas marcações semânticas limpas.
- **Barra de Ferramentas Rica Sticky:**
  - Toolbar rica contendo botões para títulos de seção (H2, H3), parágrafos (P), negrito, itálico, sublinhado, listas com marcadores e numeradas, citações em bloco, inserção segura de links, atalho para fotos da biblioteca e limpeza de formatação.
  - Fixação refinada no topo com `top: 0px` para rolagem fluida sem cobrir o texto em edição.
- **Integração com a Biblioteca de Mídias (WordPress-Style):**
  - Modal assíncrono para seleção rápida de imagens da biblioteca ou upload direto com geração imediata de variantes WebP/AVIF.
  - Suporte à seleção de **Imagem de Destaque** com card dedicado, preview visual em proporção 16:9 e botão flutuante para desvinculação em 1 clique (`✕ Remover Imagem`).
  - Inserção de imagens no corpo institucional com formatação semântica `<figure>`, `<img>` e `<figcaption>`.
- **Slug / Link Permanente Dinâmico:**
  - Campo com prefixo canônico `https://dominio.com/`, link de prévia e botão gerador automático `Auto 🪄` acoplado ao título da página.
- **Painel Lateral de Publicação e SEO Nativo:**
  - Card de status dinâmico com badge visual (`✓ Publicada no Ar` acompanhado de atalho `Ver ↗`, `📝 Modo Rascunho` ou `📦 Arquivada`).
  - Botão principal de ação em largura total com cor vermelha oficial (`#E10600`) alternando dinamicamente entre **"Atualizar Página"** e **"🚀 Publicar Página"**, além do botão secundário **"💾 Salvar como Rascunho"**.
  - Metadados detalhados de ID, visualizações registradas, data de criação e data de atualização.
  - Card dedicado de **SEO Nativo** com campos para Tag Title e Meta Description.

### Refinamento de Usabilidade no Editor de Notícias (`public/admin/editor.php`)
- **Ajuste de Rolagem da Barra de Ferramentas:**
  - Ajuste de posição sticky da `.editor-toolbar` para `top: 0px` (substituindo o espaçamento residual de `57px`), eliminando qualquer sobreposição visual sobre o texto digitado durante a rolagem contínua.

### Padronização da Área Administrativa (/admin) e Header Unificado
- **Cabeçalho Unificado Compartilhado (`public/admin/header.php`):**
  - Eliminação de duplicações estruturais de HTML, metadados, scripts anti-flash de tema e folhas de estilos em todas as páginas da área administrativa.
  - Carregamento dinâmico e centralizado das configurações da publicação via `Settings::get('site_title')` e `Settings::get('site_favicon')`.
  - Exibição dinâmica do **Título do Site (Nome da Publicação)** no link `<a href="/admin/dashboard.php" class="brand">`, eliminando textos estáticos hardcoded.
  - Navegação global completa com suporte ao estado ativo (`$activePage`), submenu suspenso para plugins ativos, menu de usuário logado com atalho para perfil, botão alternador de tema e link seguro de logout com token CSRF.
- **Rodapé Compartilhado e Gerenciador de Tema (`public/admin/footer.php`):**
  - Centralização dos scripts da interface administrativa, incluindo alternância de tema claro/escuro (`toggleTheme()`), sincronização persistente com `localStorage('lp_theme')` e atualização automática dos ícones ☀️ / 🌙.
- **Largura Universal de 1900px (`.container`):**
  - Padronização de todas as páginas administrativas para a largura máxima de **1900px** (`max-width: var(--admin-max-width)`), expandindo os limites anteriores fragmentados (1550px em `dashboard.php`, 1350px em `media.php`, 1200px em `pages.php`, `categories.php`, `plugins.php` e `system.php`, 1450px em `stats.php`, 1400px em `users.php`, 1100px em `editor.php`, 1000px em `plugin-settings.php` e `revisions.php`, 980px em `settings.php` e 960px em `profile.php`).
  - Excelente aproveitamento de espaço em monitores widescreen para tabelas, grades de mídias, filtros e formulários, com total responsividade mobile preservada (`padding: 0 1.5rem`).
- **Padronização Visual da Paleta para Vermelho Oficial (`#E10600`):**
  - Substituição definitiva de todas as tonalidades azuis remanescentes (`#2563eb`, `#3b82f6`, `#1d4ed8`, `rgba(37,99,235,...)`) pela cor padrão oficial da publicação (`--primary-accent: #E10600;`, `--primary-hover: #b80500;`, `--primary-light: rgba(225, 6, 0, 0.08);` e `--primary-focus: rgba(225, 6, 0, 0.2);`).
  - Aplicação unificada em botões (`.btn`, `.btn-filter`), links em destaque, anéis de foco em formulários, badges de categorias e tags, avatares de autores e telas de acesso (`login.php` e `activate.php`).
- **Refatoração Completa das Páginas Administrativas:**
  - Migração de 100% das páginas para a nova arquitetura: `dashboard.php`, `pages.php`, `page-edit.php`, `media.php`, `categories.php`, `stats.php`, `users.php`, `plugins.php`, `settings.php`, `system.php`, `profile.php`, `revisions.php`, `editor.php` e `plugins/webhook-sender/settings.php`.

### Documentação Técnica do Primeiro Plugin (`Plugin-Webhook.md`)
- **Criação do Documento Arquitetural Completo (`Plugin-Webhook.md`):**
  - Registro minucioso de todo o ciclo de engenharia do plugin `webhook-sender`.
  - Comparativo detalhado da migração WordPress vs. LightPress (eliminação do modelo EAV `postmeta`, tipagem estrita PHP 8.4, suporte universal a múltiplos servidores de webhook, garantia transacional pós-commit e entrega de assets estáticos no Nginx).
  - Especificação dos fluxos operacionais, esquemas DDL da tabela relacional `plugin_webhook_sender_logs`, tratamento de concorrência com `FOR UPDATE`, diagramas em Mermaid e resolução de domínio canônico em CLI/cron.

### Arquitetura de Plugins e Extensões (Zero Bloatware)
- **Barramento de Eventos Ultraleve (`core/Hook.php`):**
  - Implementação de Actions (`addAction`, `doAction`, `hasAction`, `removeAction`) e Filters (`addFilter`, `applyFilters`, `hasFilter`, `removeFilter`) com ordenação por prioridade numérica.
  - Injeção de pontos de extensão estratégicos: `frontend_head`, `frontend_footer` e `post_published`.
- **Gerenciador de Ciclo de Vida (`core/PluginManager.php`):**
  - Leitura declarativa de metadados via `plugin.json` sem avaliação prévia de código PHP.
  - Ativação, desativação e carregamento sob demanda através de `settings.active_plugins`.
  - Mapeamento transparente de assets estáticos de `/plugins/{slug}/assets/` para `/public/assets/plugins/{slug}/`.
- **Navegação Administrativa Dinâmica (`core/AdminNav.php`):**
  - Centralização do cabeçalho unificado do painel administrativo com menu suspenso **Plugins ▾** com links diretos para a listagem geral e telas de configurações dos plugins ativos.
- **Primeiro Plugin Oficial: Webhook Sender (`plugins/webhook-sender/`):**
  - Manifesto declarativo `plugin.json` e entrypoint `webhook-sender.php`.
  - Suporte a múltiplos servidores/endpoints separados por vírgula ou quebras de linha.
  - Tela administrativa `settings.php` com botão de ping de teste em tempo real, auditoria com status de entrega (`is_success`) e botão de reenvio manual em 1 clique.
  - Resiliência de rede: cURL com timeout curto (4s) não-bloqueante; respostas de erro (4xx/5xx/timeout) são registradas com `is_success = 0` para permitir novas tentativas sem travar matérias.

### Webhooks em Matérias Agendadas (`core/PostService.php` e `plugins/webhook-sender/`)
- **Disparo de Webhook na Auto-Promoção (`core/PostService::promoteScheduledPosts()`):**
  - Adicionado o disparo do hook `post_published` para cada matéria agendada que atinge o horário de publicação e é promovida de `scheduled` para `published`.
  - Inclusão dos campos editoriais completos (`title`, `summary`, `tags`, `featured_image`, `content_html`, `published_at`) na consulta de matérias vencidas, garantindo a montagem integral do payload de notificação para plugins externos.
  - Prevenção de concorrência com verificação do `rowCount()` no `UPDATE` para garantir que apenas um processo dispare os webhooks e purgue o cache.
  - Suporte a `$config['app']['canonical_origin']` no `WebhookSenderService` para que execuções via CLI/cron montem URLs absolutas corretas mesmo na ausência de `$_SERVER['HTTP_HOST']`.

### Agendamento e Auto-Promoção de Publicações
- **Promoção Automática On-Request (`core/PostService::promoteScheduledPosts()`):**
  - Implementada verificação automática no carregamento das rotas públicas (`public/index.php`) e administrativas (`dashboard.php` e `editor.php`) que busca matérias com `status = 'scheduled' AND published_at <= UTC_NOW` e as promove atomicamente para `status = 'published'`.
  - Disparo imediato de invalidação de cache (disco FastCGI e CDN Cloudflare) para `/`, `/{slug}/`, `/categoria/{category}/`, feeds RSS e sitemap no momento em que a data/hora é alcançada, sem depender exclusivamente de cron job externo.
- **Correção Visual de Badges e Ações na Redação (`dashboard.php` e `editor.php`):**
  - Corrigida a lógica de exibição em que matérias cujo horário de agendamento já havia sido ultrapassado continuavam com o badge amarelo "Agendado" e sem o botão "Ver ↗" na tabela de notícias e no editor. Agora compara adequadamente o timestamp `published_at` com o horário atual, renderizando a etiqueta verde "Publicado" e o link direto para o artigo no ar.
- **Robustez e Logs no Worker de Cron (`bin/retry-cache-purge.php` e `bin/cleanup.php`):**
  - Substituído o bloco com `catch (\Throwable) {}` silencioso em `bin/retry-cache-purge.php` (linha 28) por `PostService::promoteScheduledPosts()`, com captura de exceções e registro explícito no `error_log`.
  - Tratamento de erro detalhado caso a abertura do arquivo de trava (`cache_purge_worker.lock`) falhe por permissões de sistema.
  - Sincronização da purga de cache (disco FastCGI e CDN Cloudflare) quando o worker promove artigos agendados pelo cron.

### Correção do Painel de Plugins
- Corrigidas as chamadas de autenticação e geração de token CSRF em `public/admin/plugins.php` e `public/admin/plugin-settings.php`, eliminando os erros fatais por parâmetro nomeado desconhecido (`roles`) e método inexistente (`getCsrfToken`).

### Cache, Invalidação de Borda & Cloudflare API v4
- **Invalidador Seletivo de Borda na Cloudflare (`core/CloudflareCache.php`):**
  - Implementação do serviço de purga seletiva de cache via Cloudflare API v4 (`POST /client/v4/zones/{zone_id}/purge_cache`), invalidando pontualmente as páginas impactadas (Home `/`, feeds RSS/Atom `/feed.xml` e `/feed/`, `/sitemap.xml`, a notícia recém-publicada `/{slug}/` e sua respectiva categoria `/categoria/{slug}/`) sem derrubar o cache global de imagens, CSS e JavaScript.
  - Normalização automática de URLs canônicas a partir de `$config['app']['canonical_origin']` (`https://www.speedracing.com.br`), aceitando indistintamente URIs relativas ou URLs completas.
  - Agrupamento determinístico em lotes de até 30 URLs por requisição (respeitando o teto máximo de arquivos por chamada da Cloudflare API).
  - Execução estritamente não-bloqueante (timeout de 5s, conexão rápida de 3s) e tratamento defensivo via `error_log`, garantindo que oscilações de rede ou indisponibilidades temporárias na API da Cloudflare nunca travem a redação nem causem erro 500 no salvamento de matérias.
  - Suporte duplo de namespace PSR-4 nativo: `LightPress\Core\CloudflareCache` e alias `Core\Services\CloudflareCache` com carregamento automático transparente em `core/bootstrap.php`.
  - Métodos estáticos fornecidos:
    - `purgeUrls(array $urls): bool`: Purga seletiva de lista de URLs/URIs na Cloudflare.
    - `purgeOnPostPublish(string $postUrl, ?string $categoryUrl = null): bool`: Disparo automático pós-publicação com sincronização dupla (Cloudflare + Nginx local).
    - `purgeEverything(): bool`: Purga global da zona para manutenções estruturais e redesigns.
    - `testConnection(): array`: Validação direta de credenciais e permissão real de purga através de requisição de sonda controlada.
- **Orquestração Híbrida de Cache (Edge + Origem) (`core/CacheManager.php`):**
  - Refatoração dos métodos `getCacheFilePath()` e `purgeUrls()` para suporte nativo e robusto a URLs absolutas completas (`https://www.speedracing.com.br/slug/`), realizando o parse do caminho e host antes do cálculo do hash MD5 (`levels=1:2`).
  - Sincronização automática com a Cloudflare: quando `cloudflare.enabled` estiver ativo, o `CacheManager` despacha as URLs afetadas para o `CloudflareCache`, propagando a invalidação simultaneamente no disco do servidor (`/var/cache/nginx/wordpress`) e na CDN global.
  - Benefício arquitetural imediato: todas as ações editoriais do LightPress (publicação e atualização em `PostService`, exclusão de matérias em `dashboard.php`, edição de categorias em `categories.php`, páginas institucionais em `page-edit.php`/`pages.php`, configurações em `settings.php` e retentativa em fila de background via `bin/retry-cache-purge.php`) agora invalidam a borda Cloudflare de maneira 100% automatizada e transparente.
  - Adição do método utilitário `clearLocalCacheFolder()` para esvaziamento completo e recursivo do diretório de cache FastCGI em manutenções.
- **Utilitário CLI de Purga e Diagnóstico (`bin/purge-cache.php`):**
  - Criação de ferramenta CLI interativa para administração e testes no terminal do servidor:
    - `php bin/purge-cache.php --test`: Valida conectividade, Token e Zone ID diretamente na API da Cloudflare.
    - `php bin/purge-cache.php --slug="<slug>"`: Purga a matéria específica, a home, os feeds e o sitemap.
    - `php bin/purge-cache.php --url="<URL>"`: Purga uma URL arbitrária.
    - `php bin/purge-cache.php --home`: Purga Home, feeds e sitemap.
    - `php bin/purge-cache.php --all`: Dispara Purge Everything na Cloudflare e esvazia o cache FastCGI local em disco.
- **Painel Administrativo - Diagnóstico de Borda (`public/admin/system.php`):**
  - Adição do card informativo **Cloudflare Edge Cache (API v4)** na página de Saúde do Sistema, exibindo em tempo real o status de ativação, Zone ID mascarado, integridade do API Token e modalidade de purga seletiva ativa.
- **Configuração Central e Exemplos (`config.php`, `config.local.php.example`):**
  - Inclusão do bloco padronizado `'cloudflare' => ['enabled' => false, 'zone_id' => '', 'api_token' => '', 'timeout' => 5]` no arquivo central `config.php`.
  - Atualização do `config.local.php.example` com o modelo de configuração para servidores de produção com Cloudflare e FastCGI Nginx (`/var/cache/nginx/wordpress`).
- **Suíte de Testes Automatizados (`bin/test.php`):**
  - Adicionados testes de integridade para cálculo de hash MD5 a partir de URLs absolutas completas, verificação de carregamento de classes e compatibilidade de alias de namespace `Core\Services\CloudflareCache`.

### Ferramentas CLI & Gestão de Mídias em Produção
- **Catalogação em Lote de Mídias Existentes em Produção (`bin/catalog-media.php`):**
  - Implementação de utilitário CLI de alta performance para escanear acervos legados em `/public/uploads/` sem reprocessamento gráfico, sem consumo excessivo de CPU e com footprint de memória mínimo (< 32 MB).
  - Agrupamento determinístico de variantes (`full`, `intermediate` e `thumb` em WebP e AVIF) a partir de seus slugs e sufixos padronizados ou legados do WordPress.
  - Extração de dimensões e tipos MIME diretamente dos cabeçalhos binários (`@getimagesize`), gerando o manifesto de variantes compatível com o `ImageProcessor` e o painel administrativo.
  - Mapeamento bidirecional e indexação em `media_refs` associando cada mídia às matérias que a utilizam em `featured_image` com complexidade O(1).
  - Suporte a `--dry-run`, `--limit=N`, `--chunk=500`, `--update-posts` e `--verbose`.
- **Busca em Tempo Real e Ordenação na Biblioteca de Mídias (`public/admin/media.php`):**
  - Implementação de busca reativa em tempo real com debounce de 300ms e atualização assíncrona do grid via `fetch` e `DOMParser`, eliminando recarregamentos de página.
  - Inversão da ordenação padrão de mídias para ordem cronológica (`ORDER BY m.created_at ASC, m.id ASC`), acompanhada de seletor dropdown na barra de filtros para alternância dinâmica entre mais antigas e mais recentes.
  - Paginação assíncrona com interceptação de cliques e rolagem suave (`window.scrollTo`), preservando histórico no navegador com `history.replaceState`.
- **Paginação e Busca Rápida na Biblioteca de Mídias (`public/admin/media.php`, `public/admin/api/media.php`, `public/admin/editor.php`):**
  - Implementação de barra de busca textual e sistema de paginação de alta velocidade (48 itens por página) com navegação dinâmica e contadores totais em `public/admin/media.php`.
  - Endpoint de API interna (`public/admin/api/media.php`) aprimorado para consultar simultaneamente por nome original, manifesto de variantes e identificador único de mídia.
  - Conexão do modal de seleção de fotos no editor de notícias (`public/admin/editor.php`) com busca assíncrona com debounce de 300ms, permitindo pesquisar e filtrar instantaneamente entre todas as 30.000 imagens diretamente na redação.
- **Otimização de Índices para Acervos Massivos (`bin/schema.sql`, `core/Schema.php`):**
  - Adição do índice composto `INDEX idx_media_created (created_at DESC)` na tabela `media`, garantindo que a listagem de fotos no painel administrativo ([`public/admin/media.php`](file:///c:/Web/lightpress/public/admin/media.php)) execute em < 1ms mesmo com mais de 30.000 imagens catalogadas.

### Correções Críticas de Execução, Segurança & Integridade de Dados
- **Resolução de Erro de Truncamento ao Agendar Notícias (`core/PostService.php`, `bin/migrate-scheduled.php`):**
  - Implementação de detecção automática com self-healing DDL em `PostService::ensureScheduledEnumSupported()`, garantindo que a coluna `status` da tabela `posts` e de `post_revisions` possua os valores `'scheduled'` e `'pending_review'`, eliminando o erro MySQL `Warning: 1265 Data truncated for column 'status' at row 1` ao agendar matérias.
  - Atualização do utilitário `bin/migrate-scheduled.php` para sincronizar ambas as tabelas diretamente pelo terminal.
- **Importação de `ImageProcessor` no Painel Administrativo (`public/admin/editor.php`, `public/admin/revisions.php`):**
  - Adicionada a declaração explícita `use LightPress\Core\ImageProcessor;`, eliminando o erro fatal `Error: Class "ImageProcessor" not found` ao salvar rascunhos de matérias existentes ou restaurar revisões históricas.
- **Visibilidade, Contabilização e Promoção Automática de Artigos Agendados (`public/api/view.php`, `public/api/posts.php`, `public/api/search.php`, `public/api/categories.php`, `public/admin/stats.php`, `public/admin/dashboard.php`, `bin/cleanup.php`, `bin/retry-cache-purge.php`):**
  - Unificação do predicado SQL de visibilidade para `status IN ('published', 'scheduled') AND published_at <= ?`, garantindo que artigos com data de agendamento atingida computem visualizações e sejam retornados nas APIs e estatísticas administrativas.
  - Promoção automática periódica de posts agendados vencidos de `'scheduled'` para `'published'` em `bin/cleanup.php` e `bin/retry-cache-purge.php`.
- **Compatibilidade Portátil Multi-SGBD (MySQL / MariaDB & SQLite) (`public/api/view.php`, `public/admin/activate.php`, `public/admin/editor.php`, `public/admin/revisions.php`, `core/PostService.php`, `bin/seed.php`, `public/admin/categories.php`, `public/admin/dashboard.php`, `public/admin/users.php`):**
  - Cláusulas de lock pessimista `FOR UPDATE` tornadas condicionais via `Database::getDriver() === 'mysql'`, mantendo segurança concorrencial em MySQL e compatibilidade nativa com transações SQLite.
  - Conversão de funções exclusivas do MySQL como `IF(cond, 1, 0)` para o padrão ANSI SQL `CASE WHEN cond THEN 1 ELSE 0 END`.
  - Adaptação de upserts em lote entre `ON DUPLICATE KEY UPDATE` (MySQL) e `ON CONFLICT` (SQLite).
- **Proteção Contra IP Spoofing em `X-Forwarded-For` (`core/Security.php`):**
  - Inspeção da cadeia `X-Forwarded-For` avaliada rigorosamente da direita para a esquerda (`array_reverse($ips)`), descartando proxies confiáveis conhecidos e capturando o primeiro IP cliente real não confiável, bloqueando bypasses de rate limit via cabeçalhos injetados.
- **Eliminação de DOM XSS e Sanitização Estrita de Imagens (`public/admin/page-edit.php`, `public/admin/media.php`, `public/admin/editor.php`, `public/admin/settings.php`, `public/admin/users.php`):**
  - Substituição da injeção de HTML via `innerHTML` em `previewImage` e `showAlert` por manipulação segura de nós DOM (`createElement`, `textContent`, `.src`).
  - Validação e higienização obrigatória de URLs para `featured_image`, `avatar` e `site_favicon` com `BlockCompiler::sanitizeUrl()`.
- **Proteção CSRF em Ações Administrativas Destrutivas (`public/admin/dashboard.php`, `public/admin/pages.php`, `public/admin/categories.php`, `public/admin/users.php`, `public/admin/media.php`):**
  - Conversão de todas as operações de mutação e exclusão de `GET` para requisições `POST` com validação de token CSRF (`Security::validateCsrfToken()`), atualizando a interface administrativa para formulários inline acessíveis.
- **Preservação de Destaques Editoriais (`core/PostService.php`):**
  - Preservação do valor original de `is_featured` caso a chave não esteja presente no payload submetido, prevenindo perda silenciosa de destaques na aprovação de revisões de autores.
- **Resolução de Slugs Reservados e Prevenção de Conflitos (`core/PostService.php`, `public/admin/editor.php`):**
  - Criação de catálogo de rotas reservadas do sistema (`admin`, `api`, `uploads`, `busca`, `feed`, `sitemap`, etc.) e aplicação automática de `PostService::resolveUniqueSlug()` ao salvar rascunhos ou publicar artigos.
- **Atomicidade no Rate Limiting de Autenticação (`core/Security.php`, `bin/cleanup.php`):**
  - Operação atômica de leitura, atualização e escrita com bloqueio exclusivo de arquivo (`flock(LOCK_EX)`), prevenindo condições de corrida sob ataques de força bruta paralelos. Limpeza programada de arquivos expirados em `bin/cleanup.php`.
- **Backup e Restauração Abrangentes (`bin/backup.php`, `bin/restore.php`):**
  - Inclusão das tabelas `pages` e `settings` no backup completo, escape de identificadores com backticks, whitelist estrita de tabelas na restauração e tratamento transparente de foreign keys em MySQL e SQLite.

### Correções de Bugs & Integridade Transacional
- **P1: Proteção Transacional contra Concorrência e Sobrescrita Editorial (`public/admin/editor.php`, `public/admin/revisions.php`):**
  - Centralização da gravação de rascunhos e restauração de revisões em transações atômicas com bloqueio pessimista `SELECT ... FOR UPDATE` e atualização condicional `WHERE id = ? AND version = ?`.
  - Reavaliação em tempo real das permissões sobre o estado atualizado da linha no banco. Caso uma matéria seja aprovada ou publicada concorrentemente por um editor enquanto um autor salvava ou restaurava, o sistema gera com segurança uma revisão privada pendente de aprovação (`pending_review`), preservando a versão pública aprovada intacta e neutralizando conflitos de edição concorrentes.
- **P2: Execução Efetiva da Purga Física de Cache Pós-Commit (`core/PostService.php`):**
  - Eliminação do retorno prematuro da transação em `PostService::publishOrUpdate()` que impedia a execução de código posterior.
  - Escopo de `$affectedUrls` externalizado via passagem por referência na closure e chamada a `$cacheManager->purgeUrls($affectedUrls)` executada estritamente após a conclusão bem-sucedida do commit no banco de dados.
- **P2: Preservação do Estado Público na Aplicação de Revisões (`public/admin/revisions.php`, `core/PostService.php`):**
  - Desacoplamento do status interno do snapshot da revisão (`payload.status = 'pending_review'`) do estado da matéria pública no portal. Ao aprovar uma revisão privada em matéria já publicada ou agendada, o estado `'published'` ou `'scheduled'` é explicitamente preservado, prevenindo que o artigo seja acidentalmente retirado do ar.
- **P2: Detecção de Mídias Históricas em JSON e Vínculos Normalizados (`public/admin/media.php`, `core/ImageProcessor.php`, `core/PostService.php`):**
  - Verificação de uso de mídias em `post_revisions` compatível tanto com URLs diretas (`/uploads/...`) quanto com serializações legadas com barras invertidas escapadas (`\/uploads\/...`).
  - Implementação de `ImageProcessor::syncRefs()` para cadastrar vínculos normalizados na tabela relacional `media_refs` e adoção de `JSON_UNESCAPED_SLASHES` em todos os encoders de payloads de revisão.
- **Correção de Retorno no Roteador em PHP 8.4 (`core/Router.php`):**
  - Correção dos ramos de redirecionamento canônico (`/page/1/`, `/categoria/{slug}/page/1`, `/noticia/{slug}` e normalização de trailing slash) no método `Router::dispatch()`: substituição de instruções `return;` vazias por `return null;` explícito, cumprindo a assinatura estrita de tipo de retorno `mixed` do PHP 8.4 e eliminando o erro de compilação `Fatal error: A method with return type must return a value` que impedia a execução das rotas públicas.

### Otimizações de Banco de Dados & Performance (SQL)
- **Remoção de `p.content_html` das Consultas de Listagem (`public/index.php`, `templates/home.php`):**
  - Eliminação da transferência da coluna pesada `p.content_html` (`MEDIUMTEXT`) nas consultas SQL de feed da Home, paginações (`/page/{n}/`), categorias (`/categoria/{slug}/`), tags (`/tag/{tag}/`) e busca (`/busca`).
  - Otimização do cálculo de tempo de leitura no card de destaque principal utilizando `CHAR_LENGTH(p.content_html) AS content_len`, reduzindo o tráfego de memória entre MySQL/MariaDB e PHP em mais de 80% por requisição de listagem.
- **Índice FULLTEXT e Busca Instantânea em Larga Escala (`bin/schema.sql`, `core/Schema.php`, `public/index.php`, `public/admin/dashboard.php`):**
  - Criação do índice nativo InnoDB `FULLTEXT INDEX idx_fulltext_search (title, summary, tags)` na tabela `posts` com migração idempotente automática em `Schema::installMysql()`.
  - Implementação de busca de alta performance utilizando `MATCH(p.title, p.summary, p.tags) AGAINST(? IN BOOLEAN MODE)` com ordenação por relevância no portal público (`/busca`) e no filtro administrativo (`public/admin/dashboard.php`), com sanitização de operadores booleanos e fallback transparente para `LIKE` em SQLite ou termos curtos, reduzindo o tempo de consulta em 48.752+ artigos de ~150ms para < 2ms.
- **Sitemap Index Paginado para Cobertura de 100% dos Artigos (`public/index.php`):**
  - Substituição da rota com limite estático de 1.000 URLs por um `<sitemapindex>` padrão do Google no `/sitemap.xml`.
  - Divisão estruturada em `/sitemap-main.xml` (Home, Categorias e Páginas Institucionais) e partições de artigos `/sitemap-posts-{part}.xml` limitadas a 5.000 URLs por arquivo (ex: artigos 1 a 5.000, 5.001 a 10.000 etc.), garantindo indexação de 100% das mais de 48.750 matérias sem risco de estouro de memória, timeout de execução ou limite de 50.000 URLs por sitemap do Google.

### Segurança (Auditoria Completa & Hardening)
- **Mitigação Completa dos 9 Achados da Auditoria e Observações Operacionais (LP-01 a LP-09):**
  - **LP-01 (XSS no Modal de Mídia):** Migração da renderização de cartões e detalhes em `editor.php` e `settings.php` para construção nativa via DOM (`createElement`, `textContent`, `element.title` e validação de URL do thumbnail), eliminando injeção via `innerHTML`. Sanitização preventiva de `original_name` em `ImageProcessor.php`.
  - **LP-02 (Filhos de Links Inválidos):** Higienização recursiva dos nós descendentes antes do desembrulho de tags `<a>` sem `href` em `BlockCompiler::cleanDomNode`, impedindo que elementos perigosos (`<script>`, `<img onerror>`) escapem da sanitização.
  - **LP-03 (Escape Contextual de Atributo `alt`):** Tratamento rigoroso de atributo com `Security::e()` para `alt` em `BlockCompiler::compileImage`, neutralizando quebra de atributo e injeção de eventos via aspas literais.
  - **LP-04 (Blindagem de Script JSON-LD):** Serialização de JSON-LD com `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` em `core/Seo.php` e higienização de nome/biografia em `profile.php`, prevenindo que a sequência `</script>` encerre blocos de script prematuramente.
  - **LP-05 (Proteção Editorial de Conteúdo Público):** Bloqueio de alterações diretas na tabela `posts` por autores em matérias já publicadas ou agendadas em `editor.php`; alterações geram obrigatoriamente uma nova revisão privada (`pending_review`) e restringem campos sensíveis (`is_featured`, data de publicação).
  - **LP-06 (Autorização Estrita em Restauração de Revisões):** Restauração por autor em `revisions.php` preserva o status `'draft'` ou cria uma revisão privada pendente de aprovação, bloqueando promoção não autorizada para `'published'`. Validação adicional de `ACTION_PUBLISH_POST` no `PostService::publishOrUpdate`.
  - **LP-07 (Proteção de Artigos com Senha na Migração):** Seleção de `post_password` em `bin/migrate-wp.php`, forçando a conversão de artigos protegidos por senha para `draft` privado com log explicativo, prevenindo vazamento de dados confidenciais.
  - **LP-08 (Auditoria de Usuários e Privilégios na Migração):** Remoção de concessão de papéis baseada em nomes de login; novos usuários são criados estritamente como `author`, inativos (`is_active = 0`) e com `password_reset_required = 1`. Contas existentes nunca têm seus papéis sobrescritos.
  - **LP-09 (Invalidação Pós-Commit e Cabeçalho `X-Accel-Expires`):** Purga física de cache executada estritamente após o commit em `dashboard.php` e `PostService.php`, cobrindo tags, sitemap, feeds e paginações. Injeção do cabeçalho `X-Accel-Expires` em `public/index.php` (300s para listagens e feeds, 3600s para artigos e páginas).
  - **Observações Operacionais:**
    - Normalização canônica de IPv6 sem corte de sub-redes em `Security::hashIp()` e lock pessimista (`SELECT ... FOR UPDATE`) na deduplicação de visualizações em `view.php`.
    - Isolamento de redirecionamentos em `core/Router.php` com suporte a modo de teste (`setTestingMode`) e correção da suíte de testes em `bin/test.php` executando até o fim com relatório completo.
    - Verificação de referências de mídias na tabela `post_revisions` em `media.php` e isolamento de arquivos de staging por `media_id` em `ImageProcessor.php`.
- **Correção de Duplo Escape em URLs e Validação Rigorosa de `srcset` (`core/BlockCompiler.php`):**
  - Decodificação de entidades prévias (`html_entity_decode`) antes de passar valores sanitizados para `DOMElement::setAttribute`, evitando que query strings com `&` em links sofram escape duplo (`&amp;amp;`) na serialização nativa do `DOMDocument::saveHTML`.
  - Validação estrita de cada candidato de imagem em atributos `srcset` (em tags `<source>` e `<img>`), sanitizando URLs individualmente e validando descritores de largura e densidade (`w`/`x`).
- **Resolução Automática de Proxies Confiáveis em Toda a Aplicação (`core/Security.php`, `core/bootstrap.php`, `public/api/view.php`, `core/Logger.php`):**
  - `Security::getClientIp()` passa a ler automaticamente `$config['security']['trusted_proxies']` disponibilizado no bootstrap quando invocado sem argumentos explícitos, garantindo que `Logger::security()` e `public/api/view.php` reconheçam proxies configurados com consistência.
- **Sanitização de XSS Estruturada via DOMDocument (`core/BlockCompiler.php`):**
  - Substituição da limpeza baseada em expressões regulares vulnerável a atributos colados sem espaço (como `<img/src="x"/onerror=alert(1)>`) por parser estruturado com `\DOMDocument`.
  - Whitelist estrita de tags HTML permitidas e atributos autorizados por elemento (ex: `href`, `title`, `target`, `rel` para `<a>`; `src`, `alt`, `loading`, `decoding` para `<img>`), eliminando sumariamente eventos JavaScript (`on*`), atributos inline `style`, tags `<script>` e `<style>`.
  - Normalização e validação rígida de URLs em atributos (`sanitizeUrl()`), bloqueando esquemas perigosos (`javascript:`, `vbscript:`, `data:` para links) e referências protocolo-relativas (`//`).
- **Defesa em Profundidade nas Páginas Institucionais (`public/admin/page-edit.php`, `templates/page.php`):**
  - Sanitização via `BlockCompiler::sanitizeHtml()` tanto na persistência quanto na renderização de páginas institucionais para proteção absoluta contra XSS armazenado.
- **Prevenção contra Spoofing de IP e Ataques de Força Bruta (`core/Security.php`, `public/admin/login.php`):**
  - Validação rigorosa de proxies confiáveis em `Security::getClientIp()`: cabeçalhos `CF-Connecting-IP`, `X-Real-IP` e `X-Forwarded-For` agora só são aceitos quando a conexão remota direta (`REMOTE_ADDR`) for originada de proxies confiáveis configurados (faixas Cloudflare, localhost, loopback). Conexões diretas utilizam estritamente `REMOTE_ADDR`.
  - Rate limiting baseado no endereço IP real para proteção contra ataques de força bruta no login (`Security::checkLoginRateLimit()` e `Security::recordLoginFailure()`), bloqueando tentativas sucessivas mesmo que cookies de sessão sejam descartados pelo atacante.
- **Proteção CSRF e Bloqueio de Concorrência na Ativação de Usuários (`public/admin/activate.php`):**
  - Adição de token CSRF (`_csrf_token`) obrigatório e validado no formulário de ativação de conta e redefinição de senha.
  - Bloqueio pessimista de concorrência (`SELECT ... FOR UPDATE`) dentro de transação de banco de dados, prevenindo race conditions no consumo de tokens de ativação descartáveis.
- **Controle de Acesso RBAC Estrito e Proteção contra Exclusão Indevida (`public/admin/dashboard.php`, `public/admin/media.php`):**
  - Enforçamento de `Authorization::ACTION_DELETE_POST` para remoção definitiva de matérias, restringindo a operação estritamente a administradores conforme a matriz de privilégios.
  - Restrição de exclusão de mídias em `public/admin/media.php`: apenas administradores, editores ou o próprio autor que enviou o arquivo podem excluir.
  - Verificação de integridade referencial antes da exclusão de qualquer imagem: imagens em uso nas colunas `featured_image` ou `content_html` das tabelas `posts` ou `pages` têm sua remoção bloqueada com aviso orientativo.
- **Centralização do Controle de Acesso e Revogação Imediata de Sessões (`core/Security.php`, todos os scripts de `public/admin/`):**
  - Implementação do método unificado `Security::requireAuth(?string $requiredAction, ?array $requiredRoles, ?array $config): array` aplicado em todos os 15 endpoints administrativos e APIs internas (`categories.php`, `dashboard.php`, `editor.php`, `media.php`, `page-edit.php`, `pages.php`, `preview.php`, `profile.php`, `revisions.php`, `settings.php`, `stats.php`, `system.php`, `upload.php`, `users.php`, `api/media.php`).
  - Validação contínua e em tempo real do estado da conta (`is_active == 1`) e da versão da sessão (`session_version`), garantindo que usuários desativados ou com senhas redefinidas desloguem imediatamente em qualquer requisição normal ou AJAX.
- **Alerta e Geração Automática de Chaves Criptográficas Fortes (`public/admin/system.php`, `bin/diagnose.php`, `bin/setup.php`):**
  - Alerta visual em destaque no painel de diagnóstico (`public/admin/system.php`) e na CLI (`bin/diagnose.php`) quando `hmac_secret` mantiver o valor padrão ou tiver menos de 32 caracteres.
  - Geração automática de chave criptográfica aleatória de 64 caracteres (`bin2hex(random_bytes(32))`) durante a execução de `bin/setup.php` para criação segura do `config.local.php`.

### Adicionado
- **Pipeline de CSS Estático de Produção (Tailwind CSS Otimizado & Compilado):**
  - Implementação de compilação estática do Tailwind CSS para produção via Node.js / CLI, eliminando o Play CDN em tempo de execução (`cdn.tailwindcss.com`), zerando o Total Blocking Time (TBT) e garantindo compatibilidade estrita com Content Security Policy sem `unsafe-inline`.
  - Configuração oficial em `tailwind.config.js` com tokens do design system Kinetic Apex Editorial (`Space Grotesk`, `Work Sans`, cores `racing-red`, `carbon-black`, etc.) e varredura profunda de classes em `templates/`, `public/` e `core/`.
  - Ponto de entrada CSS em `src/input.css` contendo diretivas Tailwind e customizações globais de scrollbar para modos claro e escuro.
  - Folha de estilos compilada e minificada em `public/assets/style.css` (~29 KiB raw / ~5 a 6 KiB transferidos via gzip/brotli pelo Nginx).
  - Atualização do `templates/header.php` para carregar `public/assets/style.css` com cache-busting automático baseado em timestamp (`?v=filemtime`), mantendo fallback transparente para o Play CDN caso o arquivo não exista em desenvolvimento.
  - Adição de scripts de automação no `package.json`:
    - `npm run build:css`: compilação minificada de alta performance para produção.
    - `npm run watch:css`: monitoramento e recompilação em tempo real a cada salvamento em ambiente de desenvolvimento.

### Corrigido
- **Efeito Colateral de Timezone Global e Agendamento Preciso (`core/Settings.php`, `core/bootstrap.php`, `public/admin/editor.php`, `core/PostService.php`):**
  - Removido `@date_default_timezone_set` em `Settings::applyToConfig()`, assegurando que o PHP opere estritamente em **UTC** em todo o ciclo de vida da aplicação (`core/bootstrap.php`).
  - No `public/admin/editor.php`, o datetime-local recebido do formulário agora é convertido explicitamente do fuso local do site (`display_timezone`) para UTC antes de ser persistido no banco de dados, e reconvertido para exibição local ao renderizar o formulário.
  - No `core/PostService.php`, datas de publicação são formatadas estritamente em UTC (`gmdate`), sanando a defasagem de 3 horas em matérias agendadas.
  - Funções de data dos templates do front-end (`formatPtDate`, `formatPtFullDate`) atualizadas para exibir datas no fuso horário configurado do portal a partir do timestamp UTC do banco.
- **Chamadas de Métodos e Endpoints em Configurações (`public/admin/settings.php`):**
  - Corrigida a purga de cache após salvar configurações de `$cacheManager->purge()` para `$cacheManager->purgeUrls(['/', '/feed.xml', '/sitemap.xml'])`.
  - Corrigido o endpoint de upload de ícone/favicon no modal de mídia de `/admin/upload.php` para `/admin/media.php`, processando adequadamente o campo `media_file`.
- **Permissão de Exclusão de Artigos para Autores (`public/admin/dashboard.php`):**
  - Adicionado `p.user_id` na query de listagem e ajustada a verificação para `(int)$currentUser['id'] === (int)$p['user_id']`.
- **Sanitização e Purga de Cache de Páginas Institucionais (`public/admin/page-edit.php`, `public/admin/pages.php`):**
  - Adicionada sanitização com `BlockCompiler::sanitizeHtml($contentHtml)` antes do INSERT/UPDATE de páginas.
  - Implementada a purga automática de cache FastCGI via `CacheManager::purgeUrls()` ao salvar, editar ou excluir páginas institucionais.
- **URLs Canônicas na Raiz da API Pública (`public/api/posts.php`, `public/api/search.php`):**
  - Ajustadas as propriedades `url` nos retornos JSON de `/{slug}/` (raiz), removendo o prefixo legado `/noticia/`.
- **Chave de Configuração da URL Canônica (`templates/post.php`):**
  - Substituída a chave `$config['app']['url']` por `$config['app']['canonical_origin']` na linha 25.
- **Módulo Administrativo de Configurações Gerais do Site (`/admin/settings.php` e `core/Settings.php`):**
  - Nova interface no Painel Editorial exclusiva para administradores com suporte nativo a Modo Escuro/Claro.
  - Tabela `settings` criada automaticamente com persistência chave-valor (`setting_key`, `setting_value`), cache em memória por requisição e fallback seguro.
  - Campos gerenciáveis:
    - **Título do Site (Site Title):** Propagado automaticamente para `<title>`, feeds RSS, Open Graph, Twitter Cards e copyright.
    - **Descrição Editorial (Tagline / Meta Description):** Utilizada como meta description padrão na Home, feeds RSS, Schema.org e rodapé.
    - **Ícone do Site (Favicon):** Integração com a Biblioteca de Mídias via modal com busca em tempo real, upload direto de ícones (`.ico`, `.png`, `.svg`, `.webp`, `.avif`) e simulador ao vivo de aba de navegador (preview em 32x32 e 48x48 px).
    - **Fuso Horário (Timezone):** Seleção ampla agrupada com fusos recomendados (ex: `America/Sao_Paulo (UTC-3 - Brasília)`, `UTC (UTC+0)`, `Europe/London`, `America/New_York`) e escala completa fixa de **UTC-12** até **UTC+14**, com relógio em tempo real calculando a hora estimada no fuso escolhido.
  - Invalidação automática de cache FastCGI ao salvar para propagação instantânea das alterações aos visitantes.
- **Metatags Open Graph & Twitter Cards Dinâmicas com Favicon em WebP (`core/Seo.php`):**
  - Para a página **Home**, páginas de **Categorias** (`/categoria/{slug}/`) e páginas de **Tags** (`/tag/{slug}/`), as tags `og:image` e `twitter:image` passam a consumir dinamicamente o Favicon configurado no painel.
  - Conversão compulsória para o formato **`.webp`** através de `ImageProcessor::toWebpUrl()` e geração de URLs absolutas com o domínio canônico configurado.
- **Módulo Administrativo de Páginas Institucionais (`/admin/pages.php`, `/admin/page-edit.php`, `templates/page.php`):**
  - Tabela `pages` criada e gerenciada com campos `slug`, `title`, `content_html`, `seo_title`, `seo_description`, `status` (`published`, `draft`, `archived`) e `views`.
  - Painel com listagem, busca, contadores, filtro por status e ações rápidas (edição, link direto, exclusão com expurgo de cache).
  - Roteamento nativo na raiz `/{slug}/` (ex: `/nossa-equipe/`, `/contato/`, `/termos-de-uso/`, `/politica-de-privacidade/`, `/codigo-de-etica/`).
  - Auto-seeding e auto-migração garantindo que páginas institucionais padrão existam sem comandos SQL manuais.
- **Banners Oficiais de Parceiros em Toda a Rede:**
  - Atualizado o primeiro espaço de banner da barra lateral de todas as páginas públicas (`home.php`, `post.php`, `category.php`, `tag.php`, `page.php`, `search.php`) com a arte oficial `/uploads/2026/10/banner-vertical-qr-site.avif` utilizando elemento responsivo `<picture>` com fallback em `.webp`.
  - Preservado o segundo banner da matéria (`post.php`) para anúncios adicionais.
- **Biblioteca de Ícones Vetoriais SVG Inline Leve (`core/Icon.php`):**
  - Substituição da fonte externa pesada Material Symbols Outlined (+1 MB por carregamento) por biblioteca estática de SVGs embutidos, eliminando completamente dependências de fontes externas e acelerando a pontuação Core Web Vitals (LCP/CLS).
- **Layout Kinetic Apex Editorial nas Páginas de Categoria e Tag:**
  - Remoção do mosaico assimétrico com 4 caixas nas páginas de categoria e tag para exibição linear de todas as matérias no feed de 8 colunas com barra lateral editorial de 4 colunas.

### Corrigido
- **BUG Erro 500 no Rodapé (`templates/footer.php`):** Adicionada a importação `use LightPress\Core\Security;` no topo do arquivo `footer.php`, corrigindo erro fatal do PHP (`Class "Security" not found`) ao sanitizar a descrição dinâmica do site e assinatura de copyright.
- **Remoção de Logotipo Externo Quebrado no Header e Rodapé:** Removidas as tags `<img>` que apontavam para URL externa inacessível no Googleusercontent em `header.php` e `footer.php`, padronizando o logotipo do portal como marca tipográfica pura em CSS (`SPEEDRACING`).
- **BUG Ordenação no Gerenciador de Notícias (`admin/dashboard.php`):** Corrigida a consulta de listagem de matérias para ordenar estritamente por data de criação/publicação (`ORDER BY COALESCE(p.published_at, p.updated_at) DESC, p.id DESC`), garantindo que as matérias mais recentes apareçam no topo da lista.
- **BUG Undefined Method `Security::validateCsrfToken()`:** Corrigida a chamada de validação CSRF em `admin/settings.php` para `Security::verifyCsrfToken()` e criado alias `Security::validateCsrfToken()` em `core/Security.php` para compatibilidade total.
- **Sintaxe SQL Segura de Configurações (`core/Settings.php`):** Substituído `ON DUPLICATE KEY UPDATE` por `REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, NOW())` com tratamento de exceções, evitando incompatibilidades com palavras reservadas em versões estritas do MariaDB/MySQL.
- **BUG Erro 500 na Paginação da Home (`/page/2/`):** Inicialização defensiva de `$featuredPosts = []` nas rotas paginadas e vinculação estrita de tipo `PDO::PARAM_INT` para inteiros em `Database::query()` para parâmetros de `LIMIT` e `OFFSET`.
- **Permissões de Diretórios de Uploads (`core/ImageProcessor.php`):** Ajustadas permissões de criação de pastas para `0775` e arquivos para `0664`, garantindo permissão mútua de escrita e exclusão entre o processo web (`www-data`) e usuários FTP/SSH.
- **Restauração da Barra de Rolagem Nativa:** Remoção de regras CSS que ocultavam barras de rolagem globais do navegador.
- **Nomenclatura Limpa de Imagens (Sem Sufixo -1920) e Copiar URL estritamente .AVIF:**
  - A variante original/full de máxima resolução é gravada sem nenhum sufixo numérico (ex: `foto.avif` e `foto.webp`), preservando o nome original do upload. As variantes complementares mantêm `-1024` e `-thumb`.
  - Os botões de copiar URL na Biblioteca de Mídias (`/admin/media.php`) agora copiam estritamente a variante `.avif` (Original/Full, 1024px e Thumb).
- **Agendamento de Postagens para Data Futura (`status = 'scheduled'`):**
  - Adicionado suporte a agendamento automático com seletor `<input type="datetime-local">` no editor (`/admin/editor.php`).
  - Matérias com data de publicação futura recebem o status `scheduled` e botão dinâmico de agendamento.
  - No frontend (`public/index.php`), as consultas utilizam `WHERE status IN ('published', 'scheduled') AND published_at <= ?`, garantindo que o artigo entra no ar de forma instantânea e automática assim que o horário é atingido, sem atrasos.
  - No Dashboard (`/admin/dashboard.php`), incluído card de métricas com total de agendadas, filtro por status `Agendados` e badge visual indicando a data e hora do agendamento.
  - Criado script de migração `bin/migrate-scheduled.php` para atualizar o ENUM de status no MySQL.
- **Sistema de Tags Adicionais (Tópicos Semânticos):**
  - Implementado campo de tags separadas por vírgula no editor de notícias.
  - Na página do artigo (`templates/post.php`), as tags são exibidas como badges clicáveis `#tag` antes da caixa do autor.
  - Criada a rota de arquivo por tag (`/tag/{slug}/` e `/tag/{slug}/page/{page}/`) com template dedicado (`templates/tag.php`), paginação e contagem de matérias.
  - O mecanismo de busca pública (`/busca?q=...`) e a busca do painel agora também localizam matérias por suas tags.
- **Dark Mode Completo e Universal no Painel Administrativo:**
  - Implementado suporte a Modo Escuro instantâneo e sem flash em 100% das telas da redação: Gerenciador (`/admin/dashboard.php`), Biblioteca de Mídias (`/admin/media.php`), Diagnóstico de Infraestrutura (`/admin/system.php`), Configurações de Perfil (`/admin/profile.php`), Editor de Notícias (`/admin/editor.php`), Histórico de Revisões (`/admin/revisions.php`), Mais Lidas (`/admin/stats.php`), Categorias (`/admin/categories.php`) e Usuários (`/admin/users.php`).
  - Botão de alternância `#btnThemeToggle` integrado com persistência automática no `localStorage` (`lp_theme`), sincronizado de ponta a ponta com o tema do site público.
  - Exibição de miniaturas da galeria e do modal de mídias do editor padronizada estritamente para o formato `.avif` (com fallback transparente para `.webp`).
- **Consumo Padronizado de Miniaturas AVIF (`-thumb.avif`):**
  - Ajustado o método `ImageProcessor::toThumbUrl($url, 'avif')` para priorizar estritamente a variante `-thumb.avif` no HTML de renderização, mantendo a regra de ouro do LightPress (HTML sempre requisita AVIF, deixando WebP reservado exclusivamente para metadados sociais e fallback automático transparente no Nginx).
- **Consumo Otimizado da Variante Thumbnail (`-thumb.webp`):**
  - Implementado o método estático `ImageProcessor::toThumbUrl($url, 'webp')` para converter automaticamente URLs de qualquer resolução para a variante de corte 16:9 (`-thumb.webp`, 300x169px).
  - O painel administrativo (`/admin/dashboard.php` e `/admin/stats.php`) agora requisita estritamente o arquivo minúsculo `-thumb.webp` (~3 a 6 KiB) em vez do arquivo intermediário de 1024px, economizando banda e acelerando o carregamento da lista de publicações.
- **Thumbnails Visuais de Notícias no Painel Administrativo:**
  - Adicionadas miniaturas das imagens em destaque (`featured_image`) na listagem de publicações do **Gerenciador de Notícias (`/admin/dashboard.php`)** e no ranking do **Painel de Mais Lidas (`/admin/stats.php`)**.
  - As miniaturas contam com proporção 16:9, carregamento preguiçoso (`loading="lazy"`), link direto de clique para o editor e placeholder elegante (*"Sem foto"*) para matérias sem imagem atribuída.
- **Recursos Administrativos da Redação (Opção 2 - Gestão de Portal):**
  - **Dashboard Editorial Turbinado (`/admin/dashboard.php`):**
    - Cards de métricas operacionais em tempo real: Total de Matérias, Matérias Publicadas, Rascunhos/Revisões Pendentes e Total Acumulado de Visualizações.
    - Filtros dinâmicos múltiplos: Status (*Publicado, Rascunho, Em Revisão, Arquivado*), Categoria, Autor, e checkbox para Destaques Home (★).
    - Campo de busca textual rápida por título ou resumo.
    - Paginação rápida (25 itens por página) com preservação de parâmetros de busca e contagem eficiente.
    - Ações rápidas por artigo: Alternar destaque na Home (★) com invalidação imediata de cache, Edição, Link direto de visualização pública e Exclusão segura com expurgo de cache.
  - **Painel de Mais Lidas e Audiência Editorial (`/admin/stats.php`):**
    - Ranking Top 20 matérias mais lidas com base em visualizações atômicas deduplicadas.
    - Painel de desempenho e audiência agregada por Categoria (total de posts, total de views e média por post).
    - Desempenho e produtividade da equipe de redação por Autor.
  - **Gestão Completa de Categorias (`/admin/categories.php`):**
    - Criação e Edição de categorias com validação de unicidade de slugs.
    - Atualização transacional em cascata de matérias vinculadas caso o slug da categoria seja alterado.
    - Exclusão segura com migração automática de posts para a categoria padrão `geral` (sem deixar matérias órfãs).
    - Expurgo atômico de cache no Nginx da Home e das categorias alteradas.
  - **Gestão de Usuários e Autores (`/admin/users.php`):**
    - Cadastro e edição completa de membros da redação (repórteres, editores, colunistas e administradores).
    - Edição da Biografia do Autor e Avatar/Foto (exibidos na caixa de autor de cada matéria).
    - Emissão de link seguro de ativação criptográfico de uso único (32 bytes / 24h) ou definição direta de senha com Argon2id.
    - Incremento atômico de `session_version` em qualquer modificação ou bloqueio, revogando sessões ativas imediatamente.
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
- **Nomenclatura Limpa de Imagens (Sem Sufixo -1920) e Copiar URL estritamente .AVIF:**
  - A variante original/full de máxima resolução é gravada sem nenhum sufixo numérico (ex: `foto.avif` e `foto.webp`), preservando o nome original do upload. As variantes complementares mantêm `-1024` e `-thumb`.
  - Os botões de copiar URL na Biblioteca de Mídias (`/admin/media.php`) agora copiam estritamente a variante `.avif` (Original/Full, 1024px e Thumb).
- **Agendamento de Postagens para Data Futura (`status = 'scheduled'`):**
  - Adicionado suporte a agendamento automático com seletor `<input type="datetime-local">` no editor (`/admin/editor.php`).
  - Matérias com data de publicação futura recebem o status `scheduled` e botão dinâmico de agendamento.
  - No frontend (`public/index.php`), as consultas utilizam `WHERE status IN ('published', 'scheduled') AND published_at <= ?`, garantindo que o artigo entra no ar de forma instantânea e automática assim que o horário é atingido, sem atrasos.
  - No Dashboard (`/admin/dashboard.php`), incluído card de métricas com total de agendadas, filtro por status `Agendados` e badge visual indicando a data e hora do agendamento.
  - Criado script de migração `bin/migrate-scheduled.php` para atualizar o ENUM de status no MySQL.
- **Sistema de Tags Adicionais (Tópicos Semânticos):**
  - Implementado campo de tags separadas por vírgula no editor de notícias.
  - Na página do artigo (`templates/post.php`), as tags são exibidas como badges clicáveis `#tag` antes da caixa do autor.
  - Criada a rota de arquivo por tag (`/tag/{slug}/` e `/tag/{slug}/page/{page}/`) com template dedicado (`templates/tag.php`), paginação e contagem de matérias.
  - O mecanismo de busca pública (`/busca?q=...`) e a busca do painel agora também localizam matérias por suas tags.
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
