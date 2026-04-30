#!/bin/bash
# =============================================================================
# Скрипт синхронизации прода на тестовый сервер Битрикс24
# Запускать на ТЕСТОВОМ сервере после переноса БД и файлов
#
# Использование:
#   bash sync-prod-to-test.sh
# =============================================================================

set -e

# ====== НАСТРОЙКИ — заполнить перед запуском ======
DB_HOST="localhost"
DB_NAME="bitrix_db"
DB_USER="bitrix"
DB_PASS="your_password"
BITRIX_ROOT="/var/www/bitrix"
# ==================================================

MYSQL="mysql -h${DB_HOST} -u${DB_USER} -p${DB_PASS} ${DB_NAME}"

echo "=== [1/4] Анонимизация персональных данных ==="
$MYSQL < "$(dirname "$0")/anonymize-crm.sql"
echo "    Готово"

echo "=== [2/4] Отключение внешних интеграций ==="
$MYSQL <<'SQL'
-- Отключить исходящие вебхуки REST
UPDATE b_rest_app_event SET ACTIVE = 'N';

-- Отключить агентов рассылки
UPDATE b_agent SET ACTIVE = 'N' WHERE MODULE_ID IN ('sender', 'subscribe');

-- Очистить очередь email рассылок
TRUNCATE TABLE b_sender_mailing_chain;
DELETE FROM b_event WHERE DATE_EXEC > NOW() - INTERVAL 1 DAY;
SQL
echo "    Готово"

echo "=== [3/4] Удаление физических файлов вложений ==="
# Папки где Битрикс хранит файлы CRM активностей, записи звонков и загрузки
CRM_FILES_DIRS=(
    "${BITRIX_ROOT}/upload/crm"
    "${BITRIX_ROOT}/upload/voximplant"
    "${BITRIX_ROOT}/upload/iblock"
)

for dir in "${CRM_FILES_DIRS[@]}"; do
    if [ -d "$dir" ]; then
        rm -rf "${dir:?}"/*
        echo "    Очищена папка: $dir"
    else
        echo "    Папка не найдена (пропуск): $dir"
    fi
done
echo "    Готово"

echo "=== [4/4] Настройка init.php для тестового окружения ==="
INIT_FILE="${BITRIX_ROOT}/local/php_interface/init.php"
TEST_BLOCK="
// ===== ТЕСТОВОЕ ОКРУЖЕНИЕ — не копировать на прод =====
define('BX_SMTP_DISABLE', true);  // блокировать отправку email
// ======================================================
"

if ! grep -q 'BX_SMTP_DISABLE' "$INIT_FILE"; then
    sed -i "s|<?php|<?php\n${TEST_BLOCK}|" "$INIT_FILE"
    echo "    Добавлен блок отключения email в init.php"
else
    echo "    Блок уже есть в init.php, пропускаем"
fi

echo ""
echo "=== Готово! Тестовый сервер обновлён ==="
echo "    Персданные анонимизированы"
echo "    Звонки и письма удалены из БД"
echo "    Физические файлы вложений удалены"
echo "    Внешние интеграции отключены"
echo "    Email отправка заблокирована"
