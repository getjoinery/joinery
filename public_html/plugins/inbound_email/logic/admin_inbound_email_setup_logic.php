<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Logic for the Inbound Email guided Setup tab.
 *
 * Default render: runs InboundEmailSetupCheck and returns the result rows.
 * POST actions: save the mail hostname / public IP, enable the plugin, or
 * register a domain — each writes through a model and redirects so the next
 * render reads fresh settings.
 */
function admin_inbound_email_setup_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailSetupCheck.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$base = '/plugins/inbound_email/admin/admin_inbound_email_setup';
	$address = isset($input['address']) ? strtolower(trim($input['address'])) : '';
	$redirect_url = ($address !== '') ? $base . '?address=' . urlencode($address) : $base;

	$announce = function ($msg, $title) use ($session) {
		$session->save_message(new DisplayMessage(
			$msg, $title, '/plugins/inbound_email/admin/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	};

	// --- POST action: save mail hostname / public IP ---
	if (isset($input['mail_hostname']) || isset($input['public_ip'])) {
		inbound_email_setup_write_setting('inbound_email_mail_hostname', strtolower(trim($input['mail_hostname'] ?? '')));
		inbound_email_setup_write_setting('inbound_email_public_ip', trim($input['public_ip'] ?? ''));
		$announce('Setup details saved.', 'Saved');
		return LogicResult::redirect($redirect_url);
	}

	// --- POST action: one-click fixes ---
	if (isset($input['action'])) {
		if ($input['action'] === 'enable_plugin') {
			inbound_email_setup_write_setting('inbound_email_enabled', '1');
			$announce('Inbound email is now enabled.', 'Enabled');
			return LogicResult::redirect($redirect_url);
		}

		if ($input['action'] === 'add_domain') {
			$domain_name = strtolower(trim($input['domain'] ?? ''));
			if ($domain_name !== '') {
				try {
					if (!InboundEmailDomain::GetByDomain($domain_name)) {
						$domain = new InboundEmailDomain(NULL);
						$domain->set('ied_domain', $domain_name);
						$domain->set('ied_is_enabled', true);
						$domain->set('ied_reject_unmatched', true);
						$domain->prepare();
						$domain->save();
					}
					$announce('Domain "' . $domain_name . '" registered.', 'Domain added');
				} catch (InboundEmailDomainException $e) {
					$announce('Could not add domain: ' . $e->getMessage(), 'Error');
				}
			}
			return LogicResult::redirect($redirect_url);
		}
	}

	// --- Default render: run the check suite ---
	$focus_domain = '';
	if ($address !== '' && strpos($address, '@') !== false) {
		$focus_domain = strtolower(trim(explode('@', $address, 2)[1]));
	}

	$checker = new InboundEmailSetupCheck();
	$results = $checker->run(
		$focus_domain !== '' ? $focus_domain : null,
		$address !== '' ? $address : null
	);

	return LogicResult::render(array(
		'session'           => $session,
		'settings'          => $settings,
		'results'           => $results,
		'counts'            => InboundEmailSetupCheck::summarize($results),
		'address'           => $address,
		'focus_domain'      => $focus_domain,
		'mail_hostname'     => $checker->getMailHostname(),
		'public_ip'         => $checker->getPublicIp(),
		'public_ip_private' => $checker->publicIpIsPrivate(),
		// The raw setting (empty = autodetect) — distinct from the detected IP
		// above, so the form field shows the override state, not the result.
		'configured_public_ip' => trim((string)$settings->get_setting('inbound_email_public_ip')),
	));
}

/**
 * Upsert a single stg_settings row by name. Settings are written through the
 * Setting model (there is no set_setting()); a missing row is created.
 */
function inbound_email_setup_write_setting(string $name, string $value): void {
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
?>
