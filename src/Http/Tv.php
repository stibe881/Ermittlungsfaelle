<?php
declare(strict_types=1);
namespace ColdCase\Http;

use ColdCase\Core\{Db, HttpError, RateLimiter, Request, Router};
use ColdCase\Game\GameEngine;

/** TV-Modus: TV fragt per Polling (Token), Smartphone koppelt per Code und steuert die Ansicht. */
final class Tv
{
    private const VIEWS = ['idle', 'scene', 'evidence', 'suspect', 'board', 'timeline', 'resolution'];

    public static function register(Router $r, callable $auth): void
    {
        $r->add('POST', '/api/tv/pair', function (Request $q) {
            RateLimiter::hit("tvpair:{$q->ip}", 20, 3600);
            $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            do { $code = ''; for ($i = 0; $i < 6; $i++) $code .= $alpha[random_int(0, 31)]; }
            while (Db::val('SELECT 1 FROM tv_pairings WHERE code=? AND expires_at>?', [$code, Db::now()]));
            $token = bin2hex(random_bytes(24));
            Db::exec('INSERT INTO tv_pairings (code,token_hash,created_at,expires_at) VALUES (?,?,?,?)', [$code, hash('sha256', $token), Db::now(), gmdate('Y-m-d H:i:s', time() + 6 * 3600)]);
            return ['code' => $code, 'token' => $token];
        });

        $r->add('GET', '/api/tv/poll', function (Request $q) {
            $tok = (string) ($q->query['token'] ?? '');
            RateLimiter::hit('tvpoll:' . substr(hash('sha256', $tok), 0, 16), 60, 60);
            $p = Db::one('SELECT * FROM tv_pairings WHERE token_hash=? AND expires_at>?', [hash('sha256', $tok), Db::now()]);
            if (!$p) throw new HttpError(404, 'pairing_expired', 'Kopplung abgelaufen.');
            if (!$p['session_id']) return ['linked' => false, 'code' => $p['code']];
            $s = Db::one('SELECT * FROM game_sessions WHERE id=?', [$p['session_id']]);
            $ver = $p['version'] . '.' . $s['version'] . '.' . $s['status'];
            if (($q->query['v'] ?? '') === $ver) return ['linked' => true, 'changed' => false, 'v' => $ver];
            return ['linked' => true, 'changed' => true, 'v' => $ver, 'screen' => self::screen($s, (int) $p['user_id'], json_decode($p['view_json'] ?? 'null', true) ?: ['type' => 'idle'])];
        });

        $r->add('POST', '/api/tv/link', function (Request $q) use ($auth) {
            $u = $auth($q);
            RateLimiter::hit('tvlink:' . $u['id'], 15, 900);
            $s = GameEngine::sessionFor((int) ($q->body['session_id'] ?? 0), (int) $u['id']);
            $n = Db::exec('UPDATE tv_pairings SET session_id=?, user_id=?, view_json=?, version=version+1 WHERE code=? AND session_id IS NULL AND expires_at>?',
                [$s['id'], $u['id'], json_encode(['type' => 'idle']), strtoupper($q->str('code', 6)), Db::now()]);
            if (!$n) throw new HttpError(404, 'code_invalid', 'Code ungültig oder bereits verbunden.');
            return ['ok' => true];
        });

        $r->add('POST', '/api/sessions/{id}/tv', function (Request $q, array $a) use ($auth) {
            $u = $auth($q);
            $s = GameEngine::sessionFor((int) $a['id'], (int) $u['id']);
            $view = $q->body['view'] ?? [];
            $type = (string) ($view['type'] ?? 'idle');
            if (!in_array($type, self::VIEWS, true)) throw new HttpError(422, 'invalid_view');
            $v = ['type' => $type, 'id' => isset($view['id']) ? substr((string) $view['id'], 0, 64) : null];
            Db::exec('UPDATE tv_pairings SET view_json=?, version=version+1 WHERE session_id=?', [json_encode($v), $s['id']]);
            return ['ok' => true];
        });
    }

    /** Projektion für den Fernseher: nur Freigeschaltetes, niemals Notizen oder Lösung vor Abschluss. */
    private static function screen(array $s, int $userId, array $view): array
    {
        $st = GameEngine::state($s, $userId);
        $base = ['title' => $st['meta']['title'], 'case_number' => $st['meta']['case_number'], 'members' => array_map(fn($m) => $m['username'], $st['members']), 'status' => $st['status']];
        $find = fn(array $list, ?string $id) => array_values(array_filter($list, fn($x) => $x['id'] === $id))[0] ?? null;
        switch ($view['type']) {
            case 'scene': if ($x = $find($st['scenes'], $view['id'])) return $base + ['view' => 'scene', 'scene' => $x]; break;
            case 'evidence': if ($x = $find($st['evidence'], $view['id'])) return $base + ['view' => 'evidence', 'evidence' => $x]; break;
            case 'suspect': if ($x = $find($st['suspects'], $view['id'])) { unset($x['options']); return $base + ['view' => 'suspect', 'suspect' => $x]; } break;
            case 'board': return $base + ['view' => 'board', 'board' => $st['board'], 'evidence' => $st['evidence'], 'suspects' => array_map(fn($x) => ['id' => $x['id'], 'name' => $x['name']], $st['suspects'])];
            case 'timeline': return $base + ['view' => 'timeline', 'timeline' => $st['timeline']];
            case 'resolution': if (isset($st['resolution'])) return $base + ['view' => 'resolution', 'resolution' => $st['resolution']]; break;
        }
        return $base + ['view' => 'idle', 'evidence_count' => count($st['evidence'])];
    }
}
