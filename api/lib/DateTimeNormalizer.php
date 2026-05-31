<?php
if (!defined('TRADING_BOOT')) { http_response_code(403); exit('Forbidden'); }
// ============================================================
// DateTimeNormalizer
// Converts IBKR Eastern-Time strings to MySQL UTC DATETIME.
//
// IBKR variants observed:
//   "2026-05-28, 06:52:41"   (with colons + comma)
//   "2026-05-28, 065241"     (compact)
//   "2026-05-28"             (date only)
// ============================================================

class DateTimeNormalizer {

    /**
     * Convert IBKR raw datetime to a MySQL DATETIME string in UTC.
     * Returns null on failure.
     */
    public static function ibkrToUtc(?string $raw, string $sourceTz = TZ_IBKR): ?string {
        if ($raw === null) return null;
        $raw = trim(preg_replace('/\s+/', ' ', $raw));
        if ($raw === '') return null;

        // Normalize the separator pattern: "YYYY-MM-DD, HHMMSS" or "YYYY-MM-DD, HH:MM:SS"
        // Strip comma if present
        $clean = str_replace(',', '', $raw);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        // Compact time "065241" -> "06:52:41"
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{6})$/', $clean, $m)) {
            $clean = $m[1] . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2);
        }

        try {
            $dt = new DateTime($clean, new DateTimeZone($sourceTz));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}
