<?php
/**
 * SiteSupportThread — this site's own record of a thread its Help panel opened
 * at a support desk (support_chatbot.md, The site side).
 *
 * The thread itself lives at the desk; this row is how the site finds it
 * again and proves it is its own: the desk's thread id and the token the desk
 * gave when the thread was opened. Before that, the row holds the one-time
 * code (hashed) the first question carried, which the desk's call back to
 * support_origin_confirm consumes.
 *
 * Threads belong to the site, not to the administrator who asked: any
 * administrator opening Help sees the site's threads. A thread the site's
 * account opened before this site had a row for it (a reinstall) is adopted
 * with no token: the desk opens it by the account's key.
 *
 * @version 1.1.0
 */
class SiteSupportThreadException extends SystemBaseException {}

class SiteSupportThread extends SystemBase {
	public static $prefix = 'spt';
	public static $tablename = 'spt_support_threads';
	public static $pkey_column = 'spt_support_thread_id';

	/** Threads the panel lists and the unread check asks about. */
	const RECENT = 30;

	public static $field_specifications = array(
		'spt_support_thread_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		// The desk the thread lives at (its host), and its id there.
		'spt_destination'       => array('type' => 'varchar(255)', 'is_nullable' => false),
		'spt_remote_id'         => array('type' => 'int8', 'is_nullable' => true),
		// The desk's token for the thread: what proves a later call is this site's.
		'spt_token'             => array('type' => 'varchar(128)', 'is_nullable' => true),
		// The one-time code the first question carried, hashed, and when it was issued.
		'spt_nonce_hash'        => array('type' => 'varchar(64)', 'is_nullable' => true, 'index' => true),
		'spt_nonce_time'        => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'spt_title'             => array('type' => 'varchar(255)', 'is_nullable' => true),
		'spt_state'             => array('type' => 'varchar(32)', 'is_nullable' => true),
		// A person at the desk replied and nobody here has opened it since.
		'spt_unread'            => array('type' => 'bool', 'is_nullable' => false, 'default' => false),
		'spt_usr_user_id'       => array('type' => 'int8', 'is_nullable' => true),
		'spt_create_time'       => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'spt_update_time'       => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'spt_delete_time'       => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	protected static $foreign_key_actions = array(
		'spt_usr_user_id' => array('action' => 'null'),
	);

	function authenticate_write($data) {
		if (($data['current_user_permission'] ?? 0) < 5) {
			throw new SystemAuthenticationError('Support threads are for this site\'s administrators.');
		}
	}

	/** Start a thread's row before its first question goes out. */
	public static function begin(int $user_id, string $destination, string $first_message): SiteSupportThread {
		$t = new SiteSupportThread(NULL);
		$t->set('spt_destination', mb_substr($destination, 0, 255));
		$title = trim(preg_replace('/\s+/u', ' ', $first_message));
		$t->set('spt_title', mb_strlen($title) > 80 ? mb_substr($title, 0, 79) . '…' : $title);
		$t->set('spt_usr_user_id', $user_id > 0 ? $user_id : null);
		$t->save();
		$t->load();
		return $t;
	}

	/**
	 * This site's row for a thread at a desk, found or made. Made for a thread
	 * this site's account opened that the site has no row for (a site
	 * reinstalled and connected to its account again): it has no token, and
	 * the desk opens it by the account's key.
	 */
	public static function adopt(string $destination, int $remote_id, string $title): SiteSupportThread {
		foreach (new MultiSiteSupportThread(array('destination' => $destination, 'remote_id' => $remote_id)) as $t) {
			return $t;
		}
		$t = new SiteSupportThread(NULL);
		$t->set('spt_destination', mb_substr($destination, 0, 255));
		$t->set('spt_remote_id', $remote_id);
		$t->set('spt_title', mb_substr($title, 0, 255));
		$t->save();
		$t->load();
		return $t;
	}

	/** Issue the one-time code the first question carries; only its hash is kept. */
	public function issueNonce(): string {
		$nonce = bin2hex(random_bytes(24));
		self::updateColumns((int)$this->key, array('spt_nonce_hash' => hash('sha256', $nonce), 'spt_nonce_time' => gmdate('Y-m-d H:i:s')));
		return $nonce;
	}

	/**
	 * The desk's call back: the desk the code was issued for (its host), once,
	 * for a code issued here in the last NONCE_SECONDS; null otherwise. One
	 * statement, so a code cannot confirm twice. The desk accepts only a code
	 * issued for itself, so a code handed to one desk opens nothing at another.
	 */
	public static function consumeNonce(string $nonce): ?string {
		$nonce = trim($nonce);
		if (!preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $nonce)) {
			return null;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"UPDATE spt_support_threads SET spt_nonce_hash = NULL
			  WHERE spt_nonce_hash = ? AND spt_nonce_time > now() - (? || ' seconds')::interval
			  RETURNING spt_destination");
		$stmt->execute(array(hash('sha256', $nonce), (string)SupportChatClient::NONCE_SECONDS));
		$destination = $stmt->fetchColumn();
		return $destination === false ? null : (string)$destination;
	}

	/** The desk answered: keep its thread id and token. */
	public function opened(int $remote_id, string $token): void {
		if ($remote_id <= 0 || $token === '') {
			throw new SupportChatException('The support desk did not open a thread.');
		}
		self::updateColumns((int)$this->key, array('spt_remote_id' => $remote_id, 'spt_token' => mb_substr($token, 0, 128),
			'spt_nonce_hash' => null, 'spt_update_time' => gmdate('Y-m-d H:i:s')));
		$this->set('spt_remote_id', $remote_id);
		$this->set('spt_token', $token);
	}

	public function token(): string {
		return (string)$this->get('spt_token');
	}

	/** What the desk said of the thread's state and unread mark. */
	public function noteState(string $state, bool $unread): void {
		$cols = array('spt_unread' => $unread, 'spt_update_time' => gmdate('Y-m-d H:i:s'));
		if ($state !== '') {
			$cols['spt_state'] = mb_substr($state, 0, 32);
		}
		self::updateColumns((int)$this->key, $cols);
	}

	/** One of this site's opened threads, or the panel's refusal. */
	public static function mine(int $id): SiteSupportThread {
		$t = null;
		if ($id > 0) {
			try {
				$t = new SiteSupportThread($id, TRUE);
			} catch (\Throwable $e) {
				$t = null;
			}
		}
		if ($t === null || !$t->key || $t->get('spt_delete_time') || (int)$t->get('spt_remote_id') <= 0
				|| (string)$t->get('spt_destination') !== SupportChatClient::destinationHost()) {
			throw new SupportChatException('That thread is not one of this site\'s.', 'no_thread');
		}
		return $t;
	}

	/** The site's recent threads at the current desk, newest first. */
	public static function recent(): array {
		$out = array();
		foreach (new MultiSiteSupportThread(array('destination' => SupportChatClient::destinationHost(), 'opened' => true),
				array('spt_update_time' => 'DESC'), self::RECENT) as $t) {
			$out[] = $t;
		}
		return $out;
	}

	/** Threads with a reply nobody here has opened. Cheap: the header asks on every admin page. */
	public static function unreadCount(): int {
		try {
			return (int)DbConnector::get_instance()->get_db_link()
				->query('SELECT COUNT(*) FROM spt_support_threads WHERE spt_unread AND spt_delete_time IS NULL')->fetchColumn();
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/** Has anyone here used the panel in the last week? Then the header checks for replies. */
	public static function usedThisWeek(): bool {
		try {
			return (bool)DbConnector::get_instance()->get_db_link()
				->query("SELECT 1 FROM spt_support_threads WHERE spt_delete_time IS NULL AND spt_remote_id IS NOT NULL
					AND COALESCE(spt_update_time, spt_create_time) > now() - INTERVAL '7 days' LIMIT 1")->fetchColumn();
		} catch (\Throwable $e) {
			return false;
		}
	}
}

class MultiSiteSupportThread extends SystemMultiBase {
	protected static $model_class = 'SiteSupportThread';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['destination'])) {
			$filters['spt_destination'] = array($this->options['destination'], PDO::PARAM_STR);
		}
		if (!empty($this->options['opened'])) {
			$filters['spt_remote_id'] = 'IS NOT NULL';
		}
		if (isset($this->options['remote_id'])) {
			$filters['spt_remote_id'] = array((int)$this->options['remote_id'], PDO::PARAM_INT);
		}
		$filters['spt_delete_time'] = 'IS NULL';
		return $this->_get_resultsv2('spt_support_threads', $filters, $this->order_by, $only_count, $debug);
	}
}
