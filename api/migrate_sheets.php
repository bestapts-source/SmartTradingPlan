<?php
define('TRADING_BOOT', true);
// ============================================================
// migrate_sheets.php — ONE-SHOT pull from the legacy Google Sheets
// journal into trade_notes.
//
// Run via:
//   curl "https://…/api/migrate_sheets.php?api_key=KEY"
//   curl "https://…/api/migrate_sheets.php?api_key=KEY&dry=1"   # preview only
//
// Mapping (legacy → trade_notes):
//   row.Дата          → note_date
//   row.Тикер         → symbol
//   row.Эмоции (0-10) → emotion_before enum:
//                         0-3   → Calm
//                         4-6   → Neutral
//                         7-10  → FOMO
//   row.Действие      → good_trade / regret_flag:
//                         close-win   → good_trade=1
//                         close-loss  → regret_flag=1
//   row.Что произошло → free_text
//   row.Урок          → lesson
//
// import_id is left NULL on insert, then auto-resolved by saveNote()
// next time the user saves through analytics.php (or done explicitly in
// the loop below — we lookup an import that covers note_date).
//
// SAFETY:
//   - Idempotent: matches by (symbol, note_date) — re-run won't duplicate.
//   - dry=1 shows what would happen without writing.
// ============================================================

require __DIR__ . '/config.php';
require __DIR__ . '/lib/Db.php';

handleCorsPreflight();
$key = getApiKey();
if ($key === null || !hash_equals(API_KEY, $key)) jsonError('Unauthorized', 401);

$LEGACY_API = 'https://script.google.com/macros/s/AKfycbyP1USuGcg3vyrC-2Qi0hWh8iZ4ISjyWkGsn6p-I4Sfkbo8n4-Ziq5FEBnCqAs6LCXn/exec';
$dryRun = !empty($_GET['dry']);

// ---- Fetch from Sheets ----
$ctx = stream_context_create(['http' => ['timeout' => 30]]);
$raw = @file_get_contents($LEGACY_API . '?action=read', false, $ctx);
if ($raw === false) jsonError('Could not reach legacy Sheets API', 502);
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['ok'])) {
    jsonError('Legacy API returned unexpected payload', 502, ['payload' => substr($raw, 0, 500)]);
}
$legacyRows = $data['trades'] ?? [];
if (!is_array($legacyRows)) $legacyRows = [];

// ---- Map + write ----
$pdo = Db::pdo();
$results = ['fetched' => count($legacyRows), 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

// Pre-load imports for auto-linking by date
$imports = $pdo->query('SELECT id, period_start, period_end FROM ibkr_imports')->fetchAll();
$findImport = function(?string $date) use ($imports) {
    if (!$date) return null;
    foreach ($imports as $im) {
        if ($date >= $im['period_start'] && $date <= $im['period_end']) return (int)$im['id'];
    }
    return null;
};

$mapEmoBefore = function($n) {
    if ($n === null || $n === '') return null;
    $n = (int)$n;
    if ($n <= 3) return 'Calm';
    if ($n <= 6) return 'Neutral';
    return 'FOMO';
};

foreach ($legacyRows as $row) {
    if (!is_array($row)) { $results['skipped']++; continue; }
    $symbol   = trim((string)($row['Тикер']   ?? ''));
    $noteDate = trim((string)($row['Дата']    ?? ''));
    if ($symbol === '' || $noteDate === '') { $results['skipped']++; continue; }

    // Normalize date — Google Sheets sometimes returns ISO with time
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $noteDate, $m)) {
        $noteDate = "$m[1]-$m[2]-$m[3]";
    } else {
        $ts = strtotime($noteDate);
        if ($ts > 0) $noteDate = date('Y-m-d', $ts);
        else { $results['skipped']++; continue; }
    }

    $action  = (string)($row['Действие'] ?? '');
    $goodTrade  = $action === 'close-win'  ? 1 : null;
    $regretFlag = $action === 'close-loss' ? 1 : 0;

    $emoBefore = $mapEmoBefore($row['Эмоции'] ?? null);
    $freeText  = trim((string)($row['Что произошло'] ?? ''));
    $lesson    = trim((string)($row['Урок'] ?? ''));
    $pnlRaw    = $row['P&L'] ?? null;
    $manualPnl = is_numeric($pnlRaw) ? (float)$pnlRaw : null;

    $importId = $findImport($noteDate);

    // Look up an existing note for this (import_id, symbol). The UNIQUE key
    // is (import_id, symbol) — i.e. one note per symbol per week. Multiple
    // legacy entries for the same symbol-week get MERGED (free_text appended)
    // rather than dropped.
    $sel = $pdo->prepare(
        'SELECT id FROM trade_notes
          WHERE symbol = ?
            AND ( (import_id IS NULL AND ? IS NULL) OR import_id <=> ? )
          LIMIT 1'
    );
    $sel->execute([$symbol, $importId, $importId]);
    $existing = $sel->fetchColumn();

    if ($dryRun) {
        $results['samples'][] = [
            'symbol'    => $symbol,
            'date'      => $noteDate,
            'import_id' => $importId,
            'would'     => $existing ? 'MERGE' : 'INSERT',
            'free_text' => mb_substr($freeText, 0, 40),
        ];
        if ($existing) $results['updated']++; else $results['inserted']++;
        continue;
    }

    try {
        if ($existing) {
            // Merge: keep earliest note_date, append new free_text/lesson if
            // different, OR-merge regret_flag, prefer non-null good_trade.
            // For manual_pnl we SUM (multiple Sheets rows = multiple realized trades).
            $upd = $pdo->prepare(
                "UPDATE trade_notes
                    SET emotion_before = COALESCE(emotion_before, ?),
                        good_trade     = COALESCE(good_trade,     ?),
                        regret_flag    = GREATEST(regret_flag,    ?),
                        manual_pnl     = COALESCE(manual_pnl, 0) + COALESCE(?, 0),
                        lesson = TRIM(BOTH '\n' FROM CONCAT_WS('\n',
                                    NULLIF(lesson, ''),
                                    CASE
                                      WHEN ? = '' THEN NULL
                                      WHEN lesson IS NULL OR INSTR(lesson, ?) = 0 THEN ?
                                      ELSE NULL
                                    END)),
                        free_text = TRIM(BOTH '\n' FROM CONCAT_WS('\n',
                                    NULLIF(free_text, ''),
                                    CASE
                                      WHEN ? = '' THEN NULL
                                      WHEN free_text IS NULL OR INSTR(free_text, ?) = 0 THEN
                                        CONCAT('[', ?, '] ', ?)
                                      ELSE NULL
                                    END)),
                        import_id      = COALESCE(import_id, ?),
                        note_date      = LEAST(COALESCE(note_date, ?), ?)
                  WHERE id = ?"
            );
            $upd->execute([
                $emoBefore, $goodTrade, $regretFlag,
                $manualPnl,
                $lesson, $lesson, $lesson,
                $freeText, $freeText, $noteDate, $freeText,
                $importId,
                $noteDate, $noteDate,
                $existing,
            ]);
            $results['updated']++;
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO trade_notes
                   (import_id, symbol, note_date, manual_pnl, emotion_before, good_trade, regret_flag, lesson, free_text)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$importId, $symbol, $noteDate, $manualPnl, $emoBefore, $goodTrade, $regretFlag, $lesson, $freeText]);
            $results['inserted']++;
        }
    } catch (Throwable $e) {
        $results['errors'][] = [
            'symbol' => $symbol, 'date' => $noteDate,
            'error' => $e->getMessage(),
        ];
    }
}

// Trim long arrays before returning
if (count($results['errors']) > 10) {
    $results['errors_truncated'] = count($results['errors']);
    $results['errors'] = array_slice($results['errors'], 0, 10);
}
if (!empty($results['samples']) && count($results['samples']) > 20) {
    $results['samples_truncated'] = count($results['samples']);
    $results['samples'] = array_slice($results['samples'], 0, 20);
}

jsonResponse(['success' => true, 'dry_run' => $dryRun] + $results);
