<?php
// Usage: php bin/migrate.php   – wendet migrations/*.sql der Reihe nach an (idempotent).
require __DIR__ . '/_boot.php';
use ColdCase\Core\Db;

$pdo = Db::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB');
$done = array_column(Db::all('SELECT version FROM schema_migrations'), 'version');
$files = glob(CC_ROOT . '/migrations/*.sql');
sort($files);
foreach ($files as $f) {
    $v = basename($f, '.sql');
    if (in_array($v, $done, true)) continue;
    echo "Migration $v ... ";
    $sql = file_get_contents($f);
    // einfache Statement-Trennung (Migrationen enthalten keine Prozeduren/Delimiter)
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
        if (preg_match('/^(--[^\n]*\n?)+$/', $stmt)) continue;
        $pdo->exec($stmt);
    }
    Db::exec('INSERT INTO schema_migrations (version,applied_at) VALUES (?,?)', [$v, Db::now()]);
    echo "ok\n";
}
echo "Fertig.\n";
