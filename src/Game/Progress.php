<?php
declare(strict_types=1);
namespace ColdCase\Game;

/** Unveränderlicher-Stil-Wertecontainer des Ermittlungsstands (Mengen je Art). */
final class Progress
{
    /** @var array<string,array<string,true>> */
    public array $sets = ['evidence' => [], 'asked' => [], 'action' => [], 'flag' => [], 'hotspot' => []];

    public function has(string $kind, string $ref): bool { return isset($this->sets[$kind][$ref]); }
    public function add(string $kind, string $ref): bool
    {
        if (isset($this->sets[$kind][$ref])) return false;
        $this->sets[$kind][$ref] = true;
        return true;
    }
    public function list(string $kind): array { return array_keys($this->sets[$kind]); }
}
