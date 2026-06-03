<?php
// Configuration / bootstrap.
// Kept intentionally small: other APIs expect these constants and getDB().

header('Content-Type: text/html; charset=utf-8');

// DB connection settings (edit to match your environment)
// If you already have these configured elsewhere, you can remove these.
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'document_signature');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');

// Project root (filesystem absolute path, no trailing slash)
if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT', str_replace('\\', '/', realpath(__DIR__ . '/..')));

// Optional: directories
if (!defined('ORIGINAL_DIR')) define('ORIGINAL_DIR', rtrim(__DIR__ . '/../uploads/original/', '/\\') . '/');
if (!defined('FILLED_DIR')) define('FILLED_DIR', rtrim(__DIR__ . '/../uploads/filled/', '/\\') . '/');
if (!defined('SIGNATURE_DIR')) define('SIGNATURE_DIR', rtrim(__DIR__ . '/../uploads/signatures/', '/\\') . '/');

// Return PDO connection
function getDB(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

