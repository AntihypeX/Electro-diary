<?php

const TEACHER_GRADE_VALUES = ["2", "3", "4", "5", "н"];
const TEACHER_RESULT_VALUES = ["2", "3", "4", "5", "н/а"];
const TEACHER_LESSON_TYPES = ["Лекция", "Практическая", "Экзамен"];
const TEACHER_ATTESTATION_TYPES = [
    "month" => "За месяц",
    "semester" => "За семестр",
    "exam" => "Экзамен",
    "diff" => "Дифзачёт",
    "credit" => "Зачёт",
];

final class TeacherRequestError extends RuntimeException {}

function teacherQuery(
    PDO $db,
    string $sql,
    array $parameters = [],
): PDOStatement {
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query;
}

function teacherRows(PDO $db, string $sql, array $parameters = []): array
{
    return teacherQuery($db, $sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
}

function teacherProfile(PDO $db, int $authId): ?array
{
    $profiles = teacherRows(
        $db,
        <<<'SQL'
        SELECT t.id, t.fio
        FROM public.teachers t
        WHERE t.auth_id = ?
        ORDER BY t.id
        LIMIT 2
        SQL
        ,
        [$authId],
    );

    return count($profiles) === 1 ? $profiles[0] : null;
}

function teacherId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 1, "max_range" => 2147483647],
    ]);
    if ($id === false) {
        throw new TeacherRequestError("Некорректный идентификатор.", 400);
    }
    return $id;
}

function teacherLockStudent(PDO $db, int $student, int $group): void
{
    if (
        !teacherQuery(
            $db,
            "SELECT id FROM public.students WHERE id = ? AND in_group = ? FOR SHARE",
            [$student, $group],
        )->fetchColumn()
    ) {
        throw new TeacherRequestError(
            "Студент не входит в выбранную группу.",
            404,
        );
    }
}

