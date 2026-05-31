<?php
// ============================================================
// IbkrParser — parses Interactive Brokers Activity Statement HTML
//
// Phase 1 scope: account metadata + period + NAV + cash report.
// Trades / positions / summaries are added in Phase 2.
// ============================================================

class IbkrParser {
    private DOMDocument $dom;
    private DOMXPath $xpath;
    private ?string $accountId = null;

    public function __construct(string $html) {
        // IBKR statements are usually UTF-8 but sometimes contain Win-1252 chars.
        // Normalize to UTF-8 to be safe.
        if (!preg_match('//u', $html)) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }

        // Suppress libxml HTML5 warnings
        libxml_use_internal_errors(true);

        $this->dom = new DOMDocument();
        // Prefix with explicit meta to keep DOMDocument from re-encoding
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

        // Strategy 1: look in Account Information section
        $section = $this->findSectionByPrefix('secAccountInformation');
        if ($section) {
            $cells = $this->xpath->query('.//td', $section);
            foreach ($cells as $i => $cell) {
                $text = trim($cell->textContent);
                if ($text === 'Account' && $i + 1 < $cells->length) {
                    $candidate = trim($cells->item($i + 1)->textContent);
                    if (preg_match('/^[UF]\d+$/', $candidate)) {
                        $this->accountId = $candidate;
                        return $this->accountId;
                    }
                }
            }
        }

        // Strategy 2: scan all section IDs for the suffix
        $sections = $this->xpath->query('//*[@id]');
        foreach ($sections as $node) {
            $id = $node->getAttribute('id');
            if (preg_match('/^sec[A-Z][a-zA-Z]+([UF]\d+)$/', $id, $m)) {
                $this->accountId = $m[1];
                return $this->accountId;
            }
        }

