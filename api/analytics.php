<?php
define('TRADING_BOOT', true);
// ============================================================
// analytics.php — JSON API for the dashboard
//
// Endpoints:
//   GET  ?action=imports                    → list all imports (week dropdown)
//   GET  ?action=weekly[&import_id=N]       → full data for one week
//   GET  ?action=continuation[&import_id=N] → focus / avoid suggestions
//
// Phases 4-5 will add:
//   POST ?action=save_note     body: {import_id, symbol, ...}
//   POST ?action=save_review   body: {import_id, focus, avoid, ...}
//
// Auth: API_KEY query param OR future PHP session (Phase 5).
// ============================================================

require __DIR__ . '/config.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Recommender.php';

handleCorsPreflight();

// ---- Auth ----
$key = getApiKey();
$authedBySession = false;  // Phase 5 will populate this
if (!$authedBySession) {
    if ($key === null || !hash_equals(API_KEY, $key)) {
        jsonError('Unauthorized', 401);
    }
}

$action = $_GET['action'] ?? '';
$pdo = Db::pdo();

try {
    switch ($action) {
        case 'imports':
            jsonResponse(['success' => true, 'imports' => listImports($pdo)]);
            break;

        case 'weekly':
            $id = resolveImportId($pdo, $_GET['import_id'] ?? null);
            jsonResponse(['success' => true] + buildWeekly($pdo, $id));
            break;

        case 'continuation':
            $id = resolveImportId($pdo, $_GET['import_id'] ?? null);
            jsonResponse(['success' => true] + buildContinuation($pdo, $id));
            break;

        default:
            jsonError("Unknown action '$action'. Use: imports | weekly | continuation", 400);
    }
} catch (Throwable $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}

// ============================================================
// Query helpers
// ============================================================

function resolveImportId(PDO $pdo, $requested): int {
    if ($requested !== null && ctype_digit((string)$requested)) {
        return (int)$requested;
    }
    $row = $pdo->query('SELECT id FROM ibkr_imports ORDER BY period_end DESC, id DESC LIMIT 1')->fetch();
    if (!$row) {
        jsonError('No imports yet. POST an IBKR report to /api/import.php first.', 404);
    }
    return (int)$row['id'];
}

function listImports(PDO $pdo): array {
    return $pdo->query(
        'SELECT id, account_id, period_start, period_end, nav_start, nav_end, nav_change, twr, imported_at
         FROM ibkr_imports
         ORDER BY period_end DESC, id DESC'
    )->fetchAll();
}

