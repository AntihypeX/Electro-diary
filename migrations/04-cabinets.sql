BEGIN;
ALTER TABLE public.teacher_attestations DROP CONSTRAINT IF EXISTS teacher_attestations_kind_check;
ALTER TABLE public.teacher_attestations DROP CONSTRAINT IF EXISTS teacher_attestations_grade_check;
ALTER TABLE public.teacher_attestations ALTER COLUMN grade TYPE text USING BTRIM(grade);
ALTER TABLE public.teacher_attestations ADD CONSTRAINT teacher_attestations_kind_check CHECK (kind IN ('month','semester','exam','diff','credit'));
ALTER TABLE public.teacher_attestations ADD CONSTRAINT teacher_attestations_grade_check CHECK (
  (kind = 'credit' AND grade IN ('зачтено','не зачтено')) OR
  (kind <> 'credit' AND grade IN ('2','3','4','5','н'))
);
CREATE TABLE IF NOT EXISTS public.user_avatars (
 auth_id integer PRIMARY KEY REFERENCES public.auth(id) ON DELETE CASCADE,
 mime text NOT NULL CHECK (mime IN ('image/jpeg','image/png','image/webp')),
 content text NOT NULL
);
COMMIT;
