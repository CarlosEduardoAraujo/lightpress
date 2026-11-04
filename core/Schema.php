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
     * Cria as tabelas para MySQL / MariaDB a partir do script SQL padrão.
     */
    private static function installMysql(PDO $pdo): void
    {
        $sqlFile = dirname(__DIR__) . '/bin/schema.sql';
        if (!file_exists($sqlFile)) {
            throw new RuntimeException("Arquivo de schema não encontrado: {$sqlFile}");
        }

        $sql = file_get_contents($sqlFile);
        $pdo->exec($sql);
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
                status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'pending_review', 'published', 'archived')),
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
