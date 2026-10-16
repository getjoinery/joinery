<?php
/**
 * SupportChatClient — this site's side of the Help panel: where its questions
 * go, what it says about itself, and how it proves a thread is its own
 * (support_chatbot.md, The site side).
 *
 * The browser never talks to the support desk. The panel calls this site's
 * support_* actions, and this class calls the serving node's
 * /api/v1/action/support_desk/desk_* actions from the server: the site is the
 * identity, the browser holds no key, and the facts are built where they are.
 *
 * Where: support_url, an https address. Blank asks the upgrade source, where
 * this site's problem reports go (ProblemReport::destinationUrl()). With
 * neither naming an https node there is no Help panel: no button, and the
 * panel's actions refuse (enabled()). A node without the support desk answers
 * that support is not offered there, and the panel says so with a link to the
 * public documentation.
 *
 * Identity: a new thread carries a one-time code this site issued and stored
 * (SiteSupportThread); the desk calls the site back and support_origin_confirm
 * says yes only to a code it issued and has not used. The desk answers with
 * the thread's token, kept on the local row and sent with every later call. A
 * site connected to an account at the same node also signs with its services
 * key pair, and sees every thread that account opened from this host.
 *
 * What travels: the first message of a thread carries ProblemReportBundle,
 * built for the asking person's own permission (an operator's report carries
 * the site-wide sections; anyone below sees the member scope), plus the page
 * or setup step it was asked from and the consent flags. Only when
 * support_chat_consent is yes. Later messages carry the route.
 *
 * @version 1.2.0 - a blank support_url asks the upgrade source
 * @version 1.1.1 - a message a poll brings is drawn in the asker's timezone, not UTC
 */
class SupportChatException extends Exception {
	/** @var string a reason the panel can act on (not_offered, budget, consent, ...) */
	public $reason;
	public function __construct(string $message, string $reason = 'error') {
		parent::__construct($message);
		$this->reason = $reason;
	}
}

class SupportChatClient {

	const ACTION_BASE = '/api/v1/action/support_desk/';

	/** The permission level that may ask (the admin header's floor). */
	const ASK_PERMISSION = 5;

	/** The permission level that may allow live reads of this site. */
	const LIVE_READS_PERMISSION = 9;

	/** How long a one-time origin code stays good. */
	const NONCE_SECONDS = 300;

	/** Where the public documentation is, for a desk that answers nothing. */
	const PUBLIC_DOCS_URL = 'https://getjoinery.com/docs';

	/** @var SafeHttpClient|null a stand-in for the network, for tests */
	private static $client = null;

	public static function useClient(?SafeHttpClient $client): void {
		self::$client = $client;
	}

	/**
	 * The serving node's base URL: support_url, or the upgrade source when it
	 * is blank; '' when neither names an https node (the panel is off).
	 */
	public static function destinationUrl(): string {
		$url = trim((string)Globalvars::get_instance()->get_setting('support_url', true, true));
		if ($url === '') {
			$url = ProblemReport::destinationUrl();
		}
		return preg_match('#^https://[^/\s]+#', $url) ? rtrim($url, '/') : '';
	}

	/** Whether this site has a Help panel: a desk to ask. */
	public static function enabled(): bool {
		return self::destinationUrl() !== '';
	}

	public static function destinationHost(): string {
		return strtolower((string)parse_url(self::destinationUrl(), PHP_URL_HOST));
	}

	/** This site, as the desk checks it. */
	public static function site(): array {
		return array('host' => ServicesClient::host(), 'version' => (string)LibraryFunctions::get_joinery_version());
	}

	/** The consent this site gave: [answered, bundle, live_reads]. */
	public static function consent(): array {
		$s = Globalvars::get_instance();
		$raw = (string)$s->get_setting('support_chat_consent', true, true);
		$bundle = $raw === 'yes';
		return array(
			'answered'   => $raw === 'yes' || $raw === 'no',
			'bundle'     => $bundle,
			'live_reads' => $bundle && (string)$s->get_setting('support_chat_live_reads', true, true) === 'yes',
		);
	}

	/** Is this site joined to a management node (so live reads mean something)? */
	public static function joined(): bool {
		try {
			return ManagementNodeStatus::is_managed();
		} catch (\Throwable $e) {
			return false;
		}
	}

	/** A route as the desk takes it: an admin path or setup:{step}. */
	public static function cleanRoute(string $route): string {
		$route = trim($route);
		if (preg_match('#^setup:[a-z0-9_]{1,64}$#', $route)) {
			return $route;
		}
		$path = (string)parse_url($route, PHP_URL_PATH);
		return preg_match('#^/[A-Za-z0-9_\-/]{0,200}$#', $path) ? $path : '';
	}

	/** The bundle the first message carries, for this person and route. */
	public static function bundle(SessionControl $session, string $route): array {
		$reporter = ProblemReport::reporter($session);
		$from = strpos($route, 'setup:') === 0 ? '/setup' : $route;
		$bundle = ProblemReportBundle::build($reporter['user_id'], $reporter['permission'], $reporter['logged_in_as'], null, $from, '');
		$consent = self::consent();
		$bundle['support'] = array(
			'route'   => $route,
			'consent' => array('bundle' => $consent['bundle'], 'live_reads' => $consent['live_reads']),
		);
		return $bundle;
	}

