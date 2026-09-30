<?php
/**
 * MailboxDeviceRules - mail rules on end-to-end mail, run by the device that
 * can read it (specs/fortress_mobile_apps.md § R14).
 *
 * A mail rule matches on content: sender, subject, body. At arrival on the
 * relay path, and for "also apply to existing mail", an end-to-end row's
 * content is readable only by its owner's browser or phone, so that device
 * evaluates the rules and names the ones that matched. The server applies
 * them: it checks each named rule is an enabled rule in the row's scope and
 * takes the actions from the rule itself (InboundEmailFilter::
 * applyDeviceMatches()), so a device can pick among the owner's rules but
 * never invent an action.
 *
 *  - rulesFor(): the criteria of one mailbox's rules, in evaluation order, for
 *    the parse step (fortress_parse_store takes the matches, `rule_matches`).
 *  - backlog() / outcomes(): the "apply to existing" walk over the caller's
 *    end-to-end rows, one rule at a time, a page at a time. Each owner walks
 *    their own rows and keeps their own place (InboundEmailFilterDeviceProgress,
 *    one row per rule and owner, for the rule's latest request): a domain-wide
 *    rule reaches mailboxes of several owners, and one owner's device finishing
 *    says nothing about another's rows.
 *    Historical mail is never forwarded, as the server's own walk
 *    (ApplyInboundEmailFilters) never forwards.
 *
 * @version 1.2 - each owner's place in its own table, so a whole-row save of the rule cannot move it (review R2)
 * @version 1.1 - progress per owner (review F3); outcomes() refuses a through_id past the rows in reach (F12)
 * @version 1.0
 */

class MailboxDeviceRulesException extends Exception {}

class MailboxDeviceRules {

	const PAGE_SIZE = 100;

	/** What a device reads of each row to evaluate a rule: the opened content, sealed. */
	const ROW_COLUMNS = array('iem_sender', 'iem_subject', 'iem_body_plain', 'iem_body_html', 'iem_recipient');

	/**
	 * One of the caller's mailboxes' rules as a device evaluates them: the
	 * alias's own and its domain's, enabled, in order.
	 *
	 * @return array{alias_id:int, rules:array}
	 */
	public static function rulesFor(int $user_id, int $alias_id): array {
		$alias = self::ownAlias($user_id, $alias_id);
		$rules = array();
		foreach (InboundEmailFilter::inScopeFor($alias_id, intval($alias->get('iea_ied_inbound_email_domain_id'))) as $f) {
			$rules[] = $f->deviceRule();
		}
		return array('alias_id' => $alias_id, 'rules' => $rules);
	}

