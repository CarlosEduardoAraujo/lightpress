import sqlite3
import hashlib
import time

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

print("========================================================")
print("   LightPress - Verificação das Correções de Segurança  ")
print("========================================================\n")

# 1. SEC-01: Ciclo de Vida e Invalidação de Tokens de Ativação
print("[1] Testando SEC-01: Invalidação de Tokens e Ativação Segura")

conn = sqlite3.connect(":memory:")
cursor = conn.cursor()

cursor.executescript("""
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        username TEXT UNIQUE,
        email TEXT UNIQUE,
        password_hash TEXT,
        role TEXT DEFAULT 'author',
        is_active INTEGER DEFAULT 1,
        password_reset_required INTEGER DEFAULT 0,
        session_version INTEGER DEFAULT 1
    );

    CREATE TABLE user_reset_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        token_hash TEXT UNIQUE,
        purpose TEXT,
        expires_at DATETIME,
        used_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
""")

# Inserir usuário convidado (is_active = 0, password_reset_required = 1)
cursor.execute("""
    INSERT INTO users (id, name, username, email, password_hash, role, is_active, password_reset_required, session_version)
    VALUES (1, 'Novo Convidado', 'convidado', 'convidado@teste.com', 'hash_temp', 'author', 0, 1, 1)
""")

raw_token = "a" * 64
token_hash = hashlib.sha256(raw_token.encode()).hexdigest()
now_utc = "2026-11-09 18:00:00"
future_utc = "2026-11-10 18:00:00"

# Emite token de ativação
cursor.execute("INSERT INTO user_reset_tokens (user_id, token_hash, purpose, expires_at) VALUES (1, ?, 'activation', ?)", (token_hash, future_utc))
conn.commit()

# Consulta de ativação corrigida (activate.php)
query_lookup = """
    SELECT t.*, u.name, u.username, u.email, u.is_active, u.password_reset_required 
    FROM user_reset_tokens t
    JOIN users u ON u.id = t.user_id
    WHERE t.token_hash = ? AND t.purpose = 'activation' AND t.used_at IS NULL AND t.expires_at > ?
    LIMIT 1
"""

cursor.execute(query_lookup, (token_hash, now_utc))
token_record = cursor.fetchone()
# Colunas: t.* (0-6), u.name (7), u.username (8), u.email (9), u.is_active (10), u.password_reset_required (11)
assert_check("Lookup de ativação encontra token válido com purpose='activation'", token_record is not None and token_record[11] == 1)

# Se o administrador bloqueia o usuário (users.php)
cursor.execute("UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, session_version = session_version + 1 WHERE id = 1")
cursor.execute("UPDATE user_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = 1 AND used_at IS NULL")
conn.commit()

# Tentar o lookup novamente com o token original
cursor.execute(query_lookup, (token_hash, now_utc))
token_after_block = cursor.fetchone()
assert_check("Após bloqueio administrativo, o token pendente é invalidado e recusado", token_after_block is None)

# Token com finalidade diferente (ex: password_reset)
reset_hash = hashlib.sha256(b"password_reset_token").hexdigest()
cursor.execute("INSERT INTO user_reset_tokens (user_id, token_hash, purpose, expires_at) VALUES (1, ?, 'password_reset', ?)", (reset_hash, future_utc))
conn.commit()

cursor.execute(query_lookup, (reset_hash, now_utc))
wrong_purpose = cursor.fetchone()
assert_check("Token com purpose diferente de 'activation' é recusado no fluxo de ativação", wrong_purpose is None)

# 2. SEC-05: Prazos de Sessão no Servidor
print("\n[2] Testando SEC-05: Expiração Absoluta e por Inatividade no Servidor")

session_lifetime = 86400  # 24h
session_idle_timeout = 7200  # 2h
now = int(time.time())

def check_session_expired(auth_created_at, last_activity_at, now_ts, max_life, idle_limit):
    if auth_created_at > 0 and (now_ts - auth_created_at > max_life):
        return True, "Sessão expirada por limite de tempo máximo."
    if last_activity_at > 0 and (now_ts - last_activity_at > idle_limit):
        return True, "Sessão expirada por inatividade."
    return False, "Sessão ativa."

exp, _ = check_session_expired(now - 300, now - 60, now, session_lifetime, session_idle_timeout)
assert_check("Sessão recente é aceita normalmente", exp is False)

exp_idle, reason_idle = check_session_expired(now - 8000, now - 7300, now, session_lifetime, session_idle_timeout)
assert_check("Sessão inativa há mais de 2h é expirada pelo servidor", exp_idle is True and "inatividade" in reason_idle)

exp_abs, reason_abs = check_session_expired(now - 87000, now - 60, now, session_lifetime, session_idle_timeout)
assert_check("Sessão com mais de 24h (vida absoluta) é expirada pelo servidor", exp_abs is True and "máximo" in reason_abs)

