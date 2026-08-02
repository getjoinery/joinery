<?php
// PathHelper, Globalvars, SessionControl, DbConnector, ThemeHelper,
// PluginHelper are always pre-loaded — never require them.

require_once(PathHelper::getIncludePath('adm/logic/admin_recovery_readiness_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_recovery_readiness_logic(array_merge($_GET, $_POST)));

$session = $page_vars['session'];
$settings = $page_vars['settings'];
$items = $page_vars['items'];
$stale_days = $page_vars['stale_days'];

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'recovery-readiness',
	'page_title' => 'Recovery Readiness',
	'readable_title' => 'Recovery Readiness',
	'breadcrumbs' => array('Recovery Readiness' => ''),
	'session' => $session,
));

/** Substitute the placeholders a canonical label carries. */
$account_email = isset($page_vars['account_email']) ? $page_vars['account_email'] : '';
$label_fill = function ($label) use ($settings, $account_email) {
	return str_replace(
		array('{site}', '{account}'),
		array((string)$settings->get_setting('site_name'), $account_email),
		(string)$label
	);
};

$ceremony_configs = array();
$client_configs = array();
foreach ($items as $item) {
	if (($item['verify'] ?? '') === 'dry_run' && ($item['custody'] ?? '') === 'client') {
		$client_configs[$item['scope']] = array('wrappings' => $item['client_wrappings']);
	}
}