        throw new RuntimeException('Could not determine account ID from IBKR HTML');
    }

    public function getAccountName(): ?string {
        $section = $this->findSectionByPrefix('secAccountInformation');
        if (!$section) return null;

        $cells = $this->xpath->query('.//td', $section);
        foreach ($cells as $i => $cell) {
            if (trim($cell->textContent) === 'Name' && $i + 1 < $cells->length) {
                return trim($cells->item($i + 1)->textContent);
            }
        }
        return null;
    }

    /**
     * Returns ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD']
     * Falls back to parsing the filename if section is missing.
     */
    public function getPeriod(?string $sourceFilename = null): array {
        // From section
        $section = $this->findSectionByPrefix('secAccountInformation');
        if ($section) {
            $cells = $this->xpath->query('.//td', $section);
            foreach ($cells as $i => $cell) {
                if (trim($cell->textContent) === 'Period' && $i + 1 < $cells->length) {
                    $raw = trim($cells->item($i + 1)->textContent);
                    $parsed = self::parsePeriodString($raw);
                    if ($parsed) return $parsed;
                }
            }
        }

        // Fallback: filename
        if ($sourceFilename) {
            $parsed = self::parsePeriodFromFilename($sourceFilename);
            if ($parsed) return $parsed;
        }

        throw new RuntimeException('Could not determine reporting period');
    }

    public static function parsePeriodFromFilename(string $filename): ?array {
        // e.g. U5879065_20260525_20260529.htm
        if (preg_match('/_(\d{8})_(\d{8})\.html?$/i', $filename, $m)) {
            return [
                'start' => self::ymdToDate($m[1]),
                'end'   => self::ymdToDate($m[2]),
            ];
        }
        return null;
    }

    public static function parsePeriodString(string $raw): ?array {
        // Examples:
        //  "May 25, 2026 - May 29, 2026"
        //  "May 25 - May 29, 2026"
        //  "May 25, 2026"
        $raw = preg_replace('/\s+/', ' ', trim($raw));

        if (preg_match('/^(.+?)\s*-\s*(.+)$/', $raw, $m)) {
            $left  = trim($m[1]);
            $right = trim($m[2]);
            // If left has no year, borrow from right
            if (!preg_match('/\d{4}/', $left) && preg_match('/(\d{4})/', $right, $ym)) {
                $left .= ', ' . $ym[1];
            }
            $start = self::tryParseDate($left);
            $end   = self::tryParseDate($right);
            if ($start && $end) {
                return ['start' => $start, 'end' => $end];
            }
        }

        $single = self::tryParseDate($raw);
        if ($single) {
            return ['start' => $single, 'end' => $single];
        }
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
    // NAV — Net Asset Value
    // Returns array: nav_start, nav_end, nav_change, mtm_pl, twr
    // ----------------------------------------------------------
    public function getNav(): array {
        $section = $this->findSectionByPrefix('secNAV');
        $result = [
            'nav_start'  => null,
            'nav_end'    => null,
            'nav_change' => null,
            'mtm_pl'     => null,
            'twr'        => null,
        ];
        if (!$section) return $result;

        // The NAV table typically has rows: Starting Value | Ending Value | Change | Mark-to-Market | TWR
        // We scan every row, looking at label cell + last numeric cell
        $rows = $this->xpath->query('.//tr', $section);
        foreach ($rows as $row) {
            $cells = $this->xpath->query('.//td|.//th', $row);
            if ($cells->length < 2) continue;

            $label = strtolower(trim($cells->item(0)->textContent));
            // Last cell typically holds the total
            $lastVal = self::parseMoney($cells->item($cells->length - 1)->textContent);

            if ($this->matchesAny($label, ['starting value', 'beginning value', 'opening value', 'beginning of period'])) {
                $result['nav_start'] = $lastVal;
            } elseif ($this->matchesAny($label, ['ending value', 'closing value', 'end of period'])) {
                $result['nav_end'] = $lastVal;
            } elseif ($this->matchesAny($label, ['change', 'net change'])) {
                $result['nav_change'] = $lastVal;
            } elseif ($this->matchesAny($label, ['mark-to-market', 'mark to market', 'mtm'])) {
                $result['mtm_pl'] = $lastVal;
            } elseif ($this->matchesAny($label, ['time weighted return', 'twr', 'time-weighted'])) {
                // TWR may include trailing '%'
                $result['twr'] = self::parsePercent($cells->item($cells->length - 1)->textContent);
            }
        }

        // Derive change if missing
        if ($result['nav_change'] === null && $result['nav_start'] !== null && $result['nav_end'] !== null) {
            $result['nav_change'] = round($result['nav_end'] - $result['nav_start'], 2);
        }

        return $result;
    }

    // ----------------------------------------------------------
    // Cash report
    // ----------------------------------------------------------
    public function getCashReport(): array {
        $section = $this->findSectionByPrefix('secCashReport');
        $result = [
            'commissions'     => null,
            'trades_sales'    => null,
            'trades_purchase' => null,
            'dividends'       => null,
            'interest'        => null,
            'deposits'        => null,
            'withdrawals'    => null,
        ];
        if (!$section) return $result;

        $rows = $this->xpath->query('.//tr', $section);
        foreach ($rows as $row) {
            $cells = $this->xpath->query('.//td|.//th', $row);
            if ($cells->length < 2) continue;

            $label = strtolower(trim($cells->item(0)->textContent));
            $val   = self::parseMoney($cells->item($cells->length - 1)->textContent);

            if ($this->matchesAny($label, ['commissions'])) {
                $result['commissions'] = $val;
            } elseif ($this->matchesAny($label, ['sales', 'trades (sales)'])) {
                $result['trades_sales'] = $val;
            } elseif ($this->matchesAny($label, ['purchase', 'trades (purchase)', 'purchases'])) {
                $result['trades_purchase'] = $val;
            } elseif ($this->matchesAny($label, ['dividends'])) {
                $result['dividends'] = $val;
            } elseif ($this->matchesAny($label, ['interest', 'broker interest'])) {
                $result['interest'] = $val;
            } elseif ($this->matchesAny($label, ['deposits'])) {
                $result['deposits'] = $val;
            } elseif ($this->matchesAny($label, ['withdrawals'])) {
                $result['withdrawals'] = $val;
            }
        }
        return $result;
    }

    // ----------------------------------------------------------
    // Diagnostic: list all section IDs in the document
    // ----------------------------------------------------------
    public function getSectionIds(): array {
        $ids = [];
        $nodes = $this->xpath->query('//*[@id]');
        foreach ($nodes as $n) {
            $id = $n->getAttribute('id');
            if (str_starts_with($id, 'sec')) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    // ----------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------

    /**
     * Find a section element whose ID starts with the prefix
     * (handles dynamic suffix like "secNAVU5879065")
     */
    private function findSectionByPrefix(string $prefix): ?DOMElement {
        // Try exact prefix + account ID first (most specific)
        $byId = $this->dom->getElementById($prefix);
        if ($byId instanceof DOMElement) return $byId;

        // Try with account ID appended
        if ($this->accountId !== null) {
            $byId = $this->dom->getElementById($prefix . $this->accountId);
            if ($byId instanceof DOMElement) return $byId;
        }

        // Fall back to XPath starts-with
        $xp = sprintf("//*[starts-with(@id, '%s')]", addslashes($prefix));
        $nodes = $this->xpath->query($xp);
        if ($nodes && $nodes->length > 0) {
            $node = $nodes->item(0);
            return $node instanceof DOMElement ? $node : null;
        }
        return null;
    }

    private function matchesAny(string $haystack, array $needles): bool {
        foreach ($needles as $n) {
            if (strpos($haystack, $n) !== false) return true;
        }
        return false;
    }

    /**
     * "-20,850.00" -> -20850.00
     * "(20,850.00)" -> -20850.00
     * "" or "--" -> null
     */
    public static function parseMoney(?string $raw): ?float {
        if ($raw === null) return null;
        $s = trim($raw);
        if ($s === '' || $s === '--' || $s === '-') return null;

        // Strip currency symbols, commas, spaces, %
        $s = str_replace([',', '$', ' ', "\xc2\xa0"], '', $s);

        // Parens => negative
        if (preg_match('/^\((.+)\)$/', $s, $m)) {
            $s = '-' . $m[1];
        }

        if (!is_numeric($s)) return null;
        return (float)$s;
    }

    public static function parsePercent(?string $raw): ?float {
        if ($raw === null) return null;
        $s = str_replace(['%', ' '], '', trim($raw));
        return self::parseMoney($s);
    }
}
