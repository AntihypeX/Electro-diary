<?php
require_once __DIR__ . "/current-user.php";

function cabQuery(PDO $db, string $sql, array $args = []): PDOStatement
{
    $q = $db->prepare($sql);
    $q->execute($args);
    return $q;
}
function ch(mixed $s): string
{
    return htmlspecialchars(
        is_scalar($s) ? (string) $s : "",
        ENT_QUOTES | ENT_SUBSTITUTE,
        "UTF-8",
    );
}
function cabNav(string $role): array
{
    return match ($role) {
        "student" => [
            "home" => "Моя успеваемость",
            "schedule" => "Расписание",
            "monthly" => "Аттестация за месяцы",
            "finals" => "Итоговые оценки",
            "group" => "Моя группа",
        ],
        "teacher" => [
            "home" => "Главная",
            "schedule" => "Расписание",
            "journal" => "Журнал оценок",
            "attestation" => "Аттестация",
            "finals" => "Итоговые оценки",
        ],
        "secretary" => [
            "students" => "Студенты",
            "enroll" => "Зачисление",
            "groups" => "Группы",
        ],
        "head" => [
            "students" => "Студенты",
            "groups" => "Группы",
            "journal" => "Оценки",
            "analytics" => "Средние баллы",
            "exams" => "Экзамены",
        ],
        default => [],
    };
}
function cabSelect(
    string $name,
    string $label,
    array $options,
    mixed $value,
): void {
    echo "<label>" . ch($label) . '<select name="' . ch($name) . '">';
    foreach ($options as $id => $text) {
        echo '<option value="' .
            ch($id) .
            '"' .
            ((string) $id === (string) $value ? " selected" : "") .
            ">" .
            ch($text) .
            "</option>";
    }
    echo "</select></label>";
}
function cabScope(PDO $db, array $user): ?array
{
    $table = $user["role"] === "student" ? "students" : "teachers";
    $rows = cabQuery(
        $db,
        "SELECT * FROM public.$table WHERE auth_id = ? ORDER BY id LIMIT 2",
        [$_SESSION["user_id"] ?? $user["id"]],
    )->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}
