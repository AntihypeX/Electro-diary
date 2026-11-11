<?php

$workspaceRole = 'teacher';
if (in_array($_GET['tab'] ?? '', ['schedule', 'finals', 'group'], true)) {
    require __DIR__ . '/workspace.php';
    exit;
}


require_once __DIR__ . '/../api/cabinet.php';
require_once __DIR__ . '/../api/teacher.php';

header('Cache-Control: no-store');

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$currentUser = getCurrentUser();
if (($currentUser['role'] ?? null) !== 'teacher') {
    if ($isPost) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Войдите под учётной записью преподавателя.'], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: index.php');
    }
    exit;
}

$db = connectDB();
$profile = teacherProfile($db, (int) ($_SESSION['user_id'] ?? $currentUser['id']));
$teacher = (int) ($profile['id'] ?? 0);
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

if ($isPost) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            throw new TeacherRequestError('Сессия устарела. Обновите страницу.', 403);
        }
        if (!$teacher) {
            throw new TeacherRequestError('К учётной записи не привязан профиль преподавателя.', 403);
        }
        if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            throw new TeacherRequestError('Ожидается запрос в формате JSON.', 415);
        }
        $input = json_decode(file_get_contents('php://input', false, null, 0, 8192), true);
        if (!is_array($input)) {
            throw new TeacherRequestError('Некорректный запрос.', 400);
        }

        $db->beginTransaction();
        $message = teacherSave($db, $teacher, $input);
        $db->commit();

        $_SESSION['teacher_notice'] = $message;
        echo json_encode(['ok' => true]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if ($error instanceof TeacherRequestError) {
            http_response_code($error->getCode());
            $message = $error->getMessage();
        } else {
            error_log('Teacher cabinet: ' . $error->getMessage());
            http_response_code(500);
            $message = 'Не удалось сохранить изменения. Попробуйте ещё раз.';
        }
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$notice = $_SESSION['teacher_notice'] ?? '';
unset($_SESSION['teacher_notice']);
session_write_close();

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function teacherUrl(string $tab, array $filters = []): string
{
    return 'teacher.php?' . http_build_query(['tab' => $tab] + $filters);
}

function teacherSelect(string $key, string $label, array $options, int|string $selected, bool $wrap = false): void
{
    $selectedText = $options[$selected] ?? reset($options);
    ?>
    <label class="filter-group<?= $wrap ? ' teacher-filter-long' : '' ?>">
      <span><?= h($label) ?></span>
      <?php if ($wrap): ?>
        <span class="teacher-select-wrap">
          <span class="teacher-select-value" aria-hidden="true"><?= h($selectedText) ?></span>
      <?php endif; ?>
      <select name="<?= h($key) ?>"<?= $wrap ? ' title="' . h($selectedText) . '"' : '' ?>>
        <?php foreach ($options as $value => $text): ?>
          <option value="<?= h($value) ?>" <?= (string) $value === (string) $selected ? 'selected' : '' ?>><?= h($text) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($wrap): ?></span><?php endif; ?>
    </label>
    <?php
}

function shortDate(string $date): string
{
    return (new DateTimeImmutable($date))->format('d.m');
}

function teacherSchedule(array $lessons, bool $withDate = true): void
{
    ?>
    <ul class="teacher-lesson-list">
      <?php foreach ($lessons as $lesson): ?>
        <li>
          <span class="tag"><?= $withDate ? h(shortDate($lesson['date'])) . ' · ' : '' ?><?= h($lesson['num_in_table']) ?> пара</span>
          <div>
            <b><?= h($lesson['subject_name']) ?></b>
            <small><?= h($lesson['topic_name']) ?></small>
          </div>
          <span class="teacher-lesson-meta"><?= h($lesson['group_name']) ?><?php if ($lesson['lesson_type']): ?> · <?= h($lesson['lesson_type']) ?><?php endif; ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php
}

$tabs = cabinetTeacherTabs();
$tab = is_string($_GET['tab'] ?? null) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'home';
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Novosibirsk'));
$currentYear = (int) $today->format('Y') - ((int) $today->format('n') < 9 ? 1 : 0);
$year = $currentYear;
$thisMonday = $today->modify('monday this week');
$filterLinks = [];
$monthNames = [9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь'];
$lessonListSql = <<<'SQL'
    SELECT l.date, l.num_in_table, l.lesson_type, t.name AS topic_name,
           s.name AS subject_name, g.name AS group_name
    FROM public.lessons l
    JOIN public.subjects s ON s.id = l.subject
    JOIN public.stud_groups g ON g.id = l.in_group
    LEFT JOIN public.lesson_topics t ON t.id = l.topic
    WHERE l.teacher = ?
    SQL;

$loadError = '';
if ($teacher) {
    try {
        if ($tab === 'home') {
            $todayLessons = teacherRows($db, $lessonListSql . ' AND l.date = ? ORDER BY l.num_in_table,l.id', [$teacher,$today->format('Y-m-d')]);
            $unfinished = teacherRows($db, <<<'SQL'
                SELECT l.id,l.date,l.subject,l.in_group,l.num_in_table,g.name AS group_name,s.name AS subject_name,
                    COUNT(st.id) AS missing
                FROM public.lessons l
                JOIN public.stud_groups g ON g.id=l.in_group
                JOIN public.subjects s ON s.id=l.subject
                JOIN public.students st ON st.in_group=l.in_group
                WHERE l.teacher=? AND l.date>=? AND l.date<=?
                  AND l.lesson_type IS DISTINCT FROM 'Лекция'
                  AND NOT EXISTS(SELECT 1 FROM public.evaluations e WHERE e.lesson_id=l.id AND e.student_id=st.id AND NULLIF(BTRIM(e.grade),'') IS NOT NULL)
                GROUP BY l.id,g.name,s.name ORDER BY l.date DESC,l.num_in_table
                SQL, [$teacher,$today->modify('-30 days')->format('Y-m-d'),$today->format('Y-m-d')]);
            $attention = teacherRows($db, <<<'SQL'
                SELECT st.id,st.fio,st.in_group,g.name AS group_name,
                    COUNT(*) FILTER(WHERE e.grade='2') AS twos,
                    COUNT(*) FILTER(WHERE e.grade IN ('н','Н')) AS absences
                FROM public.evaluations e JOIN public.lessons l ON l.id=e.lesson_id
                JOIN public.students st ON st.id=e.student_id JOIN public.stud_groups g ON g.id=st.in_group
                WHERE l.teacher=? AND l.date>=? AND l.date<=? AND e.grade IN ('2','н','Н')
                GROUP BY st.id,g.name ORDER BY public.diary_name_sort(st.fio) COLLATE "C",st.fio,st.id
                SQL,[$teacher,$today->modify('-30 days')->format('Y-m-d'),$today->format('Y-m-d')]);
        } elseif ($tab === 'schedule') {
            $weekDate = $_GET['week'] ?? '';
            $date = is_string($weekDate) && preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $weekDate)
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $weekDate, $today->getTimezone())
                : false;
            $monday = $date && $date->format('Y-m-d') === $weekDate ? $date->modify('monday this week') : $thisMonday;
            $schedule = [];
            foreach (teacherRows($db, $lessonListSql . '
                AND l.date >= ? AND l.date < ? ORDER BY l.date, l.num_in_table, l.id',
                [$teacher, $monday->format('Y-m-d'), $monday->modify('+7 days')->format('Y-m-d')]) as $lesson) {
                $schedule[$lesson['date']][] = $lesson;
            }
        } else {
            $years = [$currentYear => $currentYear . '–' . ($currentYear + 1)];
            foreach (teacherRows($db, <<<'SQL'
                SELECT DISTINCT EXTRACT(YEAR FROM date)::integer
                       - CASE WHEN EXTRACT(MONTH FROM date) <= 6 THEN 1 ELSE 0 END AS year
                FROM public.lessons
                WHERE teacher = ? AND EXTRACT(MONTH FROM date) NOT IN (7, 8)
                SQL, [$teacher]) as $row) {
                $y = (int) $row['year'];
                $years[$y] = $y . '–' . ($y + 1);
            }
            krsort($years);
            $requestedYear = (int) ($_GET['year'] ?? 0);
            $year = isset($years[$requestedYear]) ? $requestedYear : $currentYear;
            $yearStart = "$year-09-01";
            $yearEnd = ($year + 1) . '-07-01';
            $assignments = teacherRows($db, <<<'SQL'
                SELECT DISTINCT g.id AS group_id, g.name AS group_name, s.id AS subject_id, s.name AS subject_name
                FROM public.lessons l
                JOIN public.stud_groups g ON g.id = l.in_group
                JOIN public.subjects s ON s.id = l.subject
                WHERE l.teacher = ? AND l.date >= ? AND l.date < ?
                ORDER BY g.name, s.name, g.id, s.id
                SQL, [$teacher, $yearStart, $yearEnd]);
            $groups = array_column($assignments, 'group_name', 'group_id');
            $group = (int) ($_GET['group'] ?? 0);
            if (!isset($groups[$group])) {
                $group = (int) array_key_first($groups);
            }
            $subjects = [];
            foreach ($assignments as $assignment) {
                if ((int) $assignment['group_id'] === $group) {
                    $subjects[$assignment['subject_id']] = $assignment['subject_name'];
                }
            }
            $subject = (int) ($_GET['subject'] ?? 0);
            if (!isset($subjects[$subject])) {
                $subject = (int) array_key_first($subjects);
            }

            $students = $group ? array_column(teacherRows($db, <<<'SQL'
                SELECT id, fio FROM public.students WHERE in_group = ? ORDER BY public.diary_name_sort(fio) COLLATE "C", fio, id
                SQL, [$group]), 'fio', 'id') : [];
            $student = (int) ($_GET['student'] ?? 0);
            if (!isset($students[$student])) {
                $student = 0;
            }
            $shownStudents = $student ? [$student => $students[$student]] : $students;

            $semesterNames = [1 => '1-й семестр', 2 => '2-й семестр'];
            $semester = (int) ($_GET['semester'] ?? 0);
            if (!isset($semesterNames[$semester])) {
                $semester = $tab === 'journal' ? 0 : ((int) $today->format('n') >= 9 ? 1 : 2);
            }
            $months = match ($semester) {
                1 => array_slice($monthNames, 0, 4, true),
                2 => array_slice($monthNames, 4, null, true),
                default => $monthNames,
            };
            $month = (int) ($_GET['month'] ?? 0);
            if (!isset($months[$month])) {
                $month = 0;
            }
            $monthStart = static fn (int $m): string => sprintf('%04d-%02d-01', $year + ($m < 9 ? 1 : 0), $m);
            $filterLinks = ['year' => $year, 'group' => $group, 'subject' => $subject, 'student' => $student];
            $columns = $entries = [];

            if ($subject && $tab === 'journal') {
                $rangeStart = $month ? $monthStart($month) : $monthStart(array_key_first($months));
                $rangeEnd = (new DateTimeImmutable($monthStart($month ?: array_key_last($months))))
                    ->modify('+1 month')->format('Y-m-d');
                $scope = [$teacher, $group, $subject, $rangeStart, $rangeEnd];
                $columns = teacherRows($db, <<<'SQL'
                    SELECT l.id, l.date, l.num_in_table, l.lesson_type, l.topic AS topic_id, t.name AS topic_name
                    FROM public.lessons l LEFT JOIN public.lesson_topics t ON t.id = l.topic
                    WHERE l.teacher = ? AND l.in_group = ? AND l.subject = ? AND l.date >= ? AND l.date < ?
                    ORDER BY l.date, l.num_in_table, l.id
                    SQL, $scope);
                $gradesSql = <<<'SQL'
                    SELECT e.id, e.student_id, e.lesson_id, e.grade, e.previous_grade
                    FROM public.evaluations e
                    JOIN public.lessons l ON l.id = e.lesson_id
                    JOIN public.students s ON s.id = e.student_id AND s.in_group = l.in_group
                    WHERE l.teacher = ? AND l.in_group = ? AND l.subject = ? AND l.date >= ? AND l.date < ?
                      AND NULLIF(BTRIM(e.grade), '') IS NOT NULL
                    SQL;
                if ($student) {
                    $gradesSql .= ' AND e.student_id = ?';
                    $scope[] = $student;
                }
                foreach (teacherRows($db, $gradesSql . ' ORDER BY e.id', $scope) as $entry) {
                    $entries[$entry['student_id']][$entry['lesson_id']][] = $entry;
                }
            } elseif ($subject) {
                foreach ($month ? [$month => $months[$month]] : $months as $number => $name) {
                    $columns[] = ['kind' => 'month', 'period' => $monthStart($number), 'name' => $name];
                }
                $semesterStart = $monthStart(array_key_first($months));
                foreach (array_intersect_key(TEACHER_ATTESTATION_TYPES, ['semester' => true]) as $kind => $name) {
                    $columns[] = ['kind' => $kind, 'period' => $semesterStart, 'name' => $name];
                }

                $scope = [$teacher, $group, $subject, $semesterStart,
                    (new DateTimeImmutable($monthStart(array_key_last($months))))->modify('+1 month')->format('Y-m-d')];
                $attestationSql = <<<'SQL'
                    SELECT a.student_id, a.period_start, a.kind, a.grade
                    FROM public.teacher_attestations a
                    JOIN public.students s ON s.id = a.student_id AND s.in_group = a.group_id
                    WHERE a.teacher_id = ? AND a.group_id = ? AND a.subject_id = ?
                      AND a.period_start >= ? AND a.period_start < ?
                    SQL;
                if ($student) {
                    $attestationSql .= ' AND a.student_id = ?';
                    $scope[] = $student;
                }
                foreach (teacherRows($db, $attestationSql, $scope) as $entry) {
                    $entries[$entry['student_id']][$entry['period_start']][$entry['kind']] = $entry['grade'];
                }
            }
        }
    } catch (PDOException $error) {
        error_log('Teacher cabinet: ' . $error->getMessage());
        http_response_code(500);
        $loadError = in_array($error->getCode(), ['42P01', '42703'], true)
            ? 'Обновите структуру базы данных кабинета преподавателя.'
            : 'Не удалось загрузить данные. Обновите страницу.';
    }
}

