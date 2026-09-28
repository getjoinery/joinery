<?php
/**
 * Search over end-to-end (Fortress) mail, the server's half
 * (specs/client_custody_mail.md § R5).
 *
 * The server cannot read a Fortress message, so each of the owner's browsers
 * keeps its own sealed word index (plugins/mailbox/assets/mailbox_search*.js)
 * and searches that. The server does two things, both here:
 *
 *   - entries() pages the owner's Fortress messages with their sealed search
 *     text, for a browser to open and index. Two directions: 'old' walks from
 *     a point in time backwards (a browser's first build, newest first), 'new'
 *     walks forwards from where the browser last got to (catching up). The
 *     cursor is (iem_search_written_time, id), not the id: a message's search
 *     text can be written long after the message (a relay message parsed on
 *     the device, a raise to Fortress, a draft turning into its Sent row).
 *   - decodeHits() reads the message ids a browser found, which thread_list
 *     unions with the server's own search (MailboxService::listThreads).
 *
 * Coverage follows the Private index (MailboxIndex § COVERAGE): every Fortress
 * message the owner holds, in any mailbox, folder or delete state, drafts
 * excepted. What a search RETURNS is decided by the read scope of the list.
 *
 * @version 1.1 - OVERLAP_SECONDS documented as the ceiling on a writer's transaction
 * @version 1.0
 */

class MailboxDeviceSearchException extends Exception {}

class MailboxDeviceSearch {

	/** Rows per entries() page. */
	const PAGE_SIZE = 200;

	/** A catch-up's first page reaches back this far behind its cursor: a row
	 *  whose write committed after a later one's is still picked up. The browser
	 *  skips ids it has already indexed. It is also the ceiling on every writer
	 *  of iem_search_written_time: stamp and commit within it (per row or per
	 *  short batch), or a row can land behind a cursor already past it and no
	 *  browser indexes it. */
	const OVERLAP_SECONDS = 600;

	/** The most message ids one search may name. */
	const MAX_HITS = 500000;

	/** A Fortress row's key column starts with this ('mail' holds no LIKE wildcard). */
	const MAIL_KEY_PREFIX = InboundEmailMessage::MAIL_KEY_PREFIX;

	/**
	 * One page of $user_id's Fortress messages with their sealed search text.
	 *
	 * $order 'old': written before ($time, $id), newest first; $time '' starts
	 * from now, and $id 0 takes every row written at $time. $order 'new': written after ($time, $id), oldest first; $time ''
	 * starts from the beginning; $overlap reaches OVERLAP_SECONDS further back
	 * (the first page of a catch-up).
	 *
	 * Returns {entries: [{id, sealed}], next: {time, id}|null, server_time,
	 * total?}; $limit (at most PAGE_SIZE) is for tests; `sealed` is the shape the browser opens ({key, sealed_scope,
	 * sealed_dek, sealed_ad_prefix, iem_search_text}). `total` (with
	 * $with_total) counts every such message, for a build's progress line.
	 *
	 * @throws MailboxDeviceSearchException on a malformed cursor
	 */
	public static function entries(int $user_id, string $order, string $time = '', int $id = 0,
			bool $overlap = false, bool $with_total = false, int $limit = self::PAGE_SIZE): array {
		$limit = max(1, min(self::PAGE_SIZE, $limit));
		if ($order !== 'old' && $order !== 'new') {
			throw new MailboxDeviceSearchException('Order must be "old" or "new".');
		}
		if ($time !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $time)) {
			throw new MailboxDeviceSearchException('That is not a cursor time.');
		}
		$db = DbConnector::get_instance()->get_db_link();
		$server_time = (string)$db->query(
			"SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US')")->fetchColumn();

		$base = 'iem_sealed_owner_user_id = ?
			AND iem_sealed_key LIKE ?
			AND iem_search_written_time IS NOT NULL
			AND iem_direction IS DISTINCT FROM \'draft\'';
		$base_params = array($user_id, self::MAIL_KEY_PREFIX . '%');

