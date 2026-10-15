<?php
declare(strict_types=1);
namespace ColdCase\Core;

final class Config
{
    private static array $data = [];

    public static function load(?string $file = null): void
    {
        $file ??= getenv('CC_CONFIG') ?: CC_ROOT . '/config/config.php';
        if (!is_file($file)) throw new \RuntimeException('config/config.php fehlt (siehe config.example.php)');
        self::$data = require $file;
    }

    public static function set(array $data): void { self::$data = $data; }

    public static function get(string $path, mixed $default = null): mixed
    {
        $v = self::$data;
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) return $default;
            $v = $v[$k];
        }
        return $v;
    }

    public static function isDev(): bool { return self::get('env') === 'development'; }
}
