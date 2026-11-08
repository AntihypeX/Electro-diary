<?php
require_once __DIR__ . '/../api/current-user.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ---- Обработка JSON-запросов (login / logout) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {

    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = (string) ($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if (!hash_equals($csrfToken, $token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF-токен неверен. Обновите страницу.']);
        exit;
    }

    $action = $input['action'] ?? '';

    if ($action === 'login') {
      $loginName = trim((string) ($input['login'] ?? ''));
      $password  = (string) ($input['password'] ?? '');

      if ($loginName === '' || $password === '') {
          echo json_encode(['ok' => false, 'error' => 'Укажите логин и пароль.']);
          exit;
      }

      if (login($loginName, $password)) {
          $user = getCurrentUser();
          
          // Определяем, куда перенаправить пользователя в зависимости от роли
          $redirectUrl = match ($user['role'] ?? null) {
              'student' => 'student.php',
              'teacher' => 'teacher.php',
              'secretary' => 'secretary.php',
              'head' => 'head.php',
              default => 'index.php',
          };

          echo json_encode([
              'ok' => true, 
              'user' => $user,
              'redirect' => $redirectUrl
          ]);
      } else {
          echo json_encode(['ok' => false, 'error' => 'Неверный логин или пароль.']);
      }
      exit;
    }

    if ($action === 'logout') {
        logout();
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Неизвестное действие.']);
    exit;
}

/* ---- Рендер страницы ---- */
$currentUser = getCurrentUser();
$cabinet = match ($currentUser['role'] ?? null) {
    'teacher' => 'teacher.php',
    'secretary' => 'secretary.php',
    'head' => 'head.php',
    default => null,
};
if ($cabinet !== null) {
    header('Location: ' . $cabinet);
    exit;
}
$userJson = $currentUser
    ? json_encode($currentUser, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
    : 'null';
?>
<!doctype html>
<html lang="ru">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#111111" />
    <title>Контур — учёт успеваемости</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('index.css'), ENT_QUOTES, 'UTF-8') ?>" />
  </head>
  <body>
    <div id="root"></div>
    <script>
      /* ====================== ДАННЫЕ (демо, клиентские) ====================== */
      const initialStudents = [
        { id: 1, name: "Алина Воронцова", group: "ИВТ-21", birthDate: "2004-03-14", phone: "+7 912 440-18-42", email: "vorontsova@edu.ru" },
        { id: 2, name: "Максим Крылов",   group: "ИВТ-21", birthDate: "2003-11-02", phone: "+7 903 177-25-09", email: "krylov@edu.ru" },
        { id: 3, name: "Дарья Лебедева",  group: "ИВТ-21", birthDate: "2004-07-19", phone: "+7 999 410-05-51", email: "lebedeva@edu.ru" },
        { id: 4, name: "Илья Соколов",    group: "ПИ-22",  birthDate: "2004-01-28", phone: "+7 916 214-82-11", email: "sokolov@edu.ru" },
        { id: 5, name: "Мария Белова",    group: "ПИ-22",  birthDate: "2004-09-08", phone: "+7 926 083-60-74", email: "belova@edu.ru" },
        { id: 6, name: "Кирилл Морозов",  group: "ПИ-22",  birthDate: "2003-12-17", phone: "+7 985 652-14-40", email: "morozov@edu.ru" },
      ];

      const initialGrades = [
        { id: 1,  studentId: 1, subject: "Базы данных",           value: 5, type: "Экзамен", date: "2026-01-22" },
        { id: 2,  studentId: 1, subject: "Программирование",      value: 5, type: "Текущая", date: "2026-02-10" },
        { id: 3,  studentId: 1, subject: "Дискретная математика", value: 4, type: "Текущая", date: "2026-02-14" },
        { id: 4,  studentId: 2, subject: "Базы данных",           value: 4, type: "Экзамен", date: "2026-01-22" },
        { id: 5,  studentId: 2, subject: "Программирование",      value: 3, type: "Текущая", date: "2026-02-10" },
        { id: 6,  studentId: 3, subject: "Базы данных",           value: 5, type: "Экзамен", date: "2026-01-22" },
        { id: 7,  studentId: 3, subject: "Программирование",      value: 4, type: "Текущая", date: "2026-02-10" },
        { id: 8,  studentId: 4, subject: "Базы данных",           value: 3, type: "Экзамен", date: "2026-01-22" },
        { id: 9,  studentId: 5, subject: "Программирование",      value: 5, type: "Текущая", date: "2026-02-12" },
        { id: 10, studentId: 6, subject: "Базы данных",           value: 4, type: "Экзамен", date: "2026-01-22" },
      ];

      const roleNames = {
        teacher:   "Преподаватель",
        head:      "Заведующий кафедрой",
        student:   "Студент",
        secretary: "Секретарь",
      };

      /* ====================== СОСТОЯНИЕ ====================== */
      const SERVER_USER = <?= $userJson ?>;
      const CSRF_TOKEN  = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

      var state = {
        user: SERVER_USER,
        page: "overview",
        students: initialStudents.slice(),
        grades: initialGrades.slice(),
        group: "ИВТ-21",
        studentModal: null,
        gradeModal: false,
        notice: "",
        noticeTimer: null,
      };

      /* ====================== ИКОНКИ ====================== */
      const ICONS = {
        grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>',
        users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        chart: '<path d="M3 3v18h18"/><path d="m7 16 4-5 4 3 5-7"/>',
        logout: '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        check: '<path d="m5 12 4 4L19 6"/>',
        edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/>',
        trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v6M14 11v6"/>',
        arrow: '<path d="m9 18 6-6-6-6"/>',
      };
      function icon(name, size = 20) {
        return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ""}</svg>`;
      }

      /* ====================== ХЕЛПЕРЫ ====================== */
      const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
      const fmtDate = (d) => new Date(d).toLocaleDateString("ru-RU");
      const today = () => new Date().toISOString().slice(0, 10);
      const initials = (name) => name.split(" ").map((x) => x[0]).slice(0, 2).join("");

      function flash(msg) {
        state.notice = msg;
        clearTimeout(state.noticeTimer);
        state.noticeTimer = setTimeout(() => { state.notice = ""; render(); }, 3200);
      }

      /* ====================== РЕНДЕР: ОБЩИЕ БЛОКИ ====================== */
      function renderBrand() {
        return `<div class="brand"><span class="brand-mark"><i></i><i></i><i></i></span><b>EDU=SPT</b></div>`;
      }
      function renderMetric(label, value, note) {
        return `<div class="metric"><span>${label}</span><b>${value}</b><small>${note}</small></div>`;
      }
      function renderPanelHead(index, title, action = "") {
        return `<header class="panel-head"><div><span>${index}</span><h3>${title}</h3></div>${action}</header>`;
      }

      /* ====================== РЕНДЕР: ЛОГИН ====================== */
      function renderLogin() {
        return `
          <div class="login-page">
            <header class="login-header">
              ${renderBrand()}
              <span>Система учёта успеваемости</span>
            </header>
            <main class="login-main">
              <section class="login-copy">
                <div class="eyebrow">Образовательная платформа</div>
                <h1>Успеваемость всегда под рукой</h1>
                <p>Единое пространство для преподавателей, студентов и сотрудников кафедры.</p>
                <div class="login-stats">
                  <div><b>4</b><span>корпуса</span></div>
                  <div><b>24/7</b><span>доступ к данным</span></div>
                  <div><b>25+</b><span>специальностей</span></div>
                </div>
              </section>
              <form class="login-card" onsubmit="handleLogin(event)">
                <div class="Text">
                  <h2>Добро пожаловать</h2>
                  <p>Введите данные своей учётной записи</p>
                </div>
                <div id="login-error"></div>
                <label>Логин
                  <input id="login-input" placeholder="Введите логин" autocomplete="username" />
                </label>
                <label>Пароль
                  <input id="password-input" type="password" placeholder="Введите пароль" autocomplete="current-password" />
                </label>
                <button class="primary login-button" type="submit">Войти в систему ${icon("arrow")}</button>
              </form>
            </main>
            <footer class="login-footer">
              <span>© 2026 Контур образования</span>
              <span>Защищённое соединение</span>
            </footer>
          </div>`;
      }

      /* ====================== NAV / TITLE ====================== */
      function getNav(role) {
        if (role === "teacher")   return [["overview","Обзор","grid"],["journal","Журнал оценок","book"]];
        if (role === "head")      return [["overview","Обзор","grid"],["students","Студенты","users"],["journal","Оценки","book"],["analytics","Аналитика","chart"]];
        if (role === "secretary") return [["overview","Обзор","grid"],["students","Студенты","users"]];
        return [["overview","Моя успеваемость","grid"],["journal","Все оценки","book"]];
      }
      function getTitle(role, page) {
        if (page === "overview")  return role === "student" ? "Моя успеваемость" : "Обзор системы";
        if (page === "journal")   return "Журнал оценок";
        if (page === "students")  return "Студенты";
        return "Аналитика";
      }

      /* ====================== OVERVIEW ====================== */
      function renderOverview() {
        const role = state.user.role;
        if (role === "student") return renderJournal();

        const { students, grades } = state;
        const average = grades.length ? grades.reduce((a, g) => a + g.value, 0) / grades.length : 0;
        const greeting =
          role === "teacher"   ? "Сегодня у вас 3 занятия и 2 группы ожидают оценки." :
          role === "secretary" ? "Актуальные сведения о контингенте студентов." :
                                 "Ключевые показатели кафедры за текущий семестр.";

        return `
          <div class="page-stack">
            <section class="hero-panel">
              <div>
                <span class="section-number">01</span>
                <h2>Добрый день.</h2>
                <p>${greeting}</p>
              </div>
              <div class="hero-shape"><i></i><i></i><i></i></div>
            </section>
            <section class="metrics">
              ${renderMetric("Студентов", String(students.length), "в 2 учебных группах")}
              ${renderMetric("Средний балл", average.toFixed(2), "по всем дисциплинам")}
              ${renderMetric("Оценок выставлено", String(grades.length), "за текущий семестр")}
            </section>
            <section class="split-grid">
              <div class="panel">
                ${renderPanelHead("02", role === "teacher" ? "Мои дисциплины" : "Последние действия")}
                <div class="subject-list">
                  ${["Базы данных","Программирование","Дискретная математика"].map((s, i) => `
                    <button onclick="goTo('journal')">
                      <span class="subject-no">0${i + 1}</span>
                      <div>
                        <b>${s}</b>
                        <small>${i === 0 ? "ИВТ-21 · 24 студента" : "ПИ-22 · 21 студент"}</small>
                      </div>
                      ${icon("arrow")}
                    </button>`).join("")}
                </div>
              </div>
              <div class="panel">
                ${renderPanelHead("03", "Срез успеваемости")}
                <div class="grade-bars">
                  ${[5,4,3,2].map((v) => {
                    const count = grades.filter((g) => g.value === v).length;
                    return `<div><span>${v}</span><div><i style="width:${Math.max(8, count * 18)}%"></i></div><b>${count}</b></div>`;
                  }).join("")}
                </div>
              </div>
            </section>
          </div>`;
      }

      /* ====================== STUDENTS ====================== */
      function renderStudentsPage() {
        const role = state.user.role;
        const { group } = state;
        const shown = state.students.filter((s) => s.group === group);

        return `
          <div class="page-stack">
            <div class="toolbar">
              <div class="group-switch">
                <span>Группа</span>
                <select onchange="setGroup(this.value)">
                  <option ${group === "ИВТ-21" ? "selected" : ""}>ИВТ-21</option>
                  <option ${group === "ПИ-22"  ? "selected" : ""}>ПИ-22</option>
                </select>
              </div>
              ${role === "secretary" ? `<button class="primary" onclick="openNewStudent()">${icon("plus")}Добавить студента</button>` : ""}
            </div>
            <section class="panel table-panel">
              ${renderPanelHead("01", `Состав группы ${group}`, `<span class="count">${shown.length} человек</span>`)}
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>№</th><th>Студент</th><th>Дата рождения</th><th>Контакты</th>
                      ${role === "secretary" ? "<th></th>" : ""}
                    </tr>
                  </thead>
                  <tbody>
                    ${shown.map((s, i) => `
                      <tr>
                        <td class="muted">${String(i + 1).padStart(2, "0")}</td>
                        <td><b>${esc(s.name)}</b><small>${esc(s.email)}</small></td>
                        <td>${fmtDate(s.birthDate)}</td>
                        <td>${esc(s.phone)}</td>
                        ${role === "secretary" ? `
                          <td class="actions">
                            <button aria-label="Изменить" onclick="openEditStudent(${s.id})">${icon("edit", 17)}</button>
                            <button aria-label="Удалить" onclick="deleteStudent(${s.id})">${icon("trash", 17)}</button>
                          </td>` : ""}
                      </tr>`).join("")}
                  </tbody>
                </table>
              </div>
            </section>
          </div>`;
      }

      /* ====================== JOURNAL ====================== */
      function renderJournal() {
        const role = state.user.role;
        const isStudent = role === "student";
        const { students, grades, group } = state;

        const myRecord = isStudent
          ? (students.find((s) => s.name === state.user.name) || students.find((s) => s.id === 1))
          : null;
        const shownStudents = isStudent
          ? (myRecord ? [myRecord] : [])
          : students.filter((s) => s.group === group);
        const shownGrades = grades.filter((g) => shownStudents.some((s) => s.id === g.studentId));

        return `
          <div class="page-stack">
            ${!isStudent ? `
              <div class="toolbar">
                <div class="group-switch">
                  <span>Группа</span>
                  <select onchange="setGroup(this.value)">
                    <option ${group === "ИВТ-21" ? "selected" : ""}>ИВТ-21</option>
                    <option ${group === "ПИ-22"  ? "selected" : ""}>ПИ-22</option>
                  </select>
                </div>
                ${role === "teacher" ? `<button class="primary" onclick="openGradeModal()">${icon("plus")}Выставить оценку</button>` : ""}
              </div>` : ""}
            <section class="metrics compact">
              ${renderMetric("Средний балл", (shownGrades.reduce((a, g) => a + g.value, 0) / Math.max(1, shownGrades.length)).toFixed(2), "за весь период")}
              ${renderMetric("Пятёрок", String(shownGrades.filter((g) => g.value === 5).length), "отличных оценок")}
              ${renderMetric("Экзаменов", String(shownGrades.filter((g) => g.type === "Экзамен").length), "итоговых работ")}
            </section>
            <section class="panel table-panel">
              ${renderPanelHead("01", isStudent ? "Все мои оценки" : `Оценки группы ${group}`, `<span class="count">${shownGrades.length} записей</span>`)}
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Дата</th>
                      ${!isStudent ? "<th>Студент</th>" : ""}
                      <th>Дисциплина</th><th>Тип работы</th><th>Оценка</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${shownGrades.map((g) => {
                      const st = students.find((s) => s.id === g.studentId);
                      return `
                        <tr>
                          <td class="muted">${fmtDate(g.date)}</td>
                          ${!isStudent ? `<td><b>${esc(st ? st.name : "")}</b></td>` : ""}
                          <td>${esc(g.subject)}</td>
                          <td><span class="tag ${g.type === "Экзамен" ? "dark" : ""}">${g.type}</span></td>
                          <td><span class="grade">${g.value}</span></td>
                        </tr>`;
                    }).join("")}
                  </tbody>
                </table>
              </div>
            </section>
          </div>`;
      }

      /* ====================== ANALYTICS ====================== */
      function renderAnalytics() {
        const { students, grades } = state;
        const groups = ["ИВТ-21", "ПИ-22"];
        const avg = (ids) => {
          const x = grades.filter((g) => ids.includes(g.studentId));
          return x.reduce((a, g) => a + g.value, 0) / Math.max(1, x.length);
        };

        return `
          <div class="page-stack">
            <section class="hero-panel analytics-hero">
              <div>
                <span class="section-number">АНАЛИТИКА / 2026</span>
                <h2>${avg(students.map((s) => s.id)).toFixed(2)}</h2>
                <p>Средний балл всех групп кафедры</p>
              </div>
              <div class="ring"><span>87%</span><small>успеваемость</small></div>
            </section>
            <section class="split-grid">
              <div class="panel">
                ${renderPanelHead("01", "Средний балл по группам")}
                <div class="group-rating">
                  ${groups.map((gr, i) => {
                    const value = avg(students.filter((s) => s.group === gr).map((s) => s.id));
                    return `
                      <div>
                        <span>0${i + 1}</span>
                        <b>${gr}</b>
                        <div><i style="width:${(value / 5) * 100}%"></i></div>
                        <strong>${value.toFixed(2)}</strong>
                      </div>`;
                  }).join("")}
                </div>
              </div>
              <div class="panel">
                ${renderPanelHead("02", "Экзаменационные оценки")}
                <div class="donut-wrap">
                  <div class="donut"></div>
                  <div class="legend">
                    ${[5,4,3,2].map((v) => `
                      <div>
                        <i class="c${v}"></i>
                        <span>Оценка ${v}</span>
                        <b>${grades.filter((g) => g.type === "Экзамен" && g.value === v).length}</b>
                      </div>`).join("")}
                  </div>
                </div>
              </div>
            </section>
          </div>`;
      }

      /* ====================== МОДАЛКИ ====================== */
      function renderStudentForm() {
        const editing = state.studentModal;
        const isNew = editing === "new";
        const s = isNew
          ? { id: 0, name: "", group: "ИВТ-21", birthDate: "", phone: "", email: "" }
          : editing;

        return `
          <div class="modal-backdrop" onmousedown="if(event.target===this)closeStudentModal()">
            <div class="modal">
              <header>
                <div><span>СТУДЕНТ</span><h2>${isNew ? "Новый студент" : "Изменение данных"}</h2></div>
                <button onclick="closeStudentModal()" aria-label="Закрыть">×</button>
              </header>
              <form class="form" onsubmit="handleStudentSubmit(event)">
                <div id="student-form-error"></div>
                <label>ФИО *
                  <input name="name" value="${esc(s.name)}" placeholder="Фамилия Имя Отчество" />
                </label>
                <div class="form-row">
                  <label>Группа *
                    <select name="group">
                      <option ${s.group === "ИВТ-21" ? "selected" : ""}>ИВТ-21</option>
                      <option ${s.group === "ПИ-22"  ? "selected" : ""}>ПИ-22</option>
                    </select>
                  </label>
                  <label>Дата рождения *
                    <input name="birthDate" type="date" value="${esc(s.birthDate)}" max="${today()}" />
                  </label>
                </div>
                <label>Электронная почта *
                  <input name="email" type="email" value="${esc(s.email)}" placeholder="student@edu.ru" />
                </label>
                <label>Телефон
                  <input name="phone" value="${esc(s.phone)}" placeholder="+7 900 000-00-00" />
                </label>
                <div class="form-actions">
                  <button type="button" onclick="closeStudentModal()">Отмена</button>
                  <button class="primary" type="submit">Сохранить данные</button>
                </div>
              </form>
            </div>
          </div>`;
      }

      function renderGradeForm() {
        return `
          <div class="modal-backdrop" onmousedown="if(event.target===this)closeGradeModal()">
            <div class="modal">
              <header>
                <div><span>ЖУРНАЛ</span><h2>Выставить оценку</h2></div>
                <button onclick="closeGradeModal()" aria-label="Закрыть">×</button>
              </header>
              <form class="form" onsubmit="handleGradeSubmit(event)">
                <div id="grade-form-error"></div>
                <label>Студент
                  <select name="studentId">
                    ${state.students.map((s) => `<option value="${s.id}">${esc(s.name)} — ${esc(s.group)}</option>`).join("")}
                  </select>
                </label>
                <label>Дисциплина
                  <select name="subject">
                    <option>Базы данных</option>
                    <option>Программирование</option>
                    <option>Дискретная математика</option>
                  </select>
                </label>
                <div class="form-row">
                  <label>Оценка (2–5)
                    <input name="value" type="number" min="2" max="5" step="1" value="5" />
                  </label>
                  <label>Дата
                    <input name="date" type="date" value="${today()}" max="${today()}" />
                  </label>
                </div>
                <label>Тип работы
                  <select name="type">
                    <option>Текущая</option>
                    <option>Экзамен</option>
                  </select>
                </label>
                <div class="form-actions">
                  <button type="button" onclick="closeGradeModal()">Отмена</button>
                  <button class="primary" type="submit">Сохранить оценку</button>
                </div>
              </form>
            </div>
          </div>`;
      }

      /* ====================== APP ====================== */
      function renderApp() {
        const user = state.user;
        const nav = getNav(user.role);
        const title = getTitle(user.role, state.page);

        let pageHtml = "";
        if (state.page === "overview")   pageHtml = renderOverview();
        else if (state.page === "students") pageHtml = renderStudentsPage();
        else if (state.page === "journal")  pageHtml = renderJournal();
        else if (state.page === "analytics") pageHtml = renderAnalytics();

        return `
          <div class="app-shell">
            <aside class="sidebar">
              ${renderBrand()}
              <div class="user-card">
                <span class="avatar">${esc(initials(user.name))}</span>
                <div>
                  <b>${esc(user.name)}</b>
                  <small>${roleNames[user.role] || user.role}</small>
                </div>
              </div>
              <nav>
                ${nav.map(([id, label, ic]) => `
                  <button class="${state.page === id ? "active" : ""}" onclick="goTo('${id}')">
                    ${icon(ic)}<span>${label}</span>
                  </button>`).join("")}
              </nav>
              <button class="logout" onclick="doLogout()">
                ${icon("logout")}<span>Выйти</span>
              </button>
            </aside>
            <main class="content">
              <header class="topbar">
                <div>
                  <span class="breadcrumb">УЧЕБНЫЙ ПОРТАЛ / ${title.toUpperCase()}</span>
                  <h1>${title}</h1>
                </div>
                <div class="date-block">
                  <span>УЧЕБНЫЙ ГОД</span>
                  <b>2026 / 2026</b>
                </div>
              </header>
              ${state.notice ? `
                <div class="toast">
                  ${icon("check")}
                  <div><b>Готово</b><span>${esc(state.notice)}</span></div>
                </div>` : ""}
              ${pageHtml}
            </main>
            ${state.studentModal ? renderStudentForm() : ""}
            ${state.gradeModal   ? renderGradeForm()   : ""}
          </div>`;
      }

      /* ====================== ГЛАВНЫЙ РЕНДЕР ====================== */
      function render() {
        const root = document.getElementById("root");
        if (!state.user) {
          root.innerHTML = renderLogin();
          const input = document.getElementById("login-input");
          if (input) input.focus();
        } else {
          root.innerHTML = renderApp();
        }
      }

      /* ====================== АВТОРИЗАЦИЯ ====================== */
      async function handleLogin(e) {
        e.preventDefault();
        const loginName = (document.getElementById("login-input").value || "").trim();
        const password  = document.getElementById("password-input").value || "";
        const errEl     = document.getElementById("login-error");
        errEl.innerHTML = "";

        try {
          const resp = await fetch("index.php", {
            method: "POST",
            credentials: "same-origin",
            headers: {
              "Content-Type": "application/json",
              "Accept": "application/json",
              "X-CSRF-Token": CSRF_TOKEN,
            },
            body: JSON.stringify({ action: "login", login: loginName, password }),
          });
          const data = await resp.json();
          if (!data.ok) {
            errEl.innerHTML = `<div class="alert error">${esc(data.error || "Ошибка входа")}</div>`;
            return;
          }
          
          // Если сервер прислал адрес для перенаправления (например, student.php), переходим на него
          if (data.redirect) {
            window.location.href = data.redirect;
            return;
          }

          state.user = data.user;
          state.page = "overview";
          render();
        } catch (err) {
          errEl.innerHTML = '<div class="alert error">Сетевая ошибка. Попробуйте ещё раз.</div>';
        }
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
        } catch (_) { /* игнорируем сетевые ошибки */ }

        state.user = null;
        state.notice = "";
        clearTimeout(state.noticeTimer);
        render();
      }

      /* ====================== ОСТАЛЬНЫЕ ДЕЙСТВИЯ ====================== */
      function goTo(page) { state.page = page; render(); }
      function setGroup(g) { state.group = g; render(); }

      function openNewStudent() { state.studentModal = "new"; render(); }
      function openEditStudent(id) {
        const s = state.students.find((x) => x.id === id);
        if (s) { state.studentModal = s; render(); }
      }
      function closeStudentModal() { state.studentModal = null; render(); }

      function deleteStudent(id) {
        const s = state.students.find((x) => x.id === id);
        if (!s) return;
        if (!confirm(`Удалить студента «${s.name}»?`)) return;
        state.students = state.students.filter((x) => x.id !== id);
        flash("Данные студента удалены");
        render();
      }

      function openGradeModal()  { state.gradeModal = true;  render(); }
      function closeGradeModal() { state.gradeModal = false; render(); }

      function handleStudentSubmit(e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        const name      = (fd.get("name") || "").toString().trim();
        const group     = fd.get("group").toString();
        const birthDate = (fd.get("birthDate") || "").toString();
        const email     = (fd.get("email") || "").toString().trim();
        const phone     = (fd.get("phone") || "").toString().trim();
        const errEl     = document.getElementById("student-form-error");

        if (!name || !birthDate || !email) {
          errEl.innerHTML = '<div class="alert error">Заполните обязательные поля.</div>';
          return;
        }
        if (new Date(birthDate) >= new Date()) {
          errEl.innerHTML = '<div class="alert error">Дата рождения должна быть раньше текущей даты.</div>';
          return;
        }

        const editing = state.studentModal;
        if (editing === "new") {
          state.students = state.students.concat({ id: Date.now(), name, group, birthDate, phone, email });
          flash("Студент добавлен в группу");
        } else {
          state.students = state.students.map((s) =>
            s.id === editing.id ? { ...s, name, group, birthDate, phone, email } : s
          );
          flash("Данные студента обновлены");
        }
        state.studentModal = null;
        render();
      }

      function handleGradeSubmit(e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        const studentId = Number(fd.get("studentId"));
        const subject   = fd.get("subject").toString();
        const value     = Number(fd.get("value"));
        const type      = fd.get("type").toString();
        const date      = (fd.get("date") || "").toString();
        const errEl     = document.getElementById("grade-form-error");

        if (!Number.isInteger(value) || value < 2 || value > 5) {
          errEl.innerHTML = '<div class="alert error">Оценка должна быть целым числом от 2 до 5.</div>';
          return;
        }
        if (!date) {
          errEl.innerHTML = '<div class="alert error">Укажите дату оценки.</div>';
          return;
        }

        state.grades = state.grades.concat({ id: Date.now(), studentId, subject, value, type, date });
        state.gradeModal = false;
        flash("Оценка сохранена в журнале");
        render();
      }

      /* ====================== СТАРТ ====================== */
      render();
    </script>
  </body>
</html>