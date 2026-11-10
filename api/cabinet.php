<?php
require_once __DIR__ . "/current-user.php";
require_once __DIR__ . "/schedule.php";
require_once __DIR__ . "/statements.php";

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
function cabGradeAttributes(string $classes, ?string $previous = null): string
{
    if ($previous === null || $previous === '') {
        return 'class="' . ch($classes) . '"';
    }
    return 'class="' . ch($classes . ' grade-changed') . '" title="' . ch('Было: ' . $previous) . '"';
}

function cabTabs(string $role): array
{
    $book = '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>';
    $calendar = '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>';
    $people = '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>';
    $check = '<path d="M9 5H5v16h14V5h-4M9 3h6v4H9zM9 14l2 2 4-4"/>';
    return match ($role) {
        'student' => [
            'home' => ['Моя успеваемость', $book],
            'schedule' => ['Расписание', $calendar],
            'monthly' => ['Аттестация за месяцы', $check],
            'finals' => ['Итоговые оценки', $book],
        ],
        'teacher' => cabinetTeacherTabs() + ['group' => ['Группа', $people]],
        'secretary' => [
            'students' => ['Студенты', $people],
            'enroll' => ['Зачисление', '<path d="M12 4v16M4 12h16"/>'],
            'groups' => ['Группы', $people],
            'schedule' => ['Расписание', $calendar],
            'statements' => ['Ведомости', $check],
        ],
        'head' => [
            'students' => ['Студенты', $people],
            'journal' => ['Оценки', $book],
            'analytics' => ['Средние баллы', '<path d="M3 3v18h18M7 16V9M12 16V5M17 16v-5"/>'],
            'finals' => ['Итоговые оценки', $check],
            'groups' => ['Группы', $people],
        ],
        default => [],
    };
}

