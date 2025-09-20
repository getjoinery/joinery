<?php
/**
 * Visitor tracking endpoint
 * Named 'vs.php' to avoid ad blockers
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('data/visitor_events_class.php');

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

// Get the page being tracked
$page = $_POST['p'] ?? $_GET['p'] ?? '/';

// Validate page parameter (prevent injection)
if (!preg_match('/^\/[a-zA-Z0-9\/_\-\?&=]*$/', $page)) {
    http_response_code(400);
    exit();
}

// Record the visit using VisitorEvent class method
VisitorEvent::recordPageVisit($page);

// Return 204 No Content (optimal for sendBeacon)
http_response_code(204);
exit();