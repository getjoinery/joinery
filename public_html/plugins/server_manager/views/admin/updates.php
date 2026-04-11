<?php
/**
 * Redirect stub — updates is now a tab on node_detail, publish is on dashboard.
 */
if (isset($_GET['node_id']) && $_GET['node_id']) {
	header('Location: /admin/server_manager/node_detail?mgn_id=' . intval($_GET['node_id']) . '&tab=updates');
} else {
	header('Location: /admin/server_manager');
}
exit;
