<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Logic for the Inbound Email Setup tab.
 *
 * The page is mailbox-first: an admin picks one of the mailboxes already
 * registered on the Accounts tab, and the checks are scoped to that mailbox —
 * a "Receiving" group always, and a "Forwarding" group only when the mailbox
 * forwards. The server-wide diagnostics (inbound provider, this server's mail
 * hostname/IP, and the full Postfix/relay health run) live behind the Advanced
 * disclosure (?advanced=1); they are useful but not per-mailbox, so they stay
 * out of the default view.
 *
 * POST actions: save the mail hostname / public IP, switch provider, enable the
 * plugin, enable SRS, register a domain, or apply a one-click fix — each writes
 * through a model and redirects so the next render reads fresh settings.
 *
 * @version 2.0
 */
function admin_inbound_email_setup_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailSetupCheck.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundProviderRegistry.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$base = '/plugins/inbound_email/admin/admin_inbound_email_setup';
	$selected_alias_id = isset($input['alias_id']) ? (int)$input['alias_id'] : 0;
	$advanced = !empty($input['advanced']);

	// Build a redirect URL that keeps the chosen mailbox and the advanced state,
	// so a POST action returns the operator to exactly where they were.
	$state_qs = function (array $over = array()) use ($base, $selected_alias_id, $advanced) {
		$alias = array_key_exists('alias_id', $over) ? $over['alias_id'] : $selected_alias_id;
		$adv   = array_key_exists('advanced', $over) ? $over['advanced'] : $advanced;
		$parts = array();
		if ($alias) { $parts[] = 'alias_id=' . (int)$alias; }
		if ($adv)   { $parts[] = 'advanced=1'; }
		return $base . ($parts ? '?' . implode('&', $parts) : '');
	};
	$redirect_url = $state_qs();

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
		return LogicResult::redirect($state_qs(array('advanced' => true)));
	}

	// --- POST action: one-click fixes ---
	if (isset($input['action'])) {
		if ($input['action'] === 'enable_plugin') {
			inbound_email_setup_write_setting('inbound_email_enabled', '1');
			$announce('Inbound email is now enabled.', 'Enabled');
			return LogicResult::redirect($redirect_url);
		}

		if ($input['action'] === 'enable_srs') {
			inbound_email_setup_write_setting('inbound_email_srs_enabled', '1');
			// Generate a signing secret only if one is not already set, so
			// re-running the fix never rotates a live secret.
			if (trim((string)$settings->get_setting('inbound_email_srs_secret')) === '') {
				inbound_email_setup_write_setting('inbound_email_srs_secret', bin2hex(random_bytes(24)));
			}
			$announce('SRS is now enabled with a signing secret.', 'Enabled');
			return LogicResult::redirect($redirect_url);
		}

		if ($input['action'] === 'set_provider') {
			$key = trim((string)($input['provider'] ?? ''));
			if ($key !== '' && InboundProviderRegistry::get($key) !== null) {
				inbound_email_setup_write_setting('inbound_email_provider', $key);
				$announce('Inbound provider switched to ' . $key . '.', 'Provider');
			}
			return LogicResult::redirect($state_qs(array('advanced' => true)));
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

	// --- Mailbox picker: every enabled, registered mailbox (Accounts tab) ---
	$mailbox_options = array();   // alias_id => display label
	$mailbox_index   = array();   // alias_id => meta about the mailbox
	$domains = new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC'));
	$domains->load();
	foreach ($domains as $domain) {
		$is_imap = (bool)$domain->get('ied_is_imap_source');
		$aliases = new MultiInboundEmailAlias(
			array('domain_id' => $domain->key, 'deleted' => false, 'enabled' => true),
			array('iea_alias' => 'ASC')
		);
		$aliases->load();
		foreach ($aliases as $alias) {
			$mode    = $alias->get('iea_delivery_mode') ?: InboundEmailAlias::MODE_FORWARD;
			$address = strtolower($alias->get('iea_alias') . '@' . $domain->get('ied_domain'));
			$tag = ($mode === InboundEmailAlias::MODE_STORE) ? 'Mailbox'
				: (($mode === InboundEmailAlias::MODE_FORWARD_AND_STORE) ? 'Forward + store' : 'Forward');
			if ($is_imap) { $tag .= ' · IMAP'; }
			$mailbox_options[$alias->key] = $address . '  —  ' . $tag;
			$mailbox_index[$alias->key] = array(
				'address'   => $address,
				'domain'    => strtolower($domain->get('ied_domain')),
				'is_imap'   => $is_imap,
				'mode'      => $mode,
				'forwards'  => in_array($mode, array(InboundEmailAlias::MODE_FORWARD, InboundEmailAlias::MODE_FORWARD_AND_STORE), true),
			);
		}
	}

	// Default to the first mailbox so the page is never an empty shell.
	if (!isset($mailbox_options[$selected_alias_id])) {
		$selected_alias_id = $mailbox_options ? (int)array_key_first($mailbox_options) : 0;
	}

	// --- Inbound provider (global) — needed for arrival shape + Advanced ---
	$provider_classes = InboundProviderRegistry::all();
	$provider_options = array();
	foreach ($provider_classes as $key => $class) {
		$provider_options[$key] = $class::getLabel();
	}
	$active_provider_key = trim((string)$settings->get_setting('inbound_email_provider'));
	if ($active_provider_key === '') { $active_provider_key = 'postfix'; }
	$active_provider_class = $provider_classes[$active_provider_key] ?? null;
	$active_provider_is_webhook = $active_provider_class ? $active_provider_class::isWebhook() : false;

	// --- Scoped checks for the chosen mailbox ---
	$selected       = $selected_alias_id > 0;
	$address        = '';
	$focus_domain   = '';
	$arrival        = '';          // 'imap' | 'webhook' | 'postfix'
	$forwards       = false;
	$mode           = '';
	$receiving_rows = array();
	$forwarding_rows = array();
	$selected_imap  = null;

	if ($selected) {
		$meta         = $mailbox_index[$selected_alias_id];
		$address      = $meta['address'];
		$focus_domain = $meta['domain'];
		$mode         = $meta['mode'];
		$forwards     = $meta['forwards'];
		$arrival      = $meta['is_imap'] ? 'imap' : ($active_provider_is_webhook ? 'webhook' : 'postfix');

		if ($arrival === 'imap') {
			// IMAP-pull mailboxes have no MX/host stack — receiving is "is the
			// feed connected and fetching". Build those rows from the feed model.
			$feeds = new MultiInboundImapAccount(array('alias_id' => $selected_alias_id, 'deleted' => false));
			$feeds->load();
			$selected_imap = count($feeds) ? $feeds->get(0) : null;
			$receiving_rows = _setup_imap_receiving_rows($selected_imap);

			// Forwarding still applies if the mailbox forwards; those checks are
			// server-global (relay/SRS), so a domain-less run supplies them.
			if ($forwards) {
				$all = (new InboundEmailSetupCheck())->run(null, null);
				foreach ($all as $r) {
					if (_setup_is_forwarding_row($r)) { $forwarding_rows[] = $r; }
				}
			}
		} else {
			$all = (new InboundEmailSetupCheck())->run($focus_domain, $address);
			foreach ($all as $r) {
				if ($forwards && _setup_is_forwarding_row($r)) {
					$forwarding_rows[] = $r;
				} elseif (_setup_is_receiving_row($r)) {
					$receiving_rows[] = $r;
				}
			}
		}
	}

	// --- Advanced (server-wide) — only run the full suite when expanded ---
	$results     = array();
	$dns_records = array();
	$checker     = new InboundEmailSetupCheck();
	if ($advanced) {
		$results = $checker->run($focus_domain !== '' ? $focus_domain : null, $address !== '' ? $address : null);
		if ($active_provider_class && $focus_domain !== '') {
			$dns_records = $active_provider_class::getDnsRecords($focus_domain);
		}
	}

	// Webhook URL — shown in Advanced when the active provider is a webhook.
	$webhook_url = '';
	if ($active_provider_is_webhook) {
		$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ($host !== '') {
			$webhook_url = $scheme . '://' . $host . '/ajax/inbound_email_webhook?provider=' . rawurlencode($active_provider_key);
		}
	}

	return LogicResult::render(array(
		'session'                    => $session,
		'settings'                   => $settings,
		'base'                       => $base,
		// Mailbox-first view
		'mailbox_options'            => $mailbox_options,
		'selected_alias_id'          => $selected_alias_id,
		'selected'                   => $selected,
		'address'                    => $address,
		'focus_domain'               => $focus_domain,
		'arrival'                    => $arrival,
		'mode'                       => $mode,
		'forwards'                   => $forwards,
		'receiving_rows'             => $receiving_rows,
		'forwarding_rows'            => $forwarding_rows,
		'selected_imap'              => $selected_imap,
		// Advanced
		'advanced'                   => $advanced,
		'results'                    => $results,
		'dns_records'                => $dns_records,
		'provider_options'           => $provider_options,
		'active_provider_key'        => $active_provider_key,
		'active_provider_is_webhook' => $active_provider_is_webhook,
		'webhook_url'                => $webhook_url,
		'mail_hostname'              => $checker->getMailHostname(),
		'public_ip'                  => $checker->getPublicIp(),
		'public_ip_private'          => $checker->publicIpIsPrivate(),
		'configured_public_ip'       => trim((string)$settings->get_setting('inbound_email_public_ip')),
	));
}

/**
 * Forwarding (outbound) rows: the relay, SRS, and DKIM signing — the only
 * checks that matter when a mailbox forwards mail back out.
 */
function _setup_is_forwarding_row(array $r): bool {
	return in_array($r['id'], array('plugin.srs_secret', 'plugin.relay', 'domain.dkim', 'host.opendkim'), true);
}

/**
 * Receiving rows for a mailbox: the domain's inbound DNS, the inbound-auth
 * verifier, that the plugin is on, that the alias resolves, and the end-to-end
 * proof. Server-internal host/mailhost rows are intentionally excluded — they
 * live in the Advanced server view.
 */
function _setup_is_receiving_row(array $r): bool {
	if ($r['id'] === 'domain.dkim') { return false; }      // DKIM signing is outbound
	if ($r['layer'] === 'domain')   { return true; }
	if ($r['layer'] === 'address')  { return true; }
	if ($r['layer'] === 'e2e')      { return true; }
	return in_array($r['id'], array('plugin.enabled', 'host.inbound_verification'), true);
}

/**
 * Synthetic receiving rows for an IMAP-pull mailbox, derived from its feed.
 * Mirrors the row shape InboundEmailSetupCheck::r() produces so the same
 * renderer handles them.
 */
function _setup_imap_receiving_rows(?InboundImapAccount $imap): array {
	$row = function ($status, $label, $summary, $detail = '', $fix = null) {
		return array(
			'id' => 'imap.' . strtolower(str_replace(' ', '_', $label)), 'scope' => '', 'layer' => 'imap',
			'label' => $label, 'severity' => InboundEmailSetupCheck::REQUIRED, 'status' => $status,
			'summary' => $summary, 'detail' => $detail, 'fix' => $fix, 'recheckable' => true,
		);
	};
	$accounts_link = array('text' => 'Manage this mailbox on the Accounts tab.');

	if (!$imap) {
		return array($row(InboundEmailSetupCheck::FAIL, 'IMAP feed',
			'This mailbox has no IMAP feed configured.',
			'An IMAP-source mailbox needs a feed to pull mail. Add one from the Accounts tab.', $accounts_link));
	}

	$out = array();
	if ($imap->isOAuth() && !$imap->hasOAuthToken()) {
		$out[] = $row(InboundEmailSetupCheck::FAIL, 'IMAP connection',
			'The mailbox is not connected yet.',
			'Press "Connect" on the Accounts tab to authorize access.', $accounts_link);
	} elseif ($imap->needsReauth()) {
		$out[] = $row(InboundEmailSetupCheck::FAIL, 'IMAP connection',
			'The stored authorization has expired and needs to be renewed.',
			'Press "Reconnect" on the Accounts tab.', $accounts_link);
	} else {
		$out[] = $row(InboundEmailSetupCheck::PASS, 'IMAP connection', 'The mailbox is connected.');
	}

	if (!$imap->get('iia_is_enabled')) {
		$out[] = $row(InboundEmailSetupCheck::WARN, 'Feed enabled',
			'Fetching is turned off for this feed.',
			'Enable it from the Accounts tab to resume pulling mail.', $accounts_link);
	}

	// Sync mode (specs/two_way_imap_sync.md §8): report Off / Read-only / Two-way
	// and the CONDSTORE requirement so the operator sees why sync may be unavailable.
	$modeLabels = array(
		InboundImapAccount::SYNC_OFF  => 'Off (one-time import)',
		InboundImapAccount::SYNC_PULL => 'Read-only (follow the source)',
		InboundImapAccount::SYNC_BOTH => 'Two-way (full sync)',
	);
	$mode = $imap->syncMode();
	if ($mode === InboundImapAccount::SYNC_OFF) {
		$summary = $imap->supportsCondstore()
			? 'Sync is off; this feed does a one-time import only.'
			: 'Sync is off. This server does not advertise CONDSTORE, so only one-time import is available.';
		$out[] = $row(InboundEmailSetupCheck::INFO, 'Sync', $summary);
	} else {
		$out[] = $row(InboundEmailSetupCheck::PASS, 'Sync', $modeLabels[$mode]);
	}

	$last = trim((string)$imap->get('iia_last_status'));
	if ($last !== '') {
		$out[] = $row(InboundEmailSetupCheck::INFO, 'Last fetch', $last);
	}

	return $out;
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
