<?php
/**
 * API action: mailbox/unseal_batch — one bounded unseal pass on domains that
 * no longer seal (specs/mailbox_lowering_unseal.md).
 *
 * POST /api/v1/action/mailbox/unseal_batch (browser session). Body:
 * {domain_id} optional — one lowered domain (the editor's receipt loop),
 * {alias_id} for one lowered mailbox, or neither for every non-sealing scope
 * (the reader's quiet convergence).
 * Caller-scoped by construction: unsealing needs the per-message DEK, which
 * unwraps only inside the sealed owner's unlock window — so this call
 * converges only the CALLER's rows, and answers {locked: true} when their
 * window is closed. No staff gate, mirroring mailbox/backfill_seal: the rows
 * are the caller's own, and the domain posture already says plaintext is the
 * correct state. A domain that still seals is refused.
 *
 * Returns {unsealed, own_remaining, others_remaining} (+ locked when closed).
 *
 * @version 1.1
 * @changelog 1.1 - accepts an alias_id scope; the still-sealing refusal asks the
 *   MAILBOX, so a Private mailbox on a lowered domain keeps its mail sealed
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function unseal_batch_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/protection_ceremony.php'));

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$domain = null;
	$alias_scope_id = 0;
	if (!empty($input['alias_id'])) {
		// One lowered mailbox (specs/mailbox_connect_flow.md § D) — its own level
		// answers, so a Private mailbox on a Standard domain is still refused.
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
		$alias = new InboundEmailAlias(intval($input['alias_id']), TRUE);
		if (!$alias->key) {
			return LogicResult::error('Unknown mailbox.');
		}
		if ($alias->seals_content()) {
			return LogicResult::error('This mailbox still seals content — its mail is not unsealed.');
		}
		$alias_scope_id = intval($alias->key);
		$domain = new InboundEmailDomain(intval($alias->get('iea_ied_inbound_email_domain_id')), TRUE);
	} elseif (!empty($input['domain_id'])) {
		$domain = new InboundEmailDomain(intval($input['domain_id']), TRUE);
		if (!$domain->key) {
			return LogicResult::error('Unknown domain.');
		}
		if ($domain->seals_content()) {
			return LogicResult::error('This domain still seals content — its mail is not unsealed.');
		}
	}

	return LogicResult::render(mailbox_protection_unseal_batch($domain, $user_id, 25, $alias_scope_id));
}

function unseal_batch_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Unseal one bounded batch of the caller\'s own sealed messages on domains that no longer seal; returns unsealed and remaining counts',
	);
}
?>
