<?php
// Nur für den lokalen Entwicklungsserver: php -S localhost:8080 -t public public/router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) { require __DIR__ . '/index.php'; return true; }
if ($path !== '/' && is_file(__DIR__ . $path)) return false;
readfile(__DIR__ . '/app.html');
return true;
