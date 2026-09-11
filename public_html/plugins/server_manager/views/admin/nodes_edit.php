<?php
/**
 * Redirect stub — nodes_edit is now split into node_detail (edit) and node_add (add).
 */
if (isset($_GET['mgn_id']) && $_GET['mgn_id']) {
	$url = '/admin/server_manager/node_detail?mgn_id=' . intval($_GET['mgn_id']);
	if (isset($_GET['action'])) {
		$url .= '&action=' . urlencode($_GET['action']);
	}
	header('Location: ' . $url);
} else {
	header('Location: /admin/server_manager/node_add');
}
exit;
