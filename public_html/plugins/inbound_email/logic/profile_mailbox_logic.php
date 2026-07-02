<?php
/**
 * Logic for the member Mailbox page (/profile/inbound_email/mailbox).
 *
 * Requires a signed-in session — any member, no staff permission. What the
 * member sees is decided entirely by MailboxViewer: the mailboxes they hold
 * grants for (superadmins are all-access). Issues the same persistent
 * per-session reader CSRF token the admin mount uses, so the shared AJAX
 * endpoints accept either mount.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function profile_mailbox_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/MailboxService.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::redirect('/login?return=' . urlencode('/profile/inbound_email/mailbox'));
	}
	$settings = Globalvars::get_instance();

	// Persistent CSRF token for the reader's state-changing endpoints
	// (validated, not consumed, because the reader fires many actions per
	// session). Same session key as the admin mount — one reader, one token.
	if (empty($_SESSION['mailbox_reader_csrf'])) {
		$_SESSION['mailbox_reader_csrf'] = bin2hex(random_bytes(32));
	}
	$csrf_token = $_SESSION['mailbox_reader_csrf'];

	$viewer = MailboxViewer::fromSession($session);
	$service = new MailboxService($viewer);
	$initial_mailboxes = $service->listMailboxes();

	// listMailboxes() returns {all_access, mailboxes, [all_mail, unmatched]}.
	// An all-access superadmin always has the merged views to look at; anyone
	// else needs at least one granted mailbox or they get the empty state.
	$has_mailboxes = !empty($initial_mailboxes['all_access'])
		|| count($initial_mailboxes['mailboxes'] ?? array()) > 0;

	return LogicResult::render(array(
		'session'           => $session,
		'settings'          => $settings,
		'csrf_token'        => $csrf_token,
		'initial_mailboxes' => $initial_mailboxes,
		'has_mailboxes'     => $has_mailboxes,
	));
}
?>
