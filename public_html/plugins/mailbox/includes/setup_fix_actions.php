<?php
/**
 * One-click fixes posted from a check row, shared by the Setup tab and the
 * Server tab.
 *
 * A check row's fix button (setup_check_render.php) posts back to the page it
 * is on, and the same row can appear on either tab, so both tabs' logic hands
 * the POST here first. Each fix writes through a model or the settings table
 * and redirects, so the next render reads fresh state.
 *
 * @version 1.0
 */

if (!function_exists('mailbox_setup_fix_action')) {

/**
 * Run the one-click fix this POST names, if it names one.
 *
 * @param array  $posted       The POST (never the query string).
 * @param mixed  $session      SessionControl.
 * @param string $redirect_url Where to land afterwards: the page the button was on.
 * @return LogicResult|null A redirect when the POST was a fix, null when it was not.
 */
function mailbox_setup_fix_action(array $posted, $session, string $redirect_url): ?LogicResult {
	$action = $posted['action'] ?? null;
	if (!is_string($action) || $action === '') {
		return null;
	}
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
	$settings = Globalvars::get_instance();

	$announce = function ($msg, $title) use ($session) {
		$session->save_message(new DisplayMessage(
			$msg, $title, '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	};

	if ($action === 'enable_plugin') {
		mailbox_setup_write_setting('mailbox_enabled', '1');
		$announce('Inbound email is now enabled.', 'Enabled');
		return LogicResult::redirect($redirect_url);
	}

	if ($action === 'enable_srs') {
		mailbox_setup_write_setting('mailbox_srs_enabled', '1');
		// Generate a signing secret only if one is not already set, so
		// re-running the fix never rotates a live secret.
		if (trim((string)$settings->get_setting('mailbox_srs_secret')) === '') {
			mailbox_setup_write_setting('mailbox_srs_secret', bin2hex(random_bytes(24)));
		}
		$announce('SRS is now enabled with a signing secret.', 'Enabled');
		return LogicResult::redirect($redirect_url);
	}

	if ($action === 'add_domain') {
		$domain_name = strtolower(trim($posted['domain'] ?? ''));
		$domain_id = 0;
		if ($domain_name !== '') {
			try {
				$existing = InboundEmailDomain::GetByDomain($domain_name);
				if ($existing) {
					$domain_id = (int)$existing->key;
				} else {
					$domain = new InboundEmailDomain(NULL);
					$domain->set('ied_domain', $domain_name);
					$domain->set('ied_is_enabled', true);
					$domain->set('ied_reject_unmatched', true);
					$domain->prepare();
					$domain->save();
					$domain_id = (int)$domain->key;
					// A fleet-fronted deployment files the domain's ownership
					// challenge at registration, so the Setup tab's ownership
					// row carries a publishable record immediately.
					require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
					(new FleetClient())->fileDomainClaims($domain_name);
				}
				$announce('Domain "' . $domain_name . '" registered.', 'Domain added');
			} catch (InboundEmailDomainException $e) {
				$announce('Could not add domain: ' . $e->getMessage(), 'Error');
			}
		}
		// Land on the new domain's setup with the publish diff already on
		// screen: adding a domain should leave exactly the decisions that
		// deserve a human, not a checklist to go and find.
		if ($domain_id > 0) {
			return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_setup?domain_id='
				. $domain_id . '&dns_show=1');
		}
		return LogicResult::redirect($redirect_url);
	}

	// --- Machine sender (specs/mailbox_machine_sender_card.md) ---

	// Register mail.<domain> at the outbound provider. Idempotent — re-pressing
	// on a registered domain announces success and changes nothing. DKIM
	// authority stays on the subdomain itself (the provider implementation
	// guarantees it) so its keys align strictly.
	if ($action === 'machine_register') {
		$machine_domain = strtolower(trim($posted['domain'] ?? ''));
		$class = EmailSender::activeServiceKey() !== ''
			? (EmailSender::getDiscoveredProviders()[EmailSender::activeServiceKey()] ?? null) : null;
		if ($machine_domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $machine_domain)) {
			$announce('No valid machine domain to register.', 'Error');
		} elseif ($class === null
				|| !in_array('SendingDomainRegistrar', class_implements($class) ?: array(), true)) {
			$announce('The configured outbound provider cannot register sending domains from here — '
				. 'add ' . $machine_domain . ' in its dashboard instead.', 'Not available');
		} else {
			$result = $class::createSendingDomain($machine_domain);
			if (($result['status'] ?? '') === 'ok') {
				$announce($machine_domain . ' is registered at ' . $class::getLabel()
					. '. Publish its DNS records next.', 'Registered');
			} else {
				$announce('Could not register ' . $machine_domain . ' at ' . $class::getLabel() . ': '
					. ($result['error'] ?? $result['status'] ?? 'unknown error'), 'Error');
			}
		}
		return LogicResult::redirect($redirect_url);
	}

	// Prove the whole ambient path — guard, provider, alignment — with one
	// real send to the operator. The one sub-check that cannot be inferred.
	if ($action === 'machine_test_send') {
		$acting = new User((int)$session->get_user_id(), TRUE);
		$to = $acting->key ? trim((string)$acting->get('usr_email')) : '';
		if ((string)$settings->get_setting('email_dry_run') === '1') {
			$announce('Email dry run is on — a test send would be suppressed, which proves nothing. '
				. 'Turn email_dry_run off first.', 'Dry run is on');
			return LogicResult::redirect($redirect_url);
		}
		if ($to === '') {
			$announce('Your account has no email address to send the test to.', 'Error');
			return LogicResult::redirect($redirect_url);
		}
		try {
			$ok = EmailSender::quickSend($to, 'Test: automated mail from this site',
				'This is a test of the site\'s automated mail identity, sent from the mail admin. '
				. 'Check the From address and the authentication results (SPF, DKIM, DMARC) in the '
				. 'message headers.');
			$announce($ok
				? 'Test email sent to ' . $to . '. Check its headers for SPF/DKIM/DMARC results.'
				: 'The test email could not be sent — it was queued for retry. Check the Sending route '
					. 'row on the Server tab.', $ok ? 'Sent' : 'Queued');
		} catch (Exception $e) {
			$announce('The test send was refused: ' . $e->getMessage(), 'Refused');
		}
		return LogicResult::redirect($redirect_url);
	}

	// Destroy the ordinary on-disk signing key for a protected domain. Never
	// automatic and never a side effect of anything else: this deletes key
	// material and cannot be undone from a browser. The gates are the two
	// facts that make it safe — sending is already locked to the sealed key,
	// and the DNS shape that makes that stick is published and verified.
	if ($action === 'destroy_local_key') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));
		$domain_name = strtolower(trim($posted['domain'] ?? ''));
		// GetByDomain() returns FALSE on a miss, never null.
		$model = $domain_name !== '' ? InboundEmailDomain::GetByDomain($domain_name) : false;
		if (!$model) {
			$announce('No such domain on this server.', 'Error');
			return LogicResult::redirect($redirect_url);
		}
		if (!$model->is_protected_identity()) {
			$announce('Send protection is not on for ' . $domain_name . '. Until it is, this key is what '
				. 'signs the domain\'s mail — destroying it would leave the domain signing nothing.', 'Not yet');
			return LogicResult::redirect($redirect_url);
		}
		$shape = (new InboundEmailSetupCheck())->protectedDomainChecks($model);
		foreach ($shape as $row) {
			if ($row['severity'] === InboundEmailSetupCheck::REQUIRED
					&& $row['status'] !== InboundEmailSetupCheck::PASS) {
				$announce('The protected DNS records for ' . $domain_name . ' are not all verified yet ('
					. $row['label'] . '). Destroying the on-disk key now could leave mail unsigned on a path '
					. 'that is still in use.', 'Not yet');
				return LogicResult::redirect($redirect_url);
			}
		}
		$result = mailbox_destroy_local_signing_key($domain_name);
		$announce($result['message'], $result['ok'] ? 'Destroyed' : 'Error');
		return LogicResult::redirect($redirect_url);
	}

	return null;
}

/**
 * Upsert a single stg_settings row by name. Settings are written through the
 * Setting model (there is no set_setting()); a missing row is created.
 */
function mailbox_setup_write_setting(string $name, string $value): void {
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