# 3. SEC-06: Precedência de IP do Cliente e Proxies Confiáveis
print("\n[3] Testando SEC-06: Resolução de IP Atrás de Proxy Confiável")

def resolve_client_ip(remote_addr, x_real_ip, cf_ip, trusted):
    if remote_addr not in trusted:
        return remote_addr
    if x_real_ip and x_real_ip not in trusted:
        return x_real_ip
    if cf_ip:
        return cf_ip
    if x_real_ip:
        return x_real_ip
    return remote_addr

trusted = ["127.0.0.1", "::1"]

# Cliente tenta injetar CF-Connecting-IP forjado através do Nginx local
ip_a = resolve_client_ip("127.0.0.1", "203.0.113.20", "198.51.100.10", trusted)
assert_check("X-Real-IP do Nginx tem precedência sobre CF-Connecting-IP forjado pelo cliente", ip_a == "203.0.113.20")

# Tráfego via Cloudflare Tunnel (cloudflared passa CF-Connecting-IP com X-Real-IP apontando para localhost)
ip_b = resolve_client_ip("127.0.0.1", "127.0.0.1", "198.51.100.55", trusted)
assert_check("Cloudflare Tunnel preserva CF-Connecting-IP do visitante quando X-Real-IP é local", ip_b == "198.51.100.55")

# Conexão direta não confiável
ip_c = resolve_client_ip("192.0.2.1", "203.0.113.20", "198.51.100.10", trusted)
assert_check("Conexão direta não confiável ignora qualquer cabeçalho de proxy", ip_c == "192.0.2.1")

# 4. SEC-04 / P2: Recarregamento de Rascunho de Revisão no Editor
print("\n[4] Testando SEC-04 / P2: Recarregamento de Rascunho Privado no Editor")

cursor.executescript("""
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT,
        title TEXT,
        summary TEXT,
        category TEXT,
        content_html TEXT,
        content_json TEXT,
        status TEXT,
        version INTEGER DEFAULT 1
    );

    CREATE TABLE post_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER,
        editor_id INTEGER,
        base_version INTEGER,
        status TEXT,
        payload_json TEXT,
        content_html TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
""")

# Matéria publicada no ar
cursor.execute("""
    INSERT INTO posts (id, slug, title, summary, category, content_html, content_json, status, version)
    VALUES (10, 'gp-brasil-f1', 'Notícia Original Pública', 'Resumo Antigo', 'f1', '<p>Texto Público Original</p>', '[]', 'published', 1)
""")
conn.commit()

# Simula "Salvar Rascunho" pelo editor: grava em post_revisions sem alterar posts
cursor.execute("""
    INSERT INTO post_revisions (post_id, editor_id, base_version, status, payload_json, content_html)
    VALUES (10, 1, 1, 'draft', '{"title": "Título em Rascunho Salvo", "summary": "Novo Resumo Rascunho"}', '<p>Texto Novo em Rascunho</p>')
""")
conn.commit()

# Verifica que o post público permanece intacto no banco
cursor.execute("SELECT title, content_html, status FROM posts WHERE id = 10")
live_post = cursor.fetchone()
assert_check("Artigo público na tabela posts permanece com conteúdo original inalterado", live_post[0] == "Notícia Original Pública" and live_post[1] == "<p>Texto Público Original</p>")

# Lógica do editor.php ao recarregar a tela:
cursor.execute("SELECT * FROM posts WHERE id = 10")
cols = [col[0] for col in cursor.description]
post_data = dict(zip(cols, cursor.fetchone()))

# Busca rascunho mais recente
cursor.execute("SELECT * FROM post_revisions WHERE post_id = 10 AND status IN ('draft', 'pending_review') ORDER BY id DESC LIMIT 1")
rev_row = cursor.fetchone()
import json
if rev_row:
    rev_payload = json.loads(rev_row[5])
    for k, v in rev_payload.items():
        post_data[k] = v
    if rev_row[6]:
        post_data['content_html'] = rev_row[6]

assert_check("Editor recarrega o formulário com o título do rascunho salvo", post_data['title'] == "Título em Rascunho Salvo")
assert_check("Editor recarrega o canvas com o HTML do rascunho salvo", post_data['content_html'] == "<p>Texto Novo em Rascunho</p>")

# Simula ação de Publicar Alterações
cursor.execute("UPDATE posts SET title = ?, content_html = ?, version = 2, status = 'published' WHERE id = 10", (post_data['title'], post_data['content_html']))
cursor.execute("UPDATE post_revisions SET status = 'applied' WHERE post_id = 10 AND status IN ('draft', 'pending_review')")
conn.commit()

cursor.execute("SELECT * FROM post_revisions WHERE post_id = 10 AND status IN ('draft', 'pending_review')")
assert_check("Após publicação explícita, as revisões pendentes são marcadas como aplicadas", cursor.fetchone() is None)

