<?php

const DIARY_CONTROL_FORMS = ['exam' => 'Экзамен', 'diff' => 'Диф. зачёт', 'credit' => 'Зачёт'];

function statementPeriod(DateTimeImmutable $day): ?string
{
    $month = (int) $day->format('n');
    if ($month === 7 || $month === 8) return null;
    return $day->format('Y') . ($month >= 9 ? '-09-01' : '-01-01');
}

function statementFilter(array $input): array
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Krasnoyarsk'));
    $month = (int) $today->format('n');
    $year = filter_var($input['year'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1900, 'max_range' => 9998],
    ]) ?: (int) $today->format('Y') - ($month < 9 ? 1 : 0);
    $semester = in_array($input['semester'] ?? null, [1, 2, '1', '2'], true)
        ? (int) $input['semester'] : ($month >= 9 ? 1 : 2);
    $period = $semester === 1 ? "$year-09-01" : ($year + 1) . '-01-01';
    return [$year, $semester, $period];
}

function secretarySaveStatement(PDO $db, array $input): array
{
    [$year, $semester, $period] = statementFilter($input);
    $group = filter_var($input['group'] ?? null, FILTER_VALIDATE_INT);
    $subject = filter_var($input['subject'] ?? null, FILTER_VALIDATE_INT);
    $teacher = filter_var($input['teacher'] ?? null, FILTER_VALIDATE_INT);
    $hours = filter_var($input['hours'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 10000],
    ]);
    $kind = $input['kind'] ?? null;
    if (!filter_var($input['year'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1900,'max_range'=>9998]])
        || !in_array($input['semester'] ?? null, [1,2,'1','2'], true)) {
        throw new RuntimeException('Укажите учебный год и семестр.');
    }
    if (!$group || !$subject || !$teacher || !$hours || !is_string($kind) || !isset(DIARY_CONTROL_FORMS[$kind])) {
        throw new RuntimeException('Укажите группу, предмет, часы, форму контроля и преподавателя.');
    }

    // Блокировка строки также синхронизируется с записью итоговых оценок.
    $db->exec('LOCK TABLE public.diary_statements IN SHARE ROW EXCLUSIVE MODE');
    $key = [$group, $subject, $period];
    $old = cabQuery($db, 'SELECT teacher_id,kind FROM public.diary_statements
        WHERE group_id=? AND subject_id=? AND period_start=? FOR UPDATE', $key)->fetch(PDO::FETCH_ASSOC);
    if ($old && ((int) $old['teacher_id'] !== $teacher || $old['kind'] !== $kind)
        && cabQuery($db, 'SELECT 1 FROM public.teacher_attestations
            WHERE group_id=? AND subject_id=? AND period_start=? AND teacher_id=? AND kind=?
              AND student_id IS NOT NULL LIMIT 1', [...$key, $old['teacher_id'], $old['kind']])->fetchColumn()) {
        throw new RuntimeException('По ведомости уже выставлены итоговые оценки. Можно изменить только количество часов.');
    }
    cabQuery($db, 'INSERT INTO public.diary_statements (group_id,subject_id,period_start,teacher_id,hours,kind)
        VALUES (?,?,?,?,?,?) ON CONFLICT (group_id,subject_id,period_start) DO UPDATE
        SET teacher_id=EXCLUDED.teacher_id,hours=EXCLUDED.hours,kind=EXCLUDED.kind',
        [...$key, $teacher, $hours, $kind]);
    return ['tab'=>'statements', 'year'=>$year, 'semester'=>$semester, 'group'=>$group];
}

function secretaryStatements(PDO $db): void
{
    [$year, $semester, $period] = statementFilter($_GET);
    $groups = cabQuery($db, 'SELECT id,name FROM public.stud_groups ORDER BY name,id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $group = filter_var($_GET['group'] ?? null, FILTER_VALIDATE_INT);
    if (!isset($groups[$group])) $group = array_key_first($groups);
    if (!$group) {
        echo '<p class="panel cab-empty">Сначала создайте группу.</p>';
        return;
    }
    $subjects = cabQuery($db, 'SELECT id,name FROM public.subjects ORDER BY name,id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $teachers = cabQuery($db, "SELECT id,fio FROM public.teachers ORDER BY public.diary_name_sort(fio) COLLATE \"C\",fio,id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $entries = cabQuery($db, 'SELECT d.* FROM public.diary_statements d JOIN public.subjects s ON s.id=d.subject_id
        WHERE d.group_id=? AND d.period_start=? ORDER BY s.name,s.id', [$group, $period])->fetchAll(PDO::FETCH_ASSOC);
    $selected = filter_var($_GET['subject'] ?? null, FILTER_VALIDATE_INT);
    $form = [];
    foreach ($entries as $entry) {
        if ((int) $entry['subject_id'] === $selected) $form = $entry;
    }
    $filters = ['tab'=>'statements','year'=>$year,'semester'=>$semester,'group'=>$group];
    ?>
    <form method="get" class="cab-filters">
        <input type="hidden" name="tab" value="statements">
        <label>Начало учебного года<input type="number" name="year" min="1900" max="9998" value="<?= ch($year) ?>" required></label>
        <?php cabSelect('semester','Семестр',[1=>'Первый',2=>'Второй'],$semester); ?>
        <?php cabSelect('group','Группа',$groups,$group); ?>
        <button class="cab-button" type="submit">Открыть ведомость</button>
    </form>
    <section class="panel">
        <header class="panel-head"><h3><?= $form ? 'Изменить предмет в ведомости' : 'Добавить предмет в ведомость' ?></h3></header>
        <form method="post" class="cab-filters">
            <input type="hidden" name="csrf" value="<?= ch($_SESSION['csrf_token']) ?>">
            <?php foreach (array_diff_key($filters, ['tab'=>true]) as $key => $value): ?>
                <input type="hidden" name="<?= ch($key) ?>" value="<?= ch($value) ?>">
            <?php endforeach; ?>
            <?php if ($form): ?>
                <input type="hidden" name="subject" value="<?= ch($selected) ?>">
                <label>Предмет<input readonly value="<?= ch($subjects[$selected]) ?>"></label>
            <?php else: ?>
                <?php cabSelect('subject','Предмет',[''=>'Выберите предмет']+$subjects,''); ?>
            <?php endif; ?>
            <label>Количество часов<input type="number" name="hours" min="1" max="10000" value="<?= ch($form['hours'] ?? '') ?>" required></label>
            <?php cabSelect('kind','Форма контроля',DIARY_CONTROL_FORMS,$form['kind'] ?? 'exam'); ?>
            <?php cabSelect('teacher','Преподаватель',[''=>'Выберите преподавателя']+$teachers,$form['teacher_id'] ?? ''); ?>
            <button class="primary" type="submit">Сохранить</button>
        </form>
    </section>
    <section class="panel">
        <header class="panel-head"><h3>Ведомость · <?= ch($groups[$group]) ?></h3><span class="count"><?= count($entries) ?></span></header>
        <?php if (!$entries): ?><p class="cab-empty">В ведомости пока нет предметов.</p><?php else: ?>
            <div class="cab-scroll"><table class="cab-table"><thead><tr><th>Предмет</th><th>Часы</th><th>Форма контроля</th><th>Преподаватель</th><th>Действия</th></tr></thead><tbody>
            <?php foreach ($entries as $entry): ?>
                <tr><td><?= ch($subjects[$entry['subject_id']]) ?></td><td><?= ch($entry['hours']) ?></td><td><?= ch(DIARY_CONTROL_FORMS[$entry['kind']]) ?></td><td><?= ch($teachers[$entry['teacher_id']]) ?></td>
                    <td><a class="cab-button" href="?<?= ch(http_build_query($filters + ['subject'=>$entry['subject_id']])) ?>">Изменить</a></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
    <?php
}

function teacherFinals(PDO $db, array $profile, bool $download = false): void
{
    [$year, $semester, $period] = statementFilter($_GET);
    $assignments = cabQuery($db, 'SELECT d.group_id,d.subject_id,d.hours,d.kind,g.name AS group_name,s.name AS subject
        FROM public.diary_statements d
        JOIN public.stud_groups g ON g.id=d.group_id JOIN public.subjects s ON s.id=d.subject_id
        WHERE d.teacher_id=? AND d.period_start=? ORDER BY g.name,g.id,s.name,s.id',
        [$profile['id'],$period])->fetchAll(PDO::FETCH_ASSOC);
    $groups = array_column($assignments, 'group_name', 'group_id');
    $group = filter_var($_GET['group'] ?? null, FILTER_VALIDATE_INT);
    if (!isset($groups[$group])) $group = array_key_first($groups);
    $subjects = [];
    foreach ($assignments as $assignment) {
        if ((int) $assignment['group_id'] === $group) $subjects[$assignment['subject_id']] = $assignment;
    }
    $subject = filter_var($_GET['subject'] ?? null, FILTER_VALIDATE_INT);
    if (!isset($subjects[$subject])) $subject = array_key_first($subjects);
    if ($download && (!$group || !$subject
        || (string) $group !== ($_GET['group'] ?? null) || (string) $subject !== ($_GET['subject'] ?? null)
        || (string) $year !== ($_GET['year'] ?? null) || (string) $semester !== ($_GET['semester'] ?? null))) {
        http_response_code(404);
        echo 'Ведомость не найдена.';
        return;
    }
    $roster = $group ? cabQuery($db, "SELECT id,fio FROM public.students WHERE in_group=?
        ORDER BY public.diary_name_sort(fio) COLLATE \"C\",fio,id", [$group])->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $requestedStudent = filter_var($_GET['student'] ?? 0, FILTER_VALIDATE_INT, [
        'options'=>['min_range'=>0,'max_range'=>2147483647],
    ]);
    $student = isset($roster[$requestedStudent]) ? $requestedStudent : 0;
    if ($download && $student !== $requestedStudent) {
        http_response_code(404);
        echo 'Студент не найден.';
        return;
    }

    $records = [];
    if ($subject) {
        $plan = $subjects[$subject];
        $kind = $plan['kind'];
        $rows = cabQuery($db, 'SELECT * FROM public.teacher_attestations
            WHERE teacher_id=? AND group_id=? AND subject_id=? AND period_start=? AND kind=?
              AND student_id IS NOT NULL', [$profile['id'],$group,$subject,$period,$kind])->fetchAll(PDO::FETCH_ASSOC);
        $marks = array_column($rows, null, 'student_id');
        foreach ($roster as $id => $fio) {
            if ($student && $student !== (int) $id) continue;
            $record = $marks[$id] ?? [];
            $records[] = $record + [
                'planned_hours'=>$plan['hours'],
                'subject'=>$plan['subject'],
                'fio'=>$profile['fio'],
                'student_name'=>$fio,
                'period_start'=>$period,
                'kind'=>$kind,
                '_edit'=>[
                    'kind'=>$kind, 'period'=>$period, 'group'=>$group, 'subject'=>$subject,
                    'student'=>$id, 'previous'=>$record['grade'] ?? null,
                ],
            ];
        }
    }
    if ($download) {
        teacherFinalsCsv($records, $groups[$group], $group, $subject, $year, $semester);
        return;
    }
    ?>
    <form class="cab-filters">
        <input type="hidden" name="tab" value="finals">
        <label>Начало учебного года<input type="number" name="year" min="1900" max="9998" value="<?= ch($year) ?>" required></label>
        <?php cabSelect('semester','Семестр',[1=>'Первый',2=>'Второй'],$semester); ?>
        <?php cabSelect('group','Группа',$groups ?: [0=>'Нет групп'],$group); ?>
        <?php cabSelect('subject','Предмет',array_column($subjects,'subject','subject_id') ?: [0=>'Нет предметов'],$subject); ?>
        <?php cabSelect('student','Студент',[0=>'Все студенты']+$roster,$student); ?>
        <?= cabinetResetLink() ?>
    </form>
    <?php
    if (!$subject) {
        echo '<p class="panel cab-empty">Секретарь ещё не назначил вам предметы в ведомости за этот семестр.</p>';
        return;
    }
    $url = '?' . http_build_query(['tab'=>'finals','download'=>'csv','year'=>$year,'semester'=>$semester,
        'group'=>$group,'subject'=>$subject,'student'=>$student]);
    echo '<div class="cab-actions"><a class="cab-button" href="' . ch($url) . '">Скачать ведомость (CSV)</a></div>';
    cabRecordBook($records, true);
}

function teacherFinalsCsv(array $records, string $groupName, int $group, int $subject, int $year, int $semester): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=finals-$year-s$semester-g$group-d$subject.csv");
    header('X-Content-Type-Options: nosniff');
    $file = fopen('php://output', 'wb');
    fwrite($file, "\xEF\xBB\xBF");
    $write = static function (array $cells) use ($file): void {
        $cells = array_map(static function ($cell): string {
            $value = (string) $cell;
            // Spreadsheet applications must interpret text from the database as text.
            return preg_match('/\A(?:[\x00-\x20]*[=+@-]|[\t\r\n])/', $value) ? "'" . $value : $value;
        }, $cells);
        fputcsv($file, $cells, ';', '"', '', "\r\n");
    };
    $write(['Группа','Учебный год','Семестр','Студент','Предмет','Часы','Преподаватель','Форма контроля',
        'Итоговая оценка','Предыдущая оценка','Номер ведомости','Дата выставления']);
    foreach ($records as $record) {
        $write([$groupName, "{$year}–" . ($year + 1), $semester, $record['student_name'], $record['subject'],
            $record['planned_hours'], $record['fio'], DIARY_CONTROL_FORMS[$record['kind']], $record['grade'] ?? '',
            $record['kind'] === 'exam' ? ($record['previous_grade'] ?? '') : '',
            $record['statement_number'] ?? '', $record['assessed_on'] ?? '']);
    }
    fclose($file);
}
