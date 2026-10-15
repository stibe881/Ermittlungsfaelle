<?php
declare(strict_types=1);
namespace ColdCase\Game;

use ColdCase\Core\Db;

final class Achievements
{
    /** Vergibt Erfolge an alle Mitglieder; gibt neu vergebene Codes für den Aufrufer-Kontext zurück. */
    public static function award(array $session, array $content, int $score): array
    {
        $sid = (int) $session['id'];
        $codes = ['first_case'];
        if ((int) $session['hints_used'] === 0) $codes[] = 'no_hints';
        if ($score >= 100) $codes[] = 'perfect_chain';
        if (($content['meta']['difficulty'] ?? 0) >= 4) $codes[] = 'hard_case';
        if ($session['mode'] === 'team') $codes[] = 'team_case';
        $suspectIds = array_column($content['suspects'], 'id');
        $askedOptions = array_column(Db::all("SELECT ref FROM session_progress WHERE session_id=? AND kind='asked'", [$sid]), 'ref');
        $allAsked = true;
        foreach ($content['suspects'] as $su) {
            $mine = array_column($su['dialogue'] ?? [], 'id');
            if (!array_intersect($mine, $askedOptions)) { $allAsked = false; break; }
        }
        if ($allAsked && $suspectIds) $codes[] = 'all_suspects';
        $new = [];
        foreach (Db::all('SELECT user_id FROM session_members WHERE session_id=?', [$sid]) as $m) {
            $uid = (int) $m['user_id'];
            $solved = (int) Db::val("SELECT COUNT(DISTINCT gs.case_id) FROM game_sessions gs JOIN session_members sm ON sm.session_id=gs.id WHERE sm.user_id=? AND gs.status='solved'", [$uid]);
            $mine = $codes;
            if ($solved >= 3) $mine[] = 'three_cases';
            foreach ($mine as $code) {
                $n = Db::exec('INSERT IGNORE INTO user_achievements (user_id,code,session_id,earned_at) VALUES (?,?,?,?)', [$uid, $code, $sid, Db::now()]);
                if ($n) $new[$uid][] = $code;
            }
        }
        return $new;
    }
}
