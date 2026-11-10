<?php
/** @joinery-admin class=personal */
/**
 * One direct message: what it said, who it went to, and whether each copy
 * went. Linked from the history on the group, event and user pages.
 *
 * @version 1.0
 */

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	$session = SessionControl::get_instance();
	$message_id = (int)LibraryFunctions::fetch_variable('dmg_direct_message_id', 0, 0, '');
	$message = new DirectMessage($message_id, TRUE);
	$message->assert_found();

	$counts = $message->delivery_counts();
	$sender = $message->get('dmg_usr_user_id_sender') ? new User($message->get('dmg_usr_user_id_sender'), TRUE) : null;

	$numperpage = 50;
	$offset = (int)LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$recipients = new MultiDirectMessageRecipient(
		array('dmr_dmg_direct_message_id' => $message->key),
		array('dmr_direct_message_recipient_id' => 'ASC'),
		$numperpage,
		$offset);
	$pager = new Pager(array('numrecords' => $recipients->count_all(), 'numperpage' => $numperpage));

	$page = new AdminPage();
	$page->admin_header(array(
		'menu-id' => 'users',
		'page_title' => 'Message',
		'readable_title' => (string)$message->get('dmg_subject'),
		'breadcrumbs' => NULL,
		'session' => $session,
	));

	$page->begin_box(array('title' => 'Message'));
	echo '<p><strong>To:</strong> ' . htmlspecialchars($message->audience_label()) . '</p>';
	echo '<p><strong>From:</strong> ' . htmlspecialchars($sender && $sender->key ? $sender->display_name() : '(System)') . '</p>';
	echo '<p><strong>Queued:</strong> ' . htmlspecialchars(LibraryFunctions::convert_time($message->get('dmg_create_time'), 'UTC', $session->get_timezone())) . '</p>';
	echo '<p><strong>Delivery:</strong> ' . (int)$counts['sent'] . ' of ' . (int)$message->get('dmg_recipient_count') . ' sent';
	if ($counts['failed'] > 0) {
		echo ', ' . (int)$counts['failed'] . ' not sent';
	}
	if ($counts['waiting'] > 0) {
		echo ', ' . (int)$counts['waiting'] . ' waiting';
	}
	echo '</p>';
	// Copies the provider refused on included email are most likely the
	// month's allowance running out; say so and where the fix is.
	if ($counts['failed'] > 0 && EmailSender::usesIncludedMail()) {
		echo '<div class="alert alert-warning">' . (int)$counts['sent'] . ' went and ' . (int)$counts['failed']
			. ' did not. On the included email the provider refuses every send once the month\'s allowance is used up. '
			. '<a href="' . HostedPlanNotice::OWN_EMAIL_PROVIDER_URL . '">' . HostedPlanNotice::OWN_EMAIL_PROVIDER_LABEL . '</a>'
			. ' to send without that limit.</div>';
	}
	echo '<p><strong>Subject:</strong> ' . htmlspecialchars((string)$message->get('dmg_subject')) . '</p>';
	// The body is the sending admin's HTML. It is shown in a sandboxed frame
	// with no scripts, so nothing in it runs in this admin's session.
	echo '<iframe sandbox="" referrerpolicy="no-referrer" title="Message body" style="width:100%;min-height:320px;border:1px solid var(--jy-border, #ddd);"'
		. ' srcdoc="' . htmlspecialchars((string)$message->get('dmg_message_html'), ENT_QUOTES) . '"></iframe>';
	$page->end_box();

	$page->tableheader(array('Recipient', 'Email', 'Status', 'Sent'), array('title' => 'Recipients'), $pager);
	foreach ($recipients as $recipient) {
		$name = htmlspecialchars((string)$recipient->get('dmr_name'));
		if ($recipient->get('dmr_usr_user_id')) {
			$name = '<a href="/admin/admin_user?usr_user_id=' . (int)$recipient->get('dmr_usr_user_id') . '">' . $name . '</a>';
		}
		$page->disprow(array(
			$name,
			htmlspecialchars((string)$recipient->get('dmr_email')),
			htmlspecialchars($recipient->get_status_text()),
			$recipient->get('dmr_sent_time') ? htmlspecialchars(LibraryFunctions::convert_time($recipient->get('dmr_sent_time'), 'UTC', $session->get_timezone())) : '',
		));
	}
	$page->endtable($pager);

	$page->admin_footer();
?>
