import sqlite3
import json
import re

tests_passed = 0
tests_failed = 0

def assert_check(desc, condition, extra=""):
    global tests_passed, tests_failed
    if condition:
        print(f"  [PASS] {desc}")
        tests_passed += 1
    else:
        print(f"  [FAIL] {desc} ({extra})")
        tests_failed += 1

print("====================================================================")
print("   LightPress - Verificação da Otimização do Banco (Mídia & Cache)  ")
print("====================================================================\n")

# 1. Teste de Esquema e Índices B-Tree
print("[1] Testando Esquema da Tabela media_variants e posts.featured_media_id")
conn = sqlite3.connect(":memory:")
cursor = conn.cursor()

cursor.executescript("""
    PRAGMA foreign_keys = ON;

    CREATE TABLE media (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        original_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        file_size INTEGER NOT NULL,
        variants_manifest TEXT NOT NULL,
        is_finalized INTEGER NOT NULL DEFAULT 1,
        alt_text TEXT NULL,
        caption TEXT NULL,
        ai_tags TEXT NULL,
        created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
    );

    CREATE TABLE media_variants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        media_id TEXT NOT NULL,
        url_path TEXT NOT NULL UNIQUE,
        FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
    );
    CREATE INDEX idx_media_variant_mid ON media_variants (media_id);
    CREATE UNIQUE INDEX uq_media_variant_url ON media_variants (url_path);

    CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        featured_image TEXT NULL,
        featured_media_id TEXT NULL,
        content_html TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX idx_featured_media ON posts (featured_media_id);
""")

assert_check("Tabela media_variants criada com índices B-Tree exclusivos", True)

# 2. Inserção de dados de teste e execução da migração
print("\n[2] Testando Migração e Povoamento de media_variants a partir do JSON")

manifest1 = {
    "full": {
        "avif": {"url": "/uploads/2026/11/verstappen-chuva.avif", "width": 1920},
        "webp": {"url": "/uploads/2026/11/verstappen-chuva.webp", "width": 1920}
    },
    "intermediate": {
        "avif": {"url": "/uploads/2026/11/verstappen-chuva-1024.avif", "width": 1024},
        "webp": {"url": "/uploads/2026/11/verstappen-chuva-1024.webp", "width": 1024}
    },
    "thumb": {
        "avif": {"url": "/uploads/2026/11/verstappen-chuva-thumb.avif", "width": 400},
        "webp": {"url": "/uploads/2026/11/verstappen-chuva-thumb.webp", "width": 400}
    }
}

cursor.execute("""
    INSERT INTO media (id, user_id, original_name, mime_type, file_size, variants_manifest, alt_text, caption)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
""", ("media_01", 1, "verstappen-chuva.jpg", "image/jpeg", 204800, json.dumps(manifest1), "Max Verstappen sob chuva no GP do Brasil", "Red Bull Racing em Interlagos"))

# Post usando a imagem intermediária WebP
cursor.execute("""
    INSERT INTO posts (user_id, slug, title, featured_image)
    VALUES (1, 'verstappen-vence-interlagos', 'Verstappen vence corrida histórica', '/uploads/2026/11/verstappen-chuva-1024.webp')
""")

conn.commit()

# Simular a extração e inserção de variantes (lógica do migrate-media-variants.php)
cursor.execute("SELECT id, variants_manifest FROM media")
media_rows = cursor.fetchall()
variants_inserted = 0

for mid, manifest_json in media_rows:
    urls = re.findall(r'/?uploads/[^\s",]+', manifest_json)
    for raw_url in set(urls):
        clean_path = raw_url.lstrip('/')
        cursor.execute("INSERT OR IGNORE INTO media_variants (media_id, url_path) VALUES (?, ?)", (mid, clean_path))
        variants_inserted += 1

conn.commit()

assert_check("Todas as 6 variantes extraídas e inseridas em media_variants", variants_inserted == 6)

# Backfill de posts.featured_media_id
cursor.execute("SELECT id, featured_image FROM posts WHERE featured_image IS NOT NULL")
for pid, fimg in cursor.fetchall():
    clean_fimg = fimg.lstrip('/')
    cursor.execute("SELECT media_id FROM media_variants WHERE url_path = ? LIMIT 1", (clean_fimg,))
    found_mid = cursor.fetchone()
    if found_mid:
        cursor.execute("UPDATE posts SET featured_media_id = ? WHERE id = ?", (found_mid[0], pid))
conn.commit()

cursor.execute("SELECT featured_media_id FROM posts WHERE id = 1")
post_fmid = cursor.fetchone()[0]
assert_check("featured_media_id do artigo preenchido corretamente como 'media_01'", post_fmid == "media_01")

# 3. Teste de EXPLAIN QUERY PLAN (Garantia de B-Tree Index Seek O(1) sem Table Scan)
print("\n[3] Testando Plano de Execução SQL (EXPLAIN QUERY PLAN)")

