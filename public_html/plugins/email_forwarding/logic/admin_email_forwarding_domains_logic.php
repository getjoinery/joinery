<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_email_forwarding_domains_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	// Handle form submission (add/edit domain)
	if ($post_vars && isset($post_vars['efd_domain'])) {
		if (isset($post_vars['edit_primary_key_value']) && $post_vars['edit_primary_key_value']) {
			$domain = new EmailForwardingDomain($post_vars['edit_primary_key_value'], TRUE);
		} else {
			$domain = new EmailForwardingDomain(NULL);
		}

		$domain->set('efd_domain', $post_vars['efd_domain']);
		$domain->set('efd_is_enabled', isset($post_vars['efd_is_enabled']) ? true : false);
		$domain->set('efd_catch_all_address', $post_vars['efd_catch_all_address'] ?: null);
		$domain->set('efd_reject_unmatched', isset($post_vars['efd_reject_unmatched']) ? true : false);

		try {
			$domain->prepare();
			$domain->save();

			$session->save_message(new DisplayMessage(
				'Domain saved.',
				'Saved',
				'/plugins/email_forwarding/admin/',
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/plugins/email_forwarding/admin/admin_email_forwarding_domains');
		} catch (EmailForwardingDomainException $e) {
			return LogicResult::render(array(
				'error' => $e->getMessage(),
				'edit_domain' => $domain,
				'session' => $session,
				'settings' => $settings,
			));
		}
	}

	// Handle delete action
	if ($post_vars && isset($post_vars['action']) && $post_vars['action'] === 'delete') {
		$domain = new EmailForwardingDomain($post_vars['efd_email_forwarding_domain_id'], TRUE);
		$domain->soft_delete();

		$session->save_message(new DisplayMessage(
			'Domain deleted.',
			'Deleted',
			'/plugins/email_forwarding/admin/',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect('/plugins/email_forwarding/admin/admin_email_forwarding_domains');
	}

	// Load domain for editing
	$edit_domain = null;
	if (isset($get_vars['efd_email_forwarding_domain_id'])) {
		$edit_domain = new EmailForwardingDomain($get_vars['efd_email_forwarding_domain_id'], TRUE);
	}

	return LogicResult::render(array(
		'edit_domain' => $edit_domain,
		'session' => $session,
		'settings' => $settings,
	));
}
?>
