<?php
require_once __DIR__ . '/secretary-fields.php';

function headQuery(PDO $db, string $sql, array $parameters = []): PDOStatement
{
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query;
}

function headNumber(mixed $value): int
{
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) ?: 0;
}

function headPeriod(array $filters, string $dateColumn, string $subjectColumn): array
{
    $conditions = [];
    $parameters = [];
    if ($filters['year']) {
        $conditions[] = "$dateColumn >= ? AND $dateColumn < ?";
        $parameters[] = $filters['year'] . '-09-01';
        $parameters[] = ($filters['year'] + 1) . '-09-01';
    }
    if ($filters['semester']) {
        $conditions[] = 'EXTRACT(MONTH FROM ' . $dateColumn . ')' .
            ($filters['semester'] === 1 ? ' BETWEEN 9 AND 12' : ' BETWEEN 1 AND 6');
    }
    if ($filters['subject']) {
        $conditions[] = "$subjectColumn = ?";
        $parameters[] = $filters['subject'];
    }
    return [$conditions ? ' AND ' . implode(' AND ', $conditions) : '', $parameters];
}

function headRoster(PDO $db, int $group, int $student = 0): array
{
    return headQuery($db, 'SELECT s.id, s.fio, s.birth_date, s.phone, s.email, e.expulsion_date
        FROM public.students s LEFT JOIN public.student_education e ON e.student_id = s.id
        WHERE s.in_group = ?' . ($student ? ' AND s.id = ?' : '') . ' ORDER BY s.fio, s.id',
        $student ? [$group, $student] : [$group])->fetchAll(PDO::FETCH_ASSOC);
}

