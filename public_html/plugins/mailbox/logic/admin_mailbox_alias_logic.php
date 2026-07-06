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

		try {
			$alias->prepare();
			$alias->save();
			$alias->load();

			// Sync the mailbox's access list to exactly the submitted set.
			InboundEmailMailboxGrant::sync_for_alias($alias->key, $submitted_grant_users);

			$session->save_message(new DisplayMessage(
				'Alias saved.',
				'Saved',
				'/plugins/mailbox/admin/',
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
?>
