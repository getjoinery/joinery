<?php
/**
 * MailAppAction - what every mail_apps/mail_* action shares: resolving the
 * mailbox and folder it names, turning a refusal into an answer the gateway
 * can put on the wire, and the descriptor that admits only `mail` app
 * credentials (ApiAuth matches `app_purpose`).
 *
 * The gateway names folders by the key the folder listing gave it
 * (MailAppFolders), and message numbers as an IMAP UID set ("1:5,7,9:*") — the
 * program's own words, so "mark everything read" is one short request, not a
 * list of a hundred thousand numbers.
 *
 * @version 1.0.0
 */

class MailAppAction {

	/** Run an action body with the mailbox it names resolved. */
	public static function run(array $input, callable $body): LogicResult {
		try {
			$ctx = MailAppContext::forMailbox((string)($input['mailbox'] ?? ''));
			return $body($ctx, $input);
		} catch (MailAppRefusal $e) {
			return $e->toLogicResult();
		}
	}

	/** The folder an action names by key. @throws MailAppRefusal */
	public static function folder(MailAppContext $ctx, string $key): array {
		$folder = MailAppFolders::byKey($ctx, $key);
		if ($folder === null) {
			throw new MailAppRefusal('That folder no longer exists.', 'NONEXISTENT');
		}
		return $folder;
	}

	/**
	 * The live numbers an IMAP UID set names in a folder, ascending. `*` is
	 * the folder's highest number; a range may be written either way round
	 * (RFC 3501 § 9).
	 *
	 * @return int[]
	 */
	public static function uidSet(MailAppContext $ctx, array $folder, string $set): array {
		$set = preg_replace('/\s+/', '', $set);
		if ($set === '' || !preg_match('/^(\d+|\*)(:(\d+|\*))?(,(\d+|\*)(:(\d+|\*))?)*$/', $set)) {
			throw new MailAppRefusal('That message set is not one I can read.', 'CLIENTBUG');
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT COALESCE(MAX(mau_uid), 0) FROM mau_mail_app_uids
			WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL');
		$stmt->execute(array($ctx->alias_id, $folder['key']));
		$max = intval($stmt->fetchColumn());

		$conditions = array();
		$params = array($ctx->alias_id, $folder['key']);
		foreach (explode(',', $set) as $piece) {
			$ends = explode(':', $piece);
			$a = $ends[0] === '*' ? $max : intval($ends[0]);
			$b = isset($ends[1]) ? ($ends[1] === '*' ? $max : intval($ends[1])) : $a;
			$conditions[] = '(mau_uid BETWEEN ? AND ?)';
			$params[] = min($a, $b);
			$params[] = max($a, $b);
		}
		$stmt = $db->prepare('SELECT mau_uid FROM mau_mail_app_uids
			WHERE mau_iea_inbound_email_alias_id = ? AND mau_folder = ? AND mau_expunged_time IS NULL
			  AND (' . implode(' OR ', $conditions) . ') ORDER BY mau_uid');
		$stmt->execute($params);
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/** A source => destination map as [[source, destination], ...]: JSON keeps the order, and numbers stay numbers. */
	public static function pairs(array $map): array {
		$out = array();
		foreach ($map as $src => $dst) {
			$out[] = array(intval($src), intval($dst));
		}
		return $out;
	}

	/**
	 * The descriptor every gateway action declares: a signed-in request, for
	 * `mail` app credentials only, naming the mailbox it is about.
	 */
	public static function descriptor(string $description, array $input = array()): array {
		return array(
			'requires_session' => true,
			'requires_setting' => 'mail_apps_enabled',
			'app_purpose'      => 'mail',
			'description'      => $description,
			'input'            => array(
				'mailbox' => array('type' => 'string', 'required' => true, 'label' => 'Mailbox address', 'max_length' => 320),
			) + $input,
		);
	}
}
?>
