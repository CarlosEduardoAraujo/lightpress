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
| `media_variants` | Mapeamento direto B-Tree O(1) de URLs de variantes para `media_id`; elimina buscas com `LIKE '%...%'` sobre JSON e viabiliza resolução instantânea (< 0.1ms) com integridade referencial em cascata |
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
* **Nomenclatura Semântica Amigável para SEO e Detecção Abrangente de Colisões:** Ao invés de hashes criptográficos aleatórios que prejudicam o ranqueamento no Google Imagens, os arquivos finais preservam com fidelidade o nome original sanitizado (slug) enviado pelo usuário (ex: `dodge-retorna-nascar-cup-series-2027-kaulig-racing-1024.webp`). O loop de detecção de colisão verifica proativamente todas as variantes e resoluções geradas (`.webp`, `.avif`, `-1024.webp`, `-1024.avif`, `-thumb.webp`, `-thumb.avif`, `-1920.webp` e `-1920.avif`), garantindo que uploads com sufixos homônimos (ex: `foto-thumb.png` seguido de `foto.png`) nunca colidam ou sobrescrevam miniaturas ou imagens de outros usuários, incrementando sufixos numéricos (`-1`, `-2`). Travas de segurança pré-`rename()` abortam com HTTP 409 caso o destino já exista fisicamente no disco.
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
* **Área de Arrastar e Soltar (Drag & Drop Zone):** Envio ágil de imagens arrastando arquivos diretamente para o topo da biblioteca com feedback visual instantâneo (`.dragover`), suporte a múltiplos arquivos sequenciais e atualização dinâmica da galeria via AJAX.
* **Paginação Fluida Assíncrona:** Grade configurada para 48 itens por página (harmônica para layouts responsivos de 2, 3, 4 e 6 colunas) com navegação numerada instantânea via AJAX.
* **Ordenação Flexível:** Seletor de ordenação com padrão decrescente (recentes primeiro), permitindo alternar entre mídias recém-enviadas e ordem cronológica ascendente (antigas primeiro).
* **Busca Global Integrada no Editor de Notícias (`public/admin/editor.php`):** O modal de seleção de fotos no editor de matérias consulta a mesma API assíncrona (`/admin/api/media.php?q=...`), permitindo que repórteres e editores pesquisem instantaneamente qualquer foto do acervo completo enquanto escrevem.

### 4.9. Resolução de `alt`/`title`, vínculos de mídia e cache

A Home (destaques e feed), categorias, tags, busca, notícias relacionadas e imagens principais institucionais resolvem a descrição por `ImageProcessor::getImageDescription()`. A imagem principal da notícia também usa esse resolvedor, passando `posts.featured_media_id` quando disponível. Nos templates de capas e miniaturas, `alt` e `title` recebem a mesma descrição; não são preenchidos pelo título da notícia nem por um campo independente de título da mídia.

**Prioridade dos textos:** `media.alt_text` → `media.caption` → nome amigável do arquivo → vazio. Textos contendo somente espaços não impedem o próximo fallback. O nome amigável remove extensão e sufixos reconhecidos de tamanho, substitui hífens/sublinhados por espaços e descarta alguns nomes genéricos. Na imagem principal da notícia e nos relacionados, uma descrição vazia mantém `alt=""` e omite `title`; nas demais capas/listagens, ambos podem ser emitidos vazios.

**Localização da mídia:** o caminho da URL, sem host, query string e barra inicial, é consultado por igualdade em `media_variants.url_path`. Um ID fornecido é priorizado quando seu manifesto confirma pasta e nome base compatíveis. Quando não há vínculo exato, a recuperação consulta nomes completos com extensão (`jpg`, `jpeg`, `png`, `webp`, `avif`, `gif`), incluindo candidatos sem sufixo de tamanho, e exige correspondência no manifesto. Homônimos ambíguos não são escolhidos arbitrariamente. Essa recuperação substitui a comparação incorreta entre `original_name` com extensão e um nome sem extensão.

**Carregamento em lote:** `preloadDescriptions()` compartilha o resolvedor da consulta individual e processa até 100 caminhos por lote. Os vínculos exatos usam consulta agregada `WHERE url_path IN (...)`; nomes e IDs de recuperação também são consultados em lote quando necessários. Isso mantém os mesmos critérios na Home e na página da notícia.

**Cache e atualização:**

- A memória da requisição memoriza inclusive fallbacks e resultados vazios.
- O cache persistente de descrições usa o grupo `media`, namespace `media_desc:v2` e TTL de 24 horas. A versão ignora valores antigos; ausência de vínculo e falhas de banco não persistem o fallback.
- A imagem principal resolve os metadados após carregar o post. O antigo `$post['image_alt']` armazenado no cache editorial deixa de prevalecer.
- Salvar metadados manualmente ou gerar descrições por IA limpa a memória e os grupos `media` e `posts`, identifica páginas afetadas e registra um job de purga. Os destinos incluem Home/paginação, categorias/paginação, notícias da categoria que podem mostrar relacionados, tags/paginação e páginas institucionais identificadas.
- Até 30 URLs são purgadas também na requisição; lotes maiores ficam com `bin/retry-cache-purge.php`. Seu cron deve permanecer ativo. Falhas ou exceções de purga Cloudflare são retornadas como falha pelo `CacheManager`, permitindo a retentativa da fila.
- URLs de busca com query strings arbitrárias já em cache dependem de expiração ou purga explícita. A publicação inicial também exige invalidar o HTML antigo; mudar o namespace de objetos não remove páginas já armazenadas no Nginx/Cloudflare.

**Imagens no corpo do conteúdo:** os atributos permanecem no HTML salvo. A inserção pelo editor usa o texto alternativo revisado ou cadastrado para `alt`, e esse texto ou a legenda para `title`. Blocos JSON seguem sua própria prioridade (`block.alt` → `block.caption` → descrição da URL). Esta correção não reescreve o HTML editorial nem sincroniza automaticamente imagens antigas do corpo.

**Diagnóstico e reparo de vínculos:**

```bash
# Padrão: relatório JSON sem INSERT/UPDATE e sem carregar o bootstrap editorial
php bin/audit-media-links.php

# Após revisar o relatório: adiciona variantes inequívocas e preenche IDs ausentes
php bin/audit-media-links.php --apply
```

O diagnóstico compara manifestos, `media_variants` e IDs ausentes em posts. O reparo não cria tabelas, não sobrescreve vínculos conflitantes ou IDs preenchidos e não purga caches. Conflitos exigem revisão manual. O utilitário anterior `bin/migrate-media-variants.php` continua disponível para migração/backfill.

**Testes de regressão isolados:**

```bash
php bin/test-image-descriptions.php
php bin/test-cache-purge-results.php
```

As 27 verificações (24 de resolução/invalidação e 3 de resultado de purga) usam SQLite em memória e simulações de cache/purga, sem carregar credenciais locais ou acessar serviços de produção. Requerem PHP 8.4+, PDO SQLite e mbstring. Cobrem JPG → AVIF sem vínculo, resolução em lote, normalização da URL, cache antigo, espaços no alt, homônimos, ID compatível, atualização dos metadados e falhas de banco/Cloudflare.

O procedimento de publicação, diagnóstico, reparo e verificação com cache MISS/HIT está em [docs/IMAGE-METADATA-FIX.md](docs/IMAGE-METADATA-FIX.md). A implementação e os testes locais não representam publicação nem reparação já executada em produção.

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
│   ├── ObjectCache.php        # Camada defensiva de cache de objetos em memória com fallback transparente
│   ├── PluginManager.php      # Gerenciador central de plugins e ciclo de vida
│   └── AdminNav.php           # Menu superior unificado do painel administrativo com submenu de plugins
├── plugins/                   # Plugins isolados fora da raiz pública
│   ├── ai-copilot/            # Plugin oficial de IA para linkagem interna e visão computacional
│   ├── redis-cache-pro/       # Plugin empresarial de cache persistente em memória RAM com PhpRedis
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
    │   ├── header.php         # Cabeçalho unificado compartilhado, marca dinâmica e estilos globais
    │   ├── footer.php         # Rodapé unificado compartilhado com alternância de tema
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
* **Suporte a Páginas Institucionais e Notícias em `view_dedupe`:** A coluna `post_id` é do tipo `BIGINT NOT NULL` (assinado) e desacoplada de chave estrangeira estrita para acomodar simultaneamente matérias (IDs positivos com rotas `/api/view/{id}`) e páginas institucionais (IDs negativos com rotas canônicas `/api/view/page/{id}`) sem colisões de chave nem violação referencial.
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

Uma categoria principal por artigo na primeira fase. Seu nome e slug são definidos em catálogo controlado por editor/administrador, mantido fora das requisições públicas de escrita; não gerar a identidade de uma categoria livremente a cada salvamento. O campo `category` guarda o slug canônico indexável, com padrão `geral`; o catálogo fornece o nome de exibição. Mudanças de slug exigem atualização dos artigos afetados, redirecionamentos e invalidação correspondente. A taxonomia oficial de navegação adota slugs canônicos padronizados: `formula-1`, `formula-2`, `formula-e`, `indycar`, `stockcar`, `nascar`, `endurance` (cobrindo FIA WEC, 24 Horas de Le Mans e IMSA) e `motovelocidade`.

`tags` contém uma lista normalizada separada por vírgulas, sem vírgulas dentro de cada tag, com limite total de 255 caracteres. Essa representação serve à exibição; pesquisa e arquivos públicos por tag ficam fora da primeira fase e exigirão modelagem relacional própria se adicionados. Busca interna também fica fora do escopo inicial até definir requisitos e índices. Essa decisão evita usar `LIKE '%tag%'` como estratégia de escala.

---

## 7. Estratégia de Cache, Invalidação e Permissões (Nginx FastCGI)

A entrega de uma página pública anônima já armazenada não executa a aplicação. O cache é permitido somente para rotas e respostas explicitamente elegíveis; login e APIs nunca dependem dele.

### 7.1. Elegibilidade, Rotas e Chave Canônica

Rotas públicas iniciais: `/`, `/page/{n}/`, `/noticia/{slug}/`, `/categoria/{slug}/`, `/categoria/{slug}/page/{n}/`, `/feed.xml` (e `/feed/`), `/categoria/{slug}/feed.xml` (e `/categoria/{slug}/feed/`), `/sitemap.xml`, `/sitemap-main.xml`, `/sitemap-posts-{part}.xml` e `/sitemap-news.xml`. A paginação inicia em 2; página 1 redireciona para a listagem principal. O `/sitemap.xml` gera um `<sitemapindex>` padrão particionado (5.000 URLs por arquivo em `/sitemap-posts-{part}.xml` e páginas/categorias em `/sitemap-main.xml`), suportando a indexação de mais de 50.000 artigos sem sobrecarga de memória ou timeout. Slugs são validados e únicos no seu escopo. Host, HTTPS e política de barra final são canônicos e aplicados antes do cache. URLs legadas são tratadas pela migração, sem mudar silenciosamente os links antigos.

