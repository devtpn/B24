<?php

use Bitrix\Main\EventManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/missed_call_debug.log';

// Ловим события от всех модулей связанных с телефонией и CRM
$modules = ['itigrix', 'itilphone', 'voximplant', 'telephony', 'crm'];

foreach ($modules as $module) {
    EventManager::getInstance()->addEventHandler(
        $module,
        '*',
        function(\Bitrix\Main\Event $event) use ($logFile, $module) {
            $type   = $event->getEventType();
            $params = $event->getParameters();

            // Пишем только события связанные со звонками
            $keywords = ['call', 'phone', 'miss', 'звон'];
            $typeLC   = mb_strtolower($type);
            foreach ($keywords as $kw) {
                if (str_contains($typeLC, $kw)) {
                    file_put_contents(
                        $logFile,
                        date('Y-m-d H:i:s') . " | module=$module | event=$type | params=" . print_r($params, true) . "\n",
                        FILE_APPEND
                    );
                    break;
                }
            }
        }
    );
}
