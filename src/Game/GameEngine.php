<?php
declare(strict_types=1);
namespace ColdCase\Game;

use ColdCase\Core\{Config, Db, HttpError};

/**
 * Autoritative Spiellogik. Der Client sendet nur Absichten (Hotspot antippen, Frage stellen, ...);
 * Ergebnisse, Freischaltungen, Zeit und Wertung entstehen ausschliesslich hier.
 */
final class GameEngine
{
    public const ROLES = ['einsatzleiter', 'forensiker', 'verhoerspezialist', 'analyst', 'spurensicherer', 'ermittler'];
    /** Überschreibbar für Tests (Unix-Zeit). */
    public static ?int $clock = null;

    public static function now(): int { return self::$clock ?? time(); }
    private static function ts(int $t): string { return gmdate('Y-m-d H:i:s', $t); }

    // ------------------------------------------------------------------ Sessions
    public static function createSession(int $userId, string $slug, string $mode, bool $isAdminTest = false, ?int $versionId = null): array
    {
        if (!in_array($mode, ['solo', 'team'], true)) throw new HttpError(422, 'invalid_mode');
        $cv = $versionId ? CaseRepository::versionBySlug($slug, $versionId) : CaseRepository::publishedBySlug($slug);
        $caseId = $cv['case']['id'];
        // Laufende Sitzung zum selben Fall fortsetzen statt Duplikate erzeugen
        $existing = Db::one("SELECT gs.id FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id WHERE m.user_id=? AND gs.case_id=? AND gs.status IN ('active','paused') ORDER BY gs.id DESC LIMIT 1", [$userId, $caseId]);
        if ($existing && !$isAdminTest) return ['id' => (int) $existing['id'], 'resumed' => true];
        // Ranglistenfähig nur der allererste Versuch dieses Benutzers an diesem Fall (kein Reset für Bestzeit)
        $everPlayed = (bool) Db::val('SELECT 1 FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id WHERE m.user_id=? AND gs.case_id=? LIMIT 1', [$userId, $caseId]);
        $ranked = !$everPlayed && !$isAdminTest;
        $now = self::now();
        $id = Db::tx(function () use ($userId, $caseId, $cv, $mode, $ranked, $now) {
            $code = self::inviteCode();
            $sid = Db::insert('INSERT INTO game_sessions (case_id,case_version_id,owner_id,mode,ranked,invite_code,started_at,last_activity_at) VALUES (?,?,?,?,?,?,?,?)',
                [$caseId, $cv['version']['id'], $userId, $mode, $ranked ? 1 : 0, $code, self::ts($now), self::ts($now)]);
            Db::exec('INSERT INTO session_members (session_id,user_id,role,joined_at) VALUES (?,?,?,?)', [$sid, $userId, 'einsatzleiter', self::ts($now)]);
            $p = new Progress();
            foreach ($cv['content']['start']['evidence'] ?? [] as $e) self::persist($sid, $userId, $p, 'evidence', $e);
            foreach ($cv['content']['start']['flags'] ?? [] as $e) self::persist($sid, $userId, $p, 'flag', $e);
            return $sid;
        });
        return ['id' => $id, 'resumed' => false, 'ranked' => $ranked];
    }

