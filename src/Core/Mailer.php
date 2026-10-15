<?php
declare(strict_types=1);
namespace ColdCase\Core;

final class Mailer
{
    public static function send(string $to, string $subject, string $body): void
    {
        $driver = Config::get('mail.driver', 'log');
        if ($driver === 'mail') {
            $from = Config::get('mail.from');
            $name = Config::get('mail.from_name', 'Cold Case');
            $headers = "From: =?UTF-8?B?" . base64_encode($name) . "?= <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
            @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
            return;
        }
        @file_put_contents(CC_ROOT . '/storage/logs/mail.log', sprintf("[%s] To:%s Subject:%s\n%s\n---\n", gmdate('c'), $to, $subject, $body), FILE_APPEND);
    }
}
