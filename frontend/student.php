<?php

$workspaceRole = 'student';
if (($_GET['tab'] ?? '') === 'group') {
    header('Location: student.php');
    exit;
}
if (in_array($_GET['tab'] ?? '', ['schedule', 'monthly', 'finals'], true)) {
    require __DIR__ . '/workspace.php';
    exit;
}

require_once __DIR__ . '/../api/cabinet.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$currentUser = getCurrentUser();

if (!$currentUser || $currentUser['role'] !== 'student') {
    header('Location: index.php');
    exit;
}

$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$studentName = $currentUser['name'];
$groupName = null;
$diaryMessage = 'Оценок пока нет.';
$subjects = [];
$gradesBySubjectAndDate = [];
$dates = [];

try {
    $pdo = connectDB();
    $statement = $pdo->prepare(<<<'SQL'
        WITH student_profile AS (
            SELECT MIN(id) AS id
            FROM public.students
            WHERE auth_id = :auth_id
            HAVING COUNT(*) = 1
        )
        SELECT st.fio, g.name AS group_name, BTRIM(s.name) AS subject_name,
               LOWER(BTRIM(s.name)) AS subject_key,
               TO_CHAR(l.date, 'YYYY-MM-DD') AS grade_date,
               e.grade, e.previous_grade, l.lesson_type
        FROM student_profile p
        JOIN public.students st ON st.id = p.id
        JOIN public.stud_groups g ON g.id = st.in_group
        LEFT JOIN (
            public.evaluations e
            JOIN public.lessons l ON l.id = e.lesson_id
            JOIN public.subjects s ON s.id = l.subject
        ) ON e.student_id = st.id AND NULLIF(BTRIM(e.grade), '') IS NOT NULL
        ORDER BY l.date, l.num_in_table, e.id
        SQL);
    $statement->execute(['auth_id' => $currentUser['id']]);
    $profile = $statement->fetch(PDO::FETCH_ASSOC);

    if ($profile === false) {
        $diaryMessage = 'Аккаунт должен быть связан с одним профилем студента.';
    } else {
        $studentName = $profile['fio'];
        $groupName = $profile['group_name'];

        do {
            if ($profile['grade'] !== null) {
                $key = $profile['subject_key'];
                $date = $profile['grade_date'];
                $subjects[$key] ??= $profile['subject_name'];
                $gradesBySubjectAndDate[$key][$date][] = $profile;
                $dates[$date] = true;
            }
        } while ($profile = $statement->fetch(PDO::FETCH_ASSOC));
    }
} catch (PDOException $error) {
    http_response_code(500);
    error_log('student.php diary query failed: ' . $error->getCode());
    $diaryMessage = 'Не удалось загрузить оценки. Попробуйте позже.';
}

asort($subjects, SORT_STRING);
$dates = array_keys($dates);
sort($dates, SORT_STRING);

$academicStartYear = static function (string $date): int {
    return (int) substr($date, 0, 4) - (substr($date, 5, 2) < '09' ? 1 : 0);
};
$firstAcademicYear = $academicStartYear($dates[0] ?? date('Y-m-d'));
$lastAcademicYear = $academicStartYear($dates[count($dates) - 1] ?? date('Y-m-d')) + 1;

// Месяцы учебного года (с сентября по июнь)
$academicMonths = [
    "09" => ["name" => "Сентябрь", "semester" => 1],
    "10" => ["name" => "Октябрь",  "semester" => 1],
    "11" => ["name" => "Ноябрь",   "semester" => 1],
    "12" => ["name" => "Декабрь",  "semester" => 1],
    "01" => ["name" => "Январь",   "semester" => 2],
    "02" => ["name" => "Февраль",  "semester" => 2],
    "03" => ["name" => "Март",     "semester" => 2],
    "04" => ["name" => "Апрель",   "semester" => 2],
    "05" => ["name" => "Май",      "semester" => 2],
    "06" => ["name" => "Июнь",     "semester" => 2],
];

