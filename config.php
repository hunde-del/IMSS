<?php
// Database Configuration - MOVE THIS TO ENVIRONMENT VARIABLES IN PRODUCTION
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'ims_db');

// Security Settings
define('APP_NAME', 'Inventory Management System');
define('SESSION_TIMEOUT', 3600); // 1 hour
define('ENVIRONMENT', getenv('APP_ENV') ?: 'development');

// Error Handling
error_reporting(E_ALL);
ini_set('display_errors', ENVIRONMENT === 'development' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/logs/error.log');

// Create logs directory if it doesn't exist
if (!is_dir(dirname(__FILE__) . '/logs')) {
    mkdir(dirname(__FILE__) . '/logs', 0755, true);
}

// Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Session Configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', ENVIRONMENT === 'production' ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// CSRF Token initialization
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function for safe JSON responses
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Helper function for safe redirects
function redirect($url, $permanent = false) {
    header('Location: ' . $url, true, $permanent ? 301 : 302);
    exit;
}

// Validation helper functions
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePositiveInt($value) {
    $intValue = (int) $value;
    return $intValue > 0 ? $intValue : false;
}

function validateFloat($value) {
    $floatValue = (float) $value;
    return is_numeric($value) && $floatValue >= 0 ? $floatValue : false;
}

function sanitizeString($input) {
    return trim(htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8'));
}

?>
