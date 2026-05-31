<?php
if (!defined('TRADING_BOOT')) { http_response_code(403); exit('Forbidden'); }
// ============================================================
// IbkrParser — parses Interactive Brokers Activity Statement HTML
//
// Real-world IBKR HTML structure (verified May 2026):
//   Section headers:   <a id="secXxxYy[_]U<accountId>Heading">
//   Table bodies:      <div id="tblXxxYy[_]U<accountId>Body">
//
//   Some sections use `_U` (underscore), others just `U`:
//     tblNAV_U5879065Body
//     tblTransactions_U5879065Body
//     tblContractInfoU5879065Body            (NO underscore)
//     tblFIFOPerfSumByUnderlyingU5879065Body (NO underscore)
//
// Phase 1 scope: account metadata + period + NAV + cash report.
// Phase 2 adds trades / positions / symbol summary.
// ============================================================

class IbkrParser {
    private DOMDocument $dom;
    private DOMXPath $xpath;
    private ?string $accountId = null;
    private ?string $rawHtml   = null;

    public function __construct(string $html) {
        if (!preg_match('//u', $html)) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        $this->rawHtml = $html;

        libxml_use_internal_errors(true);
        $this->dom = new DOMDocument();
        $this->dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $this->xpath = new DOMXPath($this->dom);
    }

    // ----------------------------------------------------------
    // Account metadata
    // ----------------------------------------------------------

    public function getAccountId(): string {
        if ($this->accountId !== null) return $this->accountId;

        // Strategy 1: any tbl/sec ID — extract trailing account part
        // matches both "tblNAV_U5879065Body" and "tblContractInfoU5879065Body"
        $nodes = $this->xpath->query('//*[@id]');
        foreach ($nodes as $node) {
            $id = $node->getAttribute('id');
            if (preg_match('/(?:^sec|^tbl).+?_?([UF]\d{4,})(?:Heading|Body)$/', $id, $m)) {
                $this->accountId = $m[1];
                return $this->accountId;
            }
        }

        // Strategy 2: title "U5879065 Activity Statement ..."
        $title = $this->xpath->query('//title')->item(0);
        if ($title && preg_match('/\b([UF]\d{4,})\b/', $title->textContent, $m)) {
            $this->accountId = $m[1];
            return $this->accountId;
        }

        // Strategy 3: Account Information body
        $body = $this->findBody('AccountInformation');
        if ($body) {
            $val = $this->labelLookup($body, 'Account');
            if ($val && preg_match('/^[UF]\d+$/', $val)) {
                $this->accountId = $val;
                return $this->accountId;
            }
        }

        throw new RuntimeException('Could not determine account ID from IBKR HTML');
    }

    public function getAccountName(): ?string {
        $body = $this->findBody('AccountInformation');
        if (!$body) return null;
        return $this->labelLookup($body, 'Name');
    }

    /**
     * Returns ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD']
     */
    public function getPeriod(?string $sourceFilename = null): array {
        // Strategy 1: <title>U5879065 Activity Statement May 25, 2026 - May 29, 2026</title>
        $title = $this->xpath->query('//title')->item(0);
        if ($title) {
            $text = trim($title->textContent);
            if (preg_match('/Activity Statement\s+(.+?)\s*$/i', $text, $m)) {
                $parsed = self::parsePeriodString($m[1]);
                if ($parsed) return $parsed;
            }
        }

        // Strategy 2: Account Information's Period field (if present)
        $body = $this->findBody('AccountInformation');
        if ($body) {
            $val = $this->labelLookup($body, 'Period');
            if ($val) {
                $parsed = self::parsePeriodString($val);
                if ($parsed) return $parsed;
            }
        }

        // Strategy 3: filename — U5879065_20260525_20260529.htm or U5879065_20260501.htm
        if ($sourceFilename) {
            $parsed = self::parsePeriodFromFilename($sourceFilename);
            if ($parsed) return $parsed;
        }

        throw new RuntimeException('Could not determine reporting period');
    }

    public static function parsePeriodFromFilename(string $filename): ?array {
        if (preg_match('/_(\d{8})_(\d{8})\.html?$/i', $filename, $m)) {
            return ['start' => self::ymdToDate($m[1]), 'end' => self::ymdToDate($m[2])];
        }
        // Single date variant: U5879065_20260501.htm — treat as single day
        if (preg_match('/_(\d{8})\.html?$/i', $filename, $m)) {
            $d = self::ymdToDate($m[1]);
            return ['start' => $d, 'end' => $d];
        }
        return null;
    }