		$where = $base;
		$params = $base_params;
		if ($order === 'old') {
			$where .= ' AND (iem_search_written_time, iem_inbound_email_message_id) < (?::timestamp, ?)';
			$params[] = $time !== '' ? $time : $server_time;
			$params[] = ($time !== '' && $id > 0) ? $id : PHP_INT_MAX;
			$sort = 'iem_search_written_time DESC, iem_inbound_email_message_id DESC';
		} else {
			if ($time !== '') {
				if ($overlap) {
					$where .= ' AND iem_search_written_time > ?::timestamp - make_interval(secs => ?)';
					$params[] = $time;
					$params[] = self::OVERLAP_SECONDS;
				} else {
					$where .= ' AND (iem_search_written_time, iem_inbound_email_message_id) > (?::timestamp, ?)';
					$params[] = $time;
					$params[] = $id;
				}
			}
			$sort = 'iem_search_written_time ASC, iem_inbound_email_message_id ASC';
		}

		$stmt = $db->prepare("SELECT iem_inbound_email_message_id, iem_sealed_key, iem_search_text,
				to_char(iem_search_written_time, 'YYYY-MM-DD HH24:MI:SS.US') AS written
			FROM iem_inbound_email_messages
			WHERE $where
			ORDER BY $sort
			LIMIT " . ($limit + 1));
		$stmt->execute($params);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$more = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);

		$entries = array();
		foreach ($rows as $row) {
			$entries[] = array(
				'id'     => (int)$row['iem_inbound_email_message_id'],
				'sealed' => InboundEmailMessage::sealedForBrowser($row, array('iem_search_text')),
			);
		}
		$last = $rows ? $rows[count($rows) - 1] : null;
		$out = array(
			'entries'     => $entries,
			'next'        => ($more && $last) ? array('time' => $last['written'], 'id' => (int)$last['iem_inbound_email_message_id']) : null,
			// Where a caught-up walk got to, for the next catch-up to start from.
			'last'        => $last ? array('time' => $last['written'], 'id' => (int)$last['iem_inbound_email_message_id']) : null,
			'server_time' => $server_time,
		);
		if ($with_total) {
			$count = $db->prepare("SELECT COUNT(*) FROM iem_inbound_email_messages WHERE $base");
			$count->execute($base_params);
			$out['total'] = (int)$count->fetchColumn();
		}
		return $out;
	}

	/**
	 * The message ids a browser's search found: base64 of the ids ascending,
	 * each written as its difference from the one before (the first from 0) in
	 * unsigned LEB128. The browser's encoder is MailboxSearchCore.packIds().
	 *
	 * @return int[]
	 * @throws MailboxDeviceSearchException malformed, not ascending, or over MAX_HITS
	 */
	public static function decodeHits(string $packed): array {
		if ($packed === '') {
			return array();
		}
		$bytes = base64_decode($packed, true);
		if ($bytes === false) {
			throw new MailboxDeviceSearchException('The search results could not be read.');
		}
		$ids = array();
		$value = 0;
		$shift = 0;
		$last = 0;
		$len = strlen($bytes);
		for ($i = 0; $i < $len; $i++) {
			$b = ord($bytes[$i]);
			if ($shift > 49) {
				throw new MailboxDeviceSearchException('The search results could not be read.');
			}
			$value |= ($b & 0x7f) << $shift;
			if ($b & 0x80) {
				$shift += 7;
				continue;
			}
			if ($value === 0 && $ids) {
				throw new MailboxDeviceSearchException('The search results repeat a message.');
			}
			$last += $value;
			$ids[] = $last;
			if (count($ids) > self::MAX_HITS) {
				throw new MailboxDeviceSearchException('Too many search results (more than '
					. number_format(self::MAX_HITS) . ').');
			}
			$value = 0;
			$shift = 0;
		}
		if ($shift !== 0) {
			throw new MailboxDeviceSearchException('The search results could not be read.');
		}
		return $ids;
	}
}
?>