| Condição | Política |
| :--- | :--- |
| GET/HEAD anônimo, rota pública permitida, sem query string e resposta 200 | Pode ler e gravar cache |
| `/admin/`, login, logout, ativação, redefinição, prévias e `/api/` | Nunca ler nem gravar cache |
| Cookie de sessão `LIGHTPRESSSESSID`, mesmo inválido, ou cabeçalho `Authorization` | Nunca ler nem gravar cache |
| Método diferente de GET/HEAD, query string ou rota não prevista | Nunca ler nem gravar cache |
| Resposta com `Set-Cookie`, indicação `private`/`no-store`/`no-cache` ou status diferente de 200 | Nunca gravar cache |

Requisições públicas elegíveis não iniciam sessão e não emitem cookies. A chave de cache FastCGI é normalizada utilizando a `$uri` canônica (sem query string) para as rotas públicas de conteúdo. Dessa forma, variações de URLs contendo parâmetros analíticos de campanhas (`?utm_source=...`, `?fbclid=...`, `?gclid=...`) compartilham exatamente a mesma entrada física de cache, permitindo que a purga de URLs canônicas invalide instantaneamente todas as cópias do artigo no disco. Consultas dinâmicas que alteram o resultado (`?q=` e `?s=`) continuam forçando o bypass completo do cache (`$lp_skip_cache = 1`).

Trecho de referência para a chave (não é um virtual host completo):

```nginx
# Contexto http: HEAD compartilha a entrada GET.

map $request_method $lp_cache_method {
    default $request_method;
    HEAD GET;
}

# Normalização da chave: extrai o caminho original antes do try_files para /index.php sem query string
map $request_uri $lp_request_path {
    ~*^(?<path>[^?]+) $path;
    default $request_uri;
}

fastcgi_cache_path /var/cache/nginx/lightpress levels=1:2 keys_zone=LIGHTPRESS:100m inactive=60m max_size=5g;
fastcgi_cache_key "$scheme$lp_cache_method$host$lp_request_path";

# Contexto location FastCGI: $lp_skip_cache deve ser definido por maps

# que implementem TODAS as exclusões de requisição da tabela acima.

fastcgi_cache LIGHTPRESS;
fastcgi_cache_methods GET HEAD;
fastcgi_cache_bypass $lp_skip_cache;
fastcgi_no_cache $lp_skip_cache $upstream_http_set_cookie;
fastcgi_cache_lock on;
fastcgi_cache_lock_timeout 5s;
fastcgi_cache_use_stale error timeout updating invalid_header http_500 http_503;
fastcgi_cache_background_update on;
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
| Artigo recente (publicado/editado até 24h) | 1 dia (86.400s) | Purga atômica em alteração ou despublicação; renova para 1 dia |
| Artigo de acervo (publicado há mais de 24h) | 10 dias (864.000s) | Congelado em disco/RAM; purga atômica imediata em alteração |
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
* **Mitigação de LIKE Injection (Wildcard Escaping):** Todas as consultas dinâmicas que utilizam o operador `LIKE` realizam o escape prévio de caracteres curinga (`%`, `_` e `\`) através de `Security::escapeLike()` (`core/Security.php`), prevenindo varreduras completas no banco (Full Table Scan / DoS) ou cruzamento indevido de mídias com caracteres especiais.
* **Normalização de Parâmetros no PDO Wrapper (`Database::query`):** Suporte nativo tanto a parâmetros posicionais (`?`) quanto nomeados associativos (`['id' => 1]`), prefixando automaticamente os dois pontos (`:`) exigidos pelo `PDO::bindValue()`.

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
* **Escape Seguro em Interfaces JavaScript e Editores:** A função `escapeHtml()` utilizada nos editores administrativos (`editor.php` e `page-edit.php`) realiza o escape de todos os caracteres críticos (`&`, `<`, `>`, `"`, `'`). A inserção assíncrona de tags `<img>` via modal de mídia aplica `escapeHtml()` obrigatoriamente tanto no `src` quanto no `alt`/`figcaption`, garantindo integridade contra injeção contextual em atributos.
* **HTML compilado:** Somente `content_html` produzido pelo compilador e armazenado pelo fluxo autorizado pode ser renderizado como HTML sem passar por `e()` novamente. JSON-LD e dados inseridos em scripts usam serialização JSON apropriada ao contexto, não apenas escape HTML. A migração obedece à mesma fronteira de confiança.

### 8.3. Proteção Contra Cross-Site Request Forgery (CSRF)

* Toda operação mutacional administrativa, inclusive login, logout, uploads, publicação e gestão de usuários, exige token CSRF gerado por `bin2hex(random_bytes(32))`, armazenado na sessão e validado com `hash_equals()`. Formulários usam campo oculto; APIs do admin exigem `X-CSRF-Token`. GET/HEAD nunca executam essas mutações.
* **Logout e Destaques via POST:** O encerramento de sessão (`logout`) e a alternância de destaques na Home (`toggle_featured_id`) são realizados estritamente via requisições `POST` com tokens em campos ocultos (`<input type="hidden">`), eliminando qualquer exposição de tokens CSRF em URLs, histórico de navegação, logs de servidores web (`access.log`) ou cabeçalhos `Referer`.
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
* **Proteção contra Força Bruta com Locks Concorrentes:** Validação de proxies reversos confiáveis (Cloudflare, localhost) em `Security::getClientIp()` antes de confiar em cabeçalhos como `X-Forwarded-For` ou `CF-Connecting-IP`. Limitação estrita de tentativas de login baseada no IP real do cliente (`Security::checkLoginRateLimit()` e `Security::recordLoginFailure()`), sincronizada com travas de arquivo `flock()` (`LOCK_SH` para leituras seguras e `LOCK_EX` para escrita atômica), neutralizando race conditions de ataques concorrentes paralelos que tentem corromper ou resetar o arquivo de rate limit.
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
| Excluir definitivamente artigos | Não | Apenas artigos de sua própria autoria | Sim |

* Centralizar as decisões em `Authorization.php`, por exemplo `can($user, $action, $post)`, com negação por padrão. Verificar todas as requisições e operações de serviço, incluindo AJAX, prévias, upload e aplicação de revisões.
* IDs recebidos nunca provam propriedade. Conferir `posts.user_id` e validar campos permitidos por perfil; um autor não pode forjar `user_id`, `status`, destaque ou privilégios no JSON. O vínculo de uma imagem a um artigo também exige autorização sobre ambos.
* Contas novas e importadas recebem `author` por padrão. Promoções são explícitas e administrativas. Impedir remoção, desativação ou rebaixamento do último administrador ativo.
* **Proteção Atômica do Último Administrador:** O sistema valida ativamente `COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?` atomicamente dentro de `Database::transaction()` com bloqueio pessimista `FOR UPDATE` (no MySQL), tanto na alteração de perfil/papel (`action=edit`) quanto na alternância de bloqueio (`toggle_id`), prevenindo condições de corrida onde dois administradores tentem rebaixar ou desativar um ao outro concorrentemente.
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
* **Bloqueio de Execução no Apache / LiteSpeed:** O diretório `/public/uploads/` conta com arquivo dedicado `.htaccess` contendo bloqueio estrito via `<FilesMatch "\.(php|phtml|phar|pl|py|cgi|sh)$"> Require all denied </FilesMatch>` (compatível com Apache 2.2 e 2.4+), isolando a proteção aos uploads sem impactar o front-controller da raiz pública.
* Arquivos são armazenados com extensões padronizadas restritas (`.webp`, `.avif`) e nomes semânticos sanitizados preservados para SEO com detecção completa de colisão de variantes.
* **Exclusão Segura contra Path Traversal:** Ao excluir imagens e variantes na biblioteca de mídia (`public/admin/media.php`), a aplicação resolve o caminho canônico com `realpath()` e garante a terminação com barra separadora (`/`) no diretório base (`$baseUploadDir = rtrim(..., '/') . '/'`), além de exigir `is_file()` antes de invocar `unlink()`. Isso impede que diretórios irmãos de mesmo prefixo (ex: `/public/uploads_backup/`) ou tentativas de navegação arbitrária via `/../` contornem a proteção.

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

# Content Security Policy (CSP) base sem 'unsafe-inline' (scripts estáticos em /assets/js/)

add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self';" always;
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

### 8.9. SEO Dinâmico, Meta Tags Avançadas de Notícias e Compartilhamento Social (`core/Seo.php`)

Para maximizar a visibilidade editorial no Google Notícias, Google Discover e garantir compatibilidade universal com redes sociais (WhatsApp, X/Twitter, Facebook, LinkedIn):

* **Diretivas Avançadas de Indexação para Notícias:**
  - `<meta name='robots' content='index, max-snippet:-1, max-image-preview:large, max-video-preview:-1, follow' />`: Instrução explícita autorizando snippets sem truncamento e prévia em tamanho grande no Google Discover e resultados de pesquisa.
  - `<meta name="google" content="nositelinkssearchbox" >`: Desativa a caixa de pesquisa interna nos sitelinks do Google para artigos individuais.
* **Canonicalização e Twitter Cards:**
  - `<link rel="canonical" href="LINKDANOTÍCIA" />` e `<meta name="twitter:url" content="LINKDANOTÍCIA" />`: Vinculam formalmente a URL canônica com barra final (`/{slug}/`).
* **SEO Nativo vs Resumo com Prevenção de Duplicação:**
  - `<meta name="description" content="...">`: Consome prioritariamente o campo nativo `seo_description`. Caso vazio, utiliza automaticamente o subtítulo/resumo (`summary`). O `templates/header.php` condiciona a meta description global apenas a páginas não-artigo, prevenindo duplicatas no `<head>`.
* **Palavra-chave Principal (Focus Keyword):**
  - `<meta name="keywords" content="...">`: Renderiza o campo `focus_keyword` cadastrado no painel com fallback transparente para o campo `tags`.
* **Otimização de Imagens e Dimensões Físicas:**
  - **Dimensões Físicas (`og:image:width` e `og:image:height`):** O método estático `Seo::getImageDimensions()` inspeciona localmente o arquivo físico com `@getimagesize()` (com fallback para 1200x675 px na proporção 16:9).
  - **Imagem Segura WebP (`og:secure_url` e `og:image`):** Garante entrega com scheme seguro `https://` e conversão obrigatória para WebP (`ImageProcessor::toWebpUrl()`).
  - **Miniatura AVIF de Próxima Geração (`<meta name="thumbnail">`):** Aponta diretamente para a variante `.avif` de alta compressão (`ImageProcessor::toAvifUrl()`).
* **Home, Categorias e Tags:** As tags `<meta property="og:image">` e `<meta name="twitter:image">` consomem dinamicamente o Favicon configurado no portal em WebP.

