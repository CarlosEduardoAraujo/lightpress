<?php
declare(strict_types=1);

namespace LightPress\Core;

use PDO;
use RuntimeException;

/**
 * Gerenciador de Esquema do Banco de Dados.
 * Cria e atualiza as tabelas nos bancos MariaDB/MySQL e SQLite.
 */
class Schema
{
    /**
     * Garante a existência da tabela de Páginas Institucionais e faz seed das páginas iniciais.
     */
    public static function ensurePagesTable(): void
    {
        $driver = Database::getDriver();
        if ($driver === 'sqlite') {
            Database::query("CREATE TABLE IF NOT EXISTS pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                content_html TEXT NOT NULL,
                featured_image TEXT NULL,
                seo_title TEXT NULL,
                seo_description TEXT NULL,
                status TEXT NOT NULL DEFAULT 'published',
                views INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now')),
                updated_at TEXT NOT NULL DEFAULT (DATETIME('now'))
            );");
            Database::query("CREATE INDEX IF NOT EXISTS idx_page_status_slug ON pages (status, slug);");
        } else {
            Database::query("CREATE TABLE IF NOT EXISTS `pages` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` SMALLINT UNSIGNED NULL,
                `slug` VARCHAR(191) NOT NULL UNIQUE,
                `title` VARCHAR(255) NOT NULL,
                `content_html` MEDIUMTEXT NOT NULL,
                `featured_image` VARCHAR(500) NULL,
                `seo_title` VARCHAR(255) NULL,
                `seo_description` VARCHAR(500) NULL,
                `status` ENUM('published', 'draft', 'archived') NOT NULL DEFAULT 'published',
                `views` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_page_status_slug` (`status`, `slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        }

        // Se a tabela estiver vazia, cria as páginas institucionais padrão
        $count = (int)Database::fetchValue("SELECT COUNT(*) FROM pages");
        if ($count === 0) {
            $initialPages = [
                [
                    'slug' => 'nossa-equipe',
                    'title' => 'Nossa Equipe Editorial',
                    'seo_title' => 'Nossa Equipe - SpeedRacing',
                    'seo_description' => 'Conheça a equipe editorial, jornalistas e colunistas especializados do portal SpeedRacing.',
                    'content_html' => "<p>Fundado em 2002, o <strong>SpeedRacing</strong> é uma das publicações pioneiras na cobertura de automobilismo no Brasil, unindo o universo das pistas reais ao dinamismo do SimRacing e das novas tecnologias do esporte a motor.</p>\n<h2>Redação & Direção Geral</h2>\n<p>Nossa equipe reúne profissionais apaixonados pela velocidade, com décadas de experiência prática nos autódromos, telemetria avançada e rigor informativo.</p>\n<ul>\n<li><strong>Carlos Araújo</strong> — Diretor Geral & Fundador</li>\n<li><strong>Redação SpeedRacing Press</strong> — Notícias, bastidores e paddock oficial</li>\n<li><strong>Equipe Técnica SpeedRacing</strong> — Telemetria e dados de pista FIA, CBA e IMSA</li>\n</ul>",
                ],
                [
                    'slug' => 'equipe',
                    'title' => 'Equipe SpeedRacing',
                    'seo_title' => 'Equipe Técnica e Editorial - SpeedRacing',
                    'seo_description' => 'Equipe técnica, jornalistas e analistas do portal SpeedRacing.',
                    'content_html' => "<p>Conheça os profissionais que trazem as melhores análises, telemetrias e notícias do automobilismo mundial para o portal SpeedRacing desde 2002.</p>\n<h2>Corpo Editorial</h2>\n<ul>\n<li><strong>Carlos Araújo</strong> — Fundador e Especialista Técnico</li>\n<li><strong>Redação SpeedRacing</strong> — Plantão 24/7 em finais de semana de corrida</li>\n</ul>",
                ],
                [
                    'slug' => 'contato',
                    'title' => 'Fale Conosco / Anuncie',
                    'seo_title' => 'Contato & Redação - SpeedRacing',
                    'seo_description' => 'Entre em contato com a equipe do SpeedRacing para pautas, parcerias comerciais e sugestões.',
                    'content_html' => "<p>Estamos à disposição para atender leitores, assessorias de imprensa, equipes e parceiros comerciais.</p>\n<h2>Canais Diretos de Comunicação</h2>\n<p>Escolha o canal mais adequado para a sua solicitação:</p>\n<ul>\n<li><strong>Redação & Envio de Press Releases:</strong> redacao@speedracing.com.br</li>\n<li><strong>Publicidade & Parcerias Comerciais:</strong> comercial@speedracing.com.br</li>\n<li><strong>Contato Geral & Paddock:</strong> contato@speedracing.com.br</li>\n</ul>\n<p>Respondemos a todos os comunicados com agilidade durante os finais de semana de corrida e dias úteis.</p>",
                ],
                [
                    'slug' => 'termos-de-uso',
                    'title' => 'Termos de Uso',
                    'seo_title' => 'Termos de Uso - SpeedRacing',
                    'seo_description' => 'Termos e condições gerais de uso do portal de notícias SpeedRacing.',
                    'content_html' => "<p>Bem-vindo ao <strong>SpeedRacing</strong>. Ao acessar este portal, você concorda em cumprir e sujeitar-se aos seguintes Termos e Condições de Uso.</p>\n<h2>1. Propriedade Intelectual & Direitos Autorais</h2>\n<p>Todo o conteúdo publicado no portal — incluindo textos, matérias investigativas, telemetrias, dados de timing e composições fotográficas — é protegido pela legislação brasileira de direitos autorais. Qualquer reprodução exige citação expressa da fonte com link direto.</p>\n<h2>2. Conduta do Usuário</h2>\n<p>É vedada qualquer tentativa de ataque cibernético, raspagem não autorizada ou uso abusivo que comprometa a integridade dos servidores e o acesso dos demais leitores.</p>",
                ],
                [
                    'slug' => 'politica-de-privacidade',
                    'title' => 'Política de Privacidade',
                    'seo_title' => 'Política de Privacidade & LGPD - SpeedRacing',
                    'seo_description' => 'Diretrizes de proteção e privacidade de dados dos usuários do SpeedRacing.',
                    'content_html' => "<p>O <strong>SpeedRacing</strong> preza pela total transparência e segurança no tratamento dos dados pessoais de seus visitantes, em integral conformidade com a Lei Geral de Proteção de Dados Pessoais (LGPD — Lei nº 13.709/2018).</p>\n<h2>1. Coleta e Uso de Dados</h2>\n<p>Nosso portal é projetado com arquitetura de privacidade por padrão (Privacy by Design). Coletamos apenas informações fornecidas voluntariamente, como endereço de e-mail para a assinatura da nossa newsletter diária.</p>\n<h2>2. Cookies & Preferências Locais</h2>\n<p>Utilizamos apenas cookies essenciais para manter preferências do leitor, como a alternância entre o Modo Escuro e o Modo Claro. Não realizamos rastreamento invasivo nem comercializamos dados de usuários com terceiros.</p>",
                ],
                [
                    'slug' => 'codigo-de-etica',
                    'title' => 'Código de Ética Editorial',
                    'seo_title' => 'Código de Ética Editorial - SpeedRacing',
                    'seo_description' => 'Diretrizes editoriais de imparcialidade, checagem e rigor jornalístico do SpeedRacing.',
                    'content_html' => "<p>Desde sua fundação em 2002, o <strong>SpeedRacing</strong> rege sua produção jornalística sob os princípios fundamentais da verdade factual, rigor técnico e transparência com seus leitores.</p>\n<h2>1. Rigor na Apuração e Checagem</h2>\n<p>Não publicamos boatos sem a devida contextualização e consulta prévia às partes envolvidas (equipes, montadoras, pilotos e federações).</p>\n<h2>2. Separação entre Publicidade e Opinião</h2>\n<p>Conteúdos patrocinados ou comerciais são explicitamente identificados, preservando a independência do juízo crítico de nossos colunistas e analistas.</p>",
                ],
            ];

            foreach ($initialPages as $pg) {
                Database::query(
                    "INSERT INTO pages (slug, title, content_html, seo_title, seo_description, status) VALUES (?, ?, ?, ?, ?, 'published')",
                    [$pg['slug'], $pg['title'], $pg['content_html'], $pg['seo_title'], $pg['seo_description']]
                );
            }
        } else {
            // Se já existia 'quem-somos' e ainda não existe 'nossa-equipe', migra o slug automaticamente
            try {
                $hasNossaEquipe = Database::fetchOne("SELECT id FROM pages WHERE slug = 'nossa-equipe' LIMIT 1");
                if (!$hasNossaEquipe) {
                    Database::query("UPDATE pages SET slug = 'nossa-equipe' WHERE slug = 'quem-somos'");
                }
            } catch (\Throwable) {
                // Silencioso se o banco estiver em processo de criação
            }
        }
    }

    /**
     * Executa a instalação completa do esquema com base no driver configurado.
     */
    public static function install(PDO $pdo, string $driver = 'mysql'): void
    {
        if ($driver === 'sqlite') {
            self::installSqlite($pdo);
        } else {
            self::installMysql($pdo);
        }
    }

    /**
     * Remove todas as tabelas gerenciadas pelo LightPress (para reinstalação limpa).
     */
    public static function dropAll(PDO $pdo, string $driver = 'mysql'): void
    {
        $tables = [
            'settings',
            'pages',
            'redirects',
            'migration_map',
            'cache_purge_jobs',
            'view_dedupe',
            'media_refs',
            'media',
            'user_reset_tokens',
            'post_revisions',
            'post_stats',
            'posts',
            'categories',
            'users',
        ];

        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = OFF;');
            foreach ($tables as $table) {
                $pdo->exec("DROP TABLE IF EXISTS {$table};");
            }
            $pdo->exec('PRAGMA foreign_keys = ON;');
        } else {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
            foreach ($tables as $table) {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`;");
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }

    /**
     * Cria as tabelas para MySQL / MariaDB a partir do script SQL padrão executando instrução por instrução.
     */
    private static function installMysql(PDO $pdo): void
    {
        $sqlFile = dirname(__DIR__) . '/bin/schema.sql';
        if (!file_exists($sqlFile)) {
            throw new RuntimeException("Arquivo de schema não encontrado: {$sqlFile}");
        }

        $sql = file_get_contents($sqlFile);
        $lines = explode("\n", $sql);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $cleanLines[] = $line;
        }

        $statements = explode(';', implode("\n", $cleanLines));
        foreach ($statements as $stmt) {
            $trimmed = trim($stmt);
            if ($trimmed !== '') {
                $pdo->exec($trimmed);
            }
        }

        // Migrações incrementais seguras e idempotentes
        try {
            $pdo->exec("ALTER TABLE posts MODIFY COLUMN status ENUM('draft', 'pending_review', 'scheduled', 'published', 'archived') NOT NULL DEFAULT 'draft'");
        } catch (\Throwable $e) {
            // Ignora se já estiver atualizado
        }

        try {
            $hasFulltext = $pdo->query("SHOW INDEX FROM posts WHERE Key_name = 'idx_fulltext_search'")->fetch();
            if (!$hasFulltext) {
                $pdo->exec("ALTER TABLE posts ADD FULLTEXT INDEX idx_fulltext_search (title, summary, tags)");
            }
        } catch (\Throwable $e) {
            // Ignora se já estiver criado ou não suportado
        }

        try {
            $hasMediaCreated = $pdo->query("SHOW INDEX FROM media WHERE Key_name = 'idx_media_created'")->fetch();
            if (!$hasMediaCreated) {
                $pdo->exec("ALTER TABLE media ADD INDEX idx_media_created (created_at DESC)");
            }
        } catch (\Throwable $e) {
            // Ignora se já existir
        }
    }

    /**
     * Cria as tabelas equivalentes para SQLite.
     */
    private static function installSqlite(PDO $pdo): void
    {
        $statements = [
            // 1. users
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                username TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'author' CHECK(role IN ('author', 'editor', 'admin')),
                is_active INTEGER NOT NULL DEFAULT 1,
                password_reset_required INTEGER NOT NULL DEFAULT 0,
                session_version INTEGER NOT NULL DEFAULT 1,
                bio TEXT NULL,
                avatar TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
            );",
            "CREATE INDEX IF NOT EXISTS idx_user_role ON users (role, is_active);",

            // 2. categories
            "CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
            );",
            "CREATE INDEX IF NOT EXISTS idx_category_active ON categories (is_active, slug);",
            "INSERT OR IGNORE INTO categories (id, slug, name, is_active) VALUES (1, 'geral', 'Geral', 1);",

            // 3. posts
            "CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                summary TEXT NULL,
                category TEXT NOT NULL DEFAULT 'geral',
                tags TEXT NULL,
                featured_image TEXT NULL,
                content_json TEXT NOT NULL,
                content_html TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'pending_review', 'scheduled', 'published', 'archived')),
                is_featured INTEGER NOT NULL DEFAULT 0,
                version INTEGER NOT NULL DEFAULT 1,
                seo_title TEXT NULL,
                seo_description TEXT NULL,
                focus_keyword TEXT NULL,
                published_at TEXT NULL,
                updated_at TEXT NOT NULL DEFAULT (DATETIME('now')),
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
            );",
            "CREATE INDEX IF NOT EXISTS idx_home ON posts (status, published_at DESC);",
            "CREATE INDEX IF NOT EXISTS idx_featured ON posts (is_featured, status, published_at DESC);",
            "CREATE INDEX IF NOT EXISTS idx_category ON posts (category, status, published_at DESC);",
            "CREATE INDEX IF NOT EXISTS idx_author ON posts (user_id);",

            // 4. post_stats
            "CREATE TABLE IF NOT EXISTS post_stats (
                post_id INTEGER PRIMARY KEY,
                views INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
            );",

            // 5. post_revisions
            "CREATE TABLE IF NOT EXISTS post_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                editor_id INTEGER NOT NULL,
                base_version INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'pending_review', 'applied')),
                payload_json TEXT NOT NULL,
                content_html TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now')),
                FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
                FOREIGN KEY (editor_id) REFERENCES users (id) ON DELETE RESTRICT
            );",
            "CREATE INDEX IF NOT EXISTS idx_revision_post ON post_revisions (post_id, id);",

            // 6. user_reset_tokens
            "CREATE TABLE IF NOT EXISTS user_reset_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                purpose TEXT NOT NULL CHECK(purpose IN ('activation', 'password_reset')),
                expires_at TEXT NOT NULL,
                used_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now')),
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            );",
            "CREATE INDEX IF NOT EXISTS idx_reset_user ON user_reset_tokens (user_id);",
            "CREATE INDEX IF NOT EXISTS idx_reset_expiry ON user_reset_tokens (expires_at);",

            // 7. media
            "CREATE TABLE IF NOT EXISTS media (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                original_name TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                variants_manifest TEXT NOT NULL,
                is_finalized INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now')),
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
            );",
            "CREATE INDEX IF NOT EXISTS idx_media_user ON media (user_id);",
            "CREATE INDEX IF NOT EXISTS idx_media_created ON media (created_at DESC);",

            // 8. media_refs
            "CREATE TABLE IF NOT EXISTS media_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                media_id TEXT NOT NULL,
                post_id INTEGER NOT NULL,
                revision_id INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now')),
                UNIQUE (media_id, post_id, revision_id),
                FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE,
                FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
            );",
            "CREATE INDEX IF NOT EXISTS idx_ref_post ON media_refs (post_id);",

            // 9. view_dedupe
            "CREATE TABLE IF NOT EXISTS view_dedupe (
                post_id INTEGER NOT NULL,
                ip_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                PRIMARY KEY (post_id, ip_hash),
                FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
            );",
            "CREATE INDEX IF NOT EXISTS idx_view_expiry ON view_dedupe (expires_at);",

            // 10. cache_purge_jobs
            "CREATE TABLE IF NOT EXISTS cache_purge_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NULL,
                event TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'processing', 'completed', 'failed')),
                attempts INTEGER NOT NULL DEFAULT 0,
                next_run_at TEXT NOT NULL DEFAULT (DATETIME('now')),
                lease_expires_at TEXT NULL,
                last_error TEXT NULL,
                completed_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
            );",
            "CREATE INDEX IF NOT EXISTS idx_purge_queue ON cache_purge_jobs (status, next_run_at);",

            // 11. migration_map
            "CREATE TABLE IF NOT EXISTS migration_map (
                source TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                source_id TEXT NOT NULL,
                target_id TEXT NOT NULL,
                checksum TEXT NULL,
                status TEXT NOT NULL DEFAULT 'completed',
                created_at TEXT NOT NULL DEFAULT (DATETIME('now')),
                PRIMARY KEY (source, entity_type, source_id)
            );",

            // 12. redirects
            "CREATE TABLE IF NOT EXISTS redirects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                old_path TEXT NOT NULL,
                path_hash TEXT NOT NULL UNIQUE,
                target_path TEXT NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 301,
                created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
            );",
        ];

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }
}
