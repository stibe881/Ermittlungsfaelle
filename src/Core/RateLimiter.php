<?php
declare(strict_types=1);
namespace ColdCase\Core;

final class RateLimiter
{
    /** Feste Zeitfenster; wirft 429 bei Überschreitung. */
    public static function hit(string $key, int $max, int $windowSec): void
    {
        $w = intdiv(time(), $windowSec) * $windowSec;
        $key = substr($key, 0, 120);
        Db::exec('INSERT INTO rate_limits (rkey,window_start,hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$key, $w]);
        $hits = (int) Db::val('SELECT hits FROM rate_limits WHERE rkey=? AND window_start=?', [$key, $w]);
        if ($hits > $max) throw new HttpError(429, 'rate_limited', 'Zu viele Anfragen. Bitte später erneut versuchen.');
        if (random_int(1, 200) === 1) Db::exec('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
    }
}
