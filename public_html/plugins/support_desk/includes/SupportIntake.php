<?php
/**
 * SupportIntake — how a question from another site becomes a turn, and how a
 * later call proves it belongs to the thread it names
 * (specs/support_chatbot.md, Intake; Trust posture).
 *
 * The actions under /api/v1/action/support_desk/ are sessionless: the asking
 * site has no account here unless it connected one. What each call proves:
 *
 *   A new thread proves its ORIGIN. The site's own server issues a one-time
 *   nonce, stores it, and sends it with the first question; this node calls
 *   the claimed host back (POST /api/v1/action/support_origin_confirm with
 *   the nonce) and the site answers yes only for a nonce it issued and has not
 *   used. A host that does not confirm is refused outright. Knowing that a
 *   Joinery site exists at a host is not enough to speak for it: that is what
 *   keeps anyone from opening a thread as someone else's joined site and
 *   reading its logs.
 *   The site answers with the host its code was issued for, and this node
 *   accepts only its own: a code handed to one desk cannot open a thread at
 *   another.
 *   Every later call proves CONTINUITY with the thread's token, which only
 *   the site that opened the thread was given (stored here as a hash).
 *   A connected site also signs with its services key pair; a thread opened
 *   by an account accepts calls signed with that account's key only, and
 *   with that key needs no token (a reinstalled site keeps its history).
 *
 * The matched ManagedNode comes from the proven host, never from anything
 * the request names; it is matched again on every question, so a site that
 * joins this node later is read from its next question on.
 *
 * Everything the site sends is untrusted text: stored as text, escaped on
 * display, wrapped on the way to the model, never a path.
 *
 * @version 0.1.1
 * @changelog 0.1.1 - an asker's writes run under rule_checked_write() behind the origin/token proofs
 */
class SupportRefusal extends Exception {
	/** @var string a machine reason the panel can act on */
	public $reason;
	/** @var int HTTP status */
	public $status;
	public function __construct(string $message, string $reason = 'refused', int $status = 422) {
		parent::__construct($message);
		$this->reason = $reason;
		$this->status = $status;
	}
}

class SupportIntake {

	const MESSAGE_MAX = 8000;
	const BUNDLE_MAX = 262144;
	const TITLE_MAX = 80;

	/** The site-side action that confirms a thread's origin. */
	const CONFIRM_PATH = '/api/v1/action/support_origin_confirm';

	/** The background worker that runs one support turn. */
	const WORKER = 'plugins/support_desk/cli/run_support_turn.php';

	/** @var SafeHttpClient|null a stand-in client for the origin callback, for tests */
	private static $client = null;

	/** @var bool tests run the turn themselves and must not spawn workers */
	public static $spawn = true;

	public static function useClient(?SafeHttpClient $client): void {
		self::$client = $client;
	}

	// ------------------------------------------------------------- ask

