<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_domains_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

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

		try {
			$domain->prepare();
			$domain->save();

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
