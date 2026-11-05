<?php

$env = parse_ini_file(__DIR__ . '/../.env');
$db = parse_url($env['DATABASE_LINK']);

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/'),
        $db['user'],
        $db['pass'] ?? ''
    );

    echo "Подключение успешно!";
} catch (PDOException $e) {
    echo "Ошибка: " . $e->getMessage();
}