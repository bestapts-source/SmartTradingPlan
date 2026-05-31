<?php
// ============================================================
// import.php — POST IBKR HTML report → MySQL
//
// Phase 1: writes only ibkr_imports (metadata + NAV + cash).
// Phase 2 will add trades, positions, summaries.
//
// Auth: ?api_key=KEY or X-API-Key header (no session required for now).
//
// Diagnostic mode:
//   POST ?action=debug_sections  with file => returns parser's section ID list.
// ============================================================

require __DIR__ . '/config.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/IbkrParser.php';

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
} catch (Throwable $e) {
    jsonError('Parser error: ' . $e->getMessage(), 422);
}

// ---- Diagnostic mode ----
if (($_GET['action'] ?? '') === 'debug_sections') {
    jsonResponse([
        'success'      => true,
        'account_id'   => $accountId,
        'account_name' => $accountName,
        'period'       => $period,
        'nav'          => $nav,
        'cash'         => $cash,
        'sections'     => $parser->getSectionIds(),
    ]);
}

// ---- Archive raw HTML ----
if (!is_dir(RAW_HTML_DIR)) {
    @mkdir(RAW_HTML_DIR, 0750, true);
}
$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName ?: ('upload_' . date('Ymd_His') . '.htm'));
$rawPath  = RAW_HTML_DIR . '/' . date('Ymd_His') . '_' . $safeName;
@file_put_contents($rawPath, $html);

// ---- Insert / update ibkr_imports ----
try {
    $importId = Db::transaction(function (PDO $pdo) use ($accountId, $accountName, $period, $nav, $cash, $origName, $rawPath) {
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

        // Resolve the id (lastInsertId is 0 on UPDATE branch)
        $id = (int)$pdo->lastInsertId();
        if ($id === 0) {
            $q = $pdo->prepare('SELECT id FROM ibkr_imports
                                 WHERE account_id=? AND period_start=? AND period_end=?');
            $q->execute([$accountId, $period['start'], $period['end']]);
            $id = (int)$q->fetchColumn();
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
    'trades'       => 0,        // populated in Phase 2
    'positions'    => 0,
    'summaries'    => 0,
    'phase'        => 1,
]);
