<?php
/**
 * BoardsInboundHandler — what happens to mail sent to a board member's own address.
 *
 * The mailbox plugin stores every message to boards@<domain> (or boards+<token>@<domain>)
 * and then hands the stored row here. The token in the address says WHO is writing and
 * WHICH board, and the standard check (docs in the mailbox plugin, "Personal addresses")
 * makes sure it really is them:
 *
 *   1. the token resolves to a member and a board;
 *   2. the message is from that member's own address and the sending domain vouches for it
 *      (InboundPersonalAddress::senderIs);
 *   3. their account is active, the board is open to them as an editor, and it is not archived.
 *
 * Mail that fails a check is left stored and not acted on. Only a failure of the second check
 * tells the member anything ("an email to your board address came from an address that isn't
 * yours"), and only once an hour, so a leaked address cannot be used to flood their
 * notifications.
 *
 * Declared in plugin.json (`inboundHandlers`). Implements the mailbox plugin's interface, so
 * it exists only while that plugin is active.
 *
 * @version 1.0.0
 */

class BoardsInboundHandler implements InboundAddressHandler {

	/** Seconds between two "came from an address that isn't yours" notices for one member. */
	const REFUSAL_NOTICE_INTERVAL = 3600;

	public function handle(InboundEmailMessage $msg, ?string $tag): bool {
		$address = InboundPersonalAddress::resolve(BoardsEmail::HANDLER, $tag);
		if ($address === null || $address->get('ipa_ref_type') !== BoardsEmail::REF_TYPE) {
			return false;      // not a Boards address: not ours to act on
		}
		$user_id = (int)$address->get('ipa_usr_user_id');
		$board_id = (int)$address->get('ipa_ref_id');

		if (!User::check_if_exists($user_id)) {
			return false;
		}
		$user = new User($user_id, TRUE);
		if (!InboundPersonalAddress::userIsActive($user)) {
			return false;
		}
		if (!Board::check_if_exists($board_id)) {
			return false;
		}
		$board = new Board($board_id, TRUE);
		if ($board->get('bbd_delete_time')) {
			return false;
		}

		if (!InboundPersonalAddress::senderIs($msg, $user)) {
			self::tellRefused($user_id, $board);
			return false;
		}

		// The role is read now, not when the address was made: an address held by someone
		// who has since lost access is harmless.
		if (!Boards::roleAtLeast(Boards::roleFor($board, $user_id), Boards::ROLE_EDITOR) || $board->is_archived()) {
			return false;
		}

		$list = BoardsEmail::landingList($board);
		if ($list === null) {
			return false;      // a board with no list has nowhere to put a card
		}

		try {
			BoardsEmail::createFromMessage($board, $list, $user_id, $msg, array(
				'at'        => (string)$board->get('bbd_email_position') === 'top' ? 'top' : 'bottom',
				'source'    => BoardCard::SOURCE_EMAIL,
				'shortcuts' => true,
			));
		} catch (BoardsRefusal $e) {
			// A message delivered twice: the card is already there. Anything else (the board
			// is at its card ceiling) leaves the message stored for an admin to see.
			return $e->getMessage() === 'That email already has a card.';
		}
		return true;
	}

	/**
	 * One in-app notice that a message to the member's board address was not theirs.
	 * Rate-limited per member: the address is a secret, but a leaked one should not turn
	 * into a notification flood.
	 */
	private static function tellRefused(int $user_id, Board $board): void {
		$q = DbConnector::GetPreparedStatement(
			"SELECT 1 FROM ntf_notifications WHERE ntf_usr_user_id = ? AND ntf_title LIKE ? AND ntf_create_time > now() - (? * interval '1 second') LIMIT 1");
		$q->execute(array($user_id, '[%] Email not used', self::REFUSAL_NOTICE_INTERVAL));
		if ($q->fetchColumn()) {
			return;
		}
		SignalBus::dispatch('boards.email_refused', array(
			'board_name' => mb_substr(trim(preg_replace('/\s+/', ' ', (string)$board->get('bbd_name'))), 0, 80),
			'board_url'  => '/profile/boards/board?id=' . (int)$board->key,
			'summary'    => 'An email to your board address came from an address that isn\'t yours, so no card was made.',
			'recipients' => array($user_id),
		));
	}
}
