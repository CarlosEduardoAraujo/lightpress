<?php
declare(strict_types=1);

/** Diagnóstico padrão sem gravação. Reparação aditiva somente com --apply. */
use LightPress\Core\Database;
require __DIR__ . '/../core/Database.php';
$config = require __DIR__ . '/../config.php';
Database::init($config['database']);
$apply = in_array('--apply', $argv, true);
$owners = [];
foreach (Database::fetchAll('SELECT id, variants_manifest FROM media') as $media) {
    $manifest = json_decode((string)$media['variants_manifest'], true);
    if (!is_array($manifest)) { continue; }
    array_walk_recursive($manifest, static function ($value) use (&$owners, $media): void {
        if (is_string($value) && str_contains($value, 'uploads/')) {
            $path = ltrim((string)(parse_url($value, PHP_URL_PATH) ?? $value), '/');
            $owners[$path][$media['id']] = true;
        }
    });
}
$existing = array_column(Database::fetchAll('SELECT url_path, media_id FROM media_variants'), 'media_id', 'url_path');
$report = ['mode' => $apply ? 'apply' : 'read-only', 'missing' => [], 'conflicts' => [], 'featured_ids_missing' => []];
foreach ($owners as $path => $ids) {
    $ids = array_keys($ids);
    if (count($ids) !== 1 || (isset($existing[$path]) && $existing[$path] !== $ids[0])) {
        $report['conflicts'][] = ['path' => $path, 'owners' => $ids, 'existing' => $existing[$path] ?? null];
        continue;
    }
    if (!isset($existing[$path])) {
        $report['missing'][] = ['path' => $path, 'media_id' => $ids[0]];
    }
}
foreach (Database::fetchAll("SELECT id, featured_image FROM posts WHERE (featured_media_id IS NULL OR featured_media_id = '') AND featured_image IS NOT NULL") as $post) {
    $path = ltrim((string)(parse_url($post['featured_image'], PHP_URL_PATH) ?? $post['featured_image']), '/');
    $ids = array_keys($owners[$path] ?? []);
    if (count($ids) === 1 && (!isset($existing[$path]) || $existing[$path] === $ids[0])) {
        $report['featured_ids_missing'][] = ['post_id' => $post['id'], 'media_id' => $ids[0]];
    }
}
if ($apply) {
    Database::transaction(static function () use ($report): void {
        $insert = Database::getDriver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        foreach ($report['missing'] as $row) {
            Database::query($insert . ' INTO media_variants (media_id, url_path) VALUES (?, ?)', [$row['media_id'], $row['path']]);
        }
        foreach ($report['featured_ids_missing'] as $row) {
            Database::query("UPDATE posts SET featured_media_id = ? WHERE id = ? AND (featured_media_id IS NULL OR featured_media_id = '')", [$row['media_id'], $row['post_id']]);
        }
    });
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
