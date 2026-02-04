# Битрикс24: Автоматическое извлечение вложений из писем CRM

Модуль для автоматического копирования вложений из входящих писем в пользовательские поля лидов, сделок и контактов.

## Требования

- Битрикс24 коробочная версия
- Доступ к файловой системе сервера

## Установка

### 1. Создайте пользовательские поля в CRM

Перейдите в **CRM → Настройки → Настройки форм и отчётов → Пользовательские поля**

Создайте поля для нужных сущностей:

| Сущность | Тип поля | Множественное | Код поля |
|----------|----------|---------------|----------|
| Лид | Файл (Диск) | Да | `UF_CRM_LEAD_ATTACHMENTS` |
| Сделка | Файл (Диск) | Да | `UF_CRM_DEAL_ATTACHMENTS` |
| Контакт | Файл (Диск) | Да | `UF_CRM_CONTACT_ATTACHMENTS` |

### 2. Скопируйте файлы на сервер

```bash
# Создайте папку include если её нет
mkdir -p /home/bitrix/www/local/php_interface/include

# Скопируйте файл обработчика
cp crm_email_attachments.php /home/bitrix/www/local/php_interface/include/
```

### 3. Подключите в init.php

Добавьте в файл `/home/bitrix/www/local/php_interface/init.php`:

```php
// Автоматическое извлечение вложений из писем CRM
require_once($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/crm_email_attachments.php');
```

### 4. Установите права

```bash
chown bitrix:bitrix /home/bitrix/www/local/php_interface/include/crm_email_attachments.php
chmod 644 /home/bitrix/www/local/php_interface/include/crm_email_attachments.php
```

### 5. Сбросьте кеш

**Админка → Настройки → Настройки продукта → Автокеширование → Очистить кеш**

## Как это работает

1. При получении входящего письма в CRM срабатывает событие `OnActivityAdd`
2. Обработчик проверяет, что это входящее письмо с вложениями
3. Вложения автоматически копируются в соответствующее пользовательское поле лида/сделки/контакта

## Настройка

### Изменение кодов полей

Если вы используете другие коды полей, отредактируйте файл `crm_email_attachments.php`:

```php
// Для лидов
'UF_CRM_LEAD_ATTACHMENTS' => $fileIds

// Для сделок  
'UF_CRM_DEAL_ATTACHMENTS' => $fileIds

// Для контактов
'UF_CRM_CONTACT_ATTACHMENTS' => $fileIds
```

### Добавление поддержки компаний

Добавьте в функцию `ExtractEmailAttachments`:

```php
if ($ownerTypeId == \CCrmOwnerType::Company) {
    (new \CCrmCompany(false))->Update($ownerId, [
        'UF_CRM_COMPANY_ATTACHMENTS' => $fileIds
    ]);
}
```

## Отладка

Для отладки добавьте логирование в начало функции:

```php
\Bitrix\Main\Diag\Debug::writeToFile([
    'id' => $id,
    'fields' => $fields
], 'OnActivityAdd', '/home/bitrix/www/local/logs/email_attachments.log');
```

## Отключение

Закомментируйте строку в `init.php`:

```php
// require_once($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/crm_email_attachments.php');
```

## Лицензия

MIT
