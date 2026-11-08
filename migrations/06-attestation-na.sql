BEGIN;
ALTER TABLE public.teacher_attestations DROP CONSTRAINT IF EXISTS teacher_attestations_grade_check;
UPDATE public.teacher_attestations SET grade='н/а' WHERE BTRIM(grade) IN ('н','Н');
ALTER TABLE public.teacher_attestations ADD CONSTRAINT teacher_attestations_grade_check CHECK (
 (kind='credit' AND grade IN ('зачтено','не зачтено','н/а'))
 OR (kind<>'credit' AND grade IN ('2','3','4','5','н/а'))
);
COMMIT;
