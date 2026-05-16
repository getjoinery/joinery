<?php
/**
 * AJAX endpoint — runs every active plugin's provisioning checks and returns
 * the results as JSON. The admin Plugins page fires this after rendering so a
 * slow check never blocks the page.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/PluginProvisioning.php'));

header('Content-Type: application/json');

$session = SessionControl::get_instance();
$session->check_permission(5);

try {
    echo json_encode([
        'success' => true,
        'plugins' => PluginProvisioning::runChecks(),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
exit;
