<?php
/**
 * Кастомизация itigrix: создание лида при пропущенном входящем звонке
 * 
 * Файл размещается на сервере Asterisk:
 * /opt/itgrix_bx/customizer/actions/post_registration.php
 * 
 * После размещения включить кастомизацию в админке itigrix:
 * http://<Asterisk IP>:8077 → Customizer → включить post_registration
 * 
 * Требования:
 * - Создать входящий вебхук в Битрикс24:
 *   Настройки → Разработчикам → Входящий вебхук
 *   Права: crm (чтение + запись)
 * - Подставить URL вебхука в $webhookUrl ниже
 */

// ====== НАСТРОЙКИ ======
$webhookUrl = 'https://ВАШ_ПОРТАЛ/rest/1/ВАШ_ТОКЕН/';
// =======================

$callFull = $params['call_full'] ?? [];
$phone    = $params['PHONE_NUMBER'] ?? $callFull['phone'] ?? '';
$duration = (int)($callFull['duration'] ?? $params['DURATION'] ?? 0);
$type     = (int)($callFull['type'] ?? $params['TYPE'] ?? 0); // 2 = входящий

// Обрабатываем только входящие пропущенные (duration = 0)
if ($type !== 2 || $duration > 0 || empty($phone)) {
    return ['state' => 'success'];
}

// Проверяем есть ли уже открытый лид с этим номером
$checkResult = _itgrix_curl_get($webhookUrl . 'crm.lead.list.json?' . http_build_query([
    'filter' => [
        'PHONE'         => $phone,
        '!STATUS_ID'    => ['CONVERTED', 'JUNK'],
    ],
    'select' => ['ID'],
]));

if (!empty($checkResult['result'])) {
    // Открытый лид уже есть — не создаём
    return ['state' => 'success'];
}

// Создаём лид
_itgrix_curl_post($webhookUrl . 'crm.lead.add.json', [
    'fields' => [
        'TITLE'              => 'Пропущенный звонок ' . $phone,
        'PHONE'              => [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']],
        'SOURCE_ID'          => 'CALL',
        'SOURCE_DESCRIPTION' => 'Пропущенный звонок (itigrix)',
        'STATUS_ID'          => 'NEW',
    ],
]);

return ['state' => 'success'];

// ====== Вспомогательные функции ======

function _itgrix_curl_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}

function _itgrix_curl_post(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? [];
}
