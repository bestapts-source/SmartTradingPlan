# Progress Log

Append-only journal of phases, decisions, and verified numbers. New entries
go at the top.

---

## Phase 3 — Analytics API + read-only dashboard · ✅ DONE (2026-05-31)

**Built**

- `api/lib/Recommender.php`
  - `positionAction(pos, note)` → CONTINUE / REDUCE / CUT + `pct` + reason.
    Thresholds: `unrealized% > +5%` → CONTINUE, between `-3%` and `+5%` →
    REDUCE, `< -3%` or `regret_flag=1` → CUT.
  - `focusAndAvoid(symbols, notes)` → suggested ticker lists for next week.
    focus = realized > 0 AND ytd > 0; avoid = realized < -500 OR ytd < -3000
    OR regret_flag = 1.
- `api/analytics.php` — read-only JSON API:
  - `GET ?action=imports` — list of all weekly imports
  - `GET ?action=weekly[&import_id=N]` — full week payload: import meta, stats
    (winners/losers/win_rate/best/worst/avg), equity_curve (per-day cum), ranked
    symbols, open positions with `recommendation`, trades, notes, review
  - `GET ?action=continuation` — focus/avoid lists
- `analytics.php` — dashboard page (vanilla JS + Chart.js):
  - Block 1 — 4×2 KPI grid (NAV start/end/Δ, TWR, Realized, Win rate, totals,
    Best/Worst split card)
  - Block 2 — Chart.js line for cumulative realized P&L per day
  - Block 3 — ranked symbols table (Realized / Unrealized / Total / YTD)
  - Block 4 — open positions with CONTINUE/REDUCE/CUT badge + reason
  - Block 7 — drag-drop upload → reuses `import.php`, reloads on success
  - Week selector dropdown reads `/imports`
- `nav.js` — added "Аналитика" menu entry
- Cache-bust: `nav.js?v=20260531-2` + `style.css?v=20260531-2` on all 6 static
  HTML pages; `analytics.php` uses `filemtime()` for auto-bust.

**Verified against MD spec (week 25-29 May 2026)**

| Metric | Got | Spec |
|---|---|---|
| Total realized | +2,696.79 | +2,696.79 |
| Best trade | +1,340.00 (ONDS) | +1,340 |
| Worst trade | -573.91 (FLY) | -573.91 |
| Win rate | 62.5% (5W/3L) | 71.4% in MD — MD used 7 closing trades, we count 8 |
| NOW unrealized | +10,180 | +10,180 |
| Equity curve day 5 | 2,696.79 cum | matches |

**Decisions**

- KPI grid is fixed 4-col on desktop (was `auto-fit minmax(120px, 1fr)` which
  truncated long values like `+13,129.09`). Merged Лучшая+Худшая into one
  split card so we get a clean 4×2 = 8 cells.
- Symbol summary table filters out aggregate rows (`%total%`, `%all assets%`)
  in the SQL query — IBKR's MTM/FIFO tables include synthetic total rows.

**5 weeks imported for the dropdown**

```
01 May        +6,547   +6.54%
04-08 May    -1,702   -1.60%
11-15 May      -943   -0.90%
18-22 May    +3,037   +2.92%
25-29 May   +13,129  +12.27%
```

---

## Phase 2 — Full IBKR parser + transactional import · ✅ DONE (2026-05-31)

**Built**

- `IbkrParser` extended with `parseTrades()`, `parseOpenPositions()`,
  `parseSymbolSummary()` (merges Mtm / FIFO / MTDYTD tables by symbol).
- `DateTimeNormalizer::ibkrToUtc()` — handles both `"2026-05-28, 06:52:41"`
  and compact `"2026-05-28, 065241"` variants, converts ET → UTC.
- `import.php` extended to insert child rows inside the same
  `Db::transaction`. Re-import wipes `ibkr_trades` / `_open_positions` /
  `_symbol_summary` by `import_id` first, then re-inserts. `trade_notes`
  survive (FK ON DELETE SET NULL).
- Diagnostic mode: `POST ?action=debug_dump` returns full parsed structures
  without touching the DB.

**Verified**

