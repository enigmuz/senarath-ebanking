<?php
/* ============================================================================
 * Senarath Bank - Online Banking (origin application, WEB01 / Part A)
 * LAB ENVIRONMENT · fictional institution · synthetic data only
 * ----------------------------------------------------------------------------
 * INTENTIONAL WEAKNESS V6 - Database credentials in a plaintext environment
 * variable. The app reads DB_USER / DB_PASSWORD / DB_HOST directly from the
 * process environment (loaded from the plaintext .env below). They are plaintext and
 * readable by anything running as the web user. After RCE (V5) an attacker
 * dumps the environment and recovers working MSSQL credentials to pivot to
 * SQL01 (stage 9). This is the documented build, not a defect to fix.
 * ==========================================================================*/

// --- Load the plaintext .env (V6) ------------------------------------------
// Read the committed .env from the APP ROOT (one level ABOVE the webroot, so
// Apache never serves it over HTTP) and populate the process environment. Only
// keys not already set in the environment are loaded. This changes nothing
// about V6 - the credentials are still plaintext and still recovered by reading
// the environment / this .env file after RCE (V5).
(function () {
    foreach ([__DIR__ . '/../.env', __DIR__ . '/.env'] as $envPath) {
        if (!is_readable($envPath)) continue;
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v, " \t\"'");
            if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
        }
        break; // first readable .env wins
    }
})();

// --- Database connection (read from plaintext environment - V6) ------------
define('DB_HOST', getenv('DB_HOST') ?: 'SQL01.senarath.local');
define('DB_PORT', getenv('DB_PORT') ?: '1433');
define('DB_NAME', getenv('DB_NAME') ?: 'SenarathLab');
define('DB_USER', getenv('DB_USER') ?: 'lab_app');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'DbLab123!');

// --- Application settings ---------------------------------------------------
define('APP_NAME', 'Senarath Online Banking');
define('BANK_SWIFT', 'SENBLKLX');
define('UPLOAD_DIR', __DIR__ . '/uploads');          // served + PHP-executable (V5)
define('UPLOAD_URL', '/uploads');
define('SESSION_COOKIE', 'sb_session');
define('LAB_FIXED_OTP', getenv('LAB_FIXED_OTP') ?: '114477'); // demo OTP value

// Lab OSINT reference (where the "leaked" creds are seeded for V1/V3).
// These are synthetic lab logins, NOT domain accounts.
$LAB_LEAKED = [
  ['username' => 'customer01', 'customer_no' => '10293', 'password' => 'Welcome123!'],
  ['username' => 'customer02', 'customer_no' => '10294', 'password' => 'Summer123!'],
];

date_default_timezone_set('Asia/Colombo');

// Lab: surface errors to make the environment easy to walk during the demo.
error_reporting(E_ALL);
ini_set('display_errors', getenv('APP_DEBUG') === '0' ? '0' : '1');
