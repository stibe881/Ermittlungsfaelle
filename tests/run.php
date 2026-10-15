<?php
// Usage: php tests/run.php   (benötigt MariaDB/MySQL mit DB 'coldcase_test', siehe tests/config.test.php)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/Harness.php';

use ColdCase\Auth\AuthService;
use ColdCase\Core\{Config, Db, HttpError};
use ColdCase\Game\{CaseRepository, CaseValidator, Condition, GameEngine, Progress};

Config::load(__DIR__ . '/config.test.php');

// --- frische Datenbank
$pdo = Db::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (Db::all('SHOW TABLES') as $row) $pdo->exec('DROP TABLE `' . array_values($row)[0] . '`');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
passthru('CC_CONFIG=' . escapeshellarg(__DIR__ . '/config.test.php') . ' php ' . escapeshellarg(__DIR__ . '/../bin/migrate.php') . ' > /dev/null');

$caseJson = json_decode(file_get_contents(__DIR__ . '/../cases/fall-001-hotel.json'), true);
$res = CaseRepository::saveVersion('fall-001-hotel', $caseJson, null);
CaseRepository::publish('fall-001-hotel', $res['version_id']);

function mkUser(string $n): int { return AuthService::register("$n@example.com", $n, 'Passwort-12345', true, '10.0.0.' . random_int(1, 250))['id']; }
$cidCounter = 0;
function act(int $sid, int $uid, string $type, array $extra = []): array
{
    global $cidCounter;
    return GameEngine::act($sid, $uid, ['type' => $type, 'client_action_id' => 'test-action-' . (++$cidCounter) . '-' . bin2hex(random_bytes(3))] + $extra);
}

echo "Bedingungen\n";
T::test('Condition: all/any/not/leaf', function () {
    $p = new Progress(); $p->add('evidence', 'a'); $p->add('flag', 'f');
    T::ok(Condition::test(null, $p));
    T::ok(Condition::test(['evidence' => 'a'], $p));
    T::ok(!Condition::test(['evidence' => 'b'], $p));
    T::ok(Condition::test(['all' => [['evidence' => 'a'], ['flag' => 'f']]], $p));
    T::ok(!Condition::test(['all' => [['evidence' => 'a'], ['flag' => 'x']]], $p));
    T::ok(Condition::test(['any' => [['evidence' => 'z'], ['flag' => 'f']]], $p));
    T::ok(Condition::test(['not' => ['evidence' => 'z']], $p));
    T::ok(!Condition::test(['bogus' => 'x'], $p), 'unbekannte Bedingung nie erfüllt');
    T::ok(!Condition::isWellFormed(['bogus' => 'x']));
});

echo "Fall-Validierung\n";
T::test('Fall 001 besteht Validierung (inkl. 24 Beweise, 27 Optionen)', function () use ($caseJson) {
    $r = CaseValidator::validate($caseJson);
    T::ok($r['ok'], implode('; ', $r['errors']));
    T::ok($r['stats']['evidence'] >= 15 && $r['stats']['dialogue_options'] >= 20);
});
T::test('Validator erkennt unerreichbaren Beweis', function () use ($caseJson) {
    $c = $caseJson;
    foreach ($c['actions'] as &$a) if ($a['id'] === 'a_blood') $a['requires'] = ['evidence' => 'ev_blood_report']; // Selbstabhängigkeit ⇒ Zirkel
    $r = CaseValidator::validate($c);
    T::ok(!$r['ok'] && count(array_filter($r['errors'], fn($e) => str_contains($e, 'unerreichbar'))) > 0);
});
T::test('Validator erkennt toten Dialogzweig & unbekannte Verweise', function () use ($caseJson) {
    $c = $caseJson;
    $c['suspects'][0]['dialogue'][3]['requires'] = ['evidence' => 'ev_gibt_es_nicht'];
    $r = CaseValidator::validate($c);
    T::ok(!$r['ok']);
});
T::test('Validator erkennt Täter-Namen in Hinweisen und falsche Lösung', function () use ($caseJson) {
    $c = $caseJson;
    $c['hints'][1]['text'] = 'Schau dir Lukas Brandt genauer an.';
    $r = CaseValidator::validate($c);
    T::ok(!$r['ok'] && str_contains(implode(' ', $r['errors']), 'nennt den Täter'));
    $c = $caseJson; $c['solution']['culprit'] = 'su_niemand';
    T::ok(!CaseValidator::validate($c)['ok']);
});
T::test('Veröffentlichung ungültiger Version wird verweigert', function () use ($caseJson) {
    $c = $caseJson; $c['solution']['culprit'] = 'su_niemand';
    $r = CaseRepository::saveVersion('fall-001-hotel', $c, null);
    T::throws(fn() => CaseRepository::publish('fall-001-hotel', $r['version_id']), 'validation_failed');
});

