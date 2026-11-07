-- Расширение карточки студента для существующей базы Electro-diary (PostgreSQL).
-- Откройте этот файл в Query Tool нужной базы и выполните целиком.
-- Скрипт добавляет структуру. Реальные сведения заполняются отдельно.
-- Повторный запуск допустим. Все изменения выполняются в одной транзакции.
-- Неизвестные сведения остаются NULL, включая ответы да/нет.
-- Связанные разделы необязательны. При чтении карточки используйте LEFT JOIN.
-- Семья и предыдущее образование возвращают несколько записей на студента.
-- ФИО, группа, дата рождения, телефон и почта сохраняются в students.

BEGIN;

-- 1. Студент: личные сведения и контакты.
ALTER TABLE public.students
    ADD COLUMN IF NOT EXISTS birth_date date,
    ADD COLUMN IF NOT EXISTS phone text,
    ADD COLUMN IF NOT EXISTS email text,
    ADD COLUMN IF NOT EXISTS birth_place text,
    ADD COLUMN IF NOT EXISTS citizenship text,
    ADD COLUMN IF NOT EXISTS nationality text;

COMMENT ON COLUMN public.students.birth_date IS 'Дата рождения';
COMMENT ON COLUMN public.students.phone IS 'Контактный телефон студента';
COMMENT ON COLUMN public.students.email IS 'Электронная почта студента';
COMMENT ON COLUMN public.students.birth_place IS 'Место рождения';
COMMENT ON COLUMN public.students.citizenship IS 'Гражданство';
COMMENT ON COLUMN public.students.nationality IS 'Национальность (этническая принадлежность)';

-- 2. Документы: паспорт или иной документ, СНИЛС, ИНН.
CREATE TABLE IF NOT EXISTS public.student_documents (
    student_id integer PRIMARY KEY REFERENCES public.students(id),
    identity_document_type text,
    identity_document_series text,
    identity_document_number text,
    identity_document_issued_on date,
    identity_document_issued_by text,
    identity_document_department_code text,
    identity_document_valid_until date,
    snils text CHECK (snils ~ '^[0-9]{11}$'),
    inn text CHECK (inn ~ '^[0-9]{12}$'),
    CHECK (
        identity_document_valid_until IS NULL
        OR identity_document_issued_on IS NULL
        OR identity_document_valid_until >= identity_document_issued_on
    )
);

COMMENT ON TABLE public.student_documents IS 'Документы студента. Одна карточка на студента';
COMMENT ON COLUMN public.student_documents.student_id IS 'Ссылка на students.id';
COMMENT ON COLUMN public.student_documents.identity_document_type IS 'Вид документа: например, паспорт РФ или иностранный паспорт';
COMMENT ON COLUMN public.student_documents.identity_document_series IS 'Серия документа. Текст сохраняет ведущие нули';
COMMENT ON COLUMN public.student_documents.identity_document_number IS 'Номер документа';
COMMENT ON COLUMN public.student_documents.identity_document_issued_on IS 'Дата выдачи документа';
COMMENT ON COLUMN public.student_documents.identity_document_issued_by IS 'Кем выдан документ';
COMMENT ON COLUMN public.student_documents.identity_document_department_code IS 'Код подразделения, если применим';
COMMENT ON COLUMN public.student_documents.identity_document_valid_until IS 'Срок действия, если указан в документе';
COMMENT ON COLUMN public.student_documents.snils IS 'СНИЛС: 11 цифр без пробелов и дефисов. Проверяется формат';
COMMENT ON COLUMN public.student_documents.inn IS 'ИНН физического лица: 12 цифр. Проверяется формат';

-- 3. Адреса: прописка и фактическое проживание.
CREATE TABLE IF NOT EXISTS public.student_addresses (
    student_id integer NOT NULL REFERENCES public.students(id),
    address_type text NOT NULL CHECK (address_type IN ('registered', 'residential')),
    country text,
    region text,
    district text,
    city text,
    settlement text,
    street text,
    house text,
    building text,
    apartment text,
    postal_code text,
    registration_date date,
    PRIMARY KEY (student_id, address_type)
);

