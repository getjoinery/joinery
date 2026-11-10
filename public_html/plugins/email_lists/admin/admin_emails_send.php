<?php
/** @joinery-admin level=8 */
/**
 * Send queued campaigns now, or a test of one campaign to yourself. Both are
 * buttons (POST) on the campaign pages; the sending is CampaignSender's, the
 * same code the SendCampaigns task runs.
 *
 * @version 2.0 - calls CampaignSender instead of sending inline
 */

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	$session = SessionControl::get_instance();
	// Sending is a button (POST): a link is a GET any other site can make the browser follow.
	if (!LibraryFunctions::isFormSubmission()) {
		throw new SystemDisplayableError('Send emails with the buttons on the Emails pages.');
	}

	$page = new AdminPage();
	$page->admin_header(array(
		'menu-id' => 'emails-list',
		'breadcrumbs' => array('Emails' => '/plugins/email_lists/admin/admin_emails'),
		'session' => $session,
	));
	$page->begin_box(array('title' => 'Send Emails'));

	if (LibraryFunctions::fetch_variable('send_test', false, 0, '')) {
		$email = new Email(LibraryFunctions::fetch_variable('eml_email_id', NULL, 1, 'To send a test email, you must pass an email.'), TRUE);
		$email->assert_found();
		$me = new User($session->get_user_id(), TRUE);
		if (!$session->send_emails()) {
			echo '<p><b>Email sending is disabled, so the email is available <a href="/plugins/email_lists/admin/admin_email_preview?eml_email_id=' . (int)$email->key . '">on the preview page</a>.</b></p>';
		} elseif (CampaignSender::send_test($email, $me)) {
			echo '<p><b>Test sent to ' . htmlspecialchars($me->display_name()) . '.</b></p>';
		} else {
			echo '<p><b>Send failed.</b></p>';
		}
		echo '<p><a href="/plugins/email_lists/admin/admin_email?eml_email_id=' . (int)$email->key . '">Return to the email</a></p>';
	} else {
		$totals = CampaignSender::send_due();
		if ($totals['held'] > 0) {
			echo CampaignRules::banner();
			echo '<p>' . (int)$totals['held'] . ' queued email(s) held, not sent.</p>';
		}
		echo '<p>' . (int)$totals['emails'] . ' queued email(s): ' . (int)$totals['sent'] . ' sent, '
			. (int)$totals['failed'] . ' failed, ' . (int)$totals['skipped'] . ' skipped (no longer subscribed).</p>';
		echo '<p><a href="/plugins/email_lists/admin/admin_emails">Return to the emails page</a></p>';
	}

	$page->end_box();
	$page->admin_footer();
?>
