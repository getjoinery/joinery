<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_email_forwarding_domains_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_alias_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$redirect_url = '/plugins/email_forwarding/admin/admin_email_forwarding_domains';

	// Handle form submission (add/edit domain)
	if ($input && isset($input['efd_domain'])) {
		if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
			$domain = new EmailForwardingDomain($input['edit_primary_key_value'], TRUE);
		} else {
			$domain = new EmailForwardingDomain(NULL);
		}

		$domain->set('efd_domain', $input['efd_domain']);
		$domain->set('efd_is_enabled', isset($input['efd_is_enabled']) ? true : false);
		$domain->set('efd_catch_all_address', $input['efd_catch_all_address'] ?: null);
		$domain->set('efd_reject_unmatched', isset($input['efd_reject_unmatched']) ? true : false);

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
			return LogicResult::redirect($redirect_url);
		} catch (EmailForwardingDomainException $e) {
			return LogicResult::render(array(
				'error' => $e->getMessage(),
				'edit_domain' => $domain,
				'session' => $session,
				'settings' => $settings,
			));
		}
	}

	// Handle delete/undelete/permanent_delete actions
	if ($input && isset($input['action'])) {
		$action = $input['action'];
		$domain_id = $input['efd_email_forwarding_domain_id'] ?? null;

		if ($domain_id && in_array($action, ['delete', 'undelete', 'permanent_delete'])) {
			$domain = new EmailForwardingDomain($domain_id, TRUE);

			if ($action === 'delete') {
				// Soft-delete domain and cascade to aliases
				$domain->soft_delete();

				$aliases = new MultiEmailForwardingAlias([
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
					'/plugins/email_forwarding/admin/',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			} else if ($action === 'undelete') {
				// Restore aliases deleted at the same time or after the domain
				$domain_delete_time = $domain->get('efd_delete_time');
				$domain->undelete();

				if ($domain_delete_time) {
					$dbconnector = DbConnector::get_instance();
					$dblink = $dbconnector->get_db_link();
					$sql = "UPDATE efa_email_forwarding_aliases
							SET efa_delete_time = NULL
							WHERE efa_efd_email_forwarding_domain_id = ?
							AND efa_delete_time >= ?";
					$q = $dblink->prepare($sql);
					$q->execute([$domain->key, $domain_delete_time]);
					$restored_count = $q->rowCount();
				}

				$msg = 'Domain restored' . (!empty($restored_count) ? " along with {$restored_count} alias(es)." : '.');
				$session->save_message(new DisplayMessage(
					$msg, 'Restored',
					'/plugins/email_forwarding/admin/',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			} else if ($action === 'permanent_delete') {
				$session->check_permission(10);
				$domain->permanent_delete();

				$session->save_message(new DisplayMessage(
					'Domain permanently deleted.',
					'Deleted',
					'/plugins/email_forwarding/admin/',
					DisplayMessage::MESSAGE_ANNOUNCEMENT,
					DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
			}

			return LogicResult::redirect($redirect_url);
		}
	}

	// Load domain for editing
	$edit_domain = null;
	if (isset($input['efd_email_forwarding_domain_id'])) {
		$edit_domain = new EmailForwardingDomain($input['efd_email_forwarding_domain_id'], TRUE);
	}

	return LogicResult::render(array(
		'edit_domain' => $edit_domain,
		'session' => $session,
		'settings' => $settings,
	));
}
?>
