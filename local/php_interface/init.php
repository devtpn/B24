<?php

\Bitrix\Main\Loader::includeModule('rest');

class MyEventProvider extends \Bitrix\Rest\Event\ProviderOAuth
{
    public function send(array $queryData)
    {
        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/missed_call_debug.log';
        $http = new \Bitrix\Main\Web\HttpClient();

        foreach ($queryData as $key => $item)
        {
            // Лог ВСЕХ событий без фильтра по URL
            $eventName = $item['query']['QUERY_DATA']['event'] ?? '';
            $data      = $item['query']['QUERY_DATA']['data'] ?? [];
            $url       = $item['query']['QUERY_URL'] ?? '';

            file_put_contents(
                $logFile,
                date('Y-m-d H:i:s') . ' | url=' . $url . ' | event=' . $eventName
                . ' | full_query_data=' . json_encode($item['query']['QUERY_DATA'], JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );

            if (preg_match('/192\.168\./', $url))
            {
                $eventName = $item['query']['QUERY_DATA']['event'] ?? '';
                $data      = $item['query']['QUERY_DATA']['data'] ?? [];

                // Обработка завершения звонка — ищем пропущенные
                if ($eventName === 'ONEXTERNALCALLEND') {
                    $phone      = $data['PHONE_NUMBER'] ?? '';
                    $callId     = $data['CALL_ID'] ?? '';
                    $duration   = (int)($data['CALL_DURATION'] ?? 0);
                    $statusCode = (int)($data['CALL_FAILED_CODE'] ?? 0);

                    // Пропущенный = длительность 0 или статус не 200
                    if (!empty($phone) && ($duration === 0 || $statusCode !== 200)) {
                        \Bitrix\Main\Loader::includeModule('crm');

                        $closedStatuses = ['CONVERTED', 'JUNK'];
                        $res = \CCrmLead::GetList(
                            ['DATE_CREATE' => 'DESC'],
                            ['PHONE' => $phone, 'CHECK_PERMISSIONS' => 'N'],
                            false,
                            ['nTopCount' => 1],
                            ['ID', 'STATUS_ID']
                        );
                        $existingLead = $res->Fetch();
                        $hasOpenLead  = $existingLead && !in_array($existingLead['STATUS_ID'], $closedStatuses);

                        if (!$hasOpenLead) {
                            $lead = new \CCrmLead(false);
                            $lead->Add([
                                'TITLE'              => 'Пропущенный звонок ' . $phone,
                                'PHONE'              => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
                                'SOURCE_ID'          => 'CALL',
                                'SOURCE_DESCRIPTION' => 'Пропущенный звонок. ID: ' . $callId,
                                'STATUS_ID'          => 'NEW',
                                'CHECK_PERMISSIONS'  => 'N',
                            ]);

                            file_put_contents($logFile,
                                date('Y-m-d H:i:s') . " | lead created for $phone\n", FILE_APPEND);
                        } else {
                            file_put_contents($logFile,
                                date('Y-m-d H:i:s') . " | open lead exists for $phone, skipped\n", FILE_APPEND);
                        }
                    }
                }

                $http->post($item['query']['QUERY_URL'], $item['query']['QUERY_DATA']);
                unset($queryData[$key]);
            }
        }

        if (count($queryData) > 0)
        {
            parent::send(array_values($queryData));
        }
    }
}

\Bitrix\Rest\Event\Sender::setProvider(MyEventProvider::instance());

require_once __DIR__ . '/include/crm_missed_call_lead.php';

// Ловим добавление записи в таймлайн
\AddEventHandler('crm', 'OnCrmTimelineItemAdd', function($fields) {
    $logFile = $_SERVER['DOCUMENT_ROOT'] . '/missed_call_debug.log';
    file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . " | OnCrmTimelineItemAdd | " . json_encode($fields, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND
    );
});