echo "Spiel-Engine\n";
$alice = mkUser('alice'); $bob = mkUser('bob'); $carol = mkUser('carol');
$sess = GameEngine::createSession($alice, 'fall-001-hotel', 'team');
$sid = $sess['id'];

T::test('Erster Versuch ist ranglistenfähig, Zustand enthält keine Lösung', function () use ($sess, $sid, $alice) {
    T::ok($sess['ranked'] === true);
    $st = GameEngine::state(GameEngine::sessionFor($sid, $alice), $alice);
    $json = json_encode($st, JSON_UNESCAPED_UNICODE);
    T::ok(!isset($st['resolution']) && !isset($st['solution']));
    foreach (['Alpenglühen', 'Zusatzkarte ausgegeben', 'Express-Reinigung', 'Bronzefalken', 'key_evidence', 'Fälschung'] as $needle)
        T::ok(!str_contains($json, $needle), "Spoiler im Anfangszustand: $needle");
    T::eq(count($st['evidence']), 2);
    T::eq($st['accusation']['unlocked'], false); T::eq($st['accusation']['methods'], [], 'Anklage-Optionen zu Beginn gesperrt');
    $ids = array_column($st['scenes'], 'id');
    T::ok(!in_array('sc_bar', $ids, true) && !in_array('sc_laundry', $ids, true), 'Gesperrte Szenen nicht ausgeliefert');
    T::ok(!in_array('hs_ledger', array_column($st['scenes'][2]['hotspots'], 'id'), true), 'Verborgene Hotspots nicht ausgeliefert');
});
T::test('IDOR: Fremde Sitzung nicht lesbar/steuerbar', function () use ($sid, $bob) {
    T::throws(fn() => GameEngine::sessionFor($sid, $bob), 'session_not_found');
    T::throws(fn() => act($sid, $bob, 'heartbeat'), 'session_not_found');
});
T::test('Gesperrte Inhalte können nicht erzwungen werden', function () use ($sid, $alice) {
    T::throws(fn() => act($sid, $alice, 'inspect', ['hotspot' => 'hs_ledger']), 'hotspot_unavailable');
    T::throws(fn() => act($sid, $alice, 'inspect', ['hotspot' => 'hs_jacket']), 'hotspot_unavailable');
    T::throws(fn() => act($sid, $alice, 'ask', ['suspect' => 'su_brandt', 'option' => 'b_blood']), 'option_unavailable');
    T::throws(fn() => act($sid, $alice, 'run_action', ['action' => 'a_blood']), 'action_unavailable');
    T::throws(fn() => act($sid, $alice, 'accuse', ['culprit' => 'su_brandt', 'evidence' => ['ev_blood_report']]), 'unknown_evidence');
});
T::test('Idempotenz: doppelte client_action_id wirkt nur einmal', function () use ($sid, $alice) {
    $req = ['type' => 'note_add', 'body' => 'Idempotenztest', 'client_action_id' => 'idem-test-0001'];
    $a = GameEngine::act($sid, $alice, $req); $b = GameEngine::act($sid, $alice, $req);
    T::ok(!$a['duplicate'] && $b['duplicate']);
    T::eq(count(array_filter($b['state']['notes'], fn($n) => $n['body'] === 'Idempotenztest')), 1);
});
T::test('Versionsnummer steigt bei Änderung, nicht bei Heartbeat', function () use ($sid, $alice) {
    $v0 = act($sid, $alice, 'heartbeat')['state']['version'];
    $v1 = act($sid, $alice, 'inspect', ['hotspot' => 'hs_weapon'])['state']['version'];
    $v2 = act($sid, $alice, 'heartbeat')['state']['version'];
    T::ok($v1 > $v0 && $v2 === $v1);
});
T::test('Team: Beitritt per Code, Limit & Rollen', function () use ($sid, $alice, $bob, $carol) {
    $code = GameEngine::state(GameEngine::sessionFor($sid, $alice), $alice)['invite_code'];
    T::eq(GameEngine::join($bob, strtolower($code))['id'], $sid);
    T::eq(GameEngine::join($bob, $code)['id'], $sid, 'erneuter Beitritt idempotent');
    T::throws(fn() => GameEngine::join($carol, 'XXXXXX'), 'invite_invalid');
    $st = act($sid, $bob, 'inspect', ['hotspot' => 'hs_glasses'])['state'];
    T::eq(count($st['members']), 2);
    T::ok(in_array('ev_glasses', array_column($st['evidence'], 'id'), true), 'Bobs Fund ist für Alice sichtbar');
    $st2 = GameEngine::state(GameEngine::sessionFor($sid, $alice), $alice);
    T::ok(in_array('ev_glasses', array_column($st2['evidence'], 'id'), true));
    act($sid, $bob, 'set_role', ['role' => 'forensiker']);
    T::throws(fn() => act($sid, $bob, 'set_role', ['role' => 'ermittler', 'user_id' => $alice]), 'owner_only');
});
T::test('Pinnwand: optimistische Nebenläufigkeit', function () use ($sid, $alice, $bob) {
    $r = act($sid, $alice, 'board_add', ['kind' => 'evidence', 'ref' => 'ev_weapon', 'x' => 10, 'y' => 10]);
    $item = $r['state']['board']['items'][0];
    act($sid, $alice, 'board_move', ['id' => $item['id'], 'x' => 100, 'y' => 120, 'base_version' => $item['version']]);
    $e = null;
    try { act($sid, $bob, 'board_move', ['id' => $item['id'], 'x' => 5, 'y' => 5, 'base_version' => $item['version']]); } catch (HttpError $x) { $e = $x; }
    T::ok($e && $e->errorCode === 'version_conflict' && isset($e->extra['current']['version']));
    $n = act($sid, $alice, 'board_add', ['kind' => 'note', 'label' => 'Wer lügt?', 'x' => 200, 'y' => 40])['state']['board']['items'];
    $a = $n[0]['id']; $b = $n[1]['id'];
    $st = act($sid, $bob, 'link_add', ['from' => $a, 'to' => $b, 'label' => 'Widerspruch', 'color' => 'red'])['state'];
    T::eq(count($st['board']['links']), 1);
    T::throws(fn() => act($sid, $alice, 'link_add', ['from' => $b, 'to' => $a]), 'link_exists');
    T::throws(fn() => act($sid, $alice, 'board_add', ['kind' => 'evidence', 'ref' => 'ev_blood_report']), 'unknown_evidence', 'Unentdeckter Beweis nicht pinnbar');
    $st = act($sid, $alice, 'board_delete', ['id' => $a])['state'];
    T::eq(count($st['board']['links']), 0, 'Verbindungen werden mit Element entfernt');
});
T::test('Notizen: Konflikterkennung', function () use ($sid, $alice, $bob) {
    $st = act($sid, $alice, 'note_add', ['body' => 'Version 1'])['state'];
    $note = array_values(array_filter($st['notes'], fn($n) => $n['body'] === 'Version 1'))[0];
    act($sid, $alice, 'note_update', ['id' => $note['id'], 'body' => 'Version 2', 'base_version' => $note['version']]);
    T::throws(fn() => act($sid, $bob, 'note_update', ['id' => $note['id'], 'body' => 'X', 'base_version' => $note['version']]), 'version_conflict');
});

