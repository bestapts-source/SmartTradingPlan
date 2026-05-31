<?php
define('TRADING_BOOT', true);
// ============================================================
// import.php — POST IBKR HTML report → MySQL
//
// Auth: ?api_key=KEY or X-API-Key header.
//
// Diagnostic mode:
//   POST ?action=debug_sections   → returns parser's section IDs + parsed values
//   POST ?action=debug_dump       → returns full parsed structures (no DB write)
// ============================================================

require __DIR__ . '/config.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/IbkrParser.php';
require __DIR__ . '/lib/DateTimeNormalizer.php';

handleCorsPreflight();

// ---- Auth ----
$providedKey = getApiKey();
if ($providedKey === null || !hash_equals(API_KEY, $providedKey)) {
    jsonError('Unauthorized: missing or invalid api_key', 401);
}

// ---- Method ----
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonError('Method not allowed. Use POST with multipart/form-data field "report"', 405);
}

// ---- File ----
if (!isset($_FILES['report']) || $_FILES['report']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['report']['error'] ?? 'no file';
    jsonError("Upload failed (code: $err). Send multipart/form-data with field 'report'.", 400);
}

$tmpPath  = $_FILES['report']['tmp_name'];
$origName = $_FILES['report']['name'];

$html = file_get_contents($tmpPath);
if ($html === false || $html === '') {
    jsonError('Empty upload', 400);
}

// ---- Parse ----
try {
    $parser = new IbkrParser($html);
    $accountId   = $parser->getAccountId();
    $accountName = $parser->getAccountName();
    $period      = $parser->getPeriod($origName);
    $nav         = $parser->getNav();
    $cash        = $parser->getCashReport();
    $trades      = $parser->parseTrades();
    $positions   = $parser->parseOpenPositions();
    $summaries   = $parser->parseSymbolSummary();
} catch (Throwable $e) {
    jsonError('Parser error: ' . $e->getMessage(), 422);
}

// ---- Diagnostic modes ----
$action = $_GET['action'] ?? '';
if ($action === 'debug_sections') {
    jsonResponse([
        'success'      => true,
        'account_id'   => $accountId,
        'account_name' => $accountName,
        'period'       => $period,
        'nav'          => $nav,
        'cash'         => $cash,
        'counts'       => [
            'trades'    => count($trades),
            'positions' => count($positions),
            'summaries' => count($summaries),
        ],
        'sections'     => $parser->getSectionIds(),
    ]);
}
if ($action === 'debug_dump') {
    jsonResponse([
        'success'   => true,
        'period'    => $period,
        'nav'       => $nav,
        'cash'      => $cash,
        'trades'    => $trades,
        'positions' => $positions,
        'summaries' => $summaries,
    ]);
}

// ---- Archive raw HTML ----
if (!is_dir(RAW_HTML_DIR)) {
    @mkdir(RAW_HTML_DIR, 0750, true);
}
$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName ?: ('upload_' . date('Ymd_His') . '.htm'));
$rawPath  = RAW_HTML_DIR . '/' . date('Ymd_His') . '_' . $safeName;
@file_put_contents($rawPath, $html);

