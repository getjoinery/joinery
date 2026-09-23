<?php
/**
 * MailboxSendAttempt - One row per time the platform tried to hand a mailbox
 * message to a carrier (specs/mailbox_message_timeline.md A2).
 *
 * Two writers, one shape:
 *   - a `compose` row from MailboxSender::send() — a reply, forward or new message
 *     a person sent from the reader, whether the carrier took it or refused it;
 *   - a `forward` row from InboundEmailRouter — an inbound message the router
 *     relayed to the alias's destinations, or a filter forwarded onward.
 *
 * The row answers, for the message timeline: when was it attempted, as whom,
 * through what, to whom, did the carrier take it, what did it say, and — asked
 * later — did it arrive. A failed attempt leaves a row like any other; before this
 * table a refused send left nothing but the error toast the person saw once.
 *
 * Delivery status (mst_delivery_*) is a CACHE of what the carrier said when the
 * timeline last asked (A4), never a value the send itself writes: a send knows
 * acceptance, not arrival.
 *
 * On a Private mailbox the recipient list and the carrier's error
 * text can name correspondents, so they seal to the mailbox owner's vault like
 * the message's own content. Message-ID, transport, outcome and timing carry no
 * content and stay plain. Sealing needs only the owner's public key, so a row
 * seals from any process; a sealing mailbox whose owner has no vault gets a row
 * WITHOUT those two fields rather than a plaintext one (see record()).
 *
 * @version 1.0.1 - comment wording: Private plus the relay-sealing and sending-lock add-ons
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailboxSendAttemptException extends SystemBaseException {}

class MailboxSendAttempt extends SystemBase {
	public static $prefix = 'mst';
	public static $tablename = 'mst_mailbox_send_attempts';
	public static $pkey_column = 'mst_mailbox_send_attempt_id';

	const KIND_COMPOSE = 'compose';
	const KIND_FORWARD = 'forward';

	const OUTCOME_SENT    = 'sent';     // the carrier took it for every recipient
	const OUTCOME_FAILED  = 'failed';   // the carrier took it for none
	const OUTCOME_PARTIAL = 'partial';  // some recipients (Direct delivered some, the carrier failed some)

	const SENT_COPY_PROVIDER      = 'provider';       // the account's SMTP filed the Sent copy itself
	const SENT_COPY_APPENDED      = 'appended';       // we APPENDed it to the account's Sent folder
	const SENT_COPY_APPEND_FAILED = 'append_failed';
	const SENT_COPY_NOT_APPLICABLE = 'not_applicable'; // hosted alias / forward: there is no remote Sent

	public static $sealed_fields = array('mst_recipients', 'mst_error');

	protected static $foreign_key_actions = array(
		// A mailbox going away takes its send history with it.
		'mst_iea_inbound_email_alias_id' => array('action' => 'cascade'),
		'mst_usr_user_id' => array('action' => 'null'),
		// The rows a deleted message linked stay: an attempt is a fact about what
		// left the building, and the failed ones never had a message row anyway.
		'mst_iem_inbound_email_message_id' => array('action' => 'null'),
		'mst_source_iem_inbound_email_message_id' => array('action' => 'null', 'source_table' => 'iem_inbound_email_messages'),
	);

	public static $field_specifications = array(
		'mst_mailbox_send_attempt_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mst_kind'                    => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'compose'),
		'mst_iea_inbound_email_alias_id' => array('type'=>'int4', 'index'=>true),
		'mst_usr_user_id'             => array('type'=>'int8'),
		// The outbound row on success / the draft row the send came from; NULL for
		// an ad-hoc compose that failed and for every forward.
		'mst_iem_inbound_email_message_id' => array('type'=>'int8', 'index'=>true),
		// The message replied to / forwarded, when any.
		'mst_source_iem_inbound_email_message_id' => array('type'=>'int8', 'index'=>true),
		// The Message-ID on the wire: ours for a compose, the original sender's for
		// a forward (the raw message relays unchanged). The key every carrier lookup uses.
		'mst_message_id_header'       => array('type'=>'varchar(255)', 'index'=>true),
		'mst_from_address'            => array('type'=>'varchar(255)'),
		// JSON [{email, name, kind}] kind = to|cc|bcc|forward. Sealed on a protected mailbox.
		'mst_recipients'              => array('type'=>'text'),
		// Provider key (mailgun, smtp, ses…), or connected_account / joinery_direct.
		'mst_transport'               => array('type'=>'varchar(40)'),
		'mst_transport_label'         => array('type'=>'varchar(120)'),
		'mst_outcome'                 => array('type'=>'varchar(10)', 'is_nullable'=>false),
		// The transport's own error text on failure. Sealed on a protected mailbox.
		'mst_error'                   => array('type'=>'text'),
		// JSON {id, response}: what the carrier said on acceptance (A3).
		'mst_receipt'                 => array('type'=>'text'),
		// JSON [addresses] Joinery Direct delivered before any carrier was involved.
		'mst_direct_delivered'        => array('type'=>'text'),
		'mst_sent_copy_filed'         => array('type'=>'varchar(20)'),
		// What the carrier said when last asked (A4) — a cache, see the class comment.
		'mst_delivery_status'         => array('type'=>'varchar(12)', 'is_nullable'=>false, 'default'=>'unknown'),
		'mst_delivery_detail'         => array('type'=>'text'),
		'mst_delivery_checked_time'   => array('type'=>'timestamp(6)'),
		// Sealing metadata (SystemBase conventions): the flag, the wrapped DEK, the
		// owner whose vault wraps it, and their key generation at seal time.
		'mst_content_sealed'          => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'mst_sealed_key'              => array('type'=>'text'),
		'mst_key_generation'          => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'mst_sealed_owner_user_id'    => array('type'=>'int8'),
		'mst_create_time'             => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	/**
	 * Seal when the mailbox seals its mail: the alias's posture, falling back to
	 * the domain's when the row names no alias. The same rule the message row
	 * follows, so a timeline never shows recipients in the clear beside a sealed
	 * message.
	 */
	protected static function shouldSeal(array $row): bool {
		$alias_id = intval($row['mst_iea_inbound_email_alias_id'] ?? 0);
		if ($alias_id <= 0) {
			return false;
		}
		$alias = new InboundEmailAlias($alias_id, TRUE);
		return $alias->key ? (bool)$alias->seals_content() : false;
	}

	/**
	 * Whose vault: the owner recorded at seal time; for a row being sealed for
	 * the first time, the mailbox's seal owner — never the person who pressed
	 * send, who may be a grantee rather than the owner.
	 */
	protected static function sealedOwnerUserIdFor(array $row): ?int {
		$recorded = parent::sealedOwnerUserIdFor($row);
		if ($recorded !== null) {
			return $recorded;
		}
		$alias_id = intval($row['mst_iea_inbound_email_alias_id'] ?? 0);
		if ($alias_id <= 0) {
			return null;
		}
		$alias = new InboundEmailAlias($alias_id, TRUE);
		$domain_id = $alias->key ? intval($alias->get('iea_ied_inbound_email_domain_id')) : 0;
		return InboundEmailMessage::sealOwnerUserId($alias_id, $domain_id > 0 ? $domain_id : null);
	}

	/**
	 * Write one attempt. Never throws: a failure to record is error_logged and
	 * returns null, because the record must never cost the message it describes.
	 *
	 * $fields are column => value with the mst_ prefix; arrays are JSON-encoded.
	 * On a sealing mailbox whose owner has no vault, the two content fields are
	 * dropped rather than written in the clear.
	 *
	 * @return ?int the new row's id
	 */
	public static function record(array $fields): ?int {
		try {
			// Protected mailbox, nobody to seal to: keep the fact of the attempt,
			// not the words. (The send itself already refused on this condition
			// where it stores content; this is the failed-send path, which has
			// nothing else to refuse.)
			$drop = self::shouldSeal($fields) && self::sealedOwnerUserIdFor($fields) === null
				? self::$sealed_fields : array();

			$row = new MailboxSendAttempt(NULL);
			foreach ($fields as $col => $value) {
				if (in_array($col, $drop, true)) {
					continue;
				}
				if (is_array($value)) {
					$value = json_encode($value, JSON_UNESCAPED_UNICODE);
				}
				if ($value === null || $value === '') {
					continue;
				}
				$row->set($col, $value);
			}
			$row->save();
			return intval($row->key);
		} catch (\Throwable $e) {
			error_log('MailboxSendAttempt: could not record a send attempt: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Store what the carrier said when asked about delivery (A4). A targeted
	 * update: the cache columns are plain, so this never touches sealed content.
	 */
	public static function cacheDelivery(int $id, string $status, ?array $events): void {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('UPDATE mst_mailbox_send_attempts
			SET mst_delivery_status = ?, mst_delivery_detail = ?, mst_delivery_checked_time = now()
			WHERE mst_mailbox_send_attempt_id = ?');
		$stmt->execute(array($status, $events === null ? null : json_encode($events, JSON_UNESCAPED_UNICODE), $id));
	}

	/** Decode a JSON column, [] when empty or malformed. */
	public function json(string $col): array {
		$raw = $this->get($col);
		if ($raw === null || $raw === '') {
			return array();
		}
		$decoded = json_decode((string)$raw, true);
		return is_array($decoded) ? $decoded : array();
	}
}

class MultiMailboxSendAttempt extends SystemMultiBase {
	protected static $model_class = 'MailboxSendAttempt';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['alias_id'])) {
			$filters['mst_iea_inbound_email_alias_id'] = array($this->options['alias_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['message_id'])) {
			$filters['mst_iem_inbound_email_message_id'] = array($this->options['message_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['source_message_id'])) {
			$filters['mst_source_iem_inbound_email_message_id'] = array($this->options['source_message_id'], PDO::PARAM_INT);
		}
		// Every attempt a message's timeline shows: the ones that produced or were
		// sent from it, and the ones that answered it.
		if (isset($this->options['about_message_id'])) {
			$id = intval($this->options['about_message_id']);
			$filters['(mst_iem_inbound_email_message_id'] = '= ' . $id
				. ' OR mst_source_iem_inbound_email_message_id = ' . $id . ')';
		}
		if (isset($this->options['message_id_header'])) {
			$filters['mst_message_id_header'] = array($this->options['message_id_header'], PDO::PARAM_STR);
		}
		if (isset($this->options['kind'])) {
			$filters['mst_kind'] = array($this->options['kind'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('mst_mailbox_send_attempts', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
