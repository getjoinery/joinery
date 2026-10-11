<?php
/**
 * Management Node — connect this machine's agent to a management node.
 *
 * The whole exchange shares no secret: this page records the management
 * node's URL, the root agent generates its own keypair and asks to join, and
 * a person approves the request on the management node after comparing the
 * key fingerprint both screens show (Phase 1.5, decision A6).
 *
 * @version 1.6 - Page heading; one Management card (status, connection, fingerprint, connect/disconnect), a list of what the
 *                agent does, a Log access section, and a guarded foot: turning the agent off needs the words
 *                "disable agent" typed into a confirmation dialog
 * @version 1.5 - Shortened the page text: one-line intro, log-access detail folded into a "What can it read?" disclosure
 *                (same wording, nothing dropped), shorter connection and turn-off notes
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
	'menu-id'        => 'system-management-node',
	'readable_title' => 'Management Node',
	'breadcrumbs'    => array(
		'System'          => '',
		'Management Node' => '',
	),
	'session' => $session,
));

$self = '/admin/admin_management_node';

if ($error) {
	echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($error) . '</div>';
}
if ($cancelled) {
	echo '<div class="alert alert-info" role="alert">Request cancelled. Nothing was shared, so there is nothing to undo.</div>';
}

$status      = is_array($state) ? (string)($state['status'] ?? '') : '';
$fingerprint = is_array($state) ? (string)($state['fingerprint'] ?? '') : '';
$fpr_display = $fingerprint !== '' ? trim(chunk_split($fingerprint, 4, ' ')) : '';

echo '<p class="text-muted" style="max-width:46rem;">The agent runs backups, upgrades and health checks on this machine. '
   . 'Connecting it to a management node lets an administrator run those jobs from one dashboard. '
   . 'No password or key is shared: the agent makes its own key, and an administrator approves it after matching the fingerprints.</p>';

if ($agent_switched === 'on') {
	echo '<div class="alert alert-info" role="alert">The agent is switched on for this machine.</div>';
} elseif ($agent_switched === 'off') {
	echo '<div class="alert alert-info" role="alert">The agent is switched off. It stops at the next container start, upgrade, or installer run; the agent itself stays installed.</div>';
}

// The agent comes first: with it off, everything below describes work this
// machine has no way to carry out.
if (!$agent_enabled) {
	echo '<div class="alert alert-secondary" role="alert">'
	   . '<strong>This machine is not running the agent.</strong> Nothing below takes effect until it is.'
	   . '</div>';
	echo '<form method="POST" action="' . $self . '">'
	   . '<input type="hidden" name="action" value="enable_agent">'
	   . '<button type="submit" class="btn btn-primary">Turn on the agent</button>'
	   . '</form>';
	echo '<p class="text-muted" style="max-width:46rem;margin-top:12px;">'
	   . ($agent_installed
		? 'Starts at the next container start, upgrade, or installer run.'
		: 'Installs and starts at the next container start, upgrade, or installer run.')
	   . ' Connecting to a management node is a separate step afterwards.</p>';
	$page->admin_footer();
	return;
}

if (!$agent_installed) {
	echo '<div class="alert alert-warning" role="alert">'
	   . '<strong>Switched on, not installed yet.</strong> It installs by itself at the next container start or upgrade. To install now, run this on the machine:'
	   . '<div class="mt-2"><code>' . htmlspecialchars($installer_hint) . '</code></div>'
	   . '</div>';
}

// ---- Management: is this machine managed, and everything about that ------
$is_managed = $status === 'connected';
if ($is_managed) {
	$badge_class = 'bg-success'; $badge_text = 'Managed';
} elseif ($request !== null && $status === 'pending' && $fpr_display !== '') {
	$badge_class = 'bg-warning text-dark'; $badge_text = 'Waiting for approval';
} elseif ($request !== null) {
	$badge_class = 'bg-secondary'; $badge_text = 'Connecting';
} else {
	$badge_class = 'bg-secondary'; $badge_text = 'Not managed';
}

echo '<div class="card mb-3" style="max-width:46rem;"><div class="card-body">';
echo '<h5 class="card-title mb-2">Management <span class="badge ' . $badge_class . '">' . $badge_text . '</span></h5>';

if ($is_managed) {
	echo '<p class="mb-2">This site is managed by <code>' . htmlspecialchars((string)($state['url'] ?? '')) . '</code>';
	if (!empty($state['node_slug']) || !empty($state['node_id'])) {
		echo ' as <strong>' . htmlspecialchars((string)($state['node_slug'] ?? '')) . '</strong>'
		   . (!empty($state['node_id']) ? ' (node #' . (int)$state['node_id'] . ')' : '');
	}
	echo '.</p>';
	if ($fpr_display !== '') {
		echo '<p class="mb-2">This machine\'s key fingerprint: <code>' . htmlspecialchars($fpr_display) . '</code></p>';
	}

	if ($leave_request !== null) {
		echo '<div class="alert alert-warning" role="alert">'
		   . '<strong>Disconnecting.</strong> The agent finishes any running job, then leaves. '
		   . 'If this does not change shortly, the agent is not running.</div>';
		echo '<form method="POST" action="' . $self . '">'
		   . '<input type="hidden" name="action" value="cancel_disconnect">'
		   . '<button type="submit" class="btn btn-secondary">Cancel the disconnect</button>'
		   . '</form>';
	} else {
		echo '<p class="text-muted">Either side can end the connection. '
		   . 'Leaving here makes the agent tell the management node to forget this machine\'s key and go back to local work only.</p>';
		echo '<form method="POST" action="' . $self . '" '
		   . 'onsubmit="return confirm(\'Disconnect from this management node? It stops managing this site, and reconnecting starts a fresh introduction.\');">'
		   . '<input type="hidden" name="action" value="disconnect">'
		   . '<button type="submit" class="btn btn-outline-danger">Disconnect</button>'
		   . '</form>';
	}

} elseif ($request !== null) {
	// A request is on the table. What we show depends on how far the agent got.
	if ($status === 'pending' && $fpr_display !== '') {
		echo '<p class="mb-2">Request sent to <code>'
		   . htmlspecialchars((string)($state['url'] ?? $request['url'] ?? '')) . '</code>. This machine\'s key fingerprint:</p>';
		echo '<p><code style="font-size:1.5em;">' . htmlspecialchars($fpr_display) . '</code></p>';
		echo '<p class="text-muted">An administrator must approve it on the management node, <strong>only if the fingerprint there matches this one exactly</strong>.</p>';
	} elseif ($status === 'rejected') {
		echo '<div class="alert alert-danger" role="alert">'
		   . '<strong>The request was rejected.</strong> If that was a mistake, cancel below and ask again.</div>';
	} elseif ($status === 'error') {
		echo '<div class="alert alert-danger" role="alert">'
		   . '<strong>The agent could not reach the management node.</strong> '
		   . htmlspecialchars((string)($state['error'] ?? '')) . '<br>'
		   . '<span class="small">It keeps retrying. Fix the URL or the network and this updates by itself.</span></div>';
	} else {
		echo '<p class="text-muted">Request recorded. Waiting for the agent to pick it up. '
		   . 'If this does not change shortly, the agent is not running.</p>';
	}
	echo '<form method="POST" action="' . $self . '">'
	   . '<input type="hidden" name="action" value="cancel">'
	   . '<button type="submit" class="btn btn-secondary">Cancel this request</button>'
	   . '</form>';

} else {
	echo '<p class="text-muted">This site is not managed by anything. It still runs its own backups and upgrades.</p>';
	if ($status === 'rejected') {
		echo '<div class="alert alert-warning" role="alert">The previous request was rejected on the management node. You can ask again below.</div>';
	}
	$formwriter = $page->getFormWriter('management_node_form');
	$formwriter->begin_form();
	$formwriter->hiddeninput('action', '', array('value' => 'connect'));
	$formwriter->textinput('management_node_url', 'Management node URL', array(
		'placeholder' => 'https://manage.example.com',
		'required'    => true,
		'helptext'    => 'Just the address. Nothing secret goes here.',
	));
	$formwriter->submitbutton('btn_connect', 'Connect', array('class' => 'btn btn-primary'));
	$formwriter->end_form();
}
echo '</div></div>';

// ---- What the agent does (illustrative, not exhaustive) -------------------
echo '<div class="card mb-3" style="max-width:46rem;"><div class="card-body">';
echo '<h5 class="card-title mb-2">What the agent does</h5>';
echo '<ul class="mb-0">'
   . '<li>Takes and checks backups of this site</li>'
   . '<li>Applies code upgrades and database updates</li>'
   . '<li>Checks that the site, database and disk are healthy</li>'
   . '<li>Restarts services and clears caches when something is stuck</li>'
   . '<li>Copies or moves the site to another machine</li>'
   . '</ul>';
echo '<p class="text-muted small mt-2 mb-0">These run here on their own. A connected management node can start the same jobs from its dashboard.</p>';
echo '</div></div>';

// ---- Log access -----------------------------------------------------------
// What a connected management node may read of this site's logs. The agent
// enforces it: a log request arriving while this is off is refused on this
// machine, whatever the management node says.
if ($log_access_switched === 'on') {
	echo '<div class="alert alert-info" role="alert">Log access is on. A connected management node can read this site\'s redacted log excerpts.</div>';
} elseif ($log_access_switched === 'off') {
	echo '<div class="alert alert-info" role="alert">Log access is off. The agent refuses every log request from a management node from now on.</div>';
}
echo '<div class="card mb-3" style="max-width:46rem;"><div class="card-body">';
echo '<h5 class="card-title mb-2">Extra permissions: logs and host configuration <span class="badge ' . ($log_access ? 'bg-success' : 'bg-secondary') . '">' . ($log_access ? 'On' : 'Off') . '</span></h5>';
echo '<p class="text-muted mb-2">Lets a connected management node read redacted log excerpts and host configuration. '
   . 'Member data and keys are never sent. On by default; no effect until connected.</p>';
echo '<details class="mb-3"><summary>What can it read?</summary>'
   . '<p class="text-muted mt-2 mb-0">A management node this machine is connected to can ask for: the last lines of the site\'s '
   . 'error and task logs; the newest rows of the login, request, event, form-error and webhook logs; the last lines of a '
   . 'service\'s journal (Apache, PostgreSQL, cron, fail2ban and the agent among them); and the first lines of the host\'s '
   . 'configuration files (fail2ban, Apache, PHP, logging, scheduled tasks, updates, Docker, mail settings). '
   . 'Member data and keys are never sent: member addresses, submitted forms and raw payloads are left out entirely, files that '
   . 'hold a key or a password are never read, and passwords, tokens, addresses and the personal half of email addresses are '
   . 'masked on this machine before anything is sent. '
   . 'When off, every such request is refused here, and the management node is told why.</p></details>';
echo '<form method="POST" action="' . $self . '" class="d-inline">'
   . '<input type="hidden" name="action" value="' . ($log_access ? 'log_access_off' : 'log_access_on') . '">'
   . '<button type="submit" class="btn btn-sm ' . ($log_access ? 'btn-outline-secondary' : 'btn-primary') . '">'
   . ($log_access ? 'Turn off log access' : 'Turn on log access') . '</button>'
   . '</form>';
echo '</div></div>';

// ---- Turning the agent off: strongly discouraged, typed confirmation -------
$consequence = $is_managed
	? 'The management node will see this machine stop responding. Disconnect first for a clean ending.'
	: 'Nothing will be able to run backups or upgrades here until it is turned back on.';

echo '<div class="card border-danger mb-3" style="max-width:46rem;"><div class="card-body">';
echo '<h5 class="card-title text-danger mb-2">Turn off the agent</h5>';
echo '<p class="mb-2"><strong>Not recommended.</strong> With the agent off, this machine stops taking its own backups and '
   . 'applying its own upgrades, and no management node can help it. Leave it on unless you have a specific reason.</p>';
echo '<button type="button" class="btn btn-sm btn-outline-danger" id="agent-off-open">Turn off the agent…</button>';
echo '</div></div>';

echo '<dialog id="agent-off-dialog" style="max-width:30rem;border:1px solid #c00;border-radius:6px;padding:1.5rem;">'
   . '<form method="POST" action="' . $self . '" id="agent-off-form">'
   . '<input type="hidden" name="action" value="disable_agent">'
   . '<h5 class="text-danger" style="margin-top:0;">Turn off the agent?</h5>'
   . '<p>This machine will stop carrying out its own backups and upgrades. ' . htmlspecialchars($consequence) . '</p>'
   . '<p>To confirm, type <strong>disable agent</strong> below.</p>'
   . '<input type="text" name="confirm_text" id="agent-off-text" class="form-control" autocomplete="off" style="width:100%;margin-bottom:1rem;">'
   . '<div style="display:flex;gap:8px;justify-content:flex-end;">'
   . '<button type="button" class="btn btn-secondary" id="agent-off-cancel">Keep the agent on</button>'
   . '<button type="submit" class="btn btn-danger" id="agent-off-go" disabled>Turn off the agent</button>'
   . '</div></form></dialog>';
echo '<script>(function(){'
   . 'var d=document.getElementById("agent-off-dialog"),t=document.getElementById("agent-off-text"),g=document.getElementById("agent-off-go");'
   . 'document.getElementById("agent-off-open").addEventListener("click",function(){t.value="";g.disabled=true;d.showModal();t.focus();});'
   . 'document.getElementById("agent-off-cancel").addEventListener("click",function(){d.close();});'
   . 't.addEventListener("input",function(){g.disabled=t.value.trim().toLowerCase()!=="disable agent";});'
   . '})();</script>';

$page->admin_footer();
