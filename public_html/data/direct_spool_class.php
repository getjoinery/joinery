<?php
/**
 * DirectSpool - a delivery accepted at a sealed tier and held until the
 * recipient's next unlock.
 *
 * At Private and Fortress the receiver accepts unconditionally, so acceptance
 * discloses nothing about whether an address exists, whether the sender is a
 * contact, or whether the vault is open. Authentication has already run at that
 * point — the instance signature and every sealed-byte hash are checkable
 * without the vault — but authorization cannot, because the contact list is
 * sealed. The delivery therefore lands here, carrying the envelope, the verified
 * sender fact, and the sealed parts, none of which needs the vault. At the next
 * unlock the framework runs the kind's deferred gate and hands the outcome to
 * its ingest.
 *
 * A spooled delivery for a kind whose plugin has been deactivated is HELD, not
 * errored: it stays sealed until the plugin comes back, and expires quietly
 * under the spool's retention if it never does. Nothing is ever returned to the
 * sender in either case — the sender was answered `accept` at receive, and the
 * no-bounce rule holds for every kind.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class DirectSpoolException extends SystemBaseException {}

class DirectSpool extends SystemBase {
	public static $prefix = 'jdp';

	// REST API: held deliveries, sealed or not, are never remotely readable.
	function authenticate_read($data) {
		throw new SystemAuthenticationError('The Direct spool is not readable through the API.');
	}

	function authenticate_write($data) {
		throw new SystemAuthenticationError('The Direct spool is not writable through the API.');
	}

	public static $tablename = 'jdp_direct_spool';
	public static $pkey_column = 'jdp_direct_spool_id';

	/**
	 * Parts are still arriving. The row exists from the moment the preflight is
	 * accepted so the byte accounting has one home for both tiers — and so a
	 * decoy delivery, whose parts are discarded, still accrues its declared
	 * bytes against the address cap.
	 */
	const STATE_STAGING = 'staging';
	/** Waiting for the recipient's next unlock. */
	const STATE_HELD  = 'held';
	/** Gated and ingested; retained only until the sweep clears it. */
	const STATE_DONE  = 'done';

	protected static $foreign_key_actions = array(
		'jdp_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'jdp_direct_spool_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'jdp_kind'         => array('type'=>'varchar(32)', 'is_nullable'=>false, 'index'=>true),
		'jdp_nonce'        => array('type'=>'varchar(64)', 'is_nullable'=>false, 'unique'=>true),
		'jdp_protocol_version' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		'jdp_sender_address' => array('type'=>'varchar(320)', 'is_nullable'=>false),
		'jdp_sender_domain'  => array('type'=>'varchar(255)', 'is_nullable'=>false),
		'jdp_recipient'    => array('type'=>'varchar(320)', 'is_nullable'=>false, 'index'=>true),
		'jdp_domain'       => array('type'=>'varchar(255)', 'is_nullable'=>false, 'index'=>true),
		// Whose unlock drains this. The gate needs THIS user's sealed contact list.
		'jdp_usr_user_id'  => array('type'=>'int8', 'is_nullable'=>true, 'index'=>true),
		// The recipient identity the resolver returned at accept — the mailbox the
		// message was addressed to and its hosting domain. Opaque handles the kind's
		// ingest interprets (for mail, an inbound alias and domain), carried here so
		// a delivery files into the right mailbox and seals to the right owner
		// instead of being re-derived and dropped between the gate and ingest. Not
		// foreign keys: core must not reference a plugin's tables, and a kind other
		// than mail gives these its own meaning.
		'jdp_recipient_alias_id'  => array('type'=>'int8', 'is_nullable'=>true),
		'jdp_recipient_domain_id' => array('type'=>'int8', 'is_nullable'=>true),
		'jdp_manifest'     => array('type'=>'text', 'is_nullable'=>false),
		'jdp_key_generation' => array('type'=>'int4', 'is_nullable'=>false, 'default'=>1),
		// The byte weight charged to the per-domain and per-address caps. Counted
		// for a decoy delivery too (whose parts are discarded), so a full spool
		// refuses identically whether the address exists or not.
		'jdp_bytes'        => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'jdp_state'        => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>self::STATE_STAGING, 'index'=>true),
		'jdp_received_time'=> array('type'=>'timestamp(6)', 'default'=>'now()'),
		'jdp_drained_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'jdp_delete_time'  => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	public static $timestamp_fields = array('jdp_received_time', 'jdp_drained_time', 'jdp_delete_time');

	/**
	 * Retention. A plain age DELETE would not be enough: a held delivery owns
	 * part rows and File bytes, and an ABANDONED one — preflighted, accepted,
	 * then never transferred — has to go far sooner than a held one, or its
	 * declared bytes stay charged against the spool caps forever. A sender that
	 * only ever preflights would otherwise fill a recipient's cap with nothing.
	 *
	 * The policy is WINDOWLESS on purpose. Most of what it reclaims — expired
	 * replay nonces, dead delivery sessions, the capability cache, abandoned and
	 * drained staging rows — expires on its own short TTL and must be swept every
	 * pass. Only the HELD-delivery purge honours joinery_direct_spool_retention_days,
	 * read inside purgeSpool: 0 there means a held delivery waits indefinitely, and
	 * that operator choice must never also stop the replay tables being reclaimed —
	 * which is exactly what gating the whole rule on that setting used to do.
	 */
	public static $retention_policy = array(
		'label'          => 'Joinery Direct spool',
		'purge_method'   => 'purgeSpool',
		'window_setting' => null,
	);

	/**
	 * Reclaim the spool: abandoned staging rows, drained rows, expired sessions
	 * and aged-out replay nonces, then held deliveries past the retention
	 * window.
	 *
	 * @return array{removed:int,message:string} the sweep's result contract
	 */
	public static function purgeSpool($window_days = 0): array {
		require_once(PathHelper::getIncludePath('data/direct_sessions_class.php'));
		require_once(PathHelper::getIncludePath('data/direct_nonces_class.php'));
		require_once(PathHelper::getIncludePath('data/direct_capability_cache_class.php'));
		require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));

		// The held-delivery window is read here, not taken from the (windowless)
		// rule: the replay/abandoned/drained cleanup below runs every pass, and only
		// aging out HELD deliveries is gated on the operator's retention setting.
		$window_days = DirectSettings::spoolRetentionDays();

		$sessions = DirectSession::purgeExpired();
		$nonces   = DirectNonce::purgeExpired();
		DirectCapabilityCache::purgeExpired();

		// A staging row with no OPEN, unexpired session is an abandoned delivery.
		// Its parts go with it, and so does its charge against the caps.
		//
		// The session must be open, not merely present: a session consumed by a
		// terminal failure leaves a staging row that will never advance, and
		// treating "a row exists" as "still in flight" would keep those charged
		// against the caps until their TTL ran out for no reason.
		$db = DbConnector::get_instance()->get_db_link();
		$abandoned = self::purgeRows($db->prepare(
			'SELECT jdp_direct_spool_id FROM jdp_direct_spool
			  WHERE jdp_state = ?
			    AND NOT EXISTS (SELECT 1 FROM jds_direct_sessions
			                     WHERE jds_nonce = jdp_nonce
			                       AND jds_state = ? AND jds_expires_time >= now())'
		), array(self::STATE_STAGING, DirectSession::STATE_OPEN));

		// A drained row has already released its parts; the row itself is kept
		// only briefly, for the operator surface.
		$drained = self::purgeRows($db->prepare(
			"SELECT jdp_direct_spool_id FROM jdp_direct_spool
			  WHERE jdp_state = ? AND jdp_drained_time < now() - interval '2 days'"
		), array(self::STATE_DONE));

		// Held deliveries nobody ever unlocked for. 0 means never purge, which is
		// the one case where a delivery waits indefinitely.
		$held = 0;
		if ($window_days > 0) {
			$held = self::purgeRows($db->prepare(
				"SELECT jdp_direct_spool_id FROM jdp_direct_spool
				  WHERE jdp_state = ? AND jdp_received_time < now() - (interval '1 day' * "
				  . intval($window_days) . ')'
			), array(self::STATE_HELD));
		}

		$removed = $abandoned + $held + $drained;
		if ($removed === 0 && $sessions === 0 && $nonces === 0) {
			return array('removed' => 0, 'message' => 'nothing to reclaim in the Direct spool');
		}
		return array('removed' => $removed, 'message' =>
			$abandoned . ' abandoned, ' . $held . ' expired held, ' . $drained . ' drained; '
			. $sessions . ' session(s) and ' . $nonces . ' nonce(s) cleared');
	}

	/** Permanently delete each row the statement selects, parts and files included. */
	private static function purgeRows($stmt, array $params): int {
		$stmt->execute($params);
		$ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array();
		$gone = 0;
		foreach ($ids as $id) {
			try {
				$row = new DirectSpool(intval($id), TRUE);
				if ($row->key) {
					$row->permanent_delete();
					$gone++;
				}
			} catch (\Throwable $e) {
				error_log('DirectSpool::purgeSpool could not reclaim ' . $id . ': ' . $e->getMessage());
			}
		}
		return $gone;
	}

	public function manifest(): array {
		$decoded = json_decode((string)$this->get('jdp_manifest'), true);
		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Bytes currently held for one domain, and for one address within it.
	 *
	 * Both caps are absolute recipient-side bounds, which is what makes them
	 * un-Sybil-able: no number of cheap sending domains raises a ceiling
	 * expressed in the receiver's own bytes.
	 */
	public static function bytesForDomain(string $domain): int {
		return self::sumBytes('jdp_domain', strtolower($domain));
	}

	public static function bytesForAddress(string $address): int {
		return self::sumBytes('jdp_recipient', strtolower($address));
	}

	/**
	 * Bytes occupied but not yet drained. Staging counts alongside held: an
	 * in-flight delivery is real disk, and leaving it uncounted would let a
	 * flood of concurrent transfers slip past a cap that only looked at what had
	 * already landed. Counters drain as the spool drains at unlock, and by
	 * retention expiry for addresses holding nothing.
	 */
	private static function sumBytes(string $column, string $value): int {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT COALESCE(SUM(jdp_bytes), 0) FROM jdp_direct_spool
			WHERE ' . $column . ' = ? AND jdp_state IN (?, ?) AND jdp_delete_time IS NULL');
		$stmt->execute(array($value, self::STATE_STAGING, self::STATE_HELD));
		return intval($stmt->fetchColumn());
	}

	/** Is there anything held for this user? Cheap and indexed — runs on every heartbeat. */
	public static function hasWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT 1 FROM jdp_direct_spool
			WHERE jdp_usr_user_id = ? AND jdp_state = ? AND jdp_delete_time IS NULL LIMIT 1');
		$stmt->execute(array($user_id, self::STATE_HELD));
		return (bool)$stmt->fetchColumn();
	}

	/**
	 * Held delivery ids for one user, NEWEST first — the same order deferred mail
	 * ingest drains in, so the two agree about which message becomes readable
	 * first after an unlock.
	 */
	public static function heldIdsForUser(int $user_id, int $max): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('SELECT jdp_direct_spool_id FROM jdp_direct_spool
			WHERE jdp_usr_user_id = ? AND jdp_state = ? AND jdp_delete_time IS NULL
			ORDER BY jdp_received_time DESC LIMIT ?');
		$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
		$stmt->bindValue(2, self::STATE_HELD, PDO::PARAM_STR);
		$stmt->bindValue(3, max(1, $max), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: array();
	}
}

class MultiDirectSpool extends SystemMultiBase {
	protected static $model_class = 'DirectSpool';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['kind'])) {
			$filters['jdp_kind'] = array((string)$this->options['kind'], PDO::PARAM_STR);
		}
		if (isset($this->options['nonce'])) {
			$filters['jdp_nonce'] = array((string)$this->options['nonce'], PDO::PARAM_STR);
		}
		if (isset($this->options['recipient'])) {
			$filters['jdp_recipient'] = array(strtolower((string)$this->options['recipient']), PDO::PARAM_STR);
		}
		if (isset($this->options['domain'])) {
			$filters['jdp_domain'] = array(strtolower((string)$this->options['domain']), PDO::PARAM_STR);
		}
		if (isset($this->options['user_id'])) {
			$filters['jdp_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['state'])) {
			$filters['jdp_state'] = array((string)$this->options['state'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('jdp_direct_spool', $filters, $this->order_by, $only_count, $debug);
	}
}
