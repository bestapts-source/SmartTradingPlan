# Smart Trading Plan

Persistent trading analytics for IBKR weekly statements.

## Structure

```
/                              ← deployed to Cloudways `public_html/`
├── index.html, calc.html, ...  Static trading plan pages
├── nav.js, style.css           Shared UI shell
└── api/
    ├── schema.sql              MySQL DDL (run once via phpMyAdmin)
    ├── config.php              Public config + loads config.local.php
    ├── config.local.php        SECRETS — gitignored, lives on server only
    ├── import.php              POST IBKR HTML → MySQL
    ├── analytics.php           (TODO) JSON API for dashboard
    ├── lib/
    │   ├── Db.php              PDO singleton + transaction helper
    │   ├── IbkrParser.php      HTML report parser
    │   └── ...
    ├── .htaccess               Denies access to config.*, lib/, raw/, *.sql
    └── raw/                    Archive of uploaded .htm reports
```

## Deploy

Code is pulled via `git pull` from Cloudways. Secrets stay in `api/config.local.php`
which is NOT in git (see `api/config.local.example.php` for the template).

## Build phases

- **Phase 1 — DONE**: DB schema + minimal import (NAV + cash).
- Phase 2: full IBKR parser (trades, positions, summaries).
- Phase 3: analytics API + dashboard blocks 1–4 + upload.
- Phase 4: notes + journal migration.
- Phase 5: continuation plan + auth + polish.
