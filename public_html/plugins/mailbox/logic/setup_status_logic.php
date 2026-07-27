<?php
/**
 * API action: mailbox/setup_status — is this mailbox all green?
 *
 * POST /api/v1/action/mailbox/setup_status (session credential). Param:
 * alias_id, plus optional fresh=1 to bypass the cache. Admin only (permission
 * 5+): mail setup is operator work, and the answer names infrastructure a
 * member has no business seeing.
 *
 * The answer is the Setup tab's own verdict for that mailbox — the same rows,
 * grouped by the same code (mailbox_setup_scope.php) — so the reader's banner
 * can never claim a mailbox is fine while the tab shows amber, or the reverse.
 * Anything the tab paints amber or red returns `attention`.
 *
 * Running the checks costs DNS lookups and local host probes, so the answer is
 * remembered per operator (mailbox_setup_scope.php) rather than re-resolved on
 * every mailbox click. Rendering the Setup tab writes that memory, so a record
 * fixed there reads as fixed the moment the operator returns to the mailbox;
 * fresh=1 (the reader's Refresh control) forces a re-run regardless.
 *
 * @version 1.1.0
 */

function setup_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_scope.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}
	// Mail setup is operator work — admins only (level 5+).
	if (intval($session->get_permission()) < 5) {
		return LogicResult::error('Not authorized.');
	}

	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id <= 0) {
		return LogicResult::error('No mailbox specified.');
	}
	// The caller must be able to see the mailbox they are asking about, so this
	// can never report on somebody else's mail estate.
	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->isAllAccess() && !$viewer->canAccess($alias_id)) {
		return LogicResult::error('Not authorized.');
	}

	if (empty($input['fresh'])) {
		$remembered = mailbox_setup_status_recall($alias_id);
		if ($remembered !== null) {
			return LogicResult::render($remembered + array('cached' => true));
		}
	}

	try {
		$verdict = mailbox_setup_verdict(mailbox_setup_scoped_rows($alias_id));
	} catch (\Throwable $e) {
		// A check that blew up is not evidence a mailbox is broken. Say nothing
		// rather than banner a mailbox on the strength of our own failure.
		error_log('mailbox setup_status: alias ' . $alias_id . ' failed: ' . $e->getMessage());
		return LogicResult::render(array('status' => 'unknown', 'reason' => '', 'label' => '', 'url' => ''));
	}

	return LogicResult::render(
		mailbox_setup_status_remember($alias_id, $verdict) + array('cached' => false));
}

function setup_status_logic_descriptor(): array {
	return array(
		'description'      => 'The Setup tab verdict for one mailbox: ok, attention (with the first non-green row), or unknown (admin only).',
		'requires_session' => true,
		'mutates'          => false,
		// Not a mutation, but it does write: the cached verdict lives in the
		// caller's session, which the API otherwise releases as soon as it has
		// read identity — without this the cache would never survive the request
		// and every reader load would re-run the DNS lookups.
		'auth'             => array('session_write' => true),
		'input'            => array(
			'alias_id' => array('type' => 'int', 'required' => true, 'label' => 'Mailbox'),
			'fresh'    => array('type' => 'string', 'required' => false, 'label' => 'Bypass the cached verdict'),
		),
	);
}
?>
