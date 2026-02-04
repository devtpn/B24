<?php
/**
 * Автоматическое извлечение вложений из входящих писем CRM
 * 
 * @version 1.3.0 - поддержка полей типа file (конвертация disk -> b_file)
 */

// Включаем логирование для отладки (потом можно отключить)
define('CRM_EMAIL_ATTACHMENTS_DEBUG', true);

AddEventHandler('crm', 'OnActivityAdd', 'ExtractEmailAttachments');

/**
 * Конвертирует ID элементов диска в данные для поля типа 'file'
 * Для полей типа 'file' нужно копировать файл и передавать массив с данными
 */
function ConvertDiskToFileData($diskElementIds, $logFile = null)
{
    if (empty($diskElementIds) || !is_array($diskElementIds)) {
        return [];
    }
    
    // Подключаем модуль диска
    if (!\Bitrix\Main\Loader::includeModule('disk')) {
        if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "WARNING: Disk module not loaded\n", FILE_APPEND);
        }
        return [];
    }
    
    $fileData = [];
    
    foreach ($diskElementIds as $diskId) {
        $diskId = (int)$diskId;
        if ($diskId <= 0) continue;
        
        // Получаем элемент диска
        $diskFile = \Bitrix\Disk\File::loadById($diskId);
        if (!$diskFile) {
            if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
                file_put_contents($logFile, "WARNING: Disk file $diskId not found\n", FILE_APPEND);
            }
            continue;
        }
        
        // FILE_ID - это ID в таблице b_file
        $bFileId = $diskFile->getFileId();
        if ($bFileId <= 0) {
            if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
                file_put_contents($logFile, "WARNING: Disk file $diskId has no b_file ID\n", FILE_APPEND);
            }
            continue;
        }
        
        // Получаем данные файла из b_file
        $fileArray = \CFile::GetFileArray($bFileId);
        if (!$fileArray) {
            if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
                file_put_contents($logFile, "WARNING: b_file $bFileId not found\n", FILE_APPEND);
            }
            continue;
        }
        
        // Копируем файл для UF поля (создаём новую запись в b_file)
        $copiedFileId = \CFile::CopyFile($bFileId);
        if ($copiedFileId > 0) {
            $fileData[] = $copiedFileId;
            if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
                file_put_contents($logFile, "Copied file: disk $diskId -> b_file $bFileId -> new b_file $copiedFileId ({$fileArray['ORIGINAL_NAME']})\n", FILE_APPEND);
            }
        } else {
            if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
                file_put_contents($logFile, "ERROR: Failed to copy b_file $bFileId\n", FILE_APPEND);
            }
        }
    }
    
    return $fileData;
}

/**
 * Определяет тип UF поля и возвращает правильные данные файлов
 */