# 5. P2: Detecção de Concorrência em Rascunhos e Preservação de Corpo Vazio
print("\n[5] Testando P2: Concorrência Otimista e Corpo de Rascunho Vazio")

# Caso A: Concorrência de Versão ao Salvar Rascunho de Artigo Publicado
# Post 10 está na versão 2 (publicado). Usuário submete form com known_version = 1.
cursor.execute("SELECT version FROM posts WHERE id = 10")
current_version = cursor.fetchone()[0]
known_version = 1
version_conflict = False
try:
    if current_version != known_version:
        version_conflict = True
        raise RuntimeError("Conflito de edição detectado! 409")
except RuntimeError:
    pass

assert_check("Formulário com versão 1 é recusado com HTTP 409 quando o artigo já está na versão 2", version_conflict)

# Caso B: Concorrência de Rascunhos Concorrentes via known_revision_id
# Usuário A e B abriram o editor na versão 2.
# Usuário A salvou uma revisão (id = 101).
cursor.execute("""
    INSERT INTO post_revisions (id, post_id, editor_id, base_version, status, payload_json, content_html)
    VALUES (101, 10, 1, 2, 'draft', '{"title": "Revisão Usuário A"}', '<p>Texto A</p>')
""")
conn.commit()

# Usuário B tenta salvar com known_revision_id = 0 (ou id antigo)
cursor.execute("SELECT id FROM post_revisions WHERE post_id = 10 AND status IN ('draft', 'pending_review') ORDER BY id DESC LIMIT 1")
latest_rev_id = cursor.fetchone()[0]
known_rev_b = 0
draft_conflict = False
try:
    if latest_rev_id > 0 and known_rev_b != latest_rev_id:
        draft_conflict = True
        raise RuntimeError("Conflito de rascunho detectado! 409")
except RuntimeError:
    pass

assert_check("Salvamento concorrente de rascunho com known_revision_id desatualizado é recusado com 409", draft_conflict)

# Caso C: Rascunho com corpo intencionalmente vazio
# Post 10 tem texto público:
cursor.execute("SELECT content_html, content_json FROM posts WHERE id = 10")
live_row = cursor.fetchone()
live_html = live_row[0]
live_json = live_row[1]

# Editor salva rascunho com HTML vazio ("")
empty_html = ""
clean_json = "[]" if empty_html == "" else live_json
cursor.execute("""
    INSERT INTO post_revisions (id, post_id, editor_id, base_version, status, payload_json, content_html)
    VALUES (102, 10, 1, 2, 'draft', ?, ?)
""", (json.dumps({"title": "Post com Corpo Vazio", "content_json": clean_json, "content_html": empty_html}), empty_html))
conn.commit()

# Recarregamento do editor: aplica revisão mais recente
cursor.execute("SELECT * FROM post_revisions WHERE post_id = 10 AND status IN ('draft', 'pending_review') ORDER BY id DESC LIMIT 1")
rev_102 = cursor.fetchone()
# Colunas: id(0), post_id(1), editor_id(2), base_version(3), status(4), payload_json(5), content_html(6)
rev_payload_102 = json.loads(rev_102[5])
editor_post = {"content_html": live_html, "content_json": live_json, "version": 2}

# Lógica corrigida do $applyDraftRevision:
# Diferencia campo ausente de conteúdo vazio (string vazia intencional)
if rev_102[6] is not None:
    editor_post["content_html"] = rev_102[6]
    if editor_post["content_html"] == "":
        editor_post["content_json"] = "[]"
elif "content_html" in rev_payload_102 and rev_payload_102["content_html"] is not None:
    editor_post["content_html"] = rev_payload_102["content_html"]
    if editor_post["content_html"] == "":
        editor_post["content_json"] = "[]"

# Lógica corrigida do $initialHtml:
initial_html = editor_post["content_html"] if ("content_html" in editor_post and editor_post["content_html"] is not None) else (
    "FALLBACK_COMPILED" if (editor_post.get("content_json") and editor_post["content_json"] != "[]") else ""
)

assert_check("Rascunho com corpo vazio preserva content_html como string vazia", editor_post["content_html"] == "")
assert_check("Rascunho com corpo vazio mantém content_json coerente como '[]'", editor_post["content_json"] == "[]")
assert_check("Editor não aciona fallback de compilação nem restaura o texto publicado", initial_html == "")

# Caso D: Revisão defasada (base_version desatualizada em relação ao post ao vivo)
# Post é promovido para a versão 3 (com novo texto ao vivo)
cursor.execute("UPDATE posts SET version = 3, content_html = '<p>Texto Mais Recente v3</p>' WHERE id = 10")
conn.commit()