#### 8.9.1. Feeds RSS 2.0 Enriquecidos e Google News XML Sitemap (`/feed.xml`, `/categoria/{slug}/feed.xml`, `/sitemap-news.xml`)

Para indexação em tempo real pelo Googlebot-News e integração direta com a Central do Editor (**Google Publisher Center**), o sistema fornece um pipeline completo de feeds semânticos e sitemaps dedicados:

* **Feed RSS Geral (`/feed.xml`, `/feed`, `/feed/`):**
  - **Namespaces XML Completos:** `xmlns:content="http://purl.org/rss/1.0/modules/content/"`, `xmlns:media="http://search.yahoo.com/mrss/"`, `xmlns:dc="http://purl.org/dc/elements/1.1/"` e `xmlns:atom="http://www.w3.org/2005/Atom"`.
  - **Imagens e Enclosures:** Imagem de destaque vinculada com compressão WebP em `<enclosure url="..." length="0" type="image/webp" />` e `<media:content medium="image" type="image/webp">`, permitindo que o aplicativo do Google Notícias e agregadores exibam capas ricas em alta resolução.
  - **Metadados Estruturados por Item:** Autor do artigo em `<dc:creator>`, categoria editorial em `<category>`, resumo protegido em CDATA em `<description>` e corpo editorial completo compilado em `<content:encoded><![CDATA[...]]></content:encoded>`, permitindo renderização imediata do conteúdo em leitores sem requisições adicionais.
  - **Padrão RFC 822:** Datas formatadas rigorosamente via `date(DATE_RSS, ...)` e `<guid isPermaLink="true">` apontando para a URL canônica da notícia.
* **Feeds Específicos por Categoria Editorial (`/categoria/{slug}/feed.xml`, `/categoria/{slug}/feed`, `/categoria/{slug}/feed/`):**
  - **Seções Segmentadas no Google Publisher Center:** Permite cadastrar abas e editorias independentes na Central do Editor (ex.: Fórmula 1, Endurance, MotoGP, Stock Car).
  - **Validação de Banco e Proteção 404:** Consulta atômica no banco de dados para checagem da categoria; categorias inexistentes ou inativas respondem com HTTP 404 limpo.
  - **Isolamento de Conteúdo:** O canal filtra exclusivamente os últimos 50 artigos publicados daquela categoria (`WHERE category = ? AND status = 'published'`), com título contextualizado (`{Categoria} - {Nome do Portal}`).
  - **Autodescoberta de Feeds (`templates/header.php`):** Injeção dinâmica no `<head>` de `<link rel="alternate" type="application/rss+xml" ...>` apontando para o feed geral na Home e páginas comuns, e apontando diretamente para o feed da categoria quando o leitor navega em `/categoria/{slug}/`.
* **Google News XML Sitemap Oficial (`/sitemap-news.xml`):**
  - **Janela Estrita de 48 Horas:** Atende com precisão cirúrgica às diretrizes obrigatórias do Googlebot-News (`published_at >= NOW() - 48h`). Matérias com mais de 48 horas deixam automaticamente o sitemap de notícias, permanecendo indexadas no sitemap mestre particionado (`/sitemap-posts-{part}.xml`).
  - **Namespaces Oficiais:** `xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"` e `xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"`.
  - **Estrutura de Notícia:** Tags `<news:news>`, `<news:publication>` (com `<news:name>` e `<news:language>pt-br</news:language>`), `<news:publication_date>` no padrão W3C/ISO 8601 (`date('c')`), `<news:title>` e `<image:image>` contendo `<image:loc>` apontando para a variante WebP da imagem de destaque.
  - **Priorização no Índice Mestre:** O `/sitemap-news.xml` é referenciado no topo do `<sitemapindex>` em `/sitemap.xml`, garantindo descoberta prioritária pelo Googlebot.
* **Desempenho e Cache FastCGI:** Todos os endpoints XML operam com cabeçalho de cache de 5 minutos (`X-Accel-Expires: 300`), assegurando resposta instantânea (TTFB < 50 ms) sob tráfego massivo.
* **Suíte de Testes Automatizados (`bin/test-feeds-news.php`):** 26 asserções automatizadas validando sintaxe via SimpleXML, integridade dos namespaces, enclosures WebP, filtro cronológico de 48h, isolamento de posts por categoria e status 404.

### 8.10. Módulo de Páginas Institucionais (`/admin/pages.php`, `/admin/page-edit.php`, `templates/page.php`)

Páginas estáticas corporativas (ex: *Nossa Equipe Editorial*, *Contato / Fale Conosco*, *Termos de Uso*, *Política de Privacidade*, *Código de Ética*) são gerenciadas isoladamente na tabela `pages`:
* **URLs Amigáveis na Raiz:** Servidas diretamente em `/{slug}/` com verificação transparente de colisão com notícias e palavras reservadas do sistema (`admin`, `api`, `uploads`, etc.).
* **Métricas de Leitura:** Contabilização atômica de visualizações (`views`) na tabela de páginas.
* **Auto-migração de Slugs:** O esquema migra automaticamente slugs legados (ex: `quem-somos` para `nossa-equipe`) mantendo links internos íntegros.
* **Editor Visual Moderno em Paridade com o Editor de Notícias (`/admin/page-edit.php`):**
  - **Canvas Visual Direto (`contenteditable`):** Escrita fluida e contínua com suporte a alternância em tempo real para código-fonte `</> HTML`.
  - **Limpador de Ctrl+V (Docs/Word):** Interceptação do evento de colagem com sanitização e descarte automático de spans inline, estilos proprietários do Office (`MsoNormal`) e wrappers de GUID do Google Docs.
  - **Barra de Ferramentas Rica Sticky (`top: 0px`):** Títulos de seção (H2, H3), parágrafos (P), negrito, itálico, sublinhado, listas com marcadores e numeradas, citações em destaque, links e atalho para a biblioteca de mídia.
  - **Modal de Mídias Estilo WordPress:** Seleção e upload de imagens com geração imediata de WebP/AVIF, prévia 16:9 de imagem de destaque com botão de remoção rápida (`✕ Remover Imagem`) e inserção semântica (`<figure>`, `<figcaption>`).
  - **Publicação Ágil e SEO Nativo:** Botão de ação vermelha oficial (`#E10600`) em largura total, atalhos de rascunho, badges de status (`✓ Publicada no Ar` com link direto `Ver ↗`) e campos dedicados de Meta Title e Meta Description.

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

#### 8.13.2. Segundo Plugin Oficial: AI Copilot (`plugins/ai-copilot/`)

O segundo módulo da plataforma adiciona assistência de Inteligência Artificial generativa e multimodal de alto nível para a redação, preservando integralmente os pilares do LightPress:

* **Zero Impacto no Frontend Público:** A execução de recursos de IA ocorre exclusivamente em rotas administrativas autenticadas (`/admin/`). Os leitores anônimos continuam recebendo 100% de cache FastCGI em HTML puro.
* **Grounding Estrito (Anti-Alucinação de Links):** A IA jamais inventa URLs ou títulos. O banco de dados MySQL do LightPress é a única fonte da verdade para matérias existentes; a IA atua apenas como curadora semântica dos resultados reais retornados pela busca determinística FULLTEXT.
* **Cliente HTTP Multiprovedor Leve (`src/AiClient.php`):** Motor cURL nativo com zero dependências externas, timeout rígido (8s padrão), parser tolerante a markdown e suporte a:
  - **Google Gemini API** (ex: `gemini-2.5-flash`);
  - **OpenAI API** (ex: `gpt-4o-mini`);
  - **Endpoints Locais / Compatíveis com OpenAI** (Ollama, vLLM ou automações n8n na rede local privada RFC 1918, com blindagem estrita contra SSRF e metadados de nuvem).
* **Precedência e Privacidade de Chaves de API:** Suporte prioritário a constantes em `config.local.php` (ex: `AI_COPILOT_GEMINI_API_KEY`, invisíveis no banco) com fallback seguro para persistência na tabela `settings` com mascaramento visual (`••••`).
* **Módulo 1: Linkagem Interna Inteligente (SEO Interno):**
  - **Pré-Filtragem Determinística FULLTEXT:** Extração de sementes semânticas a partir do título, tags e categoria, consultando o índice `idx_fulltext_search (title, summary, tags)` no MySQL para selecionar de 10 a 15 matérias candidatas reais publicadas.
  - **Pareamento e Ancoragem Semântica:** A IA seleciona os 2 a 4 artigos mais aderentes e indica o texto âncora literal (2 a 6 palavras) e a chamada para bloco "Leia Também".
  - **Validação de Substring Anti-Alucinação:** O backend e o JavaScript validam se o trecho sugerido existe de forma literal no texto (`mb_stripos`). Se falhar, o sistema converte automaticamente para o formato "Leia Também".
  - **Aplicação em 1 Clique:** O editor visual insere o link `<a>` diretamente no `#editorCanvas` via manipulação segura de nós de texto no DOM ou insere o bloco `<p class="post-related-link">`.
* **Módulo 2: Visão Computacional no Catálogo de Mídias:**
  - **Economia Extrema de Tokens:** Em vez de enviar fotos originais pesadas de 8 MiB, o sistema envia a variante `intermediate` (1024px WebP, ~25 a 50 KiB) gerada pelo `ImageProcessor`, consumindo o mínimo de tokens visuais.
  - **Prompt Especializado em Automobilismo:** Retorna metadados em português do Brasil:
    - `alt_text`: Descrição objetiva para acessibilidade e Google Imagens (até 125 caracteres);
    - `caption`: Legenda jornalística contextualizada para `<figcaption>` (até 250 caracteres);
    - `ai_tags`: Palavras-chave visuais e técnicas separadas por vírgula (ex: "fórmula 1, ferrari, chuva, interlagos").
  - **Upload Não-Bloqueante:** O enriquecimento automático ocorre em background via JavaScript após a conclusão do upload físico, evitando timeouts e lentidão no Nginx/PHP-FPM.
  - **Busca por Conteúdo Visual em 30.000+ Fotos:** A consulta na biblioteca (`media.php` e `api/media.php`) pesquisa em `alt_text`, `caption` e `ai_tags`, permitindo localizar qualquer foto pelo que aparece nela (ex: buscando "chuva" ou "podio").
* **Auto-Migração Idempotente:** A ativação do plugin adiciona automaticamente as colunas `alt_text`, `caption` e `ai_tags` na tabela `media` tanto em MySQL quanto em SQLite.
* **Painel de Configurações Interativo:** Tela em `/admin/plugin-settings.php?plugin=ai-copilot` com botão **"⚡ Testar Conexão com a IA"** para checagem imediata de latência e conectividade com a API configurada.

#### 8.13.3. Terceiro Plugin Oficial: Redis Cache Pro (`plugins/redis-cache-pro/`)