echo "Zeitmessung\n";
T::test('Aktive Zeit: Leerlauf gedeckelt, Pause zählt nicht, Pausierte Sitzung gesperrt', function () {
    $u = mkUser('timer'); $s = GameEngine::createSession($u, 'fall-001-hotel', 'solo')['id'];
    $t = 1_800_000_000; GameEngine::$clock = $t;
    Db::exec('UPDATE game_sessions SET last_activity_at=? WHERE id=?', [gmdate('Y-m-d H:i:s', $t), $s]);
    GameEngine::$clock = $t + 30; $st = act($s, $u, 'heartbeat')['state'];
    T::eq($st['active_seconds'], 30);
    GameEngine::$clock = $t + 30 + 3600; $st = act($s, $u, 'heartbeat')['state']; // 1 h Leerlauf
    T::eq($st['active_seconds'], 30 + 90, 'Leerlauf auf 90 s gedeckelt');
    act($s, $u, 'pause');
    GameEngine::$clock += 7200;
    T::throws(fn() => act($s, $u, 'inspect', ['hotspot' => 'hs_weapon']), 'session_paused');
    T::eq(act($s, $u, 'resume')['state']['active_seconds'], 120, 'Pause zählt nicht');
    GameEngine::$clock += 20; T::eq(act($s, $u, 'heartbeat')['state']['active_seconds'], 140);
    GameEngine::$clock = null;
});
T::test('Mehrere Geräte/Spieler: eine Uhr pro Sitzung', function () use ($sid, $alice, $bob) {
    $t = 1_800_100_000; GameEngine::$clock = $t;
    Db::exec('UPDATE game_sessions SET last_activity_at=? WHERE id=?', [gmdate('Y-m-d H:i:s', $t), $sid]);
    $a0 = GameEngine::state(GameEngine::sessionFor($sid, $alice), $alice)['active_seconds'];
    GameEngine::$clock = $t + 10; act($sid, $alice, 'heartbeat'); act($sid, $bob, 'heartbeat');
    GameEngine::$clock = $t + 20; $st = act($sid, $bob, 'heartbeat')['state'];
    T::eq($st['active_seconds'] - $a0 <= 20, true, 'Zwei Spieler verdoppeln die Zeit nicht');
    GameEngine::$clock = null;
});

