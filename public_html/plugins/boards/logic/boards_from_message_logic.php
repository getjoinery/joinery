<?php
/**
 * Page logic for "Make a card" (/profile/boards/from_message?message_id=N).
 *
 * The mailbox reader's Make a card button opens this page for one stored message.
 * It reads the message through the mailbox's own access checks
 * (MailboxMessageActions::openForAction), shows a small picker — a list on one of the
 * boards the member can add cards to, and a title prefilled from the subject — and on
 * submit makes the card and sends the member to it.
 *
 * Standard-level mailboxes only: the picker refuses a mailbox that seals its content
 * before reading anything from it.
 *
 * Not an API action: this is a page, so there is no descriptor.
 *
 * @version 1.0.0
 */

function boards_from_message_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$message_id = (int)($input['message_id'] ?? 0);

	if (!$session->get_user_id()) {
		return LogicResult::redirect('/login?return=' . urlencode('/profile/boards/from_message?message_id=' . $message_id));
	}

	$vars = array('session' => $session, 'title' => 'Make a card', 'message_id' => $message_id,
		'error' => null, 'info' => null, 'message_summary' => null, 'targets' => array(), 'existing_url' => null,
		'default_title' => '', 'chosen_list' => 0);

	try {
		$user_id = Boards::requireMember();
	} catch (BoardsRefusal $e) {
		$vars['error'] = $e->getMessage();
		return LogicResult::render($vars);
	}
	if (!BoardsEmail::available()) {
		$vars['error'] = 'Making a card from an email needs the Mailbox plugin.';
		return LogicResult::render($vars);
	}

	$viewer = MailboxViewer::forUser($user_id, Boards::userPermission($user_id));
	$opened = MailboxMessageActions::openForAction($viewer, $message_id);
	if ($opened['message'] === null) {
		$vars['error'] = $opened['error'];
		return LogicResult::render($vars);
	}
	$msg = $opened['message'];

	$vars['message_summary'] = array(
		'from'    => (string)$msg->get('iem_sender'),
		'subject' => (string)$msg->get('iem_subject'),
	);
	$vars['default_title'] = BoardsEmail::cleanSubject((string)$msg->get('iem_subject'));

	$existing = BoardsEmail::cardForMessage($message_id);
	if ($existing !== null) {
		$vars['info'] = 'This email already has a card.';
		try {
			list($card, $board) = Boards::cardFor((int)$existing->key, $user_id, 'viewer');
			$vars['existing_url'] = BoardsNotifier::cardUrl((int)$board->key, (int)$card->key);
		} catch (BoardsRefusal $e) {
			$vars['info'] = 'This email already has a card, on a board you cannot open.';
		}
		return LogicResult::render($vars);
	}

	$targets = BoardsPayload::moveTargets($user_id);
	$vars['targets'] = $targets;
	if (!$targets) {
		$vars['error'] = 'You are not an editor on any board yet, so there is nowhere to put a card. Ask a board admin to make you one, or start a board of your own.';
		return LogicResult::render($vars);
	}

	// ---- submit ----
	if (!empty($_POST) && !empty($input['boards_make_card'])) {
		$formwriter = new FormWriterV2HTML5('boards_make_card_form');
		if (!$formwriter->validateCSRF($input)) {
			$vars['error'] = 'The form expired. Reload the page and try again.';
			return LogicResult::render($vars);
		}
		$vars['default_title'] = (string)($input['title'] ?? $vars['default_title']);
		$vars['chosen_list'] = (int)($input['list_id'] ?? 0);
		try {
			if ($vars['chosen_list'] <= 0 || !BoardList::check_if_exists($vars['chosen_list'])) {
				throw new BoardsRefusal('Choose a list.');
			}
			$list = new BoardList($vars['chosen_list'], TRUE);
			$board = Boards::boardFor((int)$list->get('bls_bbd_board_id'), $user_id, 'editor');
			$list = BoardsCards::listOn($board, $vars['chosen_list']);
			$made = BoardsEmail::createFromMessage($board, $list, $user_id, $msg, array(
				'title'     => $vars['default_title'],
				'source'    => BoardCard::SOURCE_WEB,
				'shortcuts' => false,
			));
			return LogicResult::redirect(BoardsNotifier::cardUrl((int)$board->key, (int)$made['card']->key)
				. ($made['skipped'] > 0 ? '&skipped=' . (int)$made['skipped'] : ''));
		} catch (BoardsRefusal $e) {
			$vars['error'] = $e->getMessage();
		} catch (SystemBaseException $e) {
			$vars['error'] = $e->getMessage();
		}
	}

	return LogicResult::render($vars);
}