O terceiro módulo corporativo do ecossistema LightPress introduz um motor de cache de objetos persistente em memória RAM com arquitetura de alta resiliência, baixa latência e concorrência extrema:

* **Desempenho C-Driver Nativo via PhpRedis:** Comunicação direta em nível de kernel sem overhead de bibliotecas PHP interpretadas, com suporte unificado a TCP, Unix Domain Socket, Sentinel e Cluster.
* **Invalidação Atômica em $O(1)$ (Sem `FLUSHDB` ou `KEYS *`):**
  - **Invalidação Global por Namespace:** Ao solicitar a limpeza do cache da aplicação, o motor gera e persiste instantaneamente um novo hash de geração em `lp_cache:global:version`. Todo o acervo anterior torna-se inacessível em microssegundos sem travar o servidor Redis.
  - **Invalidação Granular por Grupo:** Cada agrupamento de dados (`posts`, `settings`, `categories`, `users`, `transient`) possui versionamento próprio em `group:{group}:version`. Purgar o grupo de notícias não afeta configurações ou termos já aquecidos em memória.
  - **Desalocação Assíncrona com `UNLINK`:** Liberação de memória não-bloqueante no kernel do Redis, eliminando pausas na CPU causadas pelo comando `DEL` tradicional sobre objetos volumosos.
* **Disjuntor de Alta Resiliência (Circuit Breaker):**
  - Monitora falhas consecutivas de comunicação (timeout, recusa de conexão ou indisponibilidade).
  - Em 3 falhas seguidas, o disjuntor trip-out para o estado `OPEN`, ativando o modo de proteção por 30 segundos.
  - Durante o estado de bypass, as requisições transitam de forma transparente e segura pelo banco de dados relacional e cache efêmero da requisição, garantindo que o portal permaneça 100% no ar mesmo se o daemon Redis cair.
  - Transiciona suavemente para `HALF_OPEN` para testar a recuperação da infraestrutura antes de restaurar o estado `CLOSED`.
* **Proteção contra Cache Stampede (Dogpile Lock):**
  - Implementação de locks atômicos distribuídos `SET NX PX 5000` através do método `RedisCache::remember()`.
  - Ao expirar uma chave de alto acesso concorrente, apenas um worker recalcula a query pesada no MySQL enquanto as demais requisições aguardam brevemente ou recebem o valor sem sobrecarregar o banco de dados.
* **Separação de Banco (Split Database):**
  - Suporte a direcionar dados efêmeros e transientes para um banco secundário do Redis (ex: DB 1), isolando dados de sessão e requisições externas do banco principal de posts e configurações (DB 0).
* **Codecs Modernos de Alta Velocidade:**
  - Serializadores: suporte prioritário a `igbinary` (redução drástica de CPU e consumo de bytes em RAM) com fallback transparente para o serializador nativo do PHP.
  - Algoritmos de Compressão: detecção e aplicação automática de `zstd` (Zstandard) e `lz4` se compilados na extensão `phpredis`.
* **Precedência de Configuração e Sobrescrita Segura:**
  - Prioridade estrita para a constante `WP_REDIS_CONFIG` em `config.local.php`, mantendo credenciais e parâmetros de infraestrutura sob controle de versão local/produção:
    ```php
    define('WP_REDIS_CONFIG', [
        'host'         => '127.0.0.1',
        'port'         => 6379,
        'password'     => null,
        'database'     => 0,
        'timeout'      => 1.0,
        'read_timeout' => 1.0,
        'prefix'       => 'speedracing_',
        'async_flush'  => true,
        'compression'  => 'zstd',
        'serializer'   => 'igbinary',
    ]);
    ```
* **Painel Administrativo com 5 Abas (`/admin/plugin-settings.php?plugin=redis-cache-pro`):**
  - **Aba 1 (Visão Geral & Telemetria 24h):** Radial Gauge animado de Hit Ratio (%), status do disjuntor, latência média RTT (ms), consumo de memória atual vs pico e gráfico vetorial interativo SVG com histórico de 24 horas.
  - **Aba 2 (Comandos em Tempo Real):** Query Profiler com as últimas 30 operações de cache executadas na requisição, duração em milissegundos e identificação do chamador no core.
  - **Aba 3 (Configuração & Pre-warming):** Parâmetros de rede e formulário de pré-aquecimento inteligente para configurações globais, matérias recentes e rotas estratégicas.
  - **Aba 4 (Grupos & Big Key Hunter):** Scanner incremental via cursor `SCAN` para localizar os 15 maiores objetos armazenados em RAM com botão para `UNLINK` individual e purga granular por grupo.
  - **Aba 5 (Alertas & Incidentes):** Disparo de webhooks ricos em formato embed (compatível com Discord, Slack, Teams e n8n) e alertas por e-mail em caso de pico de RAM ou disparo do Circuit Breaker.
* **Facade e Helpers de Cache (`\LightPress\Plugin\RedisCachePro\RedisCache`):**
  - Interface estática de alto nível: `get()`, `set()`, `delete()`, `remember()`, `flushGroup()`, `flush()` e `isConnected()`.
  - Invalidação automática vinculada aos hooks de ciclo de vida do LightPress (`post_saved`, `post_deleted`, `settings_updated`).

---

### 8.14. Prevenção Contra Redirecionamentos Abertos (Open Redirect / CWE-601)

Para impedir o uso indevido do sistema como vetor em ataques de engenharia social, phishing e redirecionamento para URLs externas fraudulentas:

* **Validação Estrita de Destinos (`Router::isValidRedirectTarget`):**
  - **Caminhos Relativos:** Permitidos se iniciarem estritamente com `/`, barrando tentativas de bypass com protocolo relativo (`//site-malicioso.com`) ou sintaxe de barra invertida (`/\site-malicioso.com`, `\evil.com` ou caminhos contendo backslashes).
  - **URLs Absolutas:** Esquemas restritos a `http` e `https` (eliminando `javascript:` e `data:`). O domínio deve corresponder estritamente ao host canônico autorizado do portal (`canonical_origin` ou `$_SERVER['HTTP_HOST']`).
  - **Normalização Preventiva no Despacho:** O roteador intercepta caminhos iniciados com `\`, `/\` ou `//` logo no início de `Router::dispatch()` e antes da canonicalização de barra final, neutralizando redirecionamentos maliciosos gerados por barras invertidas interpretadas por navegadores legados.
  - **Redirecionamentos Legados:** Todos os registros da tabela `redirects` são verificados em `Router::dispatch()` antes do envio do cabeçalho `Location`, passando pela mesma validação de segurança.

### 8.15. Otimização Algorítmica de Slugs e Roteamento Flexível

* **Resolução Otimizada de Slugs Únicos (`PostService::resolveUniqueSlug`):**
  - Eliminação de consultas sequenciais $O(N)$ em loop infinito `while`.
  - Checagem em 1 query para casos comuns e consulta agrupada em lote com busca por prefixo em colisões (`slug = ? OR slug LIKE ?`).
  - Detecção do maior índice em memória com fallback criptograficamente seguro (`bin2hex(random_bytes(3))`) para máxima resiliência e prevenção de DoS por esgotamento de conexões em importações massivas ou matérias com títulos idênticos.
* **Suporte Completo a Subdiretórios (`base_path`):**
  - O motor de roteamento (`core/Router.php`) abstrai o prefixo de subpasta (ex: `/blog`), garantindo que tanto o despacho de rotas públicas quanto as regras de canonicalização 301 mantenham a navegação na subpasta sem expulsar o visitante para a raiz do domínio. Suporte integrado via `config.php` (`app.base_path`) ou dedução inteligente a partir de `canonical_origin`.

### 8.16. Integridade de Agendamentos e Estados Editoriais

* **Prevenção de Publicação Acidental por Data Inválida:** Validação rigorosa em `editor.php` e `core/PostService.php` para garantir que erros de formatação em datas de agendamento não caiam em timestamp zero (`01/01/1970`), impedindo que matérias com datas corrompidas sejam publicadas inadvertidamente no passado.
* **Prevenção de Matérias Fantasmas (Status e Data Sincronizados):** Ao salvar rascunho com o campo de data esvaziado, o sistema sincroniza atomicamente o `status = 'draft'` e purga o cache das URLs afetadas, garantindo que a matéria não fique como `published` sem data válida (`published_at IS NULL`).

### 8.17. Hardening de Segurança e Integridade Arquitetural (Auditoria SEC-01 a SEC-06)

Em conformidade com a auditoria de segurança profunda de novembro de 2026, os seguintes controles defensivos foram incorporados:

* **8.17.1. Ciclo de Vida e Invalidação Atômica de Tokens (SEC-01):**
  - **Validação de Finalidade e Estado (`activate.php`):** O lookup e a transação `FOR UPDATE` de ativação validam estritamente `purpose = 'activation'`, além de verificar se a conta está de fato pendente de ativação (`is_active = 0` e `password_reset_required = 1`). Contas bloqueadas ou já ativadas são recusadas imediatamente.
  - **Invalidação Transacional em Cascata:** Qualquer alteração administrativa de senha ou alternância de bloqueio (`users.php`), alteração de senha pelo próprio usuário (`profile.php`) ou conclusão de ativação invalida na mesma transação todos os tokens pendentes em `user_reset_tokens` (`used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL`).
* **8.17.2. Blindagem SSRF em Webhooks com Suporte a Rede Local e DNS Pinning (SEC-02):**
  - **Prevenção de DNS Rebinding e TOCTOU (`WebhookSenderService::resolveSafeTarget`):** Resolução dual-stack atômica executada uma única vez, rejeitando explicitamente respostas vazias. O primeiro IP validado é fixado diretamente na conexão cURL via `CURLOPT_RESOLVE => ["$host:$port:$pinnedIp"]`, eliminando consultas DNS secundárias concorrentes e garantindo que o cURL conecte estritamente ao endereço aprovado.
  - **Normalização Binária de IPv6:** Conversão rigorosa de IPv6 via `inet_pton()` de 16 bytes, interceptando notações hexadecimais de IPv4-mapped (`::ffff:7f00:1`, `::ffff:a9fe:a9fe`), IPv4-compatible e prefixos NAT64 (`64:ff9b::`), garantindo que representações alternativas de loopback e metadados de nuvem sejam bloqueadas.
  - **Bloqueio Rigoroso de Ameaças:** Rejeição de loopback (`127.0.0.0/8`, `::1`), metadados de nuvem (`169.254.169.254`, `metadata.google.internal`), CGNAT / Alibaba Cloud Metadata (`100.64.0.0/10`), IETF Protocol Assignments / Oracle Cloud Metadata (`192.0.0.0/24`), Multicast e Reservado (`>= 224.0.0.0` - Classes D e E), link-local (`fe80::/10`) e faixas privadas quando não autorizadas.
  - **Formatação Canônica IPv6 no cURL:** Encadeamento de colchetes `[ipv6]` na opção `CURLOPT_RESOLVE` para hosts resolvidos em IPv6, garantindo separação inequívoca de porta e endereço no libcurl.
  - **Suporte a Portas Customizadas e LAN:** Aceita portas personalizadas (ex: `5678`) e tráfego em rede local privada (RFC1918 como `http://192.168.3.165:5678`), atendendo a servidores internos de automação (n8n/Zapier local) com total segurança.
