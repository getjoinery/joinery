<?php
/**
 * BoardsEmail — what Boards does with the mailbox plugin's email pieces.
 *
 *   - each board member's secret address for a board (InboundPersonalAddress);
 *   - which mail domain a board's addresses use, and where a card made by email lands;
 *   - turning a stored mailbox message into a card, for both email-to-board
 *     (BoardsInboundHandler) and the reader's "Make a card" button.
 *
 * Everything here stands behind class_exists('InboundPersonalAddress'): with the
 * mailbox plugin inactive the email features are simply absent and outbound
 * notifications go on working.
 *
 * Nothing in Boards reads mail addressed to anyone outside the site: only a board
 * member's own address, and only that member's own messages, make cards.
 *
 * @version 1.0.2 - a member's own address is written under rule_checked_write() behind the board role
 * @version 1.0.1 - an admin whose board's domain went off is offered the eligible domains, even one
 * @version 1.0.0
 */

class BoardsEmail {

	/** The handler key Boards declares (plugin.json inboundHandlers) and its alias's local part. */
	const HANDLER = 'boards';

	/** An address is always "this member, about this board". */
	const REF_TYPE = 'board';

	/** The most attachments adopted from one message. */
	const MAX_ADOPTED = 10;

	/** The longest card description made from an email. */
	const MAX_DESCRIPTION = 20000;

	/** Is the mailbox plugin's email machinery here? */
	public static function available(): bool {
		return class_exists('InboundPersonalAddress') && class_exists('InboundHandlerRegistry');
	}

	// ------------------------------------------------------------------
	// Domain and address
	// ------------------------------------------------------------------

	/**
	 * Where a board's email stands.
	 *
	 * @return array{status:string, reason:?string, domain:?InboundEmailDomain, chosen:bool}
	 *   status: 'ok' | 'no_mailbox' | 'no_domain' (nothing eligible) | 'domain_off' (the board's
	 *   own choice stopped being eligible)
	 */
	public static function state(Board $board): array {
		if (!self::available()) {
			return array('status' => 'no_mailbox', 'reason' => 'Email-to-board needs the Mailbox plugin.', 'domain' => null, 'chosen' => false);
		}
		$chosen_id = (int)$board->get('bbd_ied_inbound_email_domain_id');
		if ($chosen_id > 0) {
			$domain = new InboundEmailDomain($chosen_id, TRUE);
			if ($domain->key && !$domain->get('ied_delete_time')) {
				$why = InboundHandlerRegistry::domainRefusal($domain);
				if ($why === null) {
					return array('status' => 'ok', 'reason' => null, 'domain' => $domain, 'chosen' => true);
				}
				return array('status' => 'domain_off', 'chosen' => true, 'domain' => $domain,
					'reason' => 'Email is off for this board: ' . $why);
			}
			// The domain it chose is gone: fall through to the default, like a board that never chose.
		}
		$default = InboundHandlerRegistry::defaultDomain();
		if ($default === null) {
			return array('status' => 'no_domain', 'chosen' => false, 'domain' => null,
				'reason' => 'Email-to-board needs a Standard-level mail domain on this site. Ask a site administrator to set one up in the Mailbox plugin.');
		}
		return array('status' => 'ok', 'reason' => null, 'domain' => $default, 'chosen' => false);
	}

	/**
	 * This member's address for the board, or null when email is off for it or they cannot add
	 * cards (viewers have no address). Makes the domain's Boards alias on first use.
	 */
	public static function addressFor(Board $board, int $user_id): ?string {
		$state = self::state($board);
		if ($state['status'] !== 'ok' || !Boards::roleAtLeast(Boards::roleFor($board, $user_id), Boards::ROLE_EDITOR)) {
			return null;
		}
		// An editor on the board (checked above) gets their own address; the
		// address row and the domain's alias are the mailbox's, not theirs.
		return SystemBase::rule_checked_write(function () use ($board, $user_id, $state) {
			$ensured = InboundHandlerRegistry::ensureAlias(self::HANDLER, $state['domain'], false);
			if ($ensured['alias'] === null) {
				return null;
			}
			return InboundPersonalAddress::addressFor(self::HANDLER, $user_id, self::REF_TYPE, (int)$board->key, (int)$state['domain']->key);
		});
	}

	/** Revoke the member's address and give them a new one. */
	public static function resetAddress(Board $board, int $user_id): ?string {
		// The member's own address on a board they can open (the caller's boardFor()).
		SystemBase::rule_checked_write(function () use ($board, $user_id) {
			InboundPersonalAddress::revoke(self::HANDLER, $user_id, self::REF_TYPE, (int)$board->key);
		});
		return self::addressFor($board, $user_id);
	}

