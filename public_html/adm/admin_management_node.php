<?php
/**
 * Management Node — connect this machine's agent to a management node.
 *
 * The whole exchange shares no secret: this page records the management
 * node's URL, the root agent generates its own keypair and asks to join, and
 * a person approves the request on the management node after comparing the
 * key fingerprint both screens show (Phase 1.5, decision A6).
 *
 * @version 1.4 - the log-access switch names everything it grants: the site logs, a service's journal and
 *                the host configuration files, and says member data and keys are never sent
 *                (specs/agent_recipes_and_vocabulary.md, rule 8)
 * @version 1.3 - The log-access switch (specs/agent_log_access.md), between the agent switch and the
 *                connection: what a connected management node may read of this site's logs, in
 *                the owner's words, with the switch beside the explanation
 * @version 1.2 - The agent's own on/off switch, above everything else: none of the rest of this
 *                page means anything on a machine that is not running one
 * @version 1.1 - Disconnect: the node ends the connection from its own side (either side can);
 *                the agent says one signed goodbye, deletes its key, and serves only local work
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_management_node_logic(array_merge($_GET, $_POST)));

$session         = $page_vars['session'];
$request         = $page_vars['request'];
$state           = $page_vars['state'];
$leave_request   = $page_vars['leave_request'];
$agent_enabled   = $page_vars['agent_enabled'];
$agent_installed = $page_vars['agent_installed'];
$agent_switched  = $page_vars['agent_switched'];
$log_access      = $page_vars['log_access'];
$log_access_switched = $page_vars['log_access_switched'];
$installer_hint  = $page_vars['installer_hint'];
$error           = $page_vars['error'];
$requested       = $page_vars['requested'];
$cancelled       = $page_vars['cancelled'];

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

echo '<p class="text-muted" style="max-width:46rem;">The Joinery agent is a service on this machine that carries out backups, upgrades, '
   . 'and health checks here, with the access those jobs need. It works on its own; connecting it to a management node lets an '
   . 'administrator run those same jobs for this site from one dashboard alongside their other sites. '
   . 'Connecting shares no password or key: the agent introduces itself with a key it generates and keeps, '
   . 'and an administrator over there approves the introduction after checking that the key fingerprints on both screens match.</p>';

if ($agent_switched === 'on') {
	echo '<div class="alert alert-info" role="alert">The agent is switched on for this machine.</div>';
} elseif ($agent_switched === 'off') {
	echo '<div class="alert alert-info" role="alert">The agent is switched off. It stops at the next container start, upgrade, or installer run; the agent itself stays installed.</div>';
}

// The agent comes first: with it off, everything below describes work this
// machine has no way to carry out.
if (!$agent_enabled) {
	echo '<div class="alert alert-secondary" role="alert">'
	   . '<strong>This machine is not running the agent.</strong> Nothing on this page takes effect until it is — '
	   . 'a management node has nothing here to give work to, and local backups and upgrades are whatever you run by hand.'
	   . '</div>';
	echo '<form method="POST" action="/admin/admin_management_node">'
	   . '<input type="hidden" name="action" value="enable_agent">'
	   . '<button type="submit" class="btn btn-primary">Turn on the agent</button>'
	   . '</form>';
	echo '<p class="text-muted" style="max-width:46rem;margin-top:12px;">'
	   . ($agent_installed
		? 'The agent is already installed here; turning it on starts it at the next container start, upgrade, or installer run.'
		: 'Turning it on installs and starts the agent at the next container start, upgrade, or installer run.')
	   . ' It connects this machine to nothing by itself — that is a separate step, here, once the agent is running.</p>';
	$page->admin_footer();
	return;
}

if (!$agent_installed) {
	echo '<div class="alert alert-warning" role="alert">'
	   . '<strong>Switched on, not installed yet.</strong> The agent is installed by a script that runs as root, which a web page cannot do. '
	   . 'It lands by itself at the next container start or code upgrade. To do it now, run this on the machine:'
	   . '<div class="mt-2"><code>' . htmlspecialchars($installer_hint) . '</code></div>'
	   . '</div>';
}

// What a connected management node may read of this site's logs. Between the
// agent switch and the connection, so it is read in the same breath as the act
// that gives a management node any sight of this machine. The agent enforces
// it: a log request arriving while this is off is refused on this machine,
// whatever the management node says.
if ($log_access_switched === 'on') {
	echo '<div class="alert alert-info" role="alert">Log access is on. A connected management node can read this site\'s redacted log excerpts.</div>';
} elseif ($log_access_switched === 'off') {
	echo '<div class="alert alert-info" role="alert">Log access is off. The agent refuses every log request from a management node from now on.</div>';
}
echo '<div class="card mb-3" style="max-width:46rem;"><div class="card-body">';
echo '<h5 class="card-title mb-2">Let the management node read this site\'s logs and host configuration <span class="badge ' . ($log_access ? 'bg-success' : 'bg-secondary') . '">' . ($log_access ? 'On' : 'Off') . '</span></h5>';
echo '<p class="text-muted mb-2">When on, a management node this machine is connected to can ask for: the last lines of the site\'s '
   . 'error and task logs; the newest rows of the login, request, event, form-error and webhook logs; the last lines of a '
   . 'service\'s journal (Apache, PostgreSQL, cron, fail2ban and the agent among them); and the first lines of the host\'s '
   . 'configuration files (fail2ban, Apache, PHP, logging, scheduled tasks, updates, Docker, mail settings). '
   . 'Member data and keys are never sent: member addresses, submitted forms and raw payloads are left out entirely, files that '
   . 'hold a key or a password are never read, and passwords, tokens, addresses and the personal half of email addresses are '
   . 'masked on this machine before anything is sent. '
   . 'When off, every such request is refused here, and the management node is told why. '
   . 'On by default. It has no effect until this machine is connected to a management node.</p>';
echo '<form method="POST" action="/admin/admin_management_node" class="d-inline">'
   . '<input type="hidden" name="action" value="' . ($log_access ? 'log_access_off' : 'log_access_on') . '">'
   . '<button type="submit" class="btn btn-sm ' . ($log_access ? 'btn-outline-secondary' : 'btn-primary') . '">'
   . ($log_access ? 'Turn off log access' : 'Turn on log access') . '</button>'
   . '</form>';
echo '</div></div>';

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
	echo '</div>';

	if ($leave_request !== null) {
		echo '<div class="alert alert-warning" role="alert">'
		   . '<strong>Disconnecting.</strong> Waiting for this machine\'s agent to act on it — it checks every few seconds, '
		   . 'finishes any job it is running, tells the management node to forget this machine\'s key, and deletes its own copy. '
		   . 'If this message does not change shortly, the agent is not running on this machine.</div>';
		echo '<form method="POST" action="/admin/admin_management_node" style="margin-top:8px;">'
		   . '<input type="hidden" name="action" value="cancel_disconnect">'
		   . '<button type="submit" class="btn btn-secondary">Cancel the disconnect</button>'
		   . '</form>';
	} else {
		echo '<p class="text-muted" style="max-width:46rem;">Either side can end the connection: the management node can '
		   . 'disconnect this machine from its dashboard, and this machine can leave on its own below — no cooperation from '
		   . 'the other side required. The agent tells the management node to forget this machine\'s key, deletes its own '
		   . 'copy, and goes back to serving only local work.</p>';
		echo '<form method="POST" action="/admin/admin_management_node" '
		   . 'onsubmit="return confirm(\'Disconnect from this management node? It stops managing this site, and reconnecting starts a fresh introduction.\');">'
		   . '<input type="hidden" name="action" value="disconnect">'
		   . '<button type="submit" class="btn btn-outline-danger">Disconnect</button>'
		   . '</form>';
	}

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

// Turning the agent off is a bigger act than disconnecting — it ends local
// backups and upgrades too — so it sits at the foot of the page rather than
// beside the connection controls, and says what a connected machine loses.
$off_warning = $status === 'connected'
	? 'Turn off the agent? This machine will stop carrying out its own backups and upgrades, and the management node will see it stop responding — disconnect first if you want a clean ending there.'
	: 'Turn off the agent? This machine will stop carrying out its own backups and upgrades.';

echo '<hr style="margin-top:32px;">';
echo '<p class="text-muted" style="max-width:46rem;">The agent is running on this machine. Turning it off stops it and leaves it stopped; '
   . 'the agent and anything it has been told stay in place, so turning it back on resumes where this left off.</p>';
echo '<form method="POST" action="/admin/admin_management_node" '
   . 'onsubmit="return confirm(\'' . htmlspecialchars($off_warning, ENT_QUOTES) . '\');">'
   . '<input type="hidden" name="action" value="disable_agent">'
   . '<button type="submit" class="btn btn-outline-secondary">Turn off the agent</button>'
   . '</form>';

$page->admin_footer();
