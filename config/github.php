<?php

declare(strict_types=1);

return [
    'organisation' => env(key: 'GITHUB_ORGANISATION'),
    'app_id' => env(key: 'GITHUB_APP_ID'),
    'installation_id' => env(key: 'GITHUB_INSTALLATION_ID'),
    'private_key_path' => env(key: 'GITHUB_PRIVATE_KEY_PATH'),
    'user_agent' => 'egough-laravel-github',
    'api_version' => '2026-03-10',
    'cache' => [
        'repositories' => 300,
        'pull_requests' => 60,
    ],
];