function cabSidebar(string $role, array $user, string $active, string $csrf, array $filters = []): void
{
    $roleName = ['student'=>'Студент', 'teacher'=>'Преподаватель', 'secretary'=>'Секретарь', 'head'=>'Заведующий отделением'][$role];
    $name = $user['name'] ?? $user['login'] ?? $roleName;
    preg_match('/^./u', $name, $initial);
    ?>
    <aside class="sidebar cab-sidebar<?= in_array($role, ['head', 'secretary'], true) ? ' cab-sidebar-staff' : '' ?>">
        <div class="brand"><span class="brand-mark"><i></i><i></i><i></i></span><b>EDU=SPT</b></div>
        <div class="user-card"><span class="avatar"><?= ch($initial[0] ?? 'Я') ?></span><div><b title="<?= ch($name) ?>"><?= ch($name) ?></b><small><?= ch($roleName) ?></small></div></div>
        <nav aria-label="<?= ch('Личный кабинет · ' . $roleName) ?>">
            <?php foreach (cabTabs($role) as $key => [$label, $icon]):
                $keepFilters = ($role === 'teacher' && in_array($key, ['journal','attestation'], true))
                    || ($role === 'head' && $key !== 'groups');
                $href = $role . '.php?' . http_build_query(['tab'=>$key] + ($keepFilters ? $filters : []));
            ?>
                <a href="<?= ch($href) ?>" title="<?= ch($label) ?>" aria-label="<?= ch($label) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon ?></svg>
                    <span><?= ch($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <form action="account.php" method="post" class="cab-logout">
            <input type="hidden" name="csrf" value="<?= ch($csrf) ?>">
            <button class="logout" name="action" value="logout" title="Выйти" aria-label="Выйти">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg><span>Выйти</span>
            </button>
        </form>
    </aside>
    <?php
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
    $nav = cabTabs($role);
    $today = new DateTimeImmutable(
        "today",
        new DateTimeZone("Asia/Novosibirsk"),
    );
    $year =
        filter_var($_GET["year"] ?? null, FILTER_VALIDATE_INT, [
            "options" => ["min_range" => 1900, "max_range" => 9998],
        ]) ?:
        (int) $today->format("Y") - ((int) $today->format("n") < 9 ? 1 : 0);
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= ch($nav[$tab][0] ?? "Кабинет") ?></title><link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset("index.css"), ENT_QUOTES, "UTF-8") ?>"><?php if ($role === "teacher"): ?><link rel="stylesheet" href="<?= ch(cabinetAsset("teacher.css")) ?>"><?php endif; ?><link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset("cabinet.css"), ENT_QUOTES, "UTF-8") ?>"><script src="<?= htmlspecialchars(cabinetAsset("cabinet.js"), ENT_QUOTES, "UTF-8") ?>" defer></script><script src="<?= htmlspecialchars(cabinetAsset("grade-picker.js"), ENT_QUOTES, "UTF-8") ?>" defer></script></head><body class="cabinet<?= $role === "teacher" ? " teacher" : "" ?>"><div class="app-shell"><?php cabSidebar($role, $user, $tab, $_SESSION['csrf_token'],
        array_intersect_key($_GET, array_flip(['year','semester','month','group','subject','student']))); ?><main class="content"><header class="topbar"><div><span class="breadcrumb">ЛИЧНЫЙ КАБИНЕТ<?= $role === "teacher" ? " / ПРЕПОДАВАТЕЛЬ" : "" ?></span><h1><?= ch($nav[$tab][0] ?? "Кабинет") ?></h1></div><?php if ($role === "teacher"): ?><div class="date-block"><span>УЧЕБНЫЙ ГОД</span><b><?= $year ?>–<?= $year + 1 ?></b></div><?php endif; ?></header><div class="page-stack"><?php $body(); ?></div></main></div></body></html><?php
}
function cabSchedule(PDO $db, string $role, array $profile): void
{
    $today = new DateTimeImmutable(
        "today",
        new DateTimeZone("Asia/Novosibirsk"),
    );
    $day = scheduleDate($_GET['day'] ?? null) ?? $today;
    $byTeacher = $role === 'teacher';
    $query = ['tab'=>'schedule'] + ($role === 'secretary' ? ['group'=>$profile['in_group']] : []);
    $rows = cabQuery(
        $db,
        "SELECT l.*,s.name AS subject_name,t.fio,g.name AS group_name,tp.name AS topic_name FROM public.lessons l JOIN public.subjects s ON s.id=l.subject JOIN public.teachers t ON t.id=l.teacher JOIN public.stud_groups g ON g.id=l.in_group LEFT JOIN public.lesson_topics tp ON tp.id=l.topic WHERE l." .
            ($byTeacher ? "teacher" : "in_group") .
            "=? AND l.date=? ORDER BY l.num_in_table,l.id",
        [
            $byTeacher ? $profile["id"] : $profile["in_group"],
            $day->format("Y-m-d"),
        ],
    )->fetchAll(PDO::FETCH_ASSOC);
    $slots = scheduleSlots($db);
    $subgroup = $role === 'student'
        ? (int) cabQuery($db, 'SELECT subgroup FROM public.student_education WHERE student_id=?', [$profile['id']])->fetchColumn()
        : 0;
    $lessons = [];
    foreach ($rows as $row) {
        $lessonSubgroup = (int) ($row['subgroup'] ?? 0);
        if ($subgroup > 0 && $lessonSubgroup > 0 && $lessonSubgroup !== $subgroup) {
            continue;
        }
        $number = (int) $row['num_in_table'];
        $lessons[$number][] = $row;
        $slots[$number] ??= 'Время не задано';
    }
    ksort($slots, SORT_NUMERIC);
    ?>
    <section class="panel">
        <header class="panel-head">
            <h3><?= ch($day->format('d.m.Y')) ?></h3>
            <div class="cab-day-nav">
                <a class="cab-button" href="?<?= ch(http_build_query($query + ['day'=>$day->modify('-1 day')->format('Y-m-d')])) ?>" aria-label="Предыдущий день">←</a>
                <a class="cab-button" href="?<?= ch(http_build_query($query)) ?>">Текущая дата</a>
                <details><summary class="cab-button" aria-label="Календарь" title="Календарь">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                </summary>
                    <form method="get">
                        <?php foreach ($query as $key => $value): ?>
                            <input type="hidden" name="<?= ch($key) ?>" value="<?= ch($value) ?>">
                        <?php endforeach; ?>
                        <label>Выберите дату<input type="date" name="day" value="<?= ch($day->format('Y-m-d')) ?>" required></label>
                        <button class="primary">Открыть</button>
                    </form>
                </details>
                <a class="cab-button" href="?<?= ch(http_build_query($query + ['day'=>$day->modify('+1 day')->format('Y-m-d')])) ?>" aria-label="Следующий день">→</a>
            </div>
        </header>
        <?php foreach ($slots as $number => $hours): ?>
            <article class="cab-pair">
                <div class="cab-slot"><strong><?= ch($number) ?> пара</strong><small><?= ch($hours) ?></small></div>
                <div>
                    <?php if (empty($lessons[$number])): ?>
                        <p class="muted">Пар нет</p>
                    <?php else: ?>
                        <?php foreach ($lessons[$number] as $row): ?>
                            <div class="cab-slot-lesson"><h3><?= ch($row['subject_name']) ?></h3>
                                <p><?= ch($row['topic_name'] ?? 'Тема не указана') ?></p>
                                <small><?= ch(implode(' · ', array_filter([
                                    $row['fio'], $row['group_name'], $row['lesson_type'],
                                    empty($row['room']) ? null : 'Ауд. ' . $row['room'],
                                    empty($row['subgroup']) ? null : 'Подгруппа ' . $row['subgroup'],
                                ]))) ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <?php
}