	/**
	 * A board admin chooses the mail domain for the board's addresses. Addresses keep their
	 * token across the change, and one already given out at the old domain keeps working
	 * because the alias there is never removed.
	 *
	 * @throws BoardsRefusal
	 */
	public static function setDomain(Board $board, ?int $domain_id): void {
		if (!self::available()) {
			throw new BoardsRefusal('Email-to-board needs the Mailbox plugin.');
		}
		if ($domain_id !== null) {
			$allowed = array();
			foreach (InboundHandlerRegistry::eligibleDomains() as $d) {
				$allowed[(int)$d->key] = $d;
			}
			if (!isset($allowed[$domain_id])) {
				throw new BoardsRefusal('That mail domain cannot be used for board email.');
			}
			// The board's admin chose an eligible domain (the caller's role check):
			// the domain's boards alias is made for them, never switched back on.
			$ensured = SystemBase::rule_checked_write(function () use ($allowed, $domain_id) {
				return InboundHandlerRegistry::ensureAlias(self::HANDLER, $allowed[$domain_id], false);
			});
			if ($ensured['alias'] === null) {
				throw new BoardsRefusal((string)$ensured['reason']);
			}
		}
		Boards::transaction(function () use ($board, $domain_id) {
			Boards::lockAndReload($board);
			$board->set('bbd_ied_inbound_email_domain_id', $domain_id);
			$board->set('bbd_update_time', 'now()');
			$board->save();
			$board->load();
		});
	}

	/**
	 * A board admin chooses where email cards land.
	 *
	 * @throws BoardsRefusal
	 */
	public static function setLanding(Board $board, ?int $list_id, string $position): void {
		if (!in_array($position, array('top', 'bottom'), true)) {
			throw new BoardsRefusal('Unknown position.');
		}
		if ($list_id !== null) {
			BoardsCards::listOn($board, $list_id);
		}
		Boards::transaction(function () use ($board, $list_id, $position) {
			Boards::lockAndReload($board);
			$board->set('bbd_email_bls_list_id', $list_id);
			$board->set('bbd_email_position', $position);
			$board->set('bbd_update_time', 'now()');
			$board->save();
			$board->load();
		});
	}

	/**
	 * The list an email card lands in: the one the admin picked, or the first live list.
	 * Null when the board has no usable list.
	 */
	public static function landingList(Board $board): ?BoardList {
		$list_id = (int)$board->get('bbd_email_bls_list_id');
		if ($list_id > 0) {
			try {
				return BoardsCards::listOn($board, $list_id);
			} catch (BoardsRefusal $e) {
				// archived or gone: fall back to the first list
			}
		}
		$q = DbConnector::GetPreparedStatement(
			'SELECT bls_list_id FROM bls_lists WHERE bls_bbd_board_id = ? AND bls_archived_time IS NULL ORDER BY bls_position, bls_list_id LIMIT 1');
		$q->execute(array((int)$board->key));
		$id = $q->fetchColumn();
		return $id ? new BoardList((int)$id, TRUE) : null;
	}

