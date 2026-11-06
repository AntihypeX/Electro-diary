<?php

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Используйте GET.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Войдите в аккаунт.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($user['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Раздел доступен только студентам.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = connectDB();
    $stmt = $pdo->prepare(<<<'SQL'
        WITH student AS (
            SELECT MIN(id) AS id
            FROM public.students
            WHERE auth_id = :auth_id
            HAVING COUNT(*) = 1
        )
        SELECT e.id,
               s.id AS subject_id,
               s.name AS subject,
               LOWER(BTRIM(s.name)) AS subject_key,
               to_char(l.date, 'YYYY-MM-DD') AS date,
               e.grade AS value
        FROM student
        LEFT JOIN (
            public.evaluations e
            JOIN public.lessons l ON l.id = e.lesson_id
            JOIN public.subjects s ON s.id = l.subject
        ) ON e.student_id = student.id
             AND e.grade IS NOT NULL
        ORDER BY l.date, s.name, l.num_in_table, e.id
        SQL);
    $stmt->execute(['auth_id' => $user['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $error) {
    error_log('student-grades database error: ' . $error->getCode());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось загрузить оценки.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($rows === []) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Аккаунт должен быть привязан к одному студенту.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$grades = [];
foreach ($rows as $row) {
    if ($row['id'] === null) {
        continue;
    }

    $grades[] = [
        'id' => (int) $row['id'],
        'subjectId' => (int) $row['subject_id'],
        'subjectKey' => $row['subject_key'],
        'subject' => $row['subject'],
        'date' => $row['date'],
        'value' => $row['value'],
    ];
}

echo json_encode(['ok' => true, 'grades' => $grades], JSON_UNESCAPED_UNICODE);