foreach ($items as $i => $item) {
	$page->begin_box(array('title' => $item['title']));

	// ── Status line ────────────────────────────────────────────────────
	if ($item['state'] === 'error') {
		echo '<div class="alert alert-danger mb-2">' . htmlspecialchars($item['state_text']) . '</div>';
		$page->end_box();
		continue;
	}
	if ($item['state'] === 'not_configured') {
		echo '<div class="alert alert-warning mb-2">' . htmlspecialchars($item['state_text']) . '</div>';
		$page->end_box();
		continue;
	}

	if ($item['last_verified']) {
		$when = LibraryFunctions::convert_time($item['last_verified'], 'UTC', $session->get_timezone(), 'M j, Y g:i A');
		if ($item['stale']) {
			echo '<div class="alert alert-warning mb-2">Last verified ' . htmlspecialchars($when)
				. ' — more than ' . (int)$stale_days . ' days ago. Verify it again below.</div>';
		} else {
			echo '<div class="alert alert-success mb-2">Verified ' . htmlspecialchars($when) . '.</div>';
		}
	} else {
		echo '<div class="alert alert-warning mb-2"><strong>Never verified.</strong> Confirm below that you actually hold this.</div>';
	}

	if ($item['protects'] !== '') {
		echo '<p>' . htmlspecialchars($item['protects']) . '</p>';
	}

	foreach ($item['warnings'] as $warning) {
		echo '<div class="alert alert-warning mb-2">' . htmlspecialchars($warning) . '</div>';
	}

	// ── Facts ──────────────────────────────────────────────────────────
	if (count($item['facts'])) {
		echo '<table class="table mb-3" style="max-width:40rem;">';
		foreach ($item['facts'] as $fact_label => $fact_value) {
			echo '<tr><td class="text-muted" style="width:14rem;">' . htmlspecialchars($fact_label)
				. '</td><td>' . htmlspecialchars($fact_value) . '</td></tr>';
		}
		echo '</table>';
	}

	// ── Canonical password-manager label ───────────────────────────────
	if ($item['label'] !== '') {
		$filled = $label_fill($item['label']);
		echo '<p class="mb-3">Password-manager entry name: <code>' . htmlspecialchars($filled) . '</code> '
			. '<button type="button" class="btn btn-sm btn-outline-secondary" data-jy-copy="'
			. htmlspecialchars($filled, ENT_QUOTES) . '">Copy</button></p>';
	}

	// ── Verify tool ────────────────────────────────────────────────────
	if ($item['instructions'] !== '') {
		echo '<p class="mb-2">' . htmlspecialchars($item['instructions']) . '</p>';
	}
	if ($item['action_url'] !== '') {
		echo '<p class="mb-2"><a href="' . htmlspecialchars($item['action_url'], ENT_QUOTES) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">'
			. htmlspecialchars($item['action_url_label'] !== '' ? $item['action_url_label'] : 'Open') . ' ↗</a></p>';
	}
	switch ($item['verify']) {
		case 'ceremony':
			$ids = array(
				'key'    => 'rr-key-' . $i,
				'open'   => 'rr-open-' . $i,
				'status' => 'rr-status-' . $i,
				'proof'  => 'rr-proof-' . $i,
			);
			// The pasted key lives OUTSIDE the form: it is used in the browser
			// and cleared; only the recovered (public) proof string is submitted.
			echo '<label for="' . $ids['key'] . '" class="d-block mb-1">Paste the private key from your password manager:</label>';
			echo '<input type="password" id="' . $ids['key'] . '" class="form-control" style="max-width:40rem;" autocomplete="off">';
			echo '<p class="text-muted small mt-1 mb-2">Checked entirely in your browser — the key is never sent to the server, '
				. 'and the box is cleared the moment it is used. Only pass/fail is recorded.</p>';
			echo '<button type="button" id="' . $ids['open'] . '" class="btn btn-primary btn-sm">Verify with my key</button>';
			echo '<div id="' . $ids['status'] . '" class="small mt-2"></div>';

			echo '<div hidden>';
			$fw = $page->getFormWriter('rr_ceremony_' . $i);
			$fw->begin_form();
			echo '<input type="hidden" name="action" value="verify_item">';
			echo '<input type="hidden" name="item_key" value="' . htmlspecialchars($item['key'], ENT_QUOTES) . '">';
			echo '<input type="hidden" name="escrow_proof" id="' . $ids['proof'] . '" value="">';
			$fw->end_form();
			echo '</div>';

			if (!empty($item['ceremony']['cli_command'])) {
				echo '<details class="mt-2"><summary class="text-muted small">Do it from the command line instead</summary>'
					. '<pre class="border rounded p-2 mt-2" style="white-space:pre-wrap;word-break:break-all;">'
					. htmlspecialchars($item['ceremony']['cli_command']) . '</pre>';
				// The CLI path recovers a plain sentence; paste it here to record.
				$fw = $page->getFormWriter('rr_ceremony_cli_' . $i);
				$fw->begin_form();
				echo '<input type="hidden" name="action" value="verify_item">';
				echo '<input type="hidden" name="item_key" value="' . htmlspecialchars($item['key'], ENT_QUOTES) . '">';
				$fw->textinput('escrow_proof', 'Sentence the command printed', array('autocomplete' => 'off'));
				$fw->submitbutton('btn_verify_cli_' . $i, 'Verify pasted result');
				$fw->end_form();
				echo '</details>';
			}

			$ceremony_configs[] = array(
				'keyInputId' => $ids['key'],
				'buttonId'   => $ids['open'],
				'statusId'   => $ids['status'],
				'proofId'    => $ids['proof'],
				'challenge'  => (string)$item['ceremony']['challenge'],
				'publicKey'  => (string)$item['ceremony']['public_key'],
				// The HKDF context the server sealed with. Carried through rather
				// than assumed on the browser side, so the two cannot drift apart
				// and break verification for whoever tries it next.
				'infoPrefix' => (string)($item['ceremony']['info_prefix'] ?? ''),
			);
			break;

		case 'dry_run':
			if (($item['custody'] ?? '') === 'client') {
				// Client custody: the code must never reach the server. The
				// browser runs the same unwrap the real recovery would, then
				// reports only pass/fail.
				$ids = array('code' => 'rr-code-' . $i, 'check' => 'rr-check-' . $i, 'status' => 'rr-cstat-' . $i);
				echo '<label for="' . $ids['code'] . '" class="d-block mb-1">Enter one recovery code (checked in your browser, never sent, never used up):</label>';
				echo '<input type="password" id="' . $ids['code'] . '" class="form-control" style="max-width:24rem;" autocomplete="off">';
				echo '<button type="button" id="' . $ids['check'] . '" class="btn btn-primary btn-sm mt-2" '
					. 'data-rr-client-check data-rr-scope="' . htmlspecialchars($item['scope'], ENT_QUOTES) . '" '
					. 'data-rr-code="' . $ids['code'] . '" data-rr-status="' . $ids['status'] . '">Check code</button>';
				echo '<div id="' . $ids['status'] . '" class="small mt-2"></div>';

				echo '<div data-rr-client-form="' . htmlspecialchars($item['scope'], ENT_QUOTES) . '" hidden>';
				$fw = $page->getFormWriter('rr_client_' . $i);
				$fw->begin_form();
				echo '<input type="hidden" name="action" value="record_client_dry_run">';
				echo '<input type="hidden" name="scope" value="' . htmlspecialchars($item['scope'], ENT_QUOTES) . '">';
				echo '<input type="hidden" name="passed" value="">';
				$fw->end_form();
				echo '</div>';
			} else {
				$fw = $page->getFormWriter('rr_dryrun_' . $i);
				$fw->begin_form();
				echo '<input type="hidden" name="action" value="verify_item">';
				echo '<input type="hidden" name="item_key" value="' . htmlspecialchars($item['key'], ENT_QUOTES) . '">';
				$fw->textinput('code', 'Enter one recovery code (checked, not used up)', array('autocomplete' => 'off'));
				$fw->submitbutton('btn_check_' . $i, 'Check code');
				$fw->end_form();
			}
			break;

		case 'attested':
			$fw = $page->getFormWriter('rr_attest_' . $i);
			$fw->begin_form();
			echo '<input type="hidden" name="action" value="verify_item">';
			echo '<input type="hidden" name="item_key" value="' . htmlspecialchars($item['key'], ENT_QUOTES) . '">';
			$fw->submitbutton('btn_attest_' . $i, !empty($item['attest_label']) ? $item['attest_label'] : 'I confirmed I can access this');
			$fw->end_form();
			break;
	}

	$page->end_box();
}

