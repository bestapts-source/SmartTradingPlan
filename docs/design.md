# Trading Analytics Web App — Implementation Plan

## Context

У пользователя есть готовый фронтенд (`C:\Users\Admin\Downloads\TradingPlan webapp\` — 6 HTML-страниц + `nav.js` + `style.css`, задеплоен на Cloudways). Сейчас `journal.html` пишет в Google Sheets через Apps Script. Бэкенда и БД нет.

Цель: построить MySQL-бэкенд + PHP API + новый аналитический дашборд (`analytics.html`), который:
1. Принимает еженедельный IBKR Activity Statement (HTML), парсит и сохраняет в БД.
2. Показывает 7 блоков аналитики: KPIs, equity curve, winners/losers, open positions с рекомендациями CONTINUE/REDUCE/CUT, trade log с эмоциями, continuation plan на следующую неделю, upload form.
3. Мигрирует журнал из Google Sheets в MySQL (хард-кат).
4. Защищён логином (PHP session).

Полная спецификация: `C:\Users\Admin\Downloads\trading-analytics-summary.md`. Образец отчёта IBKR: `U5879065_20260525_20260529.htm`.

## Решения, принятые во время брейншторминга

| Развилка | Решение |
|---|---|
| MVP scope | Полный пакет — все 7 блоков сразу |
| Dev workflow | Пишем локально, тестим на Cloudways (без локального PHP) |
| Журнал (Sheets) | Хард-кат → MySQL, одноразовый миграционный скрипт |
| Авторизация | PHP session + login.php (не `.htaccess` Basic Auth — лучше для drag-drop и API) |
| Тесты | Только ручные smoke-тесты |
| Гранулярность заметок | По символу за неделю: `UNIQUE(import_id, symbol)` |
| YTD/MTD + Cash | Богатая версия: `mtd_pl`/`ytd_pl` в `ibkr_symbol_summary`; dividends/interest/deposits/withdrawals в `ibkr_imports` |
| Таймзона | Хранение UTC, отображение Israel time (Asia/Jerusalem) |
| Equity curve | Per-day агрегация (cumulative realized + EoD unrealized) |
| Continuation engine | Rule-based heuristic (см. ниже), пользователь может переопределить |

## Архитектура

```
public_html/
├── index.html, calc.html, rules.html, watchlist.html, plan.html  (как есть)
├── journal.html                ← переписать на PHP API
├── analytics.html              ← новый дашборд (7 блоков)
├── login.php                   ← страница логина
├── nav.js                      ← добавить ссылку "Аналитика"
└── api/
    ├── config.php              ← DB, API_KEY, AUTH_PASSWORD_HASH, BASE_TZ
    ├── import.php              ← POST: IBKR HTML → MySQL
    ├── analytics.php           ← GET/POST: JSON API дашборда
    ├── migrate_sheets.php      ← одноразовый импорт из Google Sheets
    ├── schema.sql              ← DDL
    ├── .htaccess               ← deny config.php, lib/, *.sql
    ├── lib/
    │   ├── Db.php              ← PDO singleton + transaction helper
    │   ├── Auth.php            ← session + API key
    │   ├── IbkrParser.php      ← парсинг секций HTML
    │   ├── DateTimeNormalizer.php  ← "2026-05-28, 065241" → MySQL DATETIME (UTC)
    │   └── Recommender.php     ← CONTINUE/REDUCE/CUT + focus/avoid logic
    └── raw/                    ← архив загруженных .htm файлов
```

## Слоистая модель

- **Парсер (`IbkrParser`)** — чистая функция: `html → структуры PHP`. Не знает про БД. Тестируется глазами на одном файле.
- **Импорт (`import.php`)** — оркестратор: парсер → транзакция в БД → JSON-ответ. Идемпотентен через `INSERT … ON DUPLICATE KEY UPDATE` + `DELETE WHERE import_id` для child-таблиц перед перезаписью.
- **Аналитика (`analytics.php`)** — read/write API. Read: JOIN-ы для dashboard. Write: `save_note`, `save_review`.
- **Frontend (`analytics.html`)** — vanilla JS + Chart.js. Один файл, модульные функции внутри `<script>`. Грузит данные через `fetch('api/analytics.php?action=weekly')`, рендерит блоки.

## Схема БД (6 таблиц)

Создание в порядке зависимостей (FK):

1. **`ibkr_imports`** — корневая. Поля по MD-спеке + `dividends`, `interest`, `deposits`, `withdrawals`, `raw_html_path`. `UNIQUE(account_id, period_start, period_end)`.
2. **`ibkr_trades`** — FK `import_id ON DELETE CASCADE`. Поля по MD-спеке. Индексы: `(symbol)`, `(trade_datetime)`, `(import_id, symbol)`.
3. **`ibkr_open_positions`** — FK CASCADE.
4. **`ibkr_symbol_summary`** — FK CASCADE + новые `mtd_pl`, `ytd_pl`. Индекс `(symbol)`.
5. **`trade_notes`** — FK `ON DELETE SET NULL` (защищает legacy записи из Sheets). `UNIQUE(import_id, symbol)`.
6. **`weekly_reviews`** — FK CASCADE. `UNIQUE(week_start, week_end)`. `continue_tickers` мерджим в `focus_tickers` (JSON `[{symbol, reason}]`).

Полный DDL — в `api/schema.sql`, исполняется через phpMyAdmin.

## Парсер: ключевые правила

- **Account ID динамический.** Сначала читаем из `secAccountInformation`, потом строим XPath: `//table[starts-with(@id, 'secTransactions')]`. Никакого хардкода `U5879065`.
- **Деньги.** Хелпер `parseMoney()`: убрать запятые, скобки `(...)` → минус, `floatval`. Тестировать на `-20,850.00` и `(20,850.00)`.
- **DateTime.** `"2026-05-28, 065241"` → распарсить в ET → конвертировать в UTC → хранить как `DATETIME`. Оригинал в `raw_datetime VARCHAR`.
- **Опционы.** Регекс `^([A-Z]+) (\d{2}[A-Z]{3}\d{2}) (\d+(\.\d+)?)([CP])$` → `asset_class='Option'`. Описание парсится в expiry/strike/type.
- **Partial fills.** Каждый fill — отдельная строка. Не агрегируем при парсинге.
- **Display:none / subtotal.** Фильтр: только строки с непустыми Symbol + DateTime, исключаем строки с классом `subTotal` или текстом `Total`.
- **Encoding.** `mb_convert_encoding` перед `DOMDocument::loadHTML` + `libxml_use_internal_errors(true)`.

## Рекомендательные правила

**Open Position → CONTINUE / REDUCE / CUT:**
```
pct = unrealized_pl / (avg_cost * abs(qty))
pct > +5% AND no regret_flag       → CONTINUE
pct between -3% и +5%              → REDUCE (трим 50%)
pct < -3% OR regret_flag = 1       → CUT
```

**Continuation Plan для следующей недели:**
```
focus  = realized_pl > 0 за неделю И ytd_pl > 0 И не в avoid
avoid  = realized_pl < -500 за неделю
         OR good_trade=0 (regret) для символа
         OR ytd_pl < -3000
```
Это suggestions — поля в Блоке 6 редактируемые, сохраняем в `weekly_reviews`.

## Авторизация

- `Auth::check()` — есть сессия ИЛИ совпал `X-API-Key`/`?api_key=`.
- `login.php` — форма (одно поле password). На submit: `password_verify` против `AUTH_PASSWORD_HASH` в config.php → `$_SESSION['logged_in']=true` → redirect.
- Все HTML-страницы (включая analytics.html и переписанный journal.html) начинаются с `<?php require 'api/lib/Auth.php'; Auth::requireLogin(); ?>` → значит их надо переименовать в `.php` ИЛИ поставить редирект через `.htaccess`. **Решение:** переименовать `analytics.html → analytics.php`, `journal.html → journal.php`. Старые статические оставляем как есть (calc, rules, watchlist, plan, index) и для них добавляем guard через `.htaccess` mod_rewrite на login.php.

## Фронтенд: dashboard блоки

```js
// analytics.php (single page)
async function loadWeekly(importId) {
  const data = await fetch(`api/analytics.php?action=weekly${importId?'&import_id='+importId:''}`).then(r=>r.json());
  renderKpis(data);             // Блок 1
  renderEquityCurve(data);      // Блок 2 — Chart.js line
  renderWinnersLosers(data);    // Блок 3
  renderOpenPositions(data);    // Блок 4 — с CONTINUE/REDUCE/CUT badge
  renderTradeLog(data);         // Блок 5 — inline edit notes
  renderContinuationPlan(data); // Блок 6
  // Блок 7 — drag-drop всегда сверху
}
async function saveNote(symbol, fields) { /* POST analytics.php?action=save_note */ }
async function saveReview(payload)     { /* POST analytics.php?action=save_review */ }
function setupDragDrop()               { /* POST import.php */ }
```

Все стили — из существующего `style.css` (dark theme). Chart.js — через CDN.

## Фазы реализации

### Фаза 1 — DB + минимальный импорт (только NAV)
**Файлы:** `api/schema.sql`, `api/config.php`, `api/.htaccess`, `api/lib/Db.php`, `api/lib/IbkrParser.php` (только metadata + NAV), `api/import.php` (минимальный).

**Verify:** phpMyAdmin показывает 6 таблиц; `curl` с .htm файлом возвращает `{success, import_id, nav_*}`; `SELECT * FROM ibkr_imports` показывает корректные NAV; повторный upload не плодит дубликаты.

### Фаза 2 — Полный парсер
**Файлы:** доделать `IbkrParser` (trades, positions, summaries, YTD, cash), добавить `DateTimeNormalizer`, расширить `import.php` транзакцией.

**Verify:** counts из спеки (`trades:12, positions:2, summaries:7`); SQL-споты: `ONDS realized = +1340`, `NOW unrealized = +10180`, `total realized = 2696.79`; rollback при битом HTML.

### Фаза 3 — Аналитика API + read-only дашборд
**Файлы:** `api/analytics.php` (actions: `weekly`, `imports`, `continuation`), `api/lib/Recommender.php`, `analytics.php` (новый, блоки 1-4 + 7), обновить `nav.js`.

**Verify:** открыть `analytics.php` → KPIs совпадают со спекой; equity curve рендерится; рекомендации NOW=CONTINUE / TSLA=REDUCE; drag-drop работает.

### Фаза 4 — Заметки + миграция журнала
**Файлы:** расширить `analytics.php` (`save_note`); добавить Блок 5; переписать `journal.html → journal.php` под новый API; написать `api/migrate_sheets.php` (одноразовый — читает Google Sheets, маппит эмоции 0-10 → enum, заливает в `trade_notes` с `import_id=NULL` для legacy).

**Verify:** правка эмоции в trade log персистится после reload; в journal.php видны мигрированные записи; новая запись через journal.php появляется в `trade_notes`.

### Фаза 5 — Continuation plan + auth + polish
**Файлы:** Блок 6 в `analytics.php`; `api/analytics.php` (`save_review`); `login.php`; `api/lib/Auth.php`; обновить `config.php` (sessions); добавить switcher между неделями (dropdown из `imports`).

**Verify:** logout/login работает; Continuation Plan сохраняется и подгружается; переключение недель в dropdown ре-рендерит все блоки.

## Риски и митигации

| Риск | Митигация |
|---|---|
| Account ID хардкод в section IDs | Динамический XPath по префиксу `starts-with(@id, 'secTransactions')` |
| DateTime парсинг ET vs IL | `DateTimeNormalizer::ibkrToMysql()` → UTC; вывод через JS `Intl.DateTimeFormat('he-IL', {timeZone:'Asia/Jerusalem'})` |
| Запятые/скобки в деньгах | `parseMoney()` хелпер, прогнать на всех числах из спеки сек. 4 |
| Re-import затирает `trade_notes` | FK `ON DELETE SET NULL` для notes; trades/positions удаляются перед перезаписью, notes остаются |
| Опционы как Stock | Регекс детектор + `asset_class` enum |
| HTML encoding | `mb_convert_encoding` + `libxml_use_internal_errors` |
| Cloudways `upload_max_filesize` | Документировать в config.php (default 32MB достаточно) |
| Sheets миграция: разные схемы | Маппинг 0-3→Calm, 4-6→Neutral, 7-10→FOMO; close-win→good_trade=1, close-loss→regret_flag=1; пользователь правит вручную после |

## Файлы, которые надо создать (полный список)

```
public_html/api/schema.sql
public_html/api/config.php
public_html/api/.htaccess
public_html/api/import.php
public_html/api/analytics.php
public_html/api/migrate_sheets.php
public_html/api/lib/Db.php
public_html/api/lib/Auth.php
public_html/api/lib/IbkrParser.php
public_html/api/lib/DateTimeNormalizer.php
public_html/api/lib/Recommender.php
public_html/analytics.php
public_html/login.php
```

Изменить:
```
public_html/nav.js             (добавить пункт меню "Аналитика")
public_html/journal.html       (→ journal.php, переписать на новый API)
```

## Верификация end-to-end

1. **БД:** phpMyAdmin → база `trading_analytics` → 6 таблиц с правильными FK.
2. **Импорт через curl:**
   ```
   curl -F "report=@U5879065_20260525_20260529.htm" \
     "https://phpstack-1428625-6409555.cloudwaysapps.com/api/import.php?api_key=KEY"
   ```
   Ожидаемо: `{success:true, trades:12, positions:2, summaries:7, nav_change:13129.09, twr:12.27}`.
3. **SQL-сверка:** `SELECT SUM(realized_pl) FROM ibkr_trades WHERE import_id=1` ≈ `2696.79`.
4. **Дашборд:** залогиниться → открыть `analytics.php` → проверить все 7 блоков визуально на тестовой неделе.
5. **Идемпотентность:** повторный upload того же файла → counts те же, нет дубликатов, ручные заметки сохранены.
6. **Журнал:** запустить `migrate_sheets.php` → старые записи в `trade_notes` → открыть `journal.php` → видны → добавить новую → сохраняется.
7. **Авторизация:** logout → попытка открыть `analytics.php` → редирект на `login.php`.