function cabShell(string $role, array $user, string $tab, callable $body): void
{
    preg_match("/^./u", $user["name"] ?? $user["login"], $avatarInitial);
    $nav = cabNav($role);
    $today = new DateTimeImmutable(
        "today",
        new DateTimeZone("Asia/Novosibirsk"),
    );
    $year =
        filter_var($_GET["year"] ?? null, FILTER_VALIDATE_INT, [
            "options" => ["min_range" => 1900, "max_range" => 9998],
        ]) ?:
        (int) $today->format("Y") - ((int) $today->format("n") < 9 ? 1 : 0);
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= ch($nav[$tab] ?? "Кабинет") ?></title><link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset("index.css"), ENT_QUOTES, "UTF-8") ?>"><?php if ($role === "teacher"): ?><link rel="stylesheet" href="<?= ch(cabinetAsset("teacher.css")) ?>"><?php endif; ?><link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset("cabinet.css"), ENT_QUOTES, "UTF-8") ?>"><script src="<?= htmlspecialchars(cabinetAsset("cabinet.js"), ENT_QUOTES, "UTF-8") ?>" defer></script><script src="<?= htmlspecialchars(cabinetAsset("grade-picker.js"), ENT_QUOTES, "UTF-8") ?>" defer></script></head><body class="cabinet<?= $role === "teacher" ? " teacher" : "" ?>"><div class="app-shell"><aside class="sidebar"><div class="brand"><span class="brand-mark"><i></i><i></i><i></i></span><b>EDU=SPT</b></div><div class="user-card"><span class="avatar"><?= ch($avatarInitial[0] ?? "Я") ?></span><div><b><?= ch($user["name"] ?? $user["login"]) ?></b><small><?= ch(["student" => "Студент", "teacher" => "Преподаватель", "secretary" => "Секретарь", "head" => "Заведующий отделением"][$role]) ?></small></div></div><?php if (
    $role === "teacher"
) {
    cabinetTeacherNav(
        $tab,
        array_intersect_key(
            $_GET,
            array_flip([
                "year",
                "semester",
                "month",
                "group",
                "subject",
                "student",
            ]),
        ),
    );
} else {
     ?><nav><?php foreach (
    $nav
    as $key => $label
): ?><a href="<?= $role ?>.php?tab=<?= $key ?>" title="<?= ch(
    $label,
) ?>" <?= $tab === $key
    ? 'aria-current="page"'
    : "" ?>><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 14h8"/></svg><span><?= ch(
    $label,
) ?></span></a><?php endforeach; ?></nav><?php
} ?><form action="account.php" method="post" class="cab-logout"><input type="hidden" name="csrf" value="<?= ch($_SESSION["csrf_token"]) ?>"><button class="logout" name="action" value="logout" title="Выйти" aria-label="Выйти"><?php if ($role === "teacher"): ?><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg><?php endif; ?><span>Выйти</span></button></form></aside><main class="content"><header class="topbar"><div><span class="breadcrumb">ЛИЧНЫЙ КАБИНЕТ<?= $role === "teacher" ? " / ПРЕПОДАВАТЕЛЬ" : "" ?></span><h1><?= ch($nav[$tab] ?? "Кабинет") ?></h1></div><?php if ($role === "teacher"): ?><div class="date-block"><span>УЧЕБНЫЙ ГОД</span><b><?= $year ?>–<?= $year + 1 ?></b></div><?php endif; ?></header><div class="page-stack"><?php $body(); ?></div></main></div></body></html><?php
}
function cabSchedule(PDO $db, string $role, array $profile): void
{
    $today = new DateTimeImmutable(
        "today",
        new DateTimeZone("Asia/Novosibirsk"),
    );
    $raw = $_GET["day"] ?? "";
    $day =
        is_string($raw) && preg_match("/\A[1-9][0-9]{3}-\d{2}-\d{2}\z/", $raw)
            ? DateTimeImmutable::createFromFormat("!Y-m-d", $raw)
            : false;
    if (!$day || $day->format("Y-m-d") !== $raw) {
        $day = $today;
    }
    $rows = cabQuery(
        $db,
        "SELECT l.*,s.name AS subject_name,t.fio,g.name AS group_name,tp.name AS topic_name FROM public.lessons l JOIN public.subjects s ON s.id=l.subject JOIN public.teachers t ON t.id=l.teacher JOIN public.stud_groups g ON g.id=l.in_group JOIN public.lesson_topics tp ON tp.id=l.topic WHERE l." .
            ($role === "student" ? "in_group" : "teacher") .
            "=? AND l.date=? ORDER BY l.num_in_table,l.id",
        [
            $role === "student" ? $profile["in_group"] : $profile["id"],
            $day->format("Y-m-d"),
        ],
    )->fetchAll(PDO::FETCH_ASSOC);
    ?><section class="panel"><header class="panel-head"><h3><?= ch($day->format("d.m.Y")) ?></h3><div class="cab-day-nav"><a class="cab-button" href="?tab=schedule&day=<?= $day->modify("-1 day")->format("Y-m-d") ?>" aria-label="Предыдущий день">←</a><details><summary class="cab-button">Текущая</summary><form method="get"><input type="hidden" name="tab" value="schedule"><label>Выберите дату<input type="date" name="day" value="<?= $day->format("Y-m-d") ?>" required></label><button class="primary">Открыть</button><a href="?tab=schedule">Сегодня</a></form></details><a class="cab-button" href="?tab=schedule&day=<?= $day->modify("+1 day")->format("Y-m-d") ?>" aria-label="Следующий день">→</a></div></header><?php
if (!$rows): ?><p class="cab-empty">На этот день занятий нет.</p><?php endif;
foreach ($rows as $row): ?><article class="cab-pair"><strong><?= ch(
    $row["num_in_table"],
) ?> пара</strong><div><h3><?= ch($row["subject_name"]) ?></h3><p><?= ch(
    $row["topic_name"],
) ?></p><small><?= ch(
    $row["fio"] .
        " · " .
        $row["group_name"] .
        " · " .
        ($row["lesson_type"] ?? ""),
) ?></small></div></article><?php endforeach;?></section><?php
}

