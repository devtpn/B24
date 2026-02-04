<?php
/**
 * Автоматическое извлечение вложений из входящих писем CRM
 * 
 * Копирует вложения из входящих писем в пользовательские поля
 * лидов, сделок и контактов.
 * 
 * Установка:
 * 1. Скопируйте файл в /local/php_interface/include/
 * 2. Добавьте в init.php:
 *    require_once($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/include/crm_email_attachments.php');
 * 3. Создайте пользовательские поля типа "Файл (Диск)" с множественным выбором:
 *    - UF_CRM_LEAD_ATTACHMENTS (для лидов)
 *    - UF_CRM_DEAL_ATTACHMENTS (для сделок)
 *    - UF_CRM_CONTACT_ATTACHMENTS (для контактов)
 * 
 * @author devtpn
 * @version 1.1.0
 */

AddEventHandler('crm', 'OnActivityAdd', 'ExtractEmailAttachments');

/**
 * Обработчик события добавления активности в CRM
 * 
 * @param int $id ID активности
 * @param array $fields Поля активности
 * @return void
 */
function ExtractEmailAttachments($id, &$fields)
{
    // Подключаем модуль CRM
    if (!\Bitrix\Main\Loader::includeModule('crm')) {
        return;
    }
    
    // Проверяем что это письмо (TYPE_ID = 4)
    if (!isset($fields['TYPE_ID']) || $fields['TYPE_ID'] != \CCrmActivityType::Email) {
        return;
    }
    
    // Проверяем что это входящее письмо (DIRECTION = 1)
    if (!isset($fields['DIRECTION']) || $fields['DIRECTION'] != \CCrmActivityDirection::Incoming) {
        return;
    }
    
    // Проверяем наличие вложений
    if (empty($fields['STORAGE_ELEMENT_IDS'])) {
        return;
    }
    
    $ownerId = isset($fields['OWNER_ID']) ? (int)$fields['OWNER_ID'] : 0;
    $ownerTypeId = isset($fields['OWNER_TYPE_ID']) ? (int)$fields['OWNER_TYPE_ID'] : 0;
    
    if ($ownerId <= 0 || $ownerTypeId <= 0) {
        return;
    }
    
    $fileIds = $fields['STORAGE_ELEMENT_IDS'];
    
    // Если это сериализованная строка - десериализуем
    if (is_string($fileIds)) {
        $fileIds = @unserialize($fileIds);
    }
    
    // Проверяем что получили массив с файлами
    if (empty($fileIds) || !is_array($fileIds)) {
        return;
    }
    
    // Обновляем соответствующую сущность CRM
    // ВАЖНО: arFields должен быть переменной для передачи по ссылке
    switch ($ownerTypeId) {
        case \CCrmOwnerType::Lead:
            $arFields = ['UF_CRM_LEAD_ATTACHMENTS' => $fileIds];
            $entity = new \CCrmLead(false);
            $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            break;
            
        case \CCrmOwnerType::Deal:
            $arFields = ['UF_CRM_DEAL_ATTACHMENTS' => $fileIds];
            $entity = new \CCrmDeal(false);
            $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            break;
            
        case \CCrmOwnerType::Contact:
            $arFields = ['UF_CRM_CONTACT_ATTACHMENTS' => $fileIds];
            $entity = new \CCrmContact(false);
            $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            break;
            
        case \CCrmOwnerType::Company:
            $arFields = ['UF_CRM_COMPANY_ATTACHMENTS' => $fileIds];
            $entity = new \CCrmCompany(false);
            $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            break;
    }
}
