BEGIN;
CREATE INDEX IF NOT EXISTS evaluations_student_lesson_idx ON public.evaluations(student_id, lesson_id);
CREATE INDEX IF NOT EXISTS lessons_group_subject_date_idx ON public.lessons(in_group, subject, date);
CREATE INDEX IF NOT EXISTS teacher_attestations_group_period_idx ON public.teacher_attestations(group_id, period_start, student_id);
COMMIT;
