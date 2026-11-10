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

function headPeriod(array $filters, string $dateColumn, string $subjectColumn, ?string $monthColumn = null): array
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
    if (!empty($filters['month'])) {
        $conditions[] = "EXTRACT(MONTH FROM " . ($monthColumn ?? $dateColumn) . ") = ?";
        $parameters[] = $filters['month'];
    }
    if ($filters['subject']) {
        $conditions[] = "$subjectColumn = ?";
        $parameters[] = $filters['subject'];
    }
    return [$conditions ? ' AND ' . implode(' AND ', $conditions) : '', $parameters];
}

function headRoster(PDO $db, int $group, int $student = 0): array
{
    return headQuery($db, 'SELECT s.id, s.fio, e.expulsion_date
        FROM public.students s LEFT JOIN public.student_education e ON e.student_id = s.id
        WHERE s.in_group = ?' . ($student ? ' AND s.id = ?' : '') . ' ORDER BY public.diary_name_sort(s.fio) COLLATE "C", s.fio, s.id',
        $student ? [$group, $student] : [$group])->fetchAll(PDO::FETCH_ASSOC);
}

function headProfile(PDO $db, int $student, int $group): array
{
    $record = headQuery($db, 'SELECT s.fio, s.birth_place, s.citizenship, s.nationality, g.name AS group_name, c.name AS campus_name
        FROM public.students s JOIN public.stud_groups g ON g.id = s.in_group
        LEFT JOIN public.campuses c ON c.id = g.campus_id
        WHERE s.id = ? AND s.in_group = ?', [$student, $group])->fetch(PDO::FETCH_ASSOC);
    if (!$record) return [];
    $profile = ['student' => $record];
    foreach (SECRETARY_SINGLE_TABLES as $section => $table) {
        $profile[$section] = headQuery($db, "SELECT * FROM public.$table WHERE student_id = ?", [$student])->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    foreach (SECRETARY_COLLECTION_TABLES as $section => $table) {
        $profile[$section] = headQuery($db, "SELECT * FROM public.$table WHERE student_id = ? ORDER BY id", [$student])->fetchAll(PDO::FETCH_ASSOC);
    }
    return $profile;
}

function headJournal(PDO $db, array $filters, int $page, bool $finals): array
{
    [$period, $periodParameters] = headPeriod($filters, 'l.date', 'l.subject');
    $studentSql = $filters['student'] ? ' AND st.id = ?' : '';
    $studentParameters = $filters['student'] ? [$filters['student']] : [];
    // Сохраняем оценки за занятия до перевода в текущую группу.
    $lessonScope = '(l.in_group = ? OR EXISTS (
        SELECT 1 FROM public.evaluations ev JOIN public.students st ON st.id = ev.student_id
        WHERE ev.lesson_id = l.id AND st.in_group = ?' . $studentSql . '))' . $period;
    if ($finals) {
        $lessonScope .= " AND public.diary_name_sort(l.lesson_type) IN
            ('экзамен','exam','зачет','credit','дифзачет','диф. зачет','дифференцированный зачет','diff')";
    }
    $columns = 'SELECT l.id::text AS column_key, l.id AS lesson_id, l.date, l.num_in_table, l.lesson_type,
        s.name AS subject_name, t.fio AS teacher_name, tp.name AS topic_name
        FROM public.lessons l JOIN public.subjects s ON s.id=l.subject
        JOIN public.teachers t ON t.id=l.teacher LEFT JOIN public.lesson_topics tp ON tp.id=l.topic
        WHERE ' . $lessonScope;
    $parameters = [$filters['group'], $filters['group'], ...$studentParameters, ...$periodParameters];

    if ($finals) {
        [$resultPeriod, $resultParameters] = headPeriod($filters, 'a.period_start', 'a.subject_id');
        $resultKey = "concat_ws(':', 'final', a.group_id, a.subject_id, a.teacher_id, a.period_start, a.kind)";
        $resultScope = " FROM public.teacher_attestations a JOIN public.students st ON st.id=a.student_id
            WHERE st.in_group=?" . $studentSql . $resultPeriod . " AND a.kind IN ('exam','diff','credit')";
        $resultParameters = [$filters['group'], ...$studentParameters, ...$resultParameters];
        $columns .= " UNION ALL
            SELECT a.column_key, NULL::integer, a.period_start, NULL::integer,
                CASE a.kind WHEN 'exam' THEN 'Итог · Экзамен' WHEN 'diff' THEN 'Итог · Диф. зачёт' ELSE 'Итог · Зачёт' END,
                s.name, t.fio, NULL::text
            FROM (
                SELECT DISTINCT $resultKey AS column_key, a.subject_id, a.teacher_id, a.period_start, a.kind $resultScope
            ) a JOIN public.subjects s ON s.id=a.subject_id JOIN public.teachers t ON t.id=a.teacher_id";
        $parameters = [...$parameters, ...$resultParameters];
    }
    $total = (int) headQuery($db, "SELECT COUNT(*) FROM ($columns) AS columns", $parameters)->fetchColumn();
    $pages = max(1, (int) ceil($total / 24));
    $page = min($pages, max(1, $page));
    $lessons = headQuery($db, "SELECT * FROM ($columns) AS columns
        ORDER BY date, num_in_table NULLS LAST, subject_name, column_key LIMIT 24 OFFSET " . (($page - 1) * 24),
        $parameters)->fetchAll(PDO::FETCH_ASSOC);

    $marks = $summary = [];
    $record = static function (array $entry) use (&$marks, &$summary): void {
        $id = $entry['student_id'];
        $marks[$id][$entry['column_key']][] = $entry;
        $summary[$id] ??= ['grade_count'=>0, 'grade_sum'=>0, 'absences'=>0];
        $grade = trim($entry['grade']);
        if (in_array($grade, ['2','3','4','5'], true)) {
            $summary[$id]['grade_count']++;
            $summary[$id]['grade_sum'] += (int) $grade;
        } elseif (in_array($grade, ['н','Н'], true)) {
            $summary[$id]['absences']++;
        }
    };
    $ids = array_values(array_filter(array_column($lessons, 'lesson_id'), static fn ($id) => $id !== null));
    if ($ids) {
        $slots = implode(',', array_fill(0, count($ids), '?'));
        foreach (headQuery($db, "SELECT e.student_id, e.lesson_id::text AS column_key, e.grade, e.comment, e.previous_grade
            FROM public.evaluations e JOIN public.students st ON st.id=e.student_id
            WHERE st.in_group=?" . $studentSql . " AND e.lesson_id IN ($slots)
            AND NULLIF(BTRIM(e.grade), '') IS NOT NULL ORDER BY e.id",
            [$filters['group'], ...$studentParameters, ...$ids])->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $record($entry);
        }
    }
    if ($finals) {
        $keys = array_column(array_filter($lessons, static fn ($column) => $column['lesson_id'] === null), 'column_key');
        if ($keys) {
            $slots = implode(',', array_fill(0, count($keys), '?'));
            foreach (headQuery($db, "SELECT a.student_id, $resultKey AS column_key, a.grade,
                CASE WHEN a.kind='exam' THEN a.previous_grade END AS previous_grade
                $resultScope AND $resultKey IN ($slots)", [...$resultParameters, ...$keys])->fetchAll(PDO::FETCH_ASSOC) as $entry) {
                $record($entry);
            }
        }
    }
    return compact('lessons', 'marks', 'summary', 'total', 'page', 'pages');
}

function headAttestations(PDO $db, array $filters, int $page): array
{
    [$period, $periodParameters] = headPeriod($filters, 'a.period_start', 'a.subject_id',
        "CASE WHEN a.kind='month' THEN a.period_start ELSE COALESCE(a.assessed_on,a.period_start) END");
    $kind = $filters['kind'];
    $where = ' WHERE st.in_group = ?' . ($filters['student'] ? ' AND st.id = ?' : '') . $period . ($kind !== '' ? ' AND a.kind = ?' : '');
    $parameters = [$filters['group'], ...($filters['student'] ? [$filters['student']] : []),
        ...$periodParameters, ...($kind !== '' ? [$kind] : [])];
    $from = ' FROM public.teacher_attestations a JOIN public.students st ON st.id=a.student_id
        JOIN public.subjects s ON s.id=a.subject_id JOIN public.teachers t ON t.id=a.teacher_id
        JOIN public.stud_groups g ON g.id=a.group_id';
    $total = (int) headQuery($db, 'SELECT COUNT(*)' . $from . $where, $parameters)->fetchColumn();
    $pages = max(1, (int) ceil($total / 50));
    $page = min($pages, max(1, $page));
    $entries = headQuery($db, 'SELECT a.*, st.fio AS student_name, s.name AS subject_name,
        t.fio AS teacher_name, g.name AS original_group' . $from . $where .
        ' ORDER BY public.diary_name_sort(st.fio) COLLATE "C", st.fio, a.period_start DESC, s.name, a.kind, a.teacher_id, a.student_id LIMIT 50 OFFSET ' . (($page - 1) * 50),
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