/** A row per discipline and assessment, grouped by academic year / semester. */
function cabResultState(array $entry): array
{
    return [
        "key" => $entry["_edit"],
        "details" => [
            "hours" => $entry["hours"] ?? null,
            "assessed_on" => $entry["assessed_on"] ?? null,
            "statement_number" => $entry["statement_number"] ?? null,
        ],
        "student" => $entry["student_name"],
        "subject" => $entry["subject"],
        "kind" => [
            "exam" => "Экзамен",
            "diff" => "Диф. зачёт",
            "credit" => "Зачёт",
        ][$entry["kind"]],
        "grade" => $entry["grade"] ?? null,
        "date" => !empty($entry["assessed_on"])
            ? (new DateTimeImmutable($entry["assessed_on"]))->format("d.m.Y")
            : "—",
    ];
}

function cabRecordBook(array $records, bool $editable = false): void
{
    $kinds = ["exam" => "Экзамен", "diff" => "Диф. зачёт", "credit" => "Зачёт"];
    if ($editable) {
        $students = [];
        foreach ($records as $entry) {
            $key = implode(":", [
                $entry["_edit"]["student"],
                $entry["_edit"]["group"],
                $entry["_edit"]["subject"],
                $entry["period_start"],
            ]);
            $students[$key][$entry["kind"]] = $entry;
        }
        $records = [];
        foreach ($students as $variants) {
            $entry = $variants["exam"] ?? reset($variants);
            $entry["_variants"] = array_map("cabResultState", $variants);
            $records[] = $entry;
        }
    }
    $years = [];
    foreach ($records as $entry) {
        $month = (int) substr($entry["period_start"], 5, 2);
        $year =
            (int) substr($entry["period_start"], 0, 4) - ($month < 9 ? 1 : 0);
        $years[$year][$month >= 9 ? 1 : 2][] = $entry;
    }
    ksort($years);
    echo '<section class="panel record-book"><header class="panel-head"><h3>Зачётная книжка</h3><span class="count">Итоговые оценки</span></header>';
    if (!$years) {
        echo '<p class="cab-empty">По выбранным фильтрам результатов нет.</p></section>';
        return;
    }
    if ($editable) {
        echo '<p class="cab-note">Выберите форму контроля в строке студента. Нажмите на отметку, чтобы выставить, изменить или удалить её. Оценки и сведения каждого вида сохраняются отдельно.</p>';
    }
    foreach ($years as $year => $semesters) {
        echo '<div class="record-year"><h3>' .
            $year .
            "–" .
            ($year + 1) .
            " учебный год</h3>";
        ksort($semesters);
        foreach ($semesters as $semester => $entries) {
            echo '<section class="record-semester"><h4>' .
                $semester .
                '-й семестр</h4><div class="cab-scroll"><table class="cab-table record-table"><thead><tr><th scope="col">№</th>';
            if ($editable) {
                echo '<th scope="col">Студент</th>';
            }
            echo '<th scope="col">Дисциплина</th><th scope="col">Кол-во часов</th><th scope="col">Ф. И. О. преподавателя</th><th scope="col">Оценка</th><th scope="col">Ведомость</th><th scope="col">Дата</th><th scope="col">Форма контроля</th>';
            if ($editable) {
                echo '<th scope="col">Сведения</th>';
            }
            echo "</tr></thead><tbody>";
            foreach ($entries as $index => $entry) {
                $state = $editable ? cabResultState($entry) : null;
                echo "<tr" .
                    ($editable
                        ? ' data-control-records="' .
                            ch(
                                json_encode(
                                    $entry["_variants"],
                                    JSON_UNESCAPED_UNICODE,
                                ),
                            ) .
                            '"'
                        : "") .
                    "><td>" .
                    ($index + 1) .
                    "</td>";
                if ($editable) {
                    echo '<td class="record-person">' .
                        ch($entry["student_name"]) .
                        "</td>";
                }
                echo '<td class="record-subject">' .
                    ch($entry["subject"]) .
                    '</td><td data-record-field="hours">' .
                    ch($entry["hours"] ?? "—") .
                    '</td><td class="record-person">' .
                    ch($entry["fio"]) .
                    "</td><td>";
                if ($editable) {
                    echo '<button type="button" class="cab-button record-mark" data-final="' .
                        ch(
                            json_encode(
                                $entry["_edit"],
                                JSON_UNESCAPED_UNICODE,
                            ),
                        ) .
                        '" aria-label="' .
                        ch(
                            "Отметка: " .
                                $entry["student_name"] .
                                ", " .
                                $kinds[$entry["kind"]],
                        ) .
                        '">' .
                        ch($entry["grade"] ?? "+") .
                        "</button>";
                } else {
                    echo '<span class="grade record-mark">' .
                        ch($entry["grade"]) .
                        "</span>";
                }
                $date = !empty($entry["assessed_on"])
                    ? (new DateTimeImmutable($entry["assessed_on"]))->format(
                        "d.m.Y",
                    )
                    : "—";
                echo '</td><td data-record-field="statement_number">' .
                    ch($entry["statement_number"] ?? "—") .
                    '</td><td class="record-date" data-record-field="date">' .
                    ch($date) .
                    "</td><td>";
                if ($editable) {
                    echo '<select class="record-control" data-control-kind aria-label="' .
                        ch("Форма контроля: " . $entry["student_name"]) .
                        '">';
                    foreach ($kinds as $kind => $label) {
                        echo '<option value="' .
                            $kind .
                            '"' .
                            ($kind === $entry["kind"] ? " selected" : "") .
                            ">" .
                            ch($label) .
                            "</option>";
                    }
                    echo "</select>";
                } else {
                    echo ch($kinds[$entry["kind"]]);
                }
                echo "</td>";
                if ($editable) {
                    echo '<td><button type="button" class="cab-button" data-result-details="' .
                        ch(json_encode($state, JSON_UNESCAPED_UNICODE)) .
                        '"' .
                        (isset($entry["grade"])
                            ? ""
                            : ' disabled title="Сначала выставьте отметку"') .
                        ">Заполнить</button></td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table></div></section>";
        }
        echo "</div>";
    }
    echo "</section>";
}

function cabStudentResults(PDO $db, array $profile, string $tab): void
{
    $year =
        filter_var($_GET["year"] ?? 0, FILTER_VALIDATE_INT, [
            "options" => ["min_range" => 1900, "max_range" => 9998],
        ]) ?:
        0;
    $semester = in_array($_GET["semester"] ?? "", ["1", "2"], true)
        ? (int) $_GET["semester"]
        : 0;
    $subject = filter_var($_GET["subject"] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    $kinds = ["exam" => "Экзамен", "diff" => "Дифзачёт", "credit" => "Зачёт"];
    $kind =
        is_string($_GET["kind"] ?? null) && isset($kinds[$_GET["kind"]])
            ? $_GET["kind"]
            : "";
    $scope =
        $tab === "monthly"
            ? "a.kind='month'"
            : "a.kind IN ('exam','diff','credit')";
    $all = cabQuery(
        $db,
        "SELECT a.*,s.name AS subject,t.fio FROM public.teacher_attestations a JOIN public.subjects s ON s.id=a.subject_id JOIN public.teachers t ON t.id=a.teacher_id WHERE a.student_id=? AND $scope ORDER BY a.period_start,a.kind,s.name,a.teacher_id",
        [$profile["id"]],
    )->fetchAll(PDO::FETCH_ASSOC);
    $years = [];
    $subjects = [];
    foreach ($all as $entry) {
        $y =
            (int) substr($entry["period_start"], 0, 4) -
            ((int) substr($entry["period_start"], 5, 2) < 9 ? 1 : 0);
        $years[$y] = $y . "–" . ($y + 1);
        $subjects[$entry["subject_id"]] = $entry["subject"];
    }
    krsort($years);
    asort($subjects);
    if (!isset($years[$year])) {
        $year = 0;
    }
    if (!isset($subjects[$subject])) {
        $subject = 0;
    }
    ?><form class="cab-filters results-filters"><input type="hidden" name="tab" value="<?= ch($tab) ?>"><?php
cabSelect("year", "Учебный год", [0 => "Все годы"] + $years, $year);
cabSelect(
    "semester",
    "Семестр",
    [0 => "Все семестры", 1 => "Первый", 2 => "Второй"],
    $semester,
);
cabSelect("subject", "Предмет", [0 => "Все предметы"] + $subjects, $subject);
if ($tab === "finals") {
    cabSelect("kind", "Вид результата", ["" => "Все виды"] + $kinds, $kind);
}
cabinetResetLink();
?></form><?php
$columns = [];
$entries = [];
$shown = [];
$filtered = [];
foreach ($all as $entry) {
    $month = (int) substr($entry["period_start"], 5, 2);
    $entryYear =
        (int) substr($entry["period_start"], 0, 4) - ($month < 9 ? 1 : 0);
    $entrySemester = $month >= 9 ? 1 : 2;
    if (
        ($year && $year !== $entryYear) ||
        ($semester && $semester !== $entrySemester) ||
        ($subject && $subject !== (int) $entry["subject_id"]) ||
        ($kind && $tab === "finals" && $kind !== $entry["kind"])
    ) {
        continue;
    }
    $filtered[] = $entry;
    $key = $entry["period_start"] . "_" . $entry["kind"];
    $columns[$key] =
        $tab === "monthly"
            ? substr($entry["period_start"], 0, 7)
            : $kinds[$entry["kind"]] .
                " · " .
                $entrySemester .
                " семестр · " .
                $entryYear .
                "–" .
                ($entryYear + 1);
    $shown[$entry["subject_id"]] = $entry["subject"];
    $entries[$entry["subject_id"]][$key][] = $entry;
}
asort($shown);
if ($tab === "finals") {
    cabRecordBook($filtered);
    return;
}
?><section class="panel table-panel"><header class="panel-head"><h3><?= $tab === "monthly" ? "Аттестация за месяцы" : "Итоговые оценки" ?></h3><span class="count"><?= count($shown) ?> дисциплин</span></header><?php if (
     !$columns
 ): ?><p class="cab-empty">По выбранным фильтрам результатов нет.</p><?php else: ?><div class="matrix-container"><table class="matrix-table result-matrix"><thead><tr><th scope="col">Дисциплина</th><?php foreach (
    $columns
    as $label
): ?><th scope="col"><?= ch(
    $label,
) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach (
    $shown
    as $id => $name
): ?><tr><th scope="row"><?= ch($name) ?></th><?php foreach (
    $columns
    as $key => $label
): ?><td><div class="result-marks"><?php
foreach (
    $entries[$id][$key] ?? []
    as $entry
): ?><span class="grade" title="<?= ch($entry["fio"]) ?>"><?= ch(
    $entry["grade"],
) ?></span><?php endforeach;
if (empty($entries[$id][$key])): ?><span class="muted">—</span><?php endif;
?></div></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php
}
