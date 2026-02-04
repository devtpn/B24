<?php
/**
 * Автоматическое извлечение вложений из входящих писем CRM
 * 
 * @version 1.2.0 - добавлено логирование для отладки
 */

// Включаем логирование для отладки (потом можно отключить)
define('CRM_EMAIL_ATTACHMENTS_DEBUG', true);

AddEventHandler('crm', 'OnActivityAdd', 'ExtractEmailAttachments');

function ExtractEmailAttachments($id, &$fields)
{
    $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/email_attachments.log';
    
    // Логируем все вызовы для отладки
    if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, date('Y-m-d H:i:s') . " OnActivityAdd called, ID: $id\n", FILE_APPEND);
        file_put_contents($logFile, "Fields: " . print_r($fields, true) . "\n\n", FILE_APPEND);
    }
    
    // Подключаем модуль CRM
    if (!\Bitrix\Main\Loader::includeModule('crm')) {
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "ERROR: CRM module not loaded\n\n", FILE_APPEND);
        }
        return;
    }
    
    // Проверяем что это письмо (TYPE_ID = 4)
    $typeId = isset($fields['TYPE_ID']) ? (int)$fields['TYPE_ID'] : 0;
    if ($typeId != 4) { // CCrmActivityType::Email = 4
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "SKIP: Not an email, TYPE_ID = $typeId\n\n", FILE_APPEND);
        }
        return;
    }
    
    // Проверяем что это входящее письмо (DIRECTION = 1)
    $direction = isset($fields['DIRECTION']) ? (int)$fields['DIRECTION'] : 0;
    if ($direction != 1) { // CCrmActivityDirection::Incoming = 1
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "SKIP: Not incoming, DIRECTION = $direction\n\n", FILE_APPEND);
        }
        return;
    }
    
    // Ищем вложения в разных полях (зависит от версии Битрикса)
    $fileIds = null;
    
    // Вариант 1: STORAGE_ELEMENT_IDS
    if (!empty($fields['STORAGE_ELEMENT_IDS'])) {
        $fileIds = $fields['STORAGE_ELEMENT_IDS'];
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "Found STORAGE_ELEMENT_IDS: " . print_r($fileIds, true) . "\n", FILE_APPEND);
        }
    }
    
    // Вариант 2: FILES
    if (empty($fileIds) && !empty($fields['FILES'])) {
        $fileIds = $fields['FILES'];
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "Found FILES: " . print_r($fileIds, true) . "\n", FILE_APPEND);
        }
    }
    
    // Вариант 3: BINDINGS с файлами
    if (empty($fileIds) && !empty($fields['BINDINGS'])) {
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "Found BINDINGS: " . print_r($fields['BINDINGS'], true) . "\n", FILE_APPEND);
        }
    }
    
    // Вариант 4: Получаем вложения через API после создания
    if (empty($fileIds) && $id > 0) {
        $activity = \CCrmActivity::GetByID($id, false);
        if ($activity) {
            if (!empty($activity['STORAGE_ELEMENT_IDS'])) {
                $fileIds = $activity['STORAGE_ELEMENT_IDS'];
            }
            if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
                file_put_contents($logFile, "Activity from DB: " . print_r($activity, true) . "\n", FILE_APPEND);
            }
        }
    }
    
    // Если это сериализованная строка - десериализуем
    if (is_string($fileIds)) {
        $fileIds = @unserialize($fileIds);
    }
    
    // Проверяем что получили массив с файлами
    if (empty($fileIds) || !is_array($fileIds)) {
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "SKIP: No attachments found\n\n", FILE_APPEND);
        }
        return;
    }
    
    $ownerId = isset($fields['OWNER_ID']) ? (int)$fields['OWNER_ID'] : 0;
    $ownerTypeId = isset($fields['OWNER_TYPE_ID']) ? (int)$fields['OWNER_TYPE_ID'] : 0;
    
    if ($ownerId <= 0 || $ownerTypeId <= 0) {
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "SKIP: Invalid owner, ID=$ownerId, TYPE=$ownerTypeId\n\n", FILE_APPEND);
        }
        return;
    }
    
    if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
        file_put_contents($logFile, "Processing: OwnerType=$ownerTypeId, OwnerId=$ownerId, Files=" . print_r($fileIds, true) . "\n", FILE_APPEND);
    }
    
    // Обновляем соответствующую сущность CRM
    $result = false;
    
    switch ($ownerTypeId) {
        case 1: // Lead
            $arFields = ['UF_CRM_LEAD_ATTACHMENTS' => $fileIds];
            $entity = new \CCrmLead(false);
            $result = $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            break;
            
        case 2: // Deal
            $arFields = ['UF_CRM_DEAL_ATTACHMENTS' => $fileIds];
            $entity = new \CCrmDeal(false);
            $result = $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            break;
            
        case 3: // Contact
            $arFields = ['UF_CRM_CONTACT_ATTACHMENTS' => $fileIds];
            $entity = new \CCrmContact(false);
            $result = $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            break;
            
        case 4: // Company
            $arFields = ['UF_CRM_COMPANY_ATTACHMENTS' => $fileIds];
            $entity = new \CCrmCompany(false);
            $result = $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            break;
    }
    
    if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
        file_put_contents($logFile, "Update result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n\n", FILE_APPEND);
    }
}