echo "Hinweise\n";
T::test('Hinweise: Stufen, Kosten, keine Täternennung, kostenlos wiederholbar', function () use ($sid, $alice) {
    $h1 = act($sid, $alice, 'hint', ['level' => 1]); $n1 = $h1['state']['hints_used'];
    T::ok($n1 >= 1);
    act($sid, $alice, 'hint', ['level' => 1]);
    T::eq(GameEngine::state(GameEngine::sessionFor($sid, $alice), $alice)['hints_used'], $n1, 'Wiederholung kostenlos');
    foreach ([2, 3, 4] as $lv) {
        $r = act($sid, $alice, 'hint', ['level' => $lv])['result'];
        T::ok(!str_contains($r['text'], 'Brandt') && !str_contains($r['text'], 'Lukas'), "Stufe $lv nennt Täter");
    }
    T::throws(fn() => act($sid, $alice, 'hint', ['level' => 9]), 'invalid_level');
});

echo "Komplett-Durchspielen (Fall 001)\n";
// Neue Sitzung (Carol, Solo) → ranglistenfähig, ohne Hinweise
$carolSess = GameEngine::createSession($carol, 'fall-001-hotel', 'solo')['id'];
$steps = [
    ['inspect', ['hotspot' => 'hs_weapon']], ['inspect', ['hotspot' => 'hs_glasses']], ['inspect', ['hotspot' => 'hs_calendar']],
    ['inspect', ['hotspot' => 'hs_phone']], ['inspect', ['hotspot' => 'hs_briefcase']], ['inspect', ['hotspot' => 'hs_cam']],
    ['inspect', ['hotspot' => 'hs_stairs']], ['inspect', ['hotspot' => 'hs_board']],
    ['ask', ['suspect' => 'su_brandt', 'option' => 'b_where']], ['inspect', ['hotspot' => 'hs_receipts']],
    ['ask', ['suspect' => 'su_mara', 'option' => 'm_cards']], ['inspect', ['hotspot' => 'hs_ledger']],
    ['run_action', ['action' => 'a_locks']], ['ask', ['suspect' => 'su_brandt', 'option' => 'b_prints']], ['run_action', ['action' => 'a_print']],
    ['inspect', ['hotspot' => 'hs_clock']], ['ask', ['suspect' => 'su_mara', 'option' => 'm_leave']], ['ask', ['suspect' => 'su_mara', 'option' => 'm_last']],
    ['inspect', ['hotspot' => 'hs_slip']], ['inspect', ['hotspot' => 'hs_jacket']], ['run_action', ['action' => 'a_blood']],
    ['ask', ['suspect' => 'su_tobias', 'option' => 't_suite']], ['ask', ['suspect' => 'su_roth', 'option' => 'r_where']], ['ask', ['suspect' => 'su_roth', 'option' => 'r_heard']],
    ['ask', ['suspect' => 'su_elena', 'option' => 'e_where']], ['ask', ['suspect' => 'su_elena', 'option' => 'e_outside']], ['run_action', ['action' => 'a_phone']],
    ['run_action', ['action' => 'a_autopsy']], ['run_action', ['action' => 'a_bank']],
    ['ask', ['suspect' => 'su_brandt', 'option' => 'b_bar']], ['ask', ['suspect' => 'su_brandt', 'option' => 'b_lock']], ['ask', ['suspect' => 'su_brandt', 'option' => 'b_blood']],
];
T::test('Alle Spielschritte laufen in der vorgesehenen Reihenfolge', function () use ($steps, $carolSess, $carol) {
    GameEngine::$clock = 1_800_200_000;
    Db::exec('UPDATE game_sessions SET last_activity_at=? WHERE id=?', [gmdate('Y-m-d H:i:s', GameEngine::$clock), $carolSess]);
    foreach ($steps as [$t, $x]) { GameEngine::$clock += 20; act($carolSess, $carol, $t, $x); }
    $st = GameEngine::state(GameEngine::sessionFor($carolSess, $carol), $carol);
    T::eq(count($st['evidence']), 24, 'Alle 24 Beweise entdeckt');
    T::eq($st['active_seconds'], 20 * count($steps));
    T::ok(count($st['timeline']) >= 14);
    T::ok(!isset($st['resolution']));
});
T::test('Zu frühe Anklage wird serverseitig abgelehnt', function () {
    $u = mkUser('early'); $s = GameEngine::createSession($u, 'fall-001-hotel', 'solo')['id'];
    T::throws(fn() => act($s, $u, 'accuse', ['culprit' => 'su_brandt']), 'too_early');
});
T::test('Falsche Anklage: Fehlanklage zählt, Lösung bleibt verborgen', function () use ($carolSess, $carol) {
    $r = act($carolSess, $carol, 'accuse', ['culprit' => 'su_elena', 'motive' => 'mo_inherit', 'method' => 'me_blunt', 'evidence' => ['ev_autopsy']]);
    T::eq($r['result']['correct'], false);
    T::ok(!isset($r['state']['resolution']));
    T::eq($r['state']['false_accusations'], 1);
    T::ok(!str_contains(json_encode($r['result']), 'su_brandt'));
});
T::test('Richtige Anklage löst Fall, vergibt Punkte, Rang und Erfolge', function () use ($carolSess, $carol) {
    $r = act($carolSess, $carol, 'accuse', [
        'culprit' => 'su_brandt', 'motive' => 'mo_cover', 'method' => 'me_blunt',
        'evidence' => ['ev_card_ledger', 'ev_lock_log', 'ev_bar_receipt', 'ev_briefcase', 'ev_print_report', 'ev_blood_report'],
        'timeline' => ['tl_bar', 'tl_call', 'tl_enter', 'tl_leave', 'tl_clean'],
    ]);
    T::eq($r['result']['correct'], true);
    T::eq($r['result']['detail']['points'], 100, 'Volle Punktzahl');
    T::eq($r['result']['rank'], 1);
    T::eq($r['state']['status'], 'solved');
    T::ok(isset($r['state']['resolution']['text']['summary']));
    $codes = array_column(Db::all('SELECT code FROM user_achievements WHERE user_id=?', [$carol]), 'code');
    foreach (['first_case', 'no_hints', 'perfect_chain', 'all_suspects'] as $c) T::ok(in_array($c, $codes, true), "Erfolg $c");
    T::throws(fn() => act($carolSess, $carol, 'inspect', ['hotspot' => 'hs_weapon']), 'session_closed');
});
T::test('Rangliste: Strafzeit, getrennt nach Solo/Team', function () use ($carolSess) {
    $row = Db::one('SELECT * FROM leaderboards WHERE session_id=?', [$carolSess]);
    T::eq((int) $row['active_seconds'], 20 * 32);
    T::eq((int) $row['score_seconds'], 20 * 32 + 600, '+10 Min. für eine Fehlanklage');
    T::eq($row['mode'], 'solo');
});
T::test('Wiederholung nach Lösung ist nicht ranglistenfähig', function () use ($carol) {
    $again = GameEngine::createSession($carol, 'fall-001-hotel', 'solo');
    T::eq($again['ranked'], false);
    T::eq((int) Db::val('SELECT COUNT(*) FROM leaderboards WHERE user_id=?', [$carol]), 1);
});
T::test('Teilweise Beweiskette: Punkte werden reduziert, Täter korrekt', function () {
    $u = mkUser('dave'); $s = GameEngine::createSession($u, 'fall-001-hotel', 'solo')['id'];
    foreach ($GLOBALS['steps'] as [$t, $x]) act($s, $u, $t, $x);
    $r = act($s, $u, 'accuse', ['culprit' => 'su_brandt', 'motive' => 'mo_inherit', 'method' => 'me_poison', 'evidence' => ['ev_card_ledger', 'ev_bar_receipt'], 'timeline' => ['tl_enter']]);
    T::eq($r['result']['correct'], true);
    T::ok($r['result']['detail']['points'] < 50, 'Lückenhafte Begründung = wenig Punkte: ' . $r['result']['detail']['points']);
    T::eq($r['result']['detail']['motive'], false);
});
T::test('Drei Fehlanklagen beenden den Fall als ungelöst', function () {
    $u = mkUser('erin'); $s = GameEngine::createSession($u, 'fall-001-hotel', 'solo')['id'];
    foreach ($GLOBALS['steps'] as [$t, $x]) act($s, $u, $t, $x);
    for ($i = 0; $i < 3; $i++) $r = act($s, $u, 'accuse', ['culprit' => 'su_mara']);
    T::eq($r['state']['status'], 'failed');
    T::eq($r['state']['resolution']['solved'], false);
    T::eq((int) Db::val('SELECT COUNT(*) FROM leaderboards WHERE user_id=?', [$u]), 0);
});