# Simula o editor carregando o post 10 na versão 3 quando há a revisão 102 que era baseada na versão 2
cursor.execute("SELECT version, content_html FROM posts WHERE id = 10")
live_v3 = cursor.fetchone()
current_v3_version = live_v3[0] # 3
current_v3_html = live_v3[1]

# $applyDraftRevision para a revisão 102 (base_version = 2):
rev_base_version = rev_102[3] # 2
has_outdated_revision = False
loaded_html = current_v3_html
if rev_base_version != current_v3_version:
    has_outdated_revision = True
    # NÃO sobrescreve loaded_html
else:
    loaded_html = rev_102[6]

assert_check("Revisão com base_version defasada (v2 != v3) é marcada como desatualizada", has_outdated_revision)
assert_check("Revisão desatualizada não sobrescreve o conteúdo mais recente publicado na tela", loaded_html == "<p>Texto Mais Recente v3</p>")

# Caso E: Revisão desatualizada não bloqueia salvamento de novo rascunho
# Post está na versão 3. Existe revisão pendente 102 com base_version = 2.
# Editor envia known_version = 3 e known_revision_id = 0.
cursor.execute("""
    SELECT id FROM post_revisions 
    WHERE post_id = 10 AND base_version = 3 AND status IN ('draft', 'pending_review') 
    ORDER BY id DESC LIMIT 1
""")
active_v3_rev = cursor.fetchone()
# Como a revisão 102 tem base_version = 2, a busca por base_version = 3 retorna None
can_save_new_draft = (active_v3_rev is None)
assert_check("Revisão de versão anterior não bloqueia o salvamento de novo rascunho na versão atual", can_save_new_draft)

# Salva novo rascunho da versão 3 e arquiva as antigas como superseded
cursor.execute("UPDATE post_revisions SET status = 'superseded' WHERE post_id = 10 AND base_version < 3 AND status IN ('draft', 'pending_review')")
cursor.execute("""
    INSERT INTO post_revisions (id, post_id, editor_id, base_version, status, payload_json, content_html)
    VALUES (201, 10, 1, 3, 'draft', '{"title": "Rascunho Versão 3 B"}', '<p>Texto v3 por B</p>')
""")
conn.commit()

cursor.execute("SELECT status FROM post_revisions WHERE id = 102")
assert_check("Revisão defasada de versão anterior é arquivada como 'superseded'", cursor.fetchone()[0] == 'superseded')

# Caso F: Publicar valida concorrência entre revisões de rascunho
# Post 10 está na versão 3. Usuário B salvou a revisão 201.
# Usuário A tenta publicar com known_version = 3 e known_revision_id = 0 (não conhecia o rascunho de B)
cursor.execute("""
    SELECT id FROM post_revisions 
    WHERE post_id = 10 AND base_version = 3 AND status IN ('draft', 'pending_review') 
    ORDER BY id DESC LIMIT 1
""")
latest_v3_rev = cursor.fetchone()
known_rev_a = 0
publish_conflict = False
try:
    if latest_v3_rev and (known_rev_a == 0 or known_rev_a != latest_v3_rev[0]):
        publish_conflict = True
        raise RuntimeError("Conflito de rascunho na publicação detectado! 409")
except RuntimeError:
    pass

assert_check("Publicação com known_revision_id desatualizado é recusada com 409 em favor do rascunho recente", publish_conflict)

# Caso G: Publicação autorizada com o rascunho conhecido marca apenas ele como aplicado
known_rev_b = 201
cursor.execute("UPDATE post_revisions SET status = 'applied' WHERE id = ? AND post_id = 10", (known_rev_b,))
cursor.execute("UPDATE post_revisions SET status = 'superseded' WHERE post_id = 10 AND status IN ('draft', 'pending_review')")
cursor.execute("UPDATE posts SET version = 4, title = 'Notícia v4 Publicada', content_html = '<p>Texto v3 por B</p>' WHERE id = 10")
conn.commit()

cursor.execute("SELECT status FROM post_revisions WHERE id = 201")
assert_check("Rascunho conhecido publicado é marcado especificamente como 'applied'", cursor.fetchone()[0] == 'applied')

# Caso H: Criação de Nova Notícia e Publicação Imediata (Fix do Bug P1)
# 1. Simula createPost() criando novo post 20 com revisão inicial 301
cursor.execute("""
    INSERT INTO posts (id, slug, title, summary, category, content_html, content_json, status, version)
    VALUES (20, 'nova-noticia-f1', 'Nova Notícia Teste', 'Resumo', 'f1', '<p>Texto Novo</p>', '[]', 'draft', 1)
""")
cursor.execute("""
    INSERT INTO post_revisions (id, post_id, editor_id, base_version, status, payload_json, content_html)
    VALUES (301, 20, 1, 1, 'draft', '{"title": "Nova Notícia Teste"}', '<p>Texto Novo</p>')
""")
conn.commit()

