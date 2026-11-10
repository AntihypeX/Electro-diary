<?php

$workspaceRole = 'secretary';
if (in_array($_GET['tab'] ?? '', ['groups', 'schedule', 'statements'], true)) {
    require __DIR__ . '/workspace.php';
    exit;
}

require_once __DIR__ . '/../api/cabinet.php';
require_once __DIR__ . '/../api/secretary.php';

header('Cache-Control: no-store');
$currentUser = getCurrentUser();
if (($currentUser['role'] ?? null) !== 'secretary') {
    header('Location: index.php');
    exit;
}
$db = connectDB();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$tab = ($_GET['tab'] ?? '') === 'enroll' ? 'enroll' : 'students';
$error = '';
$fatal = '';
$values = null;
$selected = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$fields = secretaryFields();
$formToken = '';
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Novosibirsk')))->format('Y-m-d');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $token = $_POST['csrf'] ?? null;
            if (!is_string($token) || !hash_equals($csrf, $token)) throw new SecretaryError('Сессия устарела. Обновите страницу.');
            if (($_POST['action'] ?? '') === 'logout') {
                logout();
                header('Location: index.php');
                exit;
            }
            $formToken = is_string($_POST['form_token'] ?? null) ? $_POST['form_token'] : '';
            if (!is_string($formToken) || !isset($_SESSION['secretary_forms'][$formToken])) throw new SecretaryError('Срок действия формы истёк. Откройте карточку заново.');
            $ticket = $_SESSION['secretary_forms'][$formToken];
            if (isset($ticket['result'])) {
                header('Location: secretary.php?id=' . $ticket['result']);
                exit;
            }
            $action = $_POST['action'] ?? '';
            if ($ticket['action'] !== $action || (string) $ticket['id'] !== (string) ($_POST['id'] ?? 0)) {
                throw new SecretaryError('Некорректное действие формы.');
            }
            if (($_POST['form_complete'] ?? '') !== '1') throw new SecretaryError('Форма получена не полностью. Проверьте размер запроса и повторите отправку.');
            $db->beginTransaction();
            $result = $action === 'expel' ? secExpel($db, $_POST) : secSave($db, $_POST, $action === 'enroll');
            $db->commit();
            $_SESSION['secretary_forms'][$formToken]['result'] = $result['id'];
            $_SESSION['secretary_notice'] = [
                'message' => match ($action) {
                    'enroll' => 'Студент зачислен. Учётная запись создана.',
                    'expel' => 'Студент отчислен. Сведения сохранены в карточке.',
                    default => 'Личные данные студента сохранены.',
                },
                'credentials' => $result['credentials'],
            ];
            header('Location: secretary.php?id=' . $result['id']);
            exit;
        } catch (SecretaryError $exception) {
            if ($db->inTransaction()) $db->rollBack();
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Secretary save: ' . $exception->getMessage());
            $error = 'Не удалось сохранить карточку. Проверьте выбранные значения и повторите попытку.';
        }
        if (in_array($_POST['action'] ?? '', ['enroll', 'edit'], true)) {
            $values = $_POST;
            unset($values['password']);
            foreach (secretaryFields() as $section => $definition) {
                if (!is_array($values[$section] ?? null)) $values[$section] = [];
            }
            if (!is_array($values['addresses'] ?? null)) $values['addresses'] = [];
            foreach (['registered', 'residential'] as $type) {
                if (!is_array($values['addresses'][$type] ?? null)) $values['addresses'][$type] = [];
            }
            foreach (['version', 'group', 'campus', 'login'] as $key) {
                if (!is_scalar($values[$key] ?? '')) $values[$key] = '';
            }
            $tab = $_POST['action'] === 'enroll' ? 'enroll' : 'students';
            $selected = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        }
    }

    $campuses = secQuery($db, 'SELECT id, name FROM public.campuses ORDER BY name, id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $groups = secQuery($db, 'SELECT id, name, campus_id FROM public.stud_groups ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);
    $specialties = secQuery($db, "SELECT id, code || ' · ' || name AS name FROM public.education_specialties ORDER BY code, name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $campusFilter = is_string($_GET['campus'] ?? null) ? $_GET['campus'] : '';
    if ($campusFilter !== '-1' && !isset($campuses[$campusFilter])) $campusFilter = '';
    $groupFilter = (int) (filter_var($_GET['group'] ?? null, FILTER_VALIDATE_INT) ?: 0);
    $filteredGroups = array_filter($groups, fn ($g) => $campusFilter === '' || (string) ($g['campus_id'] ?? '-1') === $campusFilter);
    if (!in_array($groupFilter, array_map(fn ($g) => (int) $g['id'], $filteredGroups), true)) $groupFilter = 0;
    $where = [];
    $parameters = [];
    if ($campusFilter !== '') {
        if ($campusFilter === '-1') $where[] = 'g.campus_id IS NULL';
        else { $where[] = 'g.campus_id = ?'; $parameters[] = $campusFilter; }
    }
    if ($groupFilter) { $where[] = 's.in_group = ?'; $parameters[] = $groupFilter; }
    $scope = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $studentOptions = secQuery($db, 'SELECT s.id, s.fio FROM public.students s JOIN public.stud_groups g ON g.id = s.in_group' . $scope . ' ORDER BY public.diary_name_sort(s.fio) COLLATE "C", s.fio, s.id', $parameters)->fetchAll(PDO::FETCH_KEY_PAIR);
    $studentFilter = (int) (filter_var($_GET['student'] ?? null, FILTER_VALIDATE_INT) ?: 0);
    if (!isset($studentOptions[$studentFilter])) $studentFilter = 0;
    if ($studentFilter) { $where[] = 's.id = ?'; $parameters[] = $studentFilter; }
    $status = in_array($_GET['status'] ?? '', ['active', 'expelled', 'all'], true) ? $_GET['status'] : 'active';
    if ($status === 'active') $where[] = 'e.expulsion_date IS NULL';
    if ($status === 'expelled') $where[] = 'e.expulsion_date IS NOT NULL';
    $from = ' FROM public.students s JOIN public.stud_groups g ON g.id = s.in_group
        LEFT JOIN public.campuses c ON c.id = g.campus_id
        LEFT JOIN public.student_education e ON e.student_id = s.id';
    $scope = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = (int) secQuery($db, 'SELECT COUNT(*)' . $from . $scope, $parameters)->fetchColumn();
    $pages = max(1, (int) ceil($count / 30));
    $page = min($pages, max(1, (int) (filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT) ?: 1)));
    $students = secQuery($db, 'SELECT s.id, s.fio, s.birth_date, s.phone, g.name AS group_name,
        c.name AS campus_name, e.expulsion_date' . $from . $scope . ' ORDER BY public.diary_name_sort(s.fio) COLLATE "C", s.fio, s.id LIMIT 30 OFFSET ' . (($page - 1) * 30), $parameters)->fetchAll(PDO::FETCH_ASSOC);

    $persisted = $selected ? secLoad($db, $selected) : null;
    if ($values === null) {
        $values = $tab === 'enroll' ? ['education' => ['admission_date' => $today]] : $persisted;
    }
    $action = $tab === 'enroll' ? 'enroll' : 'edit';
    if ($values !== null && !isset($_SESSION['secretary_forms'][$formToken])) {
        $formToken = bin2hex(random_bytes(24));
        $_SESSION['secretary_forms'][$formToken] = ['action' => $action, 'id' => $action === 'enroll' ? 0 : $selected];
    }
    $expelToken = '';
    if ($persisted && empty($persisted['education']['expulsion_date'])) {
        $expelToken = bin2hex(random_bytes(24));
        $_SESSION['secretary_forms'][$expelToken] = ['action' => 'expel', 'id' => $selected];
    }
    $_SESSION['secretary_forms'] = array_slice($_SESSION['secretary_forms'] ?? [], -30, null, true);
} catch (SecretaryError $exception) {
    $fatal = $exception->getMessage();
} catch (PDOException $exception) {
    error_log('Secretary load: ' . $exception->getMessage());
    $fatal = in_array($exception->getCode(), ['42P01', '42703'], true)
        ? 'Для кабинета необходимо применить миграции 01-student-profile.sql и 02-secretary.sql.'
        : 'Не удалось загрузить данные. Обновите страницу.';
}
$notice = $_SESSION['secretary_notice'] ?? null;
unset($_SESSION['secretary_notice']);
session_write_close();