	/**
	 * The next page of the "apply to existing" walk on end-to-end rows: the
	 * first of the caller's rules with device work left, and up to a page of
	 * the caller's rows in its scope past its cursor. A rule whose walk has
	 * nothing left is closed here, so the answer is `{rule: null}` exactly
	 * when there is no work.
	 */
	public static function backlog(int $user_id): array {
		$db = DbConnector::get_instance()->get_db_link();
		foreach (self::pendingRules($user_id) as $f) {
			$params = array();
			$where = self::rowScope($f, $user_id, self::cursorOf($f, $user_id), $params);
			$stmt = $db->prepare('SELECT iem_inbound_email_message_id, iem_sealed_key, iem_size_bytes,
					' . implode(', ', self::ROW_COLUMNS) . ',
					EXISTS (SELECT 1 FROM ima_inbound_message_attachments
						WHERE ima_iem_inbound_email_message_id = iem_inbound_email_message_id) AS has_attachment
				FROM iem_inbound_email_messages WHERE ' . $where . '
				ORDER BY iem_inbound_email_message_id ASC LIMIT ' . self::PAGE_SIZE);
			$stmt->execute($params);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			if (!$rows) {
				InboundEmailFilterDeviceProgress::record(intval($f->key), $user_id,
					(string)$f->get('ief_device_backlog_requested_time'), self::cursorOf($f, $user_id), true);
				continue;
			}
			$out = array();
			foreach ($rows as $row) {
				$out[] = array(
					'id'             => intval($row['iem_inbound_email_message_id']),
					// An inbound row's routing recipient is clear; a sealed one
					// travels in `sealed` for the device to open.
					'recipient'      => strncmp((string)$row['iem_recipient'], 'v1.edge.', 8) === 0 ? '' : (string)$row['iem_recipient'],
					'size_bytes'     => intval($row['iem_size_bytes']),
					'has_attachment' => self::isTrue($row['has_attachment']),
					'sealed'         => InboundEmailMessage::sealedForBrowser($row, self::ROW_COLUMNS),
				);
			}
			return array('rule' => $f->deviceRule(), 'rows' => $out,
				'through_id' => $out[count($out) - 1]['id']);
		}
		return array('rule' => null, 'rows' => array(), 'through_id' => null);
	}

	/**
	 * The device's verdict on one backlog page: which of the page's rows the
	 * rule matched. Each must be one of the caller's end-to-end rows in the
	 * rule's scope, at or before $through_id and past the cursor; the rule's
	 * actions apply to each (no forward). The cursor moves to $through_id.
	 *
	 * @param int[] $matched_ids
	 * @return array{applied:int, cursor:int}
	 */
	public static function outcomes(int $user_id, int $rule_id, int $through_id, array $matched_ids): array {
		$rule = null;
		foreach (self::pendingRules($user_id) as $f) {
			if (intval($f->key) === $rule_id) {
				$rule = $f;
			}
		}
		if ($rule === null) {
			throw new MailboxDeviceRulesException('That rule has no existing mail left to apply to.');
		}
		$cursor = self::cursorOf($rule, $user_id);
		if ($through_id <= $cursor) {
			return array('applied' => 0, 'cursor' => $cursor);
		}
		$matched_ids = array_values(array_unique(array_map('intval', $matched_ids)));
		$db = DbConnector::get_instance()->get_db_link();
		$params = array();
		$where = self::rowScope($rule, $user_id, $cursor, $params);
		// A page ends at a row backlog() handed out; a through_id past every
		// row in reach would close the walk over rows nobody looked at.
		$max = $db->prepare('SELECT MAX(iem_inbound_email_message_id) FROM iem_inbound_email_messages WHERE ' . $where);
		$max->execute($params);
		if ($through_id > intval($max->fetchColumn())) {
			throw new MailboxDeviceRulesException('That page ends past the mail this rule can reach.');
		}
		$where .= ' AND iem_inbound_email_message_id <= ?';
		$params[] = $through_id;
		$stmt = $db->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE ' . $where);
		$stmt->execute($params);
		$eligible = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
		$stray = array_diff($matched_ids, $eligible);
		if ($stray) {
			throw new MailboxDeviceRulesException('Message ' . reset($stray) . ' is not in this rule\'s reach.');
		}
		$applied = 0;
		foreach ($matched_ids as $id) {
			$msg = new InboundEmailMessage($id, TRUE);
			InboundEmailFilter::applyDeviceMatches($msg, array($rule_id), null, false);
			$applied++;
		}
		InboundEmailFilterDeviceProgress::record(intval($rule->key), $user_id,
			(string)$rule->get('ief_device_backlog_requested_time'), $through_id, false);
		return array('applied' => $applied, 'cursor' => $through_id);
	}

	/** Where $user_id's walk of $f's current request stands: 0 before it starts. */
	private static function cursorOf(InboundEmailFilter $f, int $user_id): int {
		return InboundEmailFilterDeviceProgress::placeFor(intval($f->key), $user_id,
			(string)$f->get('ief_device_backlog_requested_time'))['cursor'];
	}

	/** The caller's own mailbox, or a refusal. */
	private static function ownAlias(int $user_id, int $alias_id): InboundEmailAlias {
		if (!in_array($alias_id, array_map('intval', InboundEmailMailboxGrant::alias_ids_for_user($user_id)), true)) {
			throw new MailboxDeviceRulesException('That is not one of your mailboxes.');
		}
		$alias = new InboundEmailAlias($alias_id, TRUE);
		if (!$alias->key || $alias->get('iea_delete_time')) {
			throw new MailboxDeviceRulesException('That mailbox no longer exists.');
		}
		return $alias;
	}

	/**
	 * The enabled rules with device work left that can reach the caller's
	 * mailboxes: each mailbox's own, and its domain's.
	 *
	 * @return InboundEmailFilter[]
	 */
	private static function pendingRules(int $user_id): array {
		$alias_ids = array_map('intval', InboundEmailMailboxGrant::alias_ids_for_user($user_id));
		if (!$alias_ids) {
			return array();
		}
		$in = implode(',', $alias_ids);
		$stmt = DbConnector::get_instance()->get_db_link()->prepare('SELECT ief_inbound_email_filter_id
			FROM ief_inbound_email_filters
			WHERE ief_device_backlog_requested_time IS NOT NULL
			AND NOT EXISTS (SELECT 1 FROM ifp_inbound_email_filter_device_progress
				WHERE ifp_ief_inbound_email_filter_id = ief_inbound_email_filter_id AND ifp_usr_user_id = ?
				AND ifp_request_time = ief_device_backlog_requested_time AND ifp_done = true)
			AND ief_is_enabled = true AND ief_delete_time IS NULL
			AND (ief_iea_inbound_email_alias_id IN (' . $in . ')
				OR (ief_iea_inbound_email_alias_id IS NULL AND ief_ied_inbound_email_domain_id IN
					(SELECT iea_ied_inbound_email_domain_id FROM iea_inbound_email_aliases
					 WHERE iea_inbound_email_alias_id IN (' . $in . '))))
			ORDER BY ief_inbound_email_filter_id ASC');
		$stmt->execute(array($user_id));
		$out = array();
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$out[] = new InboundEmailFilter(intval($id), TRUE);
		}
		return $out;
	}

	/** The caller's end-to-end, parsed, live, locally-received rows in a rule's scope past $cursor. */
	private static function rowScope(InboundEmailFilter $f, int $user_id, int $cursor, array &$params): string {
		$where = 'iem_sealed_owner_user_id = ? AND ' . InboundEmailMessage::mailKeySql() . '
			AND iem_pending_parse IS NOT TRUE AND iem_delete_time IS NULL
			AND iem_iia_inbound_imap_account_id IS NULL AND iem_inbound_email_message_id > ?';
		$params[] = $user_id;
		$params[] = $cursor;
		if ($f->get('ief_iea_inbound_email_alias_id') !== null) {
			$where .= ' AND iem_iea_inbound_email_alias_id = ?';
			$params[] = intval($f->get('ief_iea_inbound_email_alias_id'));
		} else {
			$where .= ' AND iem_ied_inbound_email_domain_id = ?';
			$params[] = intval($f->get('ief_ied_inbound_email_domain_id'));
		}
		return $where;
	}

	private static function isTrue($v): bool {
		return $v === true || $v === 't' || $v === 1 || $v === '1';
	}
}
