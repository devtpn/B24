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
            if (preg_match('/192\.168\./', $item['query']['QUERY_URL']))
            {
                // Лог всех событий для отладки — удалить после определения нужного события
                file_put_contents(
                    $logFile,
                    date('Y-m-d H:i:s') . ' | event=' . ($item['query']['QUERY_DATA']['event'] ?? 'none')
                    . ' | data=' . json_encode($item['query']['QUERY_DATA'], JSON_UNESCAPED_UNICODE) . "\n",
                    FILE_APPEND
                );

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
