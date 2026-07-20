<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_alias_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	$gate_redirect = mailbox_receive_gate_handle($input);
	if ($gate_redirect !== null) {
		return $gate_redirect;
	}

	// Staff users who can be granted access to this mailbox. The reader is
	// staff-only in v1, so the access list is scoped to permission >= 5; the
	// grant model itself is generic and supports non-admin members later.
	$staff = new MultiUser(
		array('permission_range' => array(5, 10), 'deleted' => false, 'not_system_users' => true),
		array('usr_first_name' => 'ASC', 'usr_last_name' => 'ASC')
	);
	$staff->load();
	$user_options = $staff->get_dropdown_array();

	// Load or create alias
	if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
		$alias = new InboundEmailAlias($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['iea_inbound_email_alias_id'])) {
		$alias = new InboundEmailAlias($input['iea_inbound_email_alias_id'], TRUE);
	} else {
		$alias = new InboundEmailAlias(NULL);
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

		// Protected-domain invariants enforce at the mutation point
		// (specs/mailbox_protection_ceremony.md § 2b): the ceremony guards the
		// raise, and this refusal makes the raised state impossible to corrupt
		// afterward — never merely alarmed about.
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
		$alias_domain = new InboundEmailDomain(intval($alias->get('iea_ied_inbound_email_domain_id')), TRUE);
		$protected_error = mailbox_protected_grant_error($alias_domain, $submitted_grant_users);
		if ($protected_error !== null) {
			return LogicResult::render(array(
				'alias' => $alias,
				'error' => $protected_error,
				'session' => $session,
				'settings' => $settings,
				'domains' => new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')),
				'user_options' => $user_options,
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
						'user_options' => $user_options,
						'granted_user_ids' => $submitted_grant_users,
					));
				}
			}
		}

		try {
			$alias->prepare();
			$alias->save();
			$alias->load();

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
		} catch (InboundEmailAliasException $e) {
			return LogicResult::render(array(
				'alias' => $alias,
				'error' => $e->getMessage(),
				'session' => $session,
				'settings' => $settings,
				'domains' => new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC')),
				'user_options' => $user_options,
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
		'user_options' => $user_options,
		'granted_user_ids' => $granted_user_ids,
	));
}

/**
 * Vault-gated settings pull-forward — see the identical guard in
 * admin_mailbox_filters_logic.php for the full rationale. A destination or
 * delivery-mode change reroutes this mailbox's future mail before it is ever
 * sealed, so it requires an open unlock window — which, since
 * VaultUnlock::isOpen() is scoped to the calling session, only the mailbox
 * owner's own session can ever satisfy.
 */
function _mailbox_alias_require_unlock(int $alias_id): ?string {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
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
