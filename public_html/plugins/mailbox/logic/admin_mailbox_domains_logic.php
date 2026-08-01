<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_domains_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	$gate_redirect = mailbox_receive_gate_handle($input);
	if ($gate_redirect !== null) {
		return $gate_redirect;
	}

	// Level ordering: raising crosses into sealing; lowering leaves it.
	$level_rank = array(
		InboundEmailDomain::LEVEL_STANDARD => 0,
		InboundEmailDomain::LEVEL_PRIVATE  => 1,
		InboundEmailDomain::LEVEL_FORTRESS => 2,
	);

	// True when any alias on the domain has more than one live grant — today's
	// structural proxy for a group-collaboration mailbox, which cannot be raised
	// above Standard (specs/mailbox_security_levels.md § The Unit of Choice).
	$domain_has_group_mailbox = function($domain_id) {
		$aliases = new MultiInboundEmailAlias(array('domain_id' => $domain_id, 'deleted' => false));
		$aliases->load();
		foreach ($aliases as $alias) {
			$grantees = InboundEmailMailboxGrant::user_ids_for_alias(intval($alias->key));
			if (count($grantees) > 1) {
				return true;
			}
		}
		return false;
	};

	// The domain editor is reached from the Accounts tree; saves and the bare
	// (non-editing) view both land back on Accounts.
	$accounts_url = '/plugins/mailbox/admin/admin_mailbox_accounts';
	$editor_base = '/plugins/mailbox/admin/admin_mailbox_domains';

	// --- Protection ceremony inline fixes (specs/mailbox_protection_ceremony.md) ---
	// Both act on a domain being edited and land back on its editor so the
	// checklist re-evaluates immediately.
	if ($input && ($input['action'] ?? '') === 'ceremony_remove_grant') {
		$domain_id = intval($input['ied_inbound_email_domain_id'] ?? 0);
		$alias = new InboundEmailAlias(intval($input['alias_id'] ?? 0), TRUE);
		if ($alias->key && intval($alias->get('iea_ied_inbound_email_domain_id')) === $domain_id) {
			$remaining = array();
			foreach (InboundEmailMailboxGrant::user_ids_for_alias(intval($alias->key)) as $uid) {
				if (intval($uid) !== intval($input['user_id'] ?? 0)) {
					$remaining[] = intval($uid);
				}
			}
			InboundEmailMailboxGrant::sync_for_alias($alias->key, $remaining);
		}
		return LogicResult::redirect($editor_base . '?ied_inbound_email_domain_id=' . $domain_id);
	}
	// Claim ownership of the domain — the inline fix for the domain_owner row
	// (specs/mailbox_unmatched_sealing.md). Mail arriving for an address with no
	// mailbox seals to whoever owns the domain, so a sealing level needs one.
	// Only ever assigns the ACTING admin: this is a deliberate claim, never a
	// picker that could hand someone else's key duty to them without their
	// knowledge. Refused when the domain already has an owner, so it can never
	// be used to take a domain from someone.
	if ($input && ($input['action'] ?? '') === 'ceremony_set_domain_owner') {
		$domain_id = intval($input['ied_inbound_email_domain_id'] ?? 0);
		$claim = new InboundEmailDomain($domain_id, TRUE);
		$acting = intval($session->get_user_id());
		if ($claim->key && $acting > 0 && intval($claim->get('ied_owner_usr_user_id')) <= 0) {
			$claim->set('ied_owner_usr_user_id', $acting);
			$claim->save();
		}
		return LogicResult::redirect($editor_base . '?ied_inbound_email_domain_id=' . $domain_id);
	}
	if ($input && ($input['action'] ?? '') === 'ceremony_seal_batch') {
		$domain_id = intval($input['ied_inbound_email_domain_id'] ?? 0);
		$domain = new InboundEmailDomain($domain_id, TRUE);
		if ($domain->key && $domain->seals_content()) {
			mailbox_protection_seal_batch($domain);
		}
		return LogicResult::redirect($editor_base . '?ied_inbound_email_domain_id=' . $domain_id
			. '&sealed_now=1');
	}
	if ($input && ($input['action'] ?? '') === 'ceremony_unseal_batch') {
		$domain_id = intval($input['ied_inbound_email_domain_id'] ?? 0);
		$domain = new InboundEmailDomain($domain_id, TRUE);
		if ($domain->key && !$domain->seals_content()) {
			mailbox_protection_unseal_batch($domain, intval($session->get_user_id()));
		}
		return LogicResult::redirect($editor_base . '?ied_inbound_email_domain_id=' . $domain_id
			. '&unsealed_now=1');
	}

	// Known IMAP-source provider domains (shared with the IMAP editor). Selecting
	// one of these "Type" options implies the domain (the local part is the
	// mailbox), flags it IMAP-source, and skips the catch-all/MX flow. "custom" is
	// a hosted domain; "imap_generic" is an IMAP source whose domain the operator
	// types (e.g. a Workspace domain).
	$imap_type_domains = InboundImapAccount::PROVIDER_EMAIL_DOMAINS;

	// Handle form submission (add/edit domain)
	if ($input && isset($input['domain_type'])) {
		if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
			$domain = new InboundEmailDomain($input['edit_primary_key_value'], TRUE);
		} else {
			$domain = new InboundEmailDomain(NULL);
		}

		$type = $input['domain_type'];
		$is_imap = ($type !== 'custom');
		$domain_name = isset($imap_type_domains[$type])
			? $imap_type_domains[$type]
			: trim((string)($input['ied_domain'] ?? ''));

		$domain->set('ied_domain', $domain_name);
		$domain->set('ied_is_enabled', isset($input['ied_is_enabled']) ? true : false);
		$domain->set('ied_is_imap_source', $is_imap);
		if ($is_imap) {
			// IMAP-source domains route per mailbox — no catch-all.
			$domain->set('ied_catch_all_mode', 'forward');
			$domain->set('ied_catch_all_address', '');
			$domain->set('ied_reject_unmatched', false);
		} else {
			$domain->set('ied_catch_all_mode', $input['ied_catch_all_mode'] ?? 'forward');
			$domain->set('ied_catch_all_address', $input['ied_catch_all_address'] ?? '');
			$domain->set('ied_reject_unmatched', isset($input['ied_reject_unmatched']) ? true : false);
		}

		// --- Security level (specs/mailbox_security_levels.md Phase 2) ---
		$old_level = $domain->key ? $domain->security_level() : InboundEmailDomain::LEVEL_STANDARD;
		$new_level = strtolower(trim((string)($input['ied_security_level'] ?? InboundEmailDomain::LEVEL_STANDARD)));
		if (!isset($level_rank[$new_level])) {
			$new_level = InboundEmailDomain::LEVEL_STANDARD;
		}
		// Fortress is meaningless for an IMAP-source domain — the picker hides the
		// card, but the save guards it too (a stale POST cannot smuggle it in).
		// Fall back to Standard (the no-obligations default), never silently to a
		// different protected level the user did not choose.
		if ($is_imap && $new_level === InboundEmailDomain::LEVEL_FORTRESS) {
			$new_level = InboundEmailDomain::LEVEL_STANDARD;
		}

		$raising = ($level_rank[$new_level] > $level_rank[$old_level]);
		$lowering = ($level_rank[$new_level] < $level_rank[$old_level]);
		$new_seals = in_array($new_level, array(InboundEmailDomain::LEVEL_PRIVATE, InboundEmailDomain::LEVEL_FORTRESS), true);
		$old_seals = in_array($old_level, array(InboundEmailDomain::LEVEL_PRIVATE, InboundEmailDomain::LEVEL_FORTRESS), true);

		$level_error = function($message) use ($domain, $type, $session, $settings) {
			return LogicResult::render(array(
				'error' => $message,
				'edit_domain' => $domain,
				'domain_type' => $type,
				'session' => $session,
				'settings' => $settings,
			));
		};

		// A domain security-level change is a sensitive administration action
		// (specs/mailbox_security_levels.md § 5.5): re-confirm the account's second
		// factor first. Only on an actual change to an existing domain — the
		// initial level at creation is a plain choice, not a change. The step-up
		// redirects to the ceremony and returns to this domain's editor; the user
		// re-submits, now recently confirmed.
		if ($domain->key && $new_level !== $old_level) {
			// target_level rides the return URL so the editor preselects the
			// chosen card after the ceremony — the lost form POST must not
			// silently discard the operator's intent.
			$return_url = '/plugins/mailbox/admin/admin_mailbox_domains?ied_inbound_email_domain_id=' . (int)$domain->key
				. '&target_level=' . rawurlencode($new_level);
			$stepup = $session->require_recent_second_factor($return_url);
			if ($stepup !== null) {
				return $stepup;
			}
		}

		// Group-collaboration constraint (firm): a domain hosting a shared mailbox
		// cannot be raised above Standard — the one-operator/one-key model every
		// protected level rests on does not cover multi-reader sealing.
		if ($level_rank[$new_level] > 0 && $domain->key && $domain_has_group_mailbox($domain->key)) {
			return $level_error('This domain hosts a shared (group) mailbox, so it can only use the Standard level.');
		}

		// Ceremony verification (specs/mailbox_protection_ceremony.md): a raise
		// into a sealing level is refused until every required prerequisite row
		// passes — the reader-vault rows are evaluated per HOLDER (the sealing
		// target, InboundEmailRouter::storeMessage keys off the holder's vault),
		// never the admin running the save. The editor's checklist renders these
		// same rows with in-place fixes; this re-verification is the enforcement,
		// the button state is the convenience.
		$acting_user_id = intval($session->get_user_id());
		if ($raising && $new_seals && $domain->key) {
			$rows = mailbox_protection_rows(mailbox_protection_facts($domain, $acting_user_id), $new_level, $acting_user_id);
			if (!mailbox_protection_required_ok($rows)) {
				return $level_error(mailbox_protection_first_failure($rows));
			}
		}
		// Lowering a sealing level needs the acting user's key open — an idle
		// admin session must not quietly downgrade protection.
		$acting_vault = ($acting_user_id > 0) ? UserEncryptionVault::loadForUser($acting_user_id) : null;
		if ($lowering && $old_seals && $acting_vault !== null && !VaultUnlock::isOpen($acting_user_id)) {
			return $level_error('Unlock your vault before lowering protection on this domain.');
		}

		$domain->set('ied_security_level', $new_level);

		// --- AI consent (specs/in_window_deferred_work.md § Turning it on has to
		// be a deliberate choice) ---
		// On a sealed level this decides whether the AI email features may read
		// this domain's mail during an unlock window. It changes what the
		// security level means in practice, so switching it ON needs the same
		// fresh identity check other vault-consequential changes require.
		// Turning it OFF is always allowed: withdrawing consent must never be
		// harder than giving it.
		$old_ai = $domain->key ? (bool)$domain->get('ied_ai_processing_enabled') : false;
		$new_ai = isset($input['ied_ai_processing_enabled']);
		if ($new_ai && !$new_seals) {
			$new_ai = false;   // meaningless at Standard; never store a stale yes
		}
		if ($new_ai && !$old_ai) {
			// Same ceremony the level change above uses: redirect to the step-up,
			// return to this editor, and let the operator re-submit now confirmed.
			// target_ai rides the return URL so the checkbox is still ticked when
			// they land back here — the lost POST must not discard their intent.
			//
			// SessionControl rather than PasskeyService: both read the same
			// session-bound `stepup` marker in pks_passkey_ceremonies, but this
			// needs no WebAuthn library. PasskeyService implements a library
			// interface at class-definition time, so merely requiring it pulls in
			// composer's autoloader, which this route has no other reason to load.
			// It also counts a TOTP step-up, which "recent step-up" always meant.
			$ai_return = '/plugins/mailbox/admin/admin_mailbox_domains?ied_inbound_email_domain_id='
				. (int)$domain->key . '&target_level=' . rawurlencode($new_level) . '&target_ai=1';
			$ai_stepup = $session->require_recent_second_factor($ai_return);
			if ($ai_stepup !== null) {
				return $ai_stepup;
			}
		}
		$domain->set('ied_ai_processing_enabled', $new_ai);

		// The narrower consent: may that reading leave the box? It can only be
		// on where the first is on — consenting to cloud processing for mail the
		// AI may not read at all is a stale yes waiting to surprise someone. It
		// rides the same step-up as the first, which the operator has just
		// passed if they are turning both on together.
		$new_cloud = isset($input['ied_ai_cloud_enabled']);
		if ($new_cloud && !$new_ai) {
			$new_cloud = false;
		}
		$domain->set('ied_ai_cloud_enabled', $new_cloud);

		try {
			$domain->prepare();
			$domain->save();

			// A fleet-fronted deployment files a new hosted domain's ownership
			// challenge at registration, so the Setup tab's ownership row
			// carries a publishable record immediately.
			if (!$is_imap) {
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
				(new FleetClient())->fileDomainClaims($domain_name);
			}

			// A level change may alter the acting user's max posture — drop the
			// session cache so the Fortress mandatory-2FA gate (§ 5.3) re-evaluates.
			unset($_SESSION['max_security_level']);

			// A raise into a sealing level converges the backlog: earlier mail was
			// stored plaintext (sealing is per-row) and "my mail is now private"
			// must not be quietly untrue for history. Every raise lands on the
			// editor's receipt card (specs/mailbox_raise_receipt.md) — it runs
			// the sealing batches in place and resolves into the completed
			// facts; no flash message, the card is the whole voice. The one
			// exception is a zero-backlog Fortress raise, which has nothing to
			// seal and a required next step — it routes straight into the
			// protect ceremony below.
			if ($raising && $new_seals) {
				$fortress_handoff = ($new_level === InboundEmailDomain::LEVEL_FORTRESS
					&& !$domain->is_protected_identity());
				if (mailbox_protection_backlog_count((int)$domain->key) > 0 || !$fortress_handoff) {
					return LogicResult::redirect($editor_base . '?ied_inbound_email_domain_id=' . (int)$domain->key
						. '&sealed_now=1');
				}
			}

			// A lowering out of the sealing levels converges history back to
			// plaintext (specs/mailbox_lowering_unseal.md): land on the
			// lowering receipt, which unseals the acting user's rows in place
			// (the vault-open gate above guaranteed their window is open) and
			// names any other holders' rows that must wait for their sessions.
			if ($lowering && $old_seals && !$new_seals) {
				return LogicResult::redirect($editor_base . '?ied_inbound_email_domain_id=' . (int)$domain->key
					. '&unsealed_now=1');
			}

			// Fortress records the level immediately (sealing at ingest is safe
			// from this moment) but never writes ied_is_protected_identity here —
			// the verify-gated protect ceremony flips that after DNS proves the
			// protected shape. Land on Setup focused on this domain, not on the
			// ceremony itself: Setup lists every remaining step (vault, protect,
			// relay, automated-mail subdomain), and it is a place the operator
			// can navigate back to. The ceremony is one button away from there.
			if ($new_level === InboundEmailDomain::LEVEL_FORTRESS && !$domain->is_protected_identity()) {
				// Make the signing key here rather than asking for it. Sealing needs
				// only the owner's public key, so no unlock window is required, and
				// a key that exists publishes nothing and changes no mail — the
				// operator's confirmation belongs on activate, where enforcement
				// starts. Skipped when the domain has mailbox holders: the key binds
				// to ONE person for good, and the admin raising the level need not
				// be the person who reads the mail. The protect page asks then.
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protect_identity.php'));
				if ((string)$domain->get('ied_dkim_sealed_key') === ''
						&& mailbox_protect_owner_is_unambiguous($domain, $acting_user_id)) {
					$key_error = mailbox_protect_seal_new_key($domain, $acting_user_id);
					if ($key_error !== null) {
						error_log('mailbox: Fortress raise could not seal a signing key for '
							. $domain_name . ': ' . $key_error);
					}
				}
				$session->save_message(new DisplayMessage(
					'Domain saved. Finish Fortress setup: publish the protected DNS shape and activate outbound protection.',
					'Saved',
					'~/plugins/mailbox/admin/~',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_setup?domain_id=' . (int)$domain->key);
			}

			$session->save_message(new DisplayMessage(
				'Domain saved.',
				'Saved',
				'~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect($accounts_url);
		} catch (InboundEmailDomainException $e) {
			return LogicResult::render(array(
				'error' => $e->getMessage(),
				'edit_domain' => $domain,
				'domain_type' => $type,
				'session' => $session,
				'settings' => $settings,
			));
		}
	}

	// Handle delete/undelete/permanent_delete actions
	if ($input && isset($input['action'])) {
		$action = $input['action'];
		$domain_id = $input['ied_inbound_email_domain_id'] ?? null;

		if ($domain_id && in_array($action, ['delete', 'undelete', 'permanent_delete'])) {
			$domain = new InboundEmailDomain($domain_id, TRUE);

			if ($action === 'delete') {
				// Soft-delete domain and cascade to aliases
				$domain->soft_delete();

				$aliases = new MultiInboundEmailAlias([
					'domain_id' => $domain->key,
					'deleted' => false,
				]);
				$aliases->load();
				foreach ($aliases as $alias) {
					$alias->soft_delete();
				}

				$alias_count = $aliases->count();
				$msg = 'Domain deleted' . ($alias_count ? " along with {$alias_count} alias(es)." : '.');
				$session->save_message(new DisplayMessage(
					$msg, 'Deleted',
					'~/plugins/mailbox/admin/~',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			} else if ($action === 'undelete') {
				// Restore aliases deleted at the same time or after the domain
				$domain_delete_time = $domain->get('ied_delete_time');
				$domain->undelete();

				if ($domain_delete_time) {
					$dbconnector = DbConnector::get_instance();
					$dblink = $dbconnector->get_db_link();
					$sql = "UPDATE iea_inbound_email_aliases
							SET iea_delete_time = NULL
							WHERE iea_ied_inbound_email_domain_id = ?
							AND iea_delete_time >= ?";
					$q = $dblink->prepare($sql);
					$q->execute([$domain->key, $domain_delete_time]);
					$restored_count = $q->rowCount();
				}

				$msg = 'Domain restored' . (!empty($restored_count) ? " along with {$restored_count} alias(es)." : '.');
				$session->save_message(new DisplayMessage(
					$msg, 'Restored',
					'~/plugins/mailbox/admin/~',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			} else if ($action === 'permanent_delete') {
				$session->check_permission(10);
				$domain->permanent_delete();

				$session->save_message(new DisplayMessage(
					'Domain permanently deleted.',
					'Deleted',
					'~/plugins/mailbox/admin/~',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			}

			return LogicResult::redirect($accounts_url);
		}
	}

	// Load domain for editing, and derive the "Type" the editor's dropdown shows.
	$edit_domain = null;
	$domain_type = 'custom';
	if (isset($input['ied_inbound_email_domain_id'])) {
		$edit_domain = new InboundEmailDomain($input['ied_inbound_email_domain_id'], TRUE);
		if ($edit_domain->get('ied_is_imap_source')) {
			$reverse = array_flip($imap_type_domains);
			$domain_type = $reverse[strtolower((string)$edit_domain->get('ied_domain'))] ?? 'imap_generic';
		}
	}

	// The standalone domain list is retired — the Accounts tree is the list. Only
	// the add/edit form is served here; any bare visit bounces to Accounts.
	$is_add = (($input['action'] ?? '') === 'add');
	if (!$edit_domain && !$is_add) {
		return LogicResult::redirect($accounts_url);
	}

	// Ceremony state for the editor (specs/mailbox_protection_ceremony.md): the
	// checklist for each raise target, and the backlog-sealing progress state.
	$ceremony = null;
	if ($edit_domain && $edit_domain->key) {
		$acting_user_id = intval($session->get_user_id());
		$facts = mailbox_protection_facts($edit_domain, $acting_user_id);
		$backlog = mailbox_protection_backlog_count(intval($edit_domain->key));
		$ceremony = array(
			'facts' => $facts,
			'rows_private' => mailbox_protection_rows($facts, InboundEmailDomain::LEVEL_PRIVATE, $acting_user_id),
			'rows_fortress' => mailbox_protection_rows($facts, InboundEmailDomain::LEVEL_FORTRESS, $acting_user_id),
			'backlog' => $backlog,
			'sealed_total' => mailbox_protection_sealed_count(intval($edit_domain->key)),
			'acting_user_id' => $acting_user_id,
			// The receipt card renders on arrival from a raise (sealed_now) or
			// whenever a protected domain has unsealed rows — not only right
			// after a raise — so a backlog that appears later (e.g. a vault
			// recreated after deletion) converges on the next editor visit.
			'sealing_active' => $edit_domain->seals_content()
				&& ($backlog > 0 || !empty($input['sealed_now'])),
			'editor_url' => $editor_base . '?ied_inbound_email_domain_id=' . intval($edit_domain->key),
		);
		// Lowering receipt state (specs/mailbox_lowering_unseal.md): a domain
		// that no longer seals but still carries sealed history converges it —
		// on arrival from the lowering (unsealed_now) or any later visit while
		// leftovers remain.
		if (!$edit_domain->seals_content()) {
			$unseal_counts = mailbox_protection_unseal_counts($edit_domain, $acting_user_id);
			$ceremony['unseal_own_backlog'] = $unseal_counts['own'];
			$ceremony['unseal_others_backlog'] = $unseal_counts['others'];
			$ceremony['unseal_active'] = ($unseal_counts['own'] + $unseal_counts['others'] > 0)
				|| !empty($input['unsealed_now']);
			$ceremony['window_open'] = ($acting_user_id > 0) && VaultUnlock::isOpen($acting_user_id);
		} else {
			$ceremony['unseal_active'] = false;
		}
	}

	return LogicResult::render(array(
		'edit_domain' => $edit_domain,
		'domain_type' => $domain_type,
		'session' => $session,
		'settings' => $settings,
		'ceremony' => $ceremony,
	));
}
?>
