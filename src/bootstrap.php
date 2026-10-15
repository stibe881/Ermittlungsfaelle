<?php
declare(strict_types=1);

define('CC_ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

spl_autoload_register(function (string $class): void {
    $prefix = 'ColdCase\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
