<?php
declare(strict_types=1);
namespace ColdCase\Auth;

use ColdCase\Core\{Config, Db, HttpError, Mailer, RateLimiter, Security};

final class AuthService
{
    public const RANKS = [
        [0, 'Polizeianwärter'], [1, 'Ermittler'], [3, 'Kriminalkommissar'], [5, 'Hauptkommissar'],
        [8, 'Spezialermittler'], [12, 'Elite-Ermittler'], [20, 'Legende der Mordkommission'],
    ];

    public static function rankFor(int $solved): string
    {
        $name = self::RANKS[0][1];
        foreach (self::RANKS as [$min, $n]) if ($solved >= $min) $name = $n;
        return $name;
    }

    private static function hash(string $pw): string
    {
        return password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    public static function register(string $email, string $username, string $password, bool $consent, string $ip): array
    {
        RateLimiter::hit("reg:$ip", 10, 3600);
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new HttpError(422, 'invalid_email', 'Ungültige E-Mail-Adresse.');
        if (!preg_match('/^[A-Za-z0-9_\-]{3,24}$/', $username)) throw new HttpError(422, 'invalid_username', 'Benutzername: 3–24 Zeichen (Buchstaben, Zahlen, _ -).');
        if (strlen($password) < 10 || strlen($password) > 200) throw new HttpError(422, 'weak_password', 'Das Passwort braucht mindestens 10 Zeichen.');
        if (!$consent) throw new HttpError(422, 'consent_required', 'Bitte stimme der Datenschutzerklärung zu.');
        if (Db::val('SELECT 1 FROM users WHERE email=? OR username=?', [$email, $username])) throw new HttpError(409, 'exists', 'E-Mail oder Benutzername ist bereits vergeben.');
        $verified = Config::get('require_email_verification') ? null : Db::now();
        $id = Db::insert('INSERT INTO users (email,username,password_hash,email_verified_at,consent_at,created_at) VALUES (?,?,?,?,?,?)',
            [$email, $username, self::hash($password), $verified, Db::now(), Db::now()]);
        if (!$verified) self::sendToken($id, $email, 'verify');
        Security::audit($id, 'register', "user:$id", null, $ip);
        return ['id' => $id];
    }

    private static function sendToken(int $uid, string $email, string $kind): void
    {
        $raw = bin2hex(random_bytes(24));
        Db::exec('INSERT INTO user_tokens (user_id,kind,token_hash,expires_at) VALUES (?,?,?,?)',
            [$uid, $kind, hash('sha256', $raw), gmdate('Y-m-d H:i:s', time() + ($kind === 'verify' ? 86400 * 3 : 3600))]);
        $base = rtrim((string) Config::get('base_url'), '/');
        $link = "$base/#/" . ($kind === 'verify' ? 'verify' : 'reset') . "/$raw";
        Mailer::send($email, $kind === 'verify' ? 'Bestätige deine E-Mail-Adresse' : 'Passwort zurücksetzen',
            ($kind === 'verify' ? "Willkommen bei COLD CASE.\nBestätigungslink:\n" : "Link zum Zurücksetzen deines Passworts (60 Min. gültig):\n") . $link . "\n");
    }

    public static function login(string $login, string $password, string $ip): array
    {
        RateLimiter::hit("login:ip:$ip", 20, 900);
        RateLimiter::hit('login:id:' . mb_strtolower($login), 8, 900);
        $u = Db::one('SELECT * FROM users WHERE (email=? OR username=?) AND deleted_at IS NULL', [mb_strtolower($login), $login]);
        $ok = $u && password_verify($password, $u['password_hash']);
        if (!$ok) {
            if (!$u) password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ0123');
            throw new HttpError(401, 'bad_credentials', 'Anmeldedaten ungültig.');
        }
        if (Config::get('require_email_verification') && !$u['email_verified_at']) throw new HttpError(403, 'unverified', 'Bitte bestätige zuerst deine E-Mail-Adresse.');
        if (password_needs_rehash($u['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Db::exec('UPDATE users SET password_hash=? WHERE id=?', [self::hash($password), $u['id']]);
        }
        Db::exec('UPDATE users SET last_login_at=? WHERE id=?', [Db::now(), $u['id']]);
        Security::login((int) $u['id']);
        return $u;
    }

    public static function verifyEmail(string $token): void
    {
        $t = self::consumeToken($token, 'verify');
        Db::exec('UPDATE users SET email_verified_at=? WHERE id=?', [Db::now(), $t['user_id']]);
    }

    public static function forgot(string $email, string $ip): void
    {
        RateLimiter::hit("forgot:$ip", 5, 3600);
        $u = Db::one('SELECT id,email FROM users WHERE email=? AND deleted_at IS NULL', [mb_strtolower(trim($email))]);
        if ($u) self::sendToken((int) $u['id'], $u['email'], 'reset');
    }

    public static function reset(string $token, string $password): void
    {
        if (strlen($password) < 10) throw new HttpError(422, 'weak_password', 'Das Passwort braucht mindestens 10 Zeichen.');
        $t = self::consumeToken($token, 'reset');
        Db::exec('UPDATE users SET password_hash=? WHERE id=?', [self::hash($password), $t['user_id']]);
    }

    private static function consumeToken(string $raw, string $kind): array
    {
        $t = Db::one('SELECT * FROM user_tokens WHERE token_hash=? AND kind=? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $raw), $kind, Db::now()]);
        if (!$t) throw new HttpError(400, 'bad_token', 'Der Link ist ungültig oder abgelaufen.');
        Db::exec('UPDATE user_tokens SET used_at=? WHERE id=?', [Db::now(), $t['id']]);
        return $t;
    }

    public static function currentUser(): ?array
    {
        $uid = $_SESSION['uid'] ?? null;
        if (!$uid) return null;
        return Db::one('SELECT id,email,username,role,avatar,email_verified_at,created_at FROM users WHERE id=? AND deleted_at IS NULL', [$uid]);
    }

    public static function profile(array $u): array
    {
        $solved = (int) Db::val("SELECT COUNT(DISTINCT case_id) FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id WHERE m.user_id=? AND gs.status='solved'", [$u['id']]);
        $running = (int) Db::val("SELECT COUNT(*) FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id WHERE m.user_id=? AND gs.status IN ('active','paused')", [$u['id']]);
        $secs = (int) Db::val('SELECT COALESCE(SUM(gs.active_seconds),0) FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id WHERE m.user_id=?', [$u['id']]);
        $ach = Db::all('SELECT a.code,a.title,a.description,ua.earned_at FROM user_achievements ua JOIN achievements a ON a.code=ua.code WHERE ua.user_id=? ORDER BY ua.earned_at', [$u['id']]);
        return [
            'id' => (int) $u['id'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'avatar' => $u['avatar'],
            'rank' => self::rankFor($solved), 'solved' => $solved, 'running' => $running, 'play_seconds' => $secs, 'achievements' => $ach,
        ];
    }

    public static function setAvatar(int $uid, string $avatar): void
    {
        if (!preg_match('/^a[1-8]$/', $avatar)) throw new HttpError(422, 'invalid_avatar');
        Db::exec('UPDATE users SET avatar=? WHERE id=?', [$avatar, $uid]);
    }

    public static function exportData(int $uid): array
    {
        $u = Db::one('SELECT id,email,username,avatar,created_at,last_login_at,consent_at FROM users WHERE id=?', [$uid]);
        return [
            'user' => $u,
            'sessions' => Db::all('SELECT gs.id,gs.case_id,gs.mode,gs.status,gs.active_seconds,gs.hints_used,gs.false_accusations,gs.started_at,gs.completed_at FROM game_sessions gs JOIN session_members m ON m.session_id=gs.id WHERE m.user_id=?', [$uid]),
            'notes' => Db::all('SELECT session_id,body,created_at FROM session_notes WHERE user_id=? AND deleted=0', [$uid]),
            'achievements' => Db::all('SELECT code,earned_at FROM user_achievements WHERE user_id=?', [$uid]),
            'leaderboard' => Db::all('SELECT case_id,mode,score_seconds,completed_at FROM leaderboards WHERE user_id=?', [$uid]),
        ];
    }

    /** Löscht Konto, persönliche Daten, Spielstände, Ranglisteneinträge (Kaskade). */
    public static function deleteAccount(int $uid, string $password, string $ip): void
    {
        $u = Db::one('SELECT password_hash FROM users WHERE id=?', [$uid]);
        if (!$u || !password_verify($password, $u['password_hash'])) throw new HttpError(403, 'bad_password', 'Passwort falsch.');
        Db::tx(function () use ($uid) {
            Db::exec('DELETE FROM users WHERE id=?', [$uid]);
        });
        Security::audit(null, 'account_deleted', "user:$uid", null, $ip);
    }
}