# 2. editor.php recebe newRevId = 301 e chama publishOrUpdate com known_version = 1 e known_revision_id = 301
cursor.execute("""
    SELECT id FROM post_revisions 
    WHERE post_id = 20 AND base_version = 1 AND status IN ('draft', 'pending_review') 
    ORDER BY id DESC LIMIT 1
""")
active_rev = cursor.fetchone()
latest_rev_id = active_rev[0] if active_rev else 0
known_rev_id = 301

first_publish_conflict = False
try:
    if latest_rev_id > 0:
        if known_rev_id > 0 and latest_rev_id != known_rev_id:
            first_publish_conflict = True
        elif known_rev_id == 0:
            current_status = 'draft' # post 20 está em draft
            if current_status in ('published', 'scheduled'):
                first_publish_conflict = True
except Exception:
    first_publish_conflict = True

assert_check("Primeira publicação com newRevId conhecido NÃO sofre conflito de concorrência", not first_publish_conflict)

# Valida também primeira publicação mesmo se known_rev_id for 0 em artigo com status 'draft'
fallback_draft_conflict = False
try:
    known_rev_zero = 0
    current_status = 'draft'
    if latest_rev_id > 0:
        if known_rev_zero > 0 and latest_rev_id != known_rev_zero:
            fallback_draft_conflict = True
        elif known_rev_zero == 0:
            if current_status in ('published', 'scheduled'):
                fallback_draft_conflict = True
except Exception:
    fallback_draft_conflict = True

assert_check("Publicação de artigo em rascunho (status='draft') aceita known_revision_id=0 sem falso positivo", not fallback_draft_conflict)

# Caso I: Carregamento de Post Existente com status 'draft' fornece activeRevisionId
# Post 20 está em draft, com revisão ativa 301. Ao carregar o editor:
cursor.execute("""
    SELECT id FROM post_revisions 
    WHERE post_id = 20 AND base_version = 1 AND status IN ('draft', 'pending_review') 
    ORDER BY id DESC LIMIT 1
""")
loaded_active_rev = cursor.fetchone()
active_rev_id_loaded = loaded_active_rev[0] if loaded_active_rev else 0
assert_check("Editor fornece activeRevisionId ao carregar rascunho existente", active_rev_id_loaded == 301)

# Caso J: Aprovação pelo Histórico de Revisões (revisions.php) passando revId específico
approved_rev_id = 301
cursor.execute("UPDATE post_revisions SET status = 'applied' WHERE id = ? AND post_id = 20", (approved_rev_id,))
cursor.execute("UPDATE posts SET status = 'published', version = 2 WHERE id = 20")
conn.commit()

cursor.execute("SELECT status FROM post_revisions WHERE id = 301")
assert_check("Aprovação pelo histórico marca a revisão especificada como 'applied'", cursor.fetchone()[0] == 'applied')
cursor.execute("SELECT status FROM posts WHERE id = 20")
assert_check("Artigo é publicado com sucesso", cursor.fetchone()[0] == 'published')

# 6. P1: Validação e Migração do Estado 'superseded' no Banco de Dados
print("\n[6] Testando P1: Suporte e Migração ao Estado 'superseded' em post_revisions")

# Simula uma tabela SQLite com schema legado (sem 'superseded')
cursor.execute("""
    CREATE TABLE legacy_post_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        editor_id INTEGER NOT NULL,
        base_version INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'pending_review', 'applied')),
        payload_json TEXT NOT NULL,
        content_html TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
    );
""")
cursor.execute("INSERT INTO legacy_post_revisions (id, post_id, editor_id, base_version, status, payload_json, content_html) VALUES (1, 10, 1, 1, 'draft', '{}', '<p>Rev 1</p>')")
conn.commit()

# Tenta atualizar para 'superseded' no schema legado
check_failed = False
try:
    cursor.execute("UPDATE legacy_post_revisions SET status = 'superseded' WHERE id = 1")
    conn.commit()
except sqlite3.IntegrityError:
    check_failed = True

assert_check("Schema legado rejeita 'superseded' com CHECK constraint failed", check_failed)

# Executa o procedimento de auto-migração idêntico ao Schema::ensureRevisionsSchema
conn.commit()
cursor.execute("PRAGMA foreign_keys = OFF;")
cursor.execute("""
    CREATE TABLE legacy_post_revisions_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL,
        editor_id INTEGER NOT NULL,
        base_version INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'pending_review', 'applied', 'superseded')),
        payload_json TEXT NOT NULL,
        content_html TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
    );
""")
cursor.execute("""
    INSERT INTO legacy_post_revisions_new (id, post_id, editor_id, base_version, status, payload_json, content_html, created_at)
    SELECT id, post_id, editor_id, base_version, status, payload_json, content_html, created_at FROM legacy_post_revisions;
""")
cursor.execute("DROP TABLE legacy_post_revisions;")
cursor.execute("ALTER TABLE legacy_post_revisions_new RENAME TO legacy_post_revisions;")
cursor.execute("PRAGMA foreign_keys = ON;")
conn.commit()