function cabTeacherGroup(PDO $db, int $teacher): void
{
    $groups = cabQuery($db, 'SELECT g.id,g.name,c.name AS campus FROM public.stud_groups g
        LEFT JOIN public.campuses c ON c.id=g.campus_id
        WHERE g.class_teacher=? ORDER BY g.name,g.id', [$teacher])->fetchAll(PDO::FETCH_ASSOC);
    if (!$groups) {
        echo '<p class="panel cab-empty">За вами не закреплены группы классного руководства.</p>';
        return;
    }
    foreach ($groups as $group) {
        $students = cabQuery($db, "SELECT s.fio, COUNT(e.id) FILTER (WHERE l.in_group=s.in_group) AS absences FROM public.students s
            LEFT JOIN public.evaluations e ON e.student_id=s.id AND e.grade IN ('н','Н')
            LEFT JOIN public.lessons l ON l.id=e.lesson_id
            WHERE s.in_group=?
            GROUP BY s.id,s.fio ORDER BY public.diary_name_sort(s.fio) COLLATE \"C\",s.fio,s.id",
            [$group['id']])->fetchAll(PDO::FETCH_ASSOC);
        $total = array_sum(array_column($students, 'absences'));
        ?>
        <details class="panel cab-group">
            <summary class="panel-head"><h3><?= ch($group['name']) ?> · <?= ch($group['campus'] ?? 'Корпус не указан') ?></h3>
                <span class="count"><?= count($students) ?> чел. · Н-ок: <?= ch($total) ?></span></summary>
            <?php if ($students): ?>
                <div class="cab-scroll"><table class="cab-table"><thead><tr><th>ФИО</th><th>Н-ок</th></tr></thead><tbody>
                    <?php foreach ($students as $student): ?><tr><td><?= ch($student['fio']) ?></td><td><?= ch($student['absences']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php else: ?><p class="cab-empty">В группе пока нет студентов.</p><?php endif; ?>
        </details>
        <?php
    }
}

/** A row per discipline and assessment, grouped by academic year / semester. */
function cabResultState(array $entry): array
{
    return [
        "key" => $entry["_edit"],
        "details" => [
            "assessed_on" => $entry["assessed_on"] ?? null,
            "statement_number" => $entry["statement_number"] ?? null,
        ],
        "student" => $entry["student_name"],
        "subject" => $entry["subject"],
        "kind" => DIARY_CONTROL_FORMS[$entry["kind"]],
    ];
}

function cabRecordBook(array $records, bool $editable = false): void
{
    $kinds = DIARY_CONTROL_FORMS;
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
        echo '<p class="cab-note">Часы, преподаватель и форма контроля берутся из ведомости. Нажмите на плюс или оценку, чтобы выставить или изменить результат.</p>';
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
                echo "<tr><td>" . ($index + 1) . "</td>";
                if ($editable) {
                    echo '<td class="record-person">' .
                        ch($entry["student_name"]) .
                        "</td>";
                }
                echo '<td class="record-subject">' .
                    ch($entry["subject"]) .
                    '</td><td>' .
                    ch($entry["planned_hours"] ?? $entry["hours"] ?? "—") .
                    '</td><td class="record-person">' .
                    ch($entry["fio"]) .
                    "</td><td>";
                $previous = $entry['kind'] === 'exam' ? ($entry['previous_grade'] ?? null) : null;
                if ($editable) {
                    $classes = 'cab-button record-mark' . (isset($entry['grade']) ? '' : ' record-mark-add');
                    echo '<button type="button" ' . cabGradeAttributes($classes, $previous) . ' data-final="' .
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
                    echo '<span ' . cabGradeAttributes('grade record-mark', $previous) . '>' .
                        ch($entry["grade"]) .
                        "</span>";
                }
                $date = !empty($entry["assessed_on"])
                    ? (new DateTimeImmutable($entry["assessed_on"]))->format(
                        "d.m.Y",
                    )
                    : "—";
                echo '</td><td>' .
                    ch($entry["statement_number"] ?? "—") .
                    '</td><td class="record-date">' .
                    ch($date) .
                    "</td><td>";
                echo ch($kinds[$entry["kind"]]);
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
    $kinds = DIARY_CONTROL_FORMS;
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
        "SELECT a.*,s.name AS subject,t.fio,d.hours AS planned_hours
         FROM public.teacher_attestations a JOIN public.subjects s ON s.id=a.subject_id
         JOIN public.teachers t ON t.id=a.teacher_id
         LEFT JOIN public.diary_statements d ON d.group_id=a.group_id AND d.subject_id=a.subject_id
            AND d.period_start=a.period_start AND d.teacher_id=a.teacher_id AND d.kind=a.kind
         WHERE a.student_id=? AND $scope ORDER BY a.period_start,a.kind,s.name,a.teacher_id",
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
): ?><td><div class="result-marks marks-list"><?php
foreach (
    $entries[$id][$key] ?? []
    as $entry
): ?><span class="mark-entry"><span class="grade" title="<?= ch($entry["fio"]) ?>"><?= ch(
    $entry["grade"],
) ?></span></span><?php endforeach;
if (empty($entries[$id][$key])): ?><span class="muted">—</span><?php endif;
?></div></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php
}