$topicOptions = isset($subject) && $subject ? teacherRows($db, "SELECT t.id,t.name,to_jsonb(t)->>'lesson_type' AS lesson_type FROM public.lesson_topics t WHERE t.subj_id = ? AND (t.teacher_id IS NULL OR t.teacher_id=?) ORDER BY t.name,t.id", [$subject,$teacher]) : [];
$name = $profile['fio'] ?? ($currentUser['name'] ?? 'Преподаватель');
preg_match('/\A./u', $name, $initial);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#111111">
  <meta name="csrf-token" content="<?= h($csrf) ?>">
  <title><?= h($tabs[$tab][0]) ?> — Кабинет преподавателя</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('index.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('teacher.css'), ENT_QUOTES, 'UTF-8') ?>">
  <script src="<?= htmlspecialchars(cabinetAsset('grade-picker.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script><script src="<?= htmlspecialchars(cabinetAsset('teacher.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('cabinet.css'), ENT_QUOTES, 'UTF-8') ?>"><script src="<?= htmlspecialchars(cabinetAsset('cabinet.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script></head>
<body class="teacher">
  <div class="app-shell">
    <?php cabSidebar('teacher', $currentUser, $tab, $csrf, $filterLinks); ?>

    <main class="content">
      <header class="topbar">
        <div><span class="breadcrumb">ЛИЧНЫЙ КАБИНЕТ / ПРЕПОДАВАТЕЛЬ</span><h1><?= h($tabs[$tab][0]) ?></h1></div>
        <div class="date-block"><span>УЧЕБНЫЙ ГОД</span><b><?= h($year) ?>–<?= h($year + 1) ?></b></div>
      </header>

      <div class="page-stack">
        <p class="teacher-notice" id="teacher-notice" role="status" <?= $notice === '' ? 'hidden' : '' ?>><?= h($notice) ?></p>
        <?php if (!$profile || $loadError !== ''): ?>
          <p class="panel teacher-empty"><?= h($loadError ?: 'К вашей учётной записи не привязан профиль преподавателя.') ?></p>
        <?php elseif ($tab === 'home'): ?>
          <div class="metrics">
            <div class="metric"><span>Занятий сегодня</span><b><?= count($todayLessons) ?></b><small><?= h($today->format('d.m.Y')) ?></small></div>
            <div class="metric"><span>Пар с незаполненными отметками</span><b><?= count($unfinished) ?></b><small>За последние 30 дней</small></div>
            <div class="metric"><span>Студентов с двойками или пропусками</span><b><?= count($attention) ?></b><small>За последние 30 дней</small></div>
          </div>
          <section class="panel"><header class="panel-head"><h3>Сегодня по расписанию</h3></header>
            <?php if ($todayLessons) teacherSchedule($todayLessons,false); else echo '<p class="teacher-empty">Сегодня занятий нет.</p>'; ?>
          </section>
          <section class="panel"><header class="panel-head"><h3>Требуют заполнения</h3></header>
            <div class="cab-scroll"><table class="cab-table"><thead><tr><th>Занятие</th><th>Группа</th><th>Нет отметок у студентов</th><th></th></tr></thead><tbody>
              <?php foreach($unfinished as $row): ?><tr><td><?= h($row['subject_name'].' · '.$row['date'].' · '.$row['num_in_table'].' пара') ?></td><td><?= h($row['group_name']) ?></td><td><?= h($row['missing']) ?></td><td><a class="cab-button" href="<?= h(teacherUrl('journal',['group'=>$row['in_group'],'subject'=>$row['subject'],'year'=>(int)substr($row['date'],0,4)-((int)substr($row['date'],5,2)<9?1:0),'month'=>(int)substr($row['date'],5,2)])) ?>">Открыть журнал</a></td></tr><?php endforeach; ?>
            </tbody></table></div><?php if(!$unfinished): ?><p class="teacher-empty">Отметки за последние 30 дней заполнены.</p><?php endif; ?>
          </section>
          <section class="panel"><header class="panel-head"><h3>Двойки и пропуски за 30 дней</h3></header><div class="cab-scroll"><table class="cab-table"><thead><tr><th>Студент</th><th>Группа</th><th>Двойки</th><th>Пропуски</th><th></th></tr></thead><tbody>
            <?php foreach($attention as $row): ?><tr><td><?= h($row['fio']) ?></td><td><?= h($row['group_name']) ?></td><td><?= h($row['twos']) ?></td><td><?= h($row['absences']) ?></td><td><a class="cab-button" href="<?= h(teacherUrl('journal',['group'=>$row['in_group'],'student'=>$row['id']])) ?>">Посмотреть</a></td></tr><?php endforeach; ?>
          </tbody></table></div><?php if(!$attention): ?><p class="teacher-empty">Двоек и пропусков за этот период нет.</p><?php endif; ?></section>
        <?php elseif ($tab === 'schedule'): ?>
          <section class="panel">
            <header class="panel-head teacher-week-head">
              <div><h3><?= h($monday->format('d.m')) ?> — <?= h($monday->modify('+6 days')->format('d.m.Y')) ?></h3></div>
              <nav class="teacher-week-nav" aria-label="Переключение недели">
                <a href="<?= h(teacherUrl('schedule', ['week' => $monday->modify('-7 days')->format('Y-m-d')])) ?>" aria-label="Предыдущая неделя">←</a>
                <a href="<?= h(teacherUrl('schedule')) ?>">Текущая</a>
                <a href="<?= h(teacherUrl('schedule', ['week' => $monday->modify('+7 days')->format('Y-m-d')])) ?>" aria-label="Следующая неделя">→</a>
              </nav>
            </header>
            <?php foreach (['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота', 'Воскресенье'] as $offset => $weekday): ?>
              <?php $day = $monday->modify("+$offset days")->format('Y-m-d'); ?>
              <section class="teacher-day">
                <h4><?= h($weekday) ?> <span><?= h(shortDate($day)) ?></span></h4>
                <?php if (isset($schedule[$day])): ?>
                  <?php teacherSchedule($schedule[$day], false); ?>
                <?php else: ?>
                  <p>Занятий нет</p>
                <?php endif; ?>
              </section>
            <?php endforeach; ?>
          </section>
        <?php else: ?>
          <form method="get" class="filter-toolbar teacher-filters">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <?php teacherSelect('year', 'Учебный год', $years, $year); ?>
            <?php teacherSelect('group', 'Группа', $groups ?: [0 => 'Нет групп'], $group); ?>
            <?php teacherSelect('subject', 'Дисциплина', $subjects ?: [0 => 'Нет дисциплин'], $subject, true); ?>
            <?php teacherSelect('student', 'Студент', [0 => 'Все студенты'] + $students, $student); ?>
            <?php teacherSelect('semester', 'Семестр', $tab === 'journal' ? [0 => 'Все семестры'] + $semesterNames : $semesterNames, $semester); ?>
            <?php teacherSelect('month', 'Месяц', [0 => 'Все месяцы'] + $months, $month); ?>
            <button class="primary" type="submit" hidden>Показать</button>
          <?= cabinetResetLink(); ?></form>
          <section class="panel table-panel">
            <header class="panel-head teacher-table-head">
              <div><h3><?= h($subjects[$subject] ?? $tabs[$tab][0]) ?></h3></div>
              <?php if ($group): ?><span class="count"><?= h($groups[$group]) ?> · <?= count($shownStudents) ?> из <?= count($students) ?></span><?php endif; ?>
            </header>
            <?php if (!$groups || !$students || !$columns): ?>
              <p class="teacher-empty"><?= !$groups ? 'В этом учебном году у вас нет занятий в расписании.' : (!$students ? 'В выбранной группе пока нет студентов.' : 'За выбранный период занятий нет. Измените семестр или месяц.') ?></p>
            <?php else: ?>
              <p class="teacher-table-note"><?= $tab === 'journal' ? 'Нажмите на дату, чтобы указать тему пары. Нажмите на ячейку или оценку, чтобы выставить или изменить отметку.' : 'Выберите ячейку, чтобы выставить или изменить результат аттестации.' ?></p>
              <?php if ($tab === 'attestation'): ?>
                <div class="cab-note">
                  <button class="cab-button" type="button" data-auto-attestation="<?= h(json_encode([
                      'action'=>'attestation_auto', 'year'=>$year, 'semester'=>$semester, 'month'=>$month,
                      'group'=>$group, 'subject'=>$subject, 'student'=>$student,
                  ])) ?>">Заполнить автоматически</button>
                  <small>Для среднего берутся числовые оценки, даже если оценка одна. Пропуски «н» не учитываются. Если числовых оценок нет, ячейка остаётся пустой. Семестровая отметка считается после заполнения всех месяцев.</small>
                </div>
              <?php endif; ?>
              <div class="matrix-container" id="teacher-matrix" tabindex="0" role="region" aria-label="<?= h($tabs[$tab][0]) ?>">
                <table class="matrix-table teacher-matrix <?= $tab === 'attestation' ? 'teacher-attestation' : '' ?>">
                  <thead>
                    <tr>
                      <th scope="col">Студент</th>
                      <?php foreach ($columns as $column): ?>
                        <th scope="col" data-lesson-kind="<?= h($column['lesson_type'] ?? '') ?>">
                          <?php if ($tab === 'journal'): ?>
                            <button class="teacher-date date-cell-header" type="button"
                              data-action="lesson" data-lesson="<?= h($column['id']) ?>"
                              data-topic="<?= h($column['topic_name']) ?>" data-topic-id="<?= h($column['topic_id']) ?>" data-type="<?= h($column['lesson_type'] ?? '') ?>"
                              data-caption="<?= h((new DateTimeImmutable($column['date']))->format('d.m.Y') . ' · ' . $column['num_in_table'] . ' пара') ?>"
                              title="<?= h($column['topic_name'] . ($column['lesson_type'] ? ' · ' . $column['lesson_type'] : '')) ?>">
                              <?= h(shortDate($column['date'])) ?><small><?= h($column['num_in_table']) ?> пара</small>
                            </button>
                          <?php else: ?>
                            <?= h($column['name']) ?>
                          <?php endif; ?>
                        </th>
                      <?php endforeach; ?>
                      <?php if ($tab === 'journal'): ?><th scope="col">Средний балл</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($shownStudents as $id => $fio): ?>
                      <?php $numericGrades = []; ?>
                      <tr data-student="<?= h($id) ?>" data-name="<?= h($fio) ?>">
                        <th scope="row"><?= h($fio) ?></th>
                        <?php foreach ($columns as $column): ?>
                          <?php if ($tab === 'journal'): ?>
                            <?php
                            $grades = $entries[$id][$column['id']] ?? [];
                            $exam = $column['lesson_type'] === 'Экзамен';
                            ?>
                            <td class="teacher-cell" data-lesson-kind="<?= h($column['lesson_type'] ?? '') ?>" data-lesson="<?= h($column['id']) ?>" data-count="<?= count($grades) ?>">
                              <div class="teacher-grade-list marks-list">
                                <?php foreach ($grades as $entry): ?>
                                  <?php if (in_array($entry['grade'], ['2', '3', '4', '5'], true)) $numericGrades[] = (int) $entry['grade']; ?>
                                  <span class="mark-entry"><button <?= cabGradeAttributes('grade', $exam ? $entry['previous_grade'] : null) ?> type="button" data-action="grade_edit" data-entry="<?= h($entry['id']) ?>"
                                    data-value="<?= h($entry['grade']) ?>" aria-label="<?= h('Изменить оценку ' . $entry['grade'] . ' · ' . $fio . ' · ' . shortDate($column['date'])) ?>"><?= h($entry['grade']) ?></button></span>
                                <?php endforeach; ?>
                                <?php if (!$exam || !$grades): ?>
                                  <button class="teacher-add" type="button" data-action="grade_add"
                                    aria-label="<?= h('Добавить оценку · ' . $fio . ' · ' . shortDate($column['date'])) ?>">+</button>
                                <?php endif; ?>
                              </div>
                            </td>
                          <?php else: ?>
                            <?php $value = $entries[$id][$column['period']][$column['kind']] ?? ''; ?>
                            <td class="teacher-cell">
                              <button class="<?= $value !== '' ? 'grade' : 'teacher-add' ?>" type="button"
                                data-action="attestation" data-kind="<?= h($column['kind']) ?>" data-period="<?= h($column['period']) ?>"
                                data-group="<?= h($group) ?>" data-subject="<?= h($subject) ?>" data-value="<?= h($value) ?>"
                                data-caption="<?= h($column['name'] . ' · ' . $semesterNames[$semester]) ?>"
                                aria-label="<?= h($column['name'] . ' · ' . $fio . ' · ' . ($value ?: 'выставить отметку')) ?>"><?= h($value ?: '+') ?></button>
                            </td>
                          <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($tab === 'journal'): ?>
                          <td><b><?= $numericGrades ? number_format(array_sum($numericGrades) / count($numericGrades), 2, ',', '') : '—' ?></b></td>
                        <?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <dialog class="teacher-dialog" id="teacher-editor" aria-labelledby="teacher-editor-title">
    <form id="teacher-editor-form">
      <header><h2 id="teacher-editor-title"></h2><button type="button" data-close aria-label="Закрыть">×</button></header>
      <div class="teacher-editor-body">
        <p class="teacher-editor-context" id="teacher-editor-context"></p>
        <div id="teacher-confirm" hidden>
          <p>Текущая отметка <strong id="teacher-previous-grade"></strong>. Подтвердите, что хотите её изменить.</p>
          <button type="button" class="primary" id="teacher-confirm-edit">Изменить отметку</button>
        </div>
        <div id="teacher-fields" hidden>
          <div id="teacher-lesson-fields" hidden>
            <label>Тема занятия <select name="topic"><option value="">Выберите тему</option><?php foreach ($topicOptions as $option): ?><option value="<?= h($option['id']) ?>" data-type="<?= h($option['lesson_type'] ?? '') ?>"><?= h($option['name']) ?></option><?php endforeach; ?></select></label>
            <label>Или новая тема <input name="newTopic" maxlength="100" placeholder="Введите название темы"></label>
            <label>Вид занятия
              <select name="type">
                <option value="">Выберите вид</option>
                <?php foreach (TEACHER_LESSON_TYPES as $type): ?><option><?= h($type) ?></option><?php endforeach; ?>
              </select>
            </label>
          </div>
          <div id="teacher-grade-fields" hidden>
            <div id="teacher-grade-rows"></div>
            <button class="teacher-text-button" type="button" id="teacher-add-row">+ Добавить ещё оценку</button>
          </div>
        </div>
        <p class="teacher-editor-error" id="teacher-editor-error" role="alert" hidden></p>
        <footer class="teacher-editor-actions">
          <button type="button" class="teacher-text-button" data-close>Отмена</button>
          <button type="submit" class="primary" id="teacher-save" hidden>Сохранить</button>
        </footer>
      </div>
    </form>
    <template id="teacher-grade-template">
      <div class="teacher-grade-field">
        <label><span>Отметка</span>
          <select name="grades" required>
            <option value="">Выберите отметку</option>
            <?php foreach (TEACHER_GRADE_VALUES as $value): ?><option value="<?= h($value) ?>"><?= h($value) ?></option><?php endforeach; ?>
          </select>
        </label>
        <button type="button" class="teacher-text-button teacher-remove-grade" aria-label="Убрать дополнительную оценку">×</button>
      </div>
    </template>
  </dialog>
</body>
</html>
