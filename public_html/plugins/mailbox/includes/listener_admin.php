<?php
/**
 * Mailbox - local mail listener decommission (specs/mailbox_listener_decommission.md).
 *
 * Once a relay fronts the deployment, the box's own mail listener (Postfix,
 * opendkim, opendmarc, port 25) is dead weight with live attack surface. This
 * file is the whole platform action that removes it — and restores it: the
 * guardrail evaluation, the narrow sudo-helper runner
 * (/usr/local/sbin/joinery-mail-listener, installed by
 * provision_relay_main.sh), the POST action handlers, and the "Local mail
 * listener" box the Setup tab's Relay section renders. State is recorded in
 * the mailbox_local_listener setting ('active' | 'decommissioned') so the
 * setup and health checks can compare expectation with reality.
 *
 * @version 1.1
 */

/** The recorded listener state: 'active' (factory) or 'decommissioned'. */
function mailbox_listener_setting(): string {
	$v = strtolower(trim((string)Globalvars::get_instance()->get_setting('mailbox_local_listener')));
	return $v === 'decommissioned' ? 'decommissioned' : 'active';
}

/** Whether anything answers on local port 25 right now. */
function mailbox_listener_port25_listening(): bool {
	$sock = @stream_socket_client('tcp://127.0.0.1:25', $errno, $errstr, 2);
	if ($sock) {
		@fclose($sock);
		return true;
	}
	return false;
}

/** The installed helper path (matches provision_relay_main.sh). */
function mailbox_listener_helper_path(): string {
	return '/usr/local/sbin/joinery-mail-listener';
}

/**
 * Everything the "Local mail listener" box needs: the recorded state, the
 * live port-25 reality, whether the helper is installed, and — while the
 * listener is still active — the guardrail verdict for the Decommission button.
 */
function mailbox_listener_state(): array {
	$setting = mailbox_listener_setting();
	$state = array(
		'setting'          => $setting,
		'listening'        => mailbox_listener_port25_listening(),
		'helper_installed' => is_file(mailbox_listener_helper_path()),
		'guardrail_failures' => array(),
	);
	if ($setting === 'active') {
		$state['guardrail_failures'] = mailbox_listener_guardrail_failures(mailbox_listener_guardrail_facts());
	}
	return $state;
}

/**
 * Assemble the facts the guardrail matrix decides on. Each fact is gathered
 * from the system that already owns it — the topology/cutover evaluation from
 * InboundEmailSetupCheck, spool health from InboundEmailHealth, the outbound
 * transport from EmailSender — never re-derived here.
 *
 * @return array{relay_enabled:bool,cutover_complete:bool,cutover_reason:string,
 *               spool_error:string,outbound_local:bool,outbound_label:string}
 */
function mailbox_listener_guardrail_facts(): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
	require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

	$facts = array(
		'relay_enabled'    => false,
		'cutover_complete' => false,
		'cutover_reason'   => '',
		'spool_error'      => '',
		'outbound_local'   => false,
		'outbound_label'   => '',
	);

	$facts['relay_enabled'] = (MailboxRelay::active() !== null);

	if ($facts['relay_enabled']) {
		$cutover = (new InboundEmailSetupCheck())->relayCutoverState();
		$facts['cutover_complete'] = $cutover['complete'];
		$facts['cutover_reason']   = $cutover['reason'];

		try {
			InboundEmailHealth::checkRelaySpoolDraining();
		} catch (\Throwable $e) {
			$facts['spool_error'] = $e->getMessage();
		}
	}

	// The outbound path must not lean on the local Postfix: no resolvable
	// provider means PHP mail()/local sendmail, and an SMTP provider aimed at
	// localhost submits through the very listener being removed.
	$provider = EmailSender::getActiveProvider();
	if ($provider === null) {
		$facts['outbound_local'] = true;
		$facts['outbound_label'] = 'local sendmail (no outbound provider configured)';
	} elseif ($provider instanceof SmtpProvider) {
		$host = strtolower(trim((string)Globalvars::get_instance()->get_setting('smtp_host')));
		if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
			$facts['outbound_local'] = true;
			$facts['outbound_label'] = 'SMTP via this box\'s own Postfix';
		}
	}

	return $facts;
}

/**
 * The guardrail matrix as a pure function (unit-tested directly): the refusal
 * reasons that block decommission, empty when the action may proceed.
 * Restore has no guardrails — bringing a listener back is always safe.
 *
 * @return string[] user-facing refusal reasons.
 */
function mailbox_listener_guardrail_failures(array $facts): array {
	$failures = array();
	if (empty($facts['relay_enabled'])) {
		$failures[] = 'No enabled relay fronts this deployment — the listener is its only way to receive mail.';
	} else {
		if (empty($facts['cutover_complete'])) {
			$failures[] = 'DNS has not fully cut over to the relay: '
				. ($facts['cutover_reason'] !== '' ? $facts['cutover_reason'] : 'not every domain\'s MX points at it.');
		}
		if (!empty($facts['spool_error'])) {
			$failures[] = 'The relay spool pull is not healthy — mail needs a proven path before the fallback listener goes: '
				. $facts['spool_error'];
		}
	}
	if (!empty($facts['outbound_local'])) {
		$failures[] = 'Sent mail currently leaves through ' . $facts['outbound_label']
			. ' — switch outbound to an API provider or the relay smarthost first.';
	}
	return $failures;
}

