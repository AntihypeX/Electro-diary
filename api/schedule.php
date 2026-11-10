<?php

function scheduleDate(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/\A[1-9]\d{3}-\d{2}-\d{2}\z/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function scheduleSlots(PDO $db): array
{
    return cabQuery($db, "SELECT number, to_char(starts_at, 'HH24:MI') || ' – ' || to_char(ends_at, 'HH24:MI') AS hours
        FROM public.lesson_slots ORDER BY number")->fetchAll(PDO::FETCH_KEY_PAIR);
}

function secretaryAddLesson(PDO $db, array $input): DateTimeImmutable
{
    $date = scheduleDate($input['day'] ?? null);
    $group = filter_var($input['group'] ?? null, FILTER_VALIDATE_INT);
    $subject = filter_var($input['subject'] ?? null, FILTER_VALIDATE_INT);
    $rawTopic = $input['topic'] ?? '';
    $topic = $rawTopic === '' ? null : filter_var($rawTopic, FILTER_VALIDATE_INT);
    $slot = filter_var($input['slot'] ?? null, FILTER_VALIDATE_INT);
    $type = $input['lesson_type'] ?? null;
    $period = $date ? statementPeriod($date) : null;
    if (!$date || !$group || !$subject || !$slot || $topic === false
        || !in_array($type, TEACHER_LESSON_TYPES, true)) {
        throw new RuntimeException('Проверьте дату, группу, номер пары, предмет, тему и вид занятия.');
    }
    $teacher = $period ? cabQuery($db, 'SELECT teacher_id FROM public.diary_statements
        WHERE group_id=? AND subject_id=? AND period_start=? FOR SHARE', [$group, $subject, $period])->fetchColumn() : false;
    if (!$teacher) {
        throw new RuntimeException('На этот предмет и семестр нет ведомости выбранной группы.');
    }
    if (!cabQuery($db, 'SELECT 1 FROM public.lesson_slots WHERE number=?', [$slot])->fetchColumn()
        || ($topic !== null && !cabQuery($db, 'SELECT 1 FROM public.lesson_topics
            WHERE id=? AND subj_id=? AND (teacher_id IS NULL OR teacher_id=?)', [$topic, $subject, $teacher])->fetchColumn())) {
        throw new RuntimeException('Проверьте номер пары и тему выбранного предмета.');
    }
    // Проверка занятости и создание пары выполняются в одной транзакции.
    $db->exec('LOCK TABLE public.lessons IN SHARE ROW EXCLUSIVE MODE');
    $day = $date->format('Y-m-d');
    if (cabQuery($db, 'SELECT 1 FROM public.lessons WHERE date=? AND num_in_table=?
        AND (in_group=? OR teacher=?) LIMIT 1', [$day, $slot, $group, $teacher])->fetchColumn()) {
        throw new RuntimeException('На это время у группы или преподавателя уже есть занятие.');
    }
    cabQuery($db, 'INSERT INTO public.lessons (in_group, teacher, subject, topic, date, num_in_table, lesson_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)', [$group, $teacher, $subject, $topic, $day, $slot, $type]);
    return $date;
}

function secretarySchedule(PDO $db): void
{
    $groups = cabQuery($db, 'SELECT id, name FROM public.stud_groups ORDER BY name,id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $group = filter_var($_GET['group'] ?? null, FILTER_VALIDATE_INT);
    if (!isset($groups[$group])) {
        $group = array_key_first($groups);
    }
    if (!$group) {
        echo '<p class="panel cab-empty">Сначала создайте группу.</p>';
        return;
    }
    $day = scheduleDate($_GET['day'] ?? null) ?? new DateTimeImmutable('today', new DateTimeZone('Asia/Krasnoyarsk'));
    $period = statementPeriod($day);
    $entries = $period ? cabQuery($db, 'SELECT d.*,s.name,t.fio FROM public.diary_statements d
        JOIN public.subjects s ON s.id=d.subject_id JOIN public.teachers t ON t.id=d.teacher_id
        WHERE d.group_id=? AND d.period_start=? ORDER BY s.name,s.id',
        [$group,$period])->fetchAll(PDO::FETCH_ASSOC) : [];
    $topics = $entries ? cabQuery($db, 'SELECT t.id,t.name,t.subj_id FROM public.lesson_topics t
        JOIN public.diary_statements d ON d.subject_id=t.subj_id AND (t.teacher_id IS NULL OR t.teacher_id=d.teacher_id)
        WHERE d.group_id=? AND d.period_start=? ORDER BY t.name,t.id', [$group,$period])->fetchAll(PDO::FETCH_ASSOC) : [];
    $slots = [];
    foreach (scheduleSlots($db) as $number => $hours) {
        $slots[$number] = "$number пара · $hours";
    }
    ?>
    <form method="get" class="cab-filters">
        <input type="hidden" name="tab" value="schedule">
        <?php cabSelect('group', 'Группа', $groups, $group); ?>
        <label>Дата<input type="date" name="day" value="<?= ch($day->format('Y-m-d')) ?>" required></label>
        <button class="cab-button" type="submit">Открыть день</button>
    </form>
    <section class="panel">
        <header class="panel-head"><h3>Добавить пару · <?= ch($groups[$group]) ?> · <?= ch($day->format('d.m.Y')) ?></h3></header>
        <?php if (!$entries): ?>
            <p class="cab-empty">Для группы на этот семестр пока нет предметов в ведомости.
                <a class="cab-button" href="?<?= ch(http_build_query(['tab'=>'statements','group'=>$group,
                    'year'=>(int) $day->format('Y') - ((int) $day->format('n') < 9 ? 1 : 0),
                    'semester'=>(int) $day->format('n') >= 9 ? 1 : 2])) ?>">Открыть ведомости</a></p>
        <?php else: ?>
        <form method="post" class="cab-filters" data-schedule-form>
            <input type="hidden" name="csrf" value="<?= ch($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="day" value="<?= ch($day->format('Y-m-d')) ?>">
            <input type="hidden" name="group" value="<?= ch($group) ?>">
            <?php
            cabSelect('slot', 'Номер пары', $slots, '');
            ?>
            <label>Предмет<select name="subject" required>
                <?php foreach ($entries as $entry): ?>
                    <option value="<?= ch($entry['subject_id']) ?>" data-teacher="<?= ch($entry['fio']) ?>"
                        data-hours="<?= ch($entry['hours']) ?>" data-control="<?= ch(DIARY_CONTROL_FORMS[$entry['kind']]) ?>"><?= ch($entry['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label>Преподаватель<input name="teacher_display" readonly></label>
            <label>Количество часов<input name="hours_display" readonly></label>
            <label>Форма контроля<input name="control_display" readonly></label>
            <label>Тема<select name="topic">
                <option value="">Преподаватель укажет позже</option>
                <?php foreach ($topics as $topic): ?>
                    <option value="<?= ch($topic['id']) ?>" data-subject="<?= ch($topic['subj_id']) ?>"><?= ch($topic['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
            <?php cabSelect('lesson_type', 'Вид занятия', array_combine(TEACHER_LESSON_TYPES, TEACHER_LESSON_TYPES), 'Лекция'); ?>
            <button class="primary" type="submit">Добавить пару</button>
        </form>
        <?php endif; ?>
    </section>
    <?php
    cabSchedule($db, 'secretary', ['in_group'=>$group]);
}
