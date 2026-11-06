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

// Статические демо-данные для студента
$students = [
    1 => ["id" => 1, "name" => "Алина Воронцова", "group" => "ИВТ-21", "birthDate" => "2004-03-14", "phone" => "+7 912 440-18-42", "email" => "vorontsova@edu.ru"],
    2 => ["id" => 2, "name" => "Максим Крылов",   "group" => "ИВТ-21", "birthDate" => "2003-11-02", "phone" => "+7 903 177-25-09", "email" => "krylov@edu.ru"],
    3 => ["id" => 3, "name" => "Дарья Лебедева",  "group" => "ИВТ-21", "birthDate" => "2004-07-19", "phone" => "+7 999 410-05-51", "email" => "lebedeva@edu.ru"],
];

$myRecord = $students[$currentUser['id'] ?? 1] ?? $students[1];

// Расширенный набор оценок за учебный 2026-2027 год (с сентября по июнь)
$allGrades = [
    // 2026 год (Сентябрь - декабрь -> 1 семестр)
    ["studentId" => 1, "subject" => "Алгебра и геометрия",  "value" => 5, "type" => "Текущая", "date" => "2026-09-10"],
    ["studentId" => 1, "subject" => "Базы данных",           "value" => 5, "type" => "Текущая", "date" => "2026-09-18"],
    ["studentId" => 1, "subject" => "Математический анализ", "value" => 4, "type" => "Текущая", "date" => "2026-10-05"],
    ["studentId" => 1, "subject" => "Физика",                "value" => 4, "type" => "Текущая", "date" => "2026-10-15"],
    ["studentId" => 1, "subject" => "Программирование",      "value" => 5, "type" => "Экзамен", "date" => "2026-11-20"],
    ["studentId" => 1, "subject" => "Архитектура ЭВМ",       "value" => 4, "type" => "Текущая", "date" => "2026-12-10"],

    // 2027 год (Январь - июнь -> 2 семестр)
    ["studentId" => 1, "subject" => "Базы данных",           "value" => 5, "type" => "Экзамен", "date" => "2027-01-15"],
    ["studentId" => 1, "subject" => "Математический анализ", "value" => 4, "type" => "Экзамен", "date" => "2027-01-20"],
    ["studentId" => 1, "subject" => "Алгебра и геометрия",  "value" => 5, "type" => "Экзамен", "date" => "2027-01-25"],
    ["studentId" => 1, "subject" => "Программирование",      "value" => 5, "type" => "Текущая", "date" => "2027-02-10"],
    ["studentId" => 1, "subject" => "Дискретная математика", "value" => 4, "type" => "Текущая", "date" => "2027-02-14"],
    ["studentId" => 1, "subject" => "Физика",                "value" => 3, "type" => "Текущая", "date" => "2027-02-18"],
    ["studentId" => 1, "subject" => "Базы данных",           "value" => 4, "type" => "Текущая", "date" => "2027-03-05"],
    ["studentId" => 1, "subject" => "Программирование",      "value" => 4, "type" => "Экзамен", "date" => "2027-03-20"],
    ["studentId" => 1, "subject" => "Дискретная математика", "value" => 5, "type" => "Текущая", "date" => "2027-03-25"],
    ["studentId" => 1, "subject" => "Архитектура ЭВМ",       "value" => 4, "type" => "Текущая", "date" => "2027-04-02"],
    ["studentId" => 1, "subject" => "Алгебра и геометрия",  "value" => 5, "type" => "Текущая", "date" => "2027-04-12"],
    ["studentId" => 1, "subject" => "Физика",                "value" => 5, "type" => "Экзамен", "date" => "2027-05-18"],
];

$myGrades = array_filter($allGrades, fn($g) => $g['studentId'] === $myRecord['id']);
$allSubjects = array_unique(array_column($myGrades, 'subject'));
sort($allSubjects);

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
    $currentMonth = "03"; // Если запуск летом (июль/август), по умолчанию ставим март для примера
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
          <span class="avatar"><?= mb_strtoupper(mb_substr($myRecord['name'], 0, 1)) ?></span>
          <div>
            <b><?= htmlspecialchars($myRecord['name']) ?></b>
            <small>Студент (<?= htmlspecialchars($myRecord['group']) ?>)</small>
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
            <span>УЧЕБНЫЙ ГОД</span>
            <b>2026–2027</b>
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
                <?php foreach ($allSubjects as $subj): ?>
                  <option value="<?= htmlspecialchars($subj) ?>"><?= htmlspecialchars($subj) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <?php
          $dates = array_unique(array_column($myGrades, 'date'));
          sort($dates);
          ?>

          <section class="panel table-panel">
            <header class="panel-head">
              <div><h3>Успеваемость</h3></div>
              <span class="count"><?= count($allSubjects) ?> дисциплин</span>
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
                  <?php foreach ($allSubjects as $subject): ?>
                    <tr data-subject="<?= htmlspecialchars($subject) ?>">
                      <td><b><?= htmlspecialchars($subject) ?></b></td>
                      <?php 
                      $dateGradesMap = [];
                      foreach ($myGrades as $g) {
                          if ($g['subject'] === $subject) {
                              $dateGradesMap[$g['date']] = $g['value'];
                          }
                      }
                      foreach ($dates as $date): 
                          $monthCode = date("m", strtotime($date));
                          $gradeVal = $dateGradesMap[$date] ?? null;
                      ?>
                        <td data-month="<?= $monthCode ?>">
                          <?= $gradeVal !== null ? '<span class="grade" style="margin: 0 auto;" data-value="' . $gradeVal . '">' . $gradeVal . '</span>' : '<span class="muted">—</span>' ?>
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
        const rows = table.querySelectorAll('tbody tr');
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
                const gradeSpan = cell.querySelector('.grade');
                if (gradeSpan) {
                  const val = parseFloat(gradeSpan.getAttribute('data-value'));
                  if (!isNaN(val)) {
                    sum += val;
                    count++;
                  }
                }
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