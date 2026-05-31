-- ============================================================
-- Trading Analytics — MySQL Schema
-- Run once in phpMyAdmin against database `trading_analytics`
-- All timestamps stored as UTC. Display layer converts to Asia/Jerusalem.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. ibkr_imports — каждый загруженный IBKR Activity Statement
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ibkr_imports (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id      VARCHAR(32) NOT NULL,
    account_name    VARCHAR(255) DEFAULT NULL,
    period_start    DATE NOT NULL,
    period_end      DATE NOT NULL,

    -- NAV block
    nav_start       DECIMAL(15,2) DEFAULT NULL,
    nav_end         DECIMAL(15,2) DEFAULT NULL,
    nav_change      DECIMAL(15,2) DEFAULT NULL,
    mtm_pl          DECIMAL(15,2) DEFAULT NULL,
    twr             DECIMAL(8,4)  DEFAULT NULL,  -- percent, e.g. 12.2700

    -- Cash report
    commissions     DECIMAL(15,2) DEFAULT NULL,
    trades_sales    DECIMAL(15,2) DEFAULT NULL,
    trades_purchase DECIMAL(15,2) DEFAULT NULL,
    dividends       DECIMAL(15,2) DEFAULT NULL,
    interest        DECIMAL(15,2) DEFAULT NULL,
    deposits        DECIMAL(15,2) DEFAULT NULL,
    withdrawals    DECIMAL(15,2) DEFAULT NULL,

    -- Provenance
    source_filename VARCHAR(255) DEFAULT NULL,
    raw_html_path   VARCHAR(500) DEFAULT NULL,
    imported_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_account_period (account_id, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. ibkr_trades — каждая строка из секции Transactions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ibkr_trades (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id       INT UNSIGNED NOT NULL,

    asset_class     ENUM('Stock','Option','ETF','Forex','Other') NOT NULL DEFAULT 'Stock',
    symbol          VARCHAR(64) NOT NULL,
    description     VARCHAR(255) DEFAULT NULL,

    trade_datetime  DATETIME NOT NULL,                 -- UTC
    raw_datetime    VARCHAR(64) DEFAULT NULL,          -- original "2026-05-28, 065241"

    quantity        DECIMAL(15,4) NOT NULL,
    trade_price     DECIMAL(15,6) DEFAULT NULL,
    close_price     DECIMAL(15,6) DEFAULT NULL,
    proceeds        DECIMAL(15,2) DEFAULT NULL,
    commission      DECIMAL(15,2) DEFAULT NULL,
    basis           DECIMAL(15,2) DEFAULT NULL,
    realized_pl     DECIMAL(15,2) DEFAULT NULL,
    mtm_pl          DECIMAL(15,2) DEFAULT NULL,
    trade_code      VARCHAR(16)  DEFAULT NULL,         -- O / C / CP / P

    direction       VARCHAR(8) GENERATED ALWAYS AS (IF(quantity > 0, 'BUY', 'SELL')) VIRTUAL,

    PRIMARY KEY (id),
    KEY idx_trades_import (import_id),
    KEY idx_trades_symbol (symbol),
    KEY idx_trades_datetime (trade_datetime),
    KEY idx_trades_import_symbol (import_id, symbol),
    CONSTRAINT fk_trades_import FOREIGN KEY (import_id)
        REFERENCES ibkr_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. ibkr_open_positions — открытые позиции на конец периода
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ibkr_open_positions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id       INT UNSIGNED NOT NULL,

    asset_class     ENUM('Stock','Option','ETF','Forex','Other') NOT NULL DEFAULT 'Stock',
    symbol          VARCHAR(64) NOT NULL,
    description     VARCHAR(255) DEFAULT NULL,

    quantity        DECIMAL(15,4) NOT NULL,
    avg_cost        DECIMAL(15,6) DEFAULT NULL,
    close_price     DECIMAL(15,6) DEFAULT NULL,
    market_value    DECIMAL(15,2) DEFAULT NULL,
    unrealized_pl   DECIMAL(15,2) DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_pos_import (import_id),
    KEY idx_pos_symbol (symbol),
    UNIQUE KEY uq_pos_import_symbol (import_id, symbol),
    CONSTRAINT fk_pos_import FOREIGN KEY (import_id)
        REFERENCES ibkr_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. ibkr_symbol_summary — сводка P&L по символу за период
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ibkr_symbol_summary (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id       INT UNSIGNED NOT NULL,

    symbol          VARCHAR(64) NOT NULL,
    description     VARCHAR(255) DEFAULT NULL,
    asset_class     ENUM('Stock','Option','ETF','Forex','Other') DEFAULT 'Stock',

    mtm_pl_position DECIMAL(15,2) DEFAULT NULL,
    mtm_pl_transaction DECIMAL(15,2) DEFAULT NULL,
    realized_pl     DECIMAL(15,2) DEFAULT NULL,
    unrealized_pl   DECIMAL(15,2) DEFAULT NULL,
    total_pl        DECIMAL(15,2) DEFAULT NULL,

    mtd_pl          DECIMAL(15,2) DEFAULT NULL,
    ytd_pl          DECIMAL(15,2) DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_sum_import (import_id),
    KEY idx_sum_symbol (symbol),
    UNIQUE KEY uq_sum_import_symbol (import_id, symbol),
    CONSTRAINT fk_sum_import FOREIGN KEY (import_id)
        REFERENCES ibkr_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. trade_notes — ручные заметки (эмоции, оценка, урок)
-- ON DELETE SET NULL защищает legacy записи мигрированные из Google Sheets
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trade_notes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id       INT UNSIGNED DEFAULT NULL,
    symbol          VARCHAR(64) NOT NULL,
    note_date       DATE DEFAULT NULL,

    setup           VARCHAR(100) DEFAULT NULL,
    emotion_before  ENUM('Calm','Confident','FOMO','Anxious','Greedy','Neutral') DEFAULT NULL,
    emotion_after   ENUM('Satisfied','Regret','Neutral','Proud','Frustrated') DEFAULT NULL,
    good_trade      TINYINT(1) DEFAULT NULL,
    regret_flag     TINYINT(1) DEFAULT 0,

    lesson          TEXT,
    free_text       TEXT,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_notes_import (import_id),
    KEY idx_notes_symbol (symbol),
    UNIQUE KEY uq_notes_import_symbol (import_id, symbol),
    CONSTRAINT fk_notes_import FOREIGN KEY (import_id)
        REFERENCES ibkr_imports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. weekly_reviews — план на следующую неделю
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS weekly_reviews (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id       INT UNSIGNED DEFAULT NULL,
    week_start      DATE NOT NULL,
    week_end        DATE NOT NULL,

    focus_tickers   TEXT,  -- JSON: [{"symbol":"NOW","reason":"strong trend"}]
    avoid_tickers   TEXT,  -- JSON: ["FLY","KLAR"]
    key_levels_json TEXT,  -- JSON: {"NOW":{"support":118,"resistance":130}}

    weekly_lesson   TEXT,
    plan_next_week  TEXT,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_review_week (week_start, week_end),
    KEY idx_review_import (import_id),
    CONSTRAINT fk_review_import FOREIGN KEY (import_id)
        REFERENCES ibkr_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
