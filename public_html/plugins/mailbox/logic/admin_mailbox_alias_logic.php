<?php
/**
 * @version 1.2 - any user can be given access: the list shows staff and whoever holds a grant, and
 *   add_access_user (the site's user search) adds anyone else, a member included
 * @version 1.1 - forwarding is refused where the mailbox's level does not offer it; a saved
 *   forwarding destination is asked to confirm, and the editor can resend the request
 *   (specs/relay_receive_only_forwarding.md)
 */
function admin_mailbox_alias_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grants_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	// Load or create alias
	if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
		$alias = new InboundEmailAlias($input['edit_primary_key_value'], TRUE);
		$alias->assert_found();
	} elseif (isset($input['iea_inbound_email_alias_id'])) {
		$alias = new InboundEmailAlias($input['iea_inbound_email_alias_id'], TRUE);
		$alias->assert_found();
	} else {
		$alias = new InboundEmailAlias(NULL);
	}

	// Resend a forwarding confirmation request (a single-button form under the
	// editor). Handled before the save path, and returns from it.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/forward_confirmation_panel.php'));
	if ($alias->key && ($input['action'] ?? '') === 'resend_forward_confirmation') {
		$msg = mailbox_forward_confirmation_resend($input, intval($alias->get('iea_ied_inbound_email_domain_id')),
			intval($alias->key), $alias->get_destinations_array());
		$session->save_message(new DisplayMessage($msg, 'Forwarding', '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_alias?iea_inbound_email_alias_id=' . intval($alias->key));
	}

	// Process form submission
	if ($input && isset($input['iea_alias'])) {
		// Vault-gated settings pull-forward (specs/implemented/inbound_email_
		// encryption_at_rest.md, from specs/mailbox_security_levels.md § Vault-
		// Gated Settings): capture the pre-edit destinations/mode so a real
		// change to either can be gated below — a rename or grant-list edit
		// with no routing change is never gated.
		$was_edit = (bool)$alias->key;
		$old_destinations = $was_edit ? (string)$alias->get('iea_destinations') : null;
		$old_mode = $was_edit ? (string)$alias->get('iea_delivery_mode') : null;

		$editable_fields = array('iea_ied_inbound_email_domain_id', 'iea_alias', 'iea_destinations', 'iea_description', 'iea_delivery_mode');
		foreach ($editable_fields as $field) {
			if (isset($input[$field])) {
				$value = $input[$field];
				// People often paste the whole address into the mailbox field;
				// keep only the local part before the @.
				if ($field === 'iea_alias') {
					$at = strpos($value, '@');
					if ($at !== false) {
						$value = substr($value, 0, $at);
					}
				}
				$alias->set($field, $value);
			}
		}

		$alias->set('iea_is_enabled', isset($input['iea_is_enabled']) ? true : false);

		// Submitted access grants (checkboxList → users_with_access[]).
		$submitted_grant_users = array();
		if (isset($input['users_with_access']) && is_array($input['users_with_access'])) {
			foreach ($input['users_with_access'] as $uid) {
				$submitted_grant_users[] = intval($uid);
			}
		}
		// One more user picked in the user search (add_access_user), staff or not.
		$added_user_id = intval($input['add_access_user'] ?? 0);
		if ($added_user_id > 0 && !in_array($added_user_id, $submitted_grant_users, true)) {
			$added_user = new User($added_user_id, TRUE);
			if (!$added_user->key || $added_user->get('usr_delete_time')) {
				return LogicResult::render(array(
					'alias' => $alias,
					'error' => 'The user picked to be given access no longer exists. Pick them again.',
					'session' => $session,
					'settings' => $settings,
					'domains' => new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')),
					'user_options' => _mailbox_alias_user_options($submitted_grant_users),
					'granted_user_ids' => $submitted_grant_users,
				));
			}
			$submitted_grant_users[] = $added_user_id;
		}

		// Protected-domain invariants enforce at the mutation point
		// (specs/mailbox_protection_ceremony.md § 2b): the ceremony guards the
		// raise, and this refusal makes the raised state impossible to corrupt
		// afterward — never merely alarmed about.
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
		$alias_domain = new InboundEmailDomain(intval($alias->get('iea_ied_inbound_email_domain_id')), TRUE);
		// The MAILBOX answers where it exists — its own level may differ from its
		// domain's (specs/mailbox_connect_flow.md § D).
		$protected_error = mailbox_protected_grant_error($alias_domain, $submitted_grant_users,
			$alias->key ? $alias : null);
		if ($protected_error !== null) {
			return LogicResult::render(array(
				'alias' => $alias,
				'error' => $protected_error,
				'session' => $session,
				'settings' => $settings,
				'domains' => new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')),
				'user_options' => _mailbox_alias_user_options($submitted_grant_users),
				'granted_user_ids' => $submitted_grant_users,
			));
		}

		// The routing change this mailbox's owner must actively consent to
		// (an open unlock window) — see the capture above. A new alias has no
		// established owner/grant relationship yet, so it is never gated.
		if ($was_edit) {
			$destinations_changed = ((string)$alias->get('iea_destinations') !== $old_destinations);
			$mode_changed = ((string)$alias->get('iea_delivery_mode') !== $old_mode);
			if ($destinations_changed || $mode_changed) {
				$locked_msg = _mailbox_alias_require_unlock(intval($alias->key));
				if ($locked_msg !== null) {
					return LogicResult::render(array(
						'alias' => $alias,
						'error' => $locked_msg,
						'session' => $session,
						'settings' => $settings,
						'domains' => new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')),
						'user_options' => _mailbox_alias_user_options($submitted_grant_users),
						'granted_user_ids' => $submitted_grant_users,
					));
				}
			}
		}

		// A mailbox sealed to a key this server does not hold (Fortress, or Seal
		// at the relay) is store-only: a forward would hand the recipient's
		// provider a readable copy.
		if ($alias->mode_forwards() && !$alias->forwarding_offered()) {
			return LogicResult::render(array(
				'alias' => $alias,
				'error' => 'This mailbox cannot forward. Its mail is sealed to a key this server does not hold '
					. '(Fortress, or Seal at the relay), and a forward would hand the receiving provider a readable copy. '
					. 'Choose "Store locally".',
				'session' => $session,
				'settings' => $settings,
				'domains' => new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')),
				'user_options' => _mailbox_alias_user_options($submitted_grant_users),
				'granted_user_ids' => $submitted_grant_users,
			));
		}

		try {
			$alias->prepare();
			$alias->save();
			$alias->load();

			// Every new forwarding destination is asked to confirm before
			// anything is forwarded to it.
			ForwardConfirmation::requestMissingForAlias($alias);

			// Sync the mailbox's access list to exactly the submitted set.
			InboundEmailMailboxGrant::sync_for_alias($alias->key, $submitted_grant_users);

			$session->save_message(new DisplayMessage(
				'Alias saved.',
				'Saved',
				'~/plugins/mailbox/admin/~',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_accounts');
		} catch (InboundEmailMailboxGrantException $e) {
			// The grant layer refused the resulting holder set on a protected
			// mailbox. The pre-check above catches this for anything the form can
			// express; this is the same refusal arriving from the write itself, so
			// it reads the same way rather than as a crash.
			return LogicResult::render(array(
				'alias' => $alias,
				'error' => $e->getMessage(),
				'session' => $session,
				'settings' => $settings,
				'domains' => new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')),
				'user_options' => _mailbox_alias_user_options($submitted_grant_users),
				'granted_user_ids' => $submitted_grant_users,
			));
		} catch (InboundEmailAliasException $e) {
			return LogicResult::render(array(
				'alias' => $alias,
				'error' => $e->getMessage(),
				'session' => $session,
				'settings' => $settings,
				'domains' => new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')),
				'user_options' => _mailbox_alias_user_options($submitted_grant_users),
				'granted_user_ids' => $submitted_grant_users,
			));
		}
	}

	// Load domains for dropdown
	$domains = new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC'));
	$domains->load();

	// A new alias defaults to enabled — the common case, so the operator does
	// not have to remember to tick the box.
	if (!$alias->key) {
		$alias->set('iea_is_enabled', true);
		// Pre-select the domain when "+ Mailbox" was clicked under a domain in the
		// Accounts tree (?domain_id=N).
		if (!empty($input['domain_id'])) {
			$alias->set('iea_ied_inbound_email_domain_id', intval($input['domain_id']));
		}
	}

	// Pre-select users who currently hold a grant for this mailbox.
	$granted_user_ids = array();
	if ($alias->key) {
		$grants = new MultiInboundEmailMailboxGrant(array('alias_id' => $alias->key));
		$grants->load();
		foreach ($grants as $g) {
			$granted_user_ids[] = intval($g->get('ieg_usr_user_id'));
		}
	}

	return LogicResult::render(array(
		'alias' => $alias,
		'session' => $session,
		'settings' => $settings,
		'domains' => $domains,
		'user_options' => _mailbox_alias_user_options($granted_user_ids),
		'granted_user_ids' => $granted_user_ids,
	));
}

/**
 * The users the access list shows as checkboxes, id => label: every staff
 * user (permission 5 and above), and everyone in $granted_ids whatever their
 * level, so a member given access can be seen and taken off. Anyone else is
 * added through the page's user search.
 */
function _mailbox_alias_user_options(array $granted_ids): array {
	$staff = new MultiUser(
		array('permission_range' => array(5, 10), 'deleted' => false, 'not_system_users' => true),
		array('usr_first_name' => 'ASC', 'usr_last_name' => 'ASC')
	);
	$options = $staff->get_dropdown_array();
	foreach ($granted_ids as $uid) {
		$uid = intval($uid);
		if ($uid <= 0 || isset($options[$uid])) {
			continue;
		}
		$user = new User($uid, TRUE);
		if ($user->key) {
			$options[$uid] = $user->display_name() . ' - ' . $user->login_label();
		}
	}
	return $options;
}

/**
 * Vault-gated settings pull-forward — see the identical guard in
 * mailbox_filters_logic.php for the full rationale. A destination or
 * delivery-mode change reroutes this mailbox's future mail before it is ever
 * sealed, so it requires an open unlock window — which, since
 * VaultUnlock::isOpen() is scoped to the calling session, only the mailbox
 * owner's own session can ever satisfy.
 */
function _mailbox_alias_require_unlock(int $alias_id): ?string {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_messages_class.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

	$owner_id = InboundEmailMessage::singleOwnerUserId($alias_id);
	if ($owner_id === null || !UserEncryptionVault::loadForUser($owner_id)) {
		return null;
	}
	if (!VaultUnlock::isOpen($owner_id)) {
		return 'This mailbox is sealed. Its owner must unlock their vault before its destinations or delivery mode can change.';
	}
	return null;
}
?>