echo "Konto & Datenschutz\n";
T::test('Registrierung validiert, Passwort gehasht, Duplikate abgelehnt', function () {
    T::throws(fn() => AuthService::register('kaputt', 'user1', 'Passwort-12345', true, '9.9.9.9'), 'invalid_email');
    T::throws(fn() => AuthService::register('x@example.com', 'u', 'Passwort-12345', true, '9.9.9.9'), 'invalid_username');
    T::throws(fn() => AuthService::register('x@example.com', 'user1', 'kurz', true, '9.9.9.9'), 'weak_password');
    T::throws(fn() => AuthService::register('x@example.com', 'user1', 'Passwort-12345', false, '9.9.9.9'), 'consent_required');
    T::throws(fn() => AuthService::register('alice@example.com', 'neu', 'Passwort-12345', true, '9.9.9.9'), 'exists');
    $h = Db::val('SELECT password_hash FROM users WHERE username=?', ['alice']);
    T::ok(password_verify('Passwort-12345', $h) && !str_contains($h, 'Passwort'));
});
T::test('Rate-Limit greift bei Login-Versuchen', function () {
    T::throws(function () { for ($i = 0; $i < 30; $i++) { try { AuthService::login('alice', 'falsch', '8.8.8.8'); } catch (HttpError $e) { if ($e->errorCode === 'rate_limited') throw $e; } } }, 'rate_limited');
});
T::test('Datenexport und Kontolöschung (Kaskade)', function () {
    $u = (int) Db::val("SELECT id FROM users WHERE username='dave'");
    $ex = AuthService::exportData($u);
    T::ok(isset($ex['user']['email']) && !isset($ex['user']['password_hash']));
    T::throws(fn() => AuthService::deleteAccount($u, 'falsches-passwort', '1.1.1.1'), 'bad_password');
    AuthService::deleteAccount($u, 'Passwort-12345', '1.1.1.1');
    T::eq((int) Db::val('SELECT COUNT(*) FROM game_sessions WHERE owner_id=?', [$u]), 0);
});
T::test('Rangtitel nach gelösten Fällen', function () {
    T::eq(AuthService::rankFor(0), 'Polizeianwärter'); T::eq(AuthService::rankFor(1), 'Ermittler'); T::eq(AuthService::rankFor(25), 'Legende der Mordkommission');
});

echo "\n" . T::$pass . " Assertions erfolgreich, " . T::$fail . " Test(s) fehlgeschlagen\n";
exit(T::$fail ? 1 : 0);
