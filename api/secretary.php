<?php

require_once __DIR__ . '/secretary-fields.php';

final class SecretaryError extends RuntimeException {}

function secQuery(PDO $db, string $sql, array $parameters = []): PDOStatement
{
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query;
}

function secId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($id === false) {
        throw new SecretaryError('Некорректный идентификатор.');
    }
    return $id;
}

function secText(mixed $value, string $label, int $limit = 500): ?string
{
    if (!is_string($value) || str_contains($value, "\0")) {
        throw new SecretaryError('Некорректное поле «' . $label . '».');
    }
    $value = trim($value);
    if ($value === '') return null;
    if (preg_match('/\A.{1,' . $limit . '}\z/us', $value) !== 1) {
        throw new SecretaryError('Поле «' . $label . '» слишком длинное.');
    }
    return $value;
}

function secDate(?string $value, string $label): ?string
{
    if ($value !== null && (!preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
        || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
        throw new SecretaryError('Проверьте дату в поле «' . $label . '».');
    }
    return $value;
}

function secValues(string $section, mixed $input): array
{
    if (!is_array($input)) throw new SecretaryError('Не получен раздел карточки.');
    $values = [];
    foreach (secretaryFields()[$section][1] as $key => [$label, $type]) {
        $value = secText($input[$key] ?? '', $label, $type === 'textarea' ? 5000 : 500);
        if ($type === 'required' && $value === null) throw new SecretaryError('Заполните поле «' . $label . '».');
        if ($type === 'date') $value = secDate($value, $label);
        if (in_array($type, ['select', 'bool'], true)) {
            $options = secretaryFields()[$section][1][$key][2];
            if (!array_key_exists($value ?? '', $options)) throw new SecretaryError('Выберите значение поля «' . $label . '».');
            if ($type === 'bool' && $value !== null) $value = $value === '1' ? 'true' : 'false';
        }
        if ($type === 'specialty' && $value !== null) $value = secId($value);
        if ($type === 'year' && $value !== null) {
            if (!ctype_digit($value) || (int) $value < 1900 || (int) $value > 9999) throw new SecretaryError('Проверьте год окончания.');
            $value = (int) $value;
        }
        if ($type === 'email' && $value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new SecretaryError('Проверьте электронную почту.');
        if (in_array($type, ['snils', 'inn'], true) && $value !== null) {
            $value = preg_replace('/[\s-]+/u', '', $value);
            $length = $type === 'snils' ? 11 : 12;
            if (!preg_match('/\A[0-9]{' . $length . '}\z/', $value)) throw new SecretaryError('Проверьте формат поля «' . $label . '».');
        }
        $values[$key] = $value;
    }
    if ($section === 'student' && preg_match('/\A.{1,100}\z/us', $values['fio']) !== 1) {
        throw new SecretaryError('ФИО должно содержать до 100 символов.');
    }
    return $values;
}

function secUpsert(PDO $db, string $table, array $key, array $values): void
{
    $columns = array_keys($key + $values);
    $updates = implode(', ', array_map(fn ($column) => "$column = EXCLUDED.$column", array_keys($values)));
    secQuery($db, "INSERT INTO public.$table (" . implode(', ', $columns) . ')
        VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')
        ON CONFLICT (' . implode(', ', array_keys($key)) . ") DO UPDATE SET $updates",
        array_values($key + $values));
}

function secCollection(PDO $db, int $student, string $section, mixed $rows): void
{
    if (!is_array($rows) || count($rows) > 50) throw new SecretaryError('Слишком много записей в разделе.');
    $table = SECRETARY_COLLECTION_TABLES[$section];
    $owned = secQuery($db, "SELECT id FROM public.$table WHERE student_id = ?", [$student])->fetchAll(PDO::FETCH_COLUMN);
    $kept = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new SecretaryError('Некорректная запись в разделе.');
        $id = empty($row['id']) ? null : secId($row['id']);
        if ($id && (!in_array((string) $id, array_map('strval', $owned), true) || in_array($id, $kept, true))) {
            throw new SecretaryError('Запись раздела не принадлежит студенту или повторяется.');
        }
        $values = secValues($section, $row);
        if ($id) {
            $assign = implode(', ', array_map(fn ($key) => "$key = ?", array_keys($values)));
            secQuery($db, "UPDATE public.$table SET $assign WHERE id = ? AND student_id = ?", [...array_values($values), $id, $student]);
        } else {
            $columns = array_keys($values);
            $id = (int) secQuery($db, "INSERT INTO public.$table (student_id, " . implode(', ', $columns) .
                ') VALUES (' . implode(', ', array_fill(0, count($columns) + 1, '?')) . ') RETURNING id',
                [$student, ...array_values($values)])->fetchColumn();
        }
        $kept[] = $id;
    }
    $where = $kept ? ' AND id NOT IN (' . implode(', ', array_fill(0, count($kept), '?')) . ')' : '';
    secQuery($db, "DELETE FROM public.$table WHERE student_id = ?$where", [$student, ...$kept]);
}

function secSave(PDO $db, array $input, bool $create): array
{
    $group = secId($input['group'] ?? null);
    $campus = $input['campus'] ?? '';
    if (!is_string($campus)) throw new SecretaryError('Выберите корпус.');
    $actualCampus = secQuery($db, 'SELECT campus_id FROM public.stud_groups WHERE id = ? FOR SHARE', [$group])->fetch(PDO::FETCH_ASSOC);
    if (!$actualCampus || (string) ($actualCampus['campus_id'] ?? '') !== $campus) {
        throw new SecretaryError('Выберите группу в указанном корпусе.');
    }
    if (!isset($input['family_present'], $input['previous_present'])) throw new SecretaryError('Форма получена не полностью. Обновите страницу.');
    $studentValues = secValues('student', $input['student'] ?? null);
    $education = secValues('education', $input['education'] ?? null);
    if ($create && $education['admission_date'] === null) throw new SecretaryError('Укажите дату зачисления.');
    $credentials = [];

    if ($create) {
        $login = secText($input['login'] ?? '', 'Логин', 100) ?? ('student_' . bin2hex(random_bytes(6)));
        if (!preg_match('/\A[A-Za-z0-9_.-]{3,100}\z/', $login)) throw new SecretaryError('Логин должен содержать 3–100 латинских букв, цифр, точек, дефисов или подчёркиваний.');
        $password = $input['password'] ?? '';
        if (!is_string($password)) throw new SecretaryError('Проверьте пароль.');
        $password = $password === '' ? bin2hex(random_bytes(10)) : $password;
        if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) throw new SecretaryError('Пароль должен содержать от 8 до 72 байт.');
        // В исходной auth нет UNIQUE(login). Блокировка сериализует создание аккаунтов кабинетом.
        secQuery($db, 'LOCK TABLE public.auth IN SHARE ROW EXCLUSIVE MODE');
        if (secQuery($db, 'SELECT id FROM public.auth WHERE login = ? LIMIT 1', [$login])->fetchColumn()) {
            throw new SecretaryError('Этот логин уже занят.');
        }
        $role = secQuery($db, "SELECT id FROM public.roles WHERE LOWER(BTRIM(name)) IN ('student', 'студент') ORDER BY id LIMIT 1")->fetchColumn();
        if (!$role) throw new SecretaryError('В базе отсутствует роль студента.');
        $auth = secQuery($db, 'INSERT INTO public.auth (login, password, role_id) VALUES (?, ?, ?) RETURNING id',
            [$login, password_hash($password, PASSWORD_DEFAULT), $role])->fetchColumn();
        $columns = array_keys($studentValues);
        $student = (int) secQuery($db, 'INSERT INTO public.students (in_group, auth_id, ' . implode(', ', $columns) . ')
            VALUES (' . implode(', ', array_fill(0, count($columns) + 2, '?')) . ') RETURNING id',
            [$group, $auth, ...array_values($studentValues)])->fetchColumn();
        $credentials = ['login' => $login, 'password' => $password];
    } else {
        $student = secId($input['id'] ?? null);
        $record = secQuery($db, 'SELECT secretary_version FROM public.students WHERE id = ? FOR UPDATE', [$student])->fetch(PDO::FETCH_ASSOC);
        if (!$record) throw new SecretaryError('Студент не найден.');
        if ((string) $record['secretary_version'] !== ($input['version'] ?? null)) throw new SecretaryError('Карточка уже изменилась. Откройте её заново перед сохранением.');
        $currentEducation = secQuery($db, 'SELECT expulsion_date FROM public.student_education WHERE student_id = ?', [$student])->fetch(PDO::FETCH_ASSOC);
        if ($currentEducation && $currentEducation['expulsion_date'] !== null && $education['admission_date'] !== null
            && $education['admission_date'] > $currentEducation['expulsion_date']) {
            throw new SecretaryError('Дата зачисления не может быть позднее даты отчисления.');
        }
        $assign = implode(', ', array_map(fn ($key) => "$key = ?", array_keys($studentValues)));
        secQuery($db, "UPDATE public.students SET in_group = ?, $assign, secretary_version = secretary_version + 1 WHERE id = ?",
            [$group, ...array_values($studentValues), $student]);
    }

    $documents = secValues('documents', $input['documents'] ?? null);
    if ($documents['identity_document_issued_on'] !== null && $documents['identity_document_valid_until'] !== null
        && $documents['identity_document_valid_until'] < $documents['identity_document_issued_on']) throw new SecretaryError('Срок действия документа указан раньше даты выдачи.');
    secUpsert($db, 'student_documents', ['student_id' => $student], $documents);
    secUpsert($db, 'student_education', ['student_id' => $student], $education);
    foreach (['military_legal', 'health', 'employment'] as $section) {
        secUpsert($db, SECRETARY_SINGLE_TABLES[$section], ['student_id' => $student], secValues($section, $input[$section] ?? null));
    }
    foreach (['registered', 'residential'] as $type) {
        secUpsert($db, 'student_addresses', ['student_id' => $student, 'address_type' => $type],
            secValues('address', $input['addresses'][$type] ?? null));
    }
    foreach (SECRETARY_COLLECTION_TABLES as $section => $table) secCollection($db, $student, $section, $input[$section] ?? []);
    return ['id' => $student, 'credentials' => $credentials];
}

function secExpel(PDO $db, array $input): array
{
    $student = secId($input['id'] ?? null);
    $row = secQuery($db, 'SELECT secretary_version FROM public.students WHERE id = ? FOR UPDATE', [$student])->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new SecretaryError('Студент не найден.');
    if ((string) $row['secretary_version'] !== ($input['version'] ?? null)) throw new SecretaryError('Карточка уже изменилась. Откройте её заново.');
    $date = secDate(secText($input['expulsion_date'] ?? '', 'Дата отчисления'), 'Дата отчисления');
    $order = secText($input['expulsion_order_number'] ?? '', 'Приказ об отчислении');
    $reason = secText($input['expulsion_reason'] ?? '', 'Причина отчисления', 5000);
    if (!$date || !$order || !$reason) throw new SecretaryError('Укажите дату, номер приказа и причину отчисления.');
    $education = secQuery($db, 'SELECT admission_date, expulsion_date FROM public.student_education WHERE student_id = ? FOR UPDATE', [$student])->fetch(PDO::FETCH_ASSOC);
    if ($education && $education['expulsion_date']) throw new SecretaryError('Студент уже отчислен.');
    if ($education && $education['admission_date'] && $date < $education['admission_date']) throw new SecretaryError('Дата отчисления указана раньше зачисления.');
    secUpsert($db, 'student_education', ['student_id' => $student], [
        'expulsion_date' => $date, 'expulsion_order_number' => $order, 'expulsion_reason' => $reason,
    ]);
    secQuery($db, 'UPDATE public.students SET secretary_version = secretary_version + 1 WHERE id = ?', [$student]);
    return ['id' => $student, 'credentials' => []];
}

function secLoad(PDO $db, int $id): array
{
    $student = secQuery($db, 'SELECT s.*, g.campus_id FROM public.students s JOIN public.stud_groups g ON g.id = s.in_group WHERE s.id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
    if (!$student) throw new SecretaryError('Студент не найден.');
    $data = ['student' => $student, 'id' => $id, 'version' => $student['secretary_version'],
        'group' => $student['in_group'], 'campus' => $student['campus_id'] ?? '', 'addresses' => []];
    foreach (SECRETARY_SINGLE_TABLES as $section => $table) {
        $data[$section] = secQuery($db, "SELECT * FROM public.$table WHERE student_id = ?", [$id])->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    foreach (SECRETARY_COLLECTION_TABLES as $section => $table) {
        $data[$section] = secQuery($db, "SELECT * FROM public.$table WHERE student_id = ? ORDER BY id", [$id])->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach (secQuery($db, 'SELECT * FROM public.student_addresses WHERE student_id = ?', [$id])->fetchAll(PDO::FETCH_ASSOC) as $address) {
        $data['addresses'][$address['address_type']] = $address;
    }
    return $data;
}