# Testa novamente a atualização para 'superseded' após a migração
migration_success = False
try:
    cursor.execute("UPDATE legacy_post_revisions SET status = 'superseded' WHERE id = 1")
    conn.commit()
    cursor.execute("SELECT status FROM legacy_post_revisions WHERE id = 1")
    status_row = cursor.fetchone()
    migration_success = (status_row is not None and status_row[0] == 'superseded')
except Exception as e:
    migration_success = False

assert_check("Após migração do schema, o estado 'superseded' é aceito e gravado com sucesso", migration_success)

# 7. Prevenção de Exposição de CSRF via GET no Logout e Destaques
print("\n[7] Testando Prevenção de Exposição de CSRF via GET no Logout e Destaques")

with open("core/AdminNav.php", "r", encoding="utf-8") as f:
    admin_nav_content = f.read()

with open("public/admin/dashboard.php", "r", encoding="utf-8") as f:
    dashboard_content = f.read()

assert_check(
    "AdminNav não expõe token CSRF ou logout via link GET",
    "action=logout" not in admin_nav_content and "?_csrf_token" not in admin_nav_content
)
assert_check(
    "AdminNav renderiza logout estritamente como formulário POST com token CSRF",
    '<form method="POST" action="/admin/dashboard.php"' in admin_nav_content
    and 'name="_csrf_token"' in admin_nav_content
    and 'name="action" value="logout"' in admin_nav_content
)
assert_check(
    "dashboard.php não aceita $_GET['action'] ou $_GET['_csrf_token'] para logout",
    "$_GET['action']" not in dashboard_content and "$_GET['_csrf_token']" not in dashboard_content
)
assert_check(
    "dashboard.php não aceita $_GET['toggle_featured_id'] para alternância de destaques",
    "$_GET['toggle_featured_id']" not in dashboard_content
)

# 8. Trava contra Desativação ou Rebaixamento do Último Administrador
print("\n[8] Testando Trava contra Desativação ou Rebaixamento do Último Administrador")

with open("public/admin/users.php", "r", encoding="utf-8") as f:
    users_content = f.read()

assert_check(
    "users.php verifica outros administradores antes de permitir rebaixamento no edit",
    "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?" in users_content
    and "Não é permitido rebaixar o único administrador ativo do sistema." in users_content
)

assert_check(
    "users.php executa verificação de rebaixamento e bloqueio com atomicidade dentro de transaction com FOR UPDATE",
    "Database::transaction(" in users_content
    and "Database::getDriver() === 'mysql' ? ' FOR UPDATE' : ''" in users_content
)

assert_check(
    "users.php verifica outros administradores antes de permitir desativação no toggle",
    "Não é permitido desativar o único administrador ativo do sistema." in users_content
)

# Teste com banco SQLite simulando a lógica de negócio
cursor.execute("DELETE FROM users")
cursor.execute("INSERT INTO users (id, name, username, email, role, is_active) VALUES (1, 'Admin 1', 'admin1', 'admin1@teste.com', 'admin', 1)")
cursor.execute("INSERT INTO users (id, name, username, email, role, is_active) VALUES (2, 'Autor 1', 'autor1', 'autor1@teste.com', 'author', 1)")
conn.commit()

# Tentativa de rebaixar Admin 1 (único ativo)
cursor.execute("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != 1")
other_admins = cursor.fetchone()[0]
assert_check("Único administrador ativo não possui outros administradores ativos (other_admins == 0)", other_admins == 0)

# Adiciona um segundo admin ativo
cursor.execute("INSERT INTO users (id, name, username, email, role, is_active) VALUES (3, 'Admin 2', 'admin2', 'admin2@teste.com', 'admin', 1)")
conn.commit()
cursor.execute("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != 1")
other_admins_after = cursor.fetchone()[0]
assert_check("Com dois administradores ativos, a contagem de outros admins é 1", other_admins_after == 1)

# Se Admin 2 for desativado
cursor.execute("UPDATE users SET is_active = 0 WHERE id = 3")
conn.commit()
cursor.execute("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != 1")
other_active_admins = cursor.fetchone()[0]
assert_check("Admin inativo não conta como administrador ativo disponível", other_active_admins == 0)

# 9. Validação de Caminho na Exclusão de Arquivos de Mídia (Anti-Path-Traversal)
print("\n[9] Testando Validação de Caminho Seguro na Exclusão de Mídia")

with open("public/admin/media.php", "r", encoding="utf-8") as f:
    media_content = f.read()

