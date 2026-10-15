<?php
// Kopieren nach config/config.php und anpassen. Diese Datei darf NIE im Webroot liegen.
return [
    'env'      => 'production',            // 'production' | 'development'
    'base_url' => 'https://example.ch',
    'db' => [
        'host' => 'localhost',
        'name' => 'coldcase',
        'user' => 'coldcase',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'driver' => 'mail',                // 'mail' (PHP mail()) | 'log' (nur storage/logs/mail.log)
        'from'   => 'no-reply@example.ch',
        'from_name' => 'Cold Case – Investigation',
    ],
    'game' => [
        'idle_cap_seconds'   => 90,        // max. gezählte Zeit zwischen zwei Aktivitätssignalen
        'wrong_accusation_penalty_seconds' => 600,
        'hint_penalty_seconds' => 300,
        'max_false_accusations' => 3,
    ],
    'require_email_verification' => false,
];