- 16 trades / 2 positions / 59 symbols imported from the May 25-29 report.
- `SUM(realized_pl)` from `ibkr_trades` = `2696.79` exactly.
- DateTime `2026-05-28, 06:52:41` ET stored as `2026-05-28 10:52:41` UTC ✓.
- Idempotency: re-running the same upload keeps counts at 1/16/2/59.

**Issues found, deferred to Phase 5 polish**

- `ibkr_symbol_summary` contains synthetic aggregate rows like
  `"Total (All Assets)"` and `"Stocks  Total"`. Currently filtered at query
  time in `analytics.php`. Could be filtered at parse time instead.
- Options appear under two symbol formats: human-readable
  `TSLA 01JUN26 440 C` (from Transactions) and OCC `TSLA  260601C00440000`
  (from FIFOPerf). Two distinct rows in `ibkr_symbol_summary`.

---

## Phase 1 — DB schema + minimal import (NAV + cash) · ✅ DONE (2026-05-31)

**Built**

- `api/schema.sql` with 6 InnoDB tables (`ibkr_imports`, `ibkr_trades`,
  `ibkr_open_positions`, `ibkr_symbol_summary`, `trade_notes`,
  `weekly_reviews`). FK fan-out from `ibkr_imports`. Indexes on
  `(import_id, symbol)`, `(symbol)`, `(trade_datetime)`.
- `api/config.php` — non-sensitive defaults + helpers (`jsonResponse`,
  `jsonError`, `getApiKey`). Loads `config.local.php` for secrets.
- `api/config.local.php` (server-only, gitignored) — DB creds, API key,
  password hash.
- `api/lib/Db.php` — PDO singleton with `transaction(callable)` helper that
  rolls back on any throwable.
- `api/lib/IbkrParser.php` — initial pass; rewritten in same phase to match
  the real IBKR HTML structure (`tbl<Name>_U<accountId>Body` rather than the
  `sec<Name>` pattern from the MD spec).
- `api/import.php` — POST handler. Parses metadata, NAV, cash; writes
  `ibkr_imports` via `INSERT … ON DUPLICATE KEY UPDATE`. Archives the raw
  HTML to `api/raw/`.

**Verified**

| Field | Got | Spec |
|---|---|---|
| account_id | U5879065 | U5879065 |
| period | 2026-05-25 → 2026-05-29 | same |
| nav_start | 107,022.51 | 107,022.51 |
| nav_end | 120,151.60 | 120,151.60 |
| nav_change | +13,129.09 | +13,129.09 |
| mtm_pl | +13,229.09 | +13,229.09 |
| twr | 12.27 % | 12.27 % |
| commissions | -100.00 | -100.00 |

**Decisions**

- Parser uses dynamic XPath, never hardcodes account ID. Tries
  `tbl<Name>_U######Body` (underscore variant) first, then
  `tbl<Name>U######Body` (some sections like `ContractInfo`,
  `FIFOPerfSumByUnderlying` omit the underscore).
- `parseMoney()` handles `-20,850.00`, `(20,850.00)`, `--`, and `&nbsp;`.
- Encoding: `mb_convert_encoding` to UTF-8 + `libxml_use_internal_errors`
  before `DOMDocument::loadHTML`.

---

## Phase 0 — Infrastructure · ✅ DONE (2026-05-31)

- Git config set (name `bestapts-source`, email `vadim.w@gmail.com`).
- Ed25519 SSH keypair `~/.ssh/id_ed25519_cloudways` generated.
- Public key installed in `~/.openssh/authorized_keys` on Cloudways
  (NOT `.ssh/` — Cloudways' sshd uses `AuthorizedKeysFile %h/.openssh/...`).
- Repo `bestapts-source/SmartTradingPlan` cloned, frontend + initial backend
  committed.
- Cloudways `public_html` reset to be a git working tree of the repo. Original
  files backed up to `/home/master/predeploy_backup_20260531_165717/`.
- Cloudways DB `fhfhqwdqsj` already existed (same name as app user); schema
  applied without conflict.
- API key + login password generated server-side via `openssl rand`. Bcrypt
  hash stored in `api/config.local.php`.
- TRADING_BOOT guard added to `config.php` + all `lib/*.php` after we
  discovered Cloudways' Nginx serves `.php` files directly bypassing
  `.htaccess` rules. Library files now 403 on direct HTTP access.
