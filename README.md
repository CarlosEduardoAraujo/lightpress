# Especificação Técnica do Projeto LightPress / SpeedPress

---

## 1. Visão Geral e Filosofia

O **LightPress** é um sistema de gerenciamento de conteúdo (CMS) *open source*, minimalista e focado em altíssimo desempenho, segurança máxima e baixo consumo de recursos (CPU e RAM) para servidores web. 

O projeto foi concebido para atender portais de notícias e blogs de médio a grande porte (com acervos de 50.000+ publicações) que sofrem com o *bloatware*, acúmulo de plugins, modelo de dados fragmentado (EAV) e sobrecarga de CPU do ecossistema WordPress tradicional.

### Pilares Fundamentais:

1. **Zero Dependências Pesadas:** Desenvolvido em **PHP 8.4 puro**, sem frameworks lentos ou centenas de pacotes Composer no caminho crítico de execução pública.
2. **Modelo de Dados Plano e Rápido:** Tabelas relacionais diretas, eliminando consultas em cascata e junções caras com tabelas de metadados genéricos (`postmeta`).
3. **Desacoplamento de Conteúdo:** Separação entre a estrutura semântica para edição (`content_json`) e a versão compilada final para os leitores (`content_html`).
4. **Pipeline Nativo de Mídia de Próxima Geração:** Conversão automática para WebP obrigatório e AVIF opcional, com 3 resoluções padronizadas e descarte do original temporário somente após a conclusão segura do processamento.
5. **Integração Nativa com Cache de Disco:** Arquitetura desenhada para FastCGI Cache do Nginx em SSD, com invalidação após commit, retentativas persistentes e propagação verificada. Cache HTML em CDN exige integração adicional de invalidação.
6. **Experiência de Edição Familiar e Fluida:** Editor de blocos em JavaScript puro, sem React ou Redux, com orçamento total de até 50 KiB de JavaScript transferido com gzip, incluindo todos os módulos necessários à edição.
7. **Segurança Proativa por Padrão (Security by Design):** Aplicação estrita das melhores práticas defensivas atuais (OWASP Top 10, sanitização rigorosa de inputs/outputs, blindagem de uploads, headers de proteção modernos e autenticação resistente a força bruta).

---

## 2. Pilha Tecnológica e Infraestrutura

* **Linguagem:** PHP 8.4 (modo estrito `declare(strict_types=1);`, tipagem forte, classes `readonly`, `match` expressions e suporte nativo a JIT).
* **Servidor Web:** Nginx em versão estável mantida, com FastCGI Cache ativo em SSD/NVMe; a versão exata homologada deve constar no relatório de validação.
* **Bancos de Dados Suportados:**
  * **Fase 1 (Implementação Inicial):** MariaDB / MySQL via extensão `pdo_mysql`, em versões mantidas e homologadas; o DDL abaixo usa recursos presentes em MariaDB 10.6+ / MySQL 8.0+.
  * **Fase 2 (Portabilidade):** SQLite 3 via extensão `pdo_sqlite` com suporte a arquivo único.
* **Codificação de Imagens:** PHP GD nativo com leitura de JPEG/PNG/WebP e geração de WebP obrigatórias; leitura e geração de AVIF verificadas separadamente e opcionais.
* **Topologia Inicial:** Um servidor Linux com Nginx, PHP-FPM e banco de dados. Purga por arquivos e controles locais de concorrência pressupõem essa topologia; múltiplos nós exigirão coordenação adicional.
* **Datas:** Aplicação e conexão do banco operam em UTC. O painel converte datas para o fuso configurado do portal.

---

## 3. Modelo de Dados (Database Schema)

O banco de dados é inicializado com a codificação universal para suporte completo a caracteres de 4 bytes e emojis:

```sql
CREATE DATABASE lightpress_speedracing 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;
```

### 3.1. Tabela `users`

Armazena autores, editores e administradores do portal com hashing Argon2id e perfis fixos de acesso.

```sql
CREATE TABLE users (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('author', 'editor', 'admin') NOT NULL DEFAULT 'author',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    password_reset_required TINYINT(1) NOT NULL DEFAULT 0,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    bio TEXT NULL,
    avatar VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2. Tabela `posts`

Centraliza todo o conteúdo e metadados editoriais essenciais em um único registro indexado, eliminando dependências de tabelas EAV (`wp_postmeta`, `wp_terms`). As estatísticas de acesso ficam separadas na tabela `post_stats`, descrita na seção 3.3.

```sql
CREATE TABLE posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id SMALLINT UNSIGNED NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    summary VARCHAR(500) NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'geral',
    tags VARCHAR(255) NULL,
    featured_image VARCHAR(255) NULL,
    
    -- Conteúdo desacoplado
    content_json MEDIUMTEXT NOT NULL,
    content_html MEDIUMTEXT NOT NULL,
    
    -- Controle de publicação e exibição
    status ENUM('draft', 'pending_review', 'published', 'archived') NOT NULL DEFAULT 'draft',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    
    -- SEO Nativo Integrado (dispensa plugins de terceiros)
    seo_title VARCHAR(150) NULL,
    seo_description VARCHAR(255) NULL,
    focus_keyword VARCHAR(100) NULL,
    
    published_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Índices estratégicos
    INDEX idx_home (status, published_at DESC),
    INDEX idx_featured (is_featured, status, published_at DESC),
    INDEX idx_category (category, status, published_at DESC),
    INDEX idx_author (user_id),
    FULLTEXT INDEX idx_fulltext_search (title, summary, tags),
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Data de edição controlada pela aplicação:** `updated_at` representa a última alteração editorial aplicada ao registro em `posts` e não possui atualização automática por `ON UPDATE`. O valor inicial é definido na criação; depois disso, a aplicação atribui explicitamente `updated_at = CURRENT_TIMESTAMP` quando altera conteúdo ou metadados editoriais, incluindo controles de publicação e exibição. Em artigo publicado, salvar uma revisão pendente altera somente `post_revisions`; a data pública muda apenas quando essa revisão é aplicada. Salvar sem alterações efetivas, consultar o artigo ou registrar visualizações preserva essa data.

**Versão pública e visibilidade:** Para artigos publicados, `posts` contém o conteúdo e os metadados da versão aprovada. Leitores acessam somente registros com `status = 'published'` e `published_at IS NOT NULL AND published_at <= CURRENT_TIMESTAMP`. As mesmas condições se aplicam a listagens, feeds, sitemaps e contador. `version` é incrementado em cada alteração editorial efetiva de `posts` para detectar conflitos de gravação. Revisões pendentes ficam na tabela da seção 3.4.

### 3.3. Tabela `post_stats`

Armazena exclusivamente as estatísticas de acesso, com uma linha por artigo. Essa separação impede que cada visualização escreva na linha que contém o conteúdo editorial e permite consultar os artigos sem carregar seus contadores.

