<?php
require_once __DIR__ . '/../api/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$currentUser = getCurrentUser();

if (!$currentUser || $currentUser['role'] !== 'student') {
    header('Location: index.php');
    exit;
}

$csrfToken = $_SESSION['csrf_token'] ?? '';

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
               e.grade
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
                $gradesBySubjectAndDate[$key][$date][] = $profile['grade'];
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

// Текущий месяц по умолчанию
$currentMonth = date("m");
if (!isset($academicMonths[$currentMonth])) {
    $currentMonth = "";
}
?>
<!doctype html>
<html lang="ru">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Контур — Кабинет студента</title>
    <link rel="stylesheet" href="./index.css" />
  </head>
  <body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand"><span class="brand-mark"><i></i><i></i><i></i></span><b>EDU=SPT</b></div>
        <div class="user-card">
          <span class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($studentName, 0, 1))) ?></span>
          <div>
            <b><?= htmlspecialchars($studentName) ?></b>
            <small>Студент<?= $groupName !== null ? ' (' . htmlspecialchars($groupName) . ')' : '' ?></small>
          </div>
        </div>
        <nav>
          <button class="active">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
            <span>Моя успеваемость</span>
          </button>
        </nav>
        <button class="logout" onclick="doLogout()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg>
          <span>Выйти</span>
        </button>
      </aside>

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
          <div class="filter-toolbar">
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
                  <option value="<?= $mNum ?>" data-semester="<?= $mInfo['semester'] ?>" <?= $mNum === $currentMonth ? 'selected' : '' ?>><?= $mInfo['name'] ?></option>
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
                          <?php foreach ($cellGrades as $grade): ?>
                            <span class="grade" style="margin: 0 auto;" data-value="<?= htmlspecialchars($grade) ?>"><?= htmlspecialchars($grade) ?></span>
                          <?php endforeach; ?>
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
      const CSRF_TOKEN = "<?= $csrfToken ?>";

      // Логика связи фильтра Семестра и Месяцев
      function onSemesterChange() {
        const semVal = document.getElementById('semesterFilter').value;
        const monthSelect = document.getElementById('monthFilter');
        const options = monthSelect.querySelectorAll('option');

        // Сбрасываем выбор месяца при смене семестра, либо фильтруем доступные опции
        let firstAvailable = "";
        options.forEach(opt => {
          if (!opt.value) return;
          const optSem = opt.getAttribute('data-semester');
          if (!semVal || optSem === semVal) {
            opt.style.display = '';
            if (!firstAvailable) firstAvailable = opt.value;
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
                  const val = Number(grade.dataset.value);
                  if (Number.isFinite(val)) {
                    sum += val;
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

      async function doLogout() {
        try {
          await fetch("index.php", {
            method: "POST",
            credentials: "same-origin",
            headers: {
              "Content-Type": "application/json",
              "Accept": "application/json",
              "X-CSRF-Token": CSRF_TOKEN,
            },
            body: JSON.stringify({ action: "logout" }),
          });
        } catch (_) {}
        window.location.href = "index.php";
      }

      // Инициализация фильтрации при загрузке (с выбранным текущим месяцем)
      document.addEventListener("DOMContentLoaded", filterTable);
    </script>
  </body>
</html>