/**
 * Run one helper verb as root via the sudoers rule and demand its success
 * marker. Never throws — the caller flashes ok/message.
 *
 * @param string $verb 'off' | 'on'
 * @return array{ok:bool,message:string}
 */
function mailbox_listener_run(string $verb): array {
	$helper = mailbox_listener_helper_path();
	if (!is_file($helper)) {
		return array('ok' => false, 'message' =>
			'The listener helper is not installed on this box. Run once as root: '
			. 'sudo bash plugins/mailbox/provisioning/provision_relay_main.sh — then retry.');
	}
	$marker = ($verb === 'off') ? 'LISTENER_OFF' : 'LISTENER_ON';
	$output = array();
	$code = 1;
	exec('sudo -n ' . escapeshellarg($helper) . ' ' . escapeshellarg($verb) . ' 2>&1', $output, $code);
	$text = trim(implode("\n", $output));
	if ($code !== 0 || strpos($text, $marker) === false) {
		return array('ok' => false, 'message' =>
			'The listener helper did not complete (exit ' . intval($code) . '): '
			. ($text !== '' ? $text : 'no output') . ' — nothing was recorded; the listener state is unchanged.');
	}
	return array('ok' => true, 'message' => $text);
}

/**
 * The listener POST actions (Setup tab's Relay section). Returns a redirect
 * when the input was one of them, null otherwise. Decommission re-evaluates
 * the guardrails server-side — the button's absence is not the enforcement.
 */
function mailbox_listener_actions(array $input, $session, string $self_url): ?LogicResult {
	$action = $input['action'] ?? null;
	if ($action !== 'listener_decommission' && $action !== 'listener_restore') {
		return null;
	}
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));

	if ($action === 'listener_decommission') {
		$failures = mailbox_listener_guardrail_failures(mailbox_listener_guardrail_facts());
		if (!empty($failures)) {
			admin_mailbox_relay_flash($session,
				'Refused: ' . implode(' ', $failures), 'Listener not decommissioned');
			return LogicResult::redirect($self_url);
		}
		$run = mailbox_listener_run('off');
		if (!$run['ok']) {
			admin_mailbox_relay_flash($session, $run['message'], 'Listener not decommissioned');
			return LogicResult::redirect($self_url);
		}
		admin_mailbox_relay_write_setting('mailbox_local_listener', 'decommissioned');
		admin_mailbox_relay_flash($session,
			'Local mail is uninstalled: Postfix, opendkim and opendmarc are stopped and disabled, and port 25 is '
			. 'closed at the firewall. Mail reaches this server only through your relay.',
			'Local mail uninstalled');
		return LogicResult::redirect($self_url);
	}

	$run = mailbox_listener_run('on');
	if (!$run['ok']) {
		admin_mailbox_relay_flash($session, $run['message'], 'Listener not restored');
		return LogicResult::redirect($self_url);
	}
	admin_mailbox_relay_write_setting('mailbox_local_listener', 'active');
	admin_mailbox_relay_flash($session,
		'Local mail is reinstalled: Postfix, opendkim and opendmarc are running and port 25 is open again.',
		'Local mail reinstalled');
	return LogicResult::redirect($self_url);
}

/**
 * The local-mail block (Setup tab's Relay section). Shown only when there is
 * something actionable: the amber uninstall offer once every guardrail passes
 * (a refusal renders NOTHING — the Setup rows already walk the missing
 * pieces), and, after an uninstall, a small state line so Restore is always
 * reachable. The server-side guardrail re-check on POST stays the enforcement.
 */
function mailbox_listener_box_render($page, array $state): void {
	if ($state['setting'] === 'decommissioned') {
		if ($state['listening']) {
			echo '<div class="alert alert-danger" style="margin-top:1rem;">'
				. '<p>Local mail is recorded as uninstalled, but something answers on this server\'s port 25 — '
				. 'it came back outside this page.</p>'
				. PublicPageBase::action_button('Uninstall local mail', '', array(
					'hidden'  => array('action' => 'listener_decommission'),
					'confirm' => 'Uninstall local mail (Postfix, opendkim, opendmarc)? Mail keeps arriving through your relay.',
					'class'   => 'btn btn-danger btn-sm',
				))
				. '</div>';
			return;
		}
		echo '<div style="margin-top:1rem;">'
			. '<p class="text-muted">Local mail is uninstalled — mail reaches this server only through your relay. '
			. PublicPageBase::action_button('Reinstall local mail', '', array(
				'hidden'  => array('action' => 'listener_restore'),
				'confirm' => 'Reinstall local mail? Postfix, opendkim and opendmarc start again and port 25 reopens.',
				'class'   => 'btn btn-secondary btn-sm',
			))
			. '</p></div>';
		return;
	}

	// Active listener: offer the uninstall only when it is actually possible —
	// guardrail failures or a missing helper render nothing at all.
	if (!empty($state['guardrail_failures']) || !$state['helper_installed']) {
		return;
	}

	echo '<div class="alert alert-warning" style="margin-top:1rem;">'
		. '<p>Since your mail now comes through a relay, this server\'s own mail software '
		. '(Postfix, opendkim, opendmarc) is unnecessary and a potential security risk.</p>'
		. PublicPageBase::action_button('Uninstall local mail', '', array(
			'hidden'  => array('action' => 'listener_decommission'),
			'confirm' => 'Uninstall local mail (Postfix, opendkim, opendmarc)? Mail keeps arriving through your relay. '
				. 'You can reinstall it later.',
			'class'   => 'btn btn-warning btn-sm',
		))
		. '</div>';
}
?>