```sql
CREATE TABLE post_stats (
    post_id INT UNSIGNED PRIMARY KEY,
    views BIGINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_post_stats_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

* **Criação transacional:** Ao criar um artigo, a aplicação deve inserir sua linha em `posts` e executar `INSERT INTO post_stats (post_id) VALUES (?)` na mesma transação. Se qualquer inserção falhar, ambas devem ser desfeitas.
* **Incremento atômico:** O contador deve ser incrementado diretamente no banco, sem ler o valor para somá-lo no PHP, evitando perda de incrementos simultâneos.
* **Leitura sob demanda:** Consultas que não exibem visualizações acessam apenas `posts`. Quando necessário, o contador pode ser obtido pela chave primária `post_id` ou por uma junção explícita com `post_stats`.
* **Exclusão vinculada:** A exclusão definitiva de um artigo remove suas estatísticas por `ON DELETE CASCADE`.
* **Isolamento editorial:** Atualizações de estatísticas não alteram `posts.updated_at` e não provocam recompilação de conteúdo nem invalidação do cache editorial.

### 3.4. Tabela `post_revisions`

Guarda snapshots editoriais completos para recuperação e revisão. A leitura pública permanece uma consulta direta a `posts`.

```sql
CREATE TABLE post_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    editor_id SMALLINT UNSIGNED NOT NULL,
    base_version INT UNSIGNED NOT NULL,
    status ENUM('draft', 'pending_review', 'applied') NOT NULL DEFAULT 'draft',
    payload_json MEDIUMTEXT NOT NULL,
    content_html MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_revision_post (post_id, id),
    CONSTRAINT fk_revision_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_revision_editor FOREIGN KEY (editor_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

* **Agendamento Automático e Self-Healing de Schema:** O status `'scheduled'` permite programar matérias para publicação automática futura com suporte ao worker periódico (`bin/cleanup.php`). O serviço `PostService` inclui auto-migração DDL que detecta dinamicamente instalações legadas no MySQL e atualiza as colunas ENUM em tempo de execução para evitar erros de truncamento (MySQL Warning 1265).
* `payload_json` possui versão de esquema e snapshot dos campos editoriais: título, slug, resumo, categoria, tags, imagem, autoria, SEO, destaque e `content_json`. Campos controlados pelo servidor, como estatísticas e permissões, nunca são aceitos nesse payload.
* O HTML é gerado pelo `BlockCompiler` no servidor; nunca é aceito como HTML confiável enviado pelo navegador. As permissões sobre cada campo são verificadas antes da persistência e novamente antes da aplicação.
* Cada salvamento efetivo cria um snapshot; conteúdo de snapshots anteriores é preservado. A aplicação de uma revisão marca seu estado como `applied` na mesma transação que atualiza `posts`.
* `base_version` deve coincidir com `posts.version` ao aplicar a revisão. Divergência retorna HTTP 409 e exige comparação das alterações. Restaurar histórico cria uma nova revisão sobre a versão atual.
* Na gravação de revisões, o cliente envia o ID da última revisão conhecida. A aplicação bloqueia a linha do artigo durante a transação, compara esse ID com a revisão mais recente e rejeita gravações desatualizadas com HTTP 409, preservando o texto local para recuperação.
* O histórico anterior à primeira publicação ou atualização aprovada também é preservado. A criação do artigo, de `post_stats` e do snapshot inicial é transacional.

### 3.5. Tokens de Ativação e Redefinição

```sql
CREATE TABLE user_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id SMALLINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    purpose ENUM('activation', 'password_reset') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_user (user_id),
    INDEX idx_reset_expiry (expires_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

O token aleatório possui pelo menos 32 bytes; somente seu SHA-256 é armazenado. Seu uso e a troca de senha ocorrem na mesma transação, com bloqueio da linha e verificação de finalidade, validade e ausência de uso anterior. O fluxo completo está na seção 8.4.

### 3.6. Estruturas Operacionais Complementares

Além do DDL editorial acima, a implementação deve criar estruturas persistentes para os fluxos descritos neste documento. Seus contratos mínimos são:

| Estrutura | Campos e invariantes |
| :--- | :--- |
| `categories` | Slug único e estável, nome de exibição e estado ativo; categoria inicial `geral`. Validar a existência da categoria ao salvar artigos e revisões |
| `media` | Identificador aleatório único, proprietário, manifesto de variantes validado no servidor, datas e estado de finalização; cliente não pode substituir o manifesto por URLs arbitrárias |
| `media_refs` | Vínculos de mídia a artigos/revisões com unicidade por referência; impedir limpeza de mídia ainda referenciada pelo conteúdo ou histórico |
| `view_dedupe` | Chave primária `(post_id, ip_hash)`, prazo `expires_at`, índice por expiração e exclusão em cascata com o artigo; HMAC representado por 64 caracteres hexadecimais |
| `cache_purge_jobs` | ID, payload versionado com URLs/evento, estado, tentativas, próxima execução, lease, último erro e data de conclusão; inserção na transação editorial e ausência de exclusão em cascata com o artigo |
| `migration_map` | Chave única `(source, entity_type, source_id)`, ID de destino, checksum e estado; a origem é identificador configurado e estável |
| `redirects` | Caminho antigo, hash indexável único do caminho e destino interno canônico; conferir o caminho integral em caso de colisão de hash; impedir ciclos e destinos externos |
| `pages` | Páginas institucionais isoladas (`slug`, `title`, `content_html`, `featured_image`, `seo_title`, `seo_description`, `status`, `views`). Permite gerenciar páginas estáticas (ex: Equipe, Contato, Termos) servidas diretamente na raiz `/{slug}/` |
| `settings` | Configurações dinâmicas chave-valor (`key`, `value`, `updated_at`). Armazena Título do Site, Descrição (Tagline), Favicon e Fuso Horário propagados para todo o ecossistema |

Campos JSON dessas estruturas usam esquema versionado e validação na aplicação, sem depender de diferenças de implementação do tipo JSON entre bancos. Tarefas de limpeza possuem limites por lote e só removem registros expirados ou órfãos comprovados; jobs pendentes, snapshots e mídia referenciada não expiram por simples idade. A concessão de permissões e a marcação de uma revisão como aplicada não são feitas por jobs de limpeza.

---

## 4. Pipeline de Processamento de Mídia

Toda imagem enviada ao sistema passa por um fluxo determinístico de validação rigorosa, conversão e compressão executado pelo PHP GD nativo. O objetivo é eliminar o consumo desnecessário de armazenamento em disco mantendo carregamentos ultra velozes e segurança estrita contra arquivos maliciosos.

### 4.1. Regras de Entrada e Detecção de Formato

O instalador verifica `gd_info()`, as funções de leitura e escrita necessárias e realiza uma conversão de teste. WebP é requisito obrigatório; AVIF é uma otimização opcional. A presença de uma função não substitui a validação do arquivo efetivamente produzido.

| Entrada / capacidade | Comportamento obrigatório |
| :--- | :--- |
| JPG, PNG ou WebP decodificável | Gerar WebP e tentar AVIF quando o encoder estiver habilitado |
| AVIF com decoder funcional | Decodificar, gerar WebP e tentar AVIF quando disponível |
| AVIF sem decoder funcional | Rejeitar com HTTP 415 e mensagem clara; não prometer conversão para WebP |
| Ausência de geração WebP | Impedir uploads e apontar requisito ausente no diagnóstico do ambiente |
| Encoder AVIF ausente ou com falha | Concluir com WebP válido e registrar aviso, sem referências a AVIF inexistentes |

Na classe `ImageProcessor.php`, a leitura AVIF verifica `function_exists('imagecreatefromavif')`, e a escrita verifica `function_exists('imageavif')`, além das capacidades informadas pelo GD. A decodificação deve ser bem-sucedida mesmo quando MIME e assinatura forem reconhecidos.

### 4.2. Matriz de Resoluções Finais (Até 6 arquivos por imagem)

| Versão | Largura Máx. | Altura | Proporção / Tratamento | Formatos Gerados |
| :--- | :--- | :--- | :--- | :--- |
| **Full / Destaque** | 1920px | Auto | Proporcional (sem corte) | `.webp`, `.avif` (opcional) |
| **Intermediário** | 1024px | Auto | Proporcional (sem corte) | `.webp`, `.avif` (opcional) |
| **Thumbnail** | 300px | 169px | Corte fixo 16:9 centralizado | `.webp`, `.avif` (opcional) |

### 4.3. Configurações de Qualidade, Velocidade e Otimização Inteligente (PHP GD)

* **WebP:** Qualidade 82% (`imagewebp($resource, $path, 82)`).
* **AVIF:** Qualidade 65 com velocidade 8 (`imageavif($resource, $path, 65, 8)`). Essa calibração representa o *sweet spot* da indústria: reduz o tempo de codificação na CPU em até 70% frente à velocidade 6, mantendo arquivos 30% a 50% menores que o WebP com fidelidade visual quase sem perdas.
* **Pass-Through de Formatos Pré-Comprimidos:** Se o usuário envia uma imagem que já está no formato WebP ou AVIF (ex: gerada por IA ou comprimida pelo Squoosh) e a recodificação em qualidade fixa gerar um arquivo mais pesado que a origem, o arquivo original otimizado é preservado diretamente para a variante Full HD, evitando inchaço desnecessário de armazenamento e banda.
* **Elevação Dinâmica de Limites para Alta Resolução:** Durante a manipulação de imagens grandes pelo `ImageProcessor.php`, o sistema eleva temporariamente o `memory_limit` para 512M e o `max_execution_time` para 120s, permitindo processar bitmaps de alta fidelidade sem esgotamento de memória no servidor.

### 4.4. Segurança e Nomenclatura Semântica para SEO

* **Validação Real de MIME Type:** Nunca confiar na extensão informada no cabeçalho `$_FILES`. Validar via `finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath)` e verificação de *magic bytes*. Suporte explícito a `image/jpeg`, `image/png`, `image/webp` e `image/avif`.
* **Re-encoding Completo:** Decodificar e redesenhar a imagem em um novo recurso GD, sem copiar metadados desnecessários. Isso reduz a exposição a conteúdo embutido em metadados; não garante eliminar esteganografia nem substitui atualização das bibliotecas e bloqueio de execução de uploads.
* **Nomenclatura Semântica Amigável para SEO:** Ao invés de hashes criptográficos aleatórios que prejudicam o ranqueamento no Google Imagens, os arquivos finais preservam com fidelidade o nome original sanitizado (slug) enviado pelo usuário (ex: `dodge-retorna-nascar-cup-series-2027-kaulig-racing-1024.webp`). Colisões de nomes no mesmo diretório mensal são resolvidas automaticamente com incrementos numéricos inteligentes (`-1`, `-2`).
* **Métricas Transparentes de Compressão:** O manifesto de mídias armazena os tamanhos em bytes de cada variante gerada. O painel administrativo exibe claramente nos cards:
  - Formato e peso do arquivo original (ex: `6400 KiB • 1920x1072px - PNG`)
  - Formato, peso e porcentagem de redução alcançada (ex: `129 KiB • 1920x1072px - AVIF - 98% (redução do tamanho)`)
  - Identificação de mídias já minimalistas (`- Formato original otimizado`)
* **Limites de Entrada:** Até 8 MiB por arquivo (configurável até 12-16 MiB no PHP-FPM / `bin/serve.php`), 12 milhões de pixels e 8.000 pixels por lado, verificados antes de decodificar. Uploads assíncronos via AJAX com feedback de progresso e tratamento explícito de estouro de `upload_max_filesize` e `post_max_size`.

### 4.5. Finalização Segura e Manifesto de Variantes

1. Receber o original em área temporária privada, fora de `/public`, e gerar as variantes em staging no mesmo filesystem do destino.
2. Validar todas as variantes WebP obrigatórias: arquivo não vazio, formato esperado, dimensões e decodificação válidos. Falha nessa etapa impede o sucesso do upload.
3. Tentar AVIF quando disponível e validar cada arquivo gerado. O bloco de codificação AVIF é isolado em `try/catch` não bloqueante; falhas em encoders ausentes preservam com sucesso o conjunto WebP válido.
4. Mover somente variantes válidas por renomeação e liberar a referência de mídia apenas quando o conjunto WebP estiver completo. Falha na finalização remove os resultados parciais antes de retornar erro.
5. Persistir em `media` e retornar um manifesto com as URLs, formatos, larguras, alturas e tamanhos em bytes efetivamente gerados. O compilador consulta o manifesto confiável no servidor para montar `<picture>` e `srcset`; nunca deduz a existência de AVIF pela extensão de outra URL nem confia em um manifesto reenviado pelo cliente.
6. Remover o original temporário após a finalização segura.

Imagens menores não são ampliadas. As variantes proporcionais usam a menor largura entre a origem e o limite; o thumbnail faz corte central 16:9 sem ampliação, registrando suas dimensões reais. Corrigir orientação EXIF antes de descartar metadados e preservar transparência quando aplicável.

### 4.6. Estrutura de Arquivos em Disco

```text
/public/uploads/{ano}/{mes}/
  ├── {slug-da-imagem}-1920.avif (se habilitado)
  ├── {slug-da-imagem}-1920.webp
  ├── {slug-da-imagem}-1024.avif (se habilitado)
  ├── {slug-da-imagem}-1024.webp
  ├── {slug-da-imagem}-thumb.avif (se habilitado)
  └── {slug-da-imagem}-thumb.webp
```

### 4.7. Catalogação em Lote de Acervos Massivos (`bin/catalog-media.php`)

Para instalações com grandes volumes de imagens pré-existentes (ex: acervos migrados com mais de 30.000 fotos já convertidas em WebP e AVIF), o LightPress disponibiliza um utilitário CLI de alta performance para catalogação imediata no banco de dados sem recodificação gráfica:

* **Zero Consumo de CPU/GD:** Não descompacta nem recodifica bitmaps. As dimensões e tipos MIME são lidos diretamente dos primeiros bytes do arquivo via `@getimagesize()` em microssegundos.
* **Agrupamento Inteligente de Variantes:** Varre recursivamente `/public/uploads/{ano}/{mes}/`, identifica sufixos (`-1920`, `-1024`, `-thumb` e dimensões legadas como `-300x169`) e agrupa todas as extensões em uma única entidade de mídia com manifesto `variants_manifest` JSON.
* **Vínculos Automáticos (`media_refs`):** Pré-carrega as imagens em uso nas matérias (`posts.featured_image`) em um mapa de hash em memória com complexidade O(1), vinculando instantaneamente cada imagem ao seu respectivo artigo.
* **Transações em Lotes ACID:** Executa a gravação em lotes configuráveis (padrão `--chunk=500`), garantindo commits rápidos e permitindo interrupção/retomada segura a qualquer momento sem duplicatas.

```bash
# Simulação sem gravação no banco (Dry-Run)
php bin/catalog-media.php --dry-run

