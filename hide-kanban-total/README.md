# Скрытие суммы в канбане CRM Битрикс24

Скрывает отображение сумм в канбане сделок/лидов для всех пользователей.

## Установка

### 1. Создайте папку (если нет)

```bash
mkdir -p /home/bitrix/www/local/templates/.default/
```

### 2. Скопируйте файл стилей

**Если файла `template_styles.css` нет:**
```bash
cp template_styles.css /home/bitrix/www/local/templates/.default/
```

**Если файл уже существует:**
```bash
cat template_styles.css >> /home/bitrix/www/local/templates/.default/template_styles.css
```

### 3. Установите права

```bash
chown bitrix:bitrix /home/bitrix/www/local/templates/.default/template_styles.css
chmod 644 /home/bitrix/www/local/templates/.default/template_styles.css
```

### 4. Сбросьте кеш

**Админка → Настройки → Настройки продукта → Автокеширование → Очистить кеш**

## Что скрывается

- Сумма в заголовке каждой колонки канбана
- Подзаголовок с суммой
- Итоговая сумма по воронке
- Сумма в старом интерфейсе CRM

## Отключение

Удалите или закомментируйте соответствующие правила в CSS файле:

```css
/*
.crm-kanban-column-total {
    display: none !important;
}
*/
```

## Частичное скрытие

Если нужно скрыть только определённые элементы, оставьте только нужные селекторы.

Например, скрыть только итого по воронке:
```css
.crm-kanban-total-sum {
    display: none !important;
}
```