COMMENT ON TABLE public.student_addresses IS 'Адреса студента. До двух записей: прописка и проживание';
COMMENT ON COLUMN public.student_addresses.student_id IS 'Ссылка на students.id';
COMMENT ON COLUMN public.student_addresses.address_type IS 'registered — прописка, residential — фактическое проживание';
COMMENT ON COLUMN public.student_addresses.country IS 'Страна';
COMMENT ON COLUMN public.student_addresses.region IS 'Регион: область, край, республика и т. п.';
COMMENT ON COLUMN public.student_addresses.district IS 'Район';
COMMENT ON COLUMN public.student_addresses.city IS 'Город';
COMMENT ON COLUMN public.student_addresses.settlement IS 'Населённый пункт, если отличается от города';
COMMENT ON COLUMN public.student_addresses.street IS 'Улица';
COMMENT ON COLUMN public.student_addresses.house IS 'Дом, включая литеру или дробь';
COMMENT ON COLUMN public.student_addresses.building IS 'Корпус или строение';
COMMENT ON COLUMN public.student_addresses.apartment IS 'Квартира или комната';
COMMENT ON COLUMN public.student_addresses.postal_code IS 'Почтовый индекс';
COMMENT ON COLUMN public.student_addresses.registration_date IS 'Дата регистрации по адресу, если известна';

-- 4. Семья: родители, опекуны и другие контактные лица.
CREATE TABLE IF NOT EXISTS public.student_family_contacts (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    student_id integer NOT NULL REFERENCES public.students(id),
    relationship text NOT NULL CHECK (btrim(relationship) <> ''),
    fio text NOT NULL CHECK (btrim(fio) <> ''),
    phone text,
    additional_phone text,
    email text,
    address text,
    workplace text,
    job_title text,
    is_legal_representative boolean,
    is_emergency_contact boolean
);

CREATE INDEX IF NOT EXISTS student_family_contacts_student_idx
    ON public.student_family_contacts(student_id);

COMMENT ON TABLE public.student_family_contacts IS 'Родители, опекуны и контакты. Несколько записей на студента';
COMMENT ON COLUMN public.student_family_contacts.id IS 'Идентификатор контактного лица в карточке студента';
COMMENT ON COLUMN public.student_family_contacts.student_id IS 'Ссылка на students.id';
COMMENT ON COLUMN public.student_family_contacts.relationship IS 'Кем приходится: мать, отец, опекун, другой родственник';
COMMENT ON COLUMN public.student_family_contacts.fio IS 'ФИО родителя или контактного лица';
COMMENT ON COLUMN public.student_family_contacts.phone IS 'Основной телефон';
COMMENT ON COLUMN public.student_family_contacts.additional_phone IS 'Дополнительный телефон';
COMMENT ON COLUMN public.student_family_contacts.email IS 'Электронная почта';
COMMENT ON COLUMN public.student_family_contacts.address IS 'Адрес контактного лица';
COMMENT ON COLUMN public.student_family_contacts.workplace IS 'Место работы';
COMMENT ON COLUMN public.student_family_contacts.job_title IS 'Должность';
COMMENT ON COLUMN public.student_family_contacts.is_legal_representative IS 'Является законным представителем: да/нет/неизвестно';
COMMENT ON COLUMN public.student_family_contacts.is_emergency_contact IS 'Контакт для экстренной связи: да/нет/неизвестно';

-- 5. Обучение: специальность, подгруппа, студенческий билет, зачисление.
CREATE TABLE IF NOT EXISTS public.education_specialties (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code text NOT NULL CHECK (btrim(code) <> ''),
    name text NOT NULL CHECK (btrim(name) <> ''),
    qualification text,
    UNIQUE (code, name)
);