# Execução real em produção em lotes de 500 itens
php bin/catalog-media.php --chunk=500
```

### 4.8. Biblioteca de Mídias Reativa e Busca em Tempo Real (`public/admin/media.php`)

A interface da Biblioteca de Mídias foi projetada para lidar de forma ultra rápida com acervos de 30.000+ fotos:

* **Busca em Tempo Real com Debounce (300ms):** Conforme o usuário digita no campo de busca, o painel realiza a consulta assíncrona ao servidor e atualiza a grade de fotos, contadores e paginação sem piscar ou recarregar a página inteira.
* **Paginação Fluida Assíncrona:** Grade configurada para 48 itens por página (harmônica para layouts responsivos de 2, 3, 4 e 6 colunas) com navegação numerada instantânea via AJAX.
* **Ordenação Flexível:** Seletor de ordenação permitindo alternar entre ordem cronológica original (antigas primeiro - padrão) e ordem decrescente (recentes primeiro).
* **Busca Global Integrada no Editor de Notícias (`public/admin/editor.php`):** O modal de seleção de fotos no editor de matérias consulta a mesma API assíncrona (`/admin/api/media.php?q=...`), permitindo que repórteres e editores pesquisem instantaneamente qualquer foto do acervo completo enquanto escrevem.

---

## 5. Arquitetura do Sistema e Estrutura de Pastas

A raiz pública do servidor aponta exclusivamente para a pasta `/public`, isolando regras de negócio, bibliotecas do core e credenciais de acesso.

```text
lightpress/
├── config.php                 # Parâmetros de banco de dados e ambiente (chmod 600/640)
├── core/                      # Core minimalista do CMS (Zero dependências Composer)
│   ├── Database.php           # Camada de conexão PDO unificada e segura
│   ├── Router.php             # Roteador regex leve de alta velocidade
│   ├── ImageProcessor.php     # Algoritmo GD de corte, conversão e fallback
│   ├── BlockCompiler.php      # Compilador JSON -> HTML semântico com sanitização
│   ├── CacheManager.php       # Invalidador nativo de cache em disco Nginx e orquestrador
│   ├── CloudflareCache.php    # Invalidação seletiva de cache de borda via Cloudflare API v4
│   ├── Authorization.php      # Perfis, ações e autorização por artigo/campo
│   ├── PostService.php        # Revisões, conflitos e publicação transacional
│   ├── Security.php           # Utilitários de CSRF, sanitização e rate-limiting
│   ├── Seo.php                # Metatags Open Graph, Twitter Cards e Schema.org JSON-LD
│   ├── Settings.php           # Gerenciador central de configurações dinâmicas do portal
│   ├── Icon.php               # Biblioteca estática de ícones vetoriais SVG inline ultra-leves
│   ├── Hook.php               # Sistema ultraleve de Actions e Filters (Zero Dependências)
│   ├── PluginManager.php      # Gerenciador central de plugins e ciclo de vida
│   └── AdminNav.php           # Menu superior unificado do painel administrativo com submenu de plugins
├── plugins/                   # Plugins isolados fora da raiz pública
│   └── webhook-sender/        # Plugin nativo para envio de webhooks em publicações
├── storage/                   # Dados privados; acesso web proibido
│   ├── staging/               # Originais temporários e conversões em andamento
│   ├── migration/             # Backups de origem, checkpoints e relatórios
│   └── logs/                  # Logs técnicos sem senhas ou tokens em claro
├── templates/                 # Views em PHP nativo com escape contextual explícito
│   ├── header.php             # Cabeçalho global, navegação e metatags
│   ├── footer.php             # Rodapé institucional com links dinâmicos e copyright
│   ├── home.php               # Grid inicial (Destaques + Feed de notícias)
│   ├── post.php               # Notícia individual com schema SEO e banners
│   ├── category.php           # Listagem editorial de categorias com sidebar
│   ├── tag.php                # Listagem editorial por tópico/tag
│   ├── page.php               # Páginas institucionais isoladas (Equipe, Contato, etc.)
│   ├── search.php             # Resultados de busca pública
│   ├── 404.php                # Página de erro 404 customizada
│   └── 500.php                # Página de erro 500 elegante sem vazamento de dados
├── bin/                       # Ferramentas CLI
│   ├── catalog-media.php      # Catalogador em lote para acervos massivos de uploads
│   ├── migrate-scheduled.php  # Migração idempotente de ENUM para agendamentos
│   ├── migrate-wp.php         # Importador em lotes, retomável e auditável
│   ├── retry-cache-purge.php  # Retentativa de purgas persistidas
│   ├── purge-cache.php        # Utilitário CLI para purga seletiva/total (Cloudflare e Nginx)
│   └── cleanup.php            # Expiração de tokens, deduplicação e staging
└── public/                    # Web Root (Nginx / Servidor Web)
    ├── index.php              # Ponto único de entrada (Front Controller)
    ├── admin/                 # Painel administrativo enxuto protegido (< 50 KiB)
    │   ├── login.php          # Autenticação com proteção contra força bruta
    │   ├── dashboard.php      # Gestão de publicações ordenada por data de criação
    │   ├── editor.php         # Editor visual de blocos com suporte a copiar/colar Word/Docs
    │   ├── pages.php          # Módulo de Páginas Institucionais (Equipe, Contato, etc.)
    │   ├── page-edit.php      # Editor de Páginas Institucionais
    │   ├── settings.php       # Configurações do Site (Título, Tagline, Favicon, Timezone)
    │   ├── media.php          # Catálogo e upload de imagens (AVIF/WebP)
    │   ├── categories.php     # Gestão completa de categorias
    │   ├── users.php          # Gestão de usuários, autores e links de ativação
    │   ├── stats.php          # Painel de Mais Lidas e métricas de audiência
    │   ├── profile.php        # Perfil do usuário e alteração de senha
    │   └── system.php         # Diagnóstico de integridade, PHP-GD, OPcache e permissões
    ├── api/                   # Micro-endpoints públicos de baixa latência
    │   ├── view.php           # Incremento assíncrono de visualizações
    │   ├── search.php         # Autocomplete e busca instantânea
    │   └── posts.php          # Leitura pública de matérias (Headless)
    ├── assets/                # JS puro do editor e folhas de estilo CSS
    └── uploads/               # Diretório estático público (execução de scripts bloqueada)
