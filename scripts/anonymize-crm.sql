-- =============================================================================
-- Анонимизация персональных данных в Битрикс24 CRM
-- Запускать только на ТЕСТОВОЙ базе данных!
-- =============================================================================

-- Контакты: имена
UPDATE b_crm_contact SET
    NAME        = CONCAT('Имя_', ID),
    LAST_NAME   = CONCAT('Фамилия_', ID),
    SECOND_NAME = NULL,
    BIRTHDATE   = NULL,
    COMMENTS    = NULL;

-- Контакты: телефоны и email
UPDATE b_crm_field_multi SET
    VALUE = CONCAT('+7900', LPAD(ID, 7, '0'))
WHERE ENTITY_ID = 'CONTACT' AND TYPE_ID = 'PHONE';

UPDATE b_crm_field_multi SET
    VALUE = CONCAT('contact_', ID, '@test.local')
WHERE ENTITY_ID = 'CONTACT' AND TYPE_ID = 'EMAIL';

-- Лиды: имена и заголовки
UPDATE b_crm_lead SET
    NAME        = CONCAT('Лид_', ID),
    LAST_NAME   = CONCAT('Фамилия_', ID),
    SECOND_NAME = NULL,
    TITLE       = CONCAT('Лид #', ID),
    COMMENTS    = NULL,
    BIRTHDATE   = NULL;

-- Лиды: телефоны и email
UPDATE b_crm_field_multi SET
    VALUE = CONCAT('+7900', LPAD(ID, 7, '0'))
WHERE ENTITY_ID = 'LEAD' AND TYPE_ID = 'PHONE';

UPDATE b_crm_field_multi SET
    VALUE = CONCAT('lead_', ID, '@test.local')
WHERE ENTITY_ID = 'LEAD' AND TYPE_ID = 'EMAIL';

-- Компании
UPDATE b_crm_company SET
    TITLE    = CONCAT('Компания_', ID),
    COMMENTS = NULL;

UPDATE b_crm_field_multi SET
    VALUE = CONCAT('+7900', LPAD(ID, 7, '0'))
WHERE ENTITY_ID = 'COMPANY' AND TYPE_ID = 'PHONE';

UPDATE b_crm_field_multi SET
    VALUE = CONCAT('company_', ID, '@test.local')
WHERE ENTITY_ID = 'COMPANY' AND TYPE_ID = 'EMAIL';

-- Сделки: очищаем комментарии
UPDATE b_crm_deal SET COMMENTS = NULL;

-- =============================================================================
-- Активности: удаляем звонки и письма
-- TYPE_ID: 2 = email, 6 = звонок
-- =============================================================================

-- Удаляем привязки активностей к сущностям
DELETE FROM b_crm_activity_binding
WHERE ACTIVITY_ID IN (
    SELECT ID FROM b_crm_activity WHERE TYPE_ID IN (2, 6)
);

-- Удаляем сами активности (звонки и письма)
DELETE FROM b_crm_activity WHERE TYPE_ID IN (2, 6);

-- Очищаем таймлайн от записей о звонках и письмах
-- TYPE_ID: 2 = звонок, 4 = email в таймлайне
DELETE FROM b_crm_timeline WHERE TYPE_ID IN (2, 4);

-- Удаляем записи разговоров (voximplant / itigrix)
DELETE FROM b_voximplant_call;
DELETE FROM b_voximplant_call_user;

-- =============================================================================
-- Файлы: удаляем вложения из активностей и CRM
-- =============================================================================

-- Удаляем записи о файлах вложенных в активности
DELETE FROM b_crm_activity_element
WHERE ACTIVITY_ID NOT IN (SELECT ID FROM b_crm_activity);

-- Очищаем таблицу файлов диска связанных с CRM активностями
-- (физические файлы удаляются отдельно bash скриптом)
DELETE f FROM b_disk_object f
INNER JOIN b_disk_storage s ON f.STORAGE_ID = s.ID
WHERE s.MODULE_ID = 'crm' AND f.TYPE = 'F';

-- Пользователи (кроме администратора ID=1)
UPDATE b_user SET
    NAME             = CONCAT('Пользователь_', ID),
    LAST_NAME        = CONCAT('Тест_', ID),
    SECOND_NAME      = NULL,
    EMAIL            = CONCAT('user_', ID, '@test.local'),
    PERSONAL_PHONE   = NULL,
    PERSONAL_MOBILE  = NULL,
    PERSONAL_WWW     = NULL,
    PERSONAL_ICQ     = NULL,
    PERSONAL_NOTES   = NULL
WHERE ID > 1;

-- Очистить историю активностей (звонки, письма) — опционально
-- TRUNCATE TABLE b_crm_activity;
-- TRUNCATE TABLE b_crm_timeline;

SELECT 'Анонимизация завершена' AS status;
