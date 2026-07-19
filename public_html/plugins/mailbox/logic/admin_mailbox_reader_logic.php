<?php
/**
 * Logic for the Inbound Email Mailbox Reader (the Gmail-style Mailbox tab).
 *
 * Permission-gated (staff-only in v1). Issues a persistent per-session CSRF
 * token for the reader's state-changing AJAX (mailbox_action) and seeds the
 * initial switcher data so the rail renders without a flash. All list/thread
 * reads and mutations go through the AJAX endpoints, scoped by MailboxViewer.
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_reader_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	$gate_redirect = mailbox_receive_gate_handle($input);
	if ($gate_redirect !== null) {
		return $gate_redirect;
	}

	// Persistent CSRF token for the reader's action endpoint (validated, not
	// consumed, because the reader fires many actions per session).
	if (empty($_SESSION['mailbox_reader_csrf'])) {
		$_SESSION['mailbox_reader_csrf'] = bin2hex(random_bytes(32));
	}
	$csrf_token = $_SESSION['mailbox_reader_csrf'];

	$viewer = MailboxViewer::fromSession($session);
	$service = new MailboxService($viewer);
	$initial_mailboxes = $service->listMailboxes();

	return LogicResult::render(array(
		'session'           => $session,
		'settings'          => $settings,
		'csrf_token'        => $csrf_token,
		'initial_mailboxes' => $initial_mailboxes,
	));
}
?>