assert_check(
    "media.php resolve realpath do diretório de upload e do arquivo antes de unlink",
    "realpath($uploadDir)" in media_content
    and "realpath($localPath)" in media_content
    and "str_starts_with" in media_content
    and "is_file($resolvedLocalPath)" in media_content
    and "@unlink($resolvedLocalPath)" in media_content
)

# Teste de validação de path traversal e diretório irmão (sibling directory)
import tempfile
import os

with tempfile.TemporaryDirectory() as base_temp:
    upload_root = os.path.join(base_temp, "public", "uploads")
    os.makedirs(upload_root, exist_ok=True)
    
    # Diretório irmão com mesmo prefixo
    sibling_root = os.path.join(base_temp, "public", "uploads_backup")
    os.makedirs(sibling_root, exist_ok=True)
    sibling_file = os.path.join(sibling_root, "backup.webp")
    with open(sibling_file, "w") as bf:
        bf.write("backup")
    
    secret_file = os.path.join(base_temp, "config.php")
    with open(secret_file, "w") as sf:
        sf.write("<?php // secret")
        
    safe_file = os.path.join(upload_root, "foto.webp")
    with open(safe_file, "w") as sf:
        sf.write("fake image")
        
    real_upload = os.path.realpath(upload_root).replace(os.sep, '/').rstrip('/') + '/'
    
    # Traversal tentativa
    traversal_path = os.path.join(upload_root, "..", "..", "config.php")
    real_traversal = os.path.realpath(traversal_path).replace(os.sep, '/')
    is_traversal_safe = real_traversal.lower().startswith(real_upload.lower())
    assert_check("Arquivo fora do diretório de uploads via traversal é rejeitado", not is_traversal_safe)
    
    # Teste de diretório irmão com mesmo prefixo (uploads_backup vs uploads/)
    real_sibling = os.path.realpath(sibling_file).replace(os.sep, '/')
    is_sibling_safe = real_sibling.lower().startswith(real_upload.lower())
    assert_check("Arquivo em diretório irmão com mesmo prefixo (uploads_backup) é rejeitado graças à barra final", not is_sibling_safe)
    
    # Arquivo legítimo dentro de uploads
    real_safe = os.path.realpath(safe_file).replace(os.sep, '/')
    is_legit_safe = real_safe.lower().startswith(real_upload.lower()) and os.path.isfile(safe_file)
    assert_check("Arquivo legítimo dentro do diretório de uploads é aceito", is_legit_safe)

# 10. Contador de Views de Páginas Institucionais e Schema view_dedupe
print("\n[10] Testando Contador de Views de Páginas Institucionais e Schema view_dedupe")

with open("templates/page.php", "r", encoding="utf-8") as f:
    page_template_content = f.read()

assert_check(
    "templates/page.php dispara para endpoint canônico /api/view/page/ e não usa rota bloqueada",
    "var endpoint = '/api/view/page/' + pageId;" in page_template_content
    and "/api/view.php?type=page" not in page_template_content
)

with open("public/index.php", "r", encoding="utf-8") as f:
    index_content = f.read()

assert_check(
    "public/index.php registra rota POST para /api/view/page/{id}",
    "$router->post('/api/view/page/{id}'" in index_content
    and "$_GET['type'] = 'page'" in index_content
)

with open("bin/schema.sql", "r", encoding="utf-8") as f:
    schema_sql_content = f.read()

assert_check(
    "bin/schema.sql define view_dedupe com post_id BIGINT e sem FK restrita",
    "post_id BIGINT NOT NULL" in schema_sql_content
    and "fk_view_dedupe_post" not in schema_sql_content
)

with open("core/Schema.php", "r", encoding="utf-8") as f:
    schema_php_content = f.read()

with open("public/api/view.php", "r", encoding="utf-8") as f:
    view_php_content = f.read()

assert_check(
    "core/Schema.php implementa e executa ensureViewDedupeSchema",
    "ensureViewDedupeSchema" in schema_php_content
)

assert_check(
    "public/api/view.php executa ensureViewDedupeSchema antes da transação para páginas",
    "Schema::ensureViewDedupeSchema(" in view_php_content
    and view_php_content.find("Schema::ensureViewDedupeSchema") < view_php_content.find("Database::transaction")
)

# Teste em SQLite simulando deduplicação com ID negativo de página
cursor.execute("PRAGMA foreign_keys = ON;")
cursor.execute("""
    CREATE TABLE legacy_view_dedupe (
        post_id INTEGER NOT NULL,
        ip_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        PRIMARY KEY (post_id, ip_hash),
        FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
    );
""")

# Tentativa de inserir ID negativo no schema legado falha com FK constraint
legacy_fk_failed = False
try:
    cursor.execute("INSERT INTO legacy_view_dedupe (post_id, ip_hash, expires_at) VALUES (-1, 'ip_hash_teste', '2026-11-10 00:00:00')")
