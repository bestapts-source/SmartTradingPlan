<?php
if (!defined('TRADING_BOOT')) { http_response_code(403); exit('Forbidden'); }
// ============================================================
// Recommender
// Rule-based suggestions:
//   - CONTINUE / REDUCE / CUT for an open position
//   - Focus / Avoid ticker lists for next week
// All thresholds are tunable here.
// ============================================================

class Recommender {

    // ---- Open-position action -------------------------------------
    // pct = unrealized_pl / (avg_cost * abs(qty))
    //   pct > +5%  AND no regret_flag  → CONTINUE
    //   pct in [-3%, +5%]              → REDUCE
    //   pct < -3%  OR regret_flag = 1  → CUT
    public static function positionAction(array $position, ?array $note = null): array {
        $qty       = (float)($position['quantity']     ?? 0);
        $avgCost   = (float)($position['avg_cost']     ?? 0);
        $unrealized= (float)($position['unrealized_pl']?? 0);
        $regret    = $note ? (int)($note['regret_flag'] ?? 0) : 0;

        $denom = abs($qty) * $avgCost;
        $pct   = $denom > 0 ? $unrealized / $denom : 0;
        $pctStr = round($pct * 100, 2);

        if ($pct < -0.03 || $regret === 1) {
            return ['action' => 'CUT',      'pct' => $pctStr, 'reason' => self::reasonForCut($pct, $regret)];
        }
        if ($pct > 0.05) {
            return ['action' => 'CONTINUE', 'pct' => $pctStr, 'reason' => 'unrealized > +5%'];
        }
        return ['action' => 'REDUCE',       'pct' => $pctStr, 'reason' => 'unrealized between -3% and +5%'];
    }

    private static function reasonForCut(float $pct, int $regret): string {
        if ($regret === 1) return 'regret flag set in journal';
        return 'unrealized < -3%';
    }

    // ---- Focus / Avoid tickers for next week ----------------------
    //
    // $symbolSummaries: rows from ibkr_symbol_summary  (realized_pl, ytd_pl per symbol)
    // $notes:           rows from trade_notes keyed by symbol
    //
    // focus  = realized_pl > 0 за неделю AND ytd_pl > 0 AND not in avoid
    // avoid  = realized_pl < -500 за неделю
    //          OR symbol with regret_flag = 1 in notes
    //          OR ytd_pl < -3000
    public static function focusAndAvoid(array $symbolSummaries, array $notesBySymbol): array {
        $avoid = [];
        $focus = [];

        foreach ($symbolSummaries as $row) {
            $sym       = $row['symbol'] ?? '';
            if ($sym === '' || self::looksLikeAggregate($sym)) continue;

            $realized  = (float)($row['realized_pl'] ?? 0);
            $ytd       = (float)($row['ytd_pl']      ?? 0);
            $note      = $notesBySymbol[$sym] ?? null;
            $regret    = $note ? (int)($note['regret_flag'] ?? 0) : 0;

            $isAvoid = ($realized < -500) || ($regret === 1) || ($ytd < -3000);
            if ($isAvoid) {
                $avoid[] = [
                    'symbol' => $sym,
                    'reason' => self::avoidReason($realized, $ytd, $regret),
                ];
                continue;
            }

            if ($realized > 0 && $ytd > 0) {
                $focus[] = [
                    'symbol' => $sym,
                    'reason' => sprintf('week realized +%.0f, YTD +%.0f', $realized, $ytd),
                ];
            }
        }

        // Sort focus by week realized desc, avoid by week realized asc (worst first)
        usort($focus, fn($a, $b) => 0); // stable; we don't re-rank here
        usort($avoid, fn($a, $b) => 0);

        return ['focus' => $focus, 'avoid' => $avoid];
    }

    private static function avoidReason(float $realized, float $ytd, int $regret): string {
        $bits = [];
        if ($regret === 1)     $bits[] = 'regret flag';
        if ($realized < -500)  $bits[] = sprintf('week realized %.0f', $realized);
        if ($ytd < -3000)      $bits[] = sprintf('YTD %.0f', $ytd);
        return implode(', ', $bits) ?: 'flagged';
    }

    /**
     * Skip aggregate / total rows from the summary table.
     */
    private static function looksLikeAggregate(string $symbol): bool {
        $lower = strtolower($symbol);
        return strpos($lower, 'total') !== false
            || strpos($lower, '(all assets)') !== false
            || strpos($lower, 'subtotal') !== false;
    }
}
