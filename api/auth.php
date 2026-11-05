<?php

require_once __DIR__ . '/database.php';

session_start();

function login($login, $pass) {
    $pdo = connectDB();

    $stmt = $pdo->prepare(
        "SELECT * FROM auth WHERE login = :login"
    );

    $stmt->execute([
        'login' => $login
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($pass, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        return true;
    }

    return false;
}

function logout() {
    $_SESSION = [];
    session_destroy();
}