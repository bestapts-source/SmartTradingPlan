<?php
if (!defined('TRADING_BOOT')) { http_response_code(403); exit('Forbidden'); }
// ============================================================
// Reviewer — deterministic weekly analysis from existing DB data.
// No LLM. Each bullet is composed from a SQL query + a template.
//
// Output shape:
//   ['lesson_bullets' => [['icon'=>'📊','text'=>'…'], …],
//    'plan_bullets'   => [['icon'=>'🟢','text'=>'…'], …],
//    'highlights'     => [['key'=>'best_trade','label'=>'Лучшая сделка','value'=>'ONDS +$1340'], …]]
// ============================================================

class Reviewer {

    public static function analyze(PDO $pdo, int $importId): array {
        $import = self::fetchImport($pdo, $importId);
        if (!$import) {
            return ['lesson_bullets' => [], 'plan_bullets' => [], 'highlights' => []];
        }

        $trades       = self::fetchTrades($pdo, $importId);
        $positions    = self::fetchPositions($pdo, $importId);
        $summaries    = self::fetchSummaries($pdo, $importId);
        $notes        = self::fetchNotes($pdo, $importId);
        $prevImports  = self::fetchPrevImports($pdo, $importId, 4);

        $lesson = [];
        $plan   = [];

        // ---------- LESSON ----------
        if ($t = self::topContributor($trades))               $lesson[] = $t;
        if ($t = self::bestAndWorst($trades))                  $lesson[] = $t;
        if ($t = self::stopsExecution($trades))                $lesson[] = $t;
        if ($t = self::emotionVsResult($trades, $notes))       $lesson[] = $t;
        if ($t = self::multiWeekRegrets($pdo, $importId, $notes)) $lesson[] = $t;
        if ($t = self::optionsPattern($trades))                $lesson[] = $t;
        if ($t = self::openPositionsContribution($positions, $trades)) $lesson[] = $t;
        if ($t = self::weekVsTrend($import, $prevImports))     $lesson[] = $t;

        // ---------- PLAN ----------
        foreach (self::openPositionPlan($positions, $notes) as $b) $plan[] = $b;
        if ($t = self::focusFromRules($summaries, $notes))      $plan[] = $t;
        if ($t = self::avoidFromRules($summaries, $notes, $pdo, $importId)) $plan[] = $t;
        if ($t = self::optionsForward($trades))                 $plan[] = $t;
        if ($t = self::emotionalDiscipline($trades, $notes))    $plan[] = $t;

        // ---------- Highlights (a few atoms for header pills) ----------
        $highlights = self::highlights($trades, $positions, $import);

        return [
            'import_id'      => $importId,
            'lesson_bullets' => $lesson,
            'plan_bullets'   => $plan,
            'highlights'     => $highlights,
        ];
    }

    // ==========================================================
    // Data loaders
    // ==========================================================

