<?php
/**
 * API action: mailbox/seal_batch — one bounded backlog-sealing pass.
 *
 * POST /api/v1/action/mailbox/seal_batch (browser session). Body: {domain_id},
 * or {alias_id} for one mailbox raised on its own.
 * Runs mailbox_protection_seal_batch() (200 rows per pass, sealing uses only
 * the holder's vault PUBLIC key) and returns {sealed, remaining} — the domain
 * editor's receipt card loops this until remaining is 0, resolving its
 * progress row in place (specs/mailbox_raise_receipt.md). A pass that seals
 * nothing while rows remain signals an unsealable backlog (a holder lost
 * their vault after the raise); the caller stops on that shape.
 *
 * @version 1.1
 * @changelog 1.1 - accepts an alias_id scope, so one protected mailbox converges
 *   its own backlog without touching the rest of its domain
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function seal_batch_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));

	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 5) {
		return LogicResult::error('Not authorized.');
	}

	// Scope: one mailbox when the caller names it (a pulled-in mailbox raised on
	// its own, specs/mailbox_connect_flow.md § D), the whole domain otherwise.
	// Same pass, same per-row work — only the row set differs.
	$alias_scope_id = intval($input['alias_id'] ?? 0);
	if ($alias_scope_id > 0) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
		$alias = new InboundEmailAlias($alias_scope_id, TRUE);
		if (!$alias->key) {
			return LogicResult::error('Unknown mailbox.');
		}
		if (!$alias->seals_content()) {
			return LogicResult::error('This mailbox does not seal content.');
		}
		$domain = new InboundEmailDomain(intval($alias->get('iea_ied_inbound_email_domain_id')), TRUE);
		if (!$domain->key) {
			return LogicResult::error('Unknown domain.');
		}
	} else {
		$domain = new InboundEmailDomain(intval($input['domain_id'] ?? 0), TRUE);
		if (!$domain->key) {
			return LogicResult::error('Unknown domain.');
		}
		// A Standard domain can still hold a Private mailbox, and its backlog is
		// as real as any other — refuse only when nothing in scope seals at all.
		if (!$domain->seals_content()
				&& !InboundEmailAlias::domainHasSealingMailbox(intval($domain->key))) {
			return LogicResult::error('Nothing on this domain seals content.');
		}
	}

	$result = mailbox_protection_seal_batch($domain, 200, $alias_scope_id);
	return LogicResult::render(array(
		'sealed' => intval($result['sealed']),
		'remaining' => intval($result['remaining']),
	));
}

function seal_batch_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Seal one bounded batch of a protected domain or mailbox\'s unsealed messages; returns sealed and remaining counts',
	);
}
?>