// ---- Persist ----
try {
    $importId = Db::transaction(function (PDO $pdo) use (
        $accountId, $accountName, $period, $nav, $cash,
        $trades, $positions, $summaries, $origName, $rawPath
    ) {
        // ---- ibkr_imports (parent) ----
        $sql = "INSERT INTO ibkr_imports
                  (account_id, account_name, period_start, period_end,
                   nav_start, nav_end, nav_change, mtm_pl, twr,
                   commissions, trades_sales, trades_purchase,
                   dividends, interest, deposits, withdrawals,
                   source_filename, raw_html_path)
                VALUES
                  (:account_id, :account_name, :period_start, :period_end,
                   :nav_start, :nav_end, :nav_change, :mtm_pl, :twr,
                   :commissions, :trades_sales, :trades_purchase,
                   :dividends, :interest, :deposits, :withdrawals,
                   :source_filename, :raw_html_path)
                ON DUPLICATE KEY UPDATE
                   account_name    = VALUES(account_name),
                   nav_start       = VALUES(nav_start),
                   nav_end         = VALUES(nav_end),
                   nav_change      = VALUES(nav_change),
                   mtm_pl          = VALUES(mtm_pl),
                   twr             = VALUES(twr),
                   commissions     = VALUES(commissions),
                   trades_sales    = VALUES(trades_sales),
                   trades_purchase = VALUES(trades_purchase),
                   dividends       = VALUES(dividends),
                   interest        = VALUES(interest),
                   deposits        = VALUES(deposits),
                   withdrawals     = VALUES(withdrawals),
                   source_filename = VALUES(source_filename),
                   raw_html_path   = VALUES(raw_html_path),
                   imported_at     = CURRENT_TIMESTAMP";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':account_id'      => $accountId,
            ':account_name'    => $accountName,
            ':period_start'    => $period['start'],
            ':period_end'      => $period['end'],
            ':nav_start'       => $nav['nav_start'],
            ':nav_end'         => $nav['nav_end'],
            ':nav_change'      => $nav['nav_change'],
            ':mtm_pl'          => $nav['mtm_pl'],
            ':twr'             => $nav['twr'],
            ':commissions'     => $cash['commissions'],
            ':trades_sales'    => $cash['trades_sales'],
            ':trades_purchase' => $cash['trades_purchase'],
            ':dividends'       => $cash['dividends'],
            ':interest'        => $cash['interest'],
            ':deposits'        => $cash['deposits'],
            ':withdrawals'    => $cash['withdrawals'],
            ':source_filename' => $origName,
            ':raw_html_path'   => $rawPath,
        ]);

        $id = (int)$pdo->lastInsertId();
        if ($id === 0) {
            $q = $pdo->prepare('SELECT id FROM ibkr_imports
                                 WHERE account_id=? AND period_start=? AND period_end=?');
            $q->execute([$accountId, $period['start'], $period['end']]);
            $id = (int)$q->fetchColumn();
        }
        if ($id === 0) throw new RuntimeException('Failed to resolve import_id');

        // ---- Wipe + re-insert child rows (trades / positions / summary) ----
        // trade_notes are kept (FK is ON DELETE SET NULL on schema's trade_notes)
        $pdo->prepare('DELETE FROM ibkr_trades         WHERE import_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ibkr_open_positions WHERE import_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM ibkr_symbol_summary WHERE import_id = ?')->execute([$id]);

        // ---- ibkr_trades ----
        if (!empty($trades)) {
            $sqlT = "INSERT INTO ibkr_trades
                       (import_id, asset_class, symbol, description,
                        trade_datetime, raw_datetime, quantity, trade_price, close_price,
                        proceeds, commission, basis, realized_pl, mtm_pl, trade_code)
                     VALUES
                       (:import_id, :asset_class, :symbol, :description,
                        :trade_datetime, :raw_datetime, :quantity, :trade_price, :close_price,
                        :proceeds, :commission, :basis, :realized_pl, :mtm_pl, :trade_code)";
            $stT = $pdo->prepare($sqlT);
            foreach ($trades as $t) {
                $stT->execute([
                    ':import_id'      => $id,
                    ':asset_class'    => $t['asset_class'],
                    ':symbol'         => $t['symbol'],
                    ':description'    => null,
                    ':trade_datetime' => $t['trade_datetime'],
                    ':raw_datetime'   => $t['raw_datetime'],
                    ':quantity'       => $t['quantity'],
                    ':trade_price'    => $t['trade_price'],
                    ':close_price'    => $t['close_price'],
                    ':proceeds'       => $t['proceeds'],
                    ':commission'     => $t['commission'],
                    ':basis'          => $t['basis'],
                    ':realized_pl'    => $t['realized_pl'],
                    ':mtm_pl'         => $t['mtm_pl'],
                    ':trade_code'     => $t['trade_code'],
                ]);
            }
        }

        // ---- ibkr_open_positions ----
        if (!empty($positions)) {
            $sqlP = "INSERT INTO ibkr_open_positions
                       (import_id, asset_class, symbol, description,
                        quantity, avg_cost, close_price, market_value, unrealized_pl)
                     VALUES
                       (:import_id, :asset_class, :symbol, :description,
                        :quantity, :avg_cost, :close_price, :market_value, :unrealized_pl)";
            $stP = $pdo->prepare($sqlP);
            foreach ($positions as $p) {
                $stP->execute([
                    ':import_id'    => $id,
                    ':asset_class'  => $p['asset_class'],
                    ':symbol'       => $p['symbol'],
                    ':description'  => null,
                    ':quantity'     => $p['quantity'],
                    ':avg_cost'     => $p['avg_cost'],
                    ':close_price'  => $p['close_price'],
                    ':market_value' => $p['market_value'],
                    ':unrealized_pl'=> $p['unrealized_pl'],
                ]);
            }
        }

        // ---- ibkr_symbol_summary ----
        if (!empty($summaries)) {
            $sqlS = "INSERT INTO ibkr_symbol_summary
                       (import_id, symbol, description, asset_class,
                        mtm_pl_position, mtm_pl_transaction,
                        realized_pl, unrealized_pl, total_pl,
                        mtd_pl, ytd_pl)
                     VALUES
                       (:import_id, :symbol, :description, :asset_class,
                        :mtm_pl_position, :mtm_pl_transaction,
                        :realized_pl, :unrealized_pl, :total_pl,
                        :mtd_pl, :ytd_pl)";
            $stS = $pdo->prepare($sqlS);
            foreach ($summaries as $s) {
                $stS->execute([
                    ':import_id'         => $id,
                    ':symbol'            => $s['symbol'],
                    ':description'       => $s['description'] ?? null,
                    ':asset_class'       => $s['asset_class'] ?? 'Stock',
                    ':mtm_pl_position'   => $s['mtm_pl_position'] ?? null,
                    ':mtm_pl_transaction'=> $s['mtm_pl_transaction'] ?? null,
                    ':realized_pl'       => $s['realized_pl'] ?? null,
                    ':unrealized_pl'     => $s['unrealized_pl'] ?? null,
                    ':total_pl'          => $s['total_pl'] ?? null,
                    ':mtd_pl'            => $s['mtd_pl'] ?? null,
                    ':ytd_pl'            => $s['ytd_pl'] ?? null,
                ]);
            }
        }

        return $id;
    });
} catch (Throwable $e) {
    jsonError('DB error: ' . $e->getMessage(), 500);
}

// ---- Response ----
jsonResponse([
    'success'      => true,
    'import_id'    => $importId,
    'account_id'   => $accountId,
    'account_name' => $accountName,
    'period'       => sprintf('%s → %s', $period['start'], $period['end']),
    'nav_start'    => $nav['nav_start'],
    'nav_end'      => $nav['nav_end'],
    'nav_change'   => $nav['nav_change'],
    'mtm_pl'       => $nav['mtm_pl'],
    'twr'          => $nav['twr'],
    'cash'         => $cash,
    'trades'       => count($trades),
    'positions'    => count($positions),
    'summaries'    => count($summaries),
    'phase'        => 2,
]);
