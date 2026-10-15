<?php
// Usage: php bin/validate-case.php cases/fall-001-hotel.json
require __DIR__ . '/../src/bootstrap.php';
use ColdCase\Game\CaseValidator;
$f = $argv[1] ?? null;
if (!$f || !is_file($f)) { fwrite(STDERR, "Datei fehlt\n"); exit(2); }
$c = json_decode(file_get_contents($f), true);
if (!is_array($c)) { fwrite(STDERR, "Ungültiges JSON: " . json_last_error_msg() . "\n"); exit(2); }
$r = CaseValidator::validate($c);
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
exit($r['ok'] ? 0 : 1);
