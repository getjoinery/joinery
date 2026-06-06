<?php
/**
 * Logic for the IMAP account / mailbox editor.
 *
 * Two modes:
 *   - Plain feed editor (hosted-domain mailboxes): pick a bound mailbox from the
 *     dropdown; edit a feed by its account id.
 *   - Combined mailbox+feed editor (IMAP-source domains, domain_id present): the
 *     mailbox (a store-mode alias) is created/kept from the username under the
 *     domain, and a single "Edit" manages the mailbox name, its access grants,
 *     AND the feed together — creating the feed if the mailbox doesn't have one
 *     yet. This is what the Accounts tree's "+ Mailbox" and "Edit" point at for
 *     IMAP-source domains.
 *
 * Connection details for a known provider come from the preset catalog; the
 * app/basic password is a non-model field stored encrypted via setPassword().
 *
 * @version 2.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_inbound_email_imap_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_imap_account_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_mailbox_grant_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$settings = Globalvars::get_instance();

	$list_url = '/plugins/inbound_email/admin/admin_inbound_email_accounts';

	// Combined mode: reached via "+ Mailbox" / "Edit" on an IMAP-source domain.
	// domain_id rides through the POST as a hidden field; alias_id identifies an
	// existing mailbox being edited (0 when adding a new one).
	$domain_id = intval($input['domain_id'] ?? 0);
	$domain = $domain_id > 0 ? new InboundEmailDomain($domain_id, TRUE) : null;
	$combined = (bool)($domain && $domain->key);
	$alias_id = intval($input['alias_id'] ?? 0);

	// Load or create the account. In combined mode an alias_id with an existing
	// feed loads that feed (edit); without one, a new feed is created for it.
	$id = intval($input['edit_primary_key_value'] ?? $input['iia_inbound_imap_account_id'] ?? 0);
	if ($id > 0) {
		$account = new InboundImapAccount($id, TRUE);
		if (!$account->key) {
			return LogicResult::redirect($list_url);
		}
	} elseif ($combined && $alias_id > 0) {
		$feeds = new MultiInboundImapAccount(array('alias_id' => $alias_id, 'deleted' => false));
		$feeds->load();
		$account = count($feeds) ? new InboundImapAccount($feeds->get(0)->key, TRUE) : new InboundImapAccount(NULL);
	} else {
		$account = new InboundImapAccount(NULL);
	}

	// Alias options for the plain (hosted) feed editor's dropdown.
	$aliases = new MultiInboundEmailAlias(array('deleted' => false), array('iea_alias' => 'ASC'));
	$aliases->load();
	$alias_options = array();
	foreach ($aliases as $a) {
		$alias_options[$a->key] = $a->get_full_address();
	}

	// Provider options + per-provider field visibility.
	$provider_options = array();
	$visibility = array();
	foreach (InboundImapAccount::PRESETS as $key => $p) {
		$provider_options[$key] = $p['label'];
		$show = array(); $hide = array();
		if ($key === 'imap_generic') {
			$show = array('iia_imap_host', 'iia_imap_port', 'iia_imap_encryption');
		} else {
			$hide = array('iia_imap_host', 'iia_imap_port', 'iia_imap_encryption');
		}
		if ($p['auth'] === InboundImapAccount::AUTH_PASSWORD) {
			$show[] = 'imap_password';
		} else {
			$hide[] = 'imap_password';
		}
		$visibility[$key] = array('show' => $show, 'hide' => $hide);
	}

	// Combined mode also manages the mailbox's access grants. Staff who can be
	// granted, plus the bound mailbox's current grants (when editing).
	$user_options = array();
	$granted_user_ids = array();
	if ($combined) {
		$staff = new MultiUser(
			array('permission_range' => array(5, 10), 'deleted' => false, 'not_system_users' => true),
			array('usr_first_name' => 'ASC', 'usr_last_name' => 'ASC')
		);
		$staff->load();
		$user_options = $staff->get_dropdown_array();

		$grant_alias_id = $account->key ? intval($account->get('iia_iea_inbound_email_alias_id')) : $alias_id;
		if ($grant_alias_id > 0) {
			$grants = new MultiInboundEmailMailboxGrant(array('alias_id' => $grant_alias_id));
			$grants->load();
			foreach ($grants as $g) {
				$granted_user_ids[] = intval($g->get('ieg_usr_user_id'));
			}
		}
	}

	// Save.
	if (isset($input['iia_provider_key']) && ($input['_submitted'] ?? '') === '1') {
		$provider = $input['iia_provider_key'];
		if (!isset(InboundImapAccount::PRESETS[$provider])) {
			$provider = 'imap_generic';
		}
		$preset = InboundImapAccount::PRESETS[$provider];

		$account->set('iia_provider_key', $provider);
		$account->set('iia_label', trim((string)($input['iia_label'] ?? '')));
		$account->set('iia_username', trim((string)($input['iia_username'] ?? '')));
		// Import scope — changeable at any time. Turning ON full history resets the
		// cursor so the next fetch backfills the existing mailbox (dedup prevents
		// re-storing already-imported messages). Turning it off leaves
		// already-imported mail in place and just stops future backfilling.
		$wantFull = (($input['import_history'] ?? 'future') === 'full');
		$wasFull = (bool)$account->get('iia_import_history');
		$account->set('iia_import_history', $wantFull);
		if ($wantFull && !$wasFull) {
			$account->set('iia_uidvalidity', null);
			$account->set('iia_last_seen_uid', null);
		}
		$account->set('iia_imap_folder', trim((string)($input['iia_imap_folder'] ?? 'INBOX')) ?: 'INBOX');
		$account->set('iia_poll_interval_seconds', intval($input['iia_poll_interval_seconds'] ?? 300) ?: 300);
		$account->set('iia_is_enabled', isset($input['iia_is_enabled']) ? true : false);

		// Bind the mailbox. Combined mode resolves it from the username under the
		// domain (creating it / keeping it named to match); plain mode uses the
		// dropdown selection.
		$resolved_alias_id = 0;
		if ($combined) {
			$username = trim((string)($input['iia_username'] ?? ''));
			$local = strstr($username, '@', true);
			if ($local === false) { $local = $username; }
			$existing_alias = $account->key
				? intval($account->get('iia_iea_inbound_email_alias_id'))
				: $alias_id;
			$resolved_alias_id = _imap_edit_resolve_mailbox($domain_id, trim($local), $existing_alias);
			$account->set('iia_iea_inbound_email_alias_id', $resolved_alias_id);
		} else {
			$account->set('iia_iea_inbound_email_alias_id', intval($input['iia_iea_inbound_email_alias_id'] ?? 0) ?: null);
		}

		// Connection details: preset-driven for known hosts, user-supplied for generic.
		if ($provider === 'imap_generic') {
			$account->set('iia_imap_host', trim((string)($input['iia_imap_host'] ?? '')));
			$account->set('iia_imap_port', intval($input['iia_imap_port'] ?? 993) ?: 993);
			$enc = $input['iia_imap_encryption'] ?? 'ssl';
			$account->set('iia_imap_encryption', in_array($enc, array('ssl', 'tls', 'none'), true) ? $enc : 'ssl');
		} else {
			$account->set('iia_imap_host', $preset['host']);
			$account->set('iia_imap_port', $preset['port']);
			$account->set('iia_imap_encryption', $preset['encryption']);
		}

		// Password: only for password-auth providers; blank-on-edit keeps the existing.
		if ($preset['auth'] === InboundImapAccount::AUTH_PASSWORD) {
			$pw = (string)($input['imap_password'] ?? '');
			if ($pw !== '') {
				$account->setPassword($pw);
			}
		}

		try {
			$account->prepare();
			$account->save();
			$account->load();

			// Combined mode: sync the mailbox's access grants to the submitted set.
			if ($combined && $resolved_alias_id > 0) {
				$submitted = array();
				if (isset($input['users_with_access']) && is_array($input['users_with_access'])) {
					foreach ($input['users_with_access'] as $uid) {
						$submitted[] = intval($uid);
					}
				}
				InboundEmailMailboxGrant::sync_for_alias($resolved_alias_id, $submitted);
			}

			$is_oauth = $account->isOAuth();
			$msg = $is_oauth && !$account->hasOAuthToken()
				? 'Mailbox saved. Click "Connect" to authorize mailbox access.'
				: 'Mailbox saved.';
			$session->save_message(new DisplayMessage(
				$msg, 'Accounts', '/plugins/inbound_email/admin/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect($list_url);
		} catch (Throwable $e) {
			return LogicResult::render(array(
				'session' => $session, 'settings' => $settings, 'account' => $account,
				'alias_options' => $alias_options, 'provider_options' => $provider_options,
				'visibility' => $visibility, 'combined' => $combined, 'domain' => $domain,
				'bound_alias_id' => $alias_id, 'user_options' => $user_options,
				'granted_user_ids' => $granted_user_ids, 'error' => $e->getMessage(),
			));
		}
	}

	// New account defaults.
	if (!$account->key) {
		$account->set('iia_is_enabled', true);
		$account->set('iia_imap_port', 993);
		$account->set('iia_imap_encryption', 'ssl');
		$account->set('iia_imap_folder', 'INBOX');
		$account->set('iia_poll_interval_seconds', 300);
		$account->set('iia_provider_key', 'imap_generic');
		if ($combined) {
			// Default the provider from the IMAP-source domain (gmail.com -> Gmail).
			$account->set('iia_provider_key',
				InboundImapAccount::providerForEmailDomain($domain->get('ied_domain')) ?: 'imap_generic');
			// Editing an existing mailbox that has no feed yet — prefill the username
			// from its address so the operator doesn't retype it.
			if ($alias_id > 0) {
				$bound = new InboundEmailAlias($alias_id, TRUE);
				if ($bound->key) {
					$account->set('iia_username', $bound->get_full_address());
				}
			}
		} elseif (!empty($input['alias_id'])) {
			$account->set('iia_iea_inbound_email_alias_id', intval($input['alias_id']));
		}
	}

	return LogicResult::render(array(
		'session' => $session, 'settings' => $settings, 'account' => $account,
		'alias_options' => $alias_options, 'provider_options' => $provider_options,
		'visibility' => $visibility, 'combined' => $combined, 'domain' => $domain,
		'bound_alias_id' => $alias_id, 'user_options' => $user_options,
		'granted_user_ids' => $granted_user_ids,
	));
}

/**
 * Resolve the store-mode mailbox (alias) for a combined feed under $domain_id,
 * named after the username's local part. If $existing_alias_id is given it is
 * kept (renamed to match $local when needed); otherwise an existing same-named
 * mailbox is reused or a new one created. Returns the alias id.
 */
function _imap_edit_resolve_mailbox(int $domain_id, string $local, int $existing_alias_id = 0): int {
	if ($local === '') {
		throw new InboundEmailAliasException('Enter the mailbox username (the address to poll).');
	}

	if ($existing_alias_id > 0) {
		$alias = new InboundEmailAlias($existing_alias_id, TRUE);
		if ($alias->key) {
			if (strcasecmp((string)$alias->get('iea_alias'), $local) !== 0) {
				$alias->set('iea_alias', $local);
				$alias->prepare();
				$alias->save();
			}
			return intval($alias->key);
		}
	}

	$existing = new MultiInboundEmailAlias(array('domain_id' => $domain_id, 'alias' => $local, 'deleted' => false));
	$existing->load();
	if (count($existing)) {
		return intval($existing->get(0)->key);
	}

	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', $local);
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_is_enabled', true);
	$alias->prepare();
	$alias->save();
	$alias->load();
	return intval($alias->key);
}
?>