function teacherSave(PDO $db, int $teacher, array $input): string
{
    $action = $input["action"] ?? "";
    if ($action === 'attestation_auto') {
        return teacherAutoAttestation($db, $teacher, $input);
    }
    if (
        in_array(
            $action,
            ["attestation", "attestation_delete", "result_details"],
            true,
        )
    ) {
        return teacherSaveAttestation($db, $teacher, $input);
    }

    if (
        !in_array(
            $action,
            ["lesson", "grade_add", "grade_edit", "grade_delete"],
            true,
        )
    ) {
        throw new TeacherRequestError("Неизвестное действие.", 400);
    }

    $lessonId = teacherId($input["lesson"] ?? null);
    $lesson = teacherQuery(
        $db,
        <<<'SQL'
        SELECT l.in_group, l.subject, l.topic, l.lesson_type, t.name AS topic_name
        FROM public.lessons l
        LEFT JOIN public.lesson_topics t ON t.id = l.topic
        WHERE l.id = ? AND l.teacher = ?
        FOR UPDATE OF l
        SQL
        ,
        [$lessonId, $teacher],
    )->fetch(PDO::FETCH_ASSOC);
    if (!$lesson) {
        throw new TeacherRequestError(
            "Занятие не найдено в вашем расписании.",
            404,
        );
    }

    if ($action === "lesson") {
        return teacherSaveLesson($db, $teacher, $lessonId, $lesson, $input);
    }

    $student = teacherId($input["student"] ?? null);
    teacherLockStudent($db, $student, (int) $lesson["in_group"]);

    if ($action === "grade_add") {
        $grades = $input["grades"] ?? null;
        if (
            !is_array($grades) ||
            !array_is_list($grades) ||
            count($grades) < 1 ||
            count($grades) > 10
        ) {
            throw new TeacherRequestError(
                "Выберите от одной до десяти отметок.",
                400,
            );
        }
        foreach ($grades as $grade) {
            if (!in_array($grade, TEACHER_GRADE_VALUES, true)) {
                throw new TeacherRequestError(
                    "Выберите допустимую отметку.",
                    400,
                );
            }
        }

        $currentGrades = teacherQuery(
            $db,
            <<<'SQL'
            SELECT grade FROM public.evaluations
            WHERE lesson_id = ? AND student_id = ? AND NULLIF(BTRIM(grade), '') IS NOT NULL
            SQL
            ,
            [$lessonId, $student],
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($currentGrades) !== ($input["expectedCount"] ?? null)) {
            throw new TeacherRequestError(
                "Ячейка уже изменилась. Обновите страницу и проверьте оценки.",
                409,
            );
        }
        if ($lesson['lesson_type'] === 'Экзамен' && (count($grades) !== 1 || $currentGrades)) {
            throw new TeacherRequestError('За экзамен можно поставить только одну отметку. Измените существующую.', 409);
        }
        $absenceCount = count(array_intersect($grades, ["н"]));
        if ($absenceCount > 1 || ($absenceCount === 1 && array_intersect($currentGrades, ["н", "Н"]))) {
            throw new TeacherRequestError(
                "За занятие можно поставить только одну отметку «н».",
                400,
            );
        }

        $insert = $db->prepare(
            "INSERT INTO public.evaluations (lesson_id, student_id, grade) VALUES (?, ?, ?)",
        );
        foreach ($grades as $grade) {
            $insert->execute([$lessonId, $student, $grade]);
        }
        return count($grades) === 1
            ? "Отметка сохранена."
            : "Оценки сохранены.";
    }

    if ($action === "grade_delete") {
        $previous = $input["previous"] ?? null;
        if (!in_array($previous, [...TEACHER_GRADE_VALUES, "Н"], true)) {
            throw new TeacherRequestError("Некорректная отметка.", 400);
        }
        $deleted = teacherQuery(
            $db,
            "DELETE FROM public.evaluations WHERE id = ? AND lesson_id = ? AND student_id = ? AND grade = ?",
            [
                teacherId($input["entry"] ?? null),
                $lessonId,
                $student,
                $previous,
            ],
        );
        if ($deleted->rowCount() !== 1) {
            throw new TeacherRequestError(
                "Отметка уже изменилась. Обновите страницу.",
                409,
            );
        }
        return "Отметка удалена.";
    }

    $grade = $input["grade"] ?? null;
    $previous = $input["previous"] ?? null;
    if (
        !in_array($grade, TEACHER_GRADE_VALUES, true) ||
        !is_string($previous)
    ) {
        throw new TeacherRequestError("Выберите допустимую отметку.", 400);
    }
    $gradeId = teacherId($input["entry"] ?? null);
    if (
        $grade === "н" &&
        teacherQuery(
            $db,
            <<<'SQL'
            SELECT id FROM public.evaluations
            WHERE lesson_id = ? AND student_id = ? AND id <> ? AND grade IN ('н', 'Н')
            LIMIT 1
            SQL
            ,
            [$lessonId, $student, $gradeId],
        )->fetchColumn()
    ) {
        throw new TeacherRequestError(
            "За занятие уже есть отметка «н».",
            400,
        );
    }
    $updated = teacherQuery(
        $db,
        <<<'SQL'
        UPDATE public.evaluations
        SET previous_grade = CASE WHEN ?::boolean AND grade <> ? THEN grade ELSE previous_grade END,
            grade = ?
        WHERE id = ? AND lesson_id = ? AND student_id = ? AND grade = ?
        SQL
        ,
        [$lesson['lesson_type'] === 'Экзамен' ? 'true' : 'false', $grade, $grade, $gradeId, $lessonId, $student, $previous],
    );
    if ($updated->rowCount() !== 1) {
        throw new TeacherRequestError(
            "Оценка уже изменилась. Обновите страницу и проверьте ячейку.",
            409,
        );
    }

    return "Изменение оценки сохранено.";
}