	/**
	 * Take one question from a site. Returns the poll handle:
	 * [thread_id, token (a new thread only), user_message_id, message_id
	 * (the assistant row, 0 when no turn runs), state, status].
	 *
	 * @throws SupportRefusal
	 */
	public static function ask(array $input, array $headers, string $ip): array {
		$message = trim((string)($input['message'] ?? ''));
		if ($message === '') {
			throw new SupportRefusal('Ask a question.', 'empty');
		}
		if (mb_strlen($message) > self::MESSAGE_MAX) {
			throw new SupportRefusal('Keep a question under ' . number_format(self::MESSAGE_MAX) . ' characters.', 'too_long');
		}
		list($host, $version) = self::site($input);
		$key = self::presentedKey($headers, $host);
		$consent = self::consent($input);
		$route = mb_substr(trim((string)($input['route'] ?? '')), 0, 500);

		$thread_id = (int)($input['thread_id'] ?? 0);
		$token = '';
		if ($thread_id > 0) {
			$thread = self::openThread($thread_id, (string)($input['token'] ?? ''), $host, $key);
		} else {
			// Refused before the call back and before any row: a site over its
			// budget, or a node that cannot answer, costs nothing and leaves nothing.
			self::assertCanAnswer();
			$refusal = SupportBudget::refusalFor(SupportThread::SURFACE_SITE, $host, null);
			if ($refusal !== null) {
				throw new SupportRefusal($refusal, 'budget', 429);
			}
			$confirmed_version = self::confirmOrigin($host, (string)($input['origin_nonce'] ?? ''));
			$bundle = $consent['bundle'] ? self::bundle($input, $host) : null;
			list($thread, $token) = self::createThread(SupportThread::SURFACE_SITE, array(
				'host'      => $host,
				'version'   => $confirmed_version !== '' ? $confirmed_version : $version,
				'verdict'   => SupportThread::VERDICT_VERIFIED,
				'key'       => $key,
				'bundle'    => $bundle,
				'ip'        => $ip,
				'title'     => $message,
			));
		}

		// The site's latest word on itself and on what it allows, and the node
		// it is now. Only these columns: a turn may be running in this thread,
		// and its token and read counts must not be written back stale.
		$columns = array(
			'sth_version'            => $thread_id > 0 ? $version : (string)$thread->get('sth_version'),
			'sth_consent_bundle'     => $consent['bundle'],
			'sth_consent_live_reads' => $consent['live_reads'],
			'sth_route'              => $route !== '' ? $route : null,
			'sth_sender_ip'          => mb_substr($ip, 0, 64),
			'sth_node_id'            => self::nodeIdForHost($host),
		);
		// The thread is the caller's: its token (openThread()) or its origin
		// (confirmOrigin()) was proven above. Nobody is signed in.
		SystemBase::rule_checked_write(function () use ($thread, $columns) {
			SupportThread::updateColumns((int)$thread->key, $columns);
		});
		foreach ($columns as $column => $value) {
			$thread->set_as_stored($column, $value);
		}

		return self::addQuestion($thread, $message, $token);
	}

	/**
	 * Add the person's message to a thread and start the model's turn when the
	 * model is the one to answer. Shared by the site panel and the widget.
	 *
	 * Every caller has proven the thread is the asker's first (openThread() by
	 * token or account key, SupportWidget::open() by the browser's token, or a
	 * thread createThread() just made for them), so the writes run under
	 * rule_checked_write(): the asker is not signed in, and the transcript's
	 * conversation belongs to the desk's system user.
	 */
	public static function addQuestion(SupportThread $thread, string $message, string $token = ''): array {
		$state = (string)$thread->get('sth_state');
		$pinned = (int)$thread->get('sth_pinned_user_id') > 0;
		// Whom the message is for. A thread waiting on a person, or one an
		// operator took, stays with the person: the panel says so and the
		// model does not pretend to answer. A person's answer, or a closed
		// thread, goes back to the model with the next question.
		$for_person = $pinned || $state === SupportThread::STATE_WAITING;
		$next_state = $for_person ? SupportThread::STATE_WAITING : SupportThread::STATE_OPEN;

		if (!$for_person) {
			self::assertCanAnswer();
			$refusal = SupportBudget::refusal($thread);
			if ($refusal !== null) {
				throw new SupportRefusal($refusal, 'budget', 429);
			}
		}

		$conversation = $thread->conversation();
		$user_msg = new AiConversationMessage(NULL);
		$user_msg->set('aim_aic_conversation_id', (int)$conversation->key);
		$user_msg->set('aim_role', AiConversationMessage::ROLE_USER);
		$user_msg->set('aim_content', $message);
		$user_msg->set('aim_status', AiConversationMessage::STATUS_COMPLETE);
		$columns = array('sth_state' => $next_state, 'sth_unread_reply' => false);
		if ($state === SupportThread::STATE_CLOSED) {
			$columns['sth_closed_time'] = null;
		}
		SystemBase::rule_checked_write(function () use ($user_msg, $thread, $columns) {
			$user_msg->save();
			SupportThread::touch((int)$thread->key, $columns);
		});
		$user_msg->load();
		$thread->set('sth_state', $next_state);

		$out = array(
			'thread_id'       => (int)$thread->key,
			'user_message_id' => (int)$user_msg->key,
			'message_id'      => 0,
			'state'           => $next_state,
			'status'          => 'waiting_on_person',
		);
		if ($token !== '') {
			$out['token'] = $token;
		}
		if ($for_person) {
			if (!$pinned) {
				SystemBase::rule_checked_write(function () use ($thread) {
					SupportNotify::escalate($thread, 'The person wrote again while waiting.');
				});
			}
			return $out;
		}

		$assistant = new AiConversationMessage(NULL);
		$assistant->set('aim_aic_conversation_id', (int)$conversation->key);
		$assistant->set('aim_role', AiConversationMessage::ROLE_ASSISTANT);
		$assistant->set('aim_status', AiConversationMessage::STATUS_RUNNING);
		SystemBase::rule_checked_write(function () use ($assistant) { $assistant->save(); });
		$assistant->load();
		$out['message_id'] = (int)$assistant->key;
		$out['status'] = AiConversationMessage::STATUS_RUNNING;

		if (self::$spawn && !ChatWorkerSpawner::spawnWorker(self::WORKER, (int)$assistant->key)) {
			SystemBase::rule_checked_write(function () use ($assistant) {
				SupportTurn::markFailed($assistant, 'The support desk could not start answering. Ask a person, or try again later.');
			});
			throw new SupportRefusal('The support desk is not set up to answer on this node.', 'not_ready', 503);
		}
		return $out;
	}