	/**
	 * One call to the serving node. Returns the answer's data; throws
	 * SupportChatException with the desk's own words (and reason) when it refuses.
	 */
	public static function call(string $action, array $payload): array {
		if (!preg_match('/^desk_[a-z_]+$/', $action)) {
			throw new SupportChatException('Unknown support action.');
		}
		if (!self::enabled()) {
			throw new SupportChatException('This site has no support desk set (support_url).', 'off');
		}
		$payload['site'] = self::site();
		$headers = array('Content-Type' => 'application/json', 'Accept' => 'application/json');
		$services = new ServicesClient();
		if ($services->configured() && strtolower((string)parse_url($services->serviceUrl(), PHP_URL_HOST)) === self::destinationHost()) {
			foreach ($services->authHeaders() as $k => $v) {
				$headers[$k] = $v;
			}
		}
		$client = self::$client ?: new SafeHttpClient(array(
			'timeout'            => 20,
			'connect_timeout'    => 6,
			'max_response_bytes' => 2097152,
			'user_agent'         => 'Joinery/' . LibraryFunctions::get_joinery_version() . ' support-panel',
		));
		try {
			$response = $client->post(self::destinationUrl() . self::ACTION_BASE . $action, (string)json_encode($payload), $headers);
		} catch (\Throwable $e) {
			throw new SupportChatException('The support desk at ' . self::destinationHost() . ' could not be reached. Try again in a minute.', 'unreachable');
		}
		$env = json_decode((string)$response->body, true);
		if (!is_array($env)) {
			throw new SupportChatException('The support desk at ' . self::destinationHost() . ' gave an answer this site could not read.', 'unreachable');
		}
		if ($response->status === 404 && stripos((string)($env['error'] ?? ''), 'Unknown action') !== false) {
			throw new SupportChatException('Support is not offered at ' . self::destinationHost() . '. The documentation is open to everyone.', 'not_offered');
		}
		if ($response->status >= 400 || isset($env['error'])) {
			$reason = is_array($env['data'] ?? null) ? (string)($env['data']['reason'] ?? '') : '';
			$message = is_string($env['error'] ?? null) ? $env['error'] : ('The support desk answered HTTP ' . $response->status . '.');
			throw new SupportChatException(mb_substr($message, 0, 500), $reason !== '' ? $reason : 'refused');
		}
		return is_array($env['data'] ?? null) ? $env['data'] : array();
	}

	// ----------------------------------------------------------- the panel

	/**
	 * Ask: a new thread (thread_id 0) or a later question in one of this
	 * site's threads. Returns the poll handle with the local thread id.
	 */
	public static function ask(SessionControl $session, int $local_thread_id, string $message, string $route): array {
		$consent = self::consent();
		if (!$consent['answered']) {
			throw new SupportChatException('Say first what the Help panel may send about this site.', 'consent');
		}
		$route = self::cleanRoute($route);
		$payload = array(
			'message' => $message,
			'route'   => $route,
			'consent' => array('bundle' => $consent['bundle'], 'live_reads' => $consent['live_reads']),
		);
		if ($local_thread_id > 0) {
			$local = SiteSupportThread::mine($local_thread_id);
			$payload['thread_id'] = (int)$local->get('spt_remote_id');
			$payload['token'] = $local->token();
			$data = self::call('desk_ask', $payload);
		} else {
			$local = SiteSupportThread::begin((int)$session->get_user_id(), self::destinationHost(), $message);
			$payload['origin_nonce'] = $local->issueNonce();
			if ($consent['bundle']) {
				$payload['bundle'] = self::bundle($session, $route);
			}
			try {
				$data = self::call('desk_ask', $payload);
			} catch (SupportChatException $e) {
				$local->permanent_delete();
				throw $e;
			}
			$local->opened((int)($data['thread_id'] ?? 0), (string)($data['token'] ?? ''));
		}
		$local->noteState((string)($data['state'] ?? ''), false);
		// The token proves the thread is this site's; it stays on the server.
		unset($data['token']);
		$data['thread_id'] = (int)$local->key;
		return $data;
	}

