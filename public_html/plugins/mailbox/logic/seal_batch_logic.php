<?php
/**
 * API action: mailbox/seal_batch — one bounded backlog-sealing pass.
 *
 * POST /api/v1/action/mailbox/seal_batch (browser session). Body: {domain_id}.
 * Runs mailbox_protection_seal_batch() (200 rows per pass, sealing uses only
 * the holder's vault PUBLIC key) and returns {sealed, remaining} — the domain
 * editor's receipt card loops this until remaining is 0, resolving its
 * progress row in place (specs/mailbox_raise_receipt.md). A pass that seals
 * nothing while rows remain signals an unsealable backlog (a holder lost
 * their vault after the raise); the caller stops on that shape.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function seal_batch_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));

	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 5) {
		return LogicResult::error('Not authorized.');
	}

	$domain = new InboundEmailDomain(intval($input['domain_id'] ?? 0), TRUE);
	if (!$domain->key) {
		return LogicResult::error('Unknown domain.');
	}
	if (!$domain->seals_content()) {
		return LogicResult::error('This domain does not seal content.');
	}

	$result = mailbox_protection_seal_batch($domain);
	return LogicResult::render(array(
		'sealed' => intval($result['sealed']),
		'remaining' => intval($result['remaining']),
	));
}

function seal_batch_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Seal one bounded batch of a protected domain\'s unsealed messages; returns sealed and remaining counts',
	);
}
?>
