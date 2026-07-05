<?php

return [
    'app' => [
        'name' => 'hotelpos',
        'base_path' => '',
        'session_idle_minutes' => 30,
        'csrf_key' => 'csrf_token',
        'allow_negative_stock' => false,
        'public_url' => 'http://localhost/hotelpos/public',
        'mail_from' => 'no-reply@hotelpos.local',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'hotelpos',
        'user' => 'hotelpos_user',
        'password' => 'change-me',
        'charset' => 'utf8mb4',
    ],
];