    public static function parsePeriodString(string $raw): ?array {
        $raw = preg_replace('/\s+/', ' ', trim($raw));

        if (preg_match('/^(.+?)\s*[-–—]\s*(.+)$/u', $raw, $m)) {
            $left  = trim($m[1]);
            $right = trim($m[2]);
            if (!preg_match('/\d{4}/', $left) && preg_match('/(\d{4})/', $right, $ym)) {
                $left .= ', ' . $ym[1];
            }
            $start = self::tryParseDate($left);
            $end   = self::tryParseDate($right);
            if ($start && $end) return ['start' => $start, 'end' => $end];
        }

        $single = self::tryParseDate($raw);
        if ($single) return ['start' => $single, 'end' => $single];
        return null;
    }

    private static function tryParseDate(string $s): ?string {
        $ts = strtotime($s);
        if ($ts === false || $ts <= 0) return null;
        return date('Y-m-d', $ts);
    }

    private static function ymdToDate(string $ymd): string {
        return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
    }

    // ----------------------------------------------------------
    // NAV
    //
    // The NAV table has columns:
    //   [label][period-start Total][end Long][end Short][end Total][Change]
    // We look for the row whose label = "Total" AND class includes "subtotal".
    //
    // Then a separate TWR row has class="twr" — last cell is the percent.
    // ----------------------------------------------------------
    public function getNav(): array {
        $body = $this->findBody('NAV');
        $result = [
            'nav_start'  => null,
            'nav_end'    => null,
            'nav_change' => null,
            'mtm_pl'     => null,
            'twr'        => null,
        ];
        if (!$body) return $result;

        $rows = $this->xpath->query('.//tr', $body);
        foreach ($rows as $row) {
            $cells = $this->xpath->query('.//th|.//td', $row);
            if ($cells->length === 0) continue;

            $rowClass = (string)$row->getAttribute('class');
            $label    = trim(preg_replace('/\s+/', ' ', $cells->item(0)->textContent));

            // The grand-total row: "Total" with class="subtotal"
            if (strpos($rowClass, 'subtotal') !== false && stripos($label, 'Total') !== false) {
                // Expect 6 cells: label, col1 (period-start Total), Long, Short, end Total, Change
                if ($cells->length >= 6) {
                    $result['nav_start']  = self::parseMoney($cells->item(1)->textContent);
                    $result['nav_end']    = self::parseMoney($cells->item(4)->textContent);
                    $result['nav_change'] = self::parseMoney($cells->item(5)->textContent);
                }
            }

            // TWR row
            if (stripos($label, 'Time Weighted') !== false || stripos($label, 'TWR') !== false
                || strpos($rowClass, 'twr') !== false) {
                $last = $cells->item($cells->length - 1);
                $result['twr'] = self::parsePercent($last->textContent);
            }

            // Mark-to-Market row (sometimes present in NAV table)
            if (stripos($label, 'Mark-to-Market') !== false || stripos($label, 'Mark to Market') !== false) {
                $last = $cells->item($cells->length - 1);
                $result['mtm_pl'] = self::parseMoney($last->textContent);
            }
        }

        if ($result['nav_change'] === null && $result['nav_start'] !== null && $result['nav_end'] !== null) {
            $result['nav_change'] = round($result['nav_end'] - $result['nav_start'], 2);
        }

        return $result;
    }

    // ----------------------------------------------------------
    // Cash Report
    //
    // Each row: [Label] [Total] [Securities] [Futures] [Month to Date] [Year to Date]
    // We take the "Total" column (index 1).
    // ----------------------------------------------------------
    public function getCashReport(): array {
        $body = $this->findBody('CashReport');
        $result = [
            'commissions'     => null,
            'trades_sales'    => null,
            'trades_purchase' => null,
            'dividends'       => null,
            'interest'        => null,
            'deposits'        => null,
            'withdrawals'    => null,
        ];
        if (!$body) return $result;

        $accountTransfers = null;

        $rows = $this->xpath->query('.//tr', $body);
        foreach ($rows as $row) {
            $cells = $this->xpath->query('.//td|.//th', $row);
            if ($cells->length < 2) continue;

            $label = strtolower(trim(preg_replace('/\s+/', ' ', $cells->item(0)->textContent)));
            $total = self::parseMoney($cells->item(1)->textContent);

            if ($total === null) continue;

            // Match common label phrases
            if ($this->labelHas($label, ['commission'])) {
                $result['commissions'] = $total;
            } elseif ($this->labelHas($label, ['sales', 'trades (sales)'])) {
                $result['trades_sales'] = $total;
            } elseif ($this->labelHas($label, ['purchase', 'trades (purchase)'])) {
                $result['trades_purchase'] = $total;
            } elseif ($this->labelHas($label, ['dividend'])) {
                $result['dividends'] = $total;
            } elseif ($this->labelHas($label, ['interest', 'broker interest'])) {
                $result['interest'] = $total;
            } elseif ($this->labelHas($label, ['deposit'])) {
                $result['deposits'] = $total;
            } elseif ($this->labelHas($label, ['withdrawal'])) {
                $result['withdrawals'] = $total;
            } elseif ($this->labelHas($label, ['account transfer'])) {
                $accountTransfers = $total;
            }
        }

        // Account transfers → deposits (if positive) or withdrawals (if negative).
        if ($accountTransfers !== null && abs($accountTransfers) > 0.001) {
            if ($accountTransfers > 0) {
                $result['deposits']    = ($result['deposits']    ?? 0) + $accountTransfers;
            } else {
                $result['withdrawals'] = ($result['withdrawals'] ?? 0) + $accountTransfers;
            }
        }

        return $result;
    }