```

---

## 6. Fluxo de Edição, Renderização e Métricas

### 6.1. Ciclo de Vida do Conteúdo e Editor Visual de Notícias

1. **Redação e Editor de Redação Contínua (`public/admin/editor.php`):**
   - **Canvas Visual Direto:** Elimina o atrito de blocos manuais rígidos. Jornalistas escrevem de forma contínua em um canvas visual rico (`contenteditable`), formatando títulos, parágrafos, listas e citações com atalhos e barra de ferramentas sticky.
   - **Limpador Inteligente de Ctrl+V (Google Docs e Microsoft Word):** Ao colar matérias prontas redigidas externamente, o sistema intercepta o evento `paste` e sanitiza instantaneamente o HTML:
     - Remove estilos sujos (`style="..."`), classes proprietárias do Office (`MsoNormal`), metadados, scripts, comentários condicionais (`<!--[if ...]>`) e tags legadas (`<o:p>`, `<xml>`).
     - Desembrulha automaticamente os wrappers do Google Docs (`<b id="docs-internal-guid-...">`) evitando que a matéria inteira fique em negrito.
     - Converte e preserva apenas tags semânticas limpas: `<h2>`, `<h3>`, `<p>`, `<strong>`, `<em>`, `<u>`, `<blockquote>`, `<ul>`, `<ol>`, `<li>` e `<a>` (com `rel="noopener noreferrer"`).
   - **Barra de Ferramentas Rica e Leve (< 20 KiB Vanilla JS):** Formatação rápida (H2, H3, P, B, I, U, Listas, Citação, Link, Inserir Foto e alternância de código-fonte `</> HTML`).

2. **Seletor de Mídias em Modal (Estilo WordPress):**
   - **Imagem de Destaque:** Botão `[📷 Selecionar da Biblioteca]` abre um modal overlay completo. Exibe miniaturas, dimensões, peso e busca em tempo real por nome.
   - **Upload Integrado:** Permite enviar novas fotos do computador direto pelo modal sem sair da redação.
   - **Prévia e Remoção:** Ao confirmar, exibe prévia em proporção 16:9 no formulário com botão de remoção rápida `[✕ Remover Imagem]`.
   - **Inserção no Corpo da Notícia:** O botão `[📷 Inserir Foto]` da barra de ferramentas insere a imagem selecionada com `<figure>`, `<img>` responsiva e `<figcaption>`.

3. **Gerenciamento de Slug / URL Permanente:**
   - Campo dedicado **Slug / URL Permanente** exibido logo abaixo do título com prévia em tempo real (`/noticia/slug/`).
   - Sincronização automática enquanto o jornalista digita o título (com conversão para minúsculas, remoção de acentos e caracteres especiais).
   - Botão `Auto 🪄` para regeneração a qualquer momento e permissão para digitação manual livre.
   - **Resolução Automática de Colisões no Core (`PostService.php`):** Caso um slug já exista no banco para outro post, o sistema adiciona automaticamente sufixos numéricos (`-2`, `-3`), evitando falhas de chave duplicada.

4. **SEO Nativo com Primary Focus Keyword:**
   - Campo dedicado para **Palavra-chave Principal (Focus Keyword)** para orientar a estratégia de ranqueamento nos motores de busca, além do Título SEO (`seo_title`) e Meta Description (`seo_description`).

5. **Validação, Sanitização e Compilação:**
   - Autenticar, autorizar ação e campos, verificar CSRF e sanitizar o HTML no servidor via `BlockCompiler::sanitizeHtml`.
   - O `BlockCompiler::htmlToBlocks` converte o HTML semântico sanitizado em estrutura de blocos JSON (`content_json`) para consumo de APIs headless e aplicativos móveis.

6. **Salvar Rascunho ou Publicar:**
   - Criar snapshot em `post_revisions` com JSON e HTML compilado na mesma transação. Em conteúdo ainda não publicado, atualizar também `posts` com versão e data editorial explícitas.
   - Somente editor ou administrador pode publicar. Conflitos de edição concorrente são detectados via locking otimista (`version`).
   - Após o commit, dispara a invalidação atômica do cache FastCGI das URLs afetadas (Home, Notícia e Categorias).

### 6.2. Contabilização de Visualizações (`post_stats.views`) com Cache Nginx Ativo

Como o Nginx entrega a página do cache estático sem invocar o interpretador PHP, a contagem de visualizações não pode depender da renderização síncrona da página:

1. **Rota Assíncrona Leve (`POST /api/view/{id}`):**
   * Usar uma única rota canônica (`POST /api/view/{id}`), sem iniciar sessão, emitir cookie ou usar privilégios da conta eventualmente autenticada. O endpoint fica fora do cache e retorna `Cache-Control: no-store`.
   * Validar método, ID inteiro positivo, origem conforme a seção 8.3 e visibilidade pública atual do artigo. IDs inexistentes, rascunhos e arquivados não recebem incrementos e retornam a mesma resposta 404.
   * Depois do controle de abuso, executar incremento atômico via PDO: `UPDATE post_stats SET views = views + 1 WHERE post_id = ?`. A verificação de visibilidade, a deduplicação e o incremento devem participar da mesma transação; adquirir bloqueio compartilhado na linha do artigo, permitindo contadores concorrentes enquanto serializa a operação com a gravação exclusiva de uma despublicação.
   * Essa operação não escreve em `posts`, não altera a data de edição e não dispara a invalidação do cache editorial. Uma linha de estatísticas ausente para um artigo existente deve ser registrada como falha de integridade, sem reportar o incremento como concluído.
2. **Disparo pelo Frontend:**
   * O template inclui um pequeno script em JS que utiliza `navigator.sendBeacon('/api/view/' + postId)` ou `fetch('/api/view/' + postId, { method: 'POST', keepalive: true })`.
   * Executar o script externo uma vez após o carregamento da página visível, sem aguardar sua resposta para liberar a interface. O impacto sobre LCP deve ser medido; a execução assíncrona não garante impacto zero. Falhas do contador não impedem a leitura.
3. **Controle de Abuso / Rate Limiting:**
   * Aplicar limite geral no Nginx por IP, inicialmente 60 requisições por minuto com burst 10, e aceitar no máximo um incremento por IP e artigo a cada 30 segundos na aplicação. Requisições duplicadas retornam 204 sem novo incremento; excesso do limite geral retorna 429.
   * Persistir a deduplicação em `view_dedupe`, com chave primária composta `(post_id, ip_hash)` e `expires_at`. A aquisição/renovação da janela é atômica e transacional junto com o incremento; uma leitura seguida de escrita sem bloqueio não é suficiente. Limpar registros expirados periodicamente.
   * Usar HMAC-SHA-256 do IP normalizado com segredo do servidor para `ip_hash`, sem guardar o IP em claro nessa tabela. A expiração lógica ocorre após 30 segundos e a limpeza física deve ocorrer em até 24 horas. Cabeçalhos de IP encaminhado só são aceitos de proxies configurados como confiáveis.
   * As visualizações são estimativas: bloqueadores, IPs compartilhados e robôs afetam o resultado. Validação de origem bloqueia certos abusos de navegador, mas não autentica robôs que forjam cabeçalhos.
4. **Alternativa para Tráfego Massivo:**
   * Em portais com dezenas de milhões de pageviews/dia, o endpoint pode gravar em um buffer Redis ou as visualizações podem ser agregadas periodicamente via análise de logs do Nginx (`access.log`). A consolidação desses incrementos deve atualizar exclusivamente `post_stats.views`.

### 6.3. Critérios de Aceitação para Datas Editoriais e Estatísticas

* Criar um artigo deve criar exatamente uma linha correspondente em `post_stats`, com zero visualizações; uma falha nessa inicialização deve desfazer a criação do artigo.
* Incrementos de visualizações, inclusive simultâneos, devem preservar a soma dos incrementos aceitos e manter `posts.updated_at` inalterado.
* Uma alteração editorial efetiva aplicada a `posts` deve atualizar `updated_at` explicitamente, sem modificar o contador; salvar revisão de artigo publicado ou salvar sem alterações deve preservar a data pública.
* Excluir definitivamente um artigo deve remover sua linha de estatísticas automaticamente.

### 6.4. Estados e Ações Editoriais

| Estado de `posts` | Visibilidade e comportamento |
| :--- | :--- |
| `draft` | Estado inicial; privado; `published_at` é NULL antes da primeira publicação |
| `pending_review` | Artigo ainda não publicado enviado para revisão; privado |
| `published` | Versão aprovada pública; edições seguintes ficam em revisões privadas |
| `archived` | Retirado de circulação; preserva conteúdo, histórico e eventual data da primeira publicação |

* Ações explícitas: **Salvar rascunho**, **Enviar para revisão**, **Publicar**, **Atualizar publicação** e **Arquivar**. Autor envia seus textos; editor/administrador aprova e publica. Devolver para ajustes retorna o rascunho ou a revisão pendente ao estado `draft`.
* Em artigo publicado, o envio para revisão altera o estado de `post_revisions`, mantendo `posts.status = 'published'`. O estado privado da revisão não substitui o estado da versão pública.
* Restaurar artigo arquivado para trabalho editorial o leva a `draft`; republicar requer editor/administrador. Exclusão definitiva é administrativa e sujeita à política de backup.
* Prévia autenticada usa a revisão autorizada em rota privada, com `Cache-Control: private, no-store`. Nunca compartilhar uma URL pública como mecanismo de autorização de prévia.
* Agendamento fica para fase posterior: datas futuras não são publicação agendada na primeira fase. Publicar exige data não futura; uma futura extensão terá estado e executor periódico próprios.

### 6.5. Categorias, Tags e Escopo Inicial

Uma categoria principal por artigo na primeira fase. Seu nome e slug são definidos em catálogo controlado por editor/administrador, mantido fora das requisições públicas de escrita; não gerar a identidade de uma categoria livremente a cada salvamento. O campo `category` guarda o slug canônico indexável, com padrão `geral`; o catálogo fornece o nome de exibição. Mudanças de slug exigem atualização dos artigos afetados, redirecionamentos e invalidação correspondente.

`tags` contém uma lista normalizada separada por vírgulas, sem vírgulas dentro de cada tag, com limite total de 255 caracteres. Essa representação serve à exibição; pesquisa e arquivos públicos por tag ficam fora da primeira fase e exigirão modelagem relacional própria se adicionados. Busca interna também fica fora do escopo inicial até definir requisitos e índices. Essa decisão evita usar `LIKE '%tag%'` como estratégia de escala.

---

## 7. Estratégia de Cache, Invalidação e Permissões (Nginx FastCGI)

A entrega de uma página pública anônima já armazenada não executa a aplicação. O cache é permitido somente para rotas e respostas explicitamente elegíveis; login e APIs nunca dependem dele.

### 7.1. Elegibilidade, Rotas e Chave Canônica

Rotas públicas iniciais: `/`, `/page/{n}/`, `/noticia/{slug}/`, `/categoria/{slug}/`, `/categoria/{slug}/page/{n}/`, `/feed.xml`, `/sitemap.xml`, `/sitemap-main.xml` e `/sitemap-posts-{part}.xml`. A paginação inicia em 2; página 1 redireciona para a listagem principal. O `/sitemap.xml` gera um `<sitemapindex>` padrão particionado (5.000 URLs por arquivo em `/sitemap-posts-{part}.xml` e páginas/categorias em `/sitemap-main.xml`), suportando a indexação de mais de 50.000 artigos sem sobrecarga de memória ou timeout. Slugs são validados e únicos no seu escopo. Host, HTTPS e política de barra final são canônicos e aplicados antes do cache. URLs legadas são tratadas pela migração, sem mudar silenciosamente os links antigos.

| Condição | Política |
| :--- | :--- |
| GET/HEAD anônimo, rota pública permitida, sem query string e resposta 200 | Pode ler e gravar cache |
| `/admin/`, login, logout, ativação, redefinição, prévias e `/api/` | Nunca ler nem gravar cache |
| Cookie de sessão `LIGHTPRESSSESSID`, mesmo inválido, ou cabeçalho `Authorization` | Nunca ler nem gravar cache |
| Método diferente de GET/HEAD, query string ou rota não prevista | Nunca ler nem gravar cache |
| Resposta com `Set-Cookie`, indicação `private`/`no-store`/`no-cache` ou status diferente de 200 | Nunca gravar cache |

Requisições públicas elegíveis não iniciam sessão e não emitem cookies. Parâmetros de rastreamento também causam bypass inicialmente; sua eventual normalização precisa de política explícita, sem ignorar parâmetros que mudem o conteúdo. Apenas detectar uma sessão no PHP é insuficiente: as exclusões de requisição precisam ser avaliadas pelo Nginx antes de consultar o cache.

Trecho de referência para a chave (não é um virtual host completo):

```nginx
# Contexto http: HEAD compartilha a entrada GET.

map $request_method $lp_cache_method {
    default $request_method;
    HEAD GET;
}

fastcgi_cache_path /var/cache/nginx/lightpress levels=1:2 keys_zone=LIGHTPRESS:100m inactive=60m max_size=5g;
fastcgi_cache_key "$scheme$lp_cache_method$host$request_uri";

# Contexto location FastCGI: $lp_skip_cache deve ser definido por maps

# que implementem TODAS as exclusões de requisição da tabela acima.