if (!count($items)) {
	$page->begin_box(array('title' => 'Nothing to save yet'));
	echo '<p class="text-muted mb-0">No must-save secrets exist on this site yet. Items appear here as backup recovery, cloud targets, and encrypted vaults are set up.</p>';
	$page->end_box();
}

$vault_aggregate = isset($page_vars['vault_aggregate']) ? $page_vars['vault_aggregate'] : array();
if (count($vault_aggregate)) {
	$page->begin_box(array('title' => 'Other users\' vaults at risk'));
	echo '<p>These accounts hold encrypted vaults with a thin recovery margin. Their codes are theirs alone — '
		. 'nothing can be checked from here — but each user sees the same warning with a fix on their own '
		. 'Security page (/profile/security).</p>';
	echo '<table class="table mb-0" style="max-width:50rem;">';
	echo '<tr><th>Account</th><th>Vault</th><th>Issue</th></tr>';
	foreach ($vault_aggregate as $agg) {
		echo '<tr><td>' . htmlspecialchars($agg['email']) . '</td>'
			. '<td>' . htmlspecialchars($agg['scope']) . ($agg['custody'] === 'client' ? ' (end-to-end encrypted)' : '') . '</td>'
			. '<td>' . htmlspecialchars($agg['issues']) . '</td></tr>';
	}
	echo '</table>';
	$page->end_box();
}
?>
<?php
$stepup = isset($page_vars['stepup']) ? $page_vars['stepup'] : array('needed' => false, 'passkey' => false);
if (count($client_configs)) { ?>
<script src="/assets/js/vault-crypto.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/vault-crypto.js')) ?: '1'; ?>"></script>
<?php }
if (!empty($stepup['needed']) && !empty($stepup['passkey'])) { ?>
<script src="/assets/js/joinery-api.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/joinery-api.js')) ?: '1'; ?>"></script>
<script src="/assets/js/passkeys.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/passkeys.js')) ?: '1'; ?>"></script>
<?php } ?>
<script defer src="/assets/js/recovery-readiness.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/recovery-readiness.js')) ?: '1'; ?>"></script>
<script>
window.rrCeremonyConfigs = <?php echo json_encode($ceremony_configs); ?>;
window.rrClientConfigs = <?php echo json_encode($client_configs); ?>;
window.rrStepup = <?php echo json_encode($stepup); ?>;
document.addEventListener('DOMContentLoaded', function () {
	if (window.recoveryReadiness) {
		(window.rrCeremonyConfigs || []).forEach(function (c) { window.recoveryReadiness.attachCeremony(c); });
		window.recoveryReadiness.attachClientChecks();
		window.recoveryReadiness.attachStepUp(window.rrStepup);
	}
});
</script>
<?php
$page->admin_footer();
?>
