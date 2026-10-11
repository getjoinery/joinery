<?php
/**
 * Page logic for the Boards app (/profile/boards and its pages).
 *
 * Gathers what each page needs to draw itself and embeds it in the page as JSON,
 * so the app paints without a round trip; everything after that goes through the
 * boards_* API actions.
 *
 *   index     the board list: my boards, open boards, templates, archived, starters
 *   board     one board with its lists and cards (and the open card, if ?card= names one)
 *   my_cards  every open card assigned to the member, across boards
 *   import    the import screen
 *
 * Not an API action: these are pages, so there is no descriptor.
 *
 * A board the member cannot open is a 404 — the same answer as a board that does
 * not exist.
 *
 * @version 1.0.0
 */

function boards_page_logic(array $input): LogicResult {
	$page = (string)($input['__page'] ?? 'index');
	$session = SessionControl::get_instance();

	if (!$session->get_user_id()) {
		$return = '/profile/boards' . ($page === 'index' ? '' : '/' . $page)
			. (($page === 'board' && !empty($input['id'])) ? '?id=' . (int)$input['id'] : '');
		return LogicResult::redirect('/login?return=' . urlencode($return));
	}

	try {
		$user_id = Boards::requireMember();
	} catch (BoardsRefusal $e) {
		return LogicResult::render(array('session' => $session, 'unavailable' => $e->getMessage(), 'boot' => null));
	}

	$boot = array('page' => $page, 'user_id' => $user_id, 'settings' => Boards::clientSettings());

	try {
		switch ($page) {
			case 'board':
				$board = Boards::boardFor($input['id'] ?? 0, $user_id, 'viewer');
				$role = Boards::roleFor($board, $user_id);
				$boot += BoardsPayload::boardPage($board, $user_id, $role);
				$boot['backgrounds'] = BoardsManager::BACKGROUNDS;
				$boot['label_colors'] = BoardLabel::COLORS;
				$boot['open_card'] = null;
				if (!empty($input['card'])) {
					try {
						list($card, $card_board) = Boards::cardFor($input['card'], $user_id, 'viewer', true);
						if ((int)$card_board->key === (int)$board->key) {
							$boot['open_card'] = BoardsPayload::cardDetail($card, $board, $user_id, $role);
						}
					} catch (BoardsRefusal $e) {
						// A card that is gone just opens the board.
					}
				}
				$title = (string)$board->get('bbd_name');
				break;

			case 'my_cards':
				$boot += BoardsPayload::myCards($user_id);
				$title = 'My cards';
				break;

			case 'import':
				$title = 'Import a board';
				break;

			default:
				$boot['page'] = 'index';
				$boot['index'] = BoardsPayload::boardIndex($user_id);
				$boot['starters'] = array_values(BoardsTemplates::starters());
				$boot['backgrounds'] = BoardsManager::BACKGROUNDS;
				$title = 'Boards';
		}
	} catch (BoardsRefusal $e) {
		return LogicResult::render(array('session' => $session, 'not_found' => true, 'boot' => null, 'title' => 'Not found'));
	}

	return LogicResult::render(array(
		'session' => $session,
		'boot'    => $boot,
		'title'   => $title,
		'page'    => $boot['page'],
	));
}