fastcgi_cache LIGHTPRESS;
fastcgi_cache_methods GET HEAD;
fastcgi_cache_bypass $lp_skip_cache;
fastcgi_no_cache $lp_skip_cache $upstream_http_set_cookie;
fastcgi_cache_lock on;
fastcgi_cache_use_stale off;
fastcgi_cache_background_update off;
```

A configuração final deve restringir gravação a respostas 200 e preservar o tratamento nativo dos cabeçalhos `Cache-Control`, `Set-Cookie`, `Vary` e `X-Accel-Expires`; não usar `fastcgi_ignore_headers` para contornar essas proteções. Os TTLs por rota são definidos na seção 7.3. O cookie de sessão configurado no PHP deve coincidir com o nome usado nos maps.

### 7.2. Purga Transacional, Permissões e Falhas

* **Permissões mínimas:** Usar grupo dedicado de cache compartilhado pelo Nginx e PHP-FPM, diretórios com herança de grupo/ACL e escrita efetiva nos diretórios internos. Não usar permissão global 777. O instalador deve criar e remover um arquivo de teste no caminho real; `is_writable()` isoladamente não valida toda a árvore.
* **Cálculo exato:** A chave é a concatenação de esquema, método normalizado `GET`, host canônico e URI canônica, idêntica à configuração do Nginx. Para `$hash = md5($key)`, o caminho com `levels=1:2` é:

  ```text
  /var/cache/nginx/lightpress/{substr(hash, -1)}/{substr(hash, -3, 2)}/{hash}
  ```

  Por exemplo, um hash terminado em `7fb` fica em `b/7f/{hash}`. Não inverter os dois caracteres do segundo diretório. O hash é usado apenas para localizar o cache, não para autenticação.
* **Trabalho persistente:** Na mesma transação da alteração pública, gravar em `cache_purge_jobs` o conjunto de URLs afetadas, ID do artigo, evento e valores anteriores/novos necessários. Após commit, tentar a purga síncrona. Rollback não dispara purga; salvar revisão privada também não.
* **URLs afetadas:** Purgar artigo antigo e novo, home, categoria antiga e nova, além de eventuais redirecionamentos anteriormente armazenados. Despublicação, arquivamento e exclusão também geram trabalho. Registrar URLs antigas antes da alteração ou exclusão; o trabalho deve sobreviver à exclusão do artigo.
* **Resultado verificável:** Arquivo já ausente é sucesso idempotente. Falha de permissão ou I/O gera log e mantém o trabalho pendente, com tentativas, próxima execução e último erro. O CLI `retry-cache-purge.php`, executado por cron a cada minuto com bloqueio contra sobreposição, reprocessa com backoff de 1, 5 e até 15 minutos, sem descartar falhas persistentes.
* **Estado no painel:** Distinguir gravação confirmada de propagação concluída. Falhas continuam visíveis ao administrador e não são apresentadas como publicação plenamente propagada. O worker retoma trabalhos interrompidos com lease expirável.
* **Concorrência:** Remover um arquivo não impede que uma requisição iniciada antes do commit termine gravando conteúdo antigo depois da purga. `fastcgi_cache_lock` reduz regenerações simultâneas, mas não resolve essa corrida. A implementação deve drenar as renderizações anteriores dentro de um tempo máximo configurado do upstream e repetir a purga após esse prazo antes de concluir o trabalho; novas renderizações devem ler o banco primário sem réplica atrasada. Esse protocolo precisa ser validado sob concorrência real.
* **Retirada de conteúdo:** Arquivamento ou exclusão não são considerados propagados enquanto a invalidação e a verificação final não terminarem. Durante esse intervalo, conteúdo anterior pode permanecer visível. Uma retirada urgente exige bypass temporário das URLs afetadas no Nginx, instalado e verificado pelo operador; apenas salvar no banco ou esperar o TTL não é garantia de retirada imediata.

Essa política evita servir conteúdo expirado em caso de falha por meio de `use_stale` ou atualização em segundo plano. Se uma CDN for adicionada, o trabalho de invalidação deve abranger também suas entradas; a topologia inicial não pressupõe cache HTML externo.

### 7.3. Estratégia para Páginas Secundárias, Paginações e Feeds

| Recurso | TTL máximo | Atualização |
| :--- | :--- | :--- |
| Artigo publicado | 60 minutos | Purga em alteração pública ou retirada |
| Home e categoria, página principal | 5 minutos | Purga em alteração pública relevante |
| Paginações secundárias, feeds e sitemap | 5 minutos | Expiração natural para mudanças editoriais comuns |
| Rotas privadas, APIs, erros e redirecionamentos | Sem cache FastCGI | Resposta dinâmica ou estática apropriada |

O atraso de até 5 minutos nas listagens secundárias é uma regra explícita da primeira fase. Para retiradas urgentes, essas listagens e feeds também precisam de invalidação ou bypass direcionado, pois podem conter título, resumo ou conteúdo do artigo. Não executar varredura recursiva do disco a cada salvamento; manter catálogo das URLs cacheáveis conhecidas para os casos que exigem retirada direcionada.

### 7.4. Critérios de Aceitação do Cache

* Confirmar HIT após aquecimento de cada rota pública permitida, com GET e HEAD compartilhando a mesma entrada, e bypass para todas as condições privadas, inclusive cookie de sessão inválido, Authorization e query string.
* Provar que prévias, respostas com `Set-Cookie`, erros e conteúdo de usuários autenticados nunca são armazenados nem reaproveitados para anônimos.
* Testar mudança de slug/categoria, publicação, atualização, arquivamento e exclusão, verificando URLs antigas e novas e TTLs das listagens.
* Simular falha de permissão, interrupção do worker e renderização anterior ao commit terminando depois da primeira purga; verificar retentativa persistente e ausência do conteúdo antigo após a conclusão declarada.

### 7.5. Invalidação Seletiva na Borda com Cloudflare (API v4)

Para portais que utilizam proxy reverso e CDN da Cloudflare à frente do Nginx, o LightPress integra nativamente a purga seletiva de cache por URLs via API v4 (`core/CloudflareCache.php`).

#### Funcionamento da Purga Seletiva
- **Preservação de Assets Estáticos:** A purga invalida exclusivamente o HTML das páginas impactadas pela nova notícia (Home `/`, feeds RSS/Atom `/feed.xml` e `/feed/`, `/sitemap.xml`, a notícia recém-publicada `/{slug}/` e sua categoria `/categoria/{slug}/`). Imagens (`/uploads/`), CSS, JS e fontes continuam cacheados nos datacenters globais da Cloudflare, economizando banda e mantendo máxima performance.
- **Orquestração Concorrente:** O `CacheManager` orquestra a limpeza do cache FastCGI em disco (`/var/cache/nginx/wordpress`) e dispara a invalidação seletiva na Cloudflare em milissegundos.
- **Operação Não-Bloqueante (Fail-Safe):** A chamada cURL para a Cloudflare opera com timeout estrito de 5 segundos e tratamento defensivo via `error_log`. Falhas externas de conectividade nunca bloqueiam a publicação nem retornam erro 500 no painel editorial.
- **Lotes Determinísticos:** O envio de URLs é agrupado em lotes de até 30 arquivos por requisição, respeitando os limites da API v4 da Cloudflare.

#### Configuração (`config.local.php`)
```php
    'cloudflare' => [
        'enabled'    => true,
        'zone_id'    => 'SEU_ZONE_ID_DA_CLOUDFLARE',
        'api_token'  => 'SEU_API_TOKEN_COM_PERMISSAO_DE_PURGE',
        'timeout'    => 5,
    ],
```

#### Utilitário CLI (`bin/purge-cache.php`)
O LightPress disponibiliza um utilitário de linha de comando para validações e limpezas manuais:
```bash
# Validar credenciais e permissão real de purga na Cloudflare
php bin/purge-cache.php --test

# Purgar matéria específica (Home, Feeds, Sitemap e Notícia)
php bin/purge-cache.php --slug="alan-permane-racing-bulls-2031"

# Purgar Home, Feeds e Sitemap
php bin/purge-cache.php --home

# Purga total emergencial (Cloudflare Purge Everything + Disco FastCGI local)
php bin/purge-cache.php --all
```

---

## 8. Diretrizes e Melhores Práticas de Segurança (Security by Design)

O LightPress adota uma postura defensiva em todas as camadas da aplicação, alinhada aos padrões de segurança modernos da indústria e OWASP Top 10:

### 8.1. Prevenção Contra Injeção SQL (SQLi)

* Uso obrigatório de **Prepared Statements nativos via PDO** em 100% das consultas que utilizam dados dinâmicos.
* Desativação explícita da emulação de prepares:
  ```php
  $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  ```
* Tipagem rigorosa em parâmetros de consulta (ex: casting explícito de IDs para `int`).

### 8.2. Prevenção Contra Cross-Site Scripting (XSS)

* **Compilação estruturada e Sanitização Rigorosa por DOMDocument:** O `BlockCompiler` valida tipos, tamanhos, profundidade e versão do esquema JSON. A primeira fase admite títulos h2/h3, parágrafos, negrito/ênfase, links, listas, citações e imagens com legenda. O HTML é construído por tipo de bloco e sanitizado via `\DOMDocument` através de uma whitelist estrita de tags e atributos autorizados por elemento (eliminando completamente eventos `on*`, atributos inline `style`, tags `<script>` e tags `<style>`, mesmo em casos de sintaxe HTML5 sem espaços como `<img/src="x"/onerror=alert(1)>`). Blocos desconhecidos são rejeitados com erro identificável, sem saída HTML arbitrária.
* **URLs e atributos:** Links aceitam somente esquemas explicitamente permitidos (`https`, `http` e `mailto`) ou caminhos internos validados. Imagens referenciam mídia conhecida do servidor; `srcset`, `type`, dimensões e variantes são gerados a partir do manifesto persistido. Proibir eventos `on*`, scripts, iframes, estilos arbitrários e URLs `javascript:` ou protocolo-relativas (`//`). Formatação inline também usa nós estruturados; strings de texto não são tratadas como HTML.
* **Escape em Templates & Defesa em Profundidade:** PHP nativo não oferece escape automático. Texto e atributos dinâmicos devem usar função de escape consistente com flags completas:
  ```php
  function e(string|null $value): string {
      return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
  ```
  Páginas institucionais (`templates/page.php`) executam sanitização defensiva em profundidade via `BlockCompiler::sanitizeHtml()` antes da exibição para neutralizar qualquer possibilidade de XSS armazenado.
* **HTML compilado:** Somente `content_html` produzido pelo compilador e armazenado pelo fluxo autorizado pode ser renderizado como HTML sem passar por `e()` novamente. JSON-LD e dados inseridos em scripts usam serialização JSON apropriada ao contexto, não apenas escape HTML. A migração obedece à mesma fronteira de confiança.

### 8.3. Proteção Contra Cross-Site Request Forgery (CSRF)

* Toda operação mutacional administrativa, inclusive login, logout, uploads, publicação e gestão de usuários, exige token CSRF gerado por `bin2hex(random_bytes(32))`, armazenado na sessão e validado com `hash_equals()`. Formulários usam campo oculto; APIs do admin exigem `X-CSRF-Token`. GET/HEAD nunca executam essas mutações.
* **Exceção pública explícita:** `POST /api/view/{id}` não usa token de sessão, não inicia sessão e não concede privilégios de conta. Somente o registro limitado de visualizações é permitido, com as validações e deduplicação da seção 6.2. Nenhum outro endpoint herda automaticamente essa exceção.
* **Origem do contador:** Quando `Sec-Fetch-Site` estiver presente, exigir `same-origin`; rejeitar `cross-site`, `same-site` e `none` para esse POST. Se `Origin` estiver presente, ele também deve coincidir exatamente com a origem canônica configurada, incluindo esquema e porta efetiva; `null` é rejeitado.
* **Compatibilidade:** Na ausência de Fetch Metadata, exigir `Origin` válido ou, se ausente, validar a origem extraída de `Referer`. Ausência de todas essas evidências retorna 403 sem incrementar. Nunca comparar domínios por substring nem confiar no Host recebido para definir a origem autorizada.
* Não habilitar CORS para origens externas no contador. A ausência de CORS não substitui a validação, pois requisições simples podem chegar ao servidor sem preflight. Testar tanto `sendBeacon` quanto `fetch(..., {method: 'POST', keepalive: true})` nos navegadores homologados.
* Formulários de ativação/redefinição também validam CSRF; o token de redefinição prova autorização para trocar a senha, não deve ser consumido por um GET de abertura de link.

### 8.4. Autenticação e Gestão Segura de Sessões

* **Hashing de Senhas:** Utilização exclusiva do algoritmo nativo **Argon2id** (`PASSWORD_ARGON2ID`) para hash e verificação de senhas de usuários.
* **Hardening de Cookies de Sessão:**
  ```php
  session_name('LIGHTPRESSSESSID');
  session_start([
      'cookie_lifetime' => 0,
      'cookie_path' => '/',
      'cookie_domain' => '',
      'cookie_secure' => true,      // HTTPS obrigatório
      'cookie_httponly' => true,    // Proteção contra roubo via JS
      'cookie_samesite' => 'Strict',// Mitigação contra CSRF
      'use_strict_mode' => true,
  ]);
  ```
