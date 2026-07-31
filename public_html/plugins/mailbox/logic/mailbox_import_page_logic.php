<?php
/**
 * Page logic for the Import old mail surfaces — one function, two mounts.
 *
 * The member page and the admin page ask the same question and get the same
 * answer; the only difference is that an operator's mailbox picker covers every
 * mailbox rather than just the ones they hold. That difference lives in
 * MailImportService, so this is genuinely one code path.
 *
 * No action happens here. Starting, choosing and undoing all go through the API
 * actions, which the panel calls.
 *
 * @version 1.1
 */

function mailbox_import_page_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailImportService.php'));

	$session = SessionControl::get_instance();
	if (!intval($session->get_user_id())) {
		return LogicResult::redirect('/login?return=' . urlencode((string)($input['_return'] ?? '/profile/mailbox/import')));
	}

	$settings = Globalvars::get_instance();
	$service = MailImportService::fromSession($session);

	$aliases = $service->targetableAliases();
	$last = $service->lastChoices();

	// What the form opens on, in order of how much it knows about this person:
	// an explicit ?alias_id, then the mailbox they last imported into, then the
	// first one they hold. Coming back to this page mid-migration and finding the
	// picker reset to somebody else's mailbox is how mail lands in the wrong place.
	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id <= 0 && $last && $last['alias_id'] > 0 && isset($aliases[$last['alias_id']])) {
		$alias_id = $last['alias_id'];
	}
	if ($alias_id <= 0 && $aliases) {
		$alias_id = intval(array_key_first($aliases));
	}

	// The declared addresses are the answer to "who were you at the old provider",
	// and that answer does not change between the three files of one Takeout. Carry
	// the last run's list forward — but only for the mailbox it was written for.
	// Asked about a different mailbox, the suggestion is the honest starting point.
	$own_addresses = ($last && $last['own_addresses'] !== '' && $last['alias_id'] === $alias_id)
		? $last['own_addresses']
		: implode("\n", $alias_id > 0 ? $service->suggestedAddresses($alias_id) : array());

	return LogicResult::render(array(
		'session'             => $session,
		'settings'            => $settings,
		'import_enabled'      => (bool)$settings->get_setting('mailbox_import_enabled'),
		// Null when imports are actually being processed; otherwise why they are not.
		'scheduler_warning'   => MailImportService::schedulerWarning(),
		'is_operator'         => $service->isOperator(),
		'aliases'             => $aliases,
		'alias_id'            => $alias_id,
		'files'               => $service->pickableFiles(),
		'own_addresses'       => $own_addresses,
		// The run holding the one-at-a-time slot, or null. The panel hides the start
		// form while this is set, and the poller takes over from there.
		'active_run'          => $service->activeRun(),
		'runs'                => $service->history(),
	));
}
?>
