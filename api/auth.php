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

    if (!$user || !password_verify($pass, $user['password'])) {
        return false;
    }

    $role = $user['role'] ?? 'student';
    $name = $user['full_name'] ?? $user['login'];

    if (array_key_exists('role_id', $user)) {
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT LOWER(BTRIM(r.name)) AS role_name,
                   s.fio AS student_name,
                   t.fio AS teacher_name,
                   sc.name AS secretary_name
            FROM public.auth a
            JOIN public.roles r ON r.id = a.role_id
            LEFT JOIN public.students s ON s.auth_id = a.id
            LEFT JOIN public.teachers t ON t.auth_id = a.id
            LEFT JOIN public.secretaries sc ON sc.auth_id = a.id
            WHERE a.id = :id
            SQL);
        $stmt->execute(['id' => $user['id']]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        $role = match ($profile['role_name'] ?? null) {
            'student', 'студент' => 'student',
            'teacher', 'преподаватель' => 'teacher',
            'head', 'заведующий отделением', 'заведующий кафедрой' => 'head',
            'secretary', 'секретарь' => 'secretary',
            default => null,
        };
        if ($role === null) {
            return false;
        }

        $name = match ($role) {
            'student' => $profile['student_name'] ?? $user['login'],
            'teacher', 'head' => $profile['teacher_name'] ?? $user['login'],
            'secretary' => $profile['secretary_name'] ?? $user['login'],
        };
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $role;
    $_SESSION['user_name'] = $name;
    return true;
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
