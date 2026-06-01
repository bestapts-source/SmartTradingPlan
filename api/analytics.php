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

        case 'notes':
            // GET all notes (for the journal page) — orphan + linked.
            // JOIN with ibkr_symbol_summary so each linked note carries the
            // IBKR-derived realized/total P&L for its symbol in that week.
            $rows = $pdo->query(
                "SELECT n.id, n.import_id, n.symbol, n.note_date,
                        n.setup, n.manual_pnl,
                        n.emotion_before, n.emotion_after,
                        n.good_trade, n.regret_flag, n.lesson, n.free_text,
                        n.created_at, n.updated_at,
                        i.period_start, i.period_end,
                        s.realized_pl  AS ibkr_realized_pl,
                        s.total_pl     AS ibkr_total_pl,
                        s.unrealized_pl AS ibkr_unrealized_pl,
                        /* effective_pnl = IBKR realized if linked, else manual */
                        COALESCE(s.realized_pl, n.manual_pnl) AS effective_pnl
                 FROM trade_notes n
                 LEFT JOIN ibkr_imports i        ON i.id = n.import_id
                 LEFT JOIN ibkr_symbol_summary s ON s.import_id = n.import_id AND s.symbol = n.symbol
                 ORDER BY COALESCE(n.note_date, n.updated_at) DESC, n.id DESC"
            )->fetchAll();
            jsonResponse(['success' => true, 'notes' => $rows]);
            break;

        case 'save_note':
            // POST upsert. Body fields:
            //   import_id (nullable), symbol (required), note_date (nullable),
            //   setup, emotion_before, emotion_after, good_trade, regret_flag,
            //   lesson, free_text
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                jsonError('save_note requires POST', 405);
            }
            $input = readJsonOrForm();
            jsonResponse(['success' => true] + saveNote($pdo, $input));
            break;

        case 'delete_note':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                jsonError('delete_note requires POST', 405);
            }
            $input = readJsonOrForm();
            $noteId = (int)($input['id'] ?? 0);
            if ($noteId <= 0) jsonError('id is required', 400);
            $stmt = $pdo->prepare('DELETE FROM trade_notes WHERE id = ?');
            $stmt->execute([$noteId]);
            jsonResponse(['success' => true, 'deleted' => $stmt->rowCount()]);
            break;

        default:
            jsonError("Unknown action '$action'. Use: imports | weekly | continuation | notes | save_note | delete_note", 400);
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

// ============================================================
// Notes (POST save_note)
// ============================================================
function readJsonOrForm(): array {
    // Prefer JSON body, fall back to POST form fields
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return $_POST;
}

