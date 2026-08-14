<?php
/**
 * Persona Browser — Blocked Senders
 * URL: /plugins/persona_browser/admin/admin_persona_blocked_senders
 *
 * Lists every creator blocked from the feed ("Block sender" on a feed card)
 * with an Unblock button. Unblocking soft-deletes the block row, so the
 * sender's stored posts reappear in the feed immediately; blocking them again
 * revives the same row.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_blocked_senders_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);
$session->set_return();

// Unblock action (POST) — handled before any rendering, then redirect.
if (LibraryFunctions::isFormSubmission() && ($_POST['action'] ?? '') === 'unblock') {
	$block = new PersonaBlockedSender((int)($_POST['pbs_blocked_sender_id'] ?? 0), TRUE);
	if ($block->key && !$block->get('pbs_delete_time')) {
		$block->soft_delete();
		$session->save_message(new DisplayMessage(
			htmlspecialchars($block->get('pbs_author')) . ' is unblocked — their posts show in the feed again.',
			'Unblocked',
			'~/plugins/persona_browser/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}
	header('Location: /plugins/persona_browser/admin/admin_persona_blocked_senders');
	exit;
}

$blocks = new MultiPersonaBlockedSender(array('deleted' => false), array('pbs_create_time' => 'DESC'));
$numrecords = count($blocks);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'persona-blocked-senders',
	'breadcrumbs' => array('Blocked Senders' => ''),
	'session' => $session,
));

if ($numrecords === 0) {
	echo '<p>No blocked senders. Use the &#8942; menu on a feed post to block its creator; they will appear here.</p>';
} else {
	$page->tableheader(array('Sender', 'Blocked', ''), array('title' => 'Blocked Senders'));
	foreach ($blocks as $block) {
		$page->disprow(array(
			htmlspecialchars($block->get('pbs_author')),
			$block->get_local('pbs_create_time'),
			AdminPage::action_button('Unblock', '/plugins/persona_browser/admin/admin_persona_blocked_senders', array(
				'hidden' => array(
					'action' => 'unblock',
					'pbs_blocked_sender_id' => $block->key,
				),
			)),
		));
	}
	$page->endtable();
}

$page->admin_footer();