COMMENT ON TABLE public.education_specialties IS 'Справочник специальностей';
COMMENT ON COLUMN public.education_specialties.id IS 'Идентификатор специальности';
COMMENT ON COLUMN public.education_specialties.code IS 'Код специальности';
COMMENT ON COLUMN public.education_specialties.name IS 'Название специальности';
COMMENT ON COLUMN public.education_specialties.qualification IS 'Присваиваемая квалификация';

CREATE TABLE IF NOT EXISTS public.student_education (
    student_id integer PRIMARY KEY REFERENCES public.students(id),
    specialty_id bigint REFERENCES public.education_specialties(id),
    subgroup text,
    student_card_number text,
    admission_date date,
    admission_order_number text,
    expulsion_date date,
    expulsion_order_number text,
    expulsion_reason text,
    study_form text CHECK (study_form IN ('full_time', 'part_time', 'mixed')),
    funding_type text CHECK (funding_type IN ('budget', 'paid')),
    needs_dormitory boolean,
    CHECK (expulsion_date IS NULL OR admission_date IS NULL OR expulsion_date >= admission_date)
);

CREATE INDEX IF NOT EXISTS student_education_specialty_idx
    ON public.student_education(specialty_id);

COMMENT ON TABLE public.student_education IS 'Текущие сведения об обучении студента. Группа хранится в students.in_group';
COMMENT ON COLUMN public.student_education.student_id IS 'Ссылка на students.id';
COMMENT ON COLUMN public.student_education.specialty_id IS 'Ссылка на education_specialties.id';
COMMENT ON COLUMN public.student_education.subgroup IS 'Подгруппа внутри текущей учебной группы';
COMMENT ON COLUMN public.student_education.student_card_number IS 'Номер студенческого билета';
COMMENT ON COLUMN public.student_education.admission_date IS 'Дата поступления / зачисления';
COMMENT ON COLUMN public.student_education.admission_order_number IS 'Номер приказа о зачислении';
COMMENT ON COLUMN public.student_education.expulsion_date IS 'Дата отчисления';
COMMENT ON COLUMN public.student_education.expulsion_order_number IS 'Номер приказа об отчислении';
COMMENT ON COLUMN public.student_education.expulsion_reason IS 'Причина отчисления';
COMMENT ON COLUMN public.student_education.study_form IS 'full_time — очная, part_time — заочная, mixed — очно-заочная';
COMMENT ON COLUMN public.student_education.funding_type IS 'budget — бюджетное обучение, paid — платное обучение';
COMMENT ON COLUMN public.student_education.needs_dormitory IS 'Нуждается в общежитии: да/нет/неизвестно';

-- 6. Предыдущее образование: школа, аттестат, диплом.
CREATE TABLE IF NOT EXISTS public.student_previous_education (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    student_id integer NOT NULL REFERENCES public.students(id),
    institution_name text NOT NULL CHECK (btrim(institution_name) <> ''),
    education_level text,
    graduation_year smallint CHECK (graduation_year BETWEEN 1900 AND 9999),
    document_type text,
    document_series text,
    document_number text,
    document_issued_on date
);

CREATE INDEX IF NOT EXISTS student_previous_education_student_idx
    ON public.student_previous_education(student_id);

COMMENT ON TABLE public.student_previous_education IS 'Оконченные учебные заведения и документы. Несколько записей на студента';
COMMENT ON COLUMN public.student_previous_education.id IS 'Идентификатор записи об образовании';
COMMENT ON COLUMN public.student_previous_education.student_id IS 'Ссылка на students.id';
COMMENT ON COLUMN public.student_previous_education.institution_name IS 'Название оконченной школы, колледжа или другого учебного заведения';
COMMENT ON COLUMN public.student_previous_education.education_level IS 'Полученное образование: например, 9 классов, 11 классов, СПО';
COMMENT ON COLUMN public.student_previous_education.graduation_year IS 'Год окончания';
COMMENT ON COLUMN public.student_previous_education.document_type IS 'Вид документа: аттестат, диплом и т. п.';
COMMENT ON COLUMN public.student_previous_education.document_series IS 'Серия аттестата или диплома';
COMMENT ON COLUMN public.student_previous_education.document_number IS 'Номер аттестата или диплома';
COMMENT ON COLUMN public.student_previous_education.document_issued_on IS 'Дата выдачи аттестата или диплома';

