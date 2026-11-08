<?php

require_once __DIR__ . "/auth.php";

// Старые версии auth.php содержат только login() и logout().
if (!function_exists("getCurrentUser")) {
    function getCurrentUser(): ?array
    {
        if (empty($_SESSION["user_id"])) {
            return null;
        }

        $pdo = connectDB();
        $statement = $pdo->prepare(
            <<<'SQL'
            SELECT a.id, a.login, LOWER(BTRIM(r.name)) AS role,
                   COALESCE(t.fio, s.fio, sec.name, a.login) AS name
            FROM public.auth a
            JOIN public.roles r ON r.id = a.role_id
            LEFT JOIN public.teachers t ON t.auth_id = a.id
            LEFT JOIN public.students s ON s.auth_id = a.id
            LEFT JOIN public.secretaries sec ON sec.auth_id = a.id
            WHERE a.id = :id
            SQL
            ,
        );
        $statement->execute(["id" => $_SESSION["user_id"]]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        $user["role"] = match ($user["role"]) {
            "преподаватель", "учитель" => "teacher",
            "студент" => "student",
            "секретарь" => "secretary",
            "заведующий кафедрой", "заведующий отделением" => "head",
            default => $user["role"],
        };

        return $user;
    }
}

function cabinetAsset(string $file): string
{
    $path = __DIR__ . "/../frontend/" . $file;
    return $file . "?v=" . substr(hash_file("sha256", $path), 0, 12);
}

function cabinetResetLink(): string
{
    $tab = is_string($_GET["tab"] ?? null) ? $_GET["tab"] : "";
    $url =
        basename($_SERVER["SCRIPT_NAME"]) .
        ($tab !== "" ? "?" . http_build_query(["tab" => $tab]) : "");
    return '<a class="cab-button filter-reset" href="' .
        htmlspecialchars($url, ENT_QUOTES, "UTF-8") .
        '">Сбросить фильтры</a>';
}

function cabinetTeacherTabs(): array
{
    return [
        "home" => [
            "Главная",
            '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        ],
        "schedule" => [
            "Расписание",
            '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        ],
        "journal" => [
            "Журнал оценок",
            '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
        ],
        "attestation" => [
            "Аттестация",
            '<path d="M9 5H5v16h14V5h-4M9 3h6v4H9zM9 14l2 2 4-4"/>',
        ],
        "finals" => [
            "Итоговые оценки",
            '<rect x="4" y="4" width="16" height="16"/><path d="M8 10h8M8 14h8"/>',
        ],
    ];
}

function cabinetTeacherNav(string $active, array $filters = []): void
{
    echo '<nav aria-label="Кабинет преподавателя">';
    foreach (cabinetTeacherTabs() as $key => [$title, $icon]) {
        $params =
            ["tab" => $key] +
            (in_array($key, ["journal", "attestation"], true) ? $filters : []);
        $href = htmlspecialchars(
            "teacher.php?" . http_build_query($params),
            ENT_QUOTES,
            "UTF-8",
        );
        $title = htmlspecialchars($title, ENT_QUOTES, "UTF-8");
        echo '<a href="' .
            $href .
            '" aria-label="' .
            $title .
            '" title="' .
            $title .
            '"' .
            ($active === $key ? ' aria-current="page"' : "") .
            '><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' .
            $icon .
            "</svg><span>" .
            $title .
            "</span></a>";
    }
    echo "</nav>";
}
