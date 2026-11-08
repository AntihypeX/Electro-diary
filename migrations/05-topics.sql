BEGIN;
ALTER TABLE public.lesson_topics ADD COLUMN IF NOT EXISTS lesson_type text;
DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='public.lesson_topics'::regclass AND conname='lesson_topics_type_check') THEN
  ALTER TABLE public.lesson_topics ADD CONSTRAINT lesson_topics_type_check CHECK (lesson_type IN ('Лекция','Практическая','Экзамен'));
 END IF;
END $$;
COMMENT ON COLUMN public.lesson_topics.lesson_type IS 'Вид занятия для темы. NULL — задаётся для конкретной пары';
COMMIT;
