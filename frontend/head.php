<?php

$workspaceRole = 'head';
if (in_array($_GET['tab'] ?? '', ['groups'], true)) {
    require __DIR__ . '/workspace.php';
    exit;
}

require_once __DIR__ . '/../api/cabinet.php';
require_once __DIR__ . '/../api/head.php';

header('Cache-Control: no-store');
$user = getCurrentUser();
if (($user['role'] ?? null) !== 'head') {
    header('Location: index.php');
    exit;
}
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout'
        && is_string($_POST['csrf'] ?? null) && hash_equals($csrf, $_POST['csrf'])) {
        logout();
        header('Location: index.php');
        exit;
    }
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Действие недоступно.');
}
session_write_close();

$tabs = cabTabs('head');
$requestedTab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
$tab = $requestedTab === 'exams' ? 'finals' : (isset($tabs[$requestedTab]) ? $requestedTab : 'students');
$filters = ['campus' => '', 'group' => 0, 'student' => 0, 'year' => 0, 'semester' => 0, 'month' => 0, 'subject' => 0];
$schema = secretaryFields();
$schema['student'][0] = 'Студент';
$kindNames = ['month'=>'За месяц','semester'=>'За семестр'] + DIARY_CONTROL_FORMS;
$months = [0=>'Все месяцы',9=>'Сентябрь',10=>'Октябрь',11=>'Ноябрь',12=>'Декабрь',
    1=>'Январь',2=>'Февраль',3=>'Март',4=>'Апрель',5=>'Май',6=>'Июнь',7=>'Июль',8=>'Август'];
