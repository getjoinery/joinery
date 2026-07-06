<?php
/**
 * Logic for the consolidated Accounts page.
 *
 * Assembles the domain -> mailbox(alias) -> IMAP-feed tree the page renders.
 * A domain is either MX-hosted (mail pushed in) or an IMAP source (mail pulled
 * in per mailbox); both shapes nest identically. IMAP feeds hold full-mailbox
 * credentials, so they are only loaded/shown for superadmins (permission 10).
 *
 * This page is an overview + navigation surface; create/edit/delete still go
 * through the existing per-object editors (domain, alias, IMAP) which highlight
 * the Accounts tab.
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_accounts_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
	require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	// IMAP feeds carry full-mailbox credentials — superadmin-only, like the
	// retired standalone IMAP Accounts page.
	$can_imap = ($session->get_permission() >= 10);

	// Health of the automatic-fetch scheduled task. IMAP feeds only fetch on their
	// own if "Fetch inbound IMAP mail" (PollImapAccounts) is scheduled, active, not
	// config-disabled, and running often enough (every run / hourly — daily/weekly
	// would let mail lag). Null = healthy; otherwise a short reason for the badge.
	$fetch_task_warning = _accounts_fetch_task_warning();

	$domains = new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC'));
	$domains->load();

	$tree = array();
	foreach ($domains as $domain) {
		$aliases = new MultiInboundEmailAlias(
			array('domain_id' => $domain->key, 'deleted' => false),
			array('iea_alias' => 'ASC')
		);
		$aliases->load();

		$mailboxes = array();
		foreach ($aliases as $alias) {
			$imap = null;
			if ($can_imap) {
				$feeds = new MultiInboundImapAccount(
					array('alias_id' => $alias->key, 'deleted' => false)
				);
				$feeds->load();
				if (count($feeds)) {
					$imap = $feeds->get(0);
				}
			}
			$mailboxes[] = array('alias' => $alias, 'imap' => $imap);
		}

		$tree[] = array('domain' => $domain, 'mailboxes' => $mailboxes);
	}

	// Soft-deleted domains — a "trash" superadmins can restore from, kept on this
	// one page rather than a separate list. (Restore/permanent-delete are handled
	// by the domain action handler.)
	$deleted_domains = array();
	if ($session->get_permission() >= 10) {
		$trash = new MultiInboundEmailDomain(array('deleted' => true), array('ied_domain' => 'ASC'));
		$trash->load();
		foreach ($trash as $d) {
			$deleted_domains[] = $d;
		}
	}

	return LogicResult::render(array(
		'session'            => $session,
		'settings'           => $settings,
		'tree'               => $tree,
		'deleted_domains'    => $deleted_domains,
		'can_imap'           => $can_imap,
		'presets'            => InboundImapAccount::PRESETS,
		'fetch_task_warning' => $fetch_task_warning,
	));
}

/**
 * Returns null when the automatic-fetch task is healthy, or a short reason string
 * for the warning badge when it won't fetch IMAP mail on a regular cadence.
 */
function _accounts_fetch_task_warning(): ?string {
	$tasks = new MultiScheduledTask(array('task_class' => 'PollImapAccounts', 'deleted' => false));
	$tasks->load();
	if (!count($tasks)) {
		return 'Automatic fetching is off — the “Fetch inbound IMAP mail” scheduled task is not enabled.';
	}
	$task = $tasks->get(0);
	if (!$task->get('sct_is_active')) {
		return 'Automatic fetching is paused — activate the “Fetch inbound IMAP mail” scheduled task.';
	}
	$config = $task->get_task_config();
	if (array_key_exists('polling_enabled', $config)
		&& !filter_var($config['polling_enabled'], FILTER_VALIDATE_BOOLEAN)) {
		return 'Automatic fetching is disabled in the task settings.';
	}
	$freq = $task->get('sct_frequency') ?: 'daily';
	if (!in_array($freq, array('every_run', 'hourly'), true)) {
		return 'Automatic fetching runs only ' . $freq . ' — set it to hourly or every run so mail does not lag.';
	}
	return null;
}
?>
