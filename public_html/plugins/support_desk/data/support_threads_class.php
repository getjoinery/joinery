<?php
/**
 * SupportThread — one conversation a site's administrator (or, on the public
 * widget, a visitor) has with the support desk.
 *
 * The thread is the identity and the bookkeeping; the words live in an
 * AiConversation (aic_support_thread_id points back here) owned by the
 * support desk's reserved user, so the transcript, its tool trace and its
 * token counts are joinery_ai's own rows (specs/support_chatbot.md, Threads
 * and storage).
 *
 * A thread's identity is fixed when it is made: the verified host, the API
 * key when the site is connected, the managed node when the host is joined
 * here. A message for a thread from another host or another key is refused
 * (SupportIntake). Everything that arrived from the site — the bundle, the
 * route, the words — is untrusted text: stored as text, escaped on display,
 * wrapped in the thread's envelope on the way to the model.
 *
 * States:
 *   open                 the model answers the next question
 *   waiting_on_person    the person asked for a human, or the model handed off
 *   answered_by_person   a person replied; the next question reopens it for
 *                        the model unless an operator pinned it to themselves
 *   closed               nobody is expected to say anything more
 *
 * @version 0.1.0
 */

class SupportThreadException extends SystemBaseException {}

class SupportThread extends SystemBase {
	public static $prefix = 'sth';
	public static $tablename = 'sth_support_threads';
	public static $access_class = 'personal';
	public static $pkey_column = 'sth_support_thread_id';

	const STATE_OPEN     = 'open';
	const STATE_WAITING  = 'waiting_on_person';
	const STATE_ANSWERED = 'answered_by_person';
	const STATE_CLOSED   = 'closed';

	const STATES = array(
		self::STATE_OPEN     => 'Open',
		self::STATE_WAITING  => 'Waiting on a person',
		self::STATE_ANSWERED => 'Answered by a person',
		self::STATE_CLOSED   => 'Closed',
	);

	/** Where the thread came from: a site's Help panel or the public widget. */
	const SURFACE_SITE   = 'site';
	const SURFACE_WIDGET = 'widget';

	/** The verdicts the host callback gives (BugReportIntake's three). */
	const VERDICT_VERIFIED   = 'verified';
	const VERDICT_MISMATCH   = 'version_mismatch';
	const VERDICT_UNVERIFIED = 'unverified';

