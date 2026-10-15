<?php
// Usage: php bin/create-admin.php email username   (Passwort wird interaktiv oder via CC_ADMIN_PASSWORD gelesen)
require __DIR__ . '/_boot.php';
use ColdCase\Core\Db;
[$_, $email, $user] = array_pad($argv, 3, null);
if (!$email || !$user) { fwrite(STDERR, "Usage: create-admin.php email username\n"); exit(2); }
$pw = getenv('CC_ADMIN_PASSWORD') ?: (function () { echo 'Passwort (min. 12 Zeichen): '; return trim((string) fgets(STDIN)); })();
if (strlen($pw) < 12) { fwrite(STDERR, "Passwort zu kurz\n"); exit(2); }
$hash = password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
Db::exec("INSERT INTO users (email,username,password_hash,role,email_verified_at,consent_at,created_at) VALUES (?,?,?,'admin',?,?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role='admin'",
    [mb_strtolower($email), $user, $hash, Db::now(), Db::now(), Db::now()]);
echo "Admin '$user' angelegt/aktualisiert.\n";