* **8.17.3. Blindagem de Rate Limiting no Nginx para Métricas (SEC-03):**
  - **Bloqueio Direto de Scripts (`nginx/lightpress.conf`):** O script `/api/view.php` tem acesso direto negado via Nginx (`return 404;`), forçando todo o tráfego de visualizações a transitar pela rota canônica `^~ /api/view/`, sujeita ao limitador `limit_req zone=lp_views burst=10 nodelay`.
* **8.17.4. Isolamento, Concorrência Otimista e Recarregamento Editorial de Rascunhos Privados (SEC-04):**
  - **Preservação de Conteúdo Público e Recarregamento no Editor (`editor.php`):** Salvar rascunho de matérias já publicadas ou agendadas grava as alterações exclusivamente como revisões privadas em `post_revisions` (`status = 'draft'` ou `'pending_review'`), sem alterar a linha ativa da tabela `posts`. Ao reabrir ou salvar a tela, o editor recarrega e sobrepõe automaticamente a revisão privada ativa no formulário e canvas visual com aviso informativo, permitindo continuar a edição contínua do texto salvo.
  - **Validação de Concorrência em Rascunhos e Publicação:** Verificação estrita de versão do post público (`(int)$current['version'] === $knownVersion`) e conferência de `known_revision_id` executadas tanto ao salvar rascunhos quanto ao publicar via `PostService::publishOrUpdate()`. Se outro autor salvar um rascunho recente da matéria pública, publicações e rascunhos concorrentes são interceptados com HTTP 409. O bloqueio por rascunho não conhecido aplica-se estritamente a matérias no ar (`published`/`scheduled`), permitindo a publicação instantânea de novos artigos e rascunhos (`draft`) de primeira. Na publicação, apenas a revisão aplicada é marcada como `'applied'`, enquanto rascunhos órfãos anteriores são arquivados como `'superseded'`.
  - **Suporte ao Estado `superseded` e Migração nos Bancos:** O estado `'superseded'` integra o DDL de MySQL (`bin/schema.sql`) e SQLite (`core/Schema.php`). A atualização de bases existentes ocorre em `Schema::ensureRevisionsSchema()`, chamada na inicialização do serviço editorial e na instalação do SQLite. As migrações necessárias devem terminar antes de iniciar transações editoriais; DDL necessário durante uma transação é recusado.
  - **Desbloqueio por Filtro de Versão Atual:** A busca por rascunhos concorrentes filtra estritamente por `base_version = (int)$current['version']`, garantindo que revisões defasadas de versões anteriores não impeçam o salvamento de novos rascunhos ou novas publicações.
  - **Preservação de Corpo Vazio e Prevenção de Restauração Espúria:** Diferenciação explícita entre campos ausentes e conteúdos vazios intencionais no editor e em `core/PostService.php`. Garantia de que matérias com texto intencionalmente limpo mantenham `content_json = '[]'`, evitando que fallbacks de compilação restaurem textos previamente deletados. Revisões com `base_version` defasadas não sobrepõem o conteúdo publicado mais recente.
  - **Publicação Explícita e Purga de URLs Antigas:** A aplicação no site público exige a ação expressa de publicar. A revisão escolhida é marcada como `applied`; outras revisões pendentes tornam-se `superseded`. O job de invalidação é registrado na transação editorial e a purga é tentada após o commit, incluindo a URL atual e o slug anterior quando houver renomeação.
* **8.17.5. Prazos de Sessão no Servidor (SEC-05):**
  - **Controle no Backend (`core/Security.php` e `config.php`):** Imposição de tempo máximo absoluto de 24 horas (`session_lifetime = 86400`) e tempo limite de inatividade de 2 horas (`session_idle_timeout = 7200`) em `Security::requireAuth()`.
  - **Destruição Ativa de Sessão:** Ao expirar qualquer um dos limites, a sessão é destruída no servidor, o cookie é removido e o usuário é redirecionado para `/admin/login.php?expired=1`.
* **8.17.6. Resolução Segura de IP Compatível com Cloudflare Tunnel (SEC-06):**
  - Em `Security::getClientIp()`, quando atrás de proxy reverso confiável local, prioriza `X-Real-IP` normalizado pelo Nginx para o cliente real, mantendo total compatibilidade e aceitação do `CF-Connecting-IP` originado de instâncias do Cloudflare Tunnel.
  - **Pendência da auditoria:** a precedência de cabeçalhos não comprova sua procedência. Se um proxy confiável repassar `CF-Connecting-IP` enviado pelo cliente e não fornecer um `X-Real-IP` de cliente válido, o IP ainda pode ser forjado. A restrição das origens autorizadas a atestar esse cabeçalho permanece pendente; as correções de migração e restauração abaixo não alteram esse controle.
* **8.17.7. Mutações Administrativas Exclusivamente via POST com CSRF:**
  - Todas as ações mutáveis de exclusão e alternância de estado em `pages.php`, `categories.php`, `dashboard.php`, `media.php` e `users.php` foram migradas para formulários `POST` estritos com validação de `_csrf_token`, eliminando a presença de tokens em históricos de navegação e access logs do servidor.

#### 8.17.8. Recuperação de migrações e restauração histórica

No SQLite, a migração de `post_revisions` preserva IDs e conteúdo, cria sua própria transação e restaura o valor original de `PRAGMA foreign_keys`, inclusive quando ele já estava desabilitado. Se qualquer etapa falhar, o método desfaz a transação que iniciou, restaura a configuração em `finally` e propaga a exceção. Não há confirmação nem rollback de uma transação pertencente ao chamador. A migração necessária é recusada se a conexão já estiver em uma transação.

No MySQL, `ALTER TABLE` pode causar commit implícito. Por isso, o histórico inicializa `PostService` e conclui as migrações antes de abrir a transação de restauração e adquirir os locks editoriais. O serviço também recusa atualizações de esquema necessárias dentro de uma transação, propagando erros de DDL em vez de ignorá-los. A atualização de esquema no MySQL não é anunciada como uma operação reversível por rollback.

A restauração distingue três valores: a versão pública conhecida, o identificador da revisão ativa conhecida (`knownRevisionId`) e o snapshot histórico escolhido (`appliedRevisionId`). Assim, é possível restaurar um snapshot antigo mesmo com outro rascunho ativo, desde que o estado não tenha mudado desde a abertura do formulário. A transação revalida versão e revisão ativa antes de gravar. Divergências retornam HTTP 409; a revisão escolhida recebe `applied`, e as demais revisões pendentes recebem `superseded`. Após sucesso, os identificadores do formulário são atualizados.

#### 8.17.9. Contabilização Canônica de Views em Páginas Institucionais e Auto-migração de `view_dedupe`

* **Desacoplamento e Suporte a Páginas Institucionais:** A tabela `view_dedupe` utiliza `post_id BIGINT NOT NULL` sem chave estrangeira estrita (`FOREIGN KEY`) com a tabela `posts`. Esse arranjo permite o registro de identificadores negativos para páginas institucionais (`$dedupeId = -$targetId`), prevenindo colisões de ID com posts e eliminando erros de restrição relacional em MySQL e SQLite.
* **Auto-migração Idempotente e Proteção contra Cache Nginx:** `Schema::ensureViewDedupeSchema()` executa a verificação e adequação da tabela `view_dedupe` sem causar commits implícitos indesejados. O endpoint `public/api/view.php` chama o método fora de transações abertas antes de processar beacons de páginas institucionais, assegurando a migração mesmo quando o conteúdo HTML da página tiver sido servido diretamente a partir do FastCGI Cache do Nginx.

#### 8.17.10. Fluxo Editorial de Revisão (`pending_review`) para Autores e Padronização Visual do Admin

* **Ciclo de Vida para Autores (`author`):** Colaboradores com papel de autor não possuem permissão de publicação direta (`Authorization::ACTION_PUBLISH_POST`). Ao criarem ou editarem matérias, a submissão transita o artigo para o estado `pending_review`. A versão correspondente em `post_revisions` registra adequadamente `status = 'pending_review'`, fornecendo rastreabilidade e histórico para a equipe editorial aprovar ou publicar.
* **Interface do Editor:** A barra de ações em `public/admin/editor.php` renderiza condicionalmente o botão de submissão: para autores, é disponibilizado *"📤 Enviar para Revisão Editorial"*; para editores e administradores, botões diretos de *"🚀 Publicar Agora"*, *"Atualizar Publicação"* ou *"📅 Salvar Agendamento"*.
* **Sincronização de Design Tokens e Classes no Admin:** Padronização completa de classes CSS nas páginas administrativas (`system.php`, `plugins.php`, `users.php` e `settings.php`), cobrindo grids responsivos (`.grid-details`), cartões informativos (`.card-title`, `.info-row`), badges semânticos (`.badge-ok`, `.badge-warn`, `.badge-err`, `.badge-active`, `.badge-inactive`), caixas de links temporários (`.token-box`, `.token-input`), botões de perigo (`.btn-danger`) e elementos de layout com suporte a modo escuro (`[data-theme="dark"]`).

#### 8.17.11. Resiliência do Roteador, Integridade de Metadados e Compatibilidade Multi-Banco

* **Defesa contra TypeError no Roteador (`Router.php`):** Tratamento rigoroso do retorno `false` de `parse_url()` ao despachar rotas e validar alvos de redirecionamento, prevenindo que entradas malformadas causem interrupções fatais sob tipagem estrita (`declare(strict_types=1);`).
* **Preservação de HTML e Metadados em Revisões (`revisions.php` e `PostService.php`):** A restauração de snapshots em `revisions.php` preserva o `content_html` original gravado em `post_revisions` caso o payload desserializado não contenha a chave. O método `PostService::createPost()` grava o snapshot inicial com todos os metadados (`content_html`, `tags`, `featured_image`, `seo_title`, `seo_description`, `focus_keyword`), garantindo histórico completo.
* **Compatibilidade Portável SQLite e MySQL:** Eliminação de cláusulas específicas como `DATE_SUB(NOW(), INTERVAL 24 HOUR)` em `templates/header.php` em favor de parâmetros preparados com `gmdate()`. Em `ImageProcessor::syncRefs()`, alternância transparente de dialeto entre `INSERT OR IGNORE INTO` (SQLite) e `INSERT IGNORE INTO` (MySQL).

#### 8.17.12. Verificações de regressão e limites de validação

Execute, com PHP 8.4 e as extensões do projeto:

```bash
php bin/test-revision-safety.php
php bin/test-ssrf.php
php bin/test-feeds-news.php
python bin/test_security_fixes.py
```

