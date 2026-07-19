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

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

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
			$return_url = '/plugins/mailbox/admin/admin_mailbox_domains?ied_inbound_email_domain_id=' . (int)$domain->key;
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

		// Vault gates (structural, not policy). The ingest seal gate requires a
		// vault (InboundEmailRouter::storeMessage), so a sealing level with no
		// vault would label the domain protected while every message still stores
		// plaintext — a false promise. Refuse the raise until a vault exists; the
		// guided ceremony (Phase 3) creates one, opens the window, and re-runs
		// this save. With a vault, raising and lowering both need the key open.
		$acting_user_id = intval($session->get_user_id());
		$acting_vault = ($acting_user_id > 0) ? UserEncryptionVault::loadForUser($acting_user_id) : null;
		if ($raising && $new_seals) {
			if ($acting_vault === null) {
				return $level_error('Set up your vault before choosing Private or Fortress — create it in your account security settings, then set the level. Until then this domain would be labeled protected while its mail stays unencrypted.');
			}
			if (!VaultUnlock::isOpen($acting_user_id)) {
				return $level_error('Unlock your vault before raising protection on this domain.');
			}
		}
		if ($lowering && $old_seals && $acting_vault !== null && !VaultUnlock::isOpen($acting_user_id)) {
			return $level_error('Unlock your vault before lowering protection on this domain.');
		}

		$domain->set('ied_security_level', $new_level);

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

			// Fortress records the level immediately (sealing at ingest is safe
			// from this moment) but never writes ied_is_protected_identity here —
			// the verify-gated protect ceremony flips that after DNS proves the
			// protected shape. Route the operator into that ceremony.
			if ($new_level === InboundEmailDomain::LEVEL_FORTRESS && !$domain->is_protected_identity()) {
				$session->save_message(new DisplayMessage(
					'Domain saved. Finish Fortress setup: publish the protected DNS shape and activate outbound protection.',
					'Saved',
					'~/plugins/mailbox/admin/~',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_protect?ied_inbound_email_domain_id=' . (int)$domain->key);
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

	return LogicResult::render(array(
		'edit_domain' => $edit_domain,
		'domain_type' => $domain_type,
		'session' => $session,
		'settings' => $settings,
	));
}
?>
