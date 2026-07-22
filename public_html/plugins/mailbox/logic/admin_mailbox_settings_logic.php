<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Logic for the Inbound Email Settings tab.
 *
 * Server-wide DELIVERY POLICY — spam filtering, forwarding limits, the
 * forwarded-From display, and retention/storage caps. These are distinct from
 * the Setup tab, which owns provisioning and server identity (provider, mail
 * hostname/IP, SRS, the relay, and the health run). One POST saves the whole
 * form; values are read back fresh on the redirect.
 *
 * @version 1.2
 */
function admin_mailbox_settings_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$base = '/plugins/mailbox/admin/admin_mailbox_settings';

	// The settings this page owns. Booleans render as checkboxes; integers as
	// number inputs clamped to a sensible floor.
	$bool_keys = array(
		'mailbox_spam_filtering_enabled',
		'mailbox_from_show_via',
	);
	$int_keys = array(
		'mailbox_forwarding_max_destinations'      => 1,
		'mailbox_forwarding_rate_limit_per_alias'  => 0,
		'mailbox_forwarding_rate_limit_per_domain' => 0,
		'mailbox_forwarding_rate_limit_window'     => 1,
		'mailbox_log_retention_days'               => 0,
		'mailbox_retention_days'           => 0,
		'mailbox_max_per_window'           => 0,
	);

	if (!empty($input['save_settings'])) {
		// Unchecked checkboxes are absent from the POST → '0'.
		foreach ($bool_keys as $k) {
			mailbox_settings_write_setting($k, empty($input[$k]) ? '0' : '1');
		}

		// Learning renders DISABLED when no scanner is running (a disabled
		// checkbox never posts), so its absence is only "unchecked" while the
		// scanner is present — otherwise saving would stomp the stored
		// preference with '0' every time.
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));
		if (MailboxSpamPolicy::controllerReachable()) {
			mailbox_settings_write_setting('mailbox_spam_learning_enabled',
				empty($input['mailbox_spam_learning_enabled']) ? '0' : '1');
		}
		foreach ($int_keys as $k => $min) {
			$value = max($min, intval($input[$k] ?? 0));
			mailbox_settings_write_setting($k, (string)$value);
		}

		// Relay configuration — saved only when its box rendered (the fields
		// are absent otherwise, and absence must not blank stored values).
		if (array_key_exists('mailbox_fleet_service_url', $input)) {
			mailbox_settings_write_setting('mailbox_fleet_service_url',
				trim((string)$input['mailbox_fleet_service_url']));
			mailbox_settings_write_setting('mailbox_fleet_api_public_key',
				trim((string)($input['mailbox_fleet_api_public_key'] ?? '')));
			$secret = trim((string)($input['mailbox_fleet_api_secret_key'] ?? ''));
			if ($secret !== '') { // blank keeps the stored secret
				mailbox_settings_write_setting('mailbox_fleet_api_secret_key', $secret);
			}
		}

		$outbound_note = '';
		if (array_key_exists('mailbox_relay_outbound_mode', $input)) {
			$prior = (strtolower(trim((string)$settings->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost')
				? 'smarthost' : 'provider';
			$mode = ($input['mailbox_relay_outbound_mode'] === 'smarthost') ? 'smarthost' : 'provider';
			mailbox_settings_write_setting('mailbox_relay_outbound_mode', $mode);
			// The relay's Postfix submission listener is baked at provision time,
			// so a mode switch takes effect on the relay itself only at the next
			// Rebuild (Setup tab). The tunnel check fails honestly until then.
			if ($mode === 'smarthost' && $prior !== 'smarthost') {
				$outbound_note = ' Sent mail now leaves through the relay smarthost — this deployment owns the '
					. 'relay IP\'s sending reputation. Run Rebuild on the relay (Setup tab) to open its tunnel '
					. 'submission listener; until then compose sends are refused.';
			} elseif ($mode === 'provider' && $prior === 'smarthost') {
				$outbound_note = ' Sent mail now leaves through your email provider. The relay\'s submission '
					. 'listener stays open until its next Rebuild.';
			}
		}

		$session->save_message(new DisplayMessage(
			'Settings saved.' . $outbound_note, 'Saved', '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect($base);
	}

	// Current values for the form.
	$values = array();
	foreach ($bool_keys as $k) {
		$values[$k] = (string)$settings->get_setting($k) === '1';
	}
	$values['mailbox_spam_learning_enabled'] =
		(string)$settings->get_setting('mailbox_spam_learning_enabled') === '1';
	foreach ($int_keys as $k => $min) {
		$values[$k] = intval($settings->get_setting($k));
	}

	// Relay configuration state. The connection box renders once the
	// deployment's receive mode is relay (or a connection/relay already
	// exists); the outbound box only once a relay is active.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
	$fleet_url = trim((string)$settings->get_setting('mailbox_fleet_service_url'));
	$values['mailbox_fleet_service_url']    = $fleet_url;
	$values['mailbox_fleet_api_public_key'] = trim((string)$settings->get_setting('mailbox_fleet_api_public_key'));
	$fleet_secret_set = trim((string)$settings->get_setting('mailbox_fleet_api_secret_key')) !== '';
	$outbound_mode = (strtolower(trim((string)$settings->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost')
		? 'smarthost' : 'provider';
	// The connection box is hosted-relay-only, so it is also gated behind the
	// hosted offering's launch flag.
	$show_relay_config = mailbox_hosted_relay_offered()
		&& ((mailbox_receive_mode() === 'relay')
			|| mailbox_receive_relay_exists() || $fleet_url !== '');

	// Where spam scanning happens is shown, not asked — it follows from the
	// provider and topology already configured. Learning is offered only where
	// a scanner is actually running (observed, not stored): the scanner ships
	// with the mail stack, so on any box that hosts its own mail this is
	// simply true; a webhook-only or relay-fronted-from-birth box never ran a
	// root script of ours and gets a disabled checkbox with the reason instead
	// of a command to paste.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));
	$upstream = MailboxSpamPolicy::upstreamScanner();
	$scanner_present = MailboxSpamPolicy::controllerReachable();
	if (!MailboxSpamPolicy::filingEnabled()) {
		$scanner_state = 'Spam filing is off, so nothing is being moved out of the inbox. '
			. 'Every message still records its SPF, DKIM and DMARC results, so turning it '
			. 'on takes effect on the next message.';
	} else {
		switch ($upstream) {
			case 'provider':
				$scanner_state = 'Mail is scanned by your email provider before it reaches this server.';
				break;
			case 'relay':
				$scanner_state = 'Mail is scanned by your relay before it reaches this server.';
				break;
			default:
				$scanner_state = 'This server receives mail directly, so it scans mail itself.';
		}
		if (MailboxSpamPolicy::learningEnabled() && $upstream !== 'none') {
			$scanner_state .= ' Because this deployment is learning, it scores that mail again'
				. ' itself using what it has learned.';
		}
		if (!$scanner_present) {
			$scanner_state .= ' No spam scanner is running on this server, so learning from '
				. 'user corrections is unavailable.';
		}
	}

	return LogicResult::render(array(
		'session'                 => $session,
		'base'                    => $base,
		'values'                  => $values,
		'show_relay_config'       => $show_relay_config,
		'fleet_secret_set'        => $fleet_secret_set,
		'has_active_relay'        => (MailboxRelay::active() !== null),
		'outbound_mode'           => $outbound_mode,
		'scanner_state'           => $scanner_state,
		'scanner_present'         => $scanner_present,
	));
}

/**
 * Upsert a single stg_settings row by name (the same model path Setup uses —
 * there is no set_setting()). A missing row is created.
 */
if (!function_exists('mailbox_settings_write_setting')) {
	function mailbox_settings_write_setting(string $name, string $value): void {
		$existing = new MultiSetting(array('setting_name' => $name));
		$existing->load();
		if (count($existing)) {
			$setting = $existing->get(0);
		} else {
			$setting = new Setting(NULL);
			$setting->set('stg_name', $name);
		}
		$setting->set('stg_value', $value);
		$setting->save();
	}
}
?>
