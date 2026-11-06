# Кабинет преподавателя Electro-diary

1. Примените `migrations/teacher-cabinet.sql` к существующей базе PostgreSQL проекта. В pgAdmin откройте файл в Query Tool нужной базы и выполните его. Для консоли подойдёт `psql -v ON_ERROR_STOP=1 -d "Electro-diary" -f migrations/teacher-cabinet.sql`.
2. Скопируйте файлы из `api/` и `frontend/` в одноимённые каталоги проекта. В `frontend/` обновляется `index.php`. Если в вашей рабочей версии страницы входа есть свои правки, перенесите изменения из первых 76 строк приложенного `index.php`. Кабинет подключает существующие `frontend/index.css`, `api/auth.php` и `api/database.php` проекта.
3. Войдите через `frontend/index.php` под учётной записью с ролью `teacher`. Поле `teachers.auth_id` должно указывать на `auth.id` этой учётной записи. Доступные группы, предметы и недели определяются по записям `lessons`, где `lessons.teacher` соответствует `teachers.id`. Без этих записей кабинет покажет пустые разделы.

## Работа с журналом

- Дата и номер пары обозначают одно занятие. По нажатию на дату можно изменить тему и вид этой пары.
- Нажатие на `+` открывает выбор отметки. Кнопка «Добавить ещё оценку» позволяет сохранить несколько оценок за пару. «н» означает отсутствие и занимает ячейку самостоятельно. Нажатие на существующую оценку открывает подтверждение её редактирования.
- На вкладке «Аттестация» есть месячные колонки и отдельные колонки за семестр, экзамен и дифзачёт. Первый семестр длится с сентября по декабрь, второй с января по июнь, как в кабинете студента.
- Текущие оценки хранятся в `evaluations`, результаты аттестации в `teacher_attestations`. Тип занятия «Экзамен» в журнале и итог экзамена в аттестации сохраняются независимо.

## Вход на тестовой базе

В дампе `Electro-diary-06.11.2026.sql` таблицы `teachers` и `lessons` пусты. Если проверяете на копии этого дампа, примените ранее подготовленный `import-student-demo.sql`. Он добавит расписание и профиль `demo_diary_teacher` со служебной учётной записью. Для входа задайте ей свой пароль в тестовой базе. Следующий пример рассчитан на Bash.

```bash
read -r -s -p 'Пароль тестового преподавателя: ' test_teacher_password
printf '\n'
if [ -n "$test_teacher_password" ]; then
  test_teacher_hash=$(printf '%s' "$test_teacher_password" | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);')
  psql -v ON_ERROR_STOP=1 -d "Electro-diary" -v teacher_hash="$test_teacher_hash" <<'SQL'
UPDATE public.auth
SET password = :'teacher_hash'
WHERE login = 'demo_diary_teacher';
SQL
  unset test_teacher_hash
fi
unset test_teacher_password
```
