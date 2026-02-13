<?php
/**
 * Автоматическое извлечение вложений из входящих писем CRM
 * Поддержка: Лиды, Сделки, Контакты
 * 
 * @version 2.4.0 - добавление к существующим файлам (не перезапись)
 */

define('CRM_EMAIL_ATTACHMENTS_DEBUG', true);

AddEventHandler('crm', 'OnActivityAdd', 'ExtractEmailAttachments');

function _log($msg) {
    if (!CRM_EMAIL_ATTACHMENTS_DEBUG) return;
    static $f;
    if (!$f) {
        $dir = $_SERVER['DOCUMENT_ROOT'] . '/local/logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $f = "$dir/email_attachments.log";
    }
    file_put_contents($f, date('Y-m-d H:i:s') . " $msg\n", FILE_APPEND);
}

// Конфигурация: ownerTypeId => [table, fieldName]
function getEntityConfig() {
    return [
        1 => ['b_uts_crm_lead', 'UF_CRM_LEAD_ATTACHMENTS'],
        2 => ['b_uts_crm_deal', 'UF_CRM_DEAL_ATTACHMENTS'],
        3 => ['b_uts_crm_contact', 'UF_CRM_CONTACT_ATTACHMENTS'],
    ];
}

function ExtractEmailAttachments($id, &$fields)
{
    if (!\Bitrix\Main\Loader::includeModule('crm') || !\Bitrix\Main\Loader::includeModule('disk')) {
        return;
    }
    
    // Только входящие письма
    if (($fields['TYPE_ID'] ?? 0) != 4 || ($fields['DIRECTION'] ?? 0) != 1) {
        return;
    }
    
    // Вложения
    $diskIds = $fields['STORAGE_ELEMENT_IDS'] ?? [];
    if (is_string($diskIds)) $diskIds = @unserialize($diskIds);
    if (empty($diskIds)) return;
    
    $ownerId = (int)($fields['OWNER_ID'] ?? 0);
    $ownerTypeId = (int)($fields['OWNER_TYPE_ID'] ?? 0);
    
    $config = getEntityConfig();
    if (!isset($config[$ownerTypeId]) || $ownerId <= 0) {
        _log("SKIP: unsupported type $ownerTypeId");
        return;
    }
    
    [$table, $fieldName] = $config[$ownerTypeId];
    _log("Processing: type=$ownerTypeId, id=$ownerId, files=" . count($diskIds));
    
    // Копируем файлы disk -> b_file
    $newFileIds = [];
    foreach ($diskIds as $diskId) {
        $diskFile = \Bitrix\Disk\File::loadById((int)$diskId);
        if (!$diskFile) continue;
        
        $copiedId = \CFile::CopyFile($diskFile->getFileId());
        if ($copiedId > 0) {
            $newFileIds[] = $copiedId;
            _log("Copied: disk $diskId -> b_file $copiedId");
        }
    }
    
    if (empty($newFileIds)) {
        _log("ERROR: no files copied");
        return;
    }
    
    $conn = \Bitrix\Main\Application::getConnection();
    
    // Получаем существующие файлы
    $existingFileIds = [];
    $row = $conn->query("SELECT $fieldName FROM $table WHERE VALUE_ID = $ownerId")->fetch();
    
    if ($row && !empty($row[$fieldName])) {
        $existingFileIds = @unserialize($row[$fieldName]);
        if (!is_array($existingFileIds)) {
            $existingFileIds = [];
        }
        _log("Existing files: " . count($existingFileIds));
    }
    
    // Объединяем существующие и новые файлы
    $allFileIds = array_merge($existingFileIds, $newFileIds);
    $allFileIds = array_unique($allFileIds); // убираем дубликаты
    
    // Сохраняем
    $serialized = $conn->getSqlHelper()->forSql(serialize($allFileIds));
    
    if ($row) {
        $conn->query("UPDATE $table SET $fieldName = '$serialized' WHERE VALUE_ID = $ownerId");
    } else {
        $conn->query("INSERT INTO $table (VALUE_ID, $fieldName) VALUES ($ownerId, '$serialized')");
    }
    
    _log("Saved " . count($allFileIds) . " files (+" . count($newFileIds) . " new) to $table");
}