* **Regeneração de Sessão:** Chamada obrigatória a `session_regenerate_id(true)` após login com sucesso para prevenir ataques de fixação de sessão.
* **Proteção contra Força Bruta e Spoofing de IP:** Validação de proxies reversos confiáveis (Cloudflare, localhost) em `Security::getClientIp()` antes de confiar em cabeçalhos como `X-Forwarded-For` ou `CF-Connecting-IP`. Limitação estrita de tentativas de login baseada no IP real do cliente (`Security::checkLoginRateLimit()`), bloqueando ataques distribuídos e de dicionário mesmo que o cliente descarte o cookie de sessão.
* **Sessões Revogáveis e Centralização de Autenticação (`Security::requireAuth`):** Todas as rotas administrativas e APIs internas validam o acesso através de `Security::requireAuth(?string $action, ?array $roles, ?array $config)`, que consulta em tempo real o estado da conta e a versão da sessão (`session_version`). Caso a conta seja desativada (`is_active = 0`), a senha redefinida ou o usuário deslogado remotamente, qualquer requisição (página HTML ou chamada assíncrona AJAX/JSON) é imediatamente rejeitada e a sessão invalidada.
* **Tokens e Bloqueio Concorrente:** Ativação expira em até 24 horas; redefinição, em até 1 hora. Tokens são de uso único, validados com CSRF obrigatório e protegidos contra race conditions através de lock pessimista (`SELECT ... FOR UPDATE`) dentro de transações de banco de dados. Limitar solicitação e validação por IP e conta, usar mensagens que não revelem a existência do e-mail e não registrar tokens/URLs completas em logs.
* **Conclusão segura:** Ao definir senha Argon2id, consumir o token, remover `password_reset_required` e revogar os demais tokens e sessões em uma transação. Somente na ativação válida de conta pendente, liberar também `is_active`; um bloqueio administrativo revoga tokens de ativação e não pode ser desfeito por simples redefinição de senha. Não autenticar automaticamente após redefinição.

### 8.4.1. Autorização por Perfil, Artigo e Campo

| Ação | Autor | Editor | Administrador |
| :--- | :--- | :--- | :--- |
| Criar artigo | Sim, em sua própria autoria | Sim | Sim |
| Editar rascunho ou criar revisão | Apenas artigos próprios | Qualquer artigo | Qualquer artigo |
| Enviar para revisão | Apenas artigos próprios | Qualquer artigo | Qualquer artigo |
| Publicar, atualizar versão pública, arquivar | Não | Sim | Sim |
| Alterar autoria, destaque e catálogo de categorias | Não | Sim | Sim |
| Gerir usuários, perfis e configurações | Não | Não | Sim |
| Excluir definitivamente artigos | Não | Não | Sim |

* Centralizar as decisões em `Authorization.php`, por exemplo `can($user, $action, $post)`, com negação por padrão. Verificar todas as requisições e operações de serviço, incluindo AJAX, prévias, upload e aplicação de revisões.
* IDs recebidos nunca provam propriedade. Conferir `posts.user_id` e validar campos permitidos por perfil; um autor não pode forjar `user_id`, `status`, destaque ou privilégios no JSON. O vínculo de uma imagem a um artigo também exige autorização sobre ambos.
* Contas novas e importadas recebem `author` por padrão. Promoções são explícitas e administrativas. Impedir remoção, desativação ou rebaixamento do último administrador ativo.
* Testar acesso horizontal (autor tentando editar outro autor), acesso vertical (autor tentando publicar/administrar), mudança de perfil com sessão aberta e bloqueio de conta. Ocultar botões na interface não substitui essas verificações.

### 8.5. Blindagem do Diretório de Uploads e Execução de Arquivos

* **Bloqueio de Execução no Nginx:** O diretório público `/public/uploads/` nunca deve processar ou executar scripts PHP:
  ```nginx
  location ^~ /uploads/ {
      location ~ \.(php|phtml|phar|pl|py|cgi|sh)$ {
          deny all;
          return 404;
      }
      try_files $uri =404;
      add_header X-Content-Type-Options "nosniff";
  }
  ```
* Arquivos são armazenados com extensões padronizadas restritas (`.webp`, `.avif`) e nomes pseudorrandômicos gerados no servidor.

### 8.6. Cabeçalhos HTTP de Segurança Defensivos

O servidor web e o PHP devem injetar cabeçalhos modernos para proteger o usuário final:
```nginx
# Prevenção de MIME sniffing

add_header X-Content-Type-Options "nosniff" always;

# Prevenção de Clickjacking

add_header X-Frame-Options "SAMEORIGIN" always;

# Controle estrito de Referrer

add_header Referrer-Policy "strict-origin-when-cross-origin" always;

# Restrição de APIs do navegador

add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()" always;

# Forçar HTTPS permanente

add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;

# Content Security Policy (CSP) base

add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; frame-ancestors 'self';" always;
```

### 8.7. Permissões de Pastas e Coexistência com FTP/SSH (0775 / 0664)

Para viabilizar fluxos mistos em que fotos e acervos são manipulados tanto pela aplicação web (usuário `www-data`) quanto por clientes SFTP/FTP (ex: FileZilla com usuário de sistema `notebook`), o sistema adota:
* **Diretórios de Upload:** Criados com máscara octal `0775` (`rwxrwxr-x`) e SGID ativo (`chmod g+s`), garantindo que novas pastas herdem automaticamente o grupo proprietário (`www-data`).
* **Arquivos de Mídia Gerados:** Criados com permissão `0664` (`rw-rw-r--`), permitindo leitura e exclusão mútua por ambos os usuários sem conflitos de propriedade.

### 8.8. Configurações Dinâmicas do Portal (`core/Settings.php` e `/admin/settings.php`)

O gerenciamento de metadados fundamentais do portal opera desacoplado de arquivos estáticos, permitindo ajustes em tempo real via Painel Administrativo com propagação imediata:
* **Tabela `settings`:** Modelo chave-valor (`key`, `value`, `updated_at`) com persistência via `REPLACE INTO` atômico e cache em memória durante o ciclo de vida da requisição.
* **Propagação Central (`core/bootstrap.php`):** Sobrescreve dinamicamente `$config['app']['name']`, `$config['app']['description']`, `$config['app']['favicon']` e `$config['app']['display_timezone']`.
* **Invalidação Proativa de Cache:** Salvar as configurações expurga imediatamente o FastCGI Cache da Home (`/`), sitemap e feeds RSS (`/feed.xml`).
* **Seletor de Fuso Horário:** Catálogo com fusos recomendados para motorsport e lista completa de offsets fixos de UTC-12 a UTC+14, ajustando `date_default_timezone_set()` de ponta a ponta.

### 8.9. SEO Dinâmico e Compartilhamento Social Otimizado em WebP (`core/Seo.php`)

Para maximizar a compatibilidade com redes sociais (WhatsApp, X/Twitter, Facebook, LinkedIn) que rejeitam formatos como AVIF em cards de pré-visualização:
* **Home, Categorias e Tags:** As tags `<meta property="og:image">` e `<meta name="twitter:image">` consomem dinamicamente o Favicon configurado no portal.
* **Conversão Forçada para WebP:** O método `ImageProcessor::toWebpUrl()` substitui extensões `.avif`, `.png`, `.jpg` ou `.ico` pela variante correspondente `.webp`, acompanhada do domínio canônico absoluto (`https://...`).
* **Artigos Individuais:** A imagem de destaque da matéria (`featured_image`) é convertida automaticamente para WebP de alta resolução nas metatags sociais.

### 8.10. Módulo de Páginas Institucionais (`/admin/pages.php`, `templates/page.php`)

Páginas estáticas corporativas (ex: *Nossa Equipe Editorial*, *Contato / Fale Conosco*, *Termos de Uso*, *Política de Privacidade*, *Código de Ética*) são gerenciadas isoladamente na tabela `pages`:
* **URLs Amigáveis na Raiz:** Servidas diretamente em `/{slug}/` com verificação transparente de colisão com notícias.
* **Métricas de Leitura:** Contabilização atômica de visualizações (`views`) na tabela de páginas.
* **Auto-migração de Slugs:** O esquema migra automaticamente slugs legados (ex: `quem-somos` para `nossa-equipe`) mantendo links internos íntegros.

### 8.11. Biblioteca de Ícones Vetoriais SVG Inline (`core/Icon.php`)

Para eliminar o peso excessivo de fontes de ícones de terceiros (como Material Symbols Outlined, que excedia 1 MiB):
* Os ícones do sistema (`campaign`, `verified`, `sports_score`, `article`, `menu`, etc.) são renderizados diretamente como elementos `<svg>` puros embutidos no HTML.
* Zero dependência de CDN externa, zero requisições HTTP adicionais e suporte nativo às classes utilitárias de cor e tamanho do Tailwind CSS.

### 8.12. Pipeline de CSS Estático de Produção (Tailwind CSS)

Para assegurar nota máxima (**100/100**) no Google PageSpeed Insights (Mobile e Desktop), eliminar custos de processamento no navegador do leitor (TBT/LCP) e dispensar `unsafe-inline` na CSP, o portal utiliza uma compilação estática e minificada do Tailwind CSS:

* **Ponto de Entrada:** `src/input.css` (diretivas `@tailwind base`, `@tailwind components`, `@tailwind utilities` e personalizações globais de scrollbar para modos claro e escuro).
* **Configuração Oficial:** `tailwind.config.js` com suporte a `darkMode: 'class'`, varredura automática de templates (`./templates/**/*.php`, `./public/**/*.php`, `./core/**/*.php`) e mapeamento integral do design system Kinetic Apex Editorial (cores `racing-red`, `carbon-black`, tipografias `Space Grotesk` e `Work Sans`).
* **Saída Minificada:** `public/assets/style.css` (~29 KiB raw / ~5 a 6 KiB transferidos via gzip/brotli no Nginx).
* **Inclusão Otimizada com Cache-Busting (`templates/header.php`):**
  ```php
  <?php 
  $compiledCssPath = dirname(__DIR__) . '/public/assets/style.css';
  if (file_exists($compiledCssPath)): 
  ?>
      <link rel="stylesheet" href="/assets/style.css?v=<?= filemtime($compiledCssPath) ?>">
  <?php else: ?>
      <!-- Fallback automático para Play CDN caso style.css não exista em desenvolvimento -->
      <script src="https://cdn.tailwindcss.com"></script>
  <?php endif; ?>
  ```

#### Comandos de Desenvolvimento e Produção:

Sempre que novas classes de estilo ou modificações de layout forem feitas nos templates:

* **Compilar para Produção (Minificado):**
  ```bash
  npm run build:css
  ```
  *Executa `tailwindcss -i ./src/input.css -o ./public/assets/style.css --minify` gerando a versão de distribuição otimizada.*

* **Desenvolvimento Contínuo (Compilação Automática / Watch):**
  ```bash
  npm run watch:css
  ```
  *Monitora alterações em qualquer template ou arquivo PHP e recompila o CSS instantaneamente a cada salvamento.*

---

### 8.13. Arquitetura de Plugins e Extensões (Zero Bloatware)

Para permitir a extensão modular das capacidades do LightPress sem incorrer nos vícios estruturais do WordPress (consultas em cascata, lentidão em hooks desestruturados e tabelas EAV `postmeta`):

