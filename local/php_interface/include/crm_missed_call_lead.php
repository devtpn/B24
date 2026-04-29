<?php

use Bitrix\Main\Loader;
use Bitrix\Main\EventManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/missed_call_debug.log';

// Проверка подключения файла
file_put_contents($logFile, date('Y-m-d H:i:s') . " | file loaded\n", FILE_APPEND);

/**
 * Ищет открытый лид по номеру телефона.
 * Возвращает ID лида или false.
 */
function crmFindOpenLeadByPhone(string $phone)
{
    $closedStatuses = ['CONVERTED', 'JUNK'];

    $res = \CCrmLead::GetList(
        ['DATE_CREATE' => 'DESC'],
        [
            'PHONE'             => $phone,
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        ['nTopCount' => 1],
        ['ID', 'STATUS_ID']
    );

    if ($lead = $res->Fetch()) {
        if (!in_array($lead['STATUS_ID'], $closedStatuses)) {
            return $lead['ID'];
        }
    }

    return false;
}

// Ловим добавление активности (звонка) в CRM
EventManager::getInstance()->addEventHandler(
    'crm',
    'OnCrmActivityAdd',
    function(\Bitrix\Main\Event $event) use ($logFile) {
        $fields = $event->getParameter('FIELDS');

        file_put_contents(
            $logFile,
            date('Y-m-d H:i:s') . " | OnCrmActivityAdd | fields=" . print_r($fields, true) . "\n",
            FILE_APPEND
        );

        // Проверяем что это звонок и он пропущенный
        if (
            ($fields['TYPE_ID'] ?? 0) != \CCrmActivityType::Call
            || ($fields['DIRECTION'] ?? 0) != \CCrmActivityDirection::Incoming
        ) {
            return;
        }

        // Пропущенный = звонок не завершён успешно
        // itigrix пишет статус 2 (failed) для пропущенных
        if (($fields['COMPLETED'] ?? 'Y') === 'Y') {
            return;
        }

        $phone = $fields['DESCRIPTION'] ?? '';

        // Пробуем достать номер из разных полей
        if (empty($phone)) {
            $phone = $fields['SUBJECT'] ?? '';
        }

        file_put_contents(
            $logFile,
            date('Y-m-d H:i:s') . " | missed call detected | phone=$phone\n",
            FILE_APPEND
        );

        if (empty($phone)) return;

        Loader::includeModule('crm');

        if (crmFindOpenLeadByPhone($phone)) return;

        $lead = new \CCrmLead(false);
        $lead->Add([
            'TITLE'              => 'Пропущенный звонок ' . $phone,
            'PHONE'              => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
            'SOURCE_ID'          => 'CALL',
            'SOURCE_DESCRIPTION' => 'Пропущенный звонок (itigrix)',
            'STATUS_ID'          => 'NEW',
            'CHECK_PERMISSIONS'  => 'N',
        ]);
    }
);
