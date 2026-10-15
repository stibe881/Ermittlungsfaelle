<?php
declare(strict_types=1);
namespace ColdCase\Core;

final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('ccsid');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '2592000');
        @session_start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }

    public static function csrfToken(): string { return $_SESSION['csrf'] ?? ''; }

    public static function checkCsrf(Request $r): void
    {
        if (in_array($r->method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
        $t = $r->header('x-csrf-token');
        if (!$t || !hash_equals(self::csrfToken(), $t)) throw new HttpError(403, 'csrf');
    }

    public static function login(int $uid): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }

    public static function sendHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; media-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }

    public static function ipHash(string $ip): string { return substr(hash('sha256', $ip . '|cc-salt'), 0, 16); }

    public static function audit(?int $uid, string $action, ?string $target = null, ?string $detail = null, string $ip = ''): void
    {
        Db::exec('INSERT INTO audit_logs (user_id,action,target,ip_hash,detail,created_at) VALUES (?,?,?,?,?,?)',
            [$uid, $action, $target, $ip ? self::ipHash($ip) : null, $detail, Db::now()]);
    }
}
