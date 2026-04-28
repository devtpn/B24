<?php

use Bitrix\Main\Loader;
use Bitrix\Main\EventManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

/**
 * Ищет открытый лид по номеру телефона.
 * Возвращает ID лида или false.
 */
function crmFindOpenLeadByPhone(string $phone)
{
    // Финальные статусы — при них лид считается закрытым
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

EventManager::getInstance()->addEventHandler(
    'crm',
    'OnCrmMissedCall',
    function(\Bitrix\Main\Event $event) {
        $params = $event->getParameters();
        $phone  = $params['PHONE_NUMBER'] ?? '';
        $callId = $params['CALL_ID'] ?? '';

        // Временный лог для отладки — удалить после проверки
        file_put_contents(
            $_SERVER['DOCUMENT_ROOT'] . '/missed_call_debug.log',
            date('Y-m-d H:i:s') . ' | phone=' . $phone . ' | params=' . print_r($params, true) . "\n",
            FILE_APPEND
        );

        if (empty($phone)) return;

        Loader::includeModule('crm');

        // Есть открытый лид — не создаём новый
        if (crmFindOpenLeadByPhone($phone)) return;

        $lead = new \CCrmLead(false);
        $lead->Add([
            'TITLE'              => 'Пропущенный звонок ' . $phone,
            'PHONE'              => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
            'SOURCE_ID'          => 'CALL',
            'SOURCE_DESCRIPTION' => 'Пропущенный звонок. ID: ' . $callId,
            'STATUS_ID'          => 'NEW',
            'CHECK_PERMISSIONS'  => 'N',
        ]);
    }
);