    // ----------------------------------------------------------
    // Diagnostic
    // ----------------------------------------------------------
    public function getSectionIds(): array {
        $ids = [];
        $nodes = $this->xpath->query('//*[@id]');
        foreach ($nodes as $n) {
            $id = $n->getAttribute('id');
            if (preg_match('/^(sec|tbl)/', $id)) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    // ----------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------

    /**
     * Find the body div for a logical section name like "NAV", "Transactions",
     * "AccountInformation", "CashReport", "OpenPositions", "ContractInfo",
     * "MtmPerfSumByUnderlying", "FIFOPerfSumByUnderlying", "MTDYTDPerfSum".
     *
     * Tries: tbl<Name>_U<accountId>Body, then tbl<Name>U<accountId>Body
     * (some sections use underscore, others don't).
     */
    private function findBody(string $name): ?DOMElement {
        $acc = $this->getAccountId();
        foreach ([
            'tbl' . $name . '_' . $acc . 'Body',
            'tbl' . $name . $acc . 'Body',
        ] as $candidate) {
            $el = $this->dom->getElementById($candidate);
            if ($el instanceof DOMElement) return $el;
        }

        // Fallback: XPath search by id prefix
        $xp = sprintf("//*[starts-with(@id, 'tbl%s')]", addslashes($name));
        $nodes = $this->xpath->query($xp);
        if ($nodes && $nodes->length > 0) {
            foreach ($nodes as $n) {
                $id = $n->getAttribute('id');
                if (substr($id, -4) === 'Body' && $n instanceof DOMElement) {
                    return $n;
                }
            }
        }
        return null;
    }

    /**
     * In a body div with rows like <tr><td>Label</td><td>Value</td></tr>,
     * return the value cell text for the given label (case-insensitive).
     */
    private function labelLookup(DOMElement $body, string $label): ?string {
        $rows = $this->xpath->query('.//tr', $body);
        foreach ($rows as $row) {
            $cells = $this->xpath->query('.//td|.//th', $row);
            if ($cells->length < 2) continue;
            $rowLabel = trim(preg_replace('/\s+/', ' ', $cells->item(0)->textContent));
            if (strcasecmp($rowLabel, $label) === 0) {
                return trim(preg_replace('/\s+/', ' ', $cells->item(1)->textContent));
            }
        }
        return null;
    }

    private function labelHas(string $haystack, array $needles): bool {
        foreach ($needles as $n) {
            if (strpos($haystack, $n) !== false) return true;
        }
        return false;
    }

    /**
     * "-20,850.00" → -20850.00
     * "(20,850.00)" → -20850.00
     * "&nbsp;" or "" or "--" → null
     */
    public static function parseMoney(?string $raw): ?float {
        if ($raw === null) return null;
        $s = trim(preg_replace('/\s+/', ' ', $raw));
        $s = str_replace(["\xc2\xa0", "\xa0"], '', $s);
        $s = trim($s);
        if ($s === '' || $s === '--' || $s === '-' || $s === '&nbsp;') return null;

        $s = str_replace([',', '$', ' '], '', $s);
        if (preg_match('/^\((.+)\)$/', $s, $m)) $s = '-' . $m[1];

        if (!is_numeric($s)) return null;
        return (float)$s;
    }

    public static function parsePercent(?string $raw): ?float {
        if ($raw === null) return null;
        $s = str_replace(['%', ' '], '', trim($raw));
        return self::parseMoney($s);
    }
}