function buildWeekly(PDO $pdo, int $id): array {
    // Header / import meta
    $imp = $pdo->prepare(
        'SELECT id, account_id, account_name, period_start, period_end,
                nav_start, nav_end, nav_change, mtm_pl, twr,
                commissions, trades_sales, trades_purchase,
                dividends, interest, deposits, withdrawals,
                source_filename, imported_at
         FROM ibkr_imports WHERE id = ?'
    );
    $imp->execute([$id]);
    $import = $imp->fetch();
    if (!$import) jsonError('Import not found', 404);

    // Trades
    $tr = $pdo->prepare(
        'SELECT id, asset_class, symbol, trade_datetime, raw_datetime, quantity, trade_price, close_price,
                proceeds, commission, basis, realized_pl, mtm_pl, trade_code, direction
         FROM ibkr_trades WHERE import_id = ?
         ORDER BY trade_datetime ASC, id ASC'
    );
    $tr->execute([$id]);
    $trades = $tr->fetchAll();

    // Open positions
    $pos = $pdo->prepare(
        'SELECT id, asset_class, symbol, quantity, avg_cost, close_price, market_value, unrealized_pl
         FROM ibkr_open_positions WHERE import_id = ? ORDER BY ABS(unrealized_pl) DESC'
    );
    $pos->execute([$id]);
    $positions = $pos->fetchAll();

    // Symbol summary (filter aggregate rows)
    $sum = $pdo->prepare(
        "SELECT symbol, description, asset_class,
                realized_pl, unrealized_pl, total_pl, mtm_pl_position, mtm_pl_transaction,
                mtd_pl, ytd_pl
         FROM ibkr_symbol_summary
         WHERE import_id = ?
           AND LOWER(symbol) NOT LIKE '%total%'
           AND LOWER(symbol) NOT LIKE '%all assets%'
         ORDER BY (realized_pl IS NULL), realized_pl DESC"
    );
    $sum->execute([$id]);
    $symbols = $sum->fetchAll();

    // Notes for this import
    $nt = $pdo->prepare('SELECT symbol, emotion_before, emotion_after, good_trade, regret_flag, lesson, free_text
                         FROM trade_notes WHERE import_id = ?');
    $nt->execute([$id]);
    $notesBySymbol = [];
    foreach ($nt->fetchAll() as $n) $notesBySymbol[$n['symbol']] = $n;

    // Weekly review (continuation plan)
    $rv = $pdo->prepare('SELECT focus_tickers, avoid_tickers, key_levels_json, weekly_lesson, plan_next_week
                         FROM weekly_reviews WHERE import_id = ?');
    $rv->execute([$id]);
    $review = $rv->fetch() ?: null;

    // Stats
    $stats = computeStats($trades);

    // Recommendations for open positions
    foreach ($positions as &$p) {
        $rec = Recommender::positionAction($p, $notesBySymbol[$p['symbol']] ?? null);
        $p['recommendation'] = $rec;
    }
    unset($p);

    // Symbol ranking (winners/losers) — strip aggregate symbols
    $ranked = [];
    foreach ($symbols as $s) {
        $ranked[] = [
            'symbol'        => $s['symbol'],
            'description'   => $s['description'],
            'asset_class'   => $s['asset_class'],
            'realized_pl'   => $s['realized_pl'] !== null ? (float)$s['realized_pl'] : null,
            'unrealized_pl' => $s['unrealized_pl'] !== null ? (float)$s['unrealized_pl'] : null,
            'total_pl'      => $s['total_pl'] !== null ? (float)$s['total_pl'] : null,
            'mtd_pl'        => $s['mtd_pl'] !== null ? (float)$s['mtd_pl'] : null,
            'ytd_pl'        => $s['ytd_pl'] !== null ? (float)$s['ytd_pl'] : null,
            'trade_count'   => countSymbolTrades($trades, $s['symbol']),
            'note'          => $notesBySymbol[$s['symbol']] ?? null,
        ];
    }

    // Equity curve — per-day cumulative realized + EoD unrealized (last day only)
    $equityCurve = buildEquityCurve($trades, $positions, $import);

    return [
        'import'        => $import,
        'stats'         => $stats,
        'equity_curve'  => $equityCurve,
        'rank_symbols'  => $ranked,
        'open_positions'=> $positions,
        'trades'        => $trades,
        'notes'         => $notesBySymbol,
        'review'        => $review,
    ];
}

function buildContinuation(PDO $pdo, int $id): array {
    $sum = $pdo->prepare(
        "SELECT symbol, realized_pl, ytd_pl
         FROM ibkr_symbol_summary
         WHERE import_id = ?
           AND LOWER(symbol) NOT LIKE '%total%'
           AND LOWER(symbol) NOT LIKE '%all assets%'"
    );
    $sum->execute([$id]);
    $symbols = $sum->fetchAll();

    $nt = $pdo->prepare('SELECT symbol, regret_flag FROM trade_notes WHERE import_id = ?');
    $nt->execute([$id]);
    $notesBySymbol = [];
    foreach ($nt->fetchAll() as $n) $notesBySymbol[$n['symbol']] = $n;

    $result = Recommender::focusAndAvoid($symbols, $notesBySymbol);
    return ['import_id' => $id] + $result;
}

// ============================================================
// Stats
// ============================================================
function computeStats(array $trades): array {
    $closingTrades = array_filter($trades, fn($t) => (float)$t['realized_pl'] !== 0.0);
    $winners = 0;
    $losers  = 0;
    $best    = null;
    $worst   = null;
    $totalRealized = 0.0;
    foreach ($closingTrades as $t) {
        $pl = (float)$t['realized_pl'];
        $totalRealized += $pl;
        if ($pl > 0) $winners++;
        elseif ($pl < 0) $losers++;
        if ($best === null || $pl > $best) $best = $pl;
        if ($worst === null || $pl < $worst) $worst = $pl;
    }
    $totalClosing = count($closingTrades);
    return [
        'total_trades'  => count($trades),
        'closing_trades'=> $totalClosing,
        'winners'       => $winners,
        'losers'        => $losers,
        'total_realized'=> round($totalRealized, 2),
        'avg_pl'        => $totalClosing > 0 ? round($totalRealized / $totalClosing, 2) : null,
        'best_trade'    => $best,
        'worst_trade'   => $worst,
        'win_rate'      => $totalClosing > 0 ? round(100 * $winners / $totalClosing, 1) : null,
    ];
}

function countSymbolTrades(array $trades, string $symbol): int {
    return count(array_filter($trades, fn($t) => $t['symbol'] === $symbol));
}

// Per-day cumulative realized P&L over the period; mark EoD with unrealized as final point.
function buildEquityCurve(array $trades, array $positions, array $import): array {
    $byDay = [];
    foreach ($trades as $t) {
        // trade_datetime is UTC; group by UTC date for simplicity. Display layer can re-bucket if needed.
        $day = substr($t['trade_datetime'], 0, 10);
        $byDay[$day] = ($byDay[$day] ?? 0) + (float)$t['realized_pl'];
    }
    ksort($byDay);
    $points = [];
    $cum = 0.0;
    foreach ($byDay as $day => $sum) {
        $cum += $sum;
        $points[] = ['date' => $day, 'realized_cum' => round($cum, 2), 'realized_day' => round($sum, 2)];
    }
    $unrealizedTotal = 0;
    foreach ($positions as $p) $unrealizedTotal += (float)$p['unrealized_pl'];
    return [
        'points'           => $points,
        'unrealized_total' => round($unrealizedTotal, 2),
        'period'           => [$import['period_start'], $import['period_end']],
    ];
}
