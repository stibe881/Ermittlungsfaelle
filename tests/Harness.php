<?php
declare(strict_types=1);

final class T
{
    public static int $pass = 0, $fail = 0;
    public static array $failures = [];
    private static string $current = '';

    public static function test(string $name, callable $fn): void
    {
        self::$current = $name;
        try { $fn(); echo "  ✓ $name\n"; }
        catch (\Throwable $e) { self::$fail++; self::$failures[] = "$name: " . $e->getMessage(); echo "  ✗ $name\n      " . $e->getMessage() . "\n"; }
    }
    public static function ok(bool $c, string $msg = 'Bedingung nicht erfüllt'): void { if (!$c) throw new \RuntimeException($msg); self::$pass++; }
    public static function eq(mixed $a, mixed $b, string $msg = ''): void { if ($a !== $b) throw new \RuntimeException(($msg ? "$msg: " : '') . 'erwartet ' . json_encode($b, JSON_UNESCAPED_UNICODE) . ', erhalten ' . json_encode($a, JSON_UNESCAPED_UNICODE)); self::$pass++; }
    public static function throws(callable $fn, ?string $code = null, string $msg = ''): void
    {
        try { $fn(); } catch (\ColdCase\Core\HttpError $e) { if ($code !== null && $e->errorCode !== $code) throw new \RuntimeException("$msg: Fehlercode {$e->errorCode} statt $code"); self::$pass++; return; }
        throw new \RuntimeException(($msg ?: 'Exception') . ' erwartet, aber keine geworfen');
    }
}