$monthsShort = [
    "01" => "янв", "02" => "фев", "03" => "мар", "04" => "апр",
    "05" => "май", "06" => "июн", "09" => "сен", "10" => "окт",
    "11" => "ноя", "12" => "дек"
];


?>
<!doctype html>
<html lang="ru">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Контур — Кабинет студента</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('index.css'), ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('cabinet.css'), ENT_QUOTES, 'UTF-8') ?>"><script src="<?= htmlspecialchars(cabinetAsset('cabinet.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script></head>
  <body class="student">
    <div class="app-shell">
      <?php cabSidebar('student', ['name' => $studentName] + $currentUser, 'home', $_SESSION['csrf_token']); ?>

      <main class="content">
        <header class="topbar">
          <div>
            <span class="breadcrumb">ЛИЧНЫЙ КАБИНЕТ / СТУДЕНТ</span>
            <h1>Моя успеваемость</h1>
          </div>
          <div class="date-block">
            <span>УЧЕБНЫЙ ПЕРИОД</span>
            <b><?= $firstAcademicYear ?>–<?= $lastAcademicYear ?></b>
          </div>
        </header>

        <div class="page-stack">
          <!-- Панель фильтров: Семестр, Месяц, Дисциплина -->
          <div class="filter-toolbar"><button type="button" class="cab-button filter-reset">Сбросить фильтры</button>
            <div class="filter-group">
              <span>Семестр</span>
              <select id="semesterFilter" onchange="onSemesterChange()">
                <option value="">Все семестры</option>
                <option value="1">1-й семестр (сен — дек)</option>
                <option value="2">2-й семестр (янв — июн)</option>
              </select>
            </div>
            <div class="filter-group">
              <span>Месяц</span>
              <select id="monthFilter" onchange="filterTable()">
                <option value="">Все месяцы</option>
                <?php foreach ($academicMonths as $mNum => $mInfo): ?>
                  <option value="<?= $mNum ?>" data-semester="<?= $mInfo['semester'] ?>"><?= $mInfo['name'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group">
              <span>Дисциплина</span>
              <select id="subjectFilter" onchange="filterTable()">
                <option value="">Все предметы</option>
                <?php foreach ($subjects as $key => $subject): ?>
                  <option value="<?= htmlspecialchars((string) $key) ?>"><?= htmlspecialchars($subject) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <section class="panel table-panel">
            <header class="panel-head">
              <div><h3>Успеваемость</h3></div>
              <span class="count"><?= count($subjects) ?> дисциплин</span>
            </header>
            <div class="matrix-container">
              <table class="matrix-table" id="gradesTable">
                <thead>
                  <tr>
                    <th>Дисциплина</th>
                    <?php foreach ($dates as $date): 
                      $mCode = date("m", strtotime($date));
                      $dayNum = date("j", strtotime($date));
                      $monthShortName = $monthsShort[$mCode] ?? '';
                    ?>
                      <th data-date="<?= $date ?>" data-month="<?= $mCode ?>">
                        <div class="date-cell-header">
                          <?= $dayNum . ' ' . $monthShortName ?>
                          <small><?= date("Y", strtotime($date)) ?></small>
                        </div>
                      </th>
                    <?php endforeach; ?>
                    <th>Средний балл</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($subjects === []): ?>
                    <tr><td colspan="2" class="muted"><?= htmlspecialchars($diaryMessage) ?></td></tr>
                  <?php endif; ?>
                  <?php foreach ($subjects as $key => $subject): ?>
                    <tr data-subject="<?= htmlspecialchars((string) $key) ?>">
                      <td><b><?= htmlspecialchars($subject) ?></b></td>
                      <?php foreach ($dates as $date):
                          $monthCode = date("m", strtotime($date));
                          $cellGrades = $gradesBySubjectAndDate[$key][$date] ?? [];
                      ?>
                        <td data-month="<?= $monthCode ?>">
                          <?php if ($cellGrades === []): ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                          <span class="marks-list"><?php foreach ($cellGrades as $entry): ?>
                            <span class="mark-entry"><span <?= cabGradeAttributes('grade', $entry['lesson_type'] === 'Экзамен' ? $entry['previous_grade'] : null) ?> data-value="<?= ch($entry['grade']) ?>"><?= ch($entry['grade']) ?></span></span>
                          <?php endforeach; ?></span>
                        </td>
                      <?php endforeach; ?>
                      <td>
                        <b class="avg-val">—</b>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>
    </div>

    <script>

      // Логика связи фильтра Семестра и Месяцев
      function onSemesterChange() {
        const semVal = document.getElementById('semesterFilter').value;
        const monthSelect = document.getElementById('monthFilter');
        const options = monthSelect.querySelectorAll('option');

        // Сбрасываем выбор месяца при смене семестра, либо фильтруем доступные опции
        options.forEach(opt => {
          if (!opt.value) return;
          const optSem = opt.getAttribute('data-semester');
          if (!semVal || optSem === semVal) {
            opt.style.display = '';
          } else {
            opt.style.display = 'none';
          }
        });

        // Если текущий выбранный месяц не принадлежит выбранному семестру, сбрасываем на "Все месяцы" или первый доступный
        const currentSelectedOpt = monthSelect.options[monthSelect.selectedIndex];
        if (semVal && currentSelectedOpt.value && currentSelectedOpt.getAttribute('data-semester') !== semVal) {
          monthSelect.value = "";
        }

        filterTable();
      }

      // Основная фильтрация и пересчет среднего балла для видимых колонок
      function filterTable() {
        const selectedSemester = document.getElementById('semesterFilter').value;
        const selectedMonth = document.getElementById('monthFilter').value;
        const selectedSubject = document.getElementById('subjectFilter').value;
        
        const table = document.getElementById('gradesTable');
        const rows = table.querySelectorAll('tbody tr[data-subject]');
        const headers = table.querySelectorAll('thead th');

        // Определяем, какие месяцы подходят под выбранный семестр (если месяц не выбран явно)
        headers.forEach((th, index) => {
          if (index === 0 || index === headers.length - 1) return;
          const monthAttr = th.getAttribute('data-month');
          
          // Получаем семестр месяца из выпадающего списка
          const monthOpt = document.querySelector(`#monthFilter option[value="${monthAttr}"]`);
          const monthSem = monthOpt ? monthOpt.getAttribute('data-semester') : '';

          const cells = table.querySelectorAll(`tr td:nth-child(${index + 1})`);
          
          let showCol = true;
          if (selectedMonth) {
            if (monthAttr !== selectedMonth) showCol = false;
          } else if (selectedSemester) {
            if (monthSem !== selectedSemester) showCol = false;
          }

          if (showCol) {
            th.style.display = '';
            cells.forEach(c => c.style.display = '');
          } else {
            th.style.display = 'none';
            cells.forEach(c => c.style.display = 'none');
          }
        });

        // Фильтрация строк по предмету и расчет среднего балла ПО ВИДИМЫМ КОЛОНКАМ
        rows.forEach(row => {
          const rowSubject = row.getAttribute('data-subject');
          const matchSubject = (!selectedSubject || rowSubject === selectedSubject);
          
          if (matchSubject) {
            row.style.display = '';
            
            // Пересчитываем средний балл только по видимым ячейкам с оценками
            let sum = 0;
            let count = 0;
            
            headers.forEach((th, index) => {
              if (index === 0 || index === headers.length - 1) return;
              if (th.style.display !== 'none') {
                const cell = row.children[index];
                cell.querySelectorAll('.grade').forEach(grade => {
                  const value = grade.dataset.value.trim();
                  if (['2', '3', '4', '5'].includes(value)) {
                    sum += Number(value);
                    count++;
                  }
                });
              }
            });

            const avgEl = row.querySelector('.avg-val');
            avgEl.textContent = count > 0 ? (sum / count).toFixed(2) : '—';

          } else {
            row.style.display = 'none';
          }
        });
      }


      filterTable();
    </script>
  </body>
</html>