<?php
/**
 * Диагностика пользовательского поля для вложений
 * 
 * Запуск: php /home/bitrix/www/local/tools/check_uf_field.php
 * Или через браузер: https://your-site.ru/local/tools/check_uf_field.php
 */

// Определяем DOCUMENT_ROOT для CLI
$_SERVER['DOCUMENT_ROOT'] = realpath(dirname(__FILE__) . '/../../..');
if (!$_SERVER['DOCUMENT_ROOT'] || !file_exists($_SERVER['DOCUMENT_ROOT'] . '/bitrix')) {
    $_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

\Bitrix\Main\Loader::includeModule('crm');

echo "=== Диагностика UF поля для вложений ===\n\n";

// 1. Проверяем существование поля
echo "1. Поиск поля UF_CRM_LEAD_ATTACHMENTS...\n";
$rsFields = CUserTypeEntity::GetList(
    [],
    ['ENTITY_ID' => 'CRM_LEAD', 'FIELD_NAME' => 'UF_CRM_LEAD_ATTACHMENTS']
);

if ($field = $rsFields->Fetch()) {
    echo "✅ Поле найдено:\n";
    echo "   ID: " . $field['ID'] . "\n";
    echo "   Тип: " . $field['USER_TYPE_ID'] . "\n";
    echo "   Множественное: " . ($field['MULTIPLE'] == 'Y' ? 'Да' : 'Нет') . "\n";
    echo "   Обязательное: " . ($field['MANDATORY'] == 'Y' ? 'Да' : 'Нет') . "\n";
} else {
    echo "❌ Поле UF_CRM_LEAD_ATTACHMENTS НЕ НАЙДЕНО!\n\n";
    
    // Показать все UF поля лида
    echo "Все UF поля лида:\n";
    $rsAll = CUserTypeEntity::GetList([], ['ENTITY_ID' => 'CRM_LEAD']);
    while ($f = $rsAll->Fetch()) {
        echo "   - " . $f['FIELD_NAME'] . " (тип: " . $f['USER_TYPE_ID'] . ", множ: " . $f['MULTIPLE'] . ")\n";
    }
    exit;
}

// 2. Тестовое обновление лида
echo "\n2. Тестовое обновление лида...\n";

// Найдём любой существующий лид
$rsLead = CCrmLead::GetListEx(
    ['ID' => 'DESC'],
    [],
    false,
    ['nTopCount' => 1],
    ['ID', 'TITLE']
);

if ($lead = $rsLead->Fetch()) {
    $leadId = $lead['ID'];
    echo "   Тестовый лид: ID=$leadId, '{$lead['TITLE']}'\n";
} else {
    echo "❌ Нет лидов для теста\n";
    exit;
}

// Пробуем обновить
$fileIds = [1]; // Тестовый ID файла
$entity = new CCrmLead(false);
$arFields = ['UF_CRM_LEAD_ATTACHMENTS' => $fileIds];

echo "   Пробуем записать: " . print_r($fileIds, true);

$result = $entity->Update($leadId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);

if ($result) {
    echo "   ✅ Update вернул TRUE\n";
} else {
    echo "   ❌ Update вернул FALSE\n";
    
    // Получаем ошибку
    global $APPLICATION;
    if ($ex = $APPLICATION->GetException()) {
        echo "   Ошибка Application: " . $ex->GetString() . "\n";
    }
    
    // Ошибки из LAST_ERROR
    if (!empty($entity->LAST_ERROR)) {
        echo "   LAST_ERROR: " . $entity->LAST_ERROR . "\n";
    }
}

// 3. Проверяем значение после обновления
echo "\n3. Проверка значения после обновления...\n";
$leadAfter = CCrmLead::GetByID($leadId, false);
echo "   UF_CRM_LEAD_ATTACHMENTS = ";
print_r($leadAfter['UF_CRM_LEAD_ATTACHMENTS'] ?? 'NULL');
echo "\n";

// 4. Информация о типе поля disk_file
echo "\n4. Проверка типа disk_file...\n";
if ($field['USER_TYPE_ID'] === 'disk_file') {
    echo "   ✅ Тип поля правильный (disk_file)\n";
    echo "   Для disk_file нужно передавать ID элементов диска\n";
} elseif ($field['USER_TYPE_ID'] === 'file') {
    echo "   ⚠️ Тип поля 'file' - это старый тип файлов\n";
    echo "   Для него нужно передавать ID из b_file\n";
} else {
    echo "   ⚠️ Тип поля: " . $field['USER_TYPE_ID'] . "\n";
}

echo "\n=== Диагностика завершена ===\n";
