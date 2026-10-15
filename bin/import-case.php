<?php
// Usage: php bin/import-case.php cases/fall-001-hotel.json <slug> [--publish]
require __DIR__ . '/_boot.php';
use ColdCase\Game\CaseRepository;
$f = $argv[1] ?? null; $slug = $argv[2] ?? null;
if (!$f || !$slug || !is_file($f)) { fwrite(STDERR, "Usage: import-case.php <datei.json> <slug> [--publish]\n"); exit(2); }
$content = json_decode(file_get_contents($f), true);
if (!is_array($content)) { fwrite(STDERR, "Ungültiges JSON\n"); exit(2); }
$r = CaseRepository::saveVersion($slug, $content, null);
echo "Version {$r['version']} gespeichert. Validierung: " . ($r['report']['ok'] ? 'OK' : 'FEHLER') . "\n";
foreach ($r['report']['errors'] as $e) echo "  FEHLER: $e\n";
foreach ($r['report']['warnings'] as $w) echo "  Hinweis: $w\n";
if (in_array('--publish', $argv, true)) {
    CaseRepository::publish($slug, $r['version_id']);
    echo "Veröffentlicht.\n";
}
