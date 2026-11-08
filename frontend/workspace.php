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
$profile = in_array($role, ["teacher", "student"], true)
    ? cabScope($db, $user)
    : null;
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
        header("Location: " . $role . ".php?" . http_build_query($_GET));
        exit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $message =
            $e instanceof PDOException
                ? "Проверьте выбранный корпус и руководителя."
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
    if ($tab === "schedule") {
        cabSchedule($db, $role, $profile);
        return;
    }
    if (in_array($tab, ["group", "groups"], true)) {
        $groupId =
            $role === "student"
                ? (int) $profile["in_group"]
                : (filter_var($_GET["group"] ?? 0, FILTER_VALIDATE_INT) ?:
                0);
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
            // Студенту доступны только ФИО одногруппников.
            $rows = cabQuery(
                $db,
                "SELECT s.id,s.fio AS name,s.birth_date,s.phone,s.email,
          AVG(CASE WHEN e.grade IN ('2','3','4','5') THEN e.grade::int END) AS average,
          COUNT(*) FILTER(WHERE e.grade IN ('н','Н')) AS absences
          FROM public.students s LEFT JOIN public.evaluations e ON e.student_id=s.id
          WHERE s.in_group=? GROUP BY s.id,s.fio ORDER BY " .
                    ($role === "head" ? $order : "name ASC") .
                    ",s.id",
                [$groupId],
            )->fetchAll(PDO::FETCH_ASSOC);
            echo '<div class="cab-scroll"><table class="cab-table"><thead><tr><th>Студент</th>';
            if ($role === "student") {
                echo "<th>Дата рождения</th><th>Телефон</th><th>Электронная почта</th>";
            }

            if ($role === "head") {
                echo "<th>Средний балл</th><th>Пропуски</th>";
            }
            echo "</tr></thead><tbody>";
            foreach ($rows as $row) {
                echo "<tr><td>" . ch($row["name"]) . "</td>";
                if ($role === "student") {
                    echo "<td>" .
                        ch(
                            $row["birth_date"]
                                ? (new DateTimeImmutable(
                                    $row["birth_date"],
                                ))->format("d.m.Y")
                                : "—",
                        ) .
                        "</td><td>" .
                        ch($row["phone"] ?? "—") .
                        "</td><td>" .
                        ch($row["email"] ?? "—") .
                        "</td>";
                }

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
        if ($role === "secretary") {

            $campuses = cabQuery(
                $db,
                "SELECT id,name FROM public.campuses ORDER BY name",
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $teachers = cabQuery(
                $db,
                "SELECT id,fio FROM public.teachers ORDER BY fio",
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
        return;
    }
    if ($role === "student" && in_array($tab, ["monthly", "finals"], true)) {
        cabStudentResults($db, $profile, $tab);
        return;
    }
    if (in_array($tab, ["monthly", "finals"], true)) {

        $today = new DateTimeImmutable(
            "now",
            new DateTimeZone("Asia/Novosibirsk"),
        );
        $year =
            filter_var($_GET["year"] ?? "", FILTER_VALIDATE_INT, [
                "options" => ["min_range" => 1900, "max_range" => 9998],
            ]) ?:
            (int) $today->format("Y") - ((int) $today->format("n") < 9 ? 1 : 0);
        $semester = ($_GET["semester"] ?? "1") === "2" ? 2 : 1;
        $start = sprintf(
            "%04d-%02d-01",
            $year + ($semester === 2 ? 1 : 0),
            $semester === 1 ? 9 : 1,
        );
        $end = $semester === 1 ? $year + 1 . "-01-01" : $year + 1 . "-07-01";
        $group = 0;
        $subject = 0;
        $student = 0;
        ?><form class="cab-filters"><input type="hidden" name="tab" value="<?= ch(
    $tab,
) ?>"><label>Начало учебного года<input type="number" name="year" min="1900" max="9998" value="<?= $year ?>" required></label><?php
cabSelect("semester", "Семестр", [1 => "Первый", 2 => "Второй"], $semester);
if ($role === "teacher") {
    $assignments = cabQuery(
        $db,
        "SELECT DISTINCT g.id,g.name,s.id AS subject_id,s.name AS subject FROM public.lessons l JOIN public.stud_groups g ON g.id=l.in_group JOIN public.subjects s ON s.id=l.subject WHERE l.teacher=? AND l.date>=? AND l.date<? ORDER BY g.name,s.name",
        [$profile["id"], $year . "-09-01", $year + 1 . "-07-01"],
    )->fetchAll(PDO::FETCH_ASSOC);
    $groups = array_column($assignments, "name", "id");
    $group = (int) ($_GET["group"] ?? 0);
    if (!isset($groups[$group])) {
        $group = (int) array_key_first($groups);
    }
    $subjects = [];
    foreach ($assignments as $a) {
        if ((int) $a["id"] === $group) {
            $subjects[$a["subject_id"]] = $a["subject"];
        }
    }
    $subject = (int) ($_GET["subject"] ?? 0);
    if (!isset($subjects[$subject])) {
        $subject = (int) array_key_first($subjects);
    }
    cabSelect("group", "Группа", $groups, $group);
    cabSelect("subject", "Предмет", $subjects, $subject);
    $roster = cabQuery(
        $db,
        "SELECT id,fio FROM public.students WHERE in_group=? ORDER BY fio",
        [$group],
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $student = (int) ($_GET["student"] ?? 0);
    if (!isset($roster[$student])) {
        $student = 0;
    }
    cabSelect("student", "Студент", [0 => "Все студенты"] + $roster, $student);
}
?><button class="primary" hidden>Показать</button><?= cabinetResetLink() ?></form><?php
$sql =
    "SELECT a.*,s.name AS subject,t.fio FROM public.teacher_attestations a JOIN public.subjects s ON s.id=a.subject_id JOIN public.teachers t ON t.id=a.teacher_id WHERE a.period_start>=? AND a.period_start<?";
$args = [$start, $end];
if ($role === "student") {
    $sql .= " AND a.student_id=?";
    $args[] = $profile["id"];
} else {
    $sql .= " AND a.teacher_id=? AND a.group_id=? AND a.subject_id=?";
    array_push($args, $profile["id"], $group, $subject);
}
$sql .=
    $tab === "monthly"
        ? " AND a.kind='month'"
        : " AND a.kind IN ('exam','diff','credit')";
$rows = cabQuery(
    $db,
    $sql . " ORDER BY a.period_start,a.subject_id,a.student_id",
    $args,
)->fetchAll(PDO::FETCH_ASSOC);
$kinds = ["exam" => "Экзамен", "diff" => "Дифзачёт", "credit" => "Зачёт"];
$marks = [];
foreach ($rows as $r) {
    $marks[$r["student_id"]][$r["kind"]] = $r;
}
$records = [];
foreach ($roster as $id => $fio) {
    if ($student && $student !== (int) $id) {
        continue;
    }
    foreach ($kinds as $kind => $label) {
        $entry = $marks[$id][$kind] ?? [];
        $data = [
            "kind" => $kind,
            "period" => $start,
            "group" => $group,
            "subject" => $subject,
            "student" => $id,
            "previous" => $entry["grade"] ?? null,
        ];
        $records[] = $entry + [
            "subject" => $subjects[$subject] ?? "",
            "fio" => $profile["fio"],
            "student_name" => $fio,
            "period_start" => $start,
            "kind" => $kind,
            "_edit" => $data,
        ];
    }
}
cabRecordBook($records, true);

    }
});
