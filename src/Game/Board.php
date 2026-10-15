<?php
declare(strict_types=1);
namespace ColdCase\Game;

use ColdCase\Core\{Db, HttpError};

/** Gemeinsame Pinnwand mit optimistischer Nebenläufigkeit (Versionen je Element). */
final class Board
{
    private const COLORS = ['neutral', 'red', 'blue', 'green', 'yellow', 'purple'];

    public static function snapshot(int $sid): array
    {
        $items = Db::all('SELECT id,kind,ref,label,x,y,color,version FROM session_board_items WHERE session_id=? AND deleted=0 ORDER BY id', [$sid]);
        $links = Db::all('SELECT id,from_item,to_item,label,color,version FROM session_board_connections WHERE session_id=? AND deleted=0 ORDER BY id', [$sid]);
        return ['items' => array_map(function ($r) { foreach (['id', 'x', 'y', 'version'] as $k) $r[$k] = (int) $r[$k]; return $r; }, $items),
                'links' => array_map(function ($r) { foreach (['id', 'from_item', 'to_item', 'version'] as $k) $r[$k] = (int) $r[$k]; return $r; }, $links)];
    }

    private static function color(mixed $c, string $default): string { return in_array($c, self::COLORS, true) ? $c : $default; }
    private static function coord(mixed $v): int { return max(0, min(4000, (int) $v)); }

    private static function item(int $sid, int $id): array
    {
        $r = Db::one('SELECT * FROM session_board_items WHERE id=? AND session_id=? AND deleted=0', [$id, $sid]);
        if (!$r) throw new HttpError(404, 'item_not_found');
        return $r;
    }

    private static function conflict(string $table, array $row): never
    {
        throw new HttpError(409, 'version_conflict', 'Das Element wurde inzwischen von jemand anderem geändert.', ['current' => ['id' => (int) $row['id'], 'version' => (int) $row['version']]]);
    }

    public static function handle(array $s, int $uid, array $c, Progress $p, string $type, array $req): array
    {
        $sid = (int) $s['id'];
        switch ($type) {
            case 'board_add': {
                $kind = (string) ($req['kind'] ?? '');
                $ref = null; $label = '';
                if ($kind === 'evidence') {
                    $ref = (string) ($req['ref'] ?? '');
                    if (!$p->has('evidence', $ref)) throw new HttpError(422, 'unknown_evidence');
                    foreach ($c['evidence'] as $e) if ($e['id'] === $ref) $label = $e['title'];
                } elseif ($kind === 'suspect') {
                    $ref = (string) ($req['ref'] ?? '');
                    $found = false; foreach ($c['suspects'] as $x) if ($x['id'] === $ref) { $label = $x['name']; $found = true; }
                    if (!$found) throw new HttpError(422, 'unknown_suspect');
                } elseif ($kind === 'note') {
                    $label = mb_substr(trim((string) ($req['label'] ?? '')), 0, 240);
                    if ($label === '') throw new HttpError(422, 'empty_label');
                } else throw new HttpError(422, 'invalid_kind');
                if ($ref !== null && Db::val('SELECT 1 FROM session_board_items WHERE session_id=? AND kind=? AND ref=? AND deleted=0', [$sid, $kind, $ref])) throw new HttpError(409, 'already_on_board', 'Liegt bereits an der Pinnwand.');
                $id = Db::insert('INSERT INTO session_board_items (session_id,kind,ref,label,x,y,color,created_by) VALUES (?,?,?,?,?,?,?,?)',
                    [$sid, $kind, $ref, $label, self::coord($req['x'] ?? 40), self::coord($req['y'] ?? 40), self::color($req['color'] ?? null, $kind === 'suspect' ? 'blue' : ($kind === 'note' ? 'yellow' : 'neutral')), $uid]);
                return ['id' => $id];
            }
            case 'board_move': case 'board_update': {
                $it = self::item($sid, (int) ($req['id'] ?? 0));
                if ((int) ($req['base_version'] ?? -1) !== (int) $it['version']) self::conflict('items', $it);
                $x = array_key_exists('x', $req) ? self::coord($req['x']) : (int) $it['x'];
                $y = array_key_exists('y', $req) ? self::coord($req['y']) : (int) $it['y'];
                $label = $it['label'];
                if ($it['kind'] === 'note' && isset($req['label'])) $label = mb_substr(trim((string) $req['label']), 0, 240);
                $color = isset($req['color']) ? self::color($req['color'], $it['color']) : $it['color'];
                Db::exec('UPDATE session_board_items SET x=?,y=?,label=?,color=?,version=version+1 WHERE id=?', [$x, $y, $label, $color, $it['id']]);
                return ['id' => (int) $it['id']];
            }
            case 'board_delete': {
                $it = self::item($sid, (int) ($req['id'] ?? 0));
                Db::exec('UPDATE session_board_items SET deleted=1, version=version+1 WHERE id=?', [$it['id']]);
                Db::exec('UPDATE session_board_connections SET deleted=1 WHERE session_id=? AND (from_item=? OR to_item=?)', [$sid, $it['id'], $it['id']]);
                return [];
            }
            case 'link_add': {
                $a = self::item($sid, (int) ($req['from'] ?? 0)); $b = self::item($sid, (int) ($req['to'] ?? 0));
                if ($a['id'] === $b['id']) throw new HttpError(422, 'self_link');
                $dup = Db::val('SELECT 1 FROM session_board_connections WHERE session_id=? AND deleted=0 AND ((from_item=? AND to_item=?) OR (from_item=? AND to_item=?))', [$sid, $a['id'], $b['id'], $b['id'], $a['id']]);
                if ($dup) throw new HttpError(409, 'link_exists', 'Verbindung existiert bereits.');
                $id = Db::insert('INSERT INTO session_board_connections (session_id,from_item,to_item,label,color,created_by) VALUES (?,?,?,?,?,?)',
                    [$sid, $a['id'], $b['id'], mb_substr(trim((string) ($req['label'] ?? '')), 0, 120), self::color($req['color'] ?? null, 'red'), $uid]);
                return ['id' => $id];
            }
            case 'link_update': case 'link_delete': {
                $l = Db::one('SELECT * FROM session_board_connections WHERE id=? AND session_id=? AND deleted=0', [(int) ($req['id'] ?? 0), $sid]);
                if (!$l) throw new HttpError(404, 'link_not_found');
                if ($type === 'link_delete') { Db::exec('UPDATE session_board_connections SET deleted=1, version=version+1 WHERE id=?', [$l['id']]); return []; }
                if ((int) ($req['base_version'] ?? -1) !== (int) $l['version']) self::conflict('links', $l);
                Db::exec('UPDATE session_board_connections SET label=?, color=?, version=version+1 WHERE id=?',
                    [isset($req['label']) ? mb_substr(trim((string) $req['label']), 0, 120) : $l['label'], self::color($req['color'] ?? null, $l['color']), $l['id']]);
                return ['id' => (int) $l['id']];
            }
        }
        throw new HttpError(422, 'unknown_board_action');
    }
}
