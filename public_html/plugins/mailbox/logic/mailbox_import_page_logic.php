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
 * @version 1.0
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
	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id <= 0 && $aliases) {
		$alias_id = intval(array_key_first($aliases));
	}

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
		'suggested_addresses' => $alias_id > 0 ? $service->suggestedAddresses($alias_id) : array(),
		'runs'                => $service->history(),
	));
}
?>
