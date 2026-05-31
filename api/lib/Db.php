<?php
if (!defined('TRADING_BOOT')) { http_response_code(403); exit('Forbidden'); }
// ============================================================
// Db — PDO singleton + transaction helper
// ============================================================

class Db {
    private static ?PDO $instance = null;

    public static function pdo(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                // Ensure server-side connection treats times as UTC
                self::$instance->exec("SET time_zone = '+00:00'");
            } catch (PDOException $e) {
                throw new RuntimeException('DB connection failed: ' . $e->getMessage());
            }
        }
        return self::$instance;
    }

    /**
     * Run callable inside a transaction. Returns whatever $fn returns.
     * Rolls back on any exception and re-throws.
     */
    public static function transaction(callable $fn) {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
