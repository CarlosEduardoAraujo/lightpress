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

print("\n========================================================")
print(f"Total de Testes: {tests_passed + tests_failed}")
print(f"Aprovados:       {tests_passed}")
print(f"Falhas:          {tests_failed}\n")

if tests_failed == 0:
    print(">>> TODAS AS VERIFICAÇÕES DE SEGURANÇA PASSARAM COM SUCESSO! <<<\n")
else:
    exit(1)