$semesters = [0=>'Все семестры',1=>'1-й · сентябрь — декабрь',2=>'2-й · январь — июнь'];
$error = '';
$db = null;
try {
    $db = connectDB();
    $db->beginTransaction();
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $campuses = headQuery($db, 'SELECT id, name FROM public.campuses ORDER BY name, id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $allGroups = headQuery($db, 'SELECT id, name, campus_id FROM public.stud_groups ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);
    $specialties = headQuery($db, "SELECT id, code || ' · ' || name FROM public.education_specialties ORDER BY code, name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $subjects = headQuery($db, 'SELECT id, name FROM public.subjects ORDER BY name, id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $filters['campus'] = is_string($_GET['campus'] ?? null) ? $_GET['campus'] : '';
    if ($filters['campus'] !== '-1' && !isset($campuses[$filters['campus']])) $filters['campus'] = '';
    $groupRows = array_filter($allGroups, fn ($row) => $filters['campus'] === '' || (string) ($row['campus_id'] ?? '-1') === $filters['campus']);
    $groups = array_column($groupRows, 'name', 'id');
    $filters['group'] = headNumber($_GET['group'] ?? null);
    if (!isset($groups[$filters['group']])) $filters['group'] = $tab === 'analytics' ? 0 : (int) array_key_first($groups);
    $roster = $filters['group'] ? headRoster($db, $filters['group']) : [];
    $studentOptions = array_column($roster, 'fio', 'id');
    $filters['student'] = headNumber($_GET['student'] ?? null);
    if (!isset($studentOptions[$filters['student']]) || $tab === 'analytics') $filters['student'] = 0;
    $shownStudents = $filters['student'] ? array_values(array_filter($roster, fn ($s) => (int) $s['id'] === $filters['student'])) : $roster;

    $years = [];
    foreach (headQuery($db, 'SELECT DISTINCT EXTRACT(YEAR FROM day)::integer
        - CASE WHEN EXTRACT(MONTH FROM day) < 9 THEN 1 ELSE 0 END AS year
        FROM (SELECT date AS day FROM public.lessons UNION SELECT period_start FROM public.teacher_attestations) d
        ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN) as $year) {
        $years[(int) $year] = $year . '–' . ($year + 1);
    }
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Krasnoyarsk'));
    $nowMonth = (int) $today->format('n');
    $nowYear = (int) $today->format('Y') - ($nowMonth < 9 ? 1 : 0);
    $years[$nowYear] = $nowYear . '–' . ($nowYear + 1);
    krsort($years);
    $showCurrent = in_array($tab, ['journal','finals'], true);
    $filters['year'] = headNumber($_GET['year'] ?? ($showCurrent ? $nowYear : 0));
    if (!isset($years[$filters['year']])) $filters['year'] = 0;
    $filters['semester'] = headNumber($_GET['semester'] ?? ($showCurrent ? ($nowMonth >= 9 ? 1 : 2) : 0));
    if (!isset($semesters[$filters['semester']])) $filters['semester'] = 0;
    $filters['month'] = $tab === 'finals' ? 0 : headNumber($_GET['month'] ?? 0);
    if (!isset($months[$filters['month']])) $filters['month'] = 0;
    $filters['subject'] = headNumber($_GET['subject'] ?? null);
    if (!isset($subjects[$filters['subject']])) $filters['subject'] = 0;

    $sidebarFilters = $tab === 'students'
        ? array_intersect_key($filters, $_GET + ['campus'=>true,'group'=>true,'student'=>true])
        : $filters;
    $attestationFilters = $filters;
    $attestationQuery = [];
    foreach (['year'=>$years, 'semester'=>$semesters, 'month'=>$months, 'subject'=>$subjects] as $key => $options) {
        $value = headNumber($_GET['att_' . $key] ?? $filters[$key]);
        $attestationFilters[$key] = isset($options[$value]) ? $value : 0;
        if (array_key_exists('att_' . $key, $_GET)) $attestationQuery['att_' . $key] = $attestationFilters[$key];
    }
    $kind = $_GET['att_kind'] ?? '';
    $attestationFilters['kind'] = is_string($kind) && isset($kindNames[$kind]) ? $kind : '';
    if ($attestationFilters['kind'] !== '') $attestationQuery['att_kind'] = $attestationFilters['kind'];

    if ($tab === 'students') {
        $profile = $filters['student'] ? headProfile($db, $filters['student'], $filters['group']) : [];
        $pageCount = max(1, (int) ceil(count($shownStudents) / 30));
        $listPage = min($pageCount, max(1, headNumber($_GET['page'] ?? null)));
        $studentPage = array_slice($shownStudents, ($listPage - 1) * 30, 30);
    } elseif ($tab === 'analytics') {
        $averages = headAverages($db, $filters);
        $gradeCount = array_sum(array_column($averages, 'grade_count'));
        $gradeSum = array_sum(array_column($averages, 'grade_sum'));
        $selectedAverage = null;
        foreach ($averages as $row) if ((int) $row['id'] === $filters['group']) $selectedAverage = $row;
    } elseif ($filters['group']) {
        $journal = headJournal($db, $filters, max(1, headNumber($_GET['lessons_page'] ?? null)), $tab === 'finals');
        if ($tab === 'journal') {
            $attestations = headAttestations($db, $attestationFilters, max(1, headNumber($_GET['attestation_page'] ?? null)));
        }
    }
    $db->commit();
} catch (PDOException $exception) {
    if ($db?->inTransaction()) $db->rollBack();
    error_log('Head cabinet: ' . $exception->getMessage());
    http_response_code(500);
    $error = in_array($exception->getCode(), ['42P01', '42703'], true)
        ? 'Проверьте, что установлены миграции дневника, включая 08 и 09.'
        : 'Не удалось загрузить данные. Обновите страницу.';
}

function hh(mixed $value): string
{
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function headUrl(string $tab, array $filters, array $extra = []): string
{
    return 'head.php?' . http_build_query(['tab' => $tab] + $extra + $filters);
}

function headAverage(int|float|string $sum, int|string $count): string
{
    return (int) $count > 0 ? number_format((float) $sum / (int) $count, 2, ',', '') : '—';
}

function headDate(?string $value): string
{
    return $value ? (new DateTimeImmutable($value))->format('d.m.Y') : '—';
}

function headSelect(string $name, string $label, array $options, int|string $selected): void
{
    ?>
    <label><span><?= hh($label) ?></span><select name="<?= hh($name) ?>">
      <?php foreach ($options as $value => $text): ?><option value="<?= hh($value) ?>" <?= (string) $selected === (string) $value ? 'selected' : '' ?>><?= hh($text) ?></option><?php endforeach; ?>
    </select></label>
    <?php
}

function headDetails(string $section, array $values, array $specialties, array $extra = []): void
{
    $fields = secretaryFields()[$section][1] + $extra;
    if ($section === 'student') {
        unset($fields['birth_date'], $fields['phone'], $fields['email']);
    }
    ?>
    <dl class="head-details">
      <?php foreach ($fields as $key => $field): ?>
        <?php
        [$label, $type] = $field;
        $value = $values[$key] ?? null;
        if ($value === null || $value === '') $display = 'Не указано';
        elseif ($type === 'bool') $display = in_array($value, [true, 'true', 't', '1', 1], true) ? 'Да' : 'Нет';
        elseif ($type === 'select') $display = $field[2][$value] ?? (string) $value;
        elseif ($type === 'date') $display = headDate((string) $value);
        elseif ($type === 'specialty') $display = $specialties[$value] ?? 'Не указано';
        else $display = $value;
        ?>
        <div><dt><?= hh($label) ?></dt><dd><?= hh($display) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <?php
}

function headPagination(string $tab, array $filters, string $key, int $page, int $pages, array $extra = []): void
{
    if ($pages <= 1) return;
    ?>
    <nav class="head-pagination" aria-label="<?= $key === 'lessons_page' ? 'Страницы занятий' : ($key === 'attestation_page' ? 'Страницы аттестации' : 'Страницы студентов') ?>">
      <span><?= $page ?> / <?= $pages ?></span>
      <?php foreach ([$page - 1 => '← Назад', $page + 1 => 'Далее →'] as $number => $label): ?>
        <?php if ($number >= 1 && $number <= $pages): ?><a href="<?= hh(headUrl($tab, $filters, [$key => $number] + $extra) . ($key === 'attestation_page' ? '#attestations' : '')) ?>"><?= hh($label) ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#111111">
  <title><?= hh($tabs[$tab][0]) ?> — Заведующий отделением</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('index.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('head.css'), ENT_QUOTES, 'UTF-8') ?>">
  <script src="<?= htmlspecialchars(cabinetAsset('head.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('cabinet.css'), ENT_QUOTES, 'UTF-8') ?>"><script src="<?= htmlspecialchars(cabinetAsset('cabinet.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script></head>
<body class="head">
<div class="app-shell">
  <?php cabSidebar('head', $user, $tab, $csrf, $sidebarFilters ?? []); ?>
  <main class="content">
    <header class="topbar"><div><span class="breadcrumb">ЛИЧНЫЙ КАБИНЕТ / ЗАВЕДУЮЩИЙ ОТДЕЛЕНИЕМ</span><h1><?= hh($tabs[$tab][0]) ?></h1></div><div class="date-block"><span>УЧЕБНЫЙ ГОД</span><b><?= $filters['year'] ? hh($filters['year'] . '–' . ($filters['year'] + 1)) : 'Все годы' ?></b></div></header>
    <div class="page-stack">
      <?php if ($error): ?><p class="panel head-empty" role="alert"><?= hh($error) ?></p>
      <?php else: ?>
        <form method="get" class="filter-toolbar head-filters">
          <input type="hidden" name="tab" value="<?= hh($tab) ?>">
          <?php headSelect('campus', 'Корпус', ['' => 'Все корпуса', '-1' => 'Не указан'] + $campuses, $filters['campus']); ?>
          <?php headSelect('group', 'Группа', ($tab === 'analytics' ? [0 => 'Все группы'] : []) + ($groups ?: [0 => 'Нет групп']), $filters['group']); ?>
          <?php if ($tab !== 'analytics') headSelect('student', 'Студент', [0 => 'Все студенты'] + $studentOptions, $filters['student']); ?>
          <?php if ($tab !== 'students'): ?>
            <?php headSelect('year', 'Учебный год', [0 => 'Все годы'] + $years, $filters['year']); ?>
            <?php headSelect('semester', 'Семестр', $semesters, $filters['semester']); ?>
            <?php if ($tab !== 'finals') headSelect('month', 'Месяц', $months, $filters['month']); ?>
            <?php headSelect('subject', 'Дисциплина', [0 => 'Все предметы'] + $subjects, $filters['subject']); ?>
          <?php else: ?>
            <?php foreach (['year', 'semester', 'month', 'subject'] as $key): if (!array_key_exists($key, $_GET)) continue; ?><input type="hidden" name="<?= $key ?>" value="<?= hh($filters[$key]) ?>"><?php endforeach; ?>
          <?php endif; ?>
          <?php if ($tab === 'journal'): foreach ($attestationQuery as $key => $value): ?>
            <input type="hidden" name="<?= hh($key) ?>" value="<?= hh($value) ?>">
          <?php endforeach; endif; ?>
          <button class="primary" type="submit" hidden>Показать</button>
        <?= cabinetResetLink(); ?></form>
        <?php if ($tab === 'students'): ?>
          <section class="panel">
            <header class="panel-head"><div><h3><?= hh($groups[$filters['group']] ?? 'Студенты') ?></h3></div><span class="count"><?= count($shownStudents) ?> студентов</span></header>
            <?php if (!$shownStudents): ?><p class="head-empty">В выбранной группе студентов нет.</p>
            <?php else: ?>
              <div class="head-scroll"><table class="head-table"><thead><tr><th>Студент</th><th>Статус</th><th>Просмотр</th></tr></thead><tbody>
                <?php foreach ($studentPage as $student): ?>
                  <tr><th scope="row"><?= hh($student['fio']) ?></th><td><span class="tag"><?= $student['expulsion_date'] ? 'Отчислен' : 'Обучается' ?></span></td><td><div class="head-links"><a class="head-button" href="<?= hh(headUrl('students', $filters, ['student' => $student['id']])) ?>#profile">Полная карточка</a><a class="head-button" href="<?= hh(headUrl('journal', $filters, ['student' => $student['id'], 'year' => 0, 'semester' => 0, 'month' => 0, 'subject' => 0])) ?>">Все оценки</a></div></td></tr>
                <?php endforeach; ?>
              </tbody></table></div>
              <?php headPagination($tab, $filters, 'page', $listPage, $pageCount); ?>
            <?php endif; ?>
          </section>
          <?php if ($profile): ?>
            <section class="panel" id="profile">
              <header class="panel-head"><div><h3><?= hh($profile['student']['fio']) ?></h3></div><span class="count"><?= hh($profile['student']['campus_name'] ?? 'Корпус не указан') ?> · <?= hh($profile['student']['group_name']) ?></span></header>
              <div class="head-profile">
                <?php foreach (['student', 'documents', 'education'] as $section): ?>
                  <details open><summary><?= hh($schema[$section][0]) ?></summary><?php headDetails($section, $profile[$section], $specialties, $section === 'education' ? [
                      'expulsion_date' => ['Дата отчисления', 'date'],
                      'expulsion_order_number' => ['Приказ об отчислении', 'text'],
                      'expulsion_reason' => ['Причина отчисления', 'text'],
                  ] : []); ?></details>
                <?php endforeach; ?>
                <?php foreach (SECRETARY_COLLECTION_TABLES as $section => $table): ?>
                  <details open><summary><?= hh($schema[$section][0]) ?></summary>
                    <?php if (!$profile[$section]): ?><p class="head-note">Сведения не указаны.</p><?php endif; ?>
                    <?php foreach ($profile[$section] as $row): ?><?php headDetails($section, $row, $specialties); ?><?php endforeach; ?>
                  </details>
                <?php endforeach; ?>
                <?php foreach (['military_legal', 'health', 'employment'] as $section): ?><details><summary><?= hh($schema[$section][0]) ?></summary><?php headDetails($section, $profile[$section], $specialties); ?></details><?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>
        <?php elseif ($tab === 'analytics'): ?>
          <div class="metrics">
            <div class="metric"><span>Все группы<?= $filters['campus'] !== '' ? ' корпуса' : '' ?></span><b id="head-overall-average"><?= headAverage($gradeSum, $gradeCount) ?></b><small><?= $gradeCount ?> числовых оценок</small></div>
            <div class="metric"><span><?= hh($groups[$filters['group']] ?? 'Выберите группу') ?></span><b id="head-selected-average"><?= $selectedAverage ? headAverage($selectedAverage['grade_sum'], $selectedAverage['grade_count']) : '—' ?></b><small><?= $selectedAverage ? hh($selectedAverage['grade_count']) . ' числовых оценок' : 'Для сравнения с общим результатом' ?></small></div>
            <div class="metric"><span>Групп в отчёте</span><b><?= count($averages) ?></b><small>По выбранным фильтрам</small></div>
          </div>
          <section class="panel">
            <header class="panel-head"><div><h3>Успеваемость по группам</h3></div></header>
            <p class="head-note">Средний балл = сумма оценок 2–5 / количество этих оценок. Пропуски показаны отдельно. Расчёт использует оценки за занятия за весь выбранный период. Итоговые аттестации отображаются в разделах «Оценки» и «Итоговые оценки».</p>
            <div class="head-scroll"><table class="head-table"><thead><tr><th>Группа</th><th>Корпус</th><th>Студентов</th><th>Числовых оценок</th><th>Пропусков</th><th>Средний балл</th></tr></thead><tbody>
              <?php foreach ($averages as $row): ?><tr data-group="<?= hh($row['id']) ?>" <?= (int) $row['id'] === $filters['group'] ? 'class="head-selected"' : '' ?>><th scope="row"><a href="<?= hh(headUrl('analytics', $filters, ['group' => $row['id']])) ?>"><?= hh($row['name']) ?></a></th><td><?= hh($row['campus_name'] ?? 'Не указан') ?></td><td><?= hh($row['students']) ?></td><td><?= hh($row['grade_count']) ?></td><td><?= hh($row['absences']) ?></td><td data-average><b><?= headAverage($row['grade_sum'], $row['grade_count']) ?></b></td></tr><?php endforeach; ?>
              <?php if (!$averages): ?><tr><td colspan="6">По выбранным фильтрам групп нет.</td></tr><?php endif; ?>
            </tbody></table></div>
          </section>
        <?php elseif (!$filters['group']): ?><p class="panel head-empty">В базе пока нет групп для просмотра оценок.</p>
        <?php else: ?>
          <section class="panel">
            <header class="panel-head"><div><h3><?= $tab === 'finals' ? 'Экзамены и зачёты' : ($filters['student'] ? hh($studentOptions[$filters['student']]) . ' · оценки за занятия' : 'Журнал группы ' . hh($groups[$filters['group']])) ?></h3></div><span class="count"><?= count($journal['lessons']) ?> из <?= $journal['total'] ?> <?= $tab === 'finals' ? 'столбцов' : 'занятий' ?></span></header>
            <?php if (!$shownStudents || !$journal['lessons']): ?><p class="head-empty"><?= !$shownStudents ? 'В выбранной группе студентов нет.' : 'За выбранный период занятий нет.' ?></p>
            <?php else: ?>
              <p class="head-note">В колонках указаны предмет, дата и номер пары. Наведите на заголовок для просмотра темы и преподавателя. <?= $tab === 'finals'
                ? 'Средний балл рассчитан по столбцам текущей страницы.'
                : 'Средний балл, число оценок и отметок «н» справа считаются по столбцам текущей страницы.' ?></p>
              <div class="head-scroll" tabindex="0" role="region" aria-label="Таблица оценок">
                <table class="head-table head-matrix"><thead><tr><th scope="col">Студент</th>
                  <?php foreach ($journal['lessons'] as $lesson): ?><th scope="col" title="<?= hh($lesson['topic_name'] . ' · ' . $lesson['teacher_name']) ?>"><b><?= hh($lesson['subject_name']) ?></b><small><?= $lesson['lesson_id'] === null
                    ? hh(((int) substr($lesson['date'], 5, 2) === 9 ? '1-й' : '2-й') . ' семестр · ' . substr($lesson['date'], 0, 4))
                    : hh(headDate($lesson['date'])) ?></small><small><?= $lesson['num_in_table'] === null ? '' : hh($lesson['num_in_table']) . ' пара · ' ?><?= hh($lesson['lesson_type'] ?? 'Вид не указан') ?></small></th><?php endforeach; ?>
                  <th scope="col">Средний балл</th></tr></thead><tbody>
                  <?php foreach ($shownStudents as $student): ?>
                    <?php $summary = $journal['summary'][$student['id']] ?? ['grade_sum' => 0, 'grade_count' => 0, 'absences' => 0]; ?>
                    <tr><th scope="row"><a href="<?= hh(headUrl('students', $filters, ['student' => $student['id']])) ?>#profile"><?= hh($student['fio']) ?></a></th>
                      <?php foreach ($journal['lessons'] as $lesson): ?><td><div class="head-grade-list marks-list">
                        <?php $marks = $journal['marks'][$student['id']][$lesson['column_key']] ?? []; ?>
                        <?php foreach ($marks as $mark): ?><span class="mark-entry"><span <?= cabGradeAttributes('grade', $mark['previous_grade']) ?>><?= hh($mark['grade']) ?></span></span><?php endforeach; ?>
                        <?php if (!$marks): ?><span class="muted">—</span><?php endif; ?>
                      </div></td><?php endforeach; ?>
                      <td><b><?= headAverage($summary['grade_sum'], $summary['grade_count']) ?></b><?php if ($tab !== 'finals'): ?><small><?= hh($summary['grade_count']) ?> оценок · <?= hh($summary['absences']) ?> н</small><?php endif; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody></table>
              </div>
              <?php headPagination($tab, $filters, 'lessons_page', $journal['page'], $journal['pages'], $tab === 'journal' ? ['attestation_page' => $attestations['page']] + $attestationQuery : []); ?>
            <?php endif; ?>
          </section>
          <?php if ($tab === 'journal'): ?>
          <section class="panel" id="attestations">
            <header class="panel-head"><div><h3>Аттестации студента и группы</h3></div></header>
            <form method="get" class="filter-toolbar head-filters head-attestation-filters" action="head.php#attestations">
              <input type="hidden" name="tab" value="journal">
              <input type="hidden" name="lessons_page" value="<?= $journal['page'] ?>">
              <?php foreach ($filters as $key => $value): ?>
                <input type="hidden" name="<?= hh($key) ?>" value="<?= hh($value) ?>">
              <?php endforeach; ?>
              <?php headSelect('att_kind', 'Вид аттестации', [''=>'Все виды'] + $kindNames, $attestationFilters['kind']); ?>
              <?php headSelect('att_subject', 'Предмет', [0=>'Все предметы'] + $subjects, $attestationFilters['subject']); ?>
              <?php headSelect('att_year', 'Учебный год', [0=>'Все годы'] + $years, $attestationFilters['year']); ?>
              <?php headSelect('att_semester', 'Семестр', $semesters, $attestationFilters['semester']); ?>
              <?php headSelect('att_month', 'Месяц', $months, $attestationFilters['month']); ?>
              <div class="head-links head-filter-actions">
                <button class="head-button" type="submit">Применить</button>
                <a class="head-button filter-reset" href="<?= hh(headUrl('journal', $filters, ['lessons_page'=>$journal['page']])) ?>#attestations">Сбросить фильтры</a>
              </div>
            </form>
            <?php if (!$attestations['entries']): ?><p class="head-empty">Итоговые отметки по выбранным фильтрам пока не выставлены.</p>
            <?php else: ?>
              <div class="head-scroll"><table class="head-table"><thead><tr><th>Студент</th><th>Предмет</th><th>Вид</th><th>Период</th><th>Группа при выставлении</th><th>Преподаватель</th><th>Отметка</th></tr></thead><tbody>
                <?php foreach ($attestations['entries'] as $entry): ?>
                  <?php $date = new DateTimeImmutable($entry['period_start']); ?>
                  <tr><th scope="row"><?= hh($entry['student_name']) ?></th><td><?= hh($entry['subject_name']) ?></td><td><?= hh($kindNames[$entry['kind']] ?? $entry['kind']) ?></td><td><?= $entry['kind'] === 'month' ? hh($date->format('m.Y')) : hh(((int) $date->format('n') === 9 ? '1-й' : '2-й') . ' семестр · ' . $date->format('Y')) ?></td><td><?= hh($entry['original_group']) ?></td><td><?= hh($entry['teacher_name']) ?></td><td><span <?= cabGradeAttributes('grade', $entry['kind'] === 'exam' ? $entry['previous_grade'] : null) ?>><?= hh($entry['grade']) ?></span></td></tr>
                <?php endforeach; ?>
              </tbody></table></div>
              <?php headPagination($tab, $filters, 'attestation_page', $attestations['page'], $attestations['pages'], ['lessons_page' => $journal['page']] + $attestationQuery); ?>
            <?php endif; ?>
          </section>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
