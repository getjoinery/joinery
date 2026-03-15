<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_email_forwarding_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	// Handle delete action
	if ($post_vars && isset($post_vars['action']) && $post_vars['action'] === 'delete') {
		$alias = new EmailForwardingAlias($post_vars['efa_email_forwarding_alias_id'], TRUE);
		$alias->soft_delete();

		$session->save_message(new DisplayMessage(
			'Alias deleted.',
			'Deleted',
			'/plugins/email_forwarding/admin/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect('/plugins/email_forwarding/admin/admin_email_forwarding');
	}

	// Handle enable/disable toggle
	if ($post_vars && isset($post_vars['action']) && $post_vars['action'] === 'toggle_enabled') {
		$alias = new EmailForwardingAlias($post_vars['efa_email_forwarding_alias_id'], TRUE);
		$alias->set('efa_is_enabled', $alias->get('efa_is_enabled') ? false : true);
		$alias->save();
		return LogicResult::redirect('/plugins/email_forwarding/admin/admin_email_forwarding');
	}

	// Load domains for filter dropdown
	$domains = new MultiEmailForwardingDomain(array('deleted' => false), array('efd_domain' => 'ASC'));
	$domains->load();

	return LogicResult::render(array(
		'session' => $session,
		'settings' => $settings,
		'domains' => $domains,
	));
}
?>
