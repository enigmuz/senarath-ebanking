<?php
/* ============================================================================
 * Senarath Bank - MSSQL connection layer (PDO / FreeTDS dblib)
 * LAB ENVIRONMENT · connects WEB01 origin app -> SQL01 MSSQL over TCP 1433
 * ----------------------------------------------------------------------------
 * The DMZ web origin talks directly to the internal MSSQL host on 1433
 * (per the lab's "Required lab connectivity" table). Credentials come from
 * the plaintext environment (see config.php / V6).
 * ==========================================================================*/
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    // FreeTDS / dblib DSN. Works against MSSQL 2019/2022.
    $dsn = sprintf('dblib:host=%s:%s;dbname=%s;charset=UTF-8',
        DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    } catch (Throwable $e) {
        // Lab-friendly: show that the DB tier is unreachable rather than a blank 500.
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Service unavailable</title>'
           . '<div style="font-family:system-ui;max-width:640px;margin:80px auto;padding:0 24px;color:#1b2635">'
           . '<h2 style="color:#0b1e34">We&rsquo;re briefly unavailable</h2>'
           . '<p>Online Banking cannot reach the core banking service right now. '
           . 'Please try again in a few minutes.</p>'
           . '<p style="color:#8a94a4;font-size:.85rem">LAB: MSSQL host <code>'
           . htmlspecialchars(DB_HOST . ':' . DB_PORT) . '</code> (database <code>'
           . htmlspecialchars(DB_NAME) . '</code>) is not responding. '
           . 'Check SQL01, TCP/IP + port 1433, and the lab_app login.</p>'
           . '<p style="color:#8a94a4;font-size:.8rem">' . htmlspecialchars($e->getMessage()) . '</p>'
           . '</div>';
        exit;
    }
    return $pdo;
}

/* Small helpers */
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function one(string $sql, array $params = []): ?array {
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function all(string $sql, array $params = []): array {
    return q($sql, $params)->fetchAll();
}
function money(float $n): string {
    return 'LKR ' . number_format($n, 2);
}
