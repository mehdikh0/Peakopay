<?php
/**
 * Peakopay — configuration template.
 *
 * You normally NEVER edit this file: the web installer (/install) creates
 * config/config.php automatically. This sample documents the format.
 */
return [
    // 32-byte application key (64 hex chars) — encrypts cards, secrets, SMS texts.
    'app_key'  => '',

    // Public base URL of the backend, no trailing slash.
    'base_url' => 'https://api.example.com',

    // Never enable on production.
    'debug'    => false,

    'db'       => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'cpaneluser_peakopay',
        'user'    => 'cpaneluser_hp',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
];