	/**
	 * A turn runs in the background or not at all: run inline, it would
	 * outlast the asking site's wait, and the answer would land in a thread
	 * nobody holds. Checked before any row is made. @throws SupportRefusal
	 */
	public static function assertCanAnswer(): void {
		if (self::$spawn && !ChatWorkerSpawner::canSpawn(self::WORKER)) {
			throw new SupportRefusal('The support desk is not set up to answer on this node.', 'not_ready', 503);
		}
	}

	/**
	 * Make a thread and its transcript. Returns [thread, token]; the token is
	 * the one time the secret exists outside a hash.
	 *
	 * Its callers admit the asker first: ask() confirms the site's origin by
	 * calling it back (confirmOrigin()), SupportWidget::ask() passes the
	 * captcha; both pass the budget. The rows are written under
	 * rule_checked_write() because nobody is signed in and the transcript is
	 * the desk's system user's.
	 */
	public static function createThread(string $surface, array $f): array {
		$token = bin2hex(random_bytes(24));
		$owner = SupportDesk::systemUserId();
		if ($owner <= 0) {
			throw new SupportRefusal('The support desk is not set up yet on this node.', 'not_ready', 503);
		}
		$thread = new SupportThread(NULL);
		$thread->set('sth_surface', $surface);
		$thread->set('sth_host', $f['host'] ?? null);
		$thread->set('sth_version', $f['version'] ?? null);
		$thread->set('sth_verdict', $f['verdict'] ?? null);
		$thread->set('sth_nonce', bin2hex(random_bytes(4)));
		$thread->set('sth_token_hash', hash('sha256', $token));
		$thread->set('sth_title', self::title((string)($f['title'] ?? '')));
		$thread->set('sth_sender_ip', mb_substr((string)($f['ip'] ?? ''), 0, 64));
		$thread->set('sth_visitor', $f['visitor'] ?? null);
		if (!empty($f['key'])) {
			$thread->set('sth_apk_api_key_id', (int)$f['key']['key_id']);
			$thread->set('sth_account_user_id', (int)$f['key']['user_id']);
		}
		if (!empty($f['bundle'])) {
			$thread->set('sth_bundle', json_encode($f['bundle'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}
		$thread->set('sth_state', SupportThread::STATE_OPEN);
		SystemBase::rule_checked_write(function () use ($thread) { $thread->save(); });
		$thread->load();

		$conversation = new AiConversation(NULL);
		$conversation->set('aic_owner_user_id', $owner);
		$conversation->set('aic_title', 'Support #' . (int)$thread->key . ': ' . (string)$thread->get('sth_title'));
		$conversation->set('aic_support_thread_id', (int)$thread->key);
		SystemBase::rule_checked_write(function () use ($thread, $conversation) {
			$conversation->save();
			SupportThread::updateColumns((int)$thread->key, array('sth_aic_conversation_id' => (int)$conversation->key));
		});
		$conversation->load();
		$thread->set_as_stored('sth_aic_conversation_id', (int)$conversation->key);
		return array($thread, $token);
	}

	// ------------------------------------------------------- proving things

	/**
	 * The thread a later call names, if the call may speak for it: the host
	 * is its host, a thread an account opened is called with a key of that
	 * account (presentedKey() has proven the key is the account's for this
	 * host), and the token opens it. With the account's key, no token is
	 * needed: that is how a reinstalled site, connected again, opens its
	 * account's threads. A wrong token is refused even then.
	 *
	 * @throws SupportRefusal
	 */
	public static function openThread(int $thread_id, string $token, string $host, ?array $key): SupportThread {
		// One answer for every way of not matching, so a caller learns nothing
		// about threads it does not hold.
		$nope = new SupportRefusal('That thread is not one this site can open.', 'no_thread', 404);
		$thread = self::load($thread_id);
		if ($thread === null || $thread->get('sth_delete_time')
				|| (string)$thread->get('sth_surface') !== SupportThread::SURFACE_SITE
				|| strtolower((string)$thread->get('sth_host')) !== $host) {
			throw $nope;
		}
		$account = (int)$thread->get('sth_account_user_id');
		$by_account = $account > 0 && $key !== null && (int)$key['user_id'] === $account;
		if ($account > 0 && !$by_account) {
			throw $nope;
		}
		if (!$thread->tokenOpens($token) && !($token === '' && $by_account)) {
			throw $nope;
		}
		return $thread;
	}

	/** A thread by id, or null when there is none (a missing row is an answer, not an error). */
	public static function load(int $thread_id): ?SupportThread {
		if ($thread_id <= 0) {
			return null;
		}
		try {
			$thread = new SupportThread($thread_id, TRUE);
		} catch (\Throwable $e) {
			return null;
		}
		return $thread->key ? $thread : null;
	}

	/** Host and version from the call, checked for shape only. @throws SupportRefusal */
	public static function site(array $input): array {
		$site = is_array($input['site'] ?? null) ? $input['site'] : array();
		$host = strtolower(trim((string)($site['host'] ?? '')));
		$version = trim((string)($site['version'] ?? ''));
		if (!self::isHostName($host)) {
			throw new SupportRefusal('The request does not name the site it came from.', 'bad_site');
		}
		if (!SupportDocIndex::isVersion($version)) {
			throw new SupportRefusal('The request does not name the release the site runs.', 'bad_site');
		}
		return array($host, $version);
	}

	/** The consent flags as sent; live reads need the bundle's consent first. */
	public static function consent(array $input): array {
		$c = is_array($input['consent'] ?? null) ? $input['consent'] : array();
		$bundle = !empty($c['bundle']);
		return array('bundle' => $bundle, 'live_reads' => $bundle && !empty($c['live_reads']));
	}

	/** The first message's bundle: a JSON object naming the confirmed host. @throws SupportRefusal */
	private static function bundle(array $input, string $host): ?array {
		$bundle = $input['bundle'] ?? null;
		if (is_string($bundle)) {
			if (strlen($bundle) > self::BUNDLE_MAX) {
				throw new SupportRefusal('The site\'s facts are larger than this desk accepts.', 'too_long', 413);
			}
			$bundle = json_decode($bundle, true);
		}
		if (!is_array($bundle) || !$bundle) {
			return null;
		}
		if (strlen((string)json_encode($bundle)) > self::BUNDLE_MAX) {
			throw new SupportRefusal('The site\'s facts are larger than this desk accepts.', 'too_long', 413);
		}
		if (strtolower(trim((string)($bundle['site']['host'] ?? ''))) !== $host) {
			throw new SupportRefusal('The site\'s facts name another site.', 'bad_site');
		}
		return $bundle;
	}

	/**
	 * The services key the site signed with, when it signed and the key is a
	 * live services key of an account that holds this host. Null when it did
	 * not sign. A key that does not check out is refused, never ignored.
	 *
	 * @return array{key_id:int, user_id:int}|null
	 * @throws SupportRefusal
	 */
	public static function presentedKey(array $headers, string $host): ?array {
		$h = array();
		foreach ($headers as $name => $value) {
			$h[str_replace('_', '-', strtolower((string)$name))] = trim((string)$value);
		}
		$public = $h['public-key'] ?? '';
		$secret = $h['secret-key'] ?? '';
		if ($public === '' && $secret === '') {
			return null;
		}
		$refused = new SupportRefusal('The site\'s account key was not accepted. Reconnect the site to its account, or ask without it.', 'bad_key', 401);
		if ($public === '' || $secret === '' || !preg_match('/^public_[A-Za-z0-9]{8,64}$/', $public)) {
			throw $refused;
		}
		$found = null;
		foreach (new MultiApiKey(array('public_key' => $public)) as $k) {
			$found = $k;
			break;
		}
		if ($found === null || !$found->get('apk_is_active') || $found->get('apk_delete_time') || !$found->check_secret_key($secret)) {
			throw $refused;
		}
		$user_id = (int)$found->get('apk_usr_user_id');
		// The key must be the one this account holds for this very host.
		if (class_exists('ServiceTenant')) {
			$holds = false;
			foreach (ServiceTenant::forHost($user_id, $host) as $row) {
				if ((int)$row->get('svt_apk_api_key_id') === (int)$found->key) {
					$holds = true;
					break;
				}
			}
			if (!$holds) {
				throw $refused;
			}
		}
		return array('key_id' => (int)$found->key, 'user_id' => $user_id);
	}

	/**
	 * Call the claimed host back with the nonce its server issued. Returns the
	 * release the site says it runs ('' when it did not say).
	 *
	 * @throws SupportRefusal
	 */
	public static function confirmOrigin(string $host, string $nonce): string {
		if (!preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $nonce)) {
			throw new SupportRefusal('The site did not send its confirmation code. Update the site and try again.', 'unconfirmed', 403);
		}
		$client = self::$client ?: new SafeHttpClient(array(
			'timeout'            => 8,
			'connect_timeout'    => 5,
			'max_response_bytes' => 8192,
			'user_agent'         => 'Joinery/support-desk origin check',
		));
		try {
			$response = $client->post('https://' . $host . self::CONFIRM_PATH, (string)json_encode(array('nonce' => $nonce)),
				array('Content-Type' => 'application/json', 'Accept' => 'application/json'));
		} catch (\Throwable $e) {
			throw new SupportRefusal('This desk could not reach ' . $host . ' to confirm the question came from it.', 'unconfirmed', 403);
		}
		$env = json_decode((string)$response->body, true);
		$data = is_array($env['data'] ?? null) ? $env['data'] : array();
		if ($response->status !== 200 || empty($data['confirmed'])) {
			throw new SupportRefusal($host . ' did not confirm that this question came from it.', 'unconfirmed', 403);
		}
		// The code was issued for one desk. Here only if that desk is this node.
		$meant_for = strtolower(trim((string)($data['destination'] ?? '')));
		$this_node = ServicesClient::host();
		if ($meant_for !== $this_node) {
			throw new SupportRefusal($host . ' sent its question to ' . ($meant_for !== '' ? $meant_for : 'another support desk')
				. ', not to ' . $this_node . '. If both names reach this node, set the site\'s support address to '
				. $this_node . '.', 'unconfirmed', 403);
		}
		$version = trim((string)($data['version'] ?? ''));
		return SupportDocIndex::isVersion($version) ? $version : '';
	}

	/** The managed node whose site URL is this host, or null. */
	public static function nodeIdForHost(string $host): ?int {
		if (!class_exists('ManagedNode')) {
			return null;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT mgn_managed_node_id FROM mgn_managed_nodes
			  WHERE mgn_delete_time IS NULL AND mgn_site_url IS NOT NULL
			    AND lower(regexp_replace(mgn_site_url, '^[a-zA-Z]+://([^/:]+).*$', '\\1')) = ?
			  ORDER BY mgn_managed_node_id ASC LIMIT 1");
		$stmt->execute(array($host));
		$id = $stmt->fetchColumn();
		return $id === false ? null : (int)$id;
	}

	public static function isHostName(string $host): bool {
		return strlen($host) <= 253
			&& (bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
	}

	private static function title(string $message): string {
		$t = trim(preg_replace('/\s+/u', ' ', $message));
		return mb_strlen($t) > self::TITLE_MAX ? mb_substr($t, 0, self::TITLE_MAX - 1) . '…' : ($t !== '' ? $t : 'Question');
	}

	// ----------------------------------------------------------- reading back

	/**
	 * A turn's state for the panel: running (with the partial answer and the
	 * status line), complete (the answer), cancelled, or failed (the reason).
	 */
	public static function poll(SupportThread $thread, int $message_id): array {
		$msg = self::answerIn($thread, $message_id);
		if (ChatAsync::sweepMessage($msg)) {
			$msg->load();
		}
		$status = (string)$msg->get('aim_status');
		$out = array('status' => $status, 'state' => (string)$thread->get('sth_state'));
		if ($status === AiConversationMessage::STATUS_RUNNING) {
			$out['partial_text'] = (string)$msg->get('aim_content');
			$out += ChatSerializer::runningExtras($msg);
		} elseif ($status === AiConversationMessage::STATUS_FAILED) {
			$out['error'] = (string)$msg->get('aim_error') ?: 'The support desk could not answer this one.';
		} else {
			$out['message'] = self::messageOut($msg);
		}
		return $out;
	}

	/** The transcript for the panel: every finished message, oldest first. */
	public static function transcript(SupportThread $thread): array {
		$out = array();
		$cid = (int)$thread->get('sth_aic_conversation_id');
		foreach (new MultiAiConversationMessage(array('conversation_id' => $cid, 'deleted' => false),
				array('aim_conversation_message_id' => 'ASC')) as $m) {
			$out[] = self::messageOut($m);
		}
		return $out;
	}

	/** One message as the panel draws it. Markdown stays Markdown; the site renders it. */
	public static function messageOut(AiConversationMessage $m): array {
		$by_person = (int)$m->get('aim_author') > 0;
		return array(
			'id'        => (int)$m->key,
			'role'      => (string)$m->get('aim_role'),
			'author'    => $by_person ? 'person' : ((string)$m->get('aim_role') === 'assistant' ? 'assistant' : 'you'),
			'content'   => (string)$m->get('aim_content'),
			'status'    => (string)$m->get('aim_status'),
			'error'     => (string)$m->get('aim_status') === AiConversationMessage::STATUS_FAILED ? (string)$m->get('aim_error') : '',
			'time'      => (string)$m->get('aim_create_time'),
			'feedback'  => (string)$m->get('aim_feedback'),
			'reads'     => self::readsOf($m),
		);
	}

	/** The site reads a turn made, as the panel lists them: word and job number. */
	private static function readsOf(AiConversationMessage $m): array {
		$calls = json_decode((string)$m->get('aim_tool_calls'), true);
		$out = array();
		foreach (is_array($calls) ? $calls : array() as $tc) {
			$name = (string)($tc['name'] ?? '');
			if (!in_array($name, SupportNodeTools::WORDS, true)) {
				continue;
			}
			$job = preg_match('/job #(\d+)/', (string)($tc['output'] ?? ''), $mm) ? (int)$mm[1] : null;
			$out[] = array('word' => $name, 'job' => $job, 'ok' => empty($tc['is_error']));
		}
		return $out;
	}

	/** An answer (assistant row) of this thread, or the refusal. @throws SupportRefusal */
	public static function answerIn(SupportThread $thread, int $message_id): AiConversationMessage {
		$msg = null;
		if ($message_id > 0) {
			try {
				$msg = new AiConversationMessage($message_id, TRUE);
			} catch (\Throwable $e) {
				$msg = null;
			}
		}
		if ($msg === null || !$msg->key || $msg->get('aim_delete_time')
				|| (int)$msg->get('aim_aic_conversation_id') !== (int)$thread->get('sth_aic_conversation_id')
				|| $msg->get('aim_role') !== AiConversationMessage::ROLE_ASSISTANT) {
			throw new SupportRefusal('That answer is not in this thread.', 'no_message', 404);
		}
		return $msg;
	}

	/** Thread summary for lists and the unread mark. */
	public static function summary(SupportThread $t): array {
		return array(
			'thread_id' => (int)$t->key,
			'title'     => (string)$t->get('sth_title'),
			'state'     => (string)$t->get('sth_state'),
			'unread'    => (bool)$t->get('sth_unread_reply'),
			'updated'   => (string)$t->get('sth_last_activity_time'),
		);
	}
}