    private static function inviteCode(): string
    {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $c = '';
            for ($i = 0; $i < 6; $i++) $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        } while (Db::val('SELECT 1 FROM game_sessions WHERE invite_code=?', [$c]));
        return $c;
    }

    public static function join(int $userId, string $code): array
    {
        $s = Db::one('SELECT * FROM game_sessions WHERE invite_code=?', [strtoupper(trim($code))]);
        if (!$s || $s['mode'] !== 'team' || !in_array($s['status'], ['active', 'paused'], true)) throw new HttpError(404, 'invite_invalid', 'Einladungscode ungültig.');
        return Db::tx(function () use ($s, $userId) {
            Db::val('SELECT id FROM game_sessions WHERE id=? FOR UPDATE', [$s['id']]);
            if (Db::val('SELECT 1 FROM session_members WHERE session_id=? AND user_id=?', [$s['id'], $userId])) return ['id' => (int) $s['id']];
            $n = (int) Db::val('SELECT COUNT(*) FROM session_members WHERE session_id=?', [$s['id']]);
            if ($n >= 6) throw new HttpError(409, 'session_full', 'Die Gruppe ist voll (max. 6).');
            Db::exec('INSERT INTO session_members (session_id,user_id,role,joined_at) VALUES (?,?,?,?)', [$s['id'], $userId, 'ermittler', Db::now()]);
            Db::exec('UPDATE game_sessions SET version=version+1 WHERE id=?', [$s['id']]);
            return ['id' => (int) $s['id']];
        });
    }

    /** IDOR-Schutz: nur Mitglieder erhalten die Sitzung. */
    public static function sessionFor(int $sessionId, int $userId, bool $lock = false): array
    {
        $s = Db::one('SELECT gs.* FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id WHERE gs.id=? AND m.user_id=?' . ($lock ? ' FOR UPDATE' : ''), [$sessionId, $userId]);
        if (!$s) throw new HttpError(404, 'session_not_found', 'Sitzung nicht gefunden.');
        return $s;
    }

    public static function loadProgress(int $sid): Progress
    {
        $p = new Progress();
        foreach (Db::all('SELECT kind,ref FROM session_progress WHERE session_id=?', [$sid]) as $r) $p->add($r['kind'], $r['ref']);
        return $p;
    }

    private static function persist(int $sid, int $uid, Progress $p, string $kind, string $ref): bool
    {
        if (!$p->add($kind, $ref)) return false;
        Db::exec('INSERT IGNORE INTO session_progress (session_id,kind,ref,user_id,created_at) VALUES (?,?,?,?,?)', [$sid, $kind, $ref, $uid, self::ts(self::now())]);
        return true;
    }

    private static function applyGrants(int $sid, int $uid, Progress $p, ?array $g): array
    {
        $new = [];
        foreach ($g['evidence'] ?? [] as $e) if (self::persist($sid, $uid, $p, 'evidence', $e)) $new[] = $e;
        foreach ($g['flags'] ?? [] as $f) self::persist($sid, $uid, $p, 'flag', $f);
        return $new;
    }

    // ------------------------------------------------------------------ Zeit
    /** Aktive Zeit: nur bei Status 'active', pro Signal max. idle_cap Sekunden. Eine Uhr pro Sitzung (nicht pro Gerät/Spieler). */
    private static function touch(array &$s): void
    {
        $now = self::now();
        if ($s['status'] === 'active') {
            $delta = max(0, $now - strtotime($s['last_activity_at'] . ' UTC'));
            $s['active_seconds'] = (int) $s['active_seconds'] + min($delta, (int) Config::get('game.idle_cap_seconds', 90));
        }
        $s['last_activity_at'] = self::ts($now);
        Db::exec('UPDATE game_sessions SET active_seconds=?, last_activity_at=? WHERE id=?', [$s['active_seconds'], $s['last_activity_at'], $s['id']]);
    }

    // ------------------------------------------------------------------ Aktionen
    /**
     * Verarbeitet eine Spieleraktion transaktional, idempotent (client_action_id) und mit Versionsprüfung.
     * @return array{state:array,result:array,duplicate:bool}
     */
    public static function act(int $sessionId, int $userId, array $req): array
    {
        $type = (string) ($req['type'] ?? '');
        $cid = (string) ($req['client_action_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $cid)) throw new HttpError(422, 'client_action_id_required');

        return Db::tx(function () use ($sessionId, $userId, $req, $type, $cid) {
            $s = self::sessionFor($sessionId, $userId, true);
            $dup = Db::val('SELECT 1 FROM session_events WHERE session_id=? AND client_action_id=?', [$sessionId, $cid]);
            if ($dup) return ['state' => self::state($s, $userId), 'result' => [], 'duplicate' => true];

            if (in_array($s['status'], ['solved', 'failed', 'abandoned'], true) && !in_array($type, ['heartbeat', 'note_add', 'note_update', 'note_delete'], true))
                throw new HttpError(409, 'session_closed', 'Der Fall ist abgeschlossen.');

            $content = CaseRepository::contentForVersion((int) $s['case_version_id']);
            $p = self::loadProgress($sessionId);

            if ($type === 'resume') {
                if ($s['status'] === 'paused') {
                    Db::exec('UPDATE session_pauses SET resumed_at=? WHERE session_id=? AND resumed_at IS NULL', [self::ts(self::now()), $sessionId]);
                    $s['status'] = 'active'; $s['last_activity_at'] = self::ts(self::now());
                    Db::exec("UPDATE game_sessions SET status='active', last_activity_at=? WHERE id=?", [$s['last_activity_at'], $sessionId]);
                    self::bump($sessionId);
                }
                self::logEvent($sessionId, $userId, $cid, $type, null);
                return ['state' => self::state(self::reload($sessionId), $userId), 'result' => [], 'duplicate' => false];
            }
            if ($s['status'] === 'paused') throw new HttpError(409, 'session_paused', 'Die Ermittlung ist pausiert.');

            self::touch($s);
            $result = [];
            $mutating = true;
            switch ($type) {
                case 'heartbeat': $mutating = false; break;
                case 'pause':
                    Db::exec("UPDATE game_sessions SET status='paused' WHERE id=?", [$sessionId]);
                    Db::exec('INSERT INTO session_pauses (session_id,user_id,paused_at) VALUES (?,?,?)', [$sessionId, $userId, self::ts(self::now())]);
                    break;
                case 'inspect': $result = self::doInspect($s, $userId, $content, $p, (string) ($req['hotspot'] ?? '')); break;
                case 'ask': $result = self::doAsk($s, $userId, $content, $p, (string) ($req['suspect'] ?? ''), (string) ($req['option'] ?? '')); break;
                case 'run_action': $result = self::doAction($s, $userId, $content, $p, (string) ($req['action'] ?? '')); break;
                case 'hint': $result = self::doHint($s, $userId, $content, $p, (int) ($req['level'] ?? 0)); break;
                case 'accuse': $result = self::doAccuse($s, $userId, $content, $p, $req); break;
                case 'note_add': case 'note_update': case 'note_delete': $result = self::doNote($s, $userId, $type, $req); break;
                case 'board_add': case 'board_move': case 'board_update': case 'board_delete':
                case 'link_add': case 'link_update': case 'link_delete': $result = Board::handle($s, $userId, $content, $p, $type, $req); break;
                case 'timeline_add': case 'timeline_flag': case 'timeline_delete': $result = self::doTimeline($s, $userId, $content, $p, $type, $req); break;
                case 'set_role': $result = self::doRole($s, $userId, $req); break;
                case 'abandon':
                    if ((int) $s['owner_id'] !== $userId) throw new HttpError(403, 'owner_only');
                    Db::exec("UPDATE game_sessions SET status='abandoned' WHERE id=?", [$sessionId]);
                    break;
                default: throw new HttpError(422, 'unknown_action', 'Unbekannte Aktion.');
            }
            if ($type !== 'heartbeat') self::logEvent($sessionId, $userId, $cid, $type, $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : null);
            if ($mutating) self::bump($sessionId);
            return ['state' => self::state(self::reload($sessionId), $userId), 'result' => $result, 'duplicate' => false];
        });
    }

    private static function reload(int $sid): array { return Db::one('SELECT * FROM game_sessions WHERE id=?', [$sid]); }
    private static function bump(int $sid): void { Db::exec('UPDATE game_sessions SET version=version+1 WHERE id=?', [$sid]); }
    private static function logEvent(int $sid, int $uid, string $cid, string $type, ?string $payload): void
    {
        Db::exec('INSERT INTO session_events (session_id,user_id,client_action_id,type,payload,created_at) VALUES (?,?,?,?,?,?)', [$sid, $uid, $cid, $type, $payload, self::ts(self::now())]);
    }

    private static function find(array $list, string $id): ?array
    {
        foreach ($list as $x) if (($x['id'] ?? null) === $id) return $x;
        return null;
    }

    private static function doInspect(array $s, int $uid, array $c, Progress $p, string $hotspotId): array
    {
        foreach ($c['scenes'] as $scene) {
            if (!Condition::test($scene['requires'] ?? null, $p)) continue;
            $h = self::find($scene['hotspots'] ?? [], $hotspotId);
            if (!$h) continue;
            if (!Condition::test($h['requires'] ?? null, $p)) break;
            self::persist((int) $s['id'], $uid, $p, 'hotspot', $hotspotId);
            $new = self::applyGrants((int) $s['id'], $uid, $p, $h['grants'] ?? null);
            return ['hotspot' => $hotspotId, 'title' => $h['title'] ?? $h['label'], 'text' => $h['text'] ?? '', 'new_evidence' => $new];
        }
        throw new HttpError(404, 'hotspot_unavailable', 'Hier gibt es nichts zu untersuchen.');
    }

    private static function doAsk(array $s, int $uid, array $c, Progress $p, string $suspectId, string $optionId): array
    {
        $su = self::find($c['suspects'], $suspectId);
        $o = $su ? self::find($su['dialogue'] ?? [], $optionId) : null;
        if (!$o || !Condition::test($o['requires'] ?? null, $p)) throw new HttpError(404, 'option_unavailable', 'Diese Frage ist derzeit nicht verfügbar.');
        if ($p->has('asked', $optionId)) return ['option' => $optionId, 'answer' => $o['answer'], 'new_evidence' => [], 'repeat' => true];
        self::persist((int) $s['id'], $uid, $p, 'asked', $optionId);
        $new = self::applyGrants((int) $s['id'], $uid, $p, $o['grants'] ?? null);
        return ['option' => $optionId, 'answer' => $o['answer'], 'new_evidence' => $new];
    }

    private static function doAction(array $s, int $uid, array $c, Progress $p, string $actionId): array
    {
        $a = self::find($c['actions'], $actionId);
        if (!$a || !Condition::test($a['requires'] ?? null, $p)) throw new HttpError(404, 'action_unavailable', 'Diese Untersuchung ist derzeit nicht möglich.');
        if ($p->has('action', $actionId)) return ['action' => $actionId, 'result' => $a['result'], 'new_evidence' => [], 'repeat' => true];
        self::persist((int) $s['id'], $uid, $p, 'action', $actionId);
        $new = self::applyGrants((int) $s['id'], $uid, $p, $a['grants'] ?? null);
        return ['action' => $actionId, 'result' => $a['result'], 'new_evidence' => $new];
    }

    private static function doHint(array $s, int $uid, array $c, Progress $p, int $level): array
    {
        if ($level < 1 || $level > 4) throw new HttpError(422, 'invalid_level');
        $text = null; $ref = null;
        foreach ($c['hints'] as $h) {
            if ((int) $h['level'] !== $level) continue;
            if (isset($h['unless']) && Condition::test($h['unless'], $p)) continue; // Meilenstein schon erreicht ⇒ nächster passender Hinweis
            $text = $h['text']; $ref = $h['id']; break;
        }
        if ($text === null) return ['level' => $level, 'text' => 'Zu diesem Aspekt hast du bereits alles Entscheidende gefunden. Prüfe deine Beweiskette.', 'free' => true];
        $already = Db::val('SELECT 1 FROM session_hints WHERE session_id=? AND hint_ref=?', [$s['id'], $ref]);
        if (!$already) {
            Db::exec('INSERT INTO session_hints (session_id,user_id,level,hint_ref,text,created_at) VALUES (?,?,?,?,?,?)', [$s['id'], $uid, $level, $ref, $text, self::ts(self::now())]);
            Db::exec('UPDATE game_sessions SET hints_used=hints_used+1 WHERE id=?', [$s['id']]);
        }
        return ['level' => $level, 'text' => $text];
    }

    private static function doNote(array $s, int $uid, string $type, array $req): array
    {
        $now = self::ts(self::now());
        if ($type === 'note_add') {
            $body = mb_substr(trim((string) ($req['body'] ?? '')), 0, 4000);
            if ($body === '') throw new HttpError(422, 'empty_note');
            $id = Db::insert('INSERT INTO session_notes (session_id,user_id,body,created_at,updated_at) VALUES (?,?,?,?,?)', [$s['id'], $uid, $body, $now, $now]);
            return ['id' => $id];
        }
        $n = Db::one('SELECT * FROM session_notes WHERE id=? AND session_id=? AND deleted=0', [(int) ($req['id'] ?? 0), $s['id']]);
        if (!$n) throw new HttpError(404, 'note_not_found');
        if ($type === 'note_delete') { Db::exec('UPDATE session_notes SET deleted=1, version=version+1 WHERE id=?', [$n['id']]); return []; }
        if ((int) ($req['base_version'] ?? -1) !== (int) $n['version']) throw new HttpError(409, 'version_conflict', 'Die Notiz wurde inzwischen geändert.', ['current' => ['id' => (int) $n['id'], 'body' => $n['body'], 'version' => (int) $n['version']]]);
        $body = mb_substr(trim((string) ($req['body'] ?? '')), 0, 4000);
        Db::exec('UPDATE session_notes SET body=?, version=version+1, updated_at=? WHERE id=?', [$body, $now, $n['id']]);
        return ['id' => (int) $n['id']];
    }

    private static function doTimeline(array $s, int $uid, array $c, Progress $p, string $type, array $req): array
    {
        $now = self::ts(self::now());
        if ($type === 'timeline_add') {
            $time = (string) ($req['time'] ?? '');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new HttpError(422, 'invalid_time');
            $title = mb_substr(trim((string) ($req['title'] ?? '')), 0, 200);
            if ($title === '') throw new HttpError(422, 'empty_title');
            $id = Db::insert('INSERT INTO session_timeline_events (session_id,user_id,at_time,title,note,created_at) VALUES (?,?,?,?,?,?)', [$s['id'], $uid, $time, $title, mb_substr((string) ($req['note'] ?? ''), 0, 1000), $now]);
            return ['id' => $id];
        }
        if ($type === 'timeline_flag') { // Markierung auf einem Fall-Ereignis (nur sichtbare)
            $ref = (string) ($req['ref'] ?? '');
            $t = self::find($c['timeline'], $ref);
            if (!$t || !Condition::test($t['requires'] ?? null, $p)) throw new HttpError(404, 'event_unavailable');
            $row = Db::one('SELECT id,flagged FROM session_timeline_events WHERE session_id=? AND event_ref=?', [$s['id'], $ref]);
            if ($row) Db::exec('UPDATE session_timeline_events SET flagged=1-flagged WHERE id=?', [$row['id']]);
            else Db::exec('INSERT INTO session_timeline_events (session_id,user_id,event_ref,flagged,created_at) VALUES (?,?,?,1,?)', [$s['id'], $uid, $ref, $now]);
            return [];
        }
        Db::exec('UPDATE session_timeline_events SET deleted=1 WHERE id=? AND session_id=? AND event_ref IS NULL', [(int) ($req['id'] ?? 0), $s['id']]);
        return [];
    }

    private static function doRole(array $s, int $uid, array $req): array
    {
        $target = (int) ($req['user_id'] ?? $uid);
        $role = (string) ($req['role'] ?? '');
        if (!in_array($role, self::ROLES, true)) throw new HttpError(422, 'invalid_role');
        if ($target !== $uid && (int) $s['owner_id'] !== $uid) throw new HttpError(403, 'owner_only');
        $n = Db::exec('UPDATE session_members SET role=? WHERE session_id=? AND user_id=?', [$role, $s['id'], $target]);
        return ['updated' => $n];
    }

    // ------------------------------------------------------------------ Anklage
    private static function doAccuse(array $s, int $uid, array $c, Progress $p, array $req): array
    {
        $sol = $c['solution'];
        $culprit = (string) ($req['culprit'] ?? '');
        if (!self::find($c['suspects'], $culprit)) throw new HttpError(422, 'invalid_culprit');
        $motive = (string) ($req['motive'] ?? ''); $method = (string) ($req['method'] ?? '');
        $evidence = array_values(array_unique(array_filter((array) ($req['evidence'] ?? []), 'is_string')));
        $timeline = array_values(array_filter((array) ($req['timeline'] ?? []), 'is_string'));
        if (count($evidence) > 12 || count($timeline) > 20) throw new HttpError(422, 'too_many_items');
        foreach ($evidence as $e) if (!$p->has('evidence', $e)) throw new HttpError(422, 'unknown_evidence'); // nur Entdecktes nutzbar
        foreach ($timeline as $t) { $te = self::find($c['timeline'], $t); if (!$te || !Condition::test($te['requires'] ?? null, $p)) throw new HttpError(422, 'unknown_event'); }

        if (count($p->list('evidence')) < (int) ($c['accusation']['unlock_min_evidence'] ?? 8)) throw new HttpError(409, 'too_early', 'Die Beweislage reicht für eine Anklage noch nicht aus.');
        $validIds = fn(string $k) => array_column($c['accusation'][$k], 'id');
        if ($motive !== '' && !in_array($motive, $validIds('motives'), true)) throw new HttpError(422, 'invalid_motive');
        if ($method !== '' && !in_array($method, $validIds('methods'), true)) throw new HttpError(422, 'invalid_method');
        $correct = $culprit === $sol['culprit'];
        $score = null; $detail = [];
        if ($correct) {
            $keys = $sol['key_evidence'];
            $hits = count(array_intersect($evidence, $keys));
            $decoys = count($evidence) - $hits;
            $chain = (int) round(40 * min(1, $hits / max(1, $sol['min_key'])) - 5 * max(0, $decoys - 1));
            $chain = max(0, min(40, $chain));
            if ($hits >= count($keys) && $decoys <= 1) $chain = 40;
            $motivePts = $motive === $sol['motive'] ? 20 : 0;
            $methodPts = $method === $sol['method'] ? 20 : 0;
            $req_t = $sol['timeline'];
            $pos = -1; $okSteps = 0;
            foreach ($req_t as $rt) { $i = array_search($rt, $timeline, true); if ($i !== false && $i > $pos) { $okSteps++; $pos = $i; } }
            $tlPts = (int) round(20 * $okSteps / max(1, count($req_t)));
            $score = $chain + $motivePts + $methodPts + $tlPts;
            $detail = ['culprit' => true, 'motive' => $motivePts > 0, 'method' => $methodPts > 0, 'chain' => $chain, 'chain_hits' => $hits, 'chain_needed' => $sol['min_key'], 'timeline' => $tlPts, 'points' => $score];
        }
        Db::exec('INSERT INTO session_accusations (session_id,user_id,culprit,motive,method,evidence_json,timeline_json,culprit_correct,score,result_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$s['id'], $uid, $culprit, $motive, $method, json_encode($evidence), json_encode($timeline), $correct ? 1 : 0, $score, json_encode($detail), self::ts(self::now())]);

        if (!$correct) {
            Db::exec('UPDATE game_sessions SET false_accusations=false_accusations+1 WHERE id=?', [$s['id']]);
            $fa = (int) $s['false_accusations'] + 1;
            $max = (int) Config::get('game.max_false_accusations', 3);
            if ($fa >= $max) {
                Db::exec("UPDATE game_sessions SET status='failed', completed_at=? WHERE id=?", [self::ts(self::now()), $s['id']]);
                return ['correct' => false, 'closed' => true, 'message' => 'Die Staatsanwaltschaft stellt das Verfahren ein. Der Fall bleibt ungelöst.'];
            }
            return ['correct' => false, 'closed' => false, 'remaining' => $max - $fa, 'message' => 'Die Staatsanwaltschaft lehnt die Anklage ab: Die Beweislage trägt diese Person nicht.'];
        }
        Db::exec("UPDATE game_sessions SET status='solved', completed_at=?, score_points=? WHERE id=?", [self::ts(self::now()), $score, $s['id']]);
        $fresh = self::reload((int) $s['id']);
        $rank = null;
        if ((int) $s['ranked'] === 1) {
            $players = (int) Db::val('SELECT COUNT(*) FROM session_members WHERE session_id=?', [$s['id']]);
            $scoreSecs = (int) $fresh['active_seconds'] + (int) $fresh['false_accusations'] * (int) Config::get('game.wrong_accusation_penalty_seconds', 600) + (int) $fresh['hints_used'] * (int) Config::get('game.hint_penalty_seconds', 300);
            Db::exec('INSERT INTO leaderboards (case_id,session_id,user_id,mode,players,hints_used,false_accusations,active_seconds,score_seconds,score_points,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$s['case_id'], $s['id'], $s['owner_id'], $s['mode'], $players, $fresh['hints_used'], $fresh['false_accusations'], $fresh['active_seconds'], $scoreSecs, $score, self::ts(self::now())]);
            $rank = 1 + (int) Db::val('SELECT COUNT(*) FROM leaderboards WHERE case_id=? AND mode=? AND score_seconds < ?', [$s['case_id'], $s['mode'], $scoreSecs]);
        }
        $ach = Achievements::award($fresh, $c, $score);
        return ['correct' => true, 'closed' => true, 'detail' => $detail, 'rank' => $rank, 'achievements' => $ach];
    }

    // ------------------------------------------------------------------ Zustandsprojektion
    /** Liefert ausschliesslich Inhalte, die für die Sitzung freigeschaltet sind. */
    public static function state(array $s, int $userId): array
    {
        $c = CaseRepository::contentForVersion((int) $s['case_version_id']);
        $p = self::loadProgress((int) $s['id']);
        $sid = (int) $s['id'];
        $active = (int) $s['active_seconds'];
        if ($s['status'] === 'active') $active += min(max(0, self::now() - strtotime($s['last_activity_at'] . ' UTC')), (int) Config::get('game.idle_cap_seconds', 90));

        $evidence = [];
        $progressRows = [];
        foreach (Db::all('SELECT kind,ref,user_id,created_at FROM session_progress WHERE session_id=?', [$sid]) as $r) $progressRows[$r['kind'] . ':' . $r['ref']] = $r;
        foreach ($c['evidence'] as $e) if ($p->has('evidence', $e['id'])) {
            $links = array_values(array_filter($e['links'] ?? [], fn($l) => $p->has('evidence', $l)));
            $evidence[] = ['id' => $e['id'], 'title' => $e['title'], 'category' => $e['category'], 'description' => $e['description'], 'location' => $e['location'], 'time' => $e['time'] ?? null, 'media' => $e['media'] ?? null, 'links' => $links, 'found_at' => $progressRows['evidence:' . $e['id']]['created_at'] ?? null];
        }

        $scenes = [];
        foreach ($c['scenes'] as $sc) {
            if (!Condition::test($sc['requires'] ?? null, $p)) continue;
            $hs = [];
            foreach ($sc['hotspots'] ?? [] as $h) {
                if (!Condition::test($h['requires'] ?? null, $p)) continue;
                $done = $p->has('hotspot', $h['id']);
                $hs[] = ['id' => $h['id'], 'label' => $h['label'], 'x' => $h['x'], 'y' => $h['y'], 'w' => $h['w'], 'h' => $h['h'], 'inspected' => $done, 'title' => $done ? ($h['title'] ?? $h['label']) : null, 'text' => $done ? ($h['text'] ?? '') : null];
            }
            $scenes[] = ['id' => $sc['id'], 'title' => $sc['title'], 'description' => $sc['description'] ?? '', 'image' => $sc['image'] ?? null, 'time_of_day' => $sc['time_of_day'] ?? null, 'hotspots' => $hs];
        }

        $suspects = [];
        foreach ($c['suspects'] as $su) {
            $avail = []; $hist = [];
            foreach ($su['dialogue'] ?? [] as $o) {
                if ($p->has('asked', $o['id'])) $hist[] = ['id' => $o['id'], 'question' => $o['question'], 'answer' => $o['answer'], 'at' => $progressRows['asked:' . $o['id']]['created_at'] ?? null];
                elseif (Condition::test($o['requires'] ?? null, $p)) $avail[] = ['id' => $o['id'], 'question' => $o['question'], 'confront' => !empty($o['confront'])];
            }
            usort($hist, fn($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
            $suspects[] = ['id' => $su['id'], 'name' => $su['name'], 'age' => $su['age'], 'job' => $su['job'], 'relation' => $su['relation'], 'bio' => $su['bio'], 'portrait' => $su['portrait'] ?? null, 'mood' => $su['mood'] ?? null, 'options' => $avail, 'history' => $hist];
        }

        $actions = [];
        foreach ($c['actions'] as $a) {
            if (!Condition::test($a['requires'] ?? null, $p)) continue;
            $done = $p->has('action', $a['id']);
            $actions[] = ['id' => $a['id'], 'title' => $a['title'], 'description' => $a['description'], 'done' => $done, 'result' => $done ? $a['result'] : null];
        }

        $custom = Db::all('SELECT id,event_ref,at_time,title,note,flagged,user_id FROM session_timeline_events WHERE session_id=? AND deleted=0', [$sid]);
        $flagged = []; $own = [];
        foreach ($custom as $r) { if ($r['event_ref']) { if ($r['flagged']) $flagged[$r['event_ref']] = true; } else $own[] = ['id' => (int) $r['id'], 'time' => $r['at_time'], 'title' => $r['title'], 'note' => $r['note'], 'custom' => true]; }
        $timeline = [];
        foreach ($c['timeline'] as $t) if (Condition::test($t['requires'] ?? null, $p))
            $timeline[] = ['id' => $t['id'], 'time' => $t['time'], 'day' => $t['day'] ?? 0, 'uncertainty' => $t['uncertainty'] ?? 0, 'title' => $t['title'], 'text' => $t['text'] ?? '', 'source' => $t['source'] ?? null, 'flagged' => isset($flagged[$t['id']])];
        usort($timeline, fn($a, $b) => ($a['day'] * 1440 + self::mins($a['time'])) <=> ($b['day'] * 1440 + self::mins($b['time'])));

        $notes = array_map(fn($n) => ['id' => (int) $n['id'], 'body' => $n['body'], 'version' => (int) $n['version'], 'author' => $n['username'], 'mine' => (int) $n['user_id'] === $userId, 'updated_at' => $n['updated_at']],
            Db::all('SELECT n.*,u.username FROM session_notes n JOIN users u ON u.id=n.user_id WHERE n.session_id=? AND n.deleted=0 ORDER BY n.id', [$sid]));

        $board = Board::snapshot($sid);
        $members = Db::all('SELECT u.id,u.username,u.avatar,m.role FROM session_members m JOIN users u ON u.id=m.user_id WHERE m.session_id=? ORDER BY m.joined_at', [$sid]);
        $hints = Db::all('SELECT level,text FROM session_hints WHERE session_id=? ORDER BY id', [$sid]);
        $acc = Db::all('SELECT culprit_correct,created_at FROM session_accusations WHERE session_id=? ORDER BY id', [$sid]);

        $out = [
            'id' => $sid, 'slug' => Db::val('SELECT slug FROM cases WHERE id=?', [$s['case_id']]), 'version' => (int) $s['version'], 'status' => $s['status'], 'mode' => $s['mode'], 'ranked' => (bool) $s['ranked'],
            'invite_code' => $s['mode'] === 'team' ? $s['invite_code'] : null, 'owner_id' => (int) $s['owner_id'],
            'active_seconds' => $active, 'hints_used' => (int) $s['hints_used'], 'false_accusations' => (int) $s['false_accusations'],
            'max_false_accusations' => (int) Config::get('game.max_false_accusations', 3),
            'meta' => $c['meta'], 'briefing' => $c['briefing'] ?? '',
            'scenes' => $scenes, 'evidence' => $evidence, 'suspects' => $suspects, 'actions' => $actions,
            'timeline' => $timeline, 'custom_timeline' => $own, 'notes' => $notes, 'board' => $board, 'members' => $members, 'hints' => $hints,
            'roles' => self::ROLES, 'me' => $userId,
            'accusation' => self::accusationView($c, count($evidence), count($acc)),
        ];
        if (in_array($s['status'], ['solved', 'failed'], true)) {
            $last = Db::one('SELECT result_json,score FROM session_accusations WHERE session_id=? AND culprit_correct=1 ORDER BY id DESC LIMIT 1', [$sid]);
            $out['resolution'] = ['culprit' => $c['solution']['culprit'], 'motive' => $c['solution']['motive'], 'method' => $c['solution']['method'], 'key_evidence' => $c['solution']['key_evidence'], 'text' => $c['solution']['resolution'], 'solved' => $s['status'] === 'solved', 'result' => $last ? json_decode($last['result_json'], true) : null, 'score_points' => $s['score_points'] !== null ? (int) $s['score_points'] : null];
        }
        return $out;
    }

    /** Motiv-/Methodenoptionen verraten Details; sie werden erst nach ausreichender Ermittlungsarbeit freigegeben. */
    private static function accusationView(array $c, int $evidenceCount, int $attempts): array
    {
        $need = (int) ($c['accusation']['unlock_min_evidence'] ?? 8);
        $open = $evidenceCount >= $need;
        return ['unlocked' => $open, 'need_evidence' => $need, 'attempts' => $attempts,
                'motives' => $open ? $c['accusation']['motives'] : [], 'methods' => $open ? $c['accusation']['methods'] : []];
    }

    private static function mins(string $t): int { return (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2); }

    /** Dashboard-Liste: eigene Sitzungen (ohne Fallinhalte). */
    public static function mySessions(int $uid): array
    {
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'slug' => $r['slug'], 'case_number' => $r['case_number'], 'status' => $r['status'], 'mode' => $r['mode'], 'ranked' => (bool) $r['ranked'],
            'active_seconds' => (int) $r['active_seconds'], 'hints_used' => (int) $r['hints_used'], 'started_at' => $r['started_at'], 'completed_at' => $r['completed_at'], 'score_points' => $r['score_points'],
        ], Db::all('SELECT gs.*,c.slug,c.case_number FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id JOIN cases c ON c.id=gs.case_id WHERE m.user_id=? ORDER BY gs.last_activity_at DESC LIMIT 100', [$uid]));
    }
}