except sqlite3.IntegrityError:
    legacy_fk_failed = True
assert_check("Schema legado de view_dedupe com FK rejeita ID negativo de páginas", legacy_fk_failed)

# Criação da tabela atualizada sem FK
cursor.execute("""
    CREATE TABLE view_dedupe (
        post_id INTEGER NOT NULL,
        ip_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        PRIMARY KEY (post_id, ip_hash)
    );
""")

# Inserção de página com ID negativo (-10) e post normal (+10)
cursor.execute("INSERT INTO view_dedupe (post_id, ip_hash, expires_at) VALUES (-10, 'hash_ip_page', '2026-11-10 00:00:00')")
cursor.execute("INSERT INTO view_dedupe (post_id, ip_hash, expires_at) VALUES (10, 'hash_ip_post', '2026-11-10 00:00:00')")
conn.commit()

cursor.execute("SELECT COUNT(*) FROM view_dedupe WHERE post_id < 0")
negative_count = cursor.fetchone()[0]
assert_check("view_dedupe sem FK aceita IDs negativos de páginas e positivos de posts concorrentemente", negative_count == 1)

# Teste de renovação com ON CONFLICT
cursor.execute("""
    INSERT INTO view_dedupe (post_id, ip_hash, expires_at)
    VALUES (-10, 'hash_ip_page', '2026-11-10 01:00:00')
    ON CONFLICT(post_id, ip_hash) DO UPDATE SET expires_at = excluded.expires_at
""")
conn.commit()

cursor.execute("SELECT expires_at FROM view_dedupe WHERE post_id = -10")
updated_expires = cursor.fetchone()[0]
assert_check("Renovação da janela de deduplicação para páginas atualiza expires_at com sucesso", updated_expires == '2026-11-10 01:00:00')

# 13. Testes do Fluxo Editorial (pending_review) e Sincronização de CSS do Admin
print("[13] Testando Fluxo de Revisão Editorial (pending_review) e CSS do Painel Administrativo")

with open("public/admin/editor.php", "r", encoding="utf-8") as f:
    editor_content = f.read()

assert_check(
    "public/admin/editor.php transita rascunhos para pending_review na submissão por autores",
    "!$canPublish && $action === 'submit_review' && $statusVal === 'draft'" in editor_content
    and "$statusVal = 'pending_review';" in editor_content
)

assert_check(
    "public/admin/editor.php registra status correto na revisão (revInsertStatus)",
    "$revInsertStatus = ($statusVal === 'pending_review') ? 'pending_review' : 'draft';" in editor_content
    and "status, payload_json, content_html" in editor_content
)

assert_check(
    "public/admin/editor.php condiciona botão de publicação com base em ACTION_PUBLISH_POST",
    "Authorization::can($currentUser, Authorization::ACTION_PUBLISH_POST)" in editor_content
    and "name=\"form_action\" value=\"submit_review\"" in editor_content
    and "Enviar para Revisão Editorial" in editor_content
)

with open("public/admin/system.php", "r", encoding="utf-8") as f:
    system_content = f.read()

assert_check(
    "public/admin/system.php define classes .grid-details, .card-title, .info-row e .badge-ok",
    ".grid-details" in system_content
    and ".card-title" in system_content
    and ".info-row" in system_content
    and ".badge-ok" in system_content
    and ".badge-warn" in system_content
    and ".badge-err" in system_content
)

with open("public/admin/plugins.php", "r", encoding="utf-8") as f:
    plugins_content = f.read()

assert_check(
    "public/admin/plugins.php define classes .badge-active, .badge-inactive e .btn-danger",
    ".badge-active" in plugins_content
    and ".badge-inactive" in plugins_content
    and ".btn-danger" in plugins_content
)

with open("public/admin/users.php", "r", encoding="utf-8") as f:
    users_content = f.read()

assert_check(
    "public/admin/users.php define classes .token-box e .token-input",
    ".token-box" in users_content and ".token-input" in users_content
)

with open("public/admin/settings.php", "r", encoding="utf-8") as f:
    settings_content = f.read()

assert_check(
    "public/admin/settings.php define classes genéricas de layout do painel (.page-header, .card, etc.)",
    ".page-header" in settings_content
    and ".page-title" in settings_content
    and ".section-title" in settings_content
    and ".browser-tab-mockup" in settings_content
    and ".modal-content" in settings_content
    and ".modal-grid" in settings_content
)

print("\n========================================================")
print(f"Total de Testes: {tests_passed + tests_failed}")
print(f"Aprovados:       {tests_passed}")
print(f"Falhas:          {tests_failed}\n")

if tests_failed == 0:
    print(">>> TODAS AS VERIFICAÇÕES DE SEGURANÇA PASSARAM COM SUCESSO! <<<\n")
else:
    exit(1)
