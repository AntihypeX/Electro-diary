<?php

function connectDB(): PDO {
    $env = parse_ini_file(__DIR__ . '/../.env');
    $db  = parse_url($env['DATABASE_LINK']);

    return new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/'),
        $db['user'],
        $db['pass'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}