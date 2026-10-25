<?php
/**
 * API action: boards/boards_comment_save — comment on a card.
 *
 * POST /api/v1/action/boards/boards_comment_save
 *
 *   create   card_id, body [mentions]   — an editor
 *   update   card_id, comment_id, body  — the comment's own author
 *   delete   card_id, comment_id        — its author, or a board admin
 *
 * The body is Markdown. `mentions` are the people the author @mentioned; a person
 * is only told if their @name is in the text and they can open the board. A person
 * who is mentioned is sent the mention and nothing more for the same comment.
 *
 * @version 1.0.0
 */

function boards_comment_save_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$op = BoardsAction::oneOf($input['op'] ?? '', array('create', 'update', 'delete'), 'operation');
		list($card, $board) = Boards::cardFor($input['card_id'] ?? 0, $user_id, 'editor');
		$role = Boards::roleFor($board, $user_id);

		switch ($op) {
			case 'create':
				BoardsCards::addComment($card, $board, $user_id, (string)($input['body'] ?? ''),
					BoardsAction::ids($input['mentions'] ?? array()), BoardComment::SOURCE_WEB);
				break;
			case 'update':
				BoardsCards::editComment(BoardsCards::commentOn($card, $input['comment_id'] ?? 0), $card, $board, $user_id, (string)($input['body'] ?? ''));
				break;
			case 'delete':
				BoardsCards::deleteComment(BoardsCards::commentOn($card, $input['comment_id'] ?? 0), $card, $board, $user_id, $role);
				break;
		}
		return array(
			'comments' => BoardsPayload::comments((int)$card->key, $user_id, $role),
			'card'     => BoardsPayload::cardSummary((int)$card->key),
		);
	});
}

function boards_comment_save_logic_descriptor(): array {
	return array(
		'description'      => 'Add, edit or remove a comment on a card.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'op'         => array('type' => 'string', 'required' => true, 'label' => 'Operation', 'enum' => array('create', 'update', 'delete')),
			'card_id'    => array('type' => 'int', 'required' => true, 'label' => 'Card'),
			'comment_id' => array('type' => 'int', 'required' => false, 'label' => 'Comment'),
			'body'       => array('type' => 'text', 'required' => false, 'label' => 'Comment'),
			'mentions'   => array('type' => 'array', 'required' => false, 'items' => array('type' => 'int'), 'max_items' => 50, 'label' => 'Mentioned people'),
		),
		'auth'             => array('min_user_permission' => 0, 'capability' => 'write'),
		'ai_agent'         => 'confirm',
	);
}
