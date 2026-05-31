# Smart Trading Plan — Project Map

Personal trading analytics for Vadim Shteiman. IBKR weekly Activity Statements
get parsed into MySQL, displayed as a dashboard with KPIs, equity curve, and
position recommendations.

**Read [docs/PROGRESS.md](docs/PROGRESS.md) first** to see what's done and what's
next. Full design lives in [docs/design.md](docs/design.md).

---

## Quick facts

| What | Where |
|---|---|
| **Working directory** | `C:\Users\Admin\SmartTradingPlan-work` (always `cd` here) |
| **GitHub repo** | https://github.com/bestapts-source/SmartTradingPlan |
| **Live URL** | https://phpstack-1428625-6458295.cloudwaysapps.com/ |
| **Dashboard** | https://phpstack-1428625-6458295.cloudwaysapps.com/analytics.php |
| **Cloudways server** | `master_dzevazptvk@167.99.254.44` — SSH alias: `cw` |
| **App folder on server** | `/home/master/applications/fhfhqwdqsj/public_html` |
| **DB on server** | `mysql -h 127.0.0.1 -u fhfhqwdqsj -p<pass> fhfhqwdqsj` |
| **Secrets locally** | `~/.smart-trading-creds` + `CLAUDE.local.md` (gitignored) |
| **IBKR reports** | `C:\Users\Admin\Downloads\U*.htm` |
| **Plan file (original)** | `~/.claude/plans/c-users-admin-downloads-trading-analyti-zazzy-neumann.md` |

## Repo layout (what's deployed verbatim to `public_html/`)

```
/                        ← deployed to Cloudways public_html
├── index.html, calc.html, rules.html, plan.html, watchlist.html  Static plan pages
├── journal.html         Currently Google Sheets — Phase 4 will migrate to MySQL
├── analytics.php        Dashboard (Blocks 1-4 + 7 done; 5-6 in Phase 4-5)
├── nav.js, style.css    Shared UI; both cache-busted with ?v=... query
├── login.php            Phase 5
└── api/
    ├── config.php       Public defaults; loads config.local.php
    ├── config.local.php SECRETS — on server only, NOT in git
    ├── import.php       POST IBKR HTML → MySQL (idempotent re-import)
    ├── analytics.php    GET imports / weekly / continuation; POST save_note (Phase 4) / save_review (Phase 5)
    ├── schema.sql       6-table DDL (rerun-safe with IF NOT EXISTS)
    ├── lib/
    │   ├── Db.php                  PDO singleton + transaction helper
    │   ├── Auth.php                Phase 5
    │   ├── IbkrParser.php          HTML report parser
    │   ├── DateTimeNormalizer.php  ET → UTC conversion
    │   └── Recommender.php         CONTINUE/REDUCE/CUT + focus/avoid logic
    └── raw/             Archive of uploaded .htm files (gitignored)
```

## How to do common things

```bash
# Always start here
cd ~/SmartTradingPlan-work

# Pull latest, edit, commit
git pull
# … edit files …
git add -A && git commit -m "..." && git push

# Deploy to Cloudways
ssh cw "cd /home/master/applications/fhfhqwdqsj/public_html && git pull"

# Tail PHP errors
ssh cw "tail -f /home/master/applications/fhfhqwdqsj/logs/php-app.error.log"

# Open a MySQL shell on the server
ssh cw "mysql -h 127.0.0.1 -u fhfhqwdqsj -p<see-creds> fhfhqwdqsj"

# Import an IBKR report (curl from local — replace <KEY>)
curl -F "report=@/c/Users/Admin/Downloads/U5879065_20260525_20260529.htm" \
     "https://phpstack-1428625-6458295.cloudwaysapps.com/api/import.php?api_key=<KEY>"

# Smoke-test the dashboard API
curl "https://phpstack-1428625-6458295.cloudwaysapps.com/api/analytics.php?action=imports&api_key=<KEY>"
```

API key, DB password, login password — all in `~/.smart-trading-creds` and
also documented in `CLAUDE.local.md` (gitignored).

## Conventions

- **Secrets** never go in git. They live in `api/config.local.php` on the
  server only. Local copies in `~/.smart-trading-creds` + `CLAUDE.local.md`.
- **`TRADING_BOOT` constant** — every entry point (`import.php`, `analytics.php`,
  any future PHP file accessed via HTTP) MUST `define('TRADING_BOOT', true)`
  at the top. Library files in `api/lib/` and `api/config.php` guard against
  direct access by checking it.
- **Cache busting** — `nav.js` and `style.css` are loaded with `?v=<version>`.
  On HTML pages the version is a hardcoded string (bump manually when those
  files change). On `analytics.php` we use `filemtime()` for automatic busting.
- **Times** stored as UTC in MySQL. Display layer formats to `Asia/Jerusalem`
  via `Intl.DateTimeFormat('ru-IL', { timeZone: 'Asia/Jerusalem' })`.
- **Idempotent imports** — re-importing the same week deletes the child rows
  (`ibkr_trades`, `ibkr_open_positions`, `ibkr_symbol_summary`) for that
  `import_id` and re-inserts. `trade_notes` survive (`FK ON DELETE SET NULL`).
- **Commit messages** — explain WHY (the problem the change solves), not just
  WHAT (the file/function touched). Sentence case, present tense.

## Phase status (short)

| Phase | Status | What |
|---|---|---|
| 1 | ✅ | DB schema + minimal import (NAV + cash) |
| 2 | ✅ | Full IBKR parser (trades / positions / symbol summary) |
| 3 | ✅ | Analytics API + dashboard blocks 1-4 + 7 (upload) |
| 4 | ⏳ next | Trade notes (Block 5) + migrate `journal.html` → MySQL |
| 5 | ⏳ | Continuation Plan (Block 6) + PHP session login + polish |

See `docs/PROGRESS.md` for the full changelog with verified numbers.