	public static $field_specifications = array(
		'sth_support_thread_id'   => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'sth_surface'             => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'site'),
		// The asking site's host, as verified by the callback. '' for a widget thread.
		'sth_host'                => array('type' => 'varchar(255)', 'is_nullable' => true, 'index' => true),
		// The release the site runs, from its latest verified message. The
		// thread searches this release's documents.
		'sth_version'             => array('type' => 'varchar(64)', 'is_nullable' => true),
		'sth_verdict'             => array('type' => 'varchar(32)', 'is_nullable' => true),
		// Set when the site signed with its services key: the thread belongs to
		// that account, which reads its history and gets the reply notice.
		'sth_apk_api_key_id'      => array('type' => 'int8', 'is_nullable' => true),
		'sth_account_user_id'     => array('type' => 'int8', 'is_nullable' => true, 'index' => true),
		// The managed node whose site URL is the verified host, when the host
		// is joined here. Read only from the verified host, never the request.
		// Not a declared foreign key: the node table is the server_manager
		// plugin's, and this plugin runs where that one is not active.
		'sth_node_id'             => array('type' => 'int8', 'is_nullable' => true),
		// The consent the site sent with its latest message.
		'sth_consent_bundle'      => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		'sth_consent_live_reads'  => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		'sth_state'               => array('type' => 'varchar(32)', 'is_nullable' => false, 'default' => 'open', 'index' => true),
		// An operator who took the thread: the model does not answer it again
		// until they let it go.
		'sth_pinned_user_id'      => array('type' => 'int8', 'is_nullable' => true),
		'sth_title'               => array('type' => 'varchar(255)', 'is_nullable' => true),
		// The first message's bundle, as received (untrusted JSON text).
		'sth_bundle'              => array('type' => 'text', 'is_nullable' => true),
		// The admin route or setup step the latest message was asked from.
		'sth_route'               => array('type' => 'varchar(500)', 'is_nullable' => true),
		'sth_aic_conversation_id' => array('type' => 'int8', 'is_nullable' => true),
		// The thread's own envelope nonce. Per thread, not per turn, so the
		// thread's facts after the cache breakpoint stay byte-identical from
		// turn to turn and are read from cache. Never sent to the site.
		'sth_nonce'               => array('type' => 'varchar(16)', 'is_nullable' => false),
		// The hash of the thread's token: the secret the asking site (or the
		// widget's browser session) was given when the thread was made and
		// presents with every later call. Knowing a host and a thread number
		// is not enough to read or continue a thread.
		'sth_token_hash'          => array('access_class' => 'restricted', 'type' => 'varchar(64)', 'is_nullable' => false),
		'sth_input_tokens'        => array('type' => 'int8', 'is_nullable' => false, 'default' => 0),
		'sth_output_tokens'       => array('type' => 'int8', 'is_nullable' => false, 'default' => 0),
		// Node reads spent on the UTC day in sth_node_reads_day.
		'sth_node_reads'          => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'sth_node_reads_day'      => array('type' => 'date', 'is_nullable' => true),
		// A person replied and the site has not opened the thread since.
		'sth_unread_reply'        => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		'sth_notified_time'       => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'sth_reply_notified_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
		// The address the thread's messages come from: the one personal datum
		// kept, for abuse handling. A widget thread also keeps the hash of its
		// browser session.
		'sth_sender_ip'           => array('type' => 'varchar(64)', 'is_nullable' => true),
		'sth_visitor'             => array('type' => 'varchar(64)', 'is_nullable' => true, 'index' => true),
		// The gap report's close: what answers this thread's question now (a doc
		// commit, an agent word, a FAQ entry), and when it was said.
		'sth_gap_note'            => array('type' => 'varchar(500)', 'is_nullable' => true),
		'sth_gap_closed_time'     => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'sth_last_activity_time'  => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'sth_closed_time'         => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'sth_create_time'         => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'sth_delete_time'         => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	protected static $foreign_key_actions = array(
		'sth_apk_api_key_id'  => array('action' => 'null'),
		'sth_account_user_id' => array('action' => 'null', 'source_table' => 'usr_users'),
		'sth_pinned_user_id'  => array('action' => 'null', 'source_table' => 'usr_users'),
		'sth_aic_conversation_id' => array('action' => 'null'),
	);

	/**
	 * Closed threads go on the operator's window; 0 keeps them
	 * (specs/support_chatbot.md, What the threads are for later). The method
	 * form, because a thread takes its transcript with it.
	 */
	public static $retention_policy = array(
		'label'          => 'Closed support threads',
		'purge_method'   => 'purgeClosed',
		'window_setting' => 'support_desk_thread_retention_days',
	);

	public function stateLabel(): string {
		return self::STATES[(string)$this->get('sth_state')] ?? (string)$this->get('sth_state');
	}

	public function isJoined(): bool {
		return (int)$this->get('sth_node_id') > 0;
	}

	public function isConnected(): bool {
		return (int)$this->get('sth_apk_api_key_id') > 0;
	}

	/** The thread's bundle as an array; [] when there is none or it does not parse. */
	public function bundle(): array {
		$decoded = json_decode((string)$this->get('sth_bundle'), true);
		return is_array($decoded) ? $decoded : array();
	}

	/** The transcript, or null when the thread has none yet. */
	public function conversation(): ?AiConversation {
		$id = (int)$this->get('sth_aic_conversation_id');
		if ($id <= 0) {
			return null;
		}
		$c = new AiConversation($id, TRUE);
		return $c->key ? $c : null;
	}

	/** The managed node, when the thread came from a joined host and the node is still there. */
	public function node() {
		$id = (int)$this->get('sth_node_id');
		if ($id <= 0 || !class_exists('ManagedNode')) {
			return null;
		}
		$node = new ManagedNode($id, TRUE);
		return ($node->key && !$node->get('mgn_delete_time')) ? $node : null;
	}

	/** Mark activity now, with a targeted UPDATE so a concurrent turn's totals are not overwritten. */
	public static function touch(int $id, array $columns = array()): void {
		$columns['sth_last_activity_time'] = gmdate('Y-m-d H:i:s');
		self::updateColumns($id, $columns);
	}

