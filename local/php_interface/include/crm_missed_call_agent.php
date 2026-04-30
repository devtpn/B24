<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

/**
 * Агент Битрикс24: создание лида при пропущенном входящем звонке.
 *
 * Как зарегистрировать агент:
 * Зайди в /bitrix/admin/agent_list.php → Добавить агент:
 *   - Модуль:    main
 *   - Функция:   CRMMissedCallLeadAgent();
 *   - Интервал:  300 (5 минут)
 *   - Активен:   да
 *
 * Подключить в init.php:
 *   require_once __DIR__ . '/include/crm_missed_call_agent.php';
 */

function CRMMissedCallLeadAgent(): string
{
    \Bitrix\Main\Loader::includeModule('crm');

    $closedStatuses = ['CONVERTED', 'JUNK'];

    // Ищем пропущенные звонки за последние 10 минут
    $timeFrom = \ConvertTimeStamp(time() - 600, 'FULL');

    $res = \CCrmActivity::GetList(
        ['ID' => 'DESC'],
        [
            'TYPE_ID'           => \CCrmActivityType::Call,
            'DIRECTION'         => \CCrmActivityDirection::Incoming,
            'COMPLETED'         => 'N',
            '>=CREATED'         => $timeFrom,
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        ['nTopCount' => 50],
        ['ID', 'SUBJECT', 'DESCRIPTION', 'COMMUNICATIONS']
    );

    while ($activity = $res->Fetch()) {
        // Номер телефона хранится в COMMUNICATIONS (JSON)
        $comms = is_string($activity['COMMUNICATIONS'])
            ? json_decode($activity['COMMUNICATIONS'], true)
            : [];
        $phone = $comms[0]['VALUE'] ?? $activity['SUBJECT'] ?? '';

        if (empty($phone)) continue;

        // Проверяем есть ли открытый лид с этим номером
        $leadRes = \CCrmLead::GetList(
            [],
            ['PHONE' => $phone, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID', 'STATUS_ID']
        );
        $lead = $leadRes->Fetch();

        if ($lead && !in_array($lead['STATUS_ID'], $closedStatuses)) {
            continue; // открытый лид уже есть
        }

        // Создаём лид
        $newLead = new \CCrmLead(false);
        $newLead->Add([
            'TITLE'              => 'Пропущенный звонок ' . $phone,
            'PHONE'              => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
            'SOURCE_ID'          => 'CALL',
            'SOURCE_DESCRIPTION' => 'Пропущенный звонок (агент)',
            'STATUS_ID'          => 'NEW',
            'CHECK_PERMISSIONS'  => 'N',
        ]);
    }

    return 'CRMMissedCallLeadAgent();'; // возвращаем себя для повторного запуска
}
