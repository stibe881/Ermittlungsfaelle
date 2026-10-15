<?php
declare(strict_types=1);
namespace ColdCase\Core;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $c = Config::get('db');
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['name'], $c['charset'] ?? 'utf8mb4');
        self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec("SET time_zone = '+00:00'");
        return self::$pdo;
    }

    public static function reset(): void { self::$pdo = null; }

    public static function all(string $sql, array $p = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $p = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $r = $st->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function exec(string $sql, array $p = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $p = []): int
    {
        self::exec($sql, $p);
        return (int) self::pdo()->lastInsertId();
    }

    /** Führt $fn in einer Transaktion aus (geschachtelte Aufrufe nutzen die äussere). */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) return $fn();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
}
