<?php
/**
 * Автоматическое извлечение вложений из входящих писем CRM
 * 
 * @version 2.0.0 - упрощённая версия с прямой записью в b_uts
 */

define('CRM_EMAIL_ATTACHMENTS_DEBUG', true);

AddEventHandler('crm', 'OnActivityAdd', 'ExtractEmailAttachments');

function _log($message) {
    if (!CRM_EMAIL_ATTACHMENTS_DEBUG) return;
    static $logFile = null;
    if ($logFile === null) {
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/email_attachments.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . " $message\n", FILE_APPEND);
}

function ExtractEmailAttachments($id, &$fields)
{
    _log("=== OnActivityAdd ID: $id ===");
    
    if (!\Bitrix\Main\Loader::includeModule('crm') || !\Bitrix\Main\Loader::includeModule('disk')) {
        _log("ERROR: modules not loaded");
        return;
    }
    
    // Только входящие письма (TYPE_ID=4, DIRECTION=1)
    if (($fields['TYPE_ID'] ?? 0) != 4 || ($fields['DIRECTION'] ?? 0) != 1) {
        _log("SKIP: not incoming email");
        return;
    }
    
    // Получаем вложения
    $diskIds = $fields['STORAGE_ELEMENT_IDS'] ?? [];
    if (is_string($diskIds)) $diskIds = @unserialize($diskIds);
    if (empty($diskIds)) {
        _log("SKIP: no attachments");
        return;
    }
    
    $ownerId = (int)($fields['OWNER_ID'] ?? 0);
    $ownerTypeId = (int)($fields['OWNER_TYPE_ID'] ?? 0);
    
    if ($ownerId <= 0 || $ownerTypeId != 1) { // Пока только лиды (1)
        _log("SKIP: not a lead, ownerType=$ownerTypeId");
        return;
    }
    
    _log("Lead: $ownerId, DiskFiles: " . implode(',', $diskIds));
    
    // Конвертируем disk ID -> b_file ID (копируем файлы)
    $fileIds = [];
    foreach ($diskIds as $diskId) {
        $diskFile = \Bitrix\Disk\File::loadById((int)$diskId);
        if (!$diskFile) continue;
        
        $bFileId = $diskFile->getFileId();
        $copiedId = \CFile::CopyFile($bFileId);
        if ($copiedId > 0) {
            $fileIds[] = $copiedId;
            _log("Copied: disk $diskId -> b_file $copiedId");
        }
    }
    
    if (empty($fileIds)) {
        _log("ERROR: no files copied");
        return;
    }
    
    // Сохраняем в b_uts_crm_lead (сериализованный массив)
    $connection = \Bitrix\Main\Application::getConnection();
    $serialized = serialize($fileIds);
    $escaped = $connection->getSqlHelper()->forSql($serialized);
    $fieldName = 'UF_CRM_LEAD_ATTACHMENTS';
    
    // Проверяем есть ли запись
    $exists = $connection->query("SELECT VALUE_ID FROM b_uts_crm_lead WHERE VALUE_ID = $ownerId")->fetch();
    
    if ($exists) {
        $connection->query("UPDATE b_uts_crm_lead SET $fieldName = '$escaped' WHERE VALUE_ID = $ownerId");
    } else {
        $connection->query("INSERT INTO b_uts_crm_lead (VALUE_ID, $fieldName) VALUES ($ownerId, '$escaped')");
    }
    
    _log("Saved " . count($fileIds) . " files to lead $ownerId");
    _log("===");
}