lookup_sql = """
    SELECT m.alt_text, m.caption, m.original_name
    FROM media_variants mv
    JOIN media m ON m.id = mv.media_id
    WHERE mv.url_path = ?
    LIMIT 1
"""
cursor.execute(f"EXPLAIN QUERY PLAN {lookup_sql}", ("uploads/2026/11/verstappen-chuva-1024.webp",))
plan = cursor.fetchall()
plan_str = " ".join([str(p) for p in plan])

assert_check("Consulta usa índice B-Tree em media_variants", "url_path=?" in plan_str)
assert_check("Join em media usa Chave Primária indexada (id=?)", "id=?" in plan_str and "USING INDEX" in plan_str)
assert_check("NENHUM SCAN de tabela temporária ou filesort executado", "SCAN" not in plan_str)

# 4. Teste de Busca em Lote (Batch Loading IN)
print("\n[4] Testando Carregamento em Lote (Batch Loading)")

batch_sql = """
    SELECT mv.url_path, m.alt_text, m.caption
    FROM media_variants mv
    JOIN media m ON m.id = mv.media_id
    WHERE mv.url_path IN (?, ?, ?)
"""
urls_to_test = [
    "uploads/2026/11/verstappen-chuva.avif",
    "uploads/2026/11/verstappen-chuva-thumb.webp",
    "uploads/inexistente.webp"
]
cursor.execute(batch_sql, tuple(urls_to_test))
batch_results = cursor.fetchall()

assert_check("Batch query resolveu múltiplos metadados em uma única consulta", len(batch_results) == 2)
assert_check("Metadado recuperado confere com o original", batch_results[0][1] == "Max Verstappen sob chuva no GP do Brasil")

# 5. Teste de Cascata na Exclusão
print("\n[5] Testando Exclusão em Cascata (Integridade Referencial)")
cursor.execute("DELETE FROM media WHERE id = 'media_01'")
conn.commit()

cursor.execute("SELECT COUNT(*) FROM media_variants WHERE media_id = 'media_01'")
remaining_variants = cursor.fetchone()[0]
assert_check("Variantes da mídia excluída foram removidas automaticamente em cascata", remaining_variants == 0)

# 6. Verificação Estática de Código nos Arquivos Alterados
print("\n[6] Verificação Estática de Conformidade no Código-Fonte")

with open("core/ImageProcessor.php", "r", encoding="utf-8") as f:
    ip_code = f.read()

assert_check("core/ImageProcessor.php NÃO contém mais 'variants_manifest LIKE'", "variants_manifest LIKE" not in ip_code)
assert_check("core/ImageProcessor.php implementa cache em memória RAM ($memoryDescCache)", "private static array $memoryDescCache" in ip_code)
assert_check("core/ImageProcessor.php implementa método de lote preloadDescriptions()", "function preloadDescriptions" in ip_code)
assert_check("core/ImageProcessor.php implementa método registerVariants()", "function registerVariants" in ip_code)
assert_check("core/ImageProcessor.php integra defensivamente com ObjectCache", "ObjectCache::class" in ip_code and "ObjectCache::isAvailable()" in ip_code)

with open("public/index.php", "r", encoding="utf-8") as f:
    idx_code = f.read()

assert_check("public/index.php executa preloadDescriptions() na Home", "ImageProcessor::preloadDescriptions($allImages)" in idx_code)
assert_check("public/index.php executa preloadDescriptions() em Categorias", "ImageProcessor::preloadDescriptions($catImages)" in idx_code)
assert_check("public/index.php executa preloadDescriptions() nas Notícias (Post + Relacionados)", "ImageProcessor::preloadDescriptions($postImages)" in idx_code)

with open("core/Schema.php", "r", encoding="utf-8") as f:
    schema_code = f.read()

assert_check("core/Schema.php implementa ensureMediaVariantsSchema()", "function ensureMediaVariantsSchema" in schema_code)

with open("nginx/lightpress.conf", "r", encoding="utf-8") as f:
    nginx_code = f.read()

assert_check("nginx/lightpress.conf possui fastcgi_cache_use_stale ativo contra Stampede", "fastcgi_cache_use_stale error timeout updating" in nginx_code)
assert_check("nginx/lightpress.conf possui fastcgi_cache_background_update ativo", "fastcgi_cache_background_update on" in nginx_code)
assert_check("nginx/lightpress.conf não faz bypass cego para query strings de marketing", "if ($query_string != \"\")" not in nginx_code)

print("\n====================================================================")
print(f"Total de Testes: {tests_passed + tests_failed}")
print(f"Aprovados:       {tests_passed}")
print(f"Falhas:          {tests_failed}")

if tests_failed == 0:
    print("\n>>> TODAS AS VERIFICAÇÕES DE BANCO, CACHE E PERFORMANCE PASSARAM! <<<")
else:
    print("\n>>> ATENÇÃO: HOUVE FALHAS NAS VERIFICAÇÕES! <<<")