	/** The site's threads with their state from the desk; with a thread, its transcript too. */
	public static function threads(int $user_id, int $open_thread_id = 0): array {
		$list = array();
		$by_remote = array();
		foreach (SiteSupportThread::recent() as $t) {
			if ((int)$t->get('spt_remote_id') <= 0) {
				continue;
			}
			$list[] = array('thread_id' => (int)$t->get('spt_remote_id'), 'token' => $t->token());
			$by_remote[(int)$t->get('spt_remote_id')] = $t;
		}
		$payload = array('threads' => $list);
		$open = null;
		if ($open_thread_id > 0) {
			$open = SiteSupportThread::mine($open_thread_id);
			$payload['thread_id'] = (int)$open->get('spt_remote_id');
			$payload['token'] = $open->token();
		}
		$data = self::call('desk_threads', $payload);
		$out = array('threads' => array());
		foreach ((array)($data['threads'] ?? array()) as $t) {
			$remote_id = (int)($t['thread_id'] ?? 0);
			$local = $by_remote[$remote_id] ?? null;
			if ($local === null && !empty($t['account']) && $remote_id > 0) {
				// The account's thread from before this site had a row for it.
				$local = SiteSupportThread::adopt(self::destinationHost(), $remote_id, (string)($t['title'] ?? ''));
			}
			if ($local === null) {
				continue;
			}
			$local->noteState((string)($t['state'] ?? ''), !empty($t['unread']));
			$out['threads'][] = array('thread_id' => (int)$local->key, 'title' => (string)$local->get('spt_title'),
				'state' => (string)($t['state'] ?? ''), 'unread' => !empty($t['unread']), 'updated' => (string)($t['updated'] ?? ''));
		}
		if ($open !== null) {
			$open->noteState((string)($data['thread']['state'] ?? ''), false);
			// remote_id and desk name the thread where the desk can find it (a bug report quotes them).
			$out['thread'] = array('thread_id' => (int)$open->key, 'title' => (string)$open->get('spt_title'),
				'state' => (string)($data['thread']['state'] ?? ''), 'remote_id' => (int)$open->get('spt_remote_id'),
				'desk' => (string)$open->get('spt_destination'));
			$out['html'] = self::transcriptHtml((array)($data['messages'] ?? array()), $user_id);
		}
		$out['unread'] = SiteSupportThread::unreadCount();
		return $out;
	}

	/** A later call about one thread (poll, cancel, feedback, escalate), proven by its token. */
	public static function about(string $action, int $local_thread_id, array $payload): array {
		$local = SiteSupportThread::mine($local_thread_id);
		$payload['thread_id'] = (int)$local->get('spt_remote_id');
		$payload['token'] = $local->token();
		$data = self::call($action, $payload);
		if (isset($data['state'])) {
			$local->noteState((string)$data['state'], false);
		}
		if (isset($data['message']) && is_array($data['message'])) {
			// Drawn in the asker's timezone, as the first load of the thread is.
			$data['html'] = self::transcriptHtml(array($data['message']), (int)SessionControl::get_instance()->get_user_id());
		}
		return $data;
	}

	/**
	 * The desk's messages drawn by the core transcript renderer, in this
	 * site's time zone. The words came from another machine: the renderer
	 * escapes them, and Markdown is rendered without raw HTML.
	 */
	public static function transcriptHtml(array $messages, int $user_id): string {
		$tz = 'UTC';
		if ($user_id > 0) {
			$u = new User($user_id, TRUE);
			$tz = $u->get('usr_timezone') ?: 'UTC';
		}
		$out = '';
		foreach ($messages as $m) {
			if (!is_array($m)) {
				continue;
			}
			$time = LibraryFunctions::convert_time((string)($m['time'] ?? gmdate('Y-m-d H:i:s')), 'UTC', $tz, 'g:i A');
			$id = (int)($m['id'] ?? 0);
			$content = (string)($m['content'] ?? '');
			if (($m['role'] ?? '') === 'user') {
				$out .= ChatTranscript::userBubble($content, $time, array('message_id' => $id));
				continue;
			}
			$status = (string)($m['status'] ?? '');
			if ($status === 'failed') {
				$out .= ChatTranscript::failedBubble((string)($m['error'] ?? ''), $id);
				continue;
			}
			if ($status === 'running') {
				continue;
			}
			$by_person = ($m['author'] ?? '') === 'person';
			$meta = array();
			foreach ((array)($m['reads'] ?? array()) as $read) {
				$meta[] = '<span class="jy-support-read">read ' . htmlspecialchars((string)($read['word'] ?? ''), ENT_QUOTES, 'UTF-8')
					. (!empty($read['job']) ? ' (job #' . (int)$read['job'] . ')' : '') . '</span>';
			}
			$after = $by_person ? '' : self::thumbsHtml($id, (string)($m['feedback'] ?? ''));
			$out .= ChatTranscript::assistantBubble($content, $time, array(
				'message_id' => $id,
				'meta_parts' => $meta,
				'cancelled'  => $status === 'cancelled',
				'by_person'  => $by_person,
				'after_html' => $after,
			));
		}
		return $out;
	}

	private static function thumbsHtml(int $message_id, string $given): string {
		if ($message_id <= 0) {
			return '';
		}
		$up = $given === 'up' ? ' is-chosen' : '';
		$down = $given === 'down' ? ' is-chosen' : '';
		return '<div class="jy-support-thumbs" data-message-id="' . $message_id . '">'
			. '<button type="button" class="jy-support-thumb' . $up . '" data-verdict="up">Helpful</button>'
			. '<button type="button" class="jy-support-thumb' . $down . '" data-verdict="down">Not helpful</button>'
			. '</div>';
	}
}
