BEGIN;

ALTER TABLE public.lessons
    ADD COLUMN IF NOT EXISTS lesson_type text;

CREATE INDEX IF NOT EXISTS lessons_teacher_group_subject_date_idx
    ON public.lessons (teacher, in_group, subject, date);

CREATE INDEX IF NOT EXISTS evaluations_lesson_student_idx
    ON public.evaluations (lesson_id, student_id);

CREATE TABLE IF NOT EXISTS public.teacher_attestations (
    teacher_id integer NOT NULL REFERENCES public.teachers (id),
    group_id integer NOT NULL REFERENCES public.stud_groups (id),
    subject_id integer NOT NULL REFERENCES public.subjects (id),
    student_id integer NOT NULL REFERENCES public.students (id),
    period_start date NOT NULL,
    kind text NOT NULL CHECK (kind IN ('month', 'semester', 'exam', 'diff')),
    grade character(1) NOT NULL CHECK (grade IN ('2', '3', '4', '5', 'н')),
    CHECK (
        EXTRACT(DAY FROM period_start) = 1
        AND CASE WHEN kind = 'month'
            THEN EXTRACT(MONTH FROM period_start) NOT IN (7, 8)
            ELSE EXTRACT(MONTH FROM period_start) IN (1, 9)
        END
    ),
    PRIMARY KEY (teacher_id, group_id, subject_id, period_start, kind, student_id)
);

COMMIT;
