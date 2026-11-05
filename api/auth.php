<?php

require_once __DIR__ . '/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function login(string $login, string $pass): bool {
    $pdo = connectDB();

    $stmt = $pdo->prepare("SELECT * FROM auth WHERE login = :login");
    $stmt->execute(['login' => $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($pass, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role']      ?? 'student';
        $_SESSION['user_name'] = $user['full_name'] ?? $user['login'];
        return true;
    }

    return false;
}

function logout(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    session_destroy();
}

/**
 * Возвращает ['id', 'role', 'name'] либо null.
 */
function getCurrentUser(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'   => (int) $_SESSION['user_id'],
        'role' => $_SESSION['user_role'] ?? 'student',
        'name' => $_SESSION['user_name'] ?? 'Пользователь',
    ];
}