<?php
require_once __DIR__ . "/../api/cabinet.php";
if (!isset($workspaceRole)) {
    http_response_code(404);
    exit();
}
$user = getCurrentUser();
if (!$user || ($user["role"] ?? "") !== $workspaceRole) {
    header("Location: index.php");
    exit();
}
header("Cache-Control: no-store");
$_SESSION["csrf_token"] ??= bin2hex(random_bytes(32));
$role = $workspaceRole;
$tab = $_GET["tab"];
$db = connectDB();
$message = "";
$redirect = $_GET;
$profile = in_array($role, ["teacher", "student"], true)
    ? cabScope($db, $user)
    : null;
if ($role === 'teacher' && $tab === 'finals' && $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['download'] ?? null) === 'csv') {
    if (!$profile) {
        http_response_code(403);
        exit('К аккаунту не привязан профиль преподавателя.');
    }
    teacherFinals($db, $profile, true);
    exit;
}
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (
            !is_string($_POST["csrf"] ?? null) ||
            !hash_equals($_SESSION["csrf_token"], $_POST["csrf"])
        ) {
            throw new RuntimeException("Обновите страницу.");
        }
        $db->beginTransaction();
        if ($role === "secretary" && $tab === "groups") {
            $name = trim((string) ($_POST["name"] ?? ""));
            if (!preg_match("/\A.{1,10}\z/u", $name)) {
                throw new RuntimeException(
                    "Название группы: от 1 до 10 символов.",
                );
            }
            $campus =
                filter_var($_POST["campus"] ?? "", FILTER_VALIDATE_INT) ?: null;
            $teacher = filter_var($_POST["teacher"] ?? "", FILTER_VALIDATE_INT);
            if (!$teacher) {
                throw new RuntimeException("Выберите классного руководителя.");
            }
            cabQuery(
                $db,
                "LOCK TABLE public.stud_groups IN SHARE ROW EXCLUSIVE MODE",
            );
            if (
                !cabQuery(
                    $db,
                    "SELECT id FROM public.stud_groups WHERE LOWER(name)=LOWER(?)",
                    [$name],
                )->fetchColumn()
            ) {
                cabQuery(
                    $db,
                    "INSERT INTO public.stud_groups(name,class_teacher,campus_id) VALUES (?,?,?)",
                    [$name, $teacher, $campus],
                );
            }
        } elseif ($role === "teacher" && $tab === "finals" && $profile) {
            require_once __DIR__ . "/../api/teacher.php";
            $payload = json_decode($_POST["payload"] ?? "", true);
            if (!is_array($payload)) {
                throw new RuntimeException("Некорректная отметка.");
            }
            if (
                !in_array(
                    $payload["action"] ?? "",
                    ["attestation", "attestation_delete", "result_details"],
                    true,
                )
            ) {
                throw new RuntimeException("Неизвестное действие.");
            }
            if (
                !in_array(
                    $payload["kind"] ?? "",
                    ["exam", "diff", "credit"],
                    true,
                )
            ) {
                throw new RuntimeException("Выберите вид итоговой отметки.");
            }
            teacherSave($db, (int) $profile["id"], $payload);
        } elseif ($role === 'secretary' && $tab === 'statements') {
            $redirect = secretarySaveStatement($db, $_POST);
        } elseif ($role === 'secretary' && $tab === 'schedule') {
            require_once __DIR__ . '/../api/teacher.php';
            $day = secretaryAddLesson($db, $_POST);
            $redirect = ['tab'=>'schedule', 'group'=>(int) $_POST['group'], 'day'=>$day->format('Y-m-d')];
        } else {
            throw new RuntimeException("Действие недоступно.");
        }
        $db->commit();
        $_SESSION["workspace_notice"] = "Изменения сохранены.";
        if (str_contains($_SERVER["HTTP_ACCEPT"] ?? "", "application/json")) {
            header("Content-Type: application/json");
            echo json_encode(["ok" => true]);
            exit();
        }
        header("Location: " . $role . ".php?" . http_build_query($redirect));
        exit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message =
            $e instanceof PDOException
                ? "Не удалось сохранить данные. Проверьте, что установлена миграция из архива."
                : $e->getMessage();
        if (str_contains($_SERVER["HTTP_ACCEPT"] ?? "", "application/json")) {
            http_response_code(400);
            header("Content-Type: application/json");
            echo json_encode(["error" => $message], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }
}
$message = $message ?: $_SESSION["workspace_notice"] ?? "";
unset($_SESSION["workspace_notice"]);
cabShell($role, $user, $tab, function () use (
    $db,
    $role,
    $tab,
    $profile,
    $message,
) {
    if ($message) {
        echo '<p class="cab-note" role="status">' . ch($message) . "</p>";
    }
    if (in_array($role, ["student", "teacher"], true) && !$profile) {
        echo '<p class="panel cab-empty">К аккаунту не привязан единственный профиль.</p>';
        return;
    }
    if ($tab === 'statements' && $role === 'secretary') {
        secretaryStatements($db);
        return;
    }
    if ($tab === 'schedule' && $role === 'secretary') {
        require_once __DIR__ . '/../api/teacher.php';
        secretarySchedule($db);
        return;
    }
    if ($tab === 'group' && $role === 'teacher') {
        cabTeacherGroup($db, (int) $profile['id']);
        return;
    }
    if ($tab === "schedule") {
        cabSchedule($db, $role, $profile);
        return;
    }
    if ($tab === 'groups') {
        $groupId = filter_var($_GET['group'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        if ($role === "secretary") {

            $campuses = cabQuery(
                $db,
                "SELECT id,name FROM public.campuses ORDER BY name",
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $teachers = cabQuery(
                $db,
                "SELECT id,fio FROM public.teachers ORDER BY public.diary_name_sort(fio) COLLATE \"C\", fio, id",
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            ?><section class="panel"><header class="panel-head"><h3>Создать группу</h3></header><form method="post" class="cab-filters"><input type="hidden" name="csrf" value="<?= ch(
    $_SESSION["csrf_token"],
) ?>"><label>Название<input name="name" maxlength="10" required></label><?php
cabSelect("campus", "Корпус", ["" => "Не указан"] + $campuses, "");
cabSelect(
    "teacher",
    "Классный руководитель",
    ["" => "Выберите"] + $teachers,
    "",
);
?><button class="primary">Создать группу</button></form></section><?php
        }
        $sorts = [
            "az" => "От А до Я",
            "za" => "От Я до А",
            "best" => "Средний балл: по убыванию",
            "worst" => "Средний балл: по возрастанию",
            "few" => "Пропуски: по возрастанию",
            "many" => "Пропуски: по убыванию",
        ];
        $sort =
            is_string($_GET["sort"] ?? null) && isset($sorts[$_GET["sort"]])
                ? $_GET["sort"]
                : "az";
        $order = match ($sort) {
            "za" => "name DESC",
            "best" => "average DESC NULLS LAST",
            "worst" => "average ASC NULLS LAST",
            "few" => "absences ASC",
            "many" => "absences DESC",
            default => "name ASC",
        };
        if (
            $role === "head"
        ) { ?><form class="cab-filters"><input type="hidden" name="tab" value="groups"><input type="hidden" name="group" value="<?= ch(
    $groupId,
) ?>"><?php cabSelect(
    "sort",
    "Сортировка",
    $sorts,
    $sort,
); ?><button class="primary" hidden>Показать</button><?= cabinetResetLink() ?></form><?php }
        if ($groupId) {
            $group = cabQuery(
                $db,
                "SELECT g.*,c.name AS campus FROM public.stud_groups g LEFT JOIN public.campuses c ON c.id=g.campus_id WHERE g.id=?",
                [$groupId],
            )->fetch(PDO::FETCH_ASSOC);
            if (!$group) {
                echo '<p class="cab-empty">Группа не найдена.</p>';
                return;
            }
            echo '<section class="panel"><header class="panel-head"><h3>' .
                ch($group["name"]) .
                " · " .
                ch($group["campus"] ?? "Корпус не указан") .
                "</h3></header>";
            $rows = cabQuery(
                $db,
                "SELECT s.id,s.fio AS name,
          AVG(CASE WHEN e.grade IN ('2','3','4','5') THEN e.grade::int END) AS average,
          COUNT(*) FILTER(WHERE e.grade IN ('н','Н')) AS absences
          FROM public.students s LEFT JOIN public.evaluations e ON e.student_id=s.id
          WHERE s.in_group=? GROUP BY s.id,s.fio ORDER BY " .
                    (in_array($sort, ['az','za'], true) || $role !== 'head'
                        ? 'public.diary_name_sort(s.fio) COLLATE "C" ' . ($role === 'head' && $sort === 'za' ? 'DESC' : 'ASC')
                        : $order) .
                    ",s.id",
                [$groupId],
            )->fetchAll(PDO::FETCH_ASSOC);
            echo '<div class="cab-scroll"><table class="cab-table"><thead><tr><th>Студент</th>';
            if ($role === "head") {
                echo "<th>Средний балл</th><th>Пропуски</th>";
            }
            echo "</tr></thead><tbody>";
            foreach ($rows as $row) {
                echo "<tr><td>" . ch($row["name"]) . "</td>";
                if ($role === "head") {
                    echo "<td>" .
                        ($row["average"] === null
                            ? "—"
                            : number_format(
                                (float) $row["average"],
                                2,
                                ",",
                                "",
                            )) .
                        "</td><td>" .
                        ch($row["absences"]) .
                        "</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table></div></section>";
        }
        if ($role !== "student") {
            $rows = cabQuery(
                $db,
                "WITH stats AS (
          SELECT s.in_group, AVG(CASE WHEN e.grade IN ('2','3','4','5') THEN e.grade::int END) AS average,
          COUNT(*) FILTER(WHERE e.grade IN ('н','Н')) AS absences
          FROM public.students s LEFT JOIN public.evaluations e ON e.student_id=s.id GROUP BY s.in_group)
          SELECT g.id,g.name,c.name AS campus,stats.average,COALESCE(stats.absences,0) AS absences,
          (SELECT COUNT(*) FROM public.students s WHERE s.in_group=g.id) AS students
          FROM public.stud_groups g LEFT JOIN public.campuses c ON c.id=g.campus_id LEFT JOIN stats ON stats.in_group=g.id
          ORDER BY $order,g.id",
            )->fetchAll(PDO::FETCH_ASSOC);
            echo '<section class="panel"><div class="cab-scroll"><table class="cab-table"><thead><tr><th>Группа</th><th>Корпус</th><th>Студентов</th>';
            if ($role === "head") {
                echo "<th>Средний балл</th><th>Пропуски</th>";
            }
            echo "<th></th></tr></thead><tbody>";
            foreach ($rows as $row) {
                echo "<tr><td>" .
                    ch($row["name"]) .
                    "</td><td>" .
                    ch($row["campus"] ?? "Не указан") .
                    "</td><td>" .
                    ch($row["students"]) .
                    "</td>";
                if ($role === "head") {
                    echo "<td>" .
                        ($row["average"] === null
                            ? "—"
                            : number_format(
                                (float) $row["average"],
                                2,
                                ",",
                                "",
                            )) .
                        "</td><td>" .
                        ch($row["absences"]) .
                        "</td>";
                }
                echo '<td><a class="cab-button" href="?tab=groups&group=' .
                    ch($row["id"]) .
                    "&sort=" .
                    ch($sort) .
                    '">Подробнее</a></td></tr>';
            }
            echo "</tbody></table></div></section>";
        }
        return;
    }
    if ($role === "student" && in_array($tab, ["monthly", "finals"], true)) {
        cabStudentResults($db, $profile, $tab);
        return;
    }
    if ($role === 'teacher' && $tab === 'finals') {
        teacherFinals($db, $profile);
    }
});
