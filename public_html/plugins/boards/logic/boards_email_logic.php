<?php
/**
 * API action: boards/boards_email — a board's email-to-board settings.
 *
 * POST /api/v1/action/boards/boards_email
 *
 * One action, chosen by `op`:
 *   panel        board_id   — what the "Email to this board" panel shows: the member's own
 *                address, and (board admins) the domain choice and where cards land
 *   reset        board_id   — editor+; revoke the member's address and make a new one
 *   set_domain   board_id, domain_id (empty: the default)   — board admin
 *   set_landing  board_id, list_id (empty: the first list), position (top|bottom)   — board admin
 *
 * Viewers have no address: adding a card needs editor. With the mailbox plugin inactive
 * the panel answers `available: false` and the browser leaves the section out.
 *
 * @version 1.0.0
 */

function boards_email_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$op = BoardsAction::oneOf($input['op'] ?? 'panel', array('panel', 'reset', 'set_domain', 'set_landing'), 'operation');
		$minimum = $op === 'set_domain' || $op === 'set_landing' ? 'admin' : ($op === 'reset' ? 'editor' : 'viewer');
		$board = Boards::boardFor($input['board_id'] ?? 0, $user_id, $minimum);

		switch ($op) {
			case 'reset':
				if (!BoardsEmail::available()) {
					throw new BoardsRefusal('Email-to-board needs the Mailbox plugin.');
				}
				BoardsEmail::resetAddress($board, $user_id);
				break;
			case 'set_domain':
				BoardsEmail::setDomain($board, BoardsAction::idOrNull($input, 'domain_id'));
				break;
			case 'set_landing':
				BoardsEmail::setLanding($board, BoardsAction::idOrNull($input, 'list_id'), (string)($input['position'] ?? 'bottom'));
				break;
		}
		return array('email' => BoardsEmail::panel($board, $user_id, (string)Boards::roleFor($board, $user_id)));
	});
}

function boards_email_logic_descriptor(): array {
	return array(
		'description'      => 'Read or change a board\'s email-to-board settings: the member\'s own address for the board (and resetting it), the mail domain it uses, and where cards made by email land.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'op'        => array('type' => 'string', 'required' => false, 'label' => 'Operation',
				'enum' => array('panel', 'reset', 'set_domain', 'set_landing')),
			'board_id'  => array('type' => 'int', 'required' => true, 'label' => 'Board'),
			'domain_id' => array('type' => 'int', 'required' => false, 'label' => 'Mail domain'),
			'list_id'   => array('type' => 'int', 'required' => false, 'label' => 'List email cards land in'),
			'position'  => array('type' => 'string', 'required' => false, 'enum' => array('top', 'bottom'), 'label' => 'Top or bottom of the list'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'write'),
		// No 'ai_agent': the AI is default-deny, and a person's secret address is not something
		// an agent reads or resets on their behalf.
	);
}