-- 7. Воинский учёт и сведения о судимости.
CREATE TABLE IF NOT EXISTS public.student_military_legal (
    student_id integer PRIMARY KEY REFERENCES public.students(id),
    military_registration_status text,
    military_office text,
    military_document_type text,
    military_document_series text,
    military_document_number text,
    military_fitness_category text,
    deferment_until date,
    has_criminal_record boolean,
    criminal_record_details text
);

COMMENT ON TABLE public.student_military_legal IS 'Сведения о воинском учёте и судимости';
COMMENT ON COLUMN public.student_military_legal.student_id IS 'Ссылка на students.id';
COMMENT ON COLUMN public.student_military_legal.military_registration_status IS 'Статус воинского учёта, включая неприменимость';
COMMENT ON COLUMN public.student_military_legal.military_office IS 'Наименование военкомата';
COMMENT ON COLUMN public.student_military_legal.military_document_type IS 'Вид документа: приписное свидетельство, военный билет и т. п.';
COMMENT ON COLUMN public.student_military_legal.military_document_series IS 'Серия документа воинского учёта';
COMMENT ON COLUMN public.student_military_legal.military_document_number IS 'Номер документа воинского учёта';
COMMENT ON COLUMN public.student_military_legal.military_fitness_category IS 'Категория годности, если указана';
COMMENT ON COLUMN public.student_military_legal.deferment_until IS 'Дата окончания отсрочки, если известна';
COMMENT ON COLUMN public.student_military_legal.has_criminal_record IS 'Наличие судимости: да/нет/неизвестно';
COMMENT ON COLUMN public.student_military_legal.criminal_record_details IS 'Уточнение сведений о судимости, в том числе о её погашении или снятии';

-- 8. Здоровье: медицинские показания и ограничения.
CREATE TABLE IF NOT EXISTS public.student_health (
    student_id integer PRIMARY KEY REFERENCES public.students(id),
    medical_indications text,
    medical_restrictions text,
    physical_education_group text,
    disability_details text
);

COMMENT ON TABLE public.student_health IS 'Медицинские сведения студента';
COMMENT ON COLUMN public.student_health.student_id IS 'Ссылка на students.id';
COMMENT ON COLUMN public.student_health.medical_indications IS 'Медицинские показания';
COMMENT ON COLUMN public.student_health.medical_restrictions IS 'Медицинские противопоказания и ограничения учебной / физической нагрузки';
COMMENT ON COLUMN public.student_health.physical_education_group IS 'Медицинская группа для занятий физкультурой';
COMMENT ON COLUMN public.student_health.disability_details IS 'Сведения об инвалидности, если предоставлены';

-- 9. Трудоустройство.
CREATE TABLE IF NOT EXISTS public.student_employment (
    student_id integer PRIMARY KEY REFERENCES public.students(id),
    employment_status text CHECK (employment_status IN ('employed', 'unemployed', 'self_employed', 'entrepreneur')),
    employer_name text,
    job_title text,
    employment_start_date date
);

COMMENT ON TABLE public.student_employment IS 'Текущие сведения о трудоустройстве студента';
COMMENT ON COLUMN public.student_employment.student_id IS 'Ссылка на students.id';
COMMENT ON COLUMN public.student_employment.employment_status IS 'employed — работает по найму, unemployed — не работает, self_employed — самозанятый, entrepreneur — ИП';
COMMENT ON COLUMN public.student_employment.employer_name IS 'Место работы';
COMMENT ON COLUMN public.student_employment.job_title IS 'Должность';
COMMENT ON COLUMN public.student_employment.employment_start_date IS 'Дата начала работы';

COMMIT;
