<?php
/**
 * Persona Browser — Senders
 * URL: /plugins/persona_browser/admin/admin_persona_senders
 *
 * The owner's standing instructions about feed creators, two lists:
 *
 *  - Allowed: always shown, never judged by the AI, never auto-blocked. Added
 *    here by name, from a feed card's menu, or by pressing Allow on a blocked
 *    row (which lifts the block).
 *  - Blocked: never shown. Added by hand ("Block sender" on a feed card) or by
 *    the ad-judging recipe's repeat-advertiser threshold. Unblock lifts the
 *    block and nothing more — the sender is ordinary again, so the threshold
 *    can block them again later. Allow is the way to keep a sender for good.
 *
 * Every action is a POST handled before rendering, then a redirect.
 *
 * @version 2.0 - both lists on one page; allow list added
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_blocked_senders_class.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_allowed_senders_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);
$session->set_return();

$self = '/plugins/persona_browser/admin/admin_persona_senders';
$owner = PersonaFeedItem::OWNER_INSTANCE;
$persona = 'facebook';

$announce = function (string $text, string $title) use ($session) {
	$session->save_message(new DisplayMessage(
		$text,
		$title,
		'~/plugins/persona_browser/admin/~',
		DisplayMessage::MESSAGE_ANNOUNCEMENT,
		DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
};

if (LibraryFunctions::isFormSubmission()) {
	$action = (string)($_POST['action'] ?? '');

	if ($action === 'allow_by_name') {
		// The only form with a typed field on the page, so the only one whose
		// token is verified (the buttons carry ids, not text).
		$formwriter = new FormWriterV2HTML5('allow_sender');
		if (!$formwriter->validateCSRF($_POST)) {
			$announce('The form had expired — please try again.', 'Not saved');
			header('Location: ' . $self);
			exit;
		}
		$author = trim((string)($_POST['pas_author'] ?? ''));
		if ($author === '') {
			$announce('Type the sender\'s name exactly as it appears on their posts.', 'Nothing added');
		} else {
			PersonaAllowedSender::allow($owner, $persona, $author, trim((string)($_POST['pas_note'] ?? '')));
			$announce(htmlspecialchars($author) . ' is allowed — always shown, never judged, never blocked.', 'Allowed');
		}
		header('Location: ' . $self);
		exit;
	}

	if ($action === 'unblock' || $action === 'allow_blocked') {
		$block = new PersonaBlockedSender((int)($_POST['pbs_blocked_sender_id'] ?? 0), TRUE);
		if ($block->key && !$block->get('pbs_delete_time')) {
			$author = (string)$block->get('pbs_author');
			if ($action === 'allow_blocked') {
				PersonaAllowedSender::allow($owner, $persona, $author);
				$announce(htmlspecialchars($author) . ' is allowed — always shown, never judged, never blocked.', 'Allowed');
			} else {
				$block->soft_delete();
				$announce(htmlspecialchars($author) . ' is unblocked — their posts show in the feed again. If their posts keep being judged ads they can be blocked again; use Allow to keep them for good.', 'Unblocked');
			}
		}
		header('Location: ' . $self);
		exit;
	}

	if ($action === 'disallow') {
		$row = new PersonaAllowedSender((int)($_POST['pas_allowed_sender_id'] ?? 0), TRUE);
		if ($row->key && !$row->get('pas_delete_time')) {
			$row->soft_delete();
			$announce(htmlspecialchars($row->get('pas_author')) . ' is an ordinary sender again — judged like anyone else.', 'Removed from allowed');
		}
		header('Location: ' . $self);
		exit;
	}
}

$allowed = new MultiPersonaAllowedSender(array('deleted' => false), array('pas_create_time' => 'DESC'));
$blocks  = new MultiPersonaBlockedSender(array('deleted' => false), array('pbs_create_time' => 'DESC'));

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'persona-senders',
	'breadcrumbs' => array('Senders' => ''),
	'session' => $session,
));

// ---- Allowed -----------------------------------------------------------------
$page->begin_box(array('title' => 'Allowed senders'));
echo '<p>Always shown in the feed, never judged by the AI, never blocked automatically. '
	. 'Use this for a creator whose posts the ad rule keeps mistaking — the name must match how it appears on their posts.</p>';

$formwriter = $page->getFormWriter('allow_sender');
$formwriter->begin_form();
$formwriter->hiddeninput('action', '', array('value' => 'allow_by_name'));
$formwriter->textinput('pas_author', 'Sender name', array(
	'placeholder' => 'Exactly as shown on their posts',
	'required' => true,
));
$formwriter->textinput('pas_note', 'Note (optional)', array(
	'placeholder' => 'Why they are here, for your own reference',
));
$formwriter->submitbutton('btn_allow', 'Allow this sender');
$formwriter->end_form();

if (count($allowed) === 0) {
	echo '<p>No allowed senders yet.</p>';
} else {
	$page->tableheader(array('Sender', 'Note', 'Allowed', ''));
	foreach ($allowed as $row) {
		$page->disprow(array(
			htmlspecialchars($row->get('pas_author')),
			htmlspecialchars((string)$row->get('pas_note')),
			$row->get_local('pas_create_time'),
			AdminPage::action_button('Remove', $self, array(
				'hidden' => array('action' => 'disallow', 'pas_allowed_sender_id' => $row->key),
				'confirm' => 'Remove ' . $row->get('pas_author') . ' from the allowed list? Their posts will be judged like anyone else\'s again.',
			)),
		));
	}
	$page->endtable();
}
$page->end_box();

// ---- Blocked -----------------------------------------------------------------
$page->begin_box(array('title' => 'Blocked senders'));
echo '<p>Never shown in the feed. Blocked by you from a post\'s &#8942; menu, or by the AI once a creator\'s posts have been judged ads '
	. 'enough times (the threshold is a plugin setting). <strong>Unblock</strong> lifts the block only — the AI can block them again. '
	. '<strong>Allow</strong> lifts the block and keeps them for good.</p>';

if (count($blocks) === 0) {
	echo '<p>No blocked senders.</p>';
} else {
	$page->tableheader(array('Sender', 'Blocked by', 'Blocked', ''));
	foreach ($blocks as $block) {
		$note = trim((string)$block->get('pbs_note'));
		$source = $block->get('pbs_source') === 'auto'
			? 'AI' . ($note !== '' ? ' &mdash; ' . htmlspecialchars($note) : '')
			: 'You';
		$page->disprow(array(
			htmlspecialchars($block->get('pbs_author')),
			$source,
			$block->get_local('pbs_create_time'),
			AdminPage::action_button('Unblock', $self, array(
				'hidden' => array('action' => 'unblock', 'pbs_blocked_sender_id' => $block->key),
			))
			. ' '
			. AdminPage::action_button('Allow', $self, array(
				'hidden' => array('action' => 'allow_blocked', 'pbs_blocked_sender_id' => $block->key),
			)),
		));
	}
	$page->endtable();
}
$page->end_box();

$page->admin_footer();
