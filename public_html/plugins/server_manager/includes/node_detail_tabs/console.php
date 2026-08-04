<?php
/**
 * node_detail — Console tab partial.
 *
 * Runs one ad-hoc command on this node (specs/server_manager_node_console.md).
 * Included by views/admin/node_detail.php in the shell's scope; the shell owns
 * node loading, the tab whitelist, and check_permission(10). Lives under
 * includes/ (not views/) so it is not reachable as a standalone URL.
 *
 * In scope: $node, $page, $session, $base_url, $node_name, $page_regex,
 * $skip_joinery, $tab.
 *
 * @version 1.0
 */

	require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmSecretRedactor.php'));
	// The shell loads this too; named here because this tab asks it whether a
	// second-factor confirmation is owed, and a tab should not depend on the
	// include order of the file that happens to include it.
	require_once(PathHelper::getIncludePath('plugins/server_manager/logic/node_detail_actions_logic.php'));

	$console_enabled = (bool)$node->get('mgn_allow_console');
	$is_container    = (bool)$node->get('mgn_container_name');

	$pageoptions = ['title' => 'Console'];
	$page->begin_box($pageoptions);

	if (!$console_enabled) {
		// Guided control, not an explainer: the one thing to do is turn it on.
		echo '<p class="mb-2"><span class="badge bg-secondary">Off</span> '
		   . 'Running commands on this site from here is turned off.</p>';
		echo '<a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars($base_url) . '&tab=overview">'
		   . 'Turn it on in Overview → Edit</a>';
		$page->end_box();
		return;
	}

	// A command runs as the node's SSH identity — the operator is entitled to
	// see whose privilege they are borrowing before they borrow it.
	echo '<p class="text-muted small mb-3">Runs as <code>'
	   . htmlspecialchars($node->get('mgn_ssh_user') ?: 'root') . '@'
	   . htmlspecialchars($node->get('mgn_host')) . '</code>'
	   . ($is_container ? ' in container <code>' . htmlspecialchars($node->get('mgn_container_name')) . '</code>' : '')
	   . '. The command and everything it prints are kept in the job record.</p>';

	// A confirmation is owed and this account has no passkey, so the inline
	// ceremony below cannot run — send them to the platform's confirm page and
	// back. Passkey holders never see this: their ceremony happens in place.
	$stepup_needed = NodeDetailActions::step_up_required($session);
	$passkeys = new MultiPasskey(['user_id' => $session->get_user_id()]);
	$has_passkey = $passkeys->count_all() > 0;
	if ($stepup_needed && !$has_passkey) {
		echo '<div class="alert alert-warning py-2 small">Confirm it is you before running a command. '
		   . '<a href="/verify-stepup?return=' . rawurlencode($base_url . '&tab=console') . '">Confirm now</a>.</div>';
	}

	// Repopulate after a refusal that re-rendered in place (step-up owed, empty
	// command): the operator's typing survives the bounce.
	$console_command = isset($_POST['console_command']) ? (string)$_POST['console_command'] : '';
	$console_timeout = isset($_POST['console_timeout']) ? (int)$_POST['console_timeout'] : 0;
	if (!in_array($console_timeout, JobCommandBuilder::CONSOLE_TIMEOUTS, true)) {
		$console_timeout = JobCommandBuilder::CONSOLE_TIMEOUT_DEFAULT;
	}

	$timeout_options = [];
	foreach (JobCommandBuilder::CONSOLE_TIMEOUTS as $seconds) {
		$timeout_options[(string)$seconds] = $seconds < 60
			? $seconds . ' seconds'
			: ($seconds / 60) . ' minute' . ($seconds === 60 ? '' : 's');
	}

	$fw_console = $page->getFormWriter('console_form', [
		'values' => [
			'console_command' => $console_command,
			'console_timeout' => (string)$console_timeout,
		],
	]);
	$fw_console->begin_form();
	$fw_console->hiddeninput('action', '', ['value' => 'run_command']);
	$fw_console->hiddeninput(SmAdminCsrf::FIELD, '', ['value' => SmAdminCsrf::token()]);
	$fw_console->textarea('console_command', 'Command', [
		'rows'        => 4,
		'placeholder' => 'apache2ctl -M | grep -E \'mpm|fcgi\'',
		'helptext'    => 'Chain steps with && or ; if you need several. Do not paste passwords or keys — the command is stored verbatim in the job record.',
		'required'    => true,
	]);
	$fw_console->dropinput('console_timeout', 'Give up after', [
		'options'  => $timeout_options,
		'value'    => (string)$console_timeout,
		'helptext' => 'The command is killed at this point and the job is marked failed.',
	]);
	if ($is_container) {
		$fw_console->checkboxinput('console_on_host', 'Run on the host instead of inside the container', [
			'checked'  => !empty($_POST['console_on_host']),
			'helptext' => 'For anything the container cannot see — the reverse proxy, certificates, docker itself.',
		]);
	}
	$fw_console->submitbutton('btn_console_run', 'Run', ['class' => 'btn btn-sm btn-primary']);
	$fw_console->end_form();

	$page->end_box();

	// ── Recent console runs ──
	// The audit trail, shown where the commands are issued. Both UI and CLI
	// (node_exec.php) runs land here, so this is the whole picture for this node.
	$recent = new MultiManagementJob(
		['node_id' => $node->key, 'job_type' => 'run_command', 'deleted' => false],
		['mjb_id' => 'DESC'],
		10
	);
	$recent->load();

	$pageoptions = ['title' => 'Recent commands'];
	$page->begin_box($pageoptions);
	if (count($recent) === 0) {
		echo '<p class="text-muted mb-0">Nothing has been run on this site yet.</p>';
	} else {
		echo '<table class="table table-sm"><tr><th>When</th><th>Command</th><th>By</th><th>Status</th></tr>';
		foreach ($recent as $run) {
			$params  = json_decode($run->get('mjb_parameters') ?: '{}', true) ?: [];
			$cmd     = isset($params['command']) ? (string)$params['command'] : '';
			$cmd     = SmSecretRedactor::redact($cmd);
			$source  = (isset($params['source']) && $params['source'] === 'cli') ? ' <span class="badge bg-secondary">CLI</span>' : '';
			$status  = (string)$run->get('mjb_status');
			$badge   = $status === 'completed' ? 'success' : ($status === 'failed' ? 'danger' : 'secondary');
			$who     = 'CLI';
			if ($run->get('mjb_created_by')) {
				$operator = new User((int)$run->get('mjb_created_by'), TRUE);
				$who = $operator->key ? $operator->display_name() : 'Removed user';
			}

			echo '<tr>';
			echo '<td class="text-nowrap">' . htmlspecialchars(LibraryFunctions::convert_time(
				$run->get('mjb_create_time'), 'UTC', $session->get_timezone(), 'M j, g:i A')) . '</td>';
			echo '<td><code class="small">' . htmlspecialchars(mb_strimwidth($cmd, 0, 90, '…')) . '</code>' . $source . '</td>';
			echo '<td>' . htmlspecialchars((string)$who) . '</td>';
			echo '<td><a class="badge bg-' . $badge . '" href="/admin/server_manager/job_detail?job_id=' . intval($run->key) . '">'
			   . htmlspecialchars($status) . '</a></td>';
			echo '</tr>';
		}
		echo '</table>';
	}
	$page->end_box();

	// ── Confirmation + inline step-up ──
	// The dialog shows the RESOLVED execution context, so the operator confirms
	// what will actually run rather than what they think they typed. When a
	// second-factor confirmation is owed, the passkey ceremony runs in place and
	// the form then submits — same server gate either way (the marker it stamps
	// is what NodeDetailActions checks). If the ceremony fails or the browser
	// cannot do WebAuthn, the submit proceeds and the server refusal takes over.
	$console_cfg = [
		'host'      => (string)$node->get('mgn_host'),
		'sshUser'   => (string)($node->get('mgn_ssh_user') ?: 'root'),
		'container' => $is_container ? (string)$node->get('mgn_container_name') : '',
		'stepup'    => ['needed' => $stepup_needed, 'passkey' => $has_passkey],
	];
	if ($stepup_needed) {
		echo '<script src="/assets/js/joinery-api.js?v=' . (@filemtime(PathHelper::getIncludePath('assets/js/joinery-api.js')) ?: '1') . '"></script>' . "\n";
		echo '<script src="/assets/js/passkeys.js?v=' . (@filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1') . '"></script>' . "\n";
	}
	echo '<script>window.smConsole = ' . json_encode($console_cfg,
		JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';</script>' . "\n";
	?>
<script>
(function () {
	var cfg  = window.smConsole || {};
	var form = document.getElementById('console_form');
	if (!form) return;

	// One-way latch, never cleared. FormWriter's validator also intercepts this
	// form's submit and re-dispatches it, so one click produces several submit
	// events — a flag that is consumed on pass-through gets eaten by the wrong
	// one and the dialog reopens forever. Once the operator has confirmed, every
	// subsequent submit event goes straight through.
	var confirmed = false;

	function summary() {
		var cmd    = form.querySelector('[name="console_command"]');
		var to     = form.querySelector('[name="console_timeout"]');
		var onHost = form.querySelector('[name="console_on_host"]');
		var where  = cfg.container
			? (onHost && onHost.checked ? 'on the host ' + cfg.host : 'in container ' + cfg.container)
			: 'on ' + cfg.host;

		var wrap = document.createElement('div');
		var p = document.createElement('p');
		p.textContent = 'Run as ' + cfg.sshUser + ' ' + where + ', giving up after '
			+ (to ? to.value : '') + ' seconds:';
		var pre = document.createElement('pre');
		pre.className = 'small';
		pre.style.whiteSpace = 'pre-wrap';
		pre.textContent = cmd ? cmd.value : '';   // textContent: the command is never parsed as markup
		wrap.appendChild(p);
		wrap.appendChild(pre);
		return wrap;
	}

	function stepUpThenSubmit() {
		if (!cfg.stepup || !cfg.stepup.needed || !cfg.stepup.passkey
			|| !window.joineryApi || !window.JoineryPasskeys) {
			return submitForReal();
		}
		joineryApi.post('passkey_stepup_options', {}).then(function (opt) {
			if (!opt || !opt.options) throw new Error('Could not start confirmation.');
			return JoineryPasskeys.authenticate(opt.options);
		}).then(function (credential) {
			return joineryApi.post('passkey_stepup_verify', { credential: credential });
		}).then(function (res) {
			if (res && res.success === false) throw new Error(res.message || 'Confirmation failed.');
			cfg.stepup.needed = false;   // the marker is stamped server-side for the session
			submitForReal();
		}).catch(function () {
			// Dead-ending here would lose the command; let the server refuse and
			// re-render with it intact.
			submitForReal();
		});
	}

	function submitForReal() {
		confirmed = true;
		if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
	}

	form.addEventListener('submit', function (ev) {
		if (confirmed) return;
		var cmd = form.querySelector('[name="console_command"]');
		if (!cmd || cmd.value.trim() === '') return;   // let the browser's required check speak
		ev.preventDefault();
		JoineryModal.open(summary(), {
			buttons: [
				{ label: 'Cancel', style: 'secondary' },
				{ label: 'Run', style: 'danger', onClick: function () { stepUpThenSubmit(); } }
			]
		});
	});
})();
</script>
<?php
