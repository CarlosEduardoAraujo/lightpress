-- =====================================================================
-- LightPress / SpeedPress - Schema do Banco de Dados (MySQL 8.0+ / MariaDB 10.6+)
-- Codificação: utf8mb4_unicode_ci (Suporte a emojis e caracteres de 4 bytes)
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Tabela de Usuários e Perfis Administrativos
CREATE TABLE IF NOT EXISTS users (
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_role (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabela de Categorias Controladas
CREATE TABLE IF NOT EXISTS categories (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category_active (is_active, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir categoria padrão inicial
INSERT IGNORE INTO categories (id, slug, name, is_active) VALUES (1, 'geral', 'Geral', 1);

-- 3. Tabela Central de Artigos e Publicações
CREATE TABLE IF NOT EXISTS posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id SMALLINT UNSIGNED NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    title VARCHAR(500) NOT NULL,
    summary TEXT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'geral',
    tags TEXT NULL,
    featured_image VARCHAR(500) NULL,
    
    -- Conteúdo Desacoplado
    content_json MEDIUMTEXT NOT NULL,
    content_html MEDIUMTEXT NOT NULL,
    
    -- Controle de Publicação e Edição Concorrente
    status ENUM('draft', 'pending_review', 'scheduled', 'published', 'archived') NOT NULL DEFAULT 'draft',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    
    -- SEO Integrado Nativo
    seo_title VARCHAR(255) NULL,
    seo_description VARCHAR(500) NULL,
    focus_keyword VARCHAR(150) NULL,
    
    published_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Índices Estratégicos para Alta Concorrência
    INDEX idx_home (status, published_at DESC),
    INDEX idx_featured (is_featured, status, published_at DESC),
    INDEX idx_category (category, status, published_at DESC),
    INDEX idx_author (user_id),
    FULLTEXT INDEX idx_fulltext_search (title, summary, tags),
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabela Isolada de Estatísticas de Visualizações
CREATE TABLE IF NOT EXISTS post_stats (
    post_id INT UNSIGNED PRIMARY KEY,
    views BIGINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_post_stats_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabela de Revisões Editoriais e Snapshots Históricos
CREATE TABLE IF NOT EXISTS post_revisions (
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

-- 6. Tabela de Tokens de Ativação e Redefinição de Senha
CREATE TABLE IF NOT EXISTS user_reset_tokens (
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

-- 7. Tabela de Catálogo de Mídias Processadas
CREATE TABLE IF NOT EXISTS media (
    id VARCHAR(32) PRIMARY KEY,
    user_id SMALLINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(50) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    variants_manifest JSON NOT NULL,
    is_finalized TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_media_user (user_id),
    CONSTRAINT fk_media_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Vínculos de Mídias a Artigos/Revisões
CREATE TABLE IF NOT EXISTS media_refs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_id VARCHAR(32) NOT NULL,
    post_id INT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_media_ref (media_id, post_id, revision_id),
    INDEX idx_ref_post (post_id),
    CONSTRAINT fk_ref_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Deduplicação Atômica de Visualizações (Privacidade com HMAC)
CREATE TABLE IF NOT EXISTS view_dedupe (
    post_id INT UNSIGNED NOT NULL,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (post_id, ip_hash),
    INDEX idx_view_expiry (expires_at),
    CONSTRAINT fk_view_dedupe_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Fila Persistente de Purga de Cache Nginx FastCGI
CREATE TABLE IF NOT EXISTS cache_purge_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NULL,
    event VARCHAR(50) NOT NULL,
    payload_json TEXT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_expires_at DATETIME NULL,
    last_error TEXT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_purge_queue (status, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Mapeamento de Migração do WordPress
CREATE TABLE IF NOT EXISTS migration_map (
    source VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    target_id VARCHAR(100) NOT NULL,
    checksum CHAR(64) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'completed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, entity_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Tabela de Redirecionamentos 301 Canônicos
CREATE TABLE IF NOT EXISTS redirects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    old_path VARCHAR(500) NOT NULL,
    path_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    target_path VARCHAR(500) NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Tabela de Páginas Institucionais (Equipe, Contato, Termos, Privacidade, etc.)
CREATE TABLE IF NOT EXISTS pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id SMALLINT UNSIGNED NULL,
    slug VARCHAR(191) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    content_html MEDIUMTEXT NOT NULL,
    featured_image VARCHAR(500) NULL,
    seo_title VARCHAR(255) NULL,
    seo_description VARCHAR(500) NULL,
    status ENUM('published', 'draft', 'archived') NOT NULL DEFAULT 'published',
    views BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_page_status_slug (status, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Tabela de Configurações Dinâmicas do Site (Título, Favicon, Fuso Horário, etc.)
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` MEDIUMTEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