function headProfile(PDO $db, int $student, int $group): array
{
    $record = headQuery($db, 'SELECT s.*, g.name AS group_name, c.name AS campus_name
        FROM public.students s JOIN public.stud_groups g ON g.id = s.in_group
        LEFT JOIN public.campuses c ON c.id = g.campus_id
        WHERE s.id = ? AND s.in_group = ?', [$student, $group])->fetch(PDO::FETCH_ASSOC);
    if (!$record) return [];
    $profile = ['student' => $record, 'addresses' => []];
    foreach (SECRETARY_SINGLE_TABLES as $section => $table) {
        $profile[$section] = headQuery($db, "SELECT * FROM public.$table WHERE student_id = ?", [$student])->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    foreach (SECRETARY_COLLECTION_TABLES as $section => $table) {
        $profile[$section] = headQuery($db, "SELECT * FROM public.$table WHERE student_id = ? ORDER BY id", [$student])->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach (headQuery($db, 'SELECT * FROM public.student_addresses WHERE student_id = ?', [$student])->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $profile['addresses'][$row['address_type']] = $row;
    }
    return $profile;
}

function headJournal(PDO $db, array $filters, int $page, bool $exams): array
{
    [$period, $periodParameters] = headPeriod($filters, 'l.date', 'l.subject');
    $exam = $exams ? " AND BTRIM(l.lesson_type) IN ('Экзамен', 'экзамен', 'ЭКЗАМЕН', 'exam', 'Exam', 'EXAM')" : '';
    $studentSql = $filters['student'] ? ' AND st.id = ?' : '';
    $studentParameters = $filters['student'] ? [$filters['student']] : [];
    // Учитываем и оценки, полученные до перевода студента в текущую группу.
    $lessonScope = '(l.in_group = ? OR EXISTS (
        SELECT 1 FROM public.evaluations ev JOIN public.students st ON st.id = ev.student_id
        WHERE ev.lesson_id = l.id AND st.in_group = ?' . $studentSql . '))' . $period . $exam;
    $lessonParameters = [$filters['group'], $filters['group'], ...$studentParameters, ...$periodParameters];
    $total = (int) headQuery($db, 'SELECT COUNT(*) FROM public.lessons l WHERE ' . $lessonScope, $lessonParameters)->fetchColumn();
    $pages = max(1, (int) ceil($total / 24));
    $page = min($pages, max(1, $page));
    $lessons = headQuery($db, 'SELECT l.id, l.date, l.num_in_table, l.lesson_type, s.name AS subject_name,
        t.fio AS teacher_name, tp.name AS topic_name
        FROM public.lessons l JOIN public.subjects s ON s.id = l.subject
        JOIN public.teachers t ON t.id = l.teacher JOIN public.lesson_topics tp ON tp.id = l.topic
        WHERE ' . $lessonScope . ' ORDER BY l.date, l.num_in_table, s.name, l.id LIMIT 24 OFFSET ' . (($page - 1) * 24),
        $lessonParameters)->fetchAll(PDO::FETCH_ASSOC);

    $summary = headQuery($db, "SELECT st.id,
        COUNT(*) FILTER (WHERE e.grade IN ('2','3','4','5')) AS grade_count,
        COALESCE(SUM(CASE WHEN e.grade IN ('2','3','4','5') THEN e.grade::integer END), 0) AS grade_sum,
        COUNT(*) FILTER (WHERE BTRIM(e.grade) IN ('н', 'Н')) AS absences
        FROM public.students st JOIN public.evaluations e ON e.student_id = st.id
        JOIN public.lessons l ON l.id = e.lesson_id
        WHERE st.in_group = ?" . $studentSql . $period . $exam . ' GROUP BY st.id',
        [$filters['group'], ...$studentParameters, ...$periodParameters])->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $marks = [];
    if ($lessons) {
        $ids = array_column($lessons, 'id');
        foreach (headQuery($db, 'SELECT e.id, e.student_id, e.lesson_id, e.grade, e.comment
            FROM public.evaluations e JOIN public.students st ON st.id = e.student_id
            WHERE st.in_group = ?' . $studentSql . ' AND e.lesson_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              AND NULLIF(BTRIM(e.grade), \'\') IS NOT NULL ORDER BY e.id',
            [$filters['group'], ...$studentParameters, ...$ids])->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $marks[$entry['student_id']][$entry['lesson_id']][] = $entry;
        }
    }
    return compact('lessons', 'marks', 'summary', 'total', 'page', 'pages');
}

function headAttestations(PDO $db, array $filters, int $page, bool $exams): array
{
    [$period, $periodParameters] = headPeriod($filters, 'a.period_start', 'a.subject_id');
    $where = ' WHERE st.in_group = ?' . ($filters['student'] ? ' AND st.id = ?' : '') . $period . ($exams ? " AND a.kind = 'exam'" : '');
    $parameters = [$filters['group'], ...($filters['student'] ? [$filters['student']] : []), ...$periodParameters];
    $from = ' FROM public.teacher_attestations a JOIN public.students st ON st.id = a.student_id
        JOIN public.subjects s ON s.id = a.subject_id JOIN public.teachers t ON t.id = a.teacher_id
        JOIN public.stud_groups g ON g.id = a.group_id';
    $total = (int) headQuery($db, 'SELECT COUNT(*)' . $from . $where, $parameters)->fetchColumn();
    $pages = max(1, (int) ceil($total / 50));
    $page = min($pages, max(1, $page));
    $entries = headQuery($db, 'SELECT a.*, st.fio AS student_name, s.name AS subject_name,
        t.fio AS teacher_name, g.name AS original_group' . $from . $where .
        ' ORDER BY a.period_start DESC, st.fio, s.name, a.kind, a.teacher_id, a.student_id LIMIT 50 OFFSET ' . (($page - 1) * 50),
        $parameters)->fetchAll(PDO::FETCH_ASSOC);
    return compact('entries', 'total', 'page', 'pages');
}

function headAverages(PDO $db, array $filters): array
{
    [$period, $parameters] = headPeriod($filters, 'l.date', 'l.subject');
    $campusWhere = '';
    if ($filters['campus'] === '-1') $campusWhere = ' WHERE g.campus_id IS NULL';
    elseif ($filters['campus'] !== '') {
        $campusWhere = ' WHERE g.campus_id = ?';
        $parameters[] = $filters['campus'];
    }
    return headQuery($db, "WITH marks AS (
        SELECT st.in_group, e.grade FROM public.students st
        JOIN public.evaluations e ON e.student_id = st.id JOIN public.lessons l ON l.id = e.lesson_id
        WHERE TRUE $period
    ), summaries AS (
        SELECT in_group,
            COUNT(*) FILTER (WHERE grade IN ('2','3','4','5')) AS grade_count,
            COALESCE(SUM(CASE WHEN grade IN ('2','3','4','5') THEN grade::integer END), 0) AS grade_sum,
            COUNT(*) FILTER (WHERE BTRIM(grade) IN ('н', 'Н')) AS absences
        FROM marks GROUP BY in_group
    )
    SELECT g.id, g.name, c.name AS campus_name,
        (SELECT COUNT(*) FROM public.students st WHERE st.in_group = g.id) AS students,
        COALESCE(a.grade_count, 0) AS grade_count, COALESCE(a.grade_sum, 0) AS grade_sum,
        COALESCE(a.absences, 0) AS absences
    FROM public.stud_groups g LEFT JOIN public.campuses c ON c.id = g.campus_id
    LEFT JOIN summaries a ON a.in_group = g.id $campusWhere ORDER BY g.name, g.id", $parameters)->fetchAll(PDO::FETCH_ASSOC);
}