	/**
	 * Everything the board menu's "Email to this board" panel draws.
	 *
	 * @return array<string,mixed>
	 */
	public static function panel(Board $board, int $user_id, string $role): array {
		$state = self::state($board);
		$out = array(
			'available' => self::available(),
			'status'    => $state['status'],
			'reason'    => $state['reason'],
		);
		if ($state['status'] === 'no_mailbox') {
			return $out;
		}
		$out['can_have_address'] = Boards::roleAtLeast($role, Boards::ROLE_EDITOR);
		$out['address'] = $state['status'] === 'ok' ? self::addressFor($board, $user_id) : null;
		$out['domain'] = $state['domain'] ? (string)$state['domain']->get('ied_domain') : null;
		if ($role === Boards::ROLE_ADMIN) {
			$out['is_admin'] = true;
			// The picker shows when there is a real choice.
			$domains = array();
			foreach (InboundHandlerRegistry::eligibleDomains() as $d) {
				$domains[] = array('id' => (int)$d->key, 'name' => (string)$d->get('ied_domain'));
			}
			// A board whose own domain went off needs a way out, however few remain.
			$out['domains'] = (count($domains) >= 2 || ($state['status'] === 'domain_off' && count($domains) >= 1)) ? $domains : array();
			$out['domain_id'] = $state['domain'] ? (int)$state['domain']->key : null;
			$landing = self::landingList($board);
			$out['landing'] = array(
				'list_id'  => $board->get('bbd_email_bls_list_id') ? (int)$board->get('bbd_email_bls_list_id') : null,
				'effective_list_id' => $landing ? (int)$landing->key : null,
				'position' => (string)$board->get('bbd_email_position') ?: 'bottom',
			);
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Reading a message
	// ------------------------------------------------------------------

	/** The message's text for a card description: the plain body, or the HTML reduced to text. */
	public static function bodyText(InboundEmailMessage $msg): string {
		$text = (string)$msg->get('iem_body_plain');
		if (trim($text) === '') {
			$html = (string)$msg->get('iem_body_html');
			$html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
			$html = preg_replace('#<\s*br\s*/?>|</(p|div|tr|li|h[1-6])\s*>#i', "\n", $html);
			$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		return trim(preg_replace("/\r\n?/", "\n", $text));
	}

	/**
	 * A body without its signature: everything from the standard `-- ` delimiter on, and a
	 * trailing "Sent from my …" line. Quoted replies are left alone.
	 */
	public static function stripSignature(string $body): string {
		$lines = explode("\n", rtrim($body));
		$kept = array();
		foreach ($lines as $line) {
			if (preg_match('/^--\s?$/', rtrim($line, "\r")) && !empty($kept)) {
				break;
			}
			$kept[] = $line;
		}
		while ($kept && preg_match('/^\s*(sent from my\b.*|get outlook for\b.*|sent from mail for\b.*)$/i', end($kept))) {
			array_pop($kept);
		}
		return trim(implode("\n", $kept));
	}

	/** A subject without Re:/Fwd:/Fw: prefixes, on one line. */
	public static function cleanSubject(string $subject): string {
		$subject = trim(preg_replace('/\s+/', ' ', $subject));
		while (preg_match('/^(re|fwd?)\s*:\s*/i', $subject)) {
			$subject = preg_replace('/^(re|fwd?)\s*:\s*/i', '', $subject);
		}
		return trim($subject);
	}

	/**
	 * Split a subject into the title and its shortcuts: `#labelname` applies a board label,
	 * `@name` adds a board member. A shortcut that names nothing on the board (or more than
	 * one thing) stays in the title.
	 *
	 * @return array{title:string, label_ids:int[], member_ids:int[]}
	 */
	public static function parseSubject(Board $board, string $subject): array {
		$subject = self::cleanSubject($subject);

		$labels = array();
		foreach (BoardsPayload::labels((int)$board->key) as $label) {
			$key = self::shortcutKey((string)$label['name']);
			if ($key !== '') {
				$labels[$key][] = (int)$label['id'];
			}
		}
		$people = array();
		foreach (array_keys(Boards::explicitMembers($board)) as $uid) {
			if (User::is_placeholder($uid)) {
				continue;
			}
			$user = new User($uid, TRUE);
			$names = array(
				self::shortcutKey((string)$user->get('usr_nickname')),
				self::shortcutKey((string)$user->get('usr_first_name')),
				self::shortcutKey(trim($user->get('usr_first_name') . $user->get('usr_last_name'))),
				self::shortcutKey((string)$user->display_name()),
			);
			foreach (array_unique(array_filter($names)) as $name) {
				$people[$name][$uid] = $uid;
			}
		}

		$label_ids = array();
		$member_ids = array();
		$title = preg_replace_callback('/(?<![\w])([#@])([\p{L}\p{N}_.\-]+)/u', function ($m) use ($labels, $people, &$label_ids, &$member_ids) {
			$key = self::shortcutKey($m[2]);
			if ($m[1] === '#' && isset($labels[$key]) && count($labels[$key]) === 1) {
				$label_ids[$labels[$key][0]] = $labels[$key][0];
				return '';
			}
			if ($m[1] === '@' && isset($people[$key]) && count($people[$key]) === 1) {
				$uid = reset($people[$key]);
				$member_ids[$uid] = $uid;
				return '';
			}
			return $m[0];
		}, $subject);
		$title = trim(preg_replace('/\s+/', ' ', (string)$title));

		return array('title' => $title, 'label_ids' => array_values($label_ids), 'member_ids' => array_values($member_ids));
	}

	/** A name as a subject shortcut spells it: lowercase, without spaces, dots, dashes or underscores. */
	private static function shortcutKey(string $name): string {
		return preg_replace('/[\s._\-]+/u', '', mb_strtolower(trim($name)));
	}

	// ------------------------------------------------------------------
	// Making the card
	// ------------------------------------------------------------------

	/**
	 * Make a card from a stored message.
	 *
	 * The caller has already decided WHO acts and checked their role. This reads the message's
	 * text, adopts its attachments (no bytes copied) and records which message it came from;
	 * one live card per message, so the same message cannot make two.
	 *
	 * @param array $opts title, description (override the message's), at ('top'|'bottom'),
	 *   source ('email' | 'web'), shortcuts (bool, parse #label/@name from the subject),
	 *   backlink (bool)
	 * @return array{card:BoardCard, skipped:int} skipped = attachments not adopted
	 * @throws BoardsRefusal when this message already has a card, or the board cannot take one
	 */
	public static function createFromMessage(Board $board, BoardList $list, int $user_id, InboundEmailMessage $msg, array $opts = array()): array {
		$message_id = (int)$msg->key;
		if (self::cardForMessage($message_id) !== null) {
			throw new BoardsRefusal('That email already has a card.');
		}

		$subject = (string)$msg->get('iem_subject');
		$parsed = !empty($opts['shortcuts'])
			? self::parseSubject($board, $subject)
			: array('title' => self::cleanSubject($subject), 'label_ids' => array(), 'member_ids' => array());
		$title = isset($opts['title']) && trim((string)$opts['title']) !== '' ? (string)$opts['title'] : $parsed['title'];
		if (trim($title) === '') {
			$title = '(no subject)';
		}
		$description = array_key_exists('description', $opts)
			? (string)$opts['description']
			: self::stripSignature(self::bodyText($msg));
		if (mb_strlen($description) > self::MAX_DESCRIPTION) {
			$description = mb_substr($description, 0, self::MAX_DESCRIPTION) . "\n\n[…]";
		}

		// Only people who may be put on a card: the shortcut already limited them to the board.
		$member_ids = array_values(array_filter($parsed['member_ids'], function ($uid) use ($user_id) {
			return (int)$uid !== $user_id;
		}));

		$skipped = 0;
		try {
			$card = BoardsCards::create($board, $list, $user_id, $title, array(
				'description' => $description,
				'at'          => ($opts['at'] ?? 'bottom') === 'top' ? 'top' : 'bottom',
				'source'      => ($opts['source'] ?? 'email') === 'web' ? BoardCard::SOURCE_WEB : BoardCard::SOURCE_EMAIL,
				'label_ids'   => $parsed['label_ids'],
				'member_ids'  => $member_ids,
				'message_id'  => $message_id,
			));
		} catch (\Throwable $e) {
			// The unique index on the message: two deliveries racing for one card.
			if (self::cardForMessage($message_id) !== null) {
				throw new BoardsRefusal('That email already has a card.');
			}
			throw $e;
		}

		BoardsUploads::adoptMessageAttachments($card, $board, $user_id, $msg, self::MAX_ADOPTED, $skipped);
		return array('card' => $card, 'skipped' => $skipped);
	}

	/** The live card made from a message, or null. */
	public static function cardForMessage(int $message_id): ?BoardCard {
		if ($message_id <= 0) {
			return null;
		}
		$q = DbConnector::GetPreparedStatement(
			'SELECT bcd_card_id FROM bcd_cards WHERE bcd_iem_message_id = ? AND bcd_delete_time IS NULL LIMIT 1');
		$q->execute(array($message_id));
		$id = $q->fetchColumn();
		return $id ? new BoardCard((int)$id, TRUE) : null;
	}

	/**
	 * What the card's back link says about the email it came from, for one viewer.
	 *
	 * @return array{state:string, url:?string} state: 'open' (they can read it), 'gone' (the
	 *   email was deleted), 'closed' (it is in a mailbox they cannot open)
	 */
	public static function sourceEmail(BoardCard $card, int $user_id): ?array {
		$message_id = (int)$card->get('bcd_iem_message_id');
		if ($message_id <= 0) {
			return null;
		}
		if (!self::available()) {
			return array('state' => 'closed', 'url' => null);
		}
		$q = DbConnector::GetPreparedStatement('SELECT 1 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
		$q->execute(array($message_id));
		if (!$q->fetchColumn()) {
			return array('state' => 'gone', 'url' => null);
		}
		$viewer = MailboxViewer::forUser($user_id, Boards::userPermission($user_id));
		if (!$viewer->canReadMessage(new InboundEmailMessage($message_id, TRUE))) {
			return array('state' => 'closed', 'url' => null);
		}
		return array('state' => 'open', 'url' => '/profile/mailbox/mailbox?open=' . $message_id);
	}
}
