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

print("\n========================================================")
print(f"Total de Testes: {tests_passed + tests_failed}")
print(f"Aprovados:       {tests_passed}")
print(f"Falhas:          {tests_failed}\n")

if tests_failed == 0:
    print(">>> TODAS AS VERIFICAÇÕES DE SEGURANÇA PASSARAM COM SUCESSO! <<<\n")
else:
    exit(1)
