<?php

// Формат поля: подпись, тип, варианты выбора (если нужны).
function secretaryFields(): array
{
    $yesNo = ['' => 'Не указано', '1' => 'Да', '0' => 'Нет'];
    return [
        'student' => ['Студент и контакты', [
            'fio' => ['ФИО', 'required'],
            'birth_date' => ['Дата рождения', 'date'],
            'birth_place' => ['Место рождения', 'text'],
            'citizenship' => ['Гражданство', 'text'],
            'nationality' => ['Национальность', 'text'],
            'phone' => ['Телефон', 'tel'],
            'email' => ['Электронная почта', 'email'],
        ]],
        'documents' => ['Документы', [
            'identity_document_type' => ['Вид документа', 'text'],
            'identity_document_series' => ['Серия', 'text'],
            'identity_document_number' => ['Номер', 'text'],
            'identity_document_issued_on' => ['Дата выдачи', 'date'],
            'identity_document_issued_by' => ['Кем выдан', 'text'],
            'identity_document_department_code' => ['Код подразделения', 'text'],
            'identity_document_valid_until' => ['Действителен до', 'date'],
            'snils' => ['СНИЛС', 'snils'],
            'inn' => ['ИНН', 'inn'],
        ]],
        'education' => ['Обучение', [
            'specialty_id' => ['Специальность', 'specialty'],
            'subgroup' => ['Подгруппа', 'text'],
            'student_card_number' => ['Номер студенческого билета', 'text'],
            'admission_date' => ['Дата зачисления', 'date'],
            'admission_order_number' => ['Приказ о зачислении', 'text'],
            'study_form' => ['Форма обучения', 'select', [
                '' => 'Не указано', 'full_time' => 'Очная', 'part_time' => 'Заочная', 'mixed' => 'Очно-заочная',
            ]],
            'funding_type' => ['Финансирование', 'select', ['' => 'Не указано', 'budget' => 'Бюджет', 'paid' => 'Платное']],
            'needs_dormitory' => ['Нуждается в общежитии', 'bool', $yesNo],
        ]],
        'address' => ['Адрес', [
            'country' => ['Страна', 'text'],
            'region' => ['Регион', 'text'],
            'district' => ['Район', 'text'],
            'city' => ['Город', 'text'],
            'settlement' => ['Населённый пункт', 'text'],
            'street' => ['Улица', 'text'],
            'house' => ['Дом', 'text'],
            'building' => ['Корпус / строение', 'text'],
            'apartment' => ['Квартира / комната', 'text'],
            'postal_code' => ['Индекс', 'text'],
            'registration_date' => ['Дата регистрации', 'date'],
        ]],
        'family' => ['Семья', [
            'relationship' => ['Кем приходится', 'required'],
            'fio' => ['ФИО', 'required'],
            'phone' => ['Телефон', 'tel'],
            'additional_phone' => ['Дополнительный телефон', 'tel'],
            'email' => ['Электронная почта', 'email'],
            'address' => ['Адрес', 'text'],
            'workplace' => ['Место работы', 'text'],
            'job_title' => ['Должность', 'text'],
            'is_legal_representative' => ['Законный представитель', 'bool', $yesNo],
            'is_emergency_contact' => ['Для экстренной связи', 'bool', $yesNo],
        ]],
        'previous' => ['Предыдущее образование', [
            'institution_name' => ['Оконченное учебное заведение', 'required'],
            'education_level' => ['Полученное образование', 'text'],
            'graduation_year' => ['Год окончания', 'year'],
            'document_type' => ['Аттестат / диплом', 'text'],
            'document_series' => ['Серия документа', 'text'],
            'document_number' => ['Номер документа', 'text'],
            'document_issued_on' => ['Дата выдачи', 'date'],
        ]],
        'military_legal' => ['Воинский учёт и судимость', [
            'military_registration_status' => ['Статус воинского учёта', 'text'],
            'military_office' => ['Военкомат', 'text'],
            'military_document_type' => ['Вид документа воинского учёта', 'text'],
            'military_document_series' => ['Серия документа', 'text'],
            'military_document_number' => ['Номер документа', 'text'],
            'military_fitness_category' => ['Категория годности', 'text'],
            'deferment_until' => ['Отсрочка до', 'date'],
            'has_criminal_record' => ['Наличие судимости', 'bool', $yesNo],
            'criminal_record_details' => ['Сведения о судимости', 'textarea'],
        ]],
        'health' => ['Здоровье', [
            'medical_indications' => ['Медицинские показания', 'textarea'],
            'medical_restrictions' => ['Ограничения', 'textarea'],
            'physical_education_group' => ['Группа по физкультуре', 'text'],
            'disability_details' => ['Сведения об инвалидности', 'textarea'],
        ]],
        'employment' => ['Трудоустройство', [
            'employment_status' => ['Занятость', 'select', [
                '' => 'Не указано', 'employed' => 'Работает по найму', 'unemployed' => 'Не работает',
                'self_employed' => 'Самозанятый', 'entrepreneur' => 'ИП',
            ]],
            'employer_name' => ['Место работы', 'text'],
            'job_title' => ['Должность', 'text'],
            'employment_start_date' => ['Дата начала работы', 'date'],
        ]],
    ];
}

const SECRETARY_SINGLE_TABLES = [
    'documents' => 'student_documents',
    'education' => 'student_education',
    'military_legal' => 'student_military_legal',
    'health' => 'student_health',
    'employment' => 'student_employment',
];
const SECRETARY_COLLECTION_TABLES = [
    'family' => 'student_family_contacts',
    'previous' => 'student_previous_education',
];