    private static function fetchImport(PDO $pdo, int $id): ?array {
        $st = $pdo->prepare('SELECT * FROM ibkr_imports WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    }

    private static function fetchTrades(PDO $pdo, int $id): array {
        $st = $pdo->prepare(
            'SELECT id, asset_class, symbol, trade_datetime, quantity, trade_price, close_price,
                    proceeds, commission, basis, realized_pl, mtm_pl, trade_code
             FROM ibkr_trades WHERE import_id = ? ORDER BY trade_datetime ASC'
        );
        $st->execute([$id]);
        return $st->fetchAll();
    }

    private static function fetchPositions(PDO $pdo, int $id): array {
        $st = $pdo->prepare(
            'SELECT symbol, quantity, avg_cost, close_price, market_value, unrealized_pl
             FROM ibkr_open_positions WHERE import_id = ?'
        );
        $st->execute([$id]);
        return $st->fetchAll();
    }

    private static function fetchSummaries(PDO $pdo, int $id): array {
        $st = $pdo->prepare(
            "SELECT symbol, realized_pl, unrealized_pl, total_pl, mtd_pl, ytd_pl
             FROM ibkr_symbol_summary
             WHERE import_id = ?
               AND LOWER(symbol) NOT LIKE '%total%'
               AND LOWER(symbol) NOT LIKE '%all assets%'"
        );
        $st->execute([$id]);
        return $st->fetchAll();
    }

    private static function fetchNotes(PDO $pdo, int $id): array {
        $st = $pdo->prepare(
            'SELECT symbol, emotion_before, emotion_after, good_trade, regret_flag, lesson, free_text
             FROM trade_notes WHERE import_id = ?'
        );
        $st->execute([$id]);
        $byKey = [];
        foreach ($st->fetchAll() as $n) $byKey[$n['symbol']] = $n;
        return $byKey;
    }

    private static function fetchPrevImports(PDO $pdo, int $currentId, int $limit): array {
        $st = $pdo->prepare(
            'SELECT id, period_start, period_end, nav_start, nav_end, nav_change, twr
             FROM ibkr_imports
             WHERE id != ? AND period_end <= (SELECT period_end FROM ibkr_imports WHERE id = ?)
             ORDER BY period_end DESC LIMIT ?'
        );
        $st->bindValue(1, $currentId, PDO::PARAM_INT);
        $st->bindValue(2, $currentId, PDO::PARAM_INT);
        $st->bindValue(3, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    // ==========================================================
    // LESSON bullets
    // ==========================================================

    private static function topContributor(array $trades): ?array {
        $bySym = [];
        $total = 0.0;
        foreach ($trades as $t) {
            $pl = (float)$t['realized_pl'];
            if ($pl === 0.0) continue;
            $bySym[$t['symbol']] = ($bySym[$t['symbol']] ?? 0) + $pl;
            $total += $pl;
        }
        if (!$bySym || abs($total) < 0.01) return null;
        arsort($bySym);
        $top  = array_key_first($bySym);
        $val  = $bySym[$top];
        $share= abs($val) / max(abs($total), 0.01) * 100;
        $rest = $total - $val;
        if ($share < 30) return null;
        return [
            'icon' => '🎯',
            'text' => sprintf(
                '**%s** дал %s — %.0f%% всего realized этой недели. Без него: %s.',
                $top, self::money($val), $share, self::money($rest)
            ),
        ];
    }

    private static function bestAndWorst(array $trades): ?array {
        $best = null; $worst = null;
        foreach ($trades as $t) {
            $pl = (float)$t['realized_pl'];
            if ($pl === 0.0) continue;
            if ($best  === null || $pl > $best['pl'])  $best  = ['sym' => $t['symbol'], 'pl' => $pl];
            if ($worst === null || $pl < $worst['pl']) $worst = ['sym' => $t['symbol'], 'pl' => $pl];
        }
        if (!$best && !$worst) return null;
        $parts = [];
        if ($best)  $parts[] = sprintf('Лучшая: **%s %s**', $best['sym'], self::money($best['pl']));
        if ($worst && $worst['pl'] < 0) $parts[] = sprintf('Худшая: **%s %s**', $worst['sym'], self::money($worst['pl']));
        return ['icon' => '📊', 'text' => implode('. ', $parts) . '.'];
    }

    private static function stopsExecution(array $trades): ?array {
        $stops = array_filter($trades, fn($t) => (float)$t['realized_pl'] < 0 && in_array(substr($t['trade_code'] ?? '', 0, 1), ['C']));
        if (!$stops) return null;
        $count = count($stops);
        $avg = array_sum(array_map(fn($t) => (float)$t['realized_pl'], $stops)) / $count;
        $sum = array_sum(array_map(fn($t) => (float)$t['realized_pl'], $stops));
        return [
            'icon' => '🛑',
            'text' => sprintf(
                'Сработало стопов: **%d** на общую сумму %s (средний %s). Это система — не держал убыток, не усреднялся.',
                $count, self::money($sum), self::money($avg)
            ),
        ];
    }

    private static function emotionVsResult(array $trades, array $notes): ?array {
        if (!$notes) return null;
        // Group symbols by emotion_before and sum realized P&L of trades for those symbols
        $byEmotion = [];
        foreach ($notes as $sym => $n) {
            $emo = $n['emotion_before'];
            if (!$emo) continue;
            $sumForSym = 0.0;
            $cnt = 0;
            foreach ($trades as $t) {
                if ($t['symbol'] === $sym && (float)$t['realized_pl'] !== 0.0) {
                    $sumForSym += (float)$t['realized_pl'];
                    $cnt++;
                }
            }
            if ($cnt === 0) continue;
            if (!isset($byEmotion[$emo])) $byEmotion[$emo] = ['sum' => 0, 'trades' => 0, 'symbols' => []];
            $byEmotion[$emo]['sum']    += $sumForSym;
            $byEmotion[$emo]['trades'] += $cnt;
            $byEmotion[$emo]['symbols'][] = $sym;
        }
        if (count($byEmotion) < 2) return null;
        // Compose a one-line summary
        uasort($byEmotion, fn($a, $b) => $b['sum'] <=> $a['sum']);
        $parts = [];
        foreach ($byEmotion as $emo => $d) {
            $parts[] = sprintf('%s = %s (%d сд.)', $emo, self::money($d['sum']), $d['trades']);
        }
        return [
            'icon' => '🎭',
            'text' => 'Эмоции до входа vs результат: ' . implode(', ', $parts) . '.',
        ];
    }

    private static function multiWeekRegrets(PDO $pdo, int $importId, array $notes): ?array {
        // Find symbols with regret_flag=1 in current week AND in earlier weeks
        $regretSyms = [];
        foreach ($notes as $sym => $n) {
            if ((int)$n['regret_flag'] === 1) $regretSyms[] = $sym;
        }
        if (!$regretSyms) return null;

        $placeholders = implode(',', array_fill(0, count($regretSyms), '?'));
        $st = $pdo->prepare(
            "SELECT symbol, COUNT(*) AS c
             FROM trade_notes
             WHERE regret_flag = 1
               AND import_id IS NOT NULL
               AND import_id != ?
               AND symbol IN ($placeholders)
             GROUP BY symbol
             HAVING c >= 2"
        );
        $st->execute(array_merge([$importId], $regretSyms));
        $repeats = $st->fetchAll();
        if (!$repeats) return null;

        $bits = array_map(fn($r) => sprintf('**%s** (%d прошлых недель)', $r['symbol'], $r['c']), $repeats);
        return [
            'icon' => '🚨',
            'text' => 'Повторяющиеся убыточные паттерны: ' . implode(', ', $bits) . '. Подумай о permanent Avoid.',
        ];
    }

    private static function optionsPattern(array $trades): ?array {
        $opts = array_filter($trades, fn($t) => $t['asset_class'] === 'Option');
        if (!$opts) return null;
        $closing = array_filter($opts, fn($t) => (float)$t['realized_pl'] !== 0.0);
        if (!$closing) return null;
        $realized = array_sum(array_map(fn($t) => (float)$t['realized_pl'], $closing));
        $winners = count(array_filter($closing, fn($t) => (float)$t['realized_pl'] > 0));
        $total   = count($closing);
        $wr      = $total > 0 ? round($winners / $total * 100) : 0;
        return [
            'icon' => '🎲',
            'text' => sprintf(
                'Опционы: %d закрывающих сделок, realized %s, win rate %d%%.',
                $total, self::money($realized), $wr
            ),
        ];
    }

    private static function openPositionsContribution(array $positions, array $trades): ?array {
        if (!$positions) return null;
        $unrealized = 0.0;
        $top = null;
        foreach ($positions as $p) {
            $u = (float)$p['unrealized_pl'];
            $unrealized += $u;
            if ($top === null || abs($u) > abs($top['u'])) $top = ['sym' => $p['symbol'], 'u' => $u];
        }
        $realized = array_sum(array_map(fn($t) => (float)$t['realized_pl'], $trades));
        if (abs($unrealized) < 100) return null;
        $bits = [];
        if ($top) {
            $share = $unrealized != 0 ? abs($top['u']) / abs($unrealized) * 100 : 0;
            $bits[] = sprintf('**%s** — %s (%.0f%% всей нереализованной)', $top['sym'], self::money($top['u']), $share);
        }
        $bits[] = sprintf('Realized %s + Unrealized %s = %s бумажного итога', self::money($realized), self::money($unrealized), self::money($realized + $unrealized));
        return ['icon' => '🔥', 'text' => implode('. ', $bits) . '.'];
    }

    private static function weekVsTrend(array $current, array $prev): ?array {
        if (!$prev) return null;
        $thisChange = (float)$current['nav_change'];
        $rank = 1;
        foreach ($prev as $p) {
            if ((float)$p['nav_change'] > $thisChange) $rank++;
        }
        $bestOf = count($prev) + 1;
        $prevSummary = array_map(fn($p) => self::money($p['nav_change']), $prev);
        $word = $rank === 1 ? 'Лучшая' : ($rank === $bestOf ? 'Худшая' : "{$rank}-я из $bestOf");
        return [
            'icon' => '📈',
            'text' => sprintf(
                '%s неделя за последние %d: %s. Предыдущие: %s.',
                $word, $bestOf, self::money($thisChange), implode(', ', $prevSummary)
            ),
        ];
    }

    // ==========================================================
    // PLAN bullets
    // ==========================================================

    private static function openPositionPlan(array $positions, array $notes): array {
        $bullets = [];
        foreach ($positions as $p) {
            $sym  = $p['symbol'];
            $note = $notes[$sym] ?? null;
            $rec  = Recommender::positionAction($p, $note);
            $emoji = ['CONTINUE' => '🟢', 'REDUCE' => '🟡', 'CUT' => '🔴'][$rec['action']] ?? '⚪';
            $pct = $rec['pct'];
            $u   = self::money($p['unrealized_pl']);
            $bullets[] = [
                'icon' => $emoji,
                'text' => sprintf(
                    '**%s** %s: %s (%s%%) → **%s**. %s',
                    $sym, $rec['action'], $u, $pct, $rec['action'],
                    $rec['action'] === 'CONTINUE' ? 'Поставь trailing stop, не отдавай прибыль обратно.' :
                    ($rec['action'] === 'REDUCE' ? 'Фиксируй ~50%, остальное держи со стопом в безубыток.' :
                    'Закрывай. ' . $rec['reason'] . '.')
                ),
            ];
        }
        return $bullets;
    }

    private static function focusFromRules(array $summaries, array $notes): ?array {
        $res = Recommender::focusAndAvoid($summaries, $notes);
        if (empty($res['focus'])) return null;
        $bits = array_map(fn($f) => sprintf('**%s** (%s)', $f['symbol'], $f['reason']), array_slice($res['focus'], 0, 6));
        return [
            'icon' => '🟢',
            'text' => 'Focus tickers: ' . implode(' · ', $bits) . '.',
        ];
    }

    private static function avoidFromRules(array $summaries, array $notes, PDO $pdo, int $importId): ?array {
        $res = Recommender::focusAndAvoid($summaries, $notes);
        if (empty($res['avoid'])) return null;
        $bits = array_map(fn($a) => sprintf('**%s** (%s)', $a['symbol'], $a['reason']), array_slice($res['avoid'], 0, 6));
        return [
            'icon' => '🔴',
            'text' => 'Avoid tickers: ' . implode(' · ', $bits) . '.',
        ];
    }

    private static function optionsForward(array $trades): ?array {
        $opts = array_filter($trades, fn($t) => $t['asset_class'] === 'Option');
        if (count($opts) === 0) return null;
        $closing = array_filter($opts, fn($t) => (float)$t['realized_pl'] !== 0.0);
        if (!$closing) return null;
        $realized = array_sum(array_map(fn($t) => (float)$t['realized_pl'], $closing));
        if ($realized > 0) {
            return ['icon' => '🎲', 'text' => sprintf('Опционы работают (%s за неделю) — продолжай с теми же параметрами, не увеличивай размер.', self::money($realized))];
        }
        return ['icon' => '🎲', 'text' => sprintf('Опционы в минусе на %s — пауза или уменьши размер контракта.', self::money($realized))];
    }

    private static function emotionalDiscipline(array $trades, array $notes): ?array {
        if (!$notes) return null;
        $fomoCount = 0;
        foreach ($notes as $n) if ($n['emotion_before'] === 'FOMO') $fomoCount++;
        if ($fomoCount >= 2) {
            return ['icon' => '🧘', 'text' => sprintf('FOMO-входов в этой неделе: %d. На следующей — если чувствуешь FOMO, ставь pre-trade checklist (rules.html) перед входом.', $fomoCount)];
        }
        return null;
    }

    // ==========================================================
    // Highlights (header pills)
    // ==========================================================
    private static function highlights(array $trades, array $positions, array $import): array {
        $h = [];
        $totalRealized = array_sum(array_map(fn($t) => (float)$t['realized_pl'], $trades));
        $best = null; $worst = null;
        foreach ($trades as $t) {
            $pl = (float)$t['realized_pl'];
            if ($pl === 0.0) continue;
            if ($best  === null || $pl > $best['pl'])  $best  = ['sym' => $t['symbol'], 'pl' => $pl];
            if ($worst === null || $pl < $worst['pl']) $worst = ['sym' => $t['symbol'], 'pl' => $pl];
        }
        $unrealized = array_sum(array_map(fn($p) => (float)$p['unrealized_pl'], $positions));

        $h[] = ['key' => 'twr',         'label' => 'TWR',            'value' => $import['twr'] . '%'];
        $h[] = ['key' => 'realized',    'label' => 'Realized',       'value' => self::money($totalRealized)];
        $h[] = ['key' => 'unrealized',  'label' => 'Unrealized',     'value' => self::money($unrealized)];
        if ($best)  $h[] = ['key' => 'best',  'label' => 'Лучшая',  'value' => $best['sym'] . ' ' . self::money($best['pl'])];
        if ($worst) $h[] = ['key' => 'worst', 'label' => 'Худшая',  'value' => $worst['sym'] . ' ' . self::money($worst['pl'])];
        return $h;
    }

    // ==========================================================
    // Format helpers
    // ==========================================================
    private static function money(float $v): string {
        $sign = $v >= 0 ? '+' : '';
        return $sign . '$' . number_format($v, 2);
    }
}
