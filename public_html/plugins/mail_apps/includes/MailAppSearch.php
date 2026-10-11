<?php
/**
 * MailAppSearch - the text half of an IMAP SEARCH: which numbered messages in
 * a folder match one criterion that needs the mail's words.
 *
 * Words are found the way the reader finds them, so a program and the
 * reader agree on what matches:
 *   - unsealed mail: TEXT and BODY through mailbox's full-text index
 *     (MailboxService::FULLTEXT_SQL), the header fields as a substring of the
 *     stored column;
 *   - sealed mail, in the window: the person's own sealed search index
 *     (MailboxIndex), which searches a message's whole text whatever the
 *     field — a superset, never a miss;
 *   - HEADER Message-ID: the stored Message-ID, or the one a program gave
 *     its draft (mam_submitted_message_id).
 * Locked, sealed mail matches nothing.
 *
 * @version 1.0.0
 */

class MailAppSearch {

	/** Index fold budget inside one search request (seconds). */
	const FOLD_BUDGET_SECONDS = 3.0;

	/** @return int[] the folder's live numbers whose message matches */
	public static function uids(MailAppContext $ctx, array $folder, string $field, string $value): array {
		$value = trim($value);
		if ($value === '') {
			return array();
		}
		$db = DbConnector::get_instance()->get_db_link();
		$scope = MailAppFolders::scopeSql($ctx, $folder);
		$ids = array();

		if ($field === 'message-id') {
			$stmt = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				LEFT JOIN mam_mail_app_messages ON mam_iem_inbound_email_message_id = iem_inbound_email_message_id
				WHERE $scope AND (iem_message_id_header = ? OR mam_submitted_message_id = ?)");
			$stmt->execute(array(substr($value, 0, 255), substr($value, 0, 255)));
			$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
		} else {
			$columns = array(
				'subject' => array('iem_subject'),
				'from'    => array('iem_sender'),
				'to'      => array('iem_to', 'iem_recipient'),
				'cc'      => array('iem_cc'),
				'bcc'     => array('iem_bcc'),
			);
			if (isset($columns[$field])) {
				$like = array();
				$params = array();
				foreach ($columns[$field] as $col) {
					$like[] = "$col ILIKE ?";
					$params[] = '%' . addcslashes($value, '%_\\') . '%';
				}
				$stmt = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE $scope AND iem_content_sealed IS NOT TRUE AND (" . implode(' OR ', $like) . ')');
				$stmt->execute($params);
			} else {
				$stmt = $db->prepare("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE $scope AND " . MailboxService::FULLTEXT_SQL . " @@ websearch_to_tsquery('english', ?)
					  AND " . MailboxService::FULLTEXT_INDEX_PREDICATE);
				$stmt->execute(array($value));
			}
			$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

			if ($ctx->seals) {
				$key = VaultUnlock::secretKey($ctx->user_id);
				if ($key !== null) {
					try {
						$index = new MailboxIndex();
						$index->fold($ctx->user_id, $key, microtime(true) + self::FOLD_BUDGET_SECONDS);
						$found = array_map('intval', $index->search($ctx->user_id, $value));
						if ($found) {
							$stmt = $db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
								WHERE $scope AND iem_inbound_email_message_id IN (" . implode(',', $found) . ')');
							$ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
						}
					} catch (\Throwable $e) {
						error_log('MailAppSearch: sealed index search failed for alias ' . $ctx->alias_id . ': ' . $e->getMessage());
					}
				}
			}
		}

		$ids = array_values(array_unique($ids));
		if (!$ids) {
			return array();
		}
		$stmt = $db->prepare('SELECT mau_uid FROM mau_mail_app_uids
			WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL
			  AND mau_iem_inbound_email_message_id = ANY(?::bigint[]) ORDER BY mau_uid');
		$stmt->execute(array($ctx->alias_id, $folder['key'], '{' . implode(',', $ids) . '}'));
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}
}
?>
