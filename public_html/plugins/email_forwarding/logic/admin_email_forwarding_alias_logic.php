<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_email_forwarding_alias_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	// Load or create alias
	if (isset($post_vars['edit_primary_key_value']) && $post_vars['edit_primary_key_value']) {
		$alias = new EmailForwardingAlias($post_vars['edit_primary_key_value'], TRUE);
	} elseif (isset($get_vars['efa_email_forwarding_alias_id'])) {
		$alias = new EmailForwardingAlias($get_vars['efa_email_forwarding_alias_id'], TRUE);
	} else {
		$alias = new EmailForwardingAlias(NULL);
	}

	// Process form submission
	if ($post_vars && isset($post_vars['efa_alias'])) {
		$editable_fields = array('efa_efd_email_forwarding_domain_id', 'efa_alias', 'efa_destinations', 'efa_description');
		foreach ($editable_fields as $field) {
			if (isset($post_vars[$field])) {
				$alias->set($field, $post_vars[$field]);
			}
		}

		$alias->set('efa_is_enabled', isset($post_vars['efa_is_enabled']) ? true : false);

		try {
			$alias->prepare();
			$alias->save();
			$alias->load();

			$session->save_message(new DisplayMessage(
				'Alias saved.',
				'Saved',
				'/plugins/email_forwarding/admin/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/plugins/email_forwarding/admin/admin_email_forwarding');
		} catch (EmailForwardingAliasException $e) {
			return LogicResult::render(array(
				'alias' => $alias,
				'error' => $e->getMessage(),
				'session' => $session,
				'settings' => $settings,
				'domains' => new MultiEmailForwardingDomain(array('deleted' => false), array('efd_domain' => 'ASC')),
			));
		}
	}

	// Load domains for dropdown
	$domains = new MultiEmailForwardingDomain(array('deleted' => false), array('efd_domain' => 'ASC'));
	$domains->load();

	return LogicResult::render(array(
		'alias' => $alias,
		'session' => $session,
		'settings' => $settings,
		'domains' => $domains,
	));
}
?>