	/** Add a turn's tokens to the thread's totals in one statement. */
	public static function addTokens(int $id, int $in, int $out): void {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE sth_support_threads SET sth_input_tokens = sth_input_tokens + ?, sth_output_tokens = sth_output_tokens + ?
			  WHERE sth_support_thread_id = ?');
		$stmt->execute(array($in, $out, $id));
	}

	/**
	 * Count one node read against today's allowance, in one statement so two
	 * reads in one turn cannot both take the last one. True when the read is
	 * allowed (and counted), false when the day's allowance is spent.
	 */
	public static function takeNodeRead(int $id, int $per_day): bool {
		if ($per_day <= 0) {
			return false;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"UPDATE sth_support_threads
			    SET sth_node_reads = CASE WHEN sth_node_reads_day = (now() AT TIME ZONE 'UTC')::date THEN sth_node_reads + 1 ELSE 1 END,
			        sth_node_reads_day = (now() AT TIME ZONE 'UTC')::date
			  WHERE sth_support_thread_id = :id
			    AND (sth_node_reads_day IS DISTINCT FROM (now() AT TIME ZONE 'UTC')::date OR sth_node_reads < :cap)
			RETURNING sth_node_reads");
		$stmt->bindValue(':id', $id, PDO::PARAM_INT);
		$stmt->bindValue(':cap', $per_day, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchColumn() !== false;
	}

	/** Node reads spent today (0 on another day). */
	public function nodeReadsToday(): int {
		return (string)$this->get('sth_node_reads_day') === gmdate('Y-m-d') ? (int)$this->get('sth_node_reads') : 0;
	}

	/** A thread goes with its transcript. */
	function permanent_delete($debug = false) {
		$conversation = $this->conversation();
		$result = parent::permanent_delete($debug);
		if ($conversation !== null) {
			$conversation->permanent_delete();
		}
		return $result;
	}

	/** Retention: closed threads older than the window, with their transcripts. */
	public static function purgeClosed($days) {
		$days = (int)$days;
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT sth_support_thread_id FROM sth_support_threads
			  WHERE sth_state = 'closed' AND sth_last_activity_time < now() - (? || ' days')::interval
			  ORDER BY sth_support_thread_id LIMIT 500");
		$stmt->execute(array((string)$days));
		$removed = 0;
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$thread = new SupportThread((int)$id, TRUE);
			if ($thread->key) {
				$thread->permanent_delete();
				$removed++;
			}
		}
		return array('removed' => $removed, 'message' => $removed . ' closed support thread(s) removed');
	}

	/** Does $token open this thread? Compared as hashes, in constant time. */
	public function tokenOpens(string $token): bool {
		$token = trim($token);
		return $token !== '' && hash_equals((string)$this->get('sth_token_hash'), hash('sha256', $token));
	}

	/** Threads waiting on a person: the admin menu count. */
	public static function waitingCount(): int {
		try {
			return (int)DbConnector::get_instance()->get_db_link()
				->query("SELECT COUNT(*) FROM sth_support_threads WHERE sth_state = 'waiting_on_person' AND sth_delete_time IS NULL")
				->fetchColumn();
		} catch (\Throwable $e) {
			return 0;
		}
	}
}

class MultiSupportThread extends SystemMultiBase {
	protected static $model_class = 'SupportThread';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['state'])) {
			$filters['sth_state'] = array($this->options['state'], PDO::PARAM_STR);
		}
		if (isset($this->options['state_not'])) {
			$filters['sth_state'] = "<> " . DbConnector::get_instance()->get_db_link()->quote((string)$this->options['state_not']);
		}
		if (isset($this->options['host'])) {
			$filters['sth_host'] = array($this->options['host'], PDO::PARAM_STR);
		}
		if (isset($this->options['account_user_id'])) {
			$filters['sth_account_user_id'] = array((int)$this->options['account_user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['visitor'])) {
			$filters['sth_visitor'] = array($this->options['visitor'], PDO::PARAM_STR);
		}
		if (isset($this->options['surface'])) {
			$filters['sth_surface'] = array($this->options['surface'], PDO::PARAM_STR);
		}
		$filters['sth_delete_time'] = 'IS NULL';
		return $this->_get_resultsv2('sth_support_threads', $filters, $this->order_by, $only_count, $debug);
	}
}
