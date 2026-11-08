<?php

require_once __DIR__ . '/auth.php';

// Старые версии auth.php содержат только login() и logout().
if (!function_exists('getCurrentUser')) {
    function getCurrentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $pdo = connectDB();
        $statement = $pdo->prepare(<<<'SQL'
            SELECT a.id, a.login, LOWER(BTRIM(r.name)) AS role,
                   COALESCE(t.fio, s.fio, sec.name, a.login) AS name
            FROM public.auth a
            JOIN public.roles r ON r.id = a.role_id
            LEFT JOIN public.teachers t ON t.auth_id = a.id
            LEFT JOIN public.students s ON s.auth_id = a.id
            LEFT JOIN public.secretaries sec ON sec.auth_id = a.id
            WHERE a.id = :id
            SQL);
        $statement->execute(['id' => $_SESSION['user_id']]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        $user['role'] = match ($user['role']) {
            'преподаватель', 'учитель' => 'teacher',
            'студент' => 'student',
            'секретарь' => 'secretary',
            'заведующий кафедрой', 'заведующий отделением' => 'head',
            default => $user['role'],
        };

        return $user;
    }
}

function cabinetAsset(string $file): string
{
    $path = __DIR__ . '/../frontend/' . $file;
    return $file . '?v=' . substr(hash_file('sha256', $path), 0, 12);
}

function cabinetResetLink(): string
{
    $tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
    $url = basename($_SERVER['SCRIPT_NAME']) . ($tab !== '' ? '?' . http_build_query(['tab' => $tab]) : '');
    return '<a class="cab-button filter-reset" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Сбросить фильтры</a>';
}