function GetFileIdsForField($entityId, $fieldName, $diskElementIds, $logFile = null)
{
    // Получаем информацию о поле
    $rsField = \CUserTypeEntity::GetList([], [
        'ENTITY_ID' => $entityId,
        'FIELD_NAME' => $fieldName
    ]);
    
    $field = $rsField->Fetch();
    if (!$field) {
        if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "ERROR: Field $fieldName not found in $entityId\n", FILE_APPEND);
        }
        return null;
    }
    
    $fieldType = $field['USER_TYPE_ID'];
    if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
        file_put_contents($logFile, "Field $fieldName: type=$fieldType, multiple={$field['MULTIPLE']}\n", FILE_APPEND);
    }
    
    // Для disk_file - используем ID элементов диска как есть
    if ($fieldType === 'disk_file') {
        if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "Using disk IDs directly for disk_file field\n", FILE_APPEND);
        }
        return $diskElementIds;
    }
    
    // Для file - копируем файлы и возвращаем новые ID из b_file
    if ($fieldType === 'file') {
        if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "Converting and copying files for 'file' type field\n", FILE_APPEND);
        }
        return ConvertDiskToFileData($diskElementIds, $logFile);
    }
    
    // Для других типов - пробуем как есть
    if ($logFile && CRM_EMAIL_ATTACHMENTS_DEBUG) {
        file_put_contents($logFile, "WARNING: Unknown field type $fieldType, trying original IDs\n", FILE_APPEND);
    }
    return $diskElementIds;
}

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
    $diskFileIds = null;
    
    // Вариант 1: STORAGE_ELEMENT_IDS
    if (!empty($fields['STORAGE_ELEMENT_IDS'])) {
        $diskFileIds = $fields['STORAGE_ELEMENT_IDS'];
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "Found STORAGE_ELEMENT_IDS: " . print_r($diskFileIds, true) . "\n", FILE_APPEND);
        }
    }
    
    // Вариант 2: FILES
    if (empty($diskFileIds) && !empty($fields['FILES'])) {
        $diskFileIds = $fields['FILES'];
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "Found FILES: " . print_r($diskFileIds, true) . "\n", FILE_APPEND);
        }
    }
    
    // Вариант 3: BINDINGS с файлами
    if (empty($diskFileIds) && !empty($fields['BINDINGS'])) {
        if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
            file_put_contents($logFile, "Found BINDINGS: " . print_r($fields['BINDINGS'], true) . "\n", FILE_APPEND);
        }
    }
    
    // Вариант 4: Получаем вложения через API после создания
    if (empty($diskFileIds) && $id > 0) {
        $activity = \CCrmActivity::GetByID($id, false);
        if ($activity) {
            if (!empty($activity['STORAGE_ELEMENT_IDS'])) {
                $diskFileIds = $activity['STORAGE_ELEMENT_IDS'];
            }
            if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
                file_put_contents($logFile, "Activity from DB: " . print_r($activity, true) . "\n", FILE_APPEND);
            }
        }
    }
    
    // Если это сериализованная строка - десериализуем
    if (is_string($diskFileIds)) {
        $diskFileIds = @unserialize($diskFileIds);
    }
    
    // Проверяем что получили массив с файлами
    if (empty($diskFileIds) || !is_array($diskFileIds)) {
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
        file_put_contents($logFile, "Processing: OwnerType=$ownerTypeId, OwnerId=$ownerId, DiskFiles=" . print_r($diskFileIds, true) . "\n", FILE_APPEND);
    }
    
    // Обновляем соответствующую сущность CRM
    $result = false;
    $errorMsg = '';
    
    switch ($ownerTypeId) {
        case 1: // Lead
            $fieldName = 'UF_CRM_LEAD_ATTACHMENTS';
            $fileIds = GetFileIdsForField('CRM_LEAD', $fieldName, $diskFileIds, $logFile);
            if ($fileIds === null) break;
            
            $arFields = [$fieldName => $fileIds];
            $entity = new \CCrmLead(false);
            $result = $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            if (!$result && !empty($entity->LAST_ERROR)) {
                $errorMsg = $entity->LAST_ERROR;
            }
            break;
            
        case 2: // Deal
            $fieldName = 'UF_CRM_DEAL_ATTACHMENTS';
            $fileIds = GetFileIdsForField('CRM_DEAL', $fieldName, $diskFileIds, $logFile);
            if ($fileIds === null) break;
            
            $arFields = [$fieldName => $fileIds];
            $entity = new \CCrmDeal(false);
            $result = $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            if (!$result && !empty($entity->LAST_ERROR)) {
                $errorMsg = $entity->LAST_ERROR;
            }
            break;
            
        case 3: // Contact
            $fieldName = 'UF_CRM_CONTACT_ATTACHMENTS';
            $fileIds = GetFileIdsForField('CRM_CONTACT', $fieldName, $diskFileIds, $logFile);
            if ($fileIds === null) break;
            
            $arFields = [$fieldName => $fileIds];
            $entity = new \CCrmContact(false);
            $result = $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            if (!$result && !empty($entity->LAST_ERROR)) {
                $errorMsg = $entity->LAST_ERROR;
            }
            break;
            
        case 4: // Company
            $fieldName = 'UF_CRM_COMPANY_ATTACHMENTS';
            $fileIds = GetFileIdsForField('CRM_COMPANY', $fieldName, $diskFileIds, $logFile);
            if ($fileIds === null) break;
            
            $arFields = [$fieldName => $fileIds];
            $entity = new \CCrmCompany(false);
            $result = $entity->Update($ownerId, $arFields, true, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            if (!$result && !empty($entity->LAST_ERROR)) {
                $errorMsg = $entity->LAST_ERROR;
            }
            break;
    }
    
    if (CRM_EMAIL_ATTACHMENTS_DEBUG) {
        $status = $result ? 'SUCCESS' : 'FAILED';
        file_put_contents($logFile, "Update result: $status\n", FILE_APPEND);
        if ($errorMsg) {
            file_put_contents($logFile, "Error: $errorMsg\n", FILE_APPEND);
        }
        file_put_contents($logFile, "\n", FILE_APPEND);
    }
}
