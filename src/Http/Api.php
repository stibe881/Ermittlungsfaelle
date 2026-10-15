<?php
declare(strict_types=1);
namespace ColdCase\Http;

use ColdCase\Auth\AuthService;
use ColdCase\Core\{Config, Db, HttpError, RateLimiter, Request, Router, Security};
use ColdCase\Game\{CaseRepository, GameEngine};

final class Api
{
    public static function router(): Router
    {
        $r = new Router();
        $auth = function (Request $q): array {
            if (!$q->user) throw new HttpError(401, 'unauthenticated', 'Bitte anmelden.');
            return $q->user;
        };

        // ---------- Sitzung / Konto
        $r->add('GET', '/api/me', fn(Request $q) => ['csrf' => Security::csrfToken(), 'user' => $q->user ? AuthService::profile($q->user) : null]);
        $r->add('POST', '/api/auth/register', function (Request $q) {
            AuthService::register($q->str('email', 190), $q->str('username', 32), (string) ($q->body['password'] ?? ''), !empty($q->body['consent']), $q->ip);
            return ['ok' => true];
        });
        $r->add('POST', '/api/auth/login', function (Request $q) {
            $u = AuthService::login($q->str('login', 190), (string) ($q->body['password'] ?? ''), $q->ip);
            return ['csrf' => Security::csrfToken(), 'user' => AuthService::profile($u)];
        });
        $r->add('POST', '/api/auth/logout', function () { Security::logout(); return ['csrf' => Security::csrfToken()]; });
        $r->add('POST', '/api/auth/verify', function (Request $q) { AuthService::verifyEmail($q->str('token', 100)); return ['ok' => true]; });
        $r->add('POST', '/api/auth/forgot', function (Request $q) { AuthService::forgot($q->str('email', 190), $q->ip); return ['ok' => true]; });
        $r->add('POST', '/api/auth/reset', function (Request $q) { AuthService::reset($q->str('token', 100), (string) ($q->body['password'] ?? '')); return ['ok' => true]; });
        $r->add('POST', '/api/me/avatar', function (Request $q) use ($auth) { AuthService::setAvatar((int) $auth($q)['id'], $q->str('avatar', 8)); return ['ok' => true]; });
        $r->add('GET', '/api/me/export', fn(Request $q) => AuthService::exportData((int) $auth($q)['id']));
        $r->add('POST', '/api/me/delete', function (Request $q) use ($auth) {
            AuthService::deleteAccount((int) $auth($q)['id'], (string) ($q->body['password'] ?? ''), $q->ip);
            Security::logout();
            return ['ok' => true];
        });

        // ---------- Fälle
        $r->add('GET', '/api/cases', function (Request $q) {
            $cards = CaseRepository::listPublished();
            if ($q->user) {
                $rows = Db::all("SELECT c.slug,gs.id,gs.status,gs.mode,gs.ranked,gs.active_seconds,gs.score_points FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id JOIN cases c ON c.id=gs.case_id WHERE m.user_id=? ORDER BY gs.id", [$q->user['id']]);
                $bySlug = [];
                foreach ($rows as $row) $bySlug[$row['slug']][] = $row;
                foreach ($cards as &$c) {
                    $mine = $bySlug[$c['slug']] ?? [];
                    $c['my_sessions'] = array_map(fn($x) => ['id' => (int) $x['id'], 'status' => $x['status'], 'mode' => $x['mode'], 'ranked' => (bool) $x['ranked'], 'active_seconds' => (int) $x['active_seconds']], $mine);
                    $best = Db::val('SELECT MIN(score_seconds) FROM leaderboards lb JOIN cases c ON c.id=lb.case_id WHERE lb.user_id=? AND c.slug=?', [$q->user['id'], $c['slug']]);
                    $c['best_seconds'] = $best !== null ? (int) $best : null;
                }
            }
            return ['cases' => $cards];
        });
        $r->add('GET', '/api/cases/{slug}/leaderboard', function (Request $q, array $a) {
            $cv = CaseRepository::publishedBySlug($a['slug']);
            $mode = ($q->query['mode'] ?? 'solo') === 'team' ? 'team' : 'solo';
            $where = 'lb.case_id=? AND lb.mode=?'; $params = [$cv['case']['id'], $mode];
            if (($q->query['hints'] ?? '') === '0') $where .= ' AND lb.hints_used=0';
            $rows = Db::all("SELECT u.username,u.avatar,lb.players,lb.hints_used,lb.false_accusations,lb.active_seconds,lb.score_seconds,lb.score_points,lb.completed_at,lb.user_id FROM leaderboards lb JOIN users u ON u.id=lb.user_id WHERE $where ORDER BY lb.score_seconds ASC, lb.completed_at ASC LIMIT 50", $params);
            $out = [];
            foreach ($rows as $i => $row) {
                $solved = (int) Db::val("SELECT COUNT(DISTINCT case_id) FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id WHERE m.user_id=? AND gs.status='solved'", [$row['user_id']]);
                $out[] = ['place' => $i + 1, 'username' => $row['username'], 'avatar' => $row['avatar'], 'rank' => AuthService::rankFor($solved), 'players' => (int) $row['players'], 'hints_used' => (int) $row['hints_used'], 'false_accusations' => (int) $row['false_accusations'], 'active_seconds' => (int) $row['active_seconds'], 'score_seconds' => (int) $row['score_seconds'], 'points' => (int) $row['score_points'], 'date' => substr($row['completed_at'], 0, 10), 'mode' => $mode];
            }
            return ['mode' => $mode, 'entries' => $out, 'rules' => 'Rangliste = aktive Ermittlungszeit + 10 Min. je Fehlanklage + 5 Min. je Hinweis. Nur der erste Versuch je Fall zählt.'];
        });

        // ---------- Sitzungen
        $r->add('GET', '/api/sessions', fn(Request $q) => ['sessions' => GameEngine::mySessions((int) $auth($q)['id'])]);
        $r->add('POST', '/api/sessions', function (Request $q) use ($auth) {
            $u = $auth($q);
            RateLimiter::hit('sess:' . $u['id'], 30, 3600);
            return GameEngine::createSession((int) $u['id'], $q->str('case', 64), $q->str('mode', 8) ?: 'solo');
        });
        $r->add('POST', '/api/sessions/join', function (Request $q) use ($auth) {
            $u = $auth($q);
            RateLimiter::hit('join:' . $u['id'], 20, 900);
            return GameEngine::join((int) $u['id'], $q->str('code', 12));
        });
        $r->add('GET', '/api/sessions/{id}', function (Request $q, array $a) use ($auth) {
            $u = $auth($q);
            RateLimiter::hit('poll:' . $u['id'], 90, 60);
            $s = GameEngine::sessionFor((int) $a['id'], (int) $u['id']);
            if (isset($q->query['since']) && (int) $q->query['since'] === (int) $s['version'] && $s['status'] === ($q->query['status'] ?? $s['status']))
                return ['changed' => false, 'version' => (int) $s['version'], 'active_seconds' => (int) $s['active_seconds']];
            return ['changed' => true, 'state' => GameEngine::state($s, (int) $u['id'])];
        });
        $r->add('POST', '/api/sessions/{id}/act', function (Request $q, array $a) use ($auth) {
            $u = $auth($q);
            RateLimiter::hit('act:' . $u['id'], 240, 60);
            return GameEngine::act((int) $a['id'], (int) $u['id'], $q->body);
        });

        Tv::register($r, $auth);
        Admin::register($r);
        return $r;
    }

    public static function handle(Request $q): array
    {
        Security::startSession();
        $q->user = AuthService::currentUser();
        $isPublicTv = in_array($q->path, ['/api/tv/pair', '/api/tv/poll'], true);
        if (!$isPublicTv) Security::checkCsrf($q);
        return self::router()->dispatch($q);
    }
}
