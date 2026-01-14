<?php
/**
 * Cookie consent recording endpoint
 * Records user consent choices for GDPR/CCPA compliance audit trail
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/ConsentHelper.php'));

// Security: Verify request is from our domain
$allowed_origins = [
    'https://' . $_SERVER['HTTP_HOST'],
    'http://' . $_SERVER['HTTP_HOST']
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';

if (!$origin || !in_array(rtrim($origin, '/'), $allowed_origins)) {
    // Also check referer as fallback for older browsers
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $referer_host = parse_url($referer, PHP_URL_HOST);

    if ($referer_host !== $_SERVER['HTTP_HOST']) {
        http_response_code(403);
        exit();
    }
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

// Get consent choices
$analytics = isset($_POST['a']) ? (int)$_POST['a'] === 1 : false;
$marketing = isset($_POST['m']) ? (int)$_POST['m'] === 1 : false;

// Get or generate visitor ID
$visitor_id = $_COOKIE['visitor_id'] ?? null;
if (!$visitor_id) {
    // Generate a new visitor ID if not present
    $visitor_id = bin2hex(random_bytes(16));
}

// Record consent using ConsentHelper
$consent = ConsentHelper::get_instance();
$consent->recordConsent($visitor_id, $analytics, $marketing);

// Return 204 No Content (optimal for async requests)
http_response_code(204);
exit();
