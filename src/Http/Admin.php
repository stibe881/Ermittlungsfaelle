<?php
declare(strict_types=1);
namespace ColdCase\Http;

use ColdCase\Core\{Db, HttpError, Request, Router, Security};
use ColdCase\Game\{CaseRepository, CaseValidator, GameEngine};

final class Admin
{
    private static function admin(Request $q): array
    {
        if (!$q->user) throw new HttpError(401, 'unauthenticated');
        if ($q->user['role'] !== 'admin') throw new HttpError(403, 'forbidden', 'Nur für Administratoren.');
        return $q->user;
    }

    public static function register(Router $r): void
    {
        $r->add('GET', '/api/admin/cases', function (Request $q) {
            self::admin($q);
            return ['cases' => Db::all("SELECT c.slug,c.case_number,c.status,c.published_version_id,(SELECT MAX(version) FROM case_versions WHERE case_id=c.id) AS latest_version,(SELECT COUNT(*) FROM game_sessions WHERE case_id=c.id) AS sessions FROM cases c ORDER BY c.case_number")];
        });
        $r->add('GET', '/api/admin/cases/{slug}', function (Request $q, array $a) {
            self::admin($q);
            $v = Db::one('SELECT cv.id,cv.version,cv.content,cv.validation_report,cv.validation_ok,cv.published_at FROM case_versions cv JOIN cases c ON c.id=cv.case_id WHERE c.slug=? ORDER BY cv.version DESC LIMIT 1', [$a['slug']]);
            if (!$v) throw new HttpError(404, 'not_found');
            return ['version_id' => (int) $v['id'], 'version' => (int) $v['version'], 'content' => json_decode($v['content'], true), 'report' => json_decode($v['validation_report'], true), 'published_at' => $v['published_at']];
        });
        $r->add('PUT', '/api/admin/cases/{slug}', function (Request $q, array $a) {
            $u = self::admin($q);
            if (!preg_match('/^[a-z0-9\-]{3,64}$/', $a['slug'])) throw new HttpError(422, 'invalid_slug');
            $content = $q->body['content'] ?? null;
            if (!is_array($content)) throw new HttpError(422, 'content_required');
            $res = CaseRepository::saveVersion($a['slug'], $content, (int) $u['id']);
            Security::audit((int) $u['id'], 'case_saved', $a['slug'] . ' v' . $res['version'], null, $q->ip);
            return $res;
        });
        $r->add('POST', '/api/admin/cases/{slug}/validate', function (Request $q) {
            self::admin($q);
            $c = $q->body['content'] ?? null;
            if (!is_array($c)) throw new HttpError(422, 'content_required');
            return CaseValidator::validate($c);
        });
        $r->add('POST', '/api/admin/cases/{slug}/publish', function (Request $q, array $a) {
            $u = self::admin($q);
            CaseRepository::publish($a['slug'], (int) ($q->body['version_id'] ?? 0));
            Security::audit((int) $u['id'], 'case_published', $a['slug'], null, $q->ip);
            return ['ok' => true];
        });
        $r->add('POST', '/api/admin/cases/{slug}/archive', function (Request $q, array $a) {
            $u = self::admin($q);
            Db::exec("UPDATE cases SET status='archived' WHERE slug=?", [$a['slug']]);
            Security::audit((int) $u['id'], 'case_archived', $a['slug'], null, $q->ip);
            return ['ok' => true];
        });
        // Testmodus: nicht gewertete Sitzung auf einer beliebigen Version (auch Entwurf)
        $r->add('POST', '/api/admin/cases/{slug}/test', function (Request $q, array $a) {
            $u = self::admin($q);
            return GameEngine::createSession((int) $u['id'], $a['slug'], 'solo', true, (int) ($q->body['version_id'] ?? 0) ?: null);
        });
        $r->add('GET', '/api/admin/stats', function (Request $q) {
            self::admin($q);
            return [
                'users' => (int) Db::val('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
                'sessions' => Db::all('SELECT status,COUNT(*) AS n FROM game_sessions GROUP BY status'),
                'solved_by_case' => Db::all("SELECT c.slug,COUNT(*) AS solved,ROUND(AVG(gs.active_seconds)) AS avg_seconds,ROUND(AVG(gs.hints_used),1) AS avg_hints FROM game_sessions gs JOIN cases c ON c.id=gs.case_id WHERE gs.status='solved' GROUP BY c.slug"),
            ];
        });
        $r->add('GET', '/api/admin/users', function (Request $q) {
            self::admin($q);
            return ['users' => Db::all('SELECT id,username,email,role,created_at,last_login_at FROM users WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 200')];
        });
        // Missbrauchsprüfung: auffällig schnelle Läufe
        $r->add('GET', '/api/admin/abuse', function (Request $q) {
            self::admin($q);
            return ['suspicious' => Db::all("SELECT lb.session_id,u.username,c.slug,lb.active_seconds,lb.hints_used FROM leaderboards lb JOIN users u ON u.id=lb.user_id JOIN cases c ON c.id=lb.case_id WHERE lb.active_seconds < 300 ORDER BY lb.active_seconds LIMIT 50")];
        });
        $r->add('DELETE', '/api/admin/leaderboard/{id}', function (Request $q, array $a) {
            $u = self::admin($q);
            Db::exec('DELETE FROM leaderboards WHERE id=?', [(int) $a['id']]);
            Security::audit((int) $u['id'], 'leaderboard_entry_removed', $a['id'], null, $q->ip);
            return ['ok' => true];
        });
    }
}