* `bin/test-revision-safety.php` não carrega o bootstrap nem `config.local.php`: usa bancos SQLite em memória e as classes reais do projeto. Cobre migração bem-sucedida, preservação de `foreign_keys` ligado/desligado, falha de DDL com rollback e propagação, rejeição de migração dentro de transação externa, publicação inicial e restauração histórica com concorrência. A CI executa esse teste em PHP 8.4.
* `bin/test-ssrf.php` valida os 4 perfis de segurança do `SafeHttpClient`, incluindo DNS pinning atômico, bloqueio de loopback, metadados de nuvem e redes privadas com 14 asserções.
* `bin/test-feeds-news.php` valida 26 asserções cobrindo a sintaxe XML via SimpleXML, integridade dos namespaces RSS 2.0 (`content`, `media`, `dc`, `atom`), enclosures e tags `<media:content>` WebP, integridade de CDATA em `<content:encoded>`, regras estritas de 48 horas no Google News Sitemap (`/sitemap-news.xml`), isolamento por categoria e respostas 404 para categorias inexistentes.

Na validação inicial destas correções, o PHP 7.2 local permitiu executar cinco testes reais de migração SQLite, mas a integração editorial foi explicitamente pulada. Em 2026-11-12, a reprodução em uma cópia isolada com PHP 8.4.27 executou e aprovou os sete testes de `bin/test-revision-safety.php`, incluindo concorrência e restauração histórica. Os testes Python simulam regras e não substituem a integração PHP. Os fluxos HTTP/MySQL ainda precisam ser confirmados em homologação.

#### 8.17.13. GitHub Actions: ambiente SQLite e testes determinísticos

O workflow [CI - Testes e Validação do LightPress](.github/workflows/ci.yml) executa em `ubuntu-latest`, com PHP 8.4, nos pushes e pull requests para `main`. Instala PDO MySQL/SQLite, GD, mbstring e fileinfo; configura limite de memória de 256 MiB e timezone UTC.

A ordem das etapas é:

1. Criar `config.local.php` no checkout temporário do runner, selecionando SQLite em `storage/test.sqlite`.
2. Verificar a sintaxe dos arquivos PHP, excluindo `storage/`.
3. Executar `php bin/diagnose.php`.
4. Executar `php bin/test.php`.
5. Executar `php bin/test-revision-safety.php`, com bancos SQLite em memória próprios.
6. Executar `php bin/setup.php` para instalar o esquema no banco temporário.
7. Executar `php bin/seed.php --count=25`.

A configuração SQLite deve existir antes de qualquer comando que carregue o bootstrap. Antes da correção, ela era criada somente na etapa de setup: a suíte tentava acessar o MySQL padrão e registrava `SQLSTATE[HY000] [2002] Connection refused`. Essas exceções eram tratadas; as duas asserções que falhavam determinavam o código de saída `1` e impediam a execução das etapas seguintes.

