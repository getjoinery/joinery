<?php
/**
 * API action: mailbox/unseal_batch — one bounded unseal pass on domains that
 * no longer seal (specs/mailbox_lowering_unseal.md).
 *
 * POST /api/v1/action/mailbox/unseal_batch (browser session). Body:
 * {domain_id} optional — one lowered domain (the editor's receipt loop), or
 * absent for every non-sealing domain (the reader's quiet convergence).
 * Caller-scoped by construction: unsealing needs the per-message DEK, which
 * unwraps only inside the sealed owner's unlock window — so this call
 * converges only the CALLER's rows, and answers {locked: true} when their
 * window is closed. No staff gate, mirroring mailbox/backfill_seal: the rows
 * are the caller's own, and the domain posture already says plaintext is the
 * correct state. A domain that still seals is refused.
 *
 * Returns {unsealed, own_remaining, others_remaining} (+ locked when closed).
 *
 * @version 1.0
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
	if (!empty($input['domain_id'])) {
		$domain = new InboundEmailDomain(intval($input['domain_id']), TRUE);
		if (!$domain->key) {
			return LogicResult::error('Unknown domain.');
		}
		if ($domain->seals_content()) {
			return LogicResult::error('This domain still seals content — its mail is not unsealed.');
		}
	}

	return LogicResult::render(mailbox_protection_unseal_batch($domain, $user_id));
}

function unseal_batch_logic_descriptor() {
	return array(
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Unseal one bounded batch of the caller\'s own sealed messages on domains that no longer seal; returns unsealed and remaining counts',
	);
}
?>