function saveNote(PDO $pdo, array $input): array {
    $symbol = trim((string)($input['symbol'] ?? ''));
    if ($symbol === '') jsonError('symbol is required', 400);

    $importId = $input['import_id'] ?? null;
    if ($importId === '' || $importId === 0 || $importId === '0') $importId = null;
    if ($importId !== null) $importId = (int)$importId;

    $noteDate = trim((string)($input['note_date'] ?? ''));
    if ($noteDate === '') $noteDate = null;

    $allowedEmoBefore = ['Calm','Confident','FOMO','Anxious','Greedy','Neutral'];
    $allowedEmoAfter  = ['Satisfied','Regret','Neutral','Proud','Frustrated'];

    $emoBefore = $input['emotion_before'] ?? null;
    if ($emoBefore !== null && !in_array($emoBefore, $allowedEmoBefore, true)) $emoBefore = null;
    $emoAfter  = $input['emotion_after']  ?? null;
    if ($emoAfter  !== null && !in_array($emoAfter,  $allowedEmoAfter,  true)) $emoAfter  = null;

    $setup     = isset($input['setup'])      ? trim((string)$input['setup'])     : null;
    $goodTrade = isset($input['good_trade']) ? (int)(bool)$input['good_trade']   : null;
    $regret    = isset($input['regret_flag'])? (int)(bool)$input['regret_flag'] : 0;
    $lesson    = $input['lesson']    ?? null;
    $freeText  = $input['free_text'] ?? null;
    // manual_pnl is only set when the caller explicitly passes a number.
    // Empty string ⇒ null (clear). Missing key ⇒ leave whatever's in DB alone.
    $manualPnl = array_key_exists('manual_pnl', $input)
        ? (is_numeric($input['manual_pnl']) ? (float)$input['manual_pnl'] : null)
        : '__keep__';

    // Auto-link to an import if note_date is inside its period and no import_id given.
    if ($importId === null && $noteDate !== null) {
        $q = $pdo->prepare('SELECT id FROM ibkr_imports
                              WHERE ? BETWEEN period_start AND period_end
                              ORDER BY id DESC LIMIT 1');
        $q->execute([$noteDate]);
        $found = $q->fetchColumn();
        if ($found) $importId = (int)$found;
    }

    // The UNIQUE key is (import_id, symbol). When import_id is NULL, the unique
    // index doesn't fire — so for orphan notes we manually look one up first.
    if ($importId === null) {
        $sel = $pdo->prepare('SELECT id FROM trade_notes
                                WHERE import_id IS NULL AND symbol = ? AND COALESCE(note_date,"") = COALESCE(?,"")
                                LIMIT 1');
        $sel->execute([$symbol, $noteDate]);
        $existingId = $sel->fetchColumn();
        if ($existingId) {
            if ($manualPnl === '__keep__') {
                $upd = $pdo->prepare(
                    'UPDATE trade_notes
                        SET setup=?, emotion_before=?, emotion_after=?, good_trade=?, regret_flag=?, lesson=?, free_text=?,
                            note_date=?
                      WHERE id = ?'
                );
                $upd->execute([$setup, $emoBefore, $emoAfter, $goodTrade, $regret, $lesson, $freeText, $noteDate, $existingId]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE trade_notes
                        SET setup=?, manual_pnl=?, emotion_before=?, emotion_after=?, good_trade=?, regret_flag=?, lesson=?, free_text=?,
                            note_date=?
                      WHERE id = ?'
                );
                $upd->execute([$setup, $manualPnl, $emoBefore, $emoAfter, $goodTrade, $regret, $lesson, $freeText, $noteDate, $existingId]);
            }
            return ['id' => (int)$existingId, 'mode' => 'updated'];
        }
        $insManual = $manualPnl === '__keep__' ? null : $manualPnl;
        $ins = $pdo->prepare(
            'INSERT INTO trade_notes
                (import_id, symbol, note_date, setup, manual_pnl, emotion_before, emotion_after,
                 good_trade, regret_flag, lesson, free_text)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$symbol, $noteDate, $setup, $insManual, $emoBefore, $emoAfter, $goodTrade, $regret, $lesson, $freeText]);
        return ['id' => (int)$pdo->lastInsertId(), 'mode' => 'inserted'];
    }

    // import_id present → UNIQUE(import_id, symbol) lets us upsert.
    // If caller didn't pass manual_pnl, preserve whatever's already in DB.
    $manualPnlSql   = $manualPnl === '__keep__'
        ? 'manual_pnl = manual_pnl'
        : 'manual_pnl = VALUES(manual_pnl)';
    $insertManual   = $manualPnl === '__keep__' ? null : $manualPnl;

    $sql = "INSERT INTO trade_notes
               (import_id, symbol, note_date, setup, manual_pnl, emotion_before, emotion_after,
                good_trade, regret_flag, lesson, free_text)
            VALUES
               (:import_id, :symbol, :note_date, :setup, :manual_pnl, :emotion_before, :emotion_after,
                :good_trade, :regret_flag, :lesson, :free_text)
            ON DUPLICATE KEY UPDATE
               note_date     = VALUES(note_date),
               setup         = VALUES(setup),
               $manualPnlSql,
               emotion_before= VALUES(emotion_before),
               emotion_after = VALUES(emotion_after),
               good_trade    = VALUES(good_trade),
               regret_flag   = VALUES(regret_flag),
               lesson        = VALUES(lesson),
               free_text     = VALUES(free_text)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':import_id'      => $importId,
        ':symbol'         => $symbol,
        ':note_date'      => $noteDate,
        ':setup'          => $setup,
        ':manual_pnl'     => $insertManual,
        ':emotion_before' => $emoBefore,
        ':emotion_after'  => $emoAfter,
        ':good_trade'     => $goodTrade,
        ':regret_flag'    => $regret,
        ':lesson'         => $lesson,
        ':free_text'      => $freeText,
    ]);
    $newId = (int)$pdo->lastInsertId();
    if ($newId === 0) {
        $sel = $pdo->prepare('SELECT id FROM trade_notes WHERE import_id = ? AND symbol = ? LIMIT 1');
        $sel->execute([$importId, $symbol]);
        $newId = (int)$sel->fetchColumn();
    }
    return ['id' => $newId, 'mode' => $stmt->rowCount() === 1 ? 'inserted' : 'updated'];
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
