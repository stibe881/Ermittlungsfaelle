<?php
declare(strict_types=1);
namespace ColdCase\Game;

/** Statische Prüfung + Erreichbarkeitssimulation eines Falls vor der Veröffentlichung. */
final class CaseValidator
{
    public const CATEGORIES = ['foto','fingerabdruck','dna','blut','autopsie','telefon','chat','email','bank','video','fahrzeug','aussage','brief','quittung','vertrag','tagebuch','audio','gps','digital','dokument','objekt','protokoll'];
    public const TYPES = ['Mord','Vermisstenfall','Entführung','Serienverbrechen','Ungeklärter Todesfall','Historischer Kriminalfall','Wirtschaftskriminalität','Brandstiftung','Komplexe Verschwörung'];

    public static function validate(array $c, ?string $mediaRoot = null): array
    {
        $err = []; $warn = [];
        $e = function (string $m) use (&$err) { $err[] = $m; };
        $w = function (string $m) use (&$warn) { $warn[] = $m; };

        foreach (['meta', 'scenes', 'evidence', 'suspects', 'actions', 'timeline', 'hints', 'accusation', 'solution'] as $k)
            if (!isset($c[$k]) || !is_array($c[$k])) { $e("Pflichtabschnitt fehlt: $k"); }
        if ($err) return self::report($err, $warn, []);

        $m = $c['meta'];
        foreach (['case_number', 'title', 'teaser', 'location', 'year', 'difficulty', 'duration_min', 'type'] as $k)
            if (!isset($m[$k]) || $m[$k] === '') $e("meta.$k fehlt");
        if (isset($m['difficulty']) && (!is_int($m['difficulty']) || $m['difficulty'] < 1 || $m['difficulty'] > 5)) $e('meta.difficulty muss 1–5 sein');
        if (isset($m['type']) && !in_array($m['type'], self::TYPES, true)) $e('meta.type unbekannt: ' . $m['type']);

        // ---- IDs sammeln
        $ids = ['phoneitem' => [], 'scene' => [], 'hotspot' => [], 'evidence' => [], 'suspect' => [], 'asked' => [], 'action' => [], 'timeline' => []];
        $reg = function (string $kind, mixed $id, string $ctx) use (&$ids, $e) {
            if (!is_string($id) || !preg_match('/^[a-z0-9_]{2,48}$/', $id)) { $e("Ungültige ID in $ctx"); return; }
            if (isset($ids[$kind][$id])) $e("Doppelte $kind-ID: $id");
            $ids[$kind][$id] = true;
        };
        foreach ($c['scenes'] as $s) {
            $reg('scene', $s['id'] ?? null, 'scene');
            foreach ($s['hotspots'] ?? [] as $h) $reg('hotspot', $h['id'] ?? null, 'hotspot in ' . ($s['id'] ?? '?'));
        }
        foreach ($c['evidence'] as $ev) {
            $reg('evidence', $ev['id'] ?? null, 'evidence');
            if (!in_array($ev['category'] ?? '', self::CATEGORIES, true)) $e('Beweiskategorie ungültig: ' . ($ev['id'] ?? '?'));
            foreach (['title', 'description', 'location'] as $k) if (empty($ev[$k])) $e("evidence." . ($ev['id'] ?? '?') . " ohne $k");
        }
        foreach ($c['suspects'] as $s) {
            $reg('suspect', $s['id'] ?? null, 'suspect');
            foreach (['name', 'age', 'job', 'relation', 'bio'] as $k) if (!isset($s[$k]) || $s[$k] === '') $e("suspect." . ($s['id'] ?? '?') . " ohne $k");
            foreach ($s['dialogue'] ?? [] as $o) $reg('asked', $o['id'] ?? null, 'Dialog von ' . ($s['id'] ?? '?'));
        }
        foreach ($c['actions'] as $a) $reg('action', $a['id'] ?? null, 'action');
        foreach ($c['timeline'] as $t) {
            $reg('timeline', $t['id'] ?? null, 'timeline');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t['time'] ?? '')) $e('Zeitformat HH:MM in timeline.' . ($t['id'] ?? '?'));
        }

        // ---- Verweise
        $flagGranted = [];
        $checkGrants = function (?array $g, string $ctx) use (&$ids, $e, &$flagGranted) {
            foreach ($g['evidence'] ?? [] as $x) if (!isset($ids['evidence'][$x])) $e("$ctx gewährt unbekannten Beweis $x");
            foreach ($g['flags'] ?? [] as $f) $flagGranted[$f] = true;
        };
        foreach ($c['scenes'] as $s) {
            foreach ($s['hotspots'] ?? [] as $h) $checkGrants($h['grants'] ?? null, 'hotspot ' . $h['id']);
        }
        foreach ($c['suspects'] as $s) foreach ($s['dialogue'] ?? [] as $o) {
            $checkGrants($o['grants'] ?? null, 'dialog ' . $o['id']);
            foreach (['question', 'answer'] as $k) if (empty($o[$k])) $e("dialog {$o['id']} ohne $k");
        }
        foreach ($c['actions'] as $a) $checkGrants($a['grants'] ?? null, 'action ' . $a['id']);
        $checkGrants($c['start'] ?? null, 'start');
        $ph = $c['phone'] ?? null;
        if ($ph) {
            if (!preg_match('/^\d{4,6}$/', (string) ($ph['lock']['pin'] ?? ''))) $e('phone.lock.pin muss 4–6 Ziffern sein');
            if (!isset($ids['evidence'][$ph['lock']['source'] ?? ''])) $e('phone.lock.source (PIN-Quelle) ist kein Beweis');
            foreach (array_merge($ph['chats'] ?? [], $ph['calls'] ?? []) as $it) {
                $reg('phoneitem', $it['id'] ?? null, 'Handy-Element');
                $checkGrants($it['grants'] ?? null, 'Handy ' . ($it['id'] ?? '?'));
                if (!Condition::isWellFormed($it['requires'] ?? null)) $e('Bedingung fehlerhaft in Handy ' . $it['id']);
            }
            foreach ($ph['calls'] ?? [] as $cl) if (empty($cl['transcript'])) $e('Anruf ohne Transkript: ' . $cl['id']);
        }

        $conds = [];
        foreach ($c['scenes'] as $s) { $conds[] = [$s['requires'] ?? null, 'scene ' . $s['id']]; foreach ($s['hotspots'] ?? [] as $h) $conds[] = [$h['requires'] ?? null, 'hotspot ' . $h['id']]; }
        foreach ($c['suspects'] as $s) foreach ($s['dialogue'] ?? [] as $o) $conds[] = [$o['requires'] ?? null, 'dialog ' . $o['id']];
        foreach ($c['actions'] as $a) $conds[] = [$a['requires'] ?? null, 'action ' . $a['id']];
        foreach ($c['timeline'] as $t) $conds[] = [$t['requires'] ?? null, 'timeline ' . $t['id']];
        foreach ($c['hints'] as $h) $conds[] = [$h['unless'] ?? null, 'hint ' . ($h['id'] ?? '?')];
        foreach ($conds as [$cond, $ctx]) {
            if (!Condition::isWellFormed($cond)) { $e("Bedingung fehlerhaft in $ctx"); continue; }
            foreach (Condition::refs($cond) as [$k, $ref]) {
                if ($k === 'flag') { if (!isset($flagGranted[$ref])) $e("Flag '$ref' (in $ctx) wird nirgends gesetzt"); }
                elseif (!isset($ids[$k === 'asked' ? 'asked' : $k][$ref])) $e("Unbekannter Verweis $k:$ref in $ctx");
            }
        }

        // ---- Erreichbarkeit (ohne Hinweise!)
        $p = new Progress();
        foreach ($c['start']['evidence'] ?? [] as $x) $p->add('evidence', $x);
        foreach ($c['start']['flags'] ?? [] as $x) $p->add('flag', $x);
        $apply = function (?array $g) use ($p) { foreach ($g['evidence'] ?? [] as $x) $p->add('evidence', $x); foreach ($g['flags'] ?? [] as $x) $p->add('flag', $x); };
        do {
            $before = array_sum(array_map('count', $p->sets));
            foreach ($c['scenes'] as $s) {
                if (!Condition::test($s['requires'] ?? null, $p)) continue;
                foreach ($s['hotspots'] ?? [] as $h) if (Condition::test($h['requires'] ?? null, $p)) { $p->add('hotspot', $h['id']); $apply($h['grants'] ?? null); }
            }
            foreach ($c['suspects'] as $s) foreach ($s['dialogue'] ?? [] as $o) if (Condition::test($o['requires'] ?? null, $p)) { $p->add('asked', $o['id']); $apply($o['grants'] ?? null); }
            foreach ($c['actions'] as $a) if (Condition::test($a['requires'] ?? null, $p)) { $p->add('action', $a['id']); $apply($a['grants'] ?? null); }
            if ($ph && Condition::test($ph['lock']['requires'] ?? null, $p) && $p->has('evidence', $ph['lock']['source'] ?? '')) {
                $p->add('flag', 'phone_unlocked');
                foreach (array_merge($ph['chats'] ?? [], $ph['calls'] ?? []) as $it) if (Condition::test($it['requires'] ?? null, $p)) { $p->add('action', 'phone:' . $it['id']); $apply($it['grants'] ?? null); }
            }
        } while (array_sum(array_map('count', $p->sets)) > $before);

        foreach ($c['scenes'] as $s) {
            if (!Condition::test($s['requires'] ?? null, $p)) $e("Szene unerreichbar: {$s['id']}");
            foreach ($s['hotspots'] ?? [] as $h) if (!$p->has('hotspot', $h['id'])) $e("Hotspot unerreichbar: {$h['id']}");
        }
        foreach ($ids['evidence'] as $id => $_) if (!$p->has('evidence', $id)) $e("Beweis unerreichbar: $id");
        foreach ($ids['asked'] as $id => $_) if (!$p->has('asked', $id)) $e("Dialogoption unerreichbar (toter Zweig): $id");
        foreach ($ids['action'] as $id => $_) if (!$p->has('action', $id)) $e("Ermittlungsaktion unerreichbar: $id");
        foreach ($c['timeline'] as $t) if (!Condition::test($t['requires'] ?? null, $p)) $e("Zeitachsen-Ereignis unerreichbar: {$t['id']}");
        foreach ($c['suspects'] as $s) {
            $start = new Progress();
            foreach ($c['start']['evidence'] ?? [] as $x) $start->add('evidence', $x);
            $open = array_filter($s['dialogue'] ?? [], fn($o) => Condition::test($o['requires'] ?? null, $start));
            if (!$open) $e("Sackgasse: {$s['name']} hat keine anfänglich verfügbare Frage");
        }

        // ---- Lösung
        $sol = $c['solution'];
        $suspectIds = array_map(fn($s) => $s['id'], $c['suspects']);
        if (!in_array($sol['culprit'] ?? '', $suspectIds, true)) $e('solution.culprit ist kein Verdächtiger');
        $motiveIds = array_column($c['accusation']['motives'] ?? [], 'id');
        $methodIds = array_column($c['accusation']['methods'] ?? [], 'id');
        if (!in_array($sol['motive'] ?? '', $motiveIds, true)) $e('solution.motive nicht in accusation.motives');
        if (!in_array($sol['method'] ?? '', $methodIds, true)) $e('solution.method nicht in accusation.methods');
        if (count($motiveIds) < 3 || count($methodIds) < 3) $w('Mind. 3 Motiv- und Methodenoptionen empfohlen');
        $key = $sol['key_evidence'] ?? [];
        foreach ($key as $k) if (!isset($ids['evidence'][$k])) $e("key_evidence unbekannt: $k"); elseif (!$p->has('evidence', $k)) $e("Pflichtbeweis nicht erreichbar: $k");
        if (empty($sol['min_key']) || $sol['min_key'] > count($key)) $e('solution.min_key ungültig');
        $last = -1;
        foreach ($sol['timeline'] ?? [] as $tid) {
            $t = null; foreach ($c['timeline'] as $x) if ($x['id'] === $tid) $t = $x;
            if (!$t) { $e("solution.timeline unbekannt: $tid"); continue; }
            $sort = ($t['day'] ?? 0) * 1440 + (int) substr($t['time'], 0, 2) * 60 + (int) substr($t['time'], 3, 2);
            if ($sort < $last) $e("solution.timeline nicht chronologisch bei $tid");
            $last = $sort;
        }
        foreach (['summary', 'reconstruction', 'key_clues', 'false_leads'] as $k) if (empty($sol['resolution'][$k])) $e("solution.resolution.$k fehlt");
        // Eindeutigkeit: kein Gegenverdächtiger darf ebenfalls als Täter definiert sein
        if (isset($sol['culprits']) && count($sol['culprits']) !== 1) $e('Lösung nicht eindeutig');

        // ---- Hinweise & Spoiler
        $levels = [];
        $culprit = null; foreach ($c['suspects'] as $s) if ($s['id'] === ($sol['culprit'] ?? '')) $culprit = $s;
        foreach ($c['hints'] as $h) {
            $lv = $h['level'] ?? 0; $levels[$lv] = true;
            if ($lv < 1 || $lv > 4) $e('Hinweisstufe 1–4 erwartet');
            if ($culprit) {
                $parts = array_merge([$culprit['name']], explode(' ', $culprit['name']));
                foreach ($parts as $part) if (mb_strlen($part) > 3 && mb_stripos($h['text'] ?? '', $part) !== false && $lv <= 3) $e("Hinweis {$h['id']} nennt den Täter beim Namen");
            }
        }
        foreach ([1, 2, 3, 4] as $lv) if (!isset($levels[$lv])) $e("Hinweisstufe $lv fehlt");
        if ($culprit) foreach (['briefing'] as $k) if (!empty($c[$k]) && mb_stripos($c[$k], $culprit['name']) !== false) $w('Briefing nennt den Namen des Täters – Spoilerrisiko prüfen');

        // ---- Medien (nur Warnung: Platzhalter sind erlaubt)
        $mediaRoot ??= CC_ROOT . '/public/assets/media';
        $missing = 0;
        $check = function (?string $f) use ($mediaRoot, &$missing) { if ($f && !is_file($mediaRoot . '/' . ltrim($f, '/'))) $missing++; };
        foreach ($c['scenes'] as $s) $check($s['image'] ?? null);
        foreach ($c['suspects'] as $s) $check($s['portrait'] ?? null);
        foreach ($c['evidence'] as $ev) $check($ev['media'] ?? null);
        if ($missing) $w("$missing Mediendatei(en) fehlen – es werden gekennzeichnete Platzhalter angezeigt");

        // ---- Qualitätsziele
        $nDlg = array_sum(array_map(fn($s) => count($s['dialogue'] ?? []), $c['suspects']));
        if (count($c['suspects']) < 5) $w('Weniger als 5 Personen');
        if (count($c['evidence']) < 15) $w('Weniger als 15 Beweise');
        if ($nDlg < 20) $w('Weniger als 20 Dialogoptionen');
        if (count($c['scenes']) < 2) $w('Weniger als 2 Tatorte');

        return self::report($err, $warn, ['scenes' => count($c['scenes']), 'evidence' => count($c['evidence']), 'suspects' => count($c['suspects']), 'dialogue_options' => $nDlg, 'actions' => count($c['actions']), 'timeline' => count($c['timeline'])]);
    }

    private static function report(array $err, array $warn, array $stats): array
    {
        return ['ok' => !$err, 'errors' => $err, 'warnings' => $warn, 'stats' => $stats];
    }
}
