<?php
return [
    'env' => 'development', 'base_url' => 'http://localhost',
    'db' => ['host' => '127.0.0.1', 'name' => 'coldcase_test', 'user' => 'cc', 'pass' => 'cc', 'charset' => 'utf8mb4'],
    'mail' => ['driver' => 'log', 'from' => 'x@localhost', 'from_name' => 'T'],
    'game' => ['idle_cap_seconds' => 90, 'wrong_accusation_penalty_seconds' => 600, 'hint_penalty_seconds' => 300, 'max_false_accusations' => 3],
    'require_email_verification' => false,
];
