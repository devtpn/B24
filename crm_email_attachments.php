<?php
/**
 * Автоматическое извлечение вложений из входящих писем CRM
 * Поддержка: Лиды, Сделки, Контакты
 * 
 * @version 2.2.0 - использование Bitrix API
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

// Конфигурация: ownerTypeId => [entityClass, fieldName]
function getEntityConfig() {
    return [
        1 => [\Bitrix\Crm\LeadTable::class, 'UF_CRM_LEAD_ATTACHMENTS'],
        2 => [\Bitrix\Crm\DealTable::class, 'UF_CRM_DEAL_ATTACHMENTS'],
        3 => [\Bitrix\Crm\ContactTable::class, 'UF_CRM_CONTACT_ATTACHMENTS'],
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
    
    [$entityClass, $fieldName] = $config[$ownerTypeId];
    _log("Processing: type=$ownerTypeId, id=$ownerId, files=" . count($diskIds));
    
    // Копируем файлы disk -> b_file
    $fileIds = [];
    foreach ($diskIds as $diskId) {
        $diskFile = \Bitrix\Disk\File::loadById((int)$diskId);
        if (!$diskFile) continue;
        
        $copiedId = \CFile::CopyFile($diskFile->getFileId());
        if ($copiedId > 0) {
            $fileIds[] = $copiedId;
            _log("Copied: disk $diskId -> b_file $copiedId");
        }
    }
    
    if (empty($fileIds)) {
        _log("ERROR: no files copied");
        return;
    }
    
    // Сохраняем через D7 API
    $result = $entityClass::update($ownerId, [$fieldName => $fileIds]);
    
    if ($result->isSuccess()) {
        _log("Saved " . count($fileIds) . " files via D7 API");
    } else {
        _log("D7 API error: " . implode(', ', $result->getErrorMessages()));
        
        // Fallback: прямой SQL
        $conn = \Bitrix\Main\Application::getConnection();
        $table = $entityClass::getUfId() ? 'b_uts_' . strtolower(str_replace('\\', '_', $entityClass::getUfId())) : null;
        
        // Определяем таблицу по типу сущности
        $tables = [1 => 'b_uts_crm_lead', 2 => 'b_uts_crm_deal', 3 => 'b_uts_crm_contact'];
        $table = $tables[$ownerTypeId] ?? null;
        
        if ($table) {
            $serialized = $conn->getSqlHelper()->forSql(serialize($fileIds));
            $exists = $conn->query("SELECT VALUE_ID FROM $table WHERE VALUE_ID = $ownerId")->fetch();
            
            if ($exists) {
                $conn->query("UPDATE $table SET $fieldName = '$serialized' WHERE VALUE_ID = $ownerId");
            } else {
                $conn->query("INSERT INTO $table (VALUE_ID, $fieldName) VALUES ($ownerId, '$serialized')");
            }
            _log("Saved via SQL fallback");
        }
    }
}
