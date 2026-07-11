<?php
/**
 * admin_debug_email_log_preview — raw HTML preview of a captured debug email
 * body, for the iframe preview on the debug email log page. Document load:
 * echoes the stored body with no admin chrome. Query param: del_debug_email_log_id.
 */

require_once(PathHelper::getIncludePath('data/debug_email_logs_class.php'));

header('Content-type: text/html');

$session = SessionControl::get_instance();
$session->check_permission(8);

$debug_email_log = new DebugEmailLog($_GET['del_debug_email_log_id'], TRUE);

echo $debug_email_log->get('del_body');
?>