function teacherSaveLesson(
    PDO $db,
    int $teacher,
    int $lessonId,
    array $lesson,
    array $input,
): string {
    if (($lesson['topic_name'] ?? '') !== ($input['previousTopic'] ?? null)
        || ($lesson['lesson_type'] ?? '') !== ($input['previousType'] ?? null)) {
        throw new TeacherRequestError('Занятие уже изменилось. Обновите страницу и проверьте данные.', 409);
    }

    $name = $input['newTopic'] ?? '';
    if (!is_string($name)) {
        throw new TeacherRequestError('Некорректное название темы.', 400);
    }
    $name = trim($name);
    if ($name !== '' && !preg_match('/\A[^\p{C}]{1,100}\z/u', $name)) {
        throw new TeacherRequestError('Введите название темы длиной до 100 символов.', 400);
    }

    $type = $input['type'] ?? null;
    if ($name === '') {
        $topicId = teacherId($input['topic'] ?? null);
        $topic = teacherQuery($db, "SELECT t.id,to_jsonb(t)->>'lesson_type' AS lesson_type
            FROM public.lesson_topics t WHERE t.id=? AND t.subj_id=? AND (t.teacher_id IS NULL OR t.teacher_id=?)",
            [$topicId,$lesson['subject'],$teacher])->fetch(PDO::FETCH_ASSOC);
        if (!$topic) {
            throw new TeacherRequestError('Выберите доступную тему этого предмета.', 400);
        }
        $type = $topic['lesson_type'] ?? $type;
    } else {
        $topicId = teacherQuery($db, 'INSERT INTO public.lesson_topics (name,subj_id,teacher_id)
            VALUES (?,?,?) ON CONFLICT DO NOTHING RETURNING id', [$name,$lesson['subject'],$teacher])->fetchColumn();
        if (!$topicId) {
            $topicId = teacherQuery($db, 'SELECT id FROM public.lesson_topics
                WHERE subj_id=? AND teacher_id=? AND lower(btrim(name))=lower(?)',
                [$lesson['subject'],$teacher,$name])->fetchColumn();
        }
    }
    if (!in_array($type, TEACHER_LESSON_TYPES, true)) {
        throw new TeacherRequestError('Выберите вид занятия.', 400);
    }
    if ($type === 'Экзамен' && $lesson['lesson_type'] !== 'Экзамен' &&
        teacherQuery($db, "SELECT 1 FROM public.evaluations
            WHERE lesson_id=? AND student_id IS NOT NULL AND NULLIF(BTRIM(grade), '') IS NOT NULL
            GROUP BY student_id HAVING COUNT(*) > 1 LIMIT 1", [$lessonId])->fetchColumn()
    ) {
        throw new TeacherRequestError('Уберите лишние отметки у студентов, прежде чем сделать занятие экзаменом.', 409);
    }
    teacherQuery(
        $db,
        "UPDATE public.lessons SET topic = ?, lesson_type = ? WHERE id = ?",
        [$topicId, $type, $lessonId],
    );
    return "Тема и вид занятия сохранены.";
}

function teacherSaveAttestation(PDO $db, int $teacher, array $input): string
{
    $kind = $input["kind"] ?? "";
    $grade = $input["grade"] ?? null;
    $previous = $input["previous"] ?? null;
    $period = $input["period"] ?? null;
    if (
        !is_string($kind) ||
        !isset(TEACHER_ATTESTATION_TYPES[$kind]) ||
        !in_array(
            $grade,
            $kind === "credit"
                ? ["зачтено", "не зачтено", "н/а"]
                : TEACHER_RESULT_VALUES,
            true,
        ) ||
        ($previous !== null &&
            !in_array(
                $previous,
                $kind === "credit"
                    ? ["зачтено", "не зачтено", "н/а"]
                    : TEACHER_RESULT_VALUES,
                true,
            )) ||
        !is_string($period) ||
        !preg_match("/\A([1-9][0-9]{3})-(0[1-9]|1[0-2])-01\z/", $period, $parts)
    ) {
        throw new TeacherRequestError("Некорректные данные аттестации.", 400);
    }

    $month = (int) $parts[2];
    if (
        ($kind === "month" && in_array($month, [7, 8], true)) ||
        ($kind !== "month" && !in_array($month, [1, 9], true))
    ) {
        throw new TeacherRequestError(
            "Выберите учебный месяц или семестр.",
            400,
        );
    }

    $student = teacherId($input["student"] ?? null);
    $group = teacherId($input["group"] ?? null);
    $subject = teacherId($input["subject"] ?? null);
    $year = (int) $parts[1] - ($month <= 6 ? 1 : 0);

    if (in_array($kind, ['exam','diff','credit'], true)) {
        $assigned = teacherQuery($db, 'SELECT 1 FROM public.diary_statements
            WHERE group_id=? AND subject_id=? AND period_start=? AND teacher_id=? AND kind=? FOR SHARE',
            [$group,$subject,$period,$teacher,$kind])->fetchColumn();
        if (!$assigned) {
            throw new TeacherRequestError('Форма контроля или преподаватель не совпадает с ведомостью.', 409);
        }
    } elseif (!teacherQuery($db, 'SELECT 1 FROM public.lessons
        WHERE teacher=? AND in_group=? AND subject=? AND date>=? AND date<? LIMIT 1',
        [$teacher,$group,$subject,"$year-09-01",($year+1)."-07-01"])->fetchColumn()) {
        throw new TeacherRequestError('В этом учебном году у вас нет занятий по предмету в выбранной группе.', 404);
    }
    teacherLockStudent($db, $student, $group);

    $key = [$teacher, $group, $subject, $period, $kind, $student];
    if (($input["action"] ?? "") === "attestation_delete") {
        if ($previous === null) {
            throw new TeacherRequestError(
                "Отметка уже удалена. Обновите страницу.",
                409,
            );
        }
        $deleted = teacherQuery(
            $db,
            "DELETE FROM public.teacher_attestations WHERE teacher_id=? AND group_id=? AND subject_id=? AND period_start=? AND kind=? AND student_id=? AND grade=?",
            [...$key, $previous],
        );
        if ($deleted->rowCount() !== 1) {
            throw new TeacherRequestError(
                "Отметка уже изменилась. Обновите страницу.",
                409,
            );
        }
        return "Отметка удалена.";
    }
    if (($input["action"] ?? "") === "result_details") {
        if (
            !in_array($kind, ["exam", "diff", "credit"], true) ||
            $previous === null
        ) {
            throw new TeacherRequestError(
                "Сначала выставьте итоговую отметку.",
                400,
            );
        }
        $date = $input["assessed_on"] ?? "";
        $parsed = is_string($date)
            ? DateTimeImmutable::createFromFormat("!Y-m-d", $date)
            : false;
        $statement = $input["statement_number"] ?? "";
        if (
            !is_string($date) ||
            ($date !== "" &&
                (!$parsed || $parsed->format("Y-m-d") !== $date)) ||
            !is_string($statement) ||
            strlen($statement) > 200 ||
            !is_array($input["expectedDetails"] ?? null)
        ) {
            throw new TeacherRequestError(
                "Проверьте дату и номер ведомости.",
                400,
            );
        }
        $expected = $input["expectedDetails"];
        $updated = teacherQuery(
            $db,
            "UPDATE public.teacher_attestations SET assessed_on=?,statement_number=? WHERE teacher_id=? AND group_id=? AND subject_id=? AND period_start=? AND kind=? AND student_id=? AND grade=? AND assessed_on IS NOT DISTINCT FROM ?::date AND statement_number IS NOT DISTINCT FROM ?::text",
            [
                $date ?: null,
                trim($statement) ?: null,
                ...$key,
                $previous,
                $expected["assessed_on"] ?? null,
                $expected["statement_number"] ?? null,
            ],
        );
        if ($updated->rowCount() !== 1) {
            throw new TeacherRequestError(
                "Запись уже изменилась. Обновите страницу.",
                409,
            );
        }
        return "Сведения сохранены.";
    }
    if ($previous === null) {
        $saved = teacherQuery(
            $db,
            <<<'SQL'
            INSERT INTO public.teacher_attestations
                (teacher_id, group_id, subject_id, period_start, kind, student_id, grade)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT DO NOTHING
            SQL
            ,
            [...$key, $grade],
        );
    } else {
        $saved = teacherQuery(
            $db,
            <<<'SQL'
            UPDATE public.teacher_attestations
            SET previous_grade = CASE WHEN kind = 'exam' AND grade <> ? THEN grade ELSE previous_grade END,
                grade = ?
            WHERE teacher_id = ? AND group_id = ? AND subject_id = ? AND period_start = ?
              AND kind = ? AND student_id = ? AND grade = ?
            SQL
            ,
            [$grade, $grade, ...$key, $previous],
        );
    }
    if ($saved->rowCount() !== 1) {
        throw new TeacherRequestError(
            "Аттестация уже изменилась. Обновите страницу и проверьте ячейку.",
            409,
        );
    }

    return "Результат аттестации сохранён.";
}


function teacherAutoAttestation(PDO $db, int $teacher, array $input): string
{
    $group = teacherId($input['group'] ?? null);
    $subject = teacherId($input['subject'] ?? null);
    $year = filter_var($input['year'] ?? null, FILTER_VALIDATE_INT, [
        'options'=>['min_range'=>1900, 'max_range'=>9998],
    ]);
    $semester = $input['semester'] ?? null;
    $month = $input['month'] ?? null;
    $months = match ($semester) {
        1 => [9, 10, 11, 12],
        2 => [1, 2, 3, 4, 5, 6],
        default => [],
    };
    if (!$year || !$months || ($month !== 0 && !in_array($month, $months, true))) {
        throw new TeacherRequestError('Выберите учебный год, семестр и месяц.', 400);
    }
    $startOf = static fn (int $m): DateTimeImmutable =>
        new DateTimeImmutable(sprintf('%04d-%02d-01', $year + ($m < 9 ? 1 : 0), $m));
    $periods = [];
    $parameters = [];
    foreach ($month ? [$month] : $months as $m) {
        $start = $startOf($m);
        $periods[] = '(?::date, ?::date)';
        array_push($parameters, $start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d'));
    }
    $student = ($input['student'] ?? 0) === 0 ? 0 : teacherId($input['student']);
    if ($student) {
        teacherLockStudent($db, $student, $group);
    }

    $values = implode(', ', $periods);
    $sql = <<<SQL
        WITH periods(start_date, end_date) AS (VALUES $values)
        INSERT INTO public.teacher_attestations
            (teacher_id, group_id, subject_id, student_id, period_start, kind, grade)
        SELECT l.teacher, l.in_group, l.subject, e.student_id, p.start_date, 'month',
               ROUND(AVG(CASE WHEN e.grade IN ('2','3','4','5') THEN e.grade::integer END))::text
        FROM periods p JOIN public.lessons l ON l.date >= p.start_date AND l.date < p.end_date
        JOIN public.evaluations e ON e.lesson_id=l.id
        JOIN public.students s ON s.id=e.student_id AND s.in_group=l.in_group
        WHERE l.teacher=? AND l.in_group=? AND l.subject=?
          AND e.grade IN ('2','3','4','5') AND (?::integer=0 OR s.id=?)
        GROUP BY l.teacher, l.in_group, l.subject, e.student_id, p.start_date
        ON CONFLICT DO NOTHING
        SQL;
    $count = teacherQuery($db, $sql, [...$parameters, $teacher, $group, $subject, $student, $student])->rowCount();

    // Читаем месячные отметки после записи, в той же транзакции.
    $first = $startOf($months[0])->format('Y-m-d');
    $end = $startOf($months[count($months)-1])->modify('+1 month')->format('Y-m-d');
    $semesters = teacherQuery($db, <<<'SQL'
        INSERT INTO public.teacher_attestations
            (teacher_id, group_id, subject_id, student_id, period_start, kind, grade)
        SELECT a.teacher_id,a.group_id,a.subject_id,a.student_id,?::date,'semester',
               ROUND(AVG(CASE WHEN a.grade IN ('2','3','4','5') THEN a.grade::int END))::text
        FROM public.teacher_attestations a JOIN public.students s ON s.id=a.student_id AND s.in_group=a.group_id
        WHERE a.teacher_id=? AND a.group_id=? AND a.subject_id=? AND a.kind='month'
          AND a.period_start>=? AND a.period_start<? AND (?::integer=0 OR s.id=?)
        GROUP BY a.teacher_id,a.group_id,a.subject_id,a.student_id
        HAVING COUNT(*)=? AND COUNT(*) FILTER (WHERE a.grade IN ('2','3','4','5'))>0
        ON CONFLICT DO NOTHING
        SQL, [$first,$teacher,$group,$subject,$first,$end,$student,$student,count($months)])->rowCount();
    return "Заполнено месячных отметок: $count. Семестровых: $semesters.";
}