function sh(mixed $value): string { return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function secRenderFields(string $section, string $prefix, array $values, array $specialties, bool $admissionRequired = false): void
{
    foreach (secretaryFields()[$section][1] as $key => $field) {
        [$label, $type] = $field;
        $value = $values[$key] ?? '';
        if ($type === 'bool') {
            $value = match ($value) { true, 't', 'true', 1, '1' => '1', false, 'f', 'false', 0, '0' => '0', default => '' };
        }
        if (!is_scalar($value) && $value !== null) $value = '';
        if ($type === 'tel') $value = secPhone((string) $value);
        $required = $type === 'required' || ($admissionRequired && $section === 'education' && $key === 'admission_date');
        $name = $prefix . '[' . $key . ']';
        echo '<label' . ($type === 'textarea' ? ' class="sec-wide"' : '') . '><span>' . sh($label) . ($required ? ' *' : '') . '</span>';
        if (in_array($type, ['select', 'bool', 'specialty'], true)) {
            $options = $type === 'specialty' ? ['' => 'Не указано'] + $specialties : $field[2];
            echo '<select name="' . sh($name) . '">';
            foreach ($options as $option => $text) echo '<option value="' . sh($option) . '"' . ((string) $value === (string) $option ? ' selected' : '') . '>' . sh($text) . '</option>';
            echo '</select>';
        } elseif ($type === 'textarea') {
            echo '<textarea name="' . sh($name) . '" maxlength="5000" rows="3">' . sh($value) . '</textarea>';
        } else {
            $inputType = match ($type) { 'date' => 'date', 'email' => 'email', 'tel' => 'tel', 'year' => 'number', default => 'text' };
            $extra = $type === 'year' ? ' min="1900" max="9999"' : ' maxlength="' . ($section === 'student' && $key === 'fio' ? 100 : 500) . '"';
            if ($type === 'tel') $extra .= ' placeholder="+7 (900) 123-45-67"';
            echo '<input type="' . $inputType . '" name="' . sh($name) . '" value="' . sh($value) . '"' . $extra .
                ($required ? ' required' : '') . '>';
        }
        echo '</label>';
    }
}

function secRenderCollection(string $section, array $rows, array $specialties): void
{
    foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;
        ?>
        <fieldset class="sec-repeat-row">
          <legend><?= $section === 'family' ? 'Контактное лицо' : 'Учебное заведение' ?></legend>
          <input type="hidden" name="<?= sh($section) ?>[<?= sh($index) ?>][id]" value="<?= sh($row['id'] ?? '') ?>">
          <div class="sec-fields"><?php secRenderFields($section, $section . '[' . $index . ']', $row, $specialties); ?></div>
          <button type="button" class="sec-button sec-remove">Удалить запись</button>
        </fieldset>
        <?php
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#111111">
  <title><?= $tab === 'enroll' ? 'Зачисление' : 'Студенты' ?> — Кабинет секретаря</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('index.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('secretary.css'), ENT_QUOTES, 'UTF-8') ?>">
  <script src="<?= htmlspecialchars(cabinetAsset('secretary.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<link rel="stylesheet" href="<?= htmlspecialchars(cabinetAsset('cabinet.css'), ENT_QUOTES, 'UTF-8') ?>"><script src="<?= htmlspecialchars(cabinetAsset('cabinet.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script></head>
<body class="secretary">
<div class="app-shell">
  <?php cabSidebar('secretary', $currentUser, $tab, $csrf); ?>
  <main class="content">
    <header class="topbar"><div><span class="breadcrumb">ЛИЧНЫЙ КАБИНЕТ / СЕКРЕТАРЬ</span><h1><?= $tab === 'enroll' ? 'Зачисление студента' : 'Студенты' ?></h1></div><div class="date-block"><span>СЕГОДНЯ</span><b><?= sh(date('d.m.Y', strtotime($today))) ?></b></div></header>
    <div class="page-stack">
      <?php if ($notice): ?>
        <section class="sec-notice" role="status">
          <p><?= sh($notice['message']) ?></p>
          <?php if ($notice['credentials']): ?><p>Данные для входа студента. Передайте их студенту.</p><div class="sec-credentials"><label>Логин<input readonly value="<?= sh($notice['credentials']['login']) ?>"></label><label>Пароль<input readonly value="<?= sh($notice['credentials']['password']) ?>"></label></div><?php endif; ?>
        </section>
      <?php endif; ?>
      <?php if ($error): ?><p class="sec-error" role="alert"><?= sh($error) ?></p><?php endif; ?>
      <?php if ($fatal): ?><p class="panel sec-empty" role="alert"><?= sh($fatal) ?></p>
      <?php else: ?>
        <?php if ($tab === 'students'): ?>
          <form method="get" class="filter-toolbar sec-filters">
            <label>Корпус<select name="campus"><option value="">Все корпуса</option><option value="-1" <?= $campusFilter === '-1' ? 'selected' : '' ?>>Корпус не указан</option><?php foreach ($campuses as $id => $name): ?><option value="<?= sh($id) ?>" <?= (string) $id === $campusFilter ? 'selected' : '' ?>><?= sh($name) ?></option><?php endforeach; ?></select></label>
            <label>Группа<select name="group"><option value="">Все группы</option><?php foreach ($filteredGroups as $group): ?><option value="<?= sh($group['id']) ?>" <?= (int) $group['id'] === $groupFilter ? 'selected' : '' ?>><?= sh($group['name']) ?></option><?php endforeach; ?></select></label>
            <label>Студент<select name="student"><option value="">Все студенты</option><?php foreach ($studentOptions as $id => $name): ?><option value="<?= sh($id) ?>" <?= (int) $id === $studentFilter ? 'selected' : '' ?>><?= sh($name) ?></option><?php endforeach; ?></select></label>
            <label>Статус<select name="status"><?php foreach (['active' => 'Обучается', 'expelled' => 'Отчислен', 'all' => 'Все'] as $key => $label): ?><option value="<?= $key ?>" <?= $key === $status ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
            <button class="primary" type="submit" hidden>Найти</button>
          <?= cabinetResetLink(); ?></form>
          <section class="panel">
            <header class="panel-head"><div><h3>Контингент студентов</h3></div><span class="count"><?= $count ?></span></header>
            <?php if (!$students): ?><p class="sec-empty">По выбранным фильтрам студентов нет.</p>
            <?php else: ?>
              <div class="sec-table-wrap"><table class="sec-table"><thead><tr><th>Студент</th><th>Корпус / группа</th><th>Дата рождения</th><th>Телефон</th><th>Статус</th><th></th></tr></thead><tbody>
                <?php foreach ($students as $row): ?>
                  <tr><td><b><?= sh($row['fio']) ?></b></td><td><?= sh($row['campus_name'] ?? 'Не указан') ?><small><?= sh($row['group_name']) ?></small></td><td><?= $row['birth_date'] ? sh(date('d.m.Y', strtotime($row['birth_date']))) : '—' ?></td><td><?= sh(secPhone($row['phone']) ?? '—') ?></td><td><span class="tag"><?= $row['expulsion_date'] ? 'Отчислен' : 'Обучается' ?></span></td><td><a class="sec-button" href="<?= sh('secretary.php?' . http_build_query(['campus' => $campusFilter, 'group' => $groupFilter, 'student' => $studentFilter, 'status' => $status, 'page' => $page, 'id' => $row['id']])) ?>#card">Карточка</a></td></tr>
                <?php endforeach; ?>
              </tbody></table></div>
              <?php if ($pages > 1): ?><nav class="sec-pagination" aria-label="Страницы"><span><?= $page ?> / <?= $pages ?></span><?php foreach ([$page - 1 => '← Назад', $page + 1 => 'Далее →'] as $n => $label): ?><?php if ($n >= 1 && $n <= $pages): ?><a href="<?= sh('secretary.php?' . http_build_query(['campus' => $campusFilter, 'group' => $groupFilter, 'student' => $studentFilter, 'status' => $status, 'page' => $n])) ?>"><?= sh($label) ?></a><?php endif; ?><?php endforeach; ?></nav><?php endif; ?>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <?php if ($values !== null): ?>
          <section class="panel" id="card">
            <header class="panel-head"><div><h3><?= $tab === 'enroll' ? 'Новый студент' : 'Карточка студента' ?></h3></div><?php if ($tab !== 'enroll' && $expelToken): ?><button class="sec-button" type="button" id="sec-expel-open">Отчислить</button><?php endif; ?></header>
            <?php if ($persisted && !empty($persisted['education']['expulsion_date'])): ?><div class="sec-notice"><p>Отчислен <?= sh($persisted['education']['expulsion_date']) ?> · приказ <?= sh($persisted['education']['expulsion_order_number']) ?></p><p><?= sh($persisted['education']['expulsion_reason']) ?></p></div><?php endif; ?>
            <form method="post" class="sec-card-form" id="sec-card-form" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= sh($csrf) ?>">
              <input type="hidden" name="form_token" value="<?= sh($formToken) ?>">
              <input type="hidden" name="action" value="<?= $action ?>">
              <input type="hidden" name="id" value="<?= $action === 'enroll' ? '0' : sh($selected) ?>">
              <input type="hidden" name="version" value="<?= sh($values['version'] ?? '0') ?>">
              <p class="sec-help">Поля со звёздочкой обязательны. Остальные сведения можно заполнить позднее.</p>
              <fieldset><legend>Место обучения</legend><div class="sec-fields">
                <label>Корпус<select name="campus" id="sec-campus"><option value="">Корпус не указан</option><?php foreach ($campuses as $id => $name): ?><option value="<?= sh($id) ?>" <?= (string) $id === (string) ($values['campus'] ?? '') ? 'selected' : '' ?>><?= sh($name) ?></option><?php endforeach; ?></select></label>
                <label>Группа *<select name="group" id="sec-group" required><option value="">Выберите группу</option><?php foreach ($groups as $group): ?><option value="<?= sh($group['id']) ?>" data-campus="<?= sh($group['campus_id'] ?? '') ?>" <?= (string) $group['id'] === (string) ($values['group'] ?? '') ? 'selected' : '' ?>><?= sh($group['name']) ?></option><?php endforeach; ?></select></label>
              </div><?php if (!$groups): ?><p class="sec-help">В базе пока нет учебных групп для зачисления.</p><?php endif; ?></fieldset>
              <?php foreach (['student', 'documents', 'education'] as $section): ?>
                <details class="sec-section" open><summary><?= sh($fields[$section][0]) ?></summary><div class="sec-fields"><?php secRenderFields($section, $section, $values[$section] ?? [], $specialties, $tab === 'enroll'); ?></div></details>
              <?php endforeach; ?>
              <?php foreach (['registered' => 'Адрес прописки', 'residential' => 'Адрес проживания'] as $type => $label): ?>
                <details class="sec-section"><summary><?= $label ?></summary><div class="sec-fields"><?php secRenderFields('address', 'addresses[' . $type . ']', $values['addresses'][$type] ?? [], $specialties); ?></div></details>
              <?php endforeach; ?>
              <?php foreach (SECRETARY_COLLECTION_TABLES as $section => $table): ?>
                <details class="sec-section" open><summary><?= sh($fields[$section][0]) ?></summary>
                  <input type="hidden" name="<?= sh($section) ?>_present" value="1">
                  <div class="sec-collection" data-section="<?= sh($section) ?>"><?php secRenderCollection($section, $values[$section] ?? [], $specialties); ?></div>
                  <button type="button" class="sec-button sec-add" data-section="<?= sh($section) ?>"><?= $section === 'family' ? '+ Добавить родственника' : '+ Добавить учебное заведение' ?></button>
                </details>
              <?php endforeach; ?>
              <?php foreach (['military_legal', 'health', 'employment'] as $section): ?>
                <details class="sec-section"><summary><?= sh($fields[$section][0]) ?></summary><div class="sec-fields"><?php secRenderFields($section, $section, $values[$section] ?? [], $specialties); ?></div></details>
              <?php endforeach; ?>
              <?php if ($tab === 'enroll'): ?><details class="sec-section"><summary>Учётная запись студента</summary><p class="sec-help">Можно указать логин и пароль или оставить оба поля пустыми для автоматического создания. Данные для входа появятся после зачисления.</p><div class="sec-fields"><label>Логин<input name="login" value="<?= sh($values['login'] ?? '') ?>" maxlength="100" autocomplete="off"></label><label>Пароль<input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password"></label></div></details><?php endif; ?>
              <input type="hidden" name="form_complete" value="1">
              <div class="sec-actions"><a href="secretary.php" class="sec-button">К списку</a><button type="submit" class="primary"><?= $tab === 'enroll' ? 'Зачислить студента' : 'Сохранить изменения' ?></button></div>
            </form>
          </section>
          <?php if ($expelToken): ?>
            <dialog class="sec-dialog" id="sec-expel" data-reopen="<?= $error && ($_POST['action'] ?? '') === 'expel' ? '1' : '0' ?>" aria-labelledby="sec-expel-title">
              <form method="post">
                <header><h2 id="sec-expel-title">Отчисление студента</h2><button type="button" data-close aria-label="Закрыть">×</button></header>
                <div class="sec-dialog-body">
                  <p><?= sh($persisted['student']['fio']) ?></p><?php if ($error && ($_POST['action'] ?? '') === 'expel'): ?><p class="sec-error" role="alert"><?= sh($error) ?></p><?php endif; ?><p class="sec-help">Подтвердите отчисление и укажите основание.</p>
                  <input type="hidden" name="csrf" value="<?= sh($csrf) ?>"><input type="hidden" name="form_token" value="<?= sh($expelToken) ?>"><input type="hidden" name="action" value="expel"><input type="hidden" name="id" value="<?= sh($selected) ?>"><input type="hidden" name="version" value="<?= sh($persisted['version']) ?>">
                  <label>Дата отчисления *<input type="date" name="expulsion_date" value="<?= sh($_POST['expulsion_date'] ?? $today) ?>" required></label>
                  <label>Номер приказа *<input name="expulsion_order_number" value="<?= sh($_POST['expulsion_order_number'] ?? '') ?>" maxlength="500" required></label>
                  <label>Причина *<textarea name="expulsion_reason" rows="3" maxlength="5000" required><?= sh($_POST['expulsion_reason'] ?? '') ?></textarea></label>
                  <input type="hidden" name="form_complete" value="1">
                  <div class="sec-actions"><button type="button" class="sec-button" data-close>Отмена</button><button class="primary" type="submit">Подтвердить отчисление</button></div>
                </div>
              </form>
            </dialog>
          <?php endif; ?>
          <?php foreach (SECRETARY_COLLECTION_TABLES as $section => $table): ?><template id="sec-template-<?= sh($section) ?>"><?php secRenderCollection($section, ['NEW_ROW' => []], $specialties); ?></template><?php endforeach; ?>
        <?php elseif ($tab === 'students'): ?><p class="sec-help">Выберите «Карточка» напротив студента, чтобы изменить его сведения или оформить отчисление.</p><?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
