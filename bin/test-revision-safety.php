<?php
declare(strict_types=1);

// Isolated databases only; deliberately does not load bootstrap/config.local.php.
spl_autoload_register(function (string $class): void {
    $prefix = 'LightPress\\Core\\';
    if (strpos($class, $prefix) === 0) {
        require_once dirname(__DIR__) . '/core/' . substr($class, strlen($prefix)) . '.php';
    }
});
if (!function_exists('str_contains')) {
    function str_contains($value, $needle) { return $needle === '' || strpos($value, $needle) !== false; }
}
use LightPress\Core\Schema;
use LightPress\Core\Database;
use LightPress\Core\PostService;
use LightPress\Core\CacheManager;

function checkRevisionSafety(string $label, bool $ok): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "[PASS] {$label}\n";
}
function legacyRevisionDatabase(int $foreignKeys = 1): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY); CREATE TABLE posts(id INTEGER PRIMARY KEY); INSERT INTO users VALUES(1); INSERT INTO posts VALUES(1);');
    $pdo->exec("CREATE TABLE post_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, editor_id INTEGER NOT NULL,
        base_version INTEGER NOT NULL, status TEXT CHECK(status IN ('draft','pending_review','applied')),
        payload_json TEXT NOT NULL, content_html TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO post_revisions(id,post_id,editor_id,base_version,status,payload_json,content_html) VALUES(7,1,1,1,'draft','{}','preserved')");
    $pdo->exec('PRAGMA foreign_keys = ' . $foreignKeys);
    return $pdo;
}
foreach ([0, 1] as $foreignKeys) {
    $pdo = legacyRevisionDatabase($foreignKeys);
    Schema::ensureRevisionsSchema($pdo, 'sqlite');
    $pdo->exec("UPDATE post_revisions SET status='superseded' WHERE id=7");
    checkRevisionSafety('Migration preserves rows and original foreign_keys=' . $foreignKeys,
        $pdo->query('SELECT content_html FROM post_revisions WHERE id=7')->fetchColumn() === 'preserved'
        && (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn() === $foreignKeys && !$pdo->inTransaction());

    $pdo = legacyRevisionDatabase($foreignKeys);
    $pdo->exec('CREATE TABLE post_revisions_new(id INTEGER)');
    $failed = false;
    try { Schema::ensureRevisionsSchema($pdo, 'sqlite'); } catch (Throwable $e) { $failed = true; }
    checkRevisionSafety('DDL failure propagates and cleans up with foreign_keys=' . $foreignKeys,
        $failed && !$pdo->inTransaction() && (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn() === $foreignKeys
        && $pdo->query('SELECT content_html FROM post_revisions WHERE id=7')->fetchColumn() === 'preserved');
}
$pdo = legacyRevisionDatabase();
$pdo->beginTransaction();
$failed = false;
try { Schema::ensureRevisionsSchema($pdo, 'sqlite'); } catch (RuntimeException $e) { $failed = true; }
checkRevisionSafety('Migration rejects caller transaction without committing or rolling it back',
    $failed && $pdo->inTransaction() && (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1);
$pdo->rollBack();

if (PHP_VERSION_ID < 80000) {
    echo "[SKIP] Editorial integration requires PHP 8+ (project target: 8.4).\n";
    exit(0);
}
Database::init(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
Schema::install(Database::getConnection(), 'sqlite');
Database::query("INSERT INTO users(name,username,email,password_hash,role,is_active) VALUES('Fixture','fixture','fixture@example.invalid','fixture','admin',1)");
$admin = (int)Database::lastInsertId();
$service = new PostService(new CacheManager(['cache' => ['enabled' => false], 'cloudflare' => ['enabled' => false]]));
$data = ['title' => 'Historical target', 'slug' => 'historical-target', 'content_html' => '', 'content_json' => '[]', 'published_at' => '2099-01-01 00:00:00', 'is_featured' => 0];
$initial = 0;
$id = $service->createPost($data, $admin, $initial);
$service->publishOrUpdate($id, $data, $admin, 1, $initial);
Database::query("INSERT INTO post_revisions(post_id,editor_id,base_version,status,payload_json,content_html) VALUES(?,?,2,'draft','{}','newer draft')", [$id, $admin]);
$active = (int)Database::lastInsertId();
$staleRejected = false;
try { $service->publishOrUpdate($id, $data, $admin, 2, $initial, $initial); }
catch (RuntimeException $e) { $staleRejected = $e->getCode() === 409; }
checkRevisionSafety('Restoration rejects stale concurrency token', $staleRejected);
$service->publishOrUpdate($id, $data, $admin, 2, $active, $initial);
checkRevisionSafety('Historical target restored while newer draft is superseded',
    Database::fetchValue('SELECT status FROM post_revisions WHERE id=?', [$initial]) === 'applied'
    && Database::fetchValue('SELECT status FROM post_revisions WHERE id=?', [$active]) === 'superseded'
    && (int)Database::fetchValue('SELECT version FROM posts WHERE id=?', [$id]) === 3);
