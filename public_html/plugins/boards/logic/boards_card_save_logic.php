<?php
/**
 * API action: boards/boards_card_save — make a card, change it, and everything
 * you do to a card except moving it.
 *
 * POST /api/v1/action/boards/boards_card_save
 *
 * One action, chosen by `op`. Needs an editor unless noted; an archived card is
 * read-only until restored.
 *
 *   create         list_id, title [description, due, member_ids, label_ids,
 *                  prev_id, next_id | at (top|bottom), from_card_id (copy a template card)]
 *   update         card_id [title, description, mentions, due, is_done]
 *   archive        card_id
 *   restore        card_id
 *   delete         card_id                     — board admin
 *   copy           card_id, list_id [title, keep_members, prev_id, next_id]  — onto any board the person can edit
 *   set_template   card_id, is_template
 *   add_member     card_id, user_id            — the person must be able to open the board
 *   remove_member  card_id, user_id
 *   add_label      card_id, label_id
 *   remove_label   card_id, label_id
 *   watch          card_id                     — a viewer may; so may anyone who can open it
 *   unwatch        card_id
 *   set_cover      card_id, attachment_id      — 0 clears the cover
 *   add_link       card_id, url [name]
 *   remove_attachment  card_id, attachment_id
 *
 * Returns the card as the board draws it; with `detail`, the whole card back too.
 *
 * @version 1.0.0
 */

function boards_card_save_logic(array $input): LogicResult {
	return BoardsAction::run(function (int $user_id) use ($input) {
		$op = BoardsAction::oneOf($input['op'] ?? '', array(
			'create', 'update', 'archive', 'restore', 'delete', 'copy', 'set_template',
			'add_member', 'remove_member', 'add_label', 'remove_label', 'watch', 'unwatch',
			'set_cover', 'add_link', 'remove_attachment',
		), 'operation');
		$want_detail = !empty($input['detail']);

		// ---- create: the one op that starts from a list ----------------------
		if ($op === 'create') {
			$list_id = (int)($input['list_id'] ?? 0);
			if ($list_id <= 0 || !BoardList::check_if_exists($list_id)) {
				throw new BoardsRefusal('List not found.');
			}
			$list = new BoardList($list_id, TRUE);
			$board = Boards::boardFor($list->get('bls_bbd_board_id'), $user_id, 'editor');
			$list = BoardsCards::listOn($board, $list_id);

			$placement = array(
				'prev_id' => BoardsAction::idOrNull($input, 'prev_id'),
				'next_id' => BoardsAction::idOrNull($input, 'next_id'),
				'at'      => ($input['at'] ?? 'bottom') === 'top' ? 'top' : 'bottom',
			);
			if (!empty($input['from_card_id'])) {
				list($template, $template_board) = Boards::cardFor($input['from_card_id'], $user_id, 'viewer');
				if ((int)$template_board->key !== (int)$board->key) {
					throw new BoardsRefusal('Card not found.');
				}
				$card = BoardsCards::copy($template, $board, $board, $list, $user_id,
					array('title' => isset($input['title']) && trim((string)$input['title']) !== '' ? $input['title'] : null) + $placement);
			} else {
				$card = BoardsCards::create($board, $list, $user_id, (string)($input['title'] ?? ''), $placement + array(
					'description' => $input['description'] ?? '',
					'due'         => array_key_exists('due', $input) ? Boards::utcFromInput($input['due']) : null,
					'member_ids'  => BoardsAction::ids($input['member_ids'] ?? array()),
					'label_ids'   => BoardsAction::ids($input['label_ids'] ?? array()),
				));
			}
			return boards_card_save_result($card, $board, $user_id, $want_detail);
		}

		// ---- everything else starts from a card ------------------------------
		$minimum = in_array($op, array('watch', 'unwatch'), true) ? 'viewer' : ($op === 'delete' ? 'admin' : 'editor');
		list($card, $board) = Boards::cardFor($input['card_id'] ?? 0, $user_id, $minimum, in_array($op, array('restore', 'delete'), true) || $minimum === 'viewer');

		switch ($op) {
			case 'update':
				$changes = array();
				foreach (array('title', 'description', 'is_done') as $field) {
					if (array_key_exists($field, $input)) {
						$changes[$field] = $input[$field];
					}
				}
				if (array_key_exists('due', $input)) {
					$changes['due'] = Boards::utcFromInput($input['due']);
				}
				BoardsCards::update($card, $board, $user_id, $changes, BoardsAction::ids($input['mentions'] ?? array()));
				break;

			case 'archive':
				BoardsCards::archive($card, $board, $user_id);
				return array('card_id' => (int)$card->key, 'archived' => true);

			case 'restore':
				BoardsCards::restore($card, $board, $user_id);
				break;

			case 'delete':
				BoardsCards::delete($card, $board, $user_id);
				return array('card_id' => (int)$card->key, 'deleted' => true);

			case 'copy':
				$target_list_id = (int)($input['list_id'] ?? 0);
				if ($target_list_id <= 0 || !BoardList::check_if_exists($target_list_id)) {
					throw new BoardsRefusal('List not found.');
				}
				$target = new BoardList($target_list_id, TRUE);
				$target_board = Boards::boardFor($target->get('bls_bbd_board_id'), $user_id, 'editor');
				$target = BoardsCards::listOn($target_board, $target_list_id);
				$copy = BoardsCards::copy($card, $board, $target_board, $target, $user_id, array(
					'title'        => isset($input['title']) && trim((string)$input['title']) !== '' ? $input['title'] : null,
					'keep_members' => !empty($input['keep_members']),
					'prev_id'      => BoardsAction::idOrNull($input, 'prev_id'),
					'next_id'      => BoardsAction::idOrNull($input, 'next_id'),
				));
				return boards_card_save_result($copy, $target_board, $user_id, $want_detail)
					+ array('board_id' => (int)$target_board->key);

			case 'set_template':
				Boards::transaction(function () use ($card, $board, $user_id, $input) {
					Boards::lockAndReload($card);
					$card->set('bcd_is_template', !empty($input['is_template']));
					$card->save();
					$card->load();
					Boards::log((int)$board->key, (int)$card->key, $user_id, 'card.updated', array('quiet' => true, 'what' => 'template'));
				});
				break;

			case 'add_member':
				BoardsCards::addMember($card, $board, $user_id, (int)($input['user_id'] ?? 0));
				break;
			case 'remove_member':
				BoardsCards::removeMember($card, $board, $user_id, (int)($input['user_id'] ?? 0));
				break;
			case 'add_label':
				BoardsCards::addLabel($card, $board, $user_id, (int)($input['label_id'] ?? 0));
				break;
			case 'remove_label':
				BoardsCards::removeLabel($card, $board, $user_id, (int)($input['label_id'] ?? 0));
				break;
			case 'watch':
				BoardsCards::setWatching($card, $user_id, true);
				break;
			case 'unwatch':
				BoardsCards::setWatching($card, $user_id, false);
				break;
			case 'set_cover':
				BoardsCards::setCover($card, $board, $user_id, (int)($input['attachment_id'] ?? 0));
				break;
			case 'add_link':
				BoardsCards::attachLink($card, $board, $user_id, (string)($input['url'] ?? ''), $input['name'] ?? null);
				break;
			case 'remove_attachment':
				BoardsCards::removeAttachment(BoardsCards::attachmentOn($card, $input['attachment_id'] ?? 0), $card, $board, $user_id);
				break;
		}
		return boards_card_save_result($card, $board, $user_id, $want_detail);
	});
}

/** The card as the board draws it, and (when asked) the whole card back. */
function boards_card_save_result(BoardCard $card, Board $board, int $user_id, bool $want_detail): array {
	$card = new BoardCard((int)$card->key, TRUE);
	$out = array('card' => BoardsPayload::cardSummary((int)$card->key));
	if ($want_detail) {
		$out['detail'] = BoardsPayload::cardDetail($card, $board, $user_id, Boards::roleFor($board, $user_id));
	}
	return $out;
}

function boards_card_save_logic_descriptor(): array {
	return array(
		'description'      => 'Create a card, edit it, archive or restore it, copy it, or change its members, labels, cover, links and watchers.',
		'requires_session' => true,
		'requires_setting' => 'boards_active',
		'mutates'          => true,
		'input'            => array(
			'op'            => array('type' => 'string', 'required' => true, 'label' => 'Operation', 'enum' => array(
				'create', 'update', 'archive', 'restore', 'delete', 'copy', 'set_template',
				'add_member', 'remove_member', 'add_label', 'remove_label', 'watch', 'unwatch',
				'set_cover', 'add_link', 'remove_attachment')),
			'card_id'       => array('type' => 'int', 'required' => false, 'label' => 'Card'),
			'list_id'       => array('type' => 'int', 'required' => false, 'label' => 'List'),
			'title'         => array('type' => 'string', 'required' => false, 'max_length' => 1000, 'label' => 'Title'),
			'description'   => array('type' => 'text', 'required' => false, 'label' => 'Description'),
			'mentions'      => array('type' => 'array', 'required' => false, 'items' => array('type' => 'int'), 'max_items' => 50, 'label' => 'Mentioned people'),
			'due'           => array('type' => 'string', 'required' => false, 'max_length' => 40, 'label' => 'Due (ISO 8601; empty clears)'),
			'is_done'       => array('type' => 'bool', 'required' => false, 'label' => 'Done'),
			'is_template'   => array('type' => 'bool', 'required' => false, 'label' => 'Is a template'),
			'user_id'       => array('type' => 'int', 'required' => false, 'label' => 'Person'),
			'member_ids'    => array('type' => 'array', 'required' => false, 'items' => array('type' => 'int'), 'max_items' => 50, 'label' => 'People'),
			'label_id'      => array('type' => 'int', 'required' => false, 'label' => 'Label'),
			'label_ids'     => array('type' => 'array', 'required' => false, 'items' => array('type' => 'int'), 'max_items' => 50, 'label' => 'Labels'),
			'attachment_id' => array('type' => 'int', 'required' => false, 'label' => 'Attachment'),
			'url'           => array('type' => 'string', 'required' => false, 'max_length' => 2000, 'label' => 'Link'),
			'name'          => array('type' => 'string', 'required' => false, 'max_length' => 255, 'label' => 'Link name'),
			'prev_id'       => array('type' => 'int', 'required' => false, 'label' => 'Card above'),
			'next_id'       => array('type' => 'int', 'required' => false, 'label' => 'Card below'),
			'at'            => array('type' => 'string', 'required' => false, 'enum' => array('top', 'bottom'), 'label' => 'Place at'),
			'from_card_id'  => array('type' => 'int', 'required' => false, 'label' => 'Template card'),
			'keep_members'  => array('type' => 'bool', 'required' => false, 'label' => 'Copy the members too'),
			'detail'        => array('type' => 'bool', 'required' => false, 'label' => 'Return the whole card'),
		),
		'auth'             => array('capability' => 'write'),
		// The AI can draft cards ("draft cards from this email thread"); every
		// call waits in the owner's approval queue.
		'ai_agent'         => 'confirm',
	);
}
