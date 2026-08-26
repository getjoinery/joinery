<?php
/**
 * Management Node — connect this machine's agent to a management node.
 *
 * The whole exchange shares no secret: this page records the management
 * node's URL, the root agent generates its own keypair and asks to join, and
 * a person approves the request on the management node after comparing the
 * key fingerprint both screens show (Phase 1.5, decision A6).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_management_node_logic(array_merge($_GET, $_POST)));

$session   = $page_vars['session'];
$request   = $page_vars['request'];
$state     = $page_vars['state'];
$error     = $page_vars['error'];
$requested = $page_vars['requested'];
$cancelled = $page_vars['cancelled'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id'     => 'system-management-node',
	'breadcrumbs' => array(
		'System'          => '',
		'Management Node' => '',
	),
	'session' => $session,
));

if ($error) {
	echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($error) . '</div>';
}
if ($cancelled) {
	echo '<div class="alert alert-info" role="alert">Request cancelled. Nothing was shared, so there is nothing to undo.</div>';
}

$status      = is_array($state) ? (string)($state['status'] ?? '') : '';
$fingerprint = is_array($state) ? (string)($state['fingerprint'] ?? '') : '';
$fpr_display = $fingerprint !== '' ? trim(chunk_split($fingerprint, 4, ' ')) : '';

echo '<p class="text-muted" style="max-width:46rem;">A management node runs backups, upgrades, and health checks for this site from one dashboard. '
   . 'Connecting shares no password or key: this machine\'s agent introduces itself with a key it generates and keeps, '
   . 'and an administrator over there approves the introduction after checking that the key fingerprints on both screens match.</p>';

if ($status === 'connected') {
	echo '<div class="alert alert-success" role="alert">';
	echo '<strong>Connected.</strong> This site is managed by <code>' . htmlspecialchars((string)($state['url'] ?? '')) . '</code>';
	if (!empty($state['node_slug']) || !empty($state['node_id'])) {
		echo ' as <strong>' . htmlspecialchars((string)($state['node_slug'] ?? '')) . '</strong>'
		   . (!empty($state['node_id']) ? ' (node #' . (int)$state['node_id'] . ')' : '');
	}
	echo '.';
	if ($fpr_display !== '') {
		echo '<div class="mt-2">This machine\'s key fingerprint: <code>' . htmlspecialchars($fpr_display) . '</code></div>';
	}
	echo '<div class="mt-2 small text-muted">To end the connection, disconnect this node on the management node\'s side — '
	   . 'it forgets this machine\'s key and the agent goes back to serving only local work.</div>';
	echo '</div>';

} elseif ($request !== null) {
	// A request is on the table. What we show depends on how far the agent got.
	if ($status === 'pending' && $fpr_display !== '') {
		echo '<div class="alert alert-warning" role="alert">';
		echo '<strong>Waiting for approval.</strong> The request was sent to <code>'
		   . htmlspecialchars((string)($state['url'] ?? $request['url'] ?? '')) . '</code>.';
		echo '<div class="my-3">This machine\'s key fingerprint:<br>'
		   . '<code style="font-size:1.5em;">' . htmlspecialchars($fpr_display) . '</code></div>';
		echo '<div>On the management node, an administrator will see this request on the node\'s page. '
		   . 'They should approve it <strong>only if the fingerprint there matches the one above exactly</strong> — '
		   . 'that comparison is the entire security of the introduction.</div>';
		echo '</div>';
	} elseif ($status === 'rejected') {
		echo '<div class="alert alert-danger" role="alert">'
		   . '<strong>The request was rejected</strong> on the management node. If that was a mistake, cancel below and ask again — '
		   . 'the agent introduces itself with a fresh key next time.</div>';
	} elseif ($status === 'error') {
		echo '<div class="alert alert-danger" role="alert">'
		   . '<strong>The agent could not reach the management node.</strong> '
		   . htmlspecialchars((string)($state['error'] ?? '')) . '<br>'
		   . '<span class="small">It keeps retrying on its own; fix the URL or the network and this page will move on by itself.</span></div>';
	} else {
		echo '<div class="alert alert-info" role="alert">'
		   . '<strong>Request recorded.</strong> Waiting for this machine\'s agent to pick it up — it checks every few seconds. '
		   . 'If this message does not change shortly, the agent is not running on this machine.</div>';
	}

	echo '<form method="POST" action="/admin/admin_management_node" style="margin-top:8px;">'
	   . '<input type="hidden" name="action" value="cancel">'
	   . '<button type="submit" class="btn btn-secondary">Cancel this request</button>'
	   . '</form>';

} else {
	if ($status === 'rejected') {
		echo '<div class="alert alert-warning" role="alert">The previous request was rejected on the management node. You can ask again below.</div>';
	}
	$formwriter = $page->getFormWriter('management_node_form');
	$formwriter->begin_form();
	$formwriter->hiddeninput('action', '', array('value' => 'connect'));
	$formwriter->textinput('management_node_url', 'Management node URL', array(
		'placeholder' => 'https://manage.example.com',
		'required'    => true,
		'helptext'    => 'Just the address. No password, key, or code goes in here — the approval happens on the management node\'s side.',
	));
	$formwriter->submitbutton('btn_connect', 'Connect', array('class' => 'btn btn-primary'));
	$formwriter->end_form();
}

$page->admin_footer();