* **Isolamento Fora da Raiz Pública:** Módulos residem em `/plugins/{plugin-slug}/` fora do web root `/public`, com bloqueio nativo de execução direta via HTTP.
* **Sistema de Hooks Ultraleve (`core/Hook.php`):** Motor de Actions (`Hook::addAction`, `Hook::doAction`) e Filters (`Hook::addFilter`, `Hook::applyFilters`) com ordenação numérica de prioridade, sem dependências externas e com impacto inferior a 1 microssegundo.
* **Gerenciador de Ciclo de Vida (`core/PluginManager.php`):**
  - Descoberta declarativa através de `plugin.json` (sem execução de código PHP no momento da leitura de metadados).
  - Ativação e desativação transacionais persistidas em `settings.active_plugins` (carregado em memória durante o bootstrap sem queries SQL adicionais).
  - **Entrega Estática de Máxima Velocidade no Nginx:** Mapeamento de `/plugins/{slug}/assets/` para `/public/assets/plugins/{slug}/` (via link simbólico ou sincronização atômica) permitindo que arquivos CSS, JS e imagens sejam entregues diretamente pelo Nginx sem passar pelo PHP.
* **Sem EAV / Proibição de `postmeta`:** Se um plugin necessita de persistência relacional, cria suas próprias tabelas dedicadas (`CREATE TABLE IF NOT EXISTS plugin_{slug}_...`) com chaves primárias indexadas e foreign keys com `ON DELETE CASCADE`.
* **Disparo Pós-Commit e Fail-Safe:** Hooks de publicação (`post_published`) são disparados **estritamente após o commit da transação do banco e purga do cache FastCGI**, com timeouts curtos (3-4s via cURL nativo) para que falhas externas de rede nunca afetem a experiência da redação.
* **Menu Superior com Submenu Dinâmico (`core/AdminNav.php`):** Inclusão no cabeçalho administrativo do dropdown **Plugins ▾**, contendo acesso rápido a **Gerenciar Plugins** (`/admin/plugins.php`) e submenus diretos para as páginas de configuração dos plugins ativos (`/admin/plugin-settings.php?plugin={slug}`).

#### 8.13.1. Primeiro Plugin Oficial: Webhook Sender (`plugins/webhook-sender/`)

