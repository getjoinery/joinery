<?php
/**
 * Redirect stub — database ops is now a tab on node_detail.
 */
if (isset($_GET['node_id']) && $_GET['node_id']) {
	header('Location: /admin/server_manager/node_detail?mgn_id=' . intval($_GET['node_id']) . '&tab=database');
} else {
	header('Location: /admin/server_manager');
}
exit;
