<?php
declare(strict_types=1);
namespace ColdCase\Game;

/**
 * Datengetriebene Bedingungen. Formen:
 *   {"evidence":"id"} {"asked":"id"} {"action":"id"} {"flag":"id"} {"hotspot":"id"}
 *   {"all":[...]} {"any":[...]} {"not":{...}}
 * null / [] = immer wahr.
 */
final class Condition
{
    private const LEAVES = ['evidence', 'asked', 'action', 'flag', 'hotspot'];

    public static function test(?array $c, Progress $p): bool
    {
        if ($c === null || $c === []) return true;
        if (isset($c['all'])) { foreach ($c['all'] as $s) if (!self::test($s, $p)) return false; return true; }
        if (isset($c['any'])) { foreach ($c['any'] as $s) if (self::test($s, $p)) return true; return false; }
        if (isset($c['not'])) return !self::test($c['not'], $p);
        foreach (self::LEAVES as $k) if (isset($c[$k])) return $p->has($k, (string) $c[$k]);
        return false; // unbekannte Bedingung ⇒ nie erfüllt (Validator meldet das)
    }

    /** Alle (Art,Ref)-Verweise, für den Validator. @return array<array{0:string,1:string}> */
    public static function refs(?array $c): array
    {
        if ($c === null || $c === []) return [];
        $out = [];
        foreach (['all', 'any'] as $g) if (isset($c[$g])) foreach ($c[$g] as $s) $out = array_merge($out, self::refs($s));
        if (isset($c['not'])) $out = array_merge($out, self::refs($c['not']));
        foreach (self::LEAVES as $k) if (isset($c[$k])) $out[] = [$k, (string) $c[$k]];
        return $out;
    }

    public static function isWellFormed(mixed $c): bool
    {
        if ($c === null || $c === []) return true;
        if (!is_array($c)) return false;
        if (isset($c['all']) || isset($c['any'])) {
            $list = $c['all'] ?? $c['any'];
            if (!is_array($list)) return false;
            foreach ($list as $s) if (!self::isWellFormed($s)) return false;
            return true;
        }
        if (isset($c['not'])) return self::isWellFormed($c['not']);
        foreach (self::LEAVES as $k) if (isset($c[$k])) return is_string($c[$k]);
        return false;
    }
}