O primeiro módulo construído sobre a nova arquitetura é o **Webhook Sender** (documentação completa em [`Plugin-Webhook.md`](file:///c:/Web/lightpress/Plugin-Webhook.md)):

* **Multi-Servidores:** Permite cadastrar múltiplos endpoints (Discord, Slack, n8n, Zapier ou microserviços HTTP REST) separados por vírgula ou quebra de linha.
* **Tabela Relacional Própria (`plugin_webhook_sender_logs`):** Substituição radical das chaves `_das_discord_pending` do `postmeta` legado por tabela própria com chave estrangeira indexada para `posts(id)` e `ON DELETE CASCADE`.
* **Auto-Promoção Atômica de Agendados (`FOR UPDATE`):** Integração profunda com `PostService::promoteScheduledPosts()`, disparando notificações para notícias publicadas com data futura no momento exato em que vão ao ar.
* **Detecção Automática de Origem Canônica em CLI/Cron:** Em execuções de terminal ou cron onde `$_SERVER['HTTP_HOST']` não existe, utiliza `$config['app']['canonical_origin']` para gerar permalinks absolutos precisos.
* **Tolerância a Falhas de Rede (`is_success`):** Falhas transitórias externas (timeout ou HTTP 4xx/5xx) são sinalizadas como erro nos logs, permitindo retentativas manuais pelo painel ou nova execução, sem bloquear o sistema.
* **Interface Administrativa:** Painel integrado em `/admin/plugin-settings.php?plugin=webhook-sender` com teste de ping imediato e tabela de auditoria dos últimos envios com status de entrega e botão de reenvio em 1 clique.

---

## 9. Script de Migração do WordPress (`migrate-wp.php`)

Utilitário CLI que lê uma origem WordPress identificada, com credenciais somente de leitura, e grava no LightPress por meio dos mesmos validadores, compilador e regras transacionais do fluxo editorial. Nenhuma dependência do WordPress é carregada nas páginas públicas do LightPress.

### 9.1. Conversão de Conteúdo e Fidelidade

```text
Inventário e backup privado
  → leitura por lotes
  → identificação de blocos Gutenberg ou HTML clássico
  → conversão para o esquema de blocos LightPress
  → validação e relatório de incompatibilidades
  → compilação segura no servidor
  → gravação transacional e checkpoint
```

* **Gutenberg:** Usar o parser oficial de blocos do WordPress no processo CLI ou exportação prévia no ambiente de origem. Mapear os blocos suportados, incluindo parágrafos, títulos, listas, links, citações, imagens e legendas. O parser identifica a estrutura; não é um sanitizador e não autoriza a execução de blocos dinâmicos.
* **Editor clássico:** Analisar HTML com parser DOM, sem usar regex como parser de documento. Converter elementos reconhecidos para nós estruturados e passar pelo compilador. Não copiar `post_content` diretamente para `content_html`.
* **Plugins, shortcodes e embeds:** Não executar código legado ou shortcodes durante a importação. Elementos desconhecidos, galerias sem conversor, embeds e atributos sem equivalente geram pendência identificada por artigo e trecho de origem. Adaptadores só podem ser adicionados explicitamente e com validação.
* **Fronteira de segurança:** `content_json` é a fonte editável e `content_html` sua compilação. Não criar bloco de HTML arbitrário como fallback para contornar o compilador. Scripts, atributos de eventos e URLs perigosas são removidos ou rejeitados com registro da alteração.
* **Preservação:** Guardar o conteúdo original, identificadores, metadados relevantes e checksum em backup privado fora do web root, com acesso restrito. Incompatibilidade não é corrigida silenciosamente transformando tudo em parágrafo; o artigo permanece privado e pendente de revisão até resolução.

### 9.2. Mapeamento de Dados e Mídia

| Origem WordPress | Destino / regra |
| :--- | :--- |
| `ID` | Mapeamento estável para `posts.id`; preservar o número apenas se não houver colisão e a política de destino permitir |
| `post_title`, `post_name`, `post_excerpt` | `title`, `slug`, `summary`, após validação e relatório de colisões/limites |
| `post_content` | Blocos convertidos em `content_json`; HTML gerado pelo `BlockCompiler` |
| Datas de publicação e modificação | `published_at` e `updated_at` convertidos para UTC; preferir campos GMT válidos, com fallback ao fuso documentado da origem |
| `post_status` | `publish` elegível para publicação após validação; `draft`/`auto-draft` → `draft`; `pending` → `pending_review`; `trash` → `archived` |
| Conteúdo privado, protegido por senha ou agendado | Importar como privado e registrar pendência; não publicar automaticamente |
| Autor | Resolver por mapa de usuários; nunca confiar que os IDs coincidem |
| Categorias e tags | Resolver relacionamentos WordPress; escolher categoria principal por regra explícita e aprovada; preservar todas as associações no backup |
| SEO Yoast/RankMath | Mapear título e descrição, incluindo `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `rank_math_title` e `rank_math_description`; variáveis de template exigem conversor ou revisão |

Datas inválidas, tags que excedam o limite, múltiplas categorias sem regra aprovada, slugs duplicados e campos grandes demais geram relatório, sem truncamento silencioso. Artigos não publicados inicialmente têm `published_at = NULL`; arquivados preservam uma data histórica válida quando existente. Um artigo de origem publicado só recebe `status = 'published'` se a conversão estiver aprovada; com pendências, fica em revisão privada e sua publicação original permanece registrada no backup.

Cada artigo importado cria `posts`, `post_stats`, snapshot inicial e mapeamento de origem na mesma transação. `post_stats.views` começa em zero, salvo mapeamento explícito de um contador de plugin; reexecuções não zeram contadores existentes. A migração preserva a data editorial de origem, sem substituí-la pela data da execução.

Anexos passam pelo mesmo `ImageProcessor` dos uploads, com deduplicação pelo identificador da origem e checksum. Converter imagem de destaque e imagens internas, preservar texto alternativo/legendas e reescrever links para manifestos válidos. A origem preferencial é uma cópia local controlada de `wp-content/uploads`; normalizar caminhos e impedir escapes do diretório. Downloads remotos, quando necessários, exigem lista explícita de hosts, limites e validação de redirecionamentos contra SSRF; ficam desabilitados por padrão.

### 9.3. Autores, Ativação e URLs Antigas

* Criar usuários com `role = 'author'`, `is_active = 0` e `password_reset_required = 1`; o hash inicial é Argon2id de um segredo aleatório descartado. Não importar senhas nem distribuir senhas temporárias compartilhadas. E-mails duplicados exigem conciliação explícita, sem fundir contas automaticamente.
* Ativar por token individual de uso único da seção 3.5. Promoção de editor/administrador depende de ação administrativa explícita; privilégios do WordPress não são copiados automaticamente. A entrega dos links é um passo operacional controlado, fora dos logs e relatórios públicos.
* Inventariar os permalinks reais antes de mudar a origem, incluindo datas, IDs, categorias e variantes históricas. Persistir em `redirects` os caminhos antigos e o destino canônico, com unicidade da origem, resposta 301 e proteção contra ciclos e redirecionamento aberto.
* Preservar URLs já canônicas; não criar redirecionamento de uma URL para ela mesma. Referências antigas de imagens também recebem mapeamento quando seus originais forem retirados. Não alterar nem apagar o acervo WordPress durante a importação.

### 9.4. Lotes, Retomada e Homologação

* Oferecer modo `--dry-run`, seleção por intervalo de IDs e lotes configuráveis, inicialmente 100 artigos. Usar paginação por ID, sem carregar o acervo inteiro e sem uma única transação para toda a migração.
* Identificar a origem de forma estável e persistir `migration_map` com chave única `(source, entity_type, source_id)`, ID de destino, checksum e estado. Confirmar o checkpoint somente depois do commit correspondente. Falhas permitem retomar sem duplicar artigos, usuários, anexos ou redirecionamentos.
* Reexecutar a mesma origem sem mudanças não cria duplicatas nem modifica datas. Se a origem mudou, gerar revisão e conflito para análise quando o destino já tiver edição própria; não sobrescrever trabalho editorial automaticamente.
* Importar primeiro em staging privado, com e-mails e contagem de visitas desativados. Emitir totais lidos, convertidos, pendentes, ignorados e falhos, além de inventário de mídia e redirecionamentos. Nunca tratar pendências como sucesso completo.
* Homologar amostra representativa de conteúdo clássico, Gutenberg, imagens, listas, links, legendas, SEO e casos incompatíveis; verificar JSON editável e HTML público. Conferir totais e testar URLs antigas antes da troca de tráfego.
* A migração pública só ocorre após aprovação do relatório, backup testado e definição de janela de congelamento editorial ou sincronização final. Documentar o retorno ao WordPress, preservado até a validação da troca.

---

## 10. Próximos Passos de Desenvolvimento e Roadmap

### 10.1. Status Atual do Core e Infraestrutura
1. [x] **Estrutura e ambiente:** Criar diretórios, `config.php` seguro, diagnóstico de extensões/codecs, origem canônica, UTC e tarefas periódicas.
2. [x] **Persistência:** Implementar DDL e transações de usuários, artigos, estatísticas, revisões, tokens e estruturas operacionais da seção 3.6.
3. [x] **Autenticação e autorização:** Implementar Argon2id, sessões revogáveis, perfis fixos, autorização por artigo/campo, CSRF administrativo, ativação e redefinição.
4. [x] **Rotas e contador:** Implementar URLs canônicas e endpoint público de visualizações sem sessão, com validação de origem, deduplicação atômica e limites de abuso.
5. [x] **Pipeline de Mídia:** Implementar WebP obrigatório, AVIF calibrado (Q65, speed 8), pass-through de arquivos já otimizados, métricas transparentes e nomes semânticos para SEO.
6. [x] **Editor Visual de Notícias:** Canvas contínuo com higienizador de cópia do Google Docs/Word, seletor de mídias em modal WordPress-style, slug personalizado e focus keyword.
7. [x] **Cache Nginx FastCGI:** Implementar maps de bypass, chave unificada (suporte a Cloudflare e HTTPS reverso), invalidação atômica de URLs na publicação e purga em SSD.

### 10.2. Próximas Frentes Priorizadas de Trabalho

* **FASE CONCLUÍDA — Opção 1: Experiência Visual do Portal (Frontend Público de Notícias):**
  - [x] **Design de Portal de Alta Velocidade:** Layout panorâmico Full HD (`1900px`), Home com Hero Quad Grid de 4 destaques com backfill automático, página de leitura de artigos widescreen (`1750px`), capa com visual cinematográfico, tempo estimado de leitura (*ex: 3 min de leitura*), bloco de autor com bio/avatar e modo escuro nativo sem flash.
  - [x] **Módulo de Notícias Relacionadas:** Recomendações contextuais de 4 matérias da mesma categoria em grid responsivo de tela ampla para retenção de audiência.
  - [x] **Botões Rápidos de Compartilhamento Social:** Barra leve de compartilhamento (WhatsApp, X, Facebook, Telegram e botão de 1 clique Copiar Link com feedback instantâneo) sem rastreadores ou bibliotecas pesadas.
  - [x] **Menu Superior Sticky Fixo:** Cabeçalho global fixado no topo para navegação fluida em todo o site.
  - [x] **Sistema de Busca Integrada (`/busca?q=...`):** Pesquisa ágil de notícias publicadas com marcação visual dos termos pesquisados e compatibilidade total com o cache estático.
  - [x] **URLs Canônicas na Raiz (`/{slug}/`):** URLs permanentes de alto impacto com redirecionamento 301 automático das rotas antigas.

* **FASE CONCLUÍDA — Opção 2: Recursos Administrativos da Redação (Gestão de Portal):**
  - [x] **Dashboard Editorial Turbinado (`/admin/dashboard.php`):** Barra de filtros dinâmicos por Status (Publicados, Rascunhos, Em Revisão, Arquivados), por Categoria, por Autor, busca textual por título/resumo, filtro de Destaques Home (★), métricas em tempo real no topo e paginação rápida (25 por página).
  - [x] **Painel de Mais Lidas e Audiência (`/admin/stats.php`):** Ranking Top 20 matérias mais lidas, desempenho por categoria e produção por autor baseados em dados atômicos sem duplicidade por IP.
  - [x] **Gestão Completa de Categorias (`/admin/categories.php`):** Interface administrativa para criar, editar nome/slug com atualização em cascata de matérias vinculadas, pausar/ativar e exclusão segura com reatribuição automática para a categoria padrão `geral`, com expurgo de cache no Nginx.
  - [x] **Gestão de Usuários e Autores (`/admin/users.php`):** Cadastro e edição completa de membros da redação (repórteres, editores e administradores), biografia do autor, avatar, link seguro de ativação criptográfico de uso único ou definição direta de senha com Argon2id e revogação instantânea de sessões ativas (`session_version`).

* **FASE CONCLUÍDA — Extensibilidade & Plugins (Zero Bloatware):**
  - [x] **Arquitetura de Hooks & Plugins:** Barramento ultraleve de eventos (`Hook`), gerenciador de ciclo de vida (`PluginManager`), manifesto `plugin.json` e menu de administração dinâmico com dropdown de plugins (`AdminNav`).
  - [x] **Primeiro Plugin Oficial (`webhook-sender`):** Disparo de webhooks para múltiplos servidores (Discord, Slack, n8n, Zapier) na publicação de matérias, com tabela relacional própria (`plugin_webhook_sender_logs`), integração atômica com agendamento de posts, resolução canônica em CLI/cron e painel de controle com testes e reenvio.

* **FASE ATUAL (Em Andamento) — Opção 3: Migração de Acervo do WordPress (`bin/migrate-wp.php`):**
  - [ ] **Importador CLI Transacional em Lotes:** Extração segura de posts, páginas, categorias, tags e autores de instalações legadas do WordPress.
  - [ ] **Processamento de Mídia em Lote:** Download/leitura local de `wp-content/uploads/` e conversão automática para o pipeline WebP/AVIF com substituição de links no conteúdo.
  - [ ] **Preservação de SEO e Redirecionamentos 301:** Mapeamento de todos os permalinks antigos para a tabela `redirects` garantindo integridade de ranqueamento no Google.
  - [ ] **Modo Simulação (`--dry-run`):** Execução com validação prévia e relatório de homologação antes da confirmação da migração.

---

## 11. Metas Mensuráveis e Critérios de Aceitação

### 11.1. Ambiente e Protocolo de Referência

Referência inicial: servidor Linux com 2 vCPU, 4 GiB de RAM e SSD, executando Nginx, PHP-FPM e banco no mesmo host. Registrar modelo/limites de CPU, armazenamento, versões exatas, configuração de processos, OPcache e JIT, índices e tamanho dos dados. OPcache é obrigatório no benchmark; JIT só é habilitado se medições demonstrarem benefício. O gerador de carga fica em outra máquina na mesma rede e registra latência de rede.

Usar pelo menos 50.000 artigos com distribuição documentada de autores, categorias, tamanho dos blocos e mídia. Executar cenários separados de cache aquecido, cache vazio, bypass e upload, com aquecimento e depois 10 minutos de medição. Registrar taxa oferecida e efetivamente concluída, concorrência, p50/p95/p99, erros, CPU, memória e consultas. Não apresentar capacidade de cache HIT como capacidade do PHP ou do banco.

As metas abaixo são **objetivos iniciais de aceitação, ainda não medidos**. Ajustes exigem relatório e revisão explícita deste documento; não devem ser tratados como garantias universais de hardware ou tráfego.

### 11.2. Orçamentos Iniciais

| Área | Objetivo / verificação |
| :--- | :--- |
| Cache HIT | p95 de TTFB até 50 ms em 200 req/s, com até 50 conexões simultâneas no ambiente de referência |
| HTML sem cache | p95 de TTFB até 250 ms em 10 req/s, com até 10 conexões simultâneas; medir artigo, home e categoria separadamente |
| Erros sob carga prevista | Menos de 0,1% de respostas 5xx ou timeouts; registrar 429 separadamente e não usá-los para ocultar falta de capacidade |
| Memória PHP comum | Pico de alocação PHP até 64 MiB por requisição, excluindo conversão de imagens; registrar também RSS dos processos |
| Editor | Soma de todos os arquivos JS necessários até 50 KiB gzip, inclusive bibliotecas e módulos carregados depois; informar tamanho sem compressão também |
| Consultas | Medir duração e plano com `EXPLAIN` para artigo, listagens e contador; listar índices usados e investigar leituras desproporcionais ao resultado |
| Mídia | Não exceder os limites de processo/concorrência da seção 4.4; registrar tempo e pico de memória para entradas pequenas, próximas do limite e inválidas, com/sem AVIF |
| Propagação | Primeira purga tentada após commit na mesma operação; conclusão só após protocolo da seção 7.2; medir p95 do intervalo commit → verificação final e registrar separadamente retentativas |
| Páginas secundárias | Defasagem máxima ordinária de 5 minutos, com tratamento específico para retirada urgente |

Para páginas no navegador, registrar LCP e demais métricas com dispositivo, rede, cache de navegador e tamanho das imagens definidos. O contador e a imagem principal devem ser avaliados nesse cenário, além dos testes de backend.

### 11.3. Verificações Funcionais e de Segurança

* **Editorial:** Rascunho é o padrão; publicar requer perfil autorizado; revisão pendente não aparece ao público nem muda data/HTML públicos; aplicar revisão é transacional; conflito de versão retorna 409 sem perda silenciosa.
* **Autorização:** Autor não lê prévia nem edita artigo de outro autor; não promove perfil, muda autoria ou publica por chamada direta; sessões bloqueadas/rebaixadas perdem acesso na próxima requisição.
* **CSRF e contador:** Mutações administrativas sem token são rejeitadas; contador funciona sem sessão apenas com origem permitida; ausência de evidências de origem e origem hostil não incrementam. Deduplicação concorrente produz exatamente um incremento na janela por IP/artigo.
* **Mídia:** Testar AVIF sem decoder, encoder ausente/falho, arquivo vazio apesar de retorno positivo, corrupção, dimensões excessivas, timeout e falha de disco. Nunca referenciar variante ausente nem anunciar sucesso sem WebP completo.
* **Cache:** Cumprir a seção 7.4 e verificar TTLs efetivos no servidor; nenhuma prévia ou sessão pode vazar para visitantes anônimos.
* **Migração:** Reexecução não duplica nem zera estatísticas; pendências ficam privadas; JSON/HTML e mídia são consistentes; datas e autores são conciliados; redirecionamentos preservam links e não criam ciclos.
* **Recuperação:** Validar restauração de backup e retomada de jobs/checkpoints após interrupção. Limpeza periódica não remove mídia referenciada nem trabalhos pendentes.

---

## 12. Referências Técnicas

* [MySQL: inicialização e atualização automática de datas](https://dev.mysql.com/doc/refman/8.0/en/timestamp-initialization.html).
* [Nginx: FastCGI cache, chave, bypass, armazenamento e locking](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html).
* [OWASP: autorização e validação por requisição](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).
* [OWASP: CSRF, Fetch Metadata e validação de origem](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).
* [OWASP: validação e limites de uploads](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).
* [PHP: capacidades do GD](https://www.php.net/manual/en/function.gd-info.php), [leitura AVIF](https://www.php.net/manual/en/function.imagecreatefromavif.php) e [geração AVIF e ressalva sobre retorno](https://www.php.net/manual/en/function.imageavif.php).
* [WordPress: parser oficial de blocos](https://developer.wordpress.org/reference/functions/parse_blocks/).