O [log da execução #141](https://github.com/CarlosEduardoAraujo/lightpress/actions/runs/43214035334) registrou 47 testes aprovados e duas falhas. As correções em `bin/test.php` são:

* **Hooks e UTF-8:** o callback do teste usa `mb_strtoupper($text, 'UTF-8')`, produzindo `OLÁ MUNDO`. `strtoupper()` retornava `OLá MUNDO`, embora o encadeamento dos filtros estivesse correto.
* **Webhooks sem dependência de DNS externo:** os domínios `.test` usam a simulação existente `WebhookSenderService::setDnsMock()`. A asserção compara a lista exata de URLs, cobrindo separação por vírgulas/quebras de linha, descarte de entradas inválidas, ordem e remoção de duplicatas. O estado anterior do mock é restaurado em `finally`.
* **Proteção contra SSRF:** uma nova asserção confirma a rejeição de DNS vazio, loopback e metadados de nuvem. A validação de segurança do plugin permanece ativa.

Para reproduzir as etapas, use uma cópia isolada do repositório e configure nela o arquivo `config.local.php` antes dos comandos acima:

```php
<?php
return [
    'database' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/storage/test.sqlite',
    ],
];
```

Na validação local de 2026-11-12, com PHP 8.4.27 no Windows, passaram lint, diagnóstico, os sete testes de revisões, setup, seeder de 25 publicações e os 14 testes adicionais de `php bin/test-ssrf.php`. As tentativas de conexão ao MySQL desapareceram. A suíte principal passou a ter 50 asserções: 49 aprovadas, incluindo as duas corrigidas, e uma falha preexistente de transliteração de slugs em `iconv` no Windows. Essa asserção passou no runner Ubuntu do log original; a reprodução Windows não equivale à aprovação remota. Consulte o resultado do commit em [GitHub Actions](https://github.com/CarlosEduardoAraujo/lightpress/actions).

**Confirmação remota em 2026-11-12:** a [execução #142](https://github.com/CarlosEduardoAraujo/lightpress/actions/runs/43220120224), do commit `fcae607`, terminou com `success` em todas as etapas: configuração SQLite, lint, diagnóstico, suíte principal, revisões, setup e seeder. Esse resultado confirma a execução completa das correções no runner Ubuntu com PHP 8.4.

---

### 8.18. Otimização de Performance, Acessibilidade WCAG AA e Estabilidade Visual (PageSpeed Insights & Core Web Vitals)

Com base na auditoria oficial do **Google PageSpeed Insights (Lighthouse 13.6.0 - Desktop)** realizada no domínio `https://www.speedracing.com.br/`, o sistema consolida o seguinte quadro de métricas:

* **Desempenho (Performance):** `100 / 100` (FCP: 0,4s | LCP: 0,7s | TBT: 0ms | Speed Index: 0,4s).
* **Práticas Recomendadas (Best Practices):** `100 / 100`.
* **SEO:** `100 / 100`.
* **Acessibilidade:** `100 / 100` (após adequações de contraste e áreas de toque).

#### 8.18.1. Eliminação de Cumulative Layout Shift (Zero CLS)
Para assegurar estabilidade visual absoluta durante o carregamento assíncrono:
* O banner vertical promocional (`/uploads/2026/10/banner-vertical-qr-site.avif`) recebeu dimensões intrínsecas fixas (`width="400" height="716"`) mantendo proporção fluida responsiva (`w-full h-auto`).
* Essa especificação elimina **0.016 de CLS** identificado no relatório, impedindo saltos nos cards laterais e no fluxo de leitura dos artigos.
* Aplicado de forma homogênea em todos os templates de exibição (`home.php`, `post.php`, `category.php`, `page.php`, `tag.php` e `search.php`).

#### 8.18.2. Design Tokens de Contraste para Acessibilidade WCAG AA (`racing-red-text` e `racing-red-text-dark`)
O critério de contraste de cores para textos normais exige no mínimo `4.5:1` segundo as diretrizes WCAG 2.1 AA. O vermelho base de marca `#E10600` atinge `3.99:1` sobre fundos brancos puros (`#FFFFFF`) e apenas `3.37:1` a `3.72:1` sobre os fundos escuros do sistema (`#111418` e `#1A1E24`). Para assegurar nota 100/100 em Acessibilidade em ambos os temas:
* **Tema Claro:** Configurado o design token `"racing-red-text": "#B30400"` no `tailwind.config.js`, atingindo taxa de contraste de **7.16:1** no modo claro sobre fundo branco.
* **Tema Escuro:** Configurado o design token `"racing-red-text-dark": "#FF4D4D"`, com luminância calibrada ($L = 0.268$) atingindo taxas de contraste de **5.10:1** sobre os cards (`#1A1E24`) e **5.62:1** sobre a superfície principal (`#111418`).
* **Elementos Decorativos e Ações:** Preservado o tom original de marca `#E10600` para fundos sólidos de botões (com texto branco puro `#FFFFFF`, contraste 4.98:1) e elementos gráficos não textuais.
* **Padronização Global:** Aplicada a variante `text-racing-red-text dark:text-racing-red-text-dark` em todo o sistema: logotipos (`RACING`), navegação ativa (`Home`), ticker *Em Alta*, links de matérias (`Ler matéria completa`), bylines editoriais e rodapé institucional.

#### 8.18.3. Áreas de Toque e Desambiguação de Links
* No rodapé institucional (`templates/footer.php`), links de navegação rápida e categorias receberam espaçamento vertical dedicado (`py-1 inline-block`) para cumprir os requisitos de alvos de toque mínimos (*touch targets*).
* Adicionados atributos `aria-label` descritivos diferenciando âncoras de categorias e tags de mesmo rótulo (ex.: `aria-label="Categoria Fórmula 1"` vs `aria-label="Tópico em alta: Fórmula 1"`), eliminando avisos de destinos ambíguos (*identical-links-same-purpose*).

### 8.19. Hardening Defensivo de Produção (Anti-SSRF Unificado, Resiliência de Sessão, Timing Constant-Time e Fail-Fast)

Para atender a ambientes de missão crítica em produção e mitigar superfícies de ataque identificadas em auditoria de segurança avançada, o LightPress consolida uma camada robusta de defesas ativas:

#### 8.19.1. Anti-SSRF Centralizado e DNS Pinning (`core/SafeHttpClient.php`)
O sistema unifica a validação de destinos e requisições HTTP externas através do serviço central `LightPress\Core\SafeHttpClient`, operando com 4 perfis estritos de segurança:
* **`PROFILE_STRICT_PUBLIC`:** Bloqueia endereços de loopback (`127.0.0.0/8`, `::1`), redes privadas RFC1918 (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), metadados de nuvem AWS/GCP/Azure (`169.254.0.0/16`, `fe80::/10`), CGNAT/Alibaba Cloud (`100.64.0.0/10`), IETF/Oracle (`192.0.0.0/24`), endereços de broadcast/multicast (`>= 224.0.0.0`) e representações hexadecimais/IPv6-mapped (`::ffff:a9fe:a9fe`, `fd00:ec2::`). Utilizado por padrão em webhooks e no `AlertService`.
* **`PROFILE_LAN`:** Permite redes RFC1918 para integrações internas (ex.: automações locais n8n em `192.168.x.x`), mantendo bloqueio irrestrito a loopback, metadados de nuvem e blocos reservados. Utilizado pelo plugin `webhook-sender` (`$allowPrivate = true`).
* **`PROFILE_LOCAL_AI`:** Habilita endpoints de inteligência artificial locais (ex.: Ollama ou vLLM em `127.0.0.1:11434` ou servidores dedicados na LAN), bloqueando de forma absoluta o acesso a metadados de provedores de nuvem. Utilizado pelo `AiClient`.
* **`PROFILE_LOCAL_ORIGIN_ONLY`:** Restringe requisições estritamente ao host canônico do portal (`canonical_origin`), garantindo que o aquecimento de cache (`WarmupService`) não possa ser desviado para domínios ou IPs arbitrários.
* **DNS Pinning Atômico:** Executa a resolução DNS uma única vez via `dns_get_record()` e fixa o IP auditado diretamente no cURL via `CURLOPT_RESOLVE`, neutralizando vulnerabilidades de DNS Rebinding (TOCTOU). Desativa redirecionamentos automáticos (`CURLOPT_FOLLOWLOCATION = false`) e audita o IP primário final (`CURLINFO_PRIMARY_IP`).

#### 8.19.2. Mitigação de Timing Attack em Autenticação e Helpers Seguros (`core/Security.php`, `login.php`)
* **Tempo Constante no Login:** Para prevenir enumeração de usuários legítimos via análise do tempo de resposta da CPU, `login.php` utiliza a constante pré-computada `Security::DUMMY_ARGON2ID_HASH` (`m=65536, t=4, p=1`) quando o usuário não existe ou está inativo. A validação via `password_verify()` consome ~136 ms de CPU de forma uniforme e indistinguível de um usuário existente com senha incorreta.
* **Tipagem Estrita de Parâmetros:** Adicionados os helpers defensivos:
  * `Security::getSafeString(array $source, string $key, string $default = ''): string`: Converte entradas em strings com `trim()`, com proteção estrita contra injeção de arrays (`?q[]=...`).
  * `Security::getSafePassword(array $source, string $key): string`: Valida o tipo string **sem** aplicar `trim()`, assegurando que senhas válidas com espaços nas extremidades não sejam corrompidas.
* **Hardening de Sessão e Proxies Reversos:**
  * `Security::startSecureSession()` lê `trusted_proxies` com fallback resiliente para `$GLOBALS['config']`.
  * Trata cadeias de proxies com múltiplos valores em `HTTP_X_FORWARDED_PROTO` (ex: `https, http`).
  * Força `cookie_secure = true` e `cookie_httponly = true` em produção sobre HTTPS.

#### 8.19.3. Fail-Fast em Produção e Prevenção de Vazamento de Segredos (`core/bootstrap.php`, `public/api/view.php`)
* **Fail-Fast HTTP:** O arquivo `core/bootstrap.php` aborta imediatamente com HTTP 500 qualquer requisição web (`PHP_SAPI !== 'cli'`) em ambiente `production` caso `hmac_secret` permaneça com a chave pública padrão do repositório ou possua menos de 32 caracteres. O motivo detalhado é gravado exclusivamente no `error_log` do servidor, sem vazar informações ao cliente. Comandos CLI (como `bin/setup.php`) permanecem funcionais para rotinas de manutenção.
* **Remoção de Segredo Padrão:** A API pública de visualizações ([view.php](file:///c:/Web/lightpress/public/api/view.php)) não utiliza mais fallback inseguro em código (`lightpress_default_secret`), retornando erro HTTP 500 controlado caso a chave não esteja configurada.
* **Metadados Reais de Mídia:** `ImageProcessor::process()` detecta e armazena o MIME type real inspecionado via `finfo_file` (em vez de forçar `'image/webp'`), e sincroniza variantes geradas tanto no upload tradicional quanto no editor de mídia.

#### 8.19.4. Desacoplamento Frontend e Content Security Policy (CSP) Estrita
* **Compatibilidade com Nginx FastCGI Full-Page Cache (60m):**
  * Para manter as páginas estáticas em cache no Nginx sem necessidade de nonces dinâmicos (que quebrariam o cache HTTP completo para visitantes anônimos), todo JavaScript inline foi desacoplado para bibliotecas estáticas versionadas:
    * `public/assets/js/theme-init.js`: Carregado no `<head>` para inicialização do tema claro/escuro sem flash de layout (Zero FOUC).
    * `public/assets/js/app.js`: Gerenciamento do seletor de tema, formulários de newsletter, cópia de links para a área de transferência e envio assíncrono de telemetria de visualizações via `navigator.sendBeacon` (com fallback para `fetch` com `keepalive: true`).
  * Removido `'unsafe-inline'` da diretiva `script-src` tanto no cabeçalho emitido pelo PHP (`public/index.php`) quanto na configuração do Nginx (`nginx/lightpress.conf`).
* **Sanitização de Canvas:** `public/admin/editor.php` e `public/admin/page-edit.php` aplicam `BlockCompiler::sanitizeHtml()` antes de injetar o conteúdo em atributos `contenteditable`, neutralizando XSS de persistência. Endpoints AJAX administrativos passam a exigir `Accept: application/json`.

#### 8.19.5. Blindagem de Cache Nginx e .htaccess
* **Cache Bypass DoS Neutralizado:** Removida a regex ambígua `~*/preview` de `$lp_skip_cache_uri` e das query strings no `nginx/lightpress.conf`, impedindo que atacantes forcem bypass de cache sob carga em URLs comuns.
* **Herança de Cabeçalhos no Nginx:** Re-declarados os cabeçalhos de segurança nos blocos `location ^~ /uploads/` e `location ^~ /assets/`, corrigindo o comportamento nativo do Nginx que silenciava os cabeçalhos do bloco `server`.
* **Bloqueio no Apache:** Adicionada regra explícita `RewriteRule ^api/view\.php$ - [R=404,L]` no `public/.htaccess`, garantindo que requisições diretas ao script PHP da API de views sejam bloqueadas.

### 8.20. Hardening Pós-Auditoria de Segurança (Auditoria CH-01 a CH-04)

Com base na auditoria técnica detalhada registrada em `AUDITORIAS/20261113-ch-seguranca.md`, foram implementadas correções defensivas abrangentes para 4 vetores de integridade, cache e implantação:

#### 8.20.1. Prevenção de Sobrescrita de Variantes de Mídia (CH-01)
* **Preservação de Nomes para SEO e Identificadores Físicos Únicos:** Os nomes de arquivos físicos combinam o slug semântico sanitizado com um token de entropia derivado do identificador de catálogo (`{$cleanSlug}-{$shortToken}`), garantindo unicidade absoluta entre uploads de diferentes usuários e matérias sem prejudicar o ranqueamento no Google Imagens.
* **Loop de Colisão Abrangente:** Em `core/ImageProcessor.php`, o teste de existência antes da gravação cobre simultaneamente todas as variantes (`full`, `1024`, `thumb`) e extensões (`.webp`, `.avif`). O sistema detecta qualquer colisão de variantes ou resoluções preexistentes, incrementando sufixos numéricos (`-1`, `-2`).
* **Reserva e Gravação Atômica Exclusiva (`fopen('xb')`):** Eliminada a dependência vulnerável de `file_exists()` seguido de `rename()`. A gravação final de cada variante utiliza atomicamente `fopen(..., 'xb')` (`O_CREAT | O_EXCL` no kernel) com cópia direta via streams (`stream_copy_to_stream()`), tornando fisicamente impossível sobrescrever qualquer arquivo preexistente mesmo sob alta concorrência de requisições.

#### 8.20.2. Invalidação Atômica do Cache de Objetos Redis (CH-02)
* **Exclusão de Artigos (`public/admin/dashboard.php`):** Ao excluir um artigo do banco de dados, o sistema remove a chave `post_slug:{$slug}` do `ObjectCache`, purga o grupo `'posts'` via `ObjectCache::flushGroup('posts')` e dispara a Action oficial `Hook::doAction('post_deleted', ...)`.
* **Despublicação e Retorno a Rascunho (`public/admin/editor.php`):** Quando uma matéria publicada é alterada para rascunho ou revisão pendente, as chaves de slug anterior e atual são excluídas do `ObjectCache` e o grupo `'posts'` é invalidado.
* **Ciclo de Vida de Páginas Institucionais (`public/admin/pages.php`, `public/admin/page-edit.php`):** A exclusão e atualização de páginas limpam as chaves `page_slug` e realizam `ObjectCache::flushGroup('pages')`, impedindo que páginas retiradas ou modificadas continuem sendo servidas pela RAM aos visitantes públicos.

#### 8.20.3. Normalização da Chave FastCGI no Nginx e Purga com UTMs (CH-03)
* **Extração do Caminho Original (`$lp_request_path`):** Em `nginx/lightpress.conf`, a chave FastCGI utiliza `$lp_request_path` mapeado via regex a partir de `$request_uri`:
  ```nginx
  map $request_uri $lp_request_path {
      ~*^(?<path>[^?]+) $path;
      default $request_uri;
  }
  fastcgi_cache_key "$scheme$lp_cache_method$host$lp_request_path";
  ```
  Como o Nginx altera `$uri` para `/index.php` após o `try_files`, utilizar `$uri` diretamente colapsava todas as rotas sob a mesma chave. A variável `$request_uri` preserva a rota original solicitada pelo leitor, e o map descarta a query string com parâmetros analíticos (`utm_*`, `fbclid`, `gclid`).
* **Purga Unificada de Cópias com Parâmetros:** Com `$lp_request_path`, `/noticia/materia/` e `/noticia/materia/?utm_source=meta` compartilham exatamente o mesmo cache físico em `/var/cache/nginx/lightpress/`, garantindo paridade total com o cálculo de purga canônica do `CacheManager` e eliminando todas as cópias instantaneamente na exclusão ou edição.
* **Preservação de Buscas Dinâmicas:** Consultas em tempo real (`?q=` e `?s=`) continuam forçando o bypass completo (`$lp_skip_cache = 1`).

#### 8.20.4. Isolamento da Proteção contra Execução de Scripts em Uploads para Apache (CH-04)
* **Liberação da Raiz Pública:** A regra `FilesMatch` que negava acesso a arquivos `.php` foi removida da raiz `public/.htaccess`, garantindo que o front-controller `index.php` e os painéis administrativos funcionem sem erro 403 Forbidden em servidores Apache e LiteSpeed.
* **Proteção Dedicada em Uploads:** Criado o arquivo `public/uploads/.htaccess` com a diretiva `FilesMatch` restrita exclusivamente à pasta de mídia, bloqueando a execução direta de qualquer script malicioso enviado ao diretório de uploads.

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
  - [x] **Design de Portal de Alta Velocidade:** Layout panorâmico Full HD (`1900px`), Home com Hero Quad Grid de 4 destaques com backfill automático e miniaturas compactas horizontais nos cards secundários mantendo alinhamento estrito (`items-stretch`), feed de Últimas Notícias unificado (listando todas as notícias sem exclusão arbitrária dos destaques), página de leitura de artigos widescreen (`1750px`), capa com visual cinematográfico, tempo estimado de leitura (*ex: 3 min de leitura*), bloco de autor com bio/avatar e modo escuro nativo sem flash.
  - [x] **Módulo de Notícias Relacionadas:** Recomendações contextuais de 4 matérias da mesma categoria em grid responsivo de tela ampla para retenção de audiência.
  - [x] **Botões Rápidos de Compartilhamento Social:** Barra leve de compartilhamento (WhatsApp, X, Facebook, Telegram e botão de 1 clique Copiar Link com feedback instantâneo) sem rastreadores ou bibliotecas pesadas.
  - [x] **Menu Superior Sticky Fixo:** Cabeçalho global fixado no topo para navegação fluida em todo o site.
  - [x] **Sistema de Busca Integrada (`/busca/?q=...`):** Pesquisa ágil de notícias publicadas com marcação visual dos termos pesquisados e compatibilidade total com o cache estático.
  - [x] **URLs Canônicas na Raiz (`/{slug}/`):** URLs permanentes de alto impacto com redirecionamento 301 automático das rotas antigas.

* **FASE CONCLUÍDA — Opção 2: Recursos Administrativos da Redação (Gestão de Portal):**
  - [x] **Dashboard Editorial Turbinado (`/admin/dashboard.php`):** Barra de filtros dinâmicos por Status (Publicados, Rascunhos, Em Revisão, Arquivados), por Categoria, por Autor, busca textual por título/resumo, filtro de Destaques Home (★), métricas em tempo real no topo e paginação rápida (25 por página).
  - [x] **Painel de Mais Lidas e Audiência (`/admin/stats.php`):** Ranking Top 20 matérias mais lidas, desempenho por categoria e produção por autor baseados em dados atômicos sem duplicidade por IP.
  - [x] **Gestão Completa de Categorias (`/admin/categories.php`):** Interface administrativa para criar, editar nome/slug com atualização em cascata de matérias vinculadas, pausar/ativar e exclusão segura com reatribuição automática para a categoria padrão `geral`, com expurgo de cache no Nginx.
  - [x] **Gestão de Usuários e Autores (`/admin/users.php`):** Cadastro e edição completa de membros da redação (repórteres, editores e administradores), biografia do autor, avatar, link seguro de ativação criptográfico de uso único ou definição direta de senha com Argon2id e revogação instantânea de sessões ativas (`session_version`).

* **FASE CONCLUÍDA — Extensibilidade & Plugins (Zero Bloatware):**
  - [x] **Arquitetura de Hooks & Plugins:** Barramento ultraleve de eventos (`Hook`), gerenciador de ciclo de vida (`PluginManager`), manifesto `plugin.json` e menu de administração dinâmico com dropdown de plugins (`AdminNav`).
  - [x] **Primeiro Plugin Oficial (`webhook-sender`):** Disparo de webhooks para múltiplos servidores (Discord, Slack, n8n, Zapier) na publicação de matérias, com tabela relacional própria (`plugin_webhook_sender_logs`), integração atômica com agendamento de posts, resolução canônica em CLI/cron e painel de controle com testes e reenvio.
  - [x] **Segundo Plugin Oficial (`ai-copilot`):** Assistente de IA generativa e multimodal com zero dependências externas (cURL nativo), suporte a Google Gemini, OpenAI e Ollama local, módulo de linkagem interna com busca FULLTEXT e ancoragem semântica estrita, visão computacional com variantes WebP 1024px e auto-migração de metadados (`alt_text`, `caption`, `ai_tags`) na tabela `media`.
  - [x] **Terceiro Plugin Oficial (`redis-cache-pro`):** Motor corporativo de cache persistente com PhpRedis, Circuit Breaker, versionamento atômico O(1), UNLINK não-bloqueante, telemetria 24h, Big Key Hunter, proteção contra Cache Stampede (Dogpile Lock), alertas via Webhooks e painel administrativo interativo com gráficos SVG.

* **FASE CONCLUÍDA — Polimento & Padronização da Área Administrativa (/admin):**
  - [x] **Header e Footer Unificados (`header.php` e `footer.php`):** Centralização da estrutura HTML, `<head>`, metatags, script anti-flash de tema claro/escuro e navegação completa com submenu de plugins em todas as páginas do `/admin`.
  - [x] **Identidade Dinâmica da Marca:** O nome da publicação exibido em `<a href="/admin/dashboard.php" class="brand">` reflete dinamicamente o Título do Site configurado em `Settings::get('site_title')`.
  - [x] **Largura Universal de 1900px:** Expansão de todas as telas administrativas para `max-width: 1900px` (`.container`), proporcionando máximo aproveitamento horizontal em monitores widescreen.
  - [x] **Padronização Visual da Paleta em Vermelho Oficial (`#E10600`):** Eliminação completa de cores azuis legadas, unificando botões, links ativos, badges e campos em foco na cor da publicação.
  - [x] **Modernização e Paridade do Editor de Páginas (`page-edit.php`):** Canvas visual contínuo `contenteditable` com alternância `</> HTML`, limpador inteligente de formatação para Ctrl+V (Google Docs / MS Word), modal assíncrono de biblioteca de mídias para imagem de destaque e corpo, badge dinâmico de status e botões de ação na cor oficial em largura total.
  - [x] **Refinamento de Rolagem no Editor de Notícias (`editor.php`):** Ajuste fino de fixação sticky da barra de ferramentas com `top: 0px`, garantindo visibilidade total do texto sem sobreposição.

* **FASE CONCLUÍDA — Expansão Panorâmica do Frontend Público (Layout 1900px):**
  - [x] **Aumento da Largura Máxima dos Containers para 1900px:** Ampliação das páginas públicas de navegação (Home, Categorias, Tags, Posts, Institucionais e Busca) de 1280px para 1900px (`max-w-[1900px]`), otimizando o consumo de conteúdo em monitores de alta resolução e mantendo alinhamento estético uniforme entre o cabeçalho (top strip, menu principal, em alta), o feed de notícias e o rodapé.

* **FASE CONCLUÍDA — Otimização, Paginação e Padronização da Busca Pública:**
  - [x] **Compatibilidade com Router Canônico (`/busca/`):** Correção do registro da rota em `public/index.php` para `/busca/`, eliminando falso erro 404 decorrente do redirecionamento 301 de normalização com barra final. Padronização de formulários (`templates/header.php`), redirecionamento da raiz e metadados Schema.org (`core/Seo.php`) para `/busca/?q=...`.
  - [x] **Ordenação Cronológica Recente Primeiro (`published_at DESC`):** Priorização de notícias recém-publicadas no topo da lista de resultados em vez de pontuação estática de relevância do FULLTEXT.
  - [x] **Paginação Completa e Totalizador Real de Resultados:** Eliminação do limite fixo de 30 itens (`LIMIT 30`), introdução de contagem atômica de matérias (`COUNT(*)`) e paginação fluida (12 notícias por página) com barra minimalista em `templates/search.php` e compatibilidade com `/busca/page/{page}/`.

* **FASE CONCLUÍDA — Drag-and-Drop e Auto-Seleção de Mídias nos Editores (`editor.php` e `page-edit.php`):**
  - [x] **Área Intuitiva de Arrastar e Soltar (Drop Zone):** Área de soltura moderna com borda tracejada e feedback visual dinâmico (*"Solte arquivos aqui para enviar ou Enviar nova foto"*), com suporte a clique e suporte a arrastar diretamente sobre o modal.
  - [x] **Seleção Automática Pós-Upload:** Ao concluir o upload e o processamento de imagens (WebP/AVIF), a biblioteca é recarregada e a nova foto fica automaticamente selecionada com destaque visual, metadados preenchidos no rodapé e botão "Confirmar Seleção" habilitado instantaneamente.
  - [x] **Validação no Cliente e Dark Mode:** Validação ágil de formatos aceitos (WebP, AVIF, JPEG, PNG) e suporte estético completo ao tema escuro.

* **FASE CONCLUÍDA — Hardening de Segurança e Integridade Arquitetural (Auditoria 2026-11-09 — SEC-01 a SEC-06):**
  - [x] **Invalidação Atômica de Tokens (SEC-01):** Validação de `purpose = 'activation'` em `activate.php` e invalidação em cascata de tokens pendentes ao alterar senha ou bloquear contas em `users.php` e `profile.php`.
  - [x] **Blindagem SSRF em Webhooks com Suporte a LAN (SEC-02):** Resolução dual-stack A/AAAA, DNS pinning com `CURLOPT_RESOLVE`, bloqueio de cloud metadata e loopback, com suporte total a portas como 5678 e IPs locais como `192.168.3.165:5678`.
  - [x] **Proteção de Rate Limiting no Nginx (SEC-03):** Bloqueio de acesso direto a `/api/view.php` via Nginx (404), garantindo enforcement da zona `lp_views` na rota canônica.
  - [x] **Isolamento de Rascunhos em Artigos Publicados (SEC-04):** Gravação de rascunhos em `post_revisions` sem alterar o conteúdo público da tabela `posts`, com purga de cache Nginx e Cloudflare na publicação (incluindo slugs antigos).
  - [x] **Controle de Prazos de Sessão no Servidor (SEC-05):** Expiração absoluta (24h) e por inatividade (2h) diretamente no backend em `Security::requireAuth()`.
  - [x] **Precedência de IP Compatível com Cloudflare Tunnel (SEC-06):** Priorização segura de `X-Real-IP` com preservação transparente de `CF-Connecting-IP` originado de Cloudflare Tunnel.
  - [x] **Mutações Administrativas via POST:** Migração de exclusões e alternâncias para formulários `POST` estritos com CSRF em `pages.php`, `categories.php`, `dashboard.php`, `media.php` e `users.php`.

* **FASE CONCLUÍDA — Migração de Acervo do WordPress (`bin/migrate-wp.php`):**
  - [x] **Importador CLI Transacional em Lotes:** Extração segura de posts, páginas, categorias, tags e autores de instalações legadas do WordPress.
  - [x] **Processamento de Mídia em Lote:** Leitura local de `wp-content/uploads/` e conversão automática para o pipeline WebP/AVIF com substituição de links no conteúdo.
  - [x] **Preservação de SEO e Redirecionamentos 301:** Mapeamento de todos os permalinks antigos para a tabela `redirects` garantindo integridade de ranqueamento no Google.
  - [x] **Modo Simulação (`--dry-run`):** Execução com validação prévia e relatório de homologação antes da confirmação da migração.

* **FASE CONCLUÍDA — Feeds RSS Google News & News XML Sitemap:**
  - [x] **Feed RSS Geral Enriquecido (`/feed.xml`, `/feed/`):** Compatível com o Google Publisher Center, com namespaces `content`, `media`, `dc`, imagens em `<enclosure>` WebP e corpo editorial em `<content:encoded>`.
  - [x] **Feeds por Categoria Editorial (`/categoria/{slug}/feed.xml`, `/categoria/{slug}/feed/`):** Feeds temáticos por categoria para seções no Google Notícias, com autodescoberta no `<head>`.
  - [x] **Google News XML Sitemap (`/sitemap-news.xml`):** Janela estrita de 48h, namespaces oficiais (`xmlns:news`, `xmlns:image`) e inclusão no índice `/sitemap.xml`.
  - [x] **Suíte de Testes Automatizados (`bin/test-feeds-news.php`):** 26 asserções com 100% de aprovação.

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
