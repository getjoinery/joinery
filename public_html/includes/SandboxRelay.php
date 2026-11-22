<?php
/**
 * SandboxRelay - core carries a fenced plugin's asks out, with the plugin's
 * approved reach and nothing more (specs/sandboxed_php_plugins.md design 3).
 *
 * SandboxRunner hands it each ask the fenced process wrote and writes back
 * what it returns. The asks:
 *
 *   record.get    {model, id}                                  GET    /api/v1/{Class}/{id}
 *   record.list   {model, filters, sort, direction, limit, offset}  GET /api/v1/{Class}s?...
 *   record.create {model, fields}                              POST   /api/v1/{Class}
 *   record.update {model, id, fields}                          PUT    /api/v1/{Class}/{id}
 *   record.delete {model, id}                                  DELETE /api/v1/{Class}/{id}
 *   action        {name, input}                                POST   /api/v1/action/{name}
 *   http          {method, url, headers, body}                 HTTPS to an approved outbound host
 *   log           {level, message}                             a line in the call's log
 *   email.send    {message, queue_on_failure}                  EmailSender::send() on the site
 *   email.batch   {message, recipients}                        EmailSender::sendBatch() on the site
 *   email.render  {inner, outer, footer, values}               EmailTemplateRenderer filled on the site
 *   file.upload   {tmp_name, name, type, owner_id, restrictions}  File::createFromUpload() of one of this call's uploads
 *   file.bytes    {part, more, name, type, owner_id, restrictions}  File::createFromBytes(), the bytes base64 in parts
 *   file.read     {id, offset}                                 read_bytes() of a file it reaches, base64, one part
 *   page.refresh  {path, with_children}                        StaticPageCache::invalidatePath() under its own /{plugin}/
 *
 * Record and action asks go to this site's own API over this machine's
 * loopback, signed with the plugin's key, which is loaded here by plugin name
 * for each call (SandboxInstall::credentialFor) and never written toward the
 * fence. The address is built from names checked against the key's scope
 * (SandboxCredentials::scopeFor) and an integer id, never from the plugin's
 * text. Every gate of the API then applies as for any caller: the scope, the
 * model's opt-in, the unreadable-column floor, per-row checks, the page cap,
 * rate limits, the locked answer for sealed rows.
 *
 * A filter or sort reaches the API only when it is a declared column of the
 * model that the API would show (or `deleted`), sent by its full column name
 * with a scalar value: many collections join their own option values into
 * SQL, and the relay does not depend on every one of them being right.
 *
 * A delete is the API's delete, which is soft_delete(): the row stays in its
 * table and an admin can restore it. Nothing here reaches permanent_delete().
 *
 * An http ask goes through SafeHttpClient (public addresses only, no
 * redirects, 1 MB answers) to a host the plugin asked for and an admin
 * approved, on 443, with the archive host's header rules: no header that
 * steers the connection, and none whose name or value carries CR, LF or NUL.
 *
 * An email ask is the plugin's EmailSender or EmailTemplateRenderer call,
 * run here with those same classes, so every rule of the site's mail applies
 * as for native code: its defaults, the protected identity domains, test mode,
 * the retry queue. It is allowed only when the plugin was approved to send
 * mail (send_email). The message arrives as EmailMessage::toTransfer()'s data
 * and is rebuilt by fromTransfer(), which takes an attachment only as bytes:
 * a path is read in the fence, where the plugin can read only what it may.
 * A footer the plugin gives as text is template source run here, in the
 * site's worker, outside the fence's limits, so it may not hold a loop
 * (reviewer2 P3). A date in the values arrives tagged and is a DateTime again.
 *
 * A file ask is the plugin's File call, run here with File, allowed only when
 * the plugin was approved to take uploads (accept_uploads; spec
 * sandboxed_plugin_uploads). A file it makes is the viewer's, private, and
 * tagged as the plugin's (fil_source sbx_{plugin}) with its install id (fil_install_id),
 * which its own-files reach checks; the only columns it may
 * set are the title and description. An upload is taken only when its path
 * is one of the uploads core put in this call's envelope, and PHP says it was
 * uploaded in this request. File is not a model the API serves, so its
 * record asks for File (get, list, update, delete) and file.read are
 * carried out here: they reach the files with its tag, or every file with an
 * approved records grant on File for the verb. Bytes cross in parts of
 * FILE_PART_BYTES, up to half the call's memory past the runtime's.
 *
 * A call for a visitor not signed in (a public page; the envelope's
 * `anonymous`, which core sets, spec sandboxed_plugin_public_pages design 3)
 * reads only, unless it answers a form POST that passed the site's spam
 * checks: then it may make at most MAX_ANON_WRITES record writes and actions, and address at
 * most MAX_ANON_RECIPIENTS people, each counted against the visitor's
 * network (ANON_MAIL_FEATURE), and never send a batch.
 *
 * @version 1.8 - a call for a visitor not signed in reads only outside a checked form POST, and within one writes
 *               and mails a little (anonymousGate()); page.refresh drops its own public pages' cached copies
 *               (spec sandboxed_plugin_public_pages)
 * @version 1.7 - a file it keeps carries its install id (fil_install_id), and its own-files reach is by tag and id,
 *               so a later plugin of the same name reaches none of them (spec plugin_install_identity A1)
 * @version 1.6 - a Drive file is read only to a plugin: rename and trash refused (reviewer2 B1, owner 2026-11-21)
 * @version 1.5 - a records grant on File reaches every file; bytes cross in parts, up to half the call's memory
 *                past the runtime's; the memory may be up to what the site gives a page request
 *                (owner 2026-11-21, spec sandboxed_plugin_uploads)
 * @version 1.4 - file.upload, file.bytes, file.read, and record asks for its own files (spec sandboxed_plugin_uploads)
 * @version 1.3 - email.send, email.batch and email.render (spec sandboxed_plugin_email)
 * @version 1.2 - own-rows grants: a record ask the plugin holds only as Model:verb:own goes as the member viewing
 *                (VIEWER_HEADER, from core's envelope), and is refused on a call with no viewer (design 8)
 * @version 1.1 - reviewer2 E1/E5: plain HTTP to port 80 says X-Forwarded-Proto https; one time budget across both
 *                tries; a webDir with a port is refused
 * @version 1.0
 */

class SandboxRelay {

	/** The largest answer read from the API or an outside host. */
	const MAX_ANSWER_BYTES = 1048576;

	/** The longest one API or outside call may take, within the call's deadline. */
	const MAX_REQUEST_SECONDS = 15;

	/** A list is read at most this many rows at a time (the API's page cap). */
	const MAX_LIST_ROWS = 200;

	/** Headers a plugin may not set on an outside request: they steer the connection. */
	const FORBIDDEN_HEADERS = array('host', 'connection', 'content-length', 'transfer-encoding', 'te', 'upgrade',
		'proxy-authorization', 'proxy-connection', 'keep-alive', 'trailer', 'user-agent', 'expect', 'cookie2');

	const HEADER_NAME_PATTERN = '~^[A-Za-z0-9!#$%&\'*+.^_`|\~-]{1,64}$~D';

	const MAX_HEADERS = 30;

	const HTTP_METHODS = array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD');

	const LOG_LEVELS = array('debug', 'info', 'warn', 'error');

	/** What each record ask is, as an API verb. */
	const RECORD_VERBS = array(
		'record.get' => 'read', 'record.list' => 'read', 'record.create' => 'create',
		'record.update' => 'update', 'record.delete' => 'delete',
	);

	/**
	 * Tests only: a callable (string $method, string $path, ?string $json, array $headers, float $seconds)
	 * => array{0:int status, 1:string body}, in place of the loopback request. Never set by the platform.
	 *
	 * @var callable|null
	 */
	public static $transport = null;

	/**
	 * Tests only: a callable (string $method, string $url, ?string $body, array $headers, float $seconds)
	 * => SafeHttpResponse, in place of SafeHttpClient. Never set by the platform.
	 *
	 * @var callable|null
	 */
	public static $outbound = null;

	/**
	 * Tests only: a callable (EmailSender $sender, EmailMessage $message) => void, called with the message as
	 * the site rebuilt it, just before it is sent. Never set by the platform.
	 *
	 * @var callable|null
	 */
	public static $mail_seen = null;

	/**
	 * Tests only: a callable (string $path) => bool, in place of is_uploaded_file(). Never set by the platform.
	 *
	 * @var callable|null
	 */
	public static $upload_check = null;

	/** A file's bytes cross the pipe in parts of this size, each well inside one message (SandboxRunner::MAX_LINE_BYTES). */
	const FILE_PART_BYTES = 1048576;

	/** The columns a plugin may set on a file it keeps. */
	const FILE_COLUMNS = array('fil_title', 'fil_description');

	/** @var array<string,int> this call's uploads, core's envelope: tmp_name => its upload error */
	private $uploads = array();

	/** @var int the call's memory, from core's envelope */
	private $memory_mb = 16;

	/** @var array|null {id, bytes} the file being read in parts */
	private $reading = null;

	/** @var string|null the temporary file a file made from bytes is gathered in, part by part */
	private $pending = null;

	/** @var int */
	private $pending_size = 0;

	/** @var string */
	private $plugin;
	/** @var string|null this install's id, read once (installId()) */
	private $install_id = null;
	/** @var float */
	private $deadline;
	/** @var array the call log's entries */
	private $calls = array();
	/** @var string[]|null */
	private $scope = null;
	/** @var array<string,string>|null the plugin's own model names => class */
	private $own = null;
	/** @var array|null|false the credential, false when there is none */
	private $credential = null;

	/** @var int|null the member viewing the page this call draws or answers, from core's envelope */
	private $viewer = null;

	/** @var bool whether the current record ask is made as the viewer (an own-rows grant) */
	private $as_viewer = false;

	/** The header that names the viewer to the API (apiv1.php, ApiAuth::$sandbox_viewer). */
	const VIEWER_HEADER = 'X-Joinery-Sandbox-Viewer';

	/** Record writes, actions and page.refresh asks together one checked anonymous POST may make (P14, P18). */
	const MAX_ANON_WRITES = 10;

	/** People one checked anonymous POST may address, To, Cc and Bcc together (P5). */
	const MAX_ANON_RECIPIENTS = 3;

	/** Each person an anonymous call mails counts here, per network: 10 an hour (P5). */
	const ANON_MAIL_FEATURE = 'sandbox_public_mail';
	const ANON_MAIL_PER_HOUR = 10;

	/** The asks a call for a visitor not signed in may make outside a checked POST: it reads. */
	const ANON_READ_ASKS = array('record.get', 'record.list', 'log', 'email.render', 'file.read');

	/** @var array|null core's word that this call is for a visitor not signed in: {checked_post} */
	private $anonymous = null;

	/** @var int record writes this anonymous call made */
	private $anon_writes = 0;

	/** @var int people this anonymous call addressed */
	private $anon_recipients = 0;

	public function __construct(string $plugin, array $envelope = array()) {
		$this->plugin = $plugin;
		$this->deadline = microtime(true) + SandboxManifest::MAX_TIMEOUT_MS / 1000;
		// Core's envelope, never the plugin: a page or UI call carries the
		// member it is for; a signal or a schedule carries none.
		$id = $envelope['input']['user']['id'] ?? null;
		$this->viewer = is_int($id) && $id > 0 ? $id : null;
		// Also core's: a public page's call for a visitor not signed in.
		$this->anonymous = is_array($envelope['anonymous'] ?? null) ? $envelope['anonymous'] : null;
		$memory = $envelope['limits']['memory_mb'] ?? null;
		$this->memory_mb = is_int($memory) && $memory > 0 ? min($memory, SandboxManifest::memoryCeilingMb()) : SandboxManifest::DEFAULT_MEMORY_MB;
		// The request's uploads, as core put them in the envelope (SandboxPages::requestFiles()).
		foreach ((array)($envelope['input']['files'] ?? array()) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$paths = (array)($entry['tmp_name'] ?? array());
			$errors = (array)($entry['error'] ?? array());
			foreach ($paths as $i => $path) {
				if (is_string($path) && $path !== '') {
					$this->uploads[$path] = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
				}
			}
		}
	}

	public function setDeadline(float $deadline): void {
		$this->deadline = $deadline;
	}

	/** @return array the call log's entries, in order */
	public function calls(): array {
		return $this->calls;
	}

	/** Carry out one ask. @return array the answer written back to the plugin */
	public function handle(array $ask): array {
		$kind = (string)$ask['ask'];
		try {
			$gate = $this->anonymousGate($kind, $ask);
			if ($gate !== null) {
				return $gate;
			}
			if ($kind === 'page.refresh') {
				return $this->pageRefresh($ask);
			}
			if (isset(self::RECORD_VERBS[$kind])) {
				return $this->record($kind, $ask);
			}
			if ($kind === 'action') {
				return $this->action($ask);
			}
			if ($kind === 'http') {
				return $this->http($ask);
			}
			if ($kind === 'log') {
				return $this->log($ask);
			}
			if (in_array($kind, array('email.send', 'email.batch', 'email.render'), true)) {
				return $this->email($kind, $ask);
			}
			if (in_array($kind, array('file.upload', 'file.bytes', 'file.read'), true)) {
				return $this->file($kind, $ask);
			}
			return $this->refuse('ask', mb_substr($kind, 0, 40), 'There is no ask ' . mb_substr($kind, 0, 40) . '.');
		} catch (Throwable $e) {
			error_log('[SandboxRelay] ' . $this->plugin . ' ' . mb_substr($kind, 0, 40) . ': ' . $e->getMessage());
			$this->note('ask', mb_substr($kind, 0, 40), 'error', 'failed');
			return array('ok' => false, 'error' => 'The ask failed.');
		}
	}

	// ---- a visitor not signed in ----------------------------------------------

	/**
	 * Null when the ask may go on; otherwise the refusal. Only a call for a
	 * visitor not signed in is held: it reads, unless it answers a checked
	 * form POST (design 3); within one, its writes, the people it mails and
	 * the network's mail count are capped (P14, P5). An ask counted here
	 * and then refused further on still counts.
	 */
	private function anonymousGate(string $kind, array $ask): ?array {
		if ($this->anonymous === null) {
			return null;
		}
		$short = mb_substr($kind, 0, 40);
		if (empty($this->anonymous['checked_post'])) {
			if (in_array($kind, self::ANON_READ_ASKS, true)) {
				return null;
			}
			return $this->refuse('visitor', $short, 'This call is for a visitor who is not signed in, and is not a form they sent, so it may only read.');
		}
		if ($kind === 'email.batch') {
			return $this->refuse('visitor', $short, 'A visitor who is not signed in cannot have a batch of mail sent.');
		}
		// An action may store or send what a visitor typed as a write does, so
		// it counts in the same cap (P18, reviewer2 R2).
		if (in_array($kind, array('record.create', 'record.update', 'record.delete', 'action', 'page.refresh'), true)) {
			if ($this->anon_writes >= self::MAX_ANON_WRITES) {
				return $this->refuse('visitor', $short, 'A form a visitor who is not signed in sent may make at most ' . self::MAX_ANON_WRITES . ' changes.');
			}
			$this->anon_writes++;
			return null;
		}
		if ($kind === 'email.send' && !empty(SandboxConsent::effective($this->plugin)['email'])) {
			try {
				$message = EmailMessage::fromTransfer(is_array($ask['message'] ?? null) ? $ask['message'] : array());
			} catch (InvalidArgumentException $e) {
				return null;
			}
			$people = count($message->getRecipients() ?: array()) + count($message->getCc() ?: array()) + count($message->getBcc() ?: array());
			if ($this->anon_recipients + $people > self::MAX_ANON_RECIPIENTS) {
				return $this->refuse('visitor', $short, 'A form a visitor who is not signed in sent may have mail sent to at most '
					. self::MAX_ANON_RECIPIENTS . ' people.');
			}
			$state = RequestLogger::attempt_limit_state(self::ANON_MAIL_FEATURE, self::ANON_MAIL_PER_HOUR, 3600);
			if ($state['count'] + $people > self::ANON_MAIL_PER_HOUR) {
				return $this->refuse('visitor', $short, RequestLogger::attempt_unit_label() . ' has had mail sent to '
					. self::ANON_MAIL_PER_HOUR . ' people in the last hour from forms anyone can send.');
			}
			$this->anon_recipients += $people;
			for ($i = 0; $i < $people; $i++) {
				RequestLogger::log(self::ANON_MAIL_FEATURE, 'recipient', true);
			}
		}
		return null;
	}

	// ---- the page cache ------------------------------------------------------

	/**
	 * page.refresh: drop the cached copies of one of the plugin's own pages
	 * (StaticPageCache::invalidatePath(), as native code calls it), under
	 * /{plugin}/ only. Allowed where writes are.
	 */
	private function pageRefresh(array $ask): array {
		$path = is_string($ask['path'] ?? null) ? '/' . trim($ask['path'], '/') : '';
		$own = '/' . $this->plugin;
		if ($path !== $own && strpos($path, $own . '/') !== 0 || strlen($path) > 200 || strpos($path, '..') !== false) {
			return $this->refuse('cache', mb_substr($path, 0, 80), 'A plugin drops the cached copies of its own pages only, under ' . $own . '/.');
		}
		$removed = StaticPageCache::invalidatePath($path, !empty($ask['with_children']));
		$this->note('cache', $path, 'ok', (int)$removed . ' dropped');
		return array('ok' => true, 'removed' => (int)$removed);
	}

	// ---- records ---------------------------------------------------------

	private function record(string $kind, array $ask): array {
		$verb = self::RECORD_VERBS[$kind];
		if (($ask['model'] ?? null) === 'File' && $this->fileReach($verb) !== null) {
			return $this->fileRecord($kind, $ask);
		}
		$class = $this->modelClass($ask['model'] ?? null, $verb);
		if (!is_string($class)) {
			return $this->refuse('api', $kind, $class['error']);
		}
		$id = null;
		if (in_array($kind, array('record.get', 'record.update', 'record.delete'), true)) {
			$id = self::positiveInt($ask['id'] ?? null);
			if ($id === null) {
				return $this->refuse('api', $kind . ' ' . $class, 'The id must be a whole number above 0.');
			}
		}
		$target = strtoupper(array('read' => 'get', 'create' => 'post', 'update' => 'put', 'delete' => 'delete')[$verb])
			. ' ' . $class . ($id !== null ? ' ' . $id : '');

		$body = null;
		if ($kind === 'record.create' || $kind === 'record.update') {
			$fields = $this->fields($class, $ask['fields'] ?? null);
			if (is_string($fields)) {
				return $this->refuse('api', $target, $fields);
			}
			$body = json_encode((object)$fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		}

		if ($kind === 'record.list') {
			$query = $this->listQuery($class, $ask);
			if (is_string($query)) {
				return $this->refuse('api', 'GET ' . $class . ' list', $query);
			}
			$path = '/api/v1/' . $class . 's?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
			$target = 'GET ' . $class . ' list';
			$method = 'GET';
		} else {
			$path = '/api/v1/' . $class . ($id !== null ? '/' . $id : '');
			$method = array('record.get' => 'GET', 'record.create' => 'POST', 'record.update' => 'PUT', 'record.delete' => 'DELETE')[$kind];
		}

		$answer = $this->api($method, $path, $body, $target);
		if (!$answer['ok']) {
			return $answer;
		}
		$decoded = $answer['decoded'];
		if ($kind === 'record.list') {
			return array('ok' => true, 'status' => $answer['status'], 'records' => is_array($decoded['data'] ?? null) ? $decoded['data'] : array(),
				'total' => (int)($decoded['num_results'] ?? 0));
		}
		return array('ok' => true, 'status' => $answer['status'], 'record' => $decoded['data'] ?? null);
	}

	/**
	 * The class a model name means for this plugin: one of its own models by
	 * its short name, or a class its key is scoped to for the verb.
	 *
	 * @return string|array the class, or ['error' => why]
	 */
	private function modelClass($model, string $verb) {
		if (!is_string($model) || !preg_match('~^[A-Z][A-Za-z0-9_]{0,79}$~D', $model)) {
			return array('error' => 'The model must be named.');
		}
		$own = $this->ownModels();
		$class = $own[$model] ?? $model;
		$this->as_viewer = false;
		if (!in_array($class . ':' . $verb, $this->scope(), true)) {
			if (!in_array($class . ':' . $verb . ':own', $this->scope(), true)) {
				return array('error' => 'This plugin may not ' . $verb . ' ' . $model . ' records (not approved).');
			}
			// Each member's own rows only: asked as the member viewing.
			if ($this->viewer === null) {
				return array('error' => 'This plugin may ' . $verb . ' only each member\'s own ' . $model . ' records, and this call has no member viewing it.');
			}
			$this->as_viewer = true;
		}
		if (!class_exists($class) || !is_subclass_of($class, 'SystemBase')) {
			return array('error' => $model . ' is not a model on this site.');
		}
		return $class;
	}

	/**
	 * The column a plugin's key means: the full column name, or the name
	 * without the model's prefix. Null unless it is a declared column the API
	 * would show.
	 */
	public static function readableColumn(string $class, $key): ?string {
		if (!is_string($key) || !preg_match('~^[a-z][a-z0-9_]{0,99}$~D', $key)) {
			return null;
		}
		$specs = $class::$field_specifications;
		$prefix = isset($class::$prefix) ? (string)$class::$prefix : '';
		foreach (array($key, $prefix . '_' . $key) as $column) {
			if (isset($specs[$column]) && !$class::is_unreadable_field($column)) {
				return $column;
			}
		}
		return null;
	}

	/**
	 * The query string of a list: each filter a readable declared column with
	 * one scalar value, or deleted; one sort column; a page.
	 *
	 * @return array|string the query, or why it is refused
	 */
	public static function listQuery(string $class, array $ask) {
		$query = array();
		$filters = $ask['filters'] ?? array();
		if (!is_array($filters)) {
			return 'Filters must be an object of column => value.';
		}
		foreach ($filters as $key => $value) {
			if ($key === 'deleted') {
				if (!is_bool($value)) {
					return 'The deleted filter is true or false.';
				}
				$query['deleted'] = $value ? '1' : '0';
				continue;
			}
			$column = self::readableColumn($class, $key);
			if ($column === null) {
				return 'A list can be filtered only on a column of the model it may read; ' . mb_substr((string)$key, 0, 60) . ' is not one.';
			}
			$text = self::scalarText($value);
			if ($text === null) {
				return 'The filter on ' . $key . ' must be one value (text, a number or true/false).';
			}
			$query[$column] = $text;
		}
		if (isset($ask['sort']) && $ask['sort'] !== null) {
			$column = self::readableColumn($class, $ask['sort']);
			if ($column === null) {
				return 'A list can be sorted only on a column of the model it may read; ' . mb_substr((string)$ask['sort'], 0, 60) . ' is not one.';
			}
			$direction = strtoupper((string)($ask['direction'] ?? 'ASC'));
			if ($direction !== 'ASC' && $direction !== 'DESC') {
				return 'The direction is asc or desc.';
			}
			$query['sort'] = $column;
			$query['sdirection'] = $direction;
		}
		$limit = self::positiveInt($ask['limit'] ?? 50);
		if ($limit === null || $limit > self::MAX_LIST_ROWS) {
			return 'The limit is from 1 to ' . self::MAX_LIST_ROWS . '.';
		}
		$offset = $ask['offset'] ?? 0;
		if (!is_int($offset) || $offset < 0 || $offset % $limit !== 0) {
			return 'The offset is a whole multiple of the limit.';
		}
		$query['numperpage'] = (string)$limit;
		$query['page'] = (string)intdiv($offset, $limit);
		return $query;
	}

	/** @return array|string the fields to send, or why they are refused */
	private function fields(string $class, $fields) {
		if (!is_array($fields) || ($fields !== array() && array_is_list($fields))) {
			return 'Fields must be an object of column => value.';
		}
		$out = array();
		foreach ($fields as $key => $value) {
			$column = self::readableColumn($class, $key);
			if ($column === null || $class::is_unwritable_field($column)) {
				return mb_substr((string)$key, 0, 60) . ' is not a column this plugin may write.';
			}
			if (!is_scalar($value) && $value !== null && !is_array($value)) {
				return 'The value of ' . $key . ' cannot be stored.';
			}
			$out[$column] = $value;
		}
		return $out;
	}

	// ---- actions ---------------------------------------------------------

	private function action(array $ask): array {
		$name = $ask['name'] ?? null;
		if (!is_string($name) || !preg_match(SandboxManifest::ACTION_PATTERN, $name)) {
			return $this->refuse('api', 'action', 'The action must be named.');
		}
		$this->as_viewer = false;
		$target = 'POST action ' . $name;
		if (!in_array($name, $this->scope(), true)) {
			return $this->refuse('api', $target, 'This plugin may not call the action ' . $name . ' (not approved).');
		}
		$input = $ask['input'] ?? array();
		if (!is_array($input)) {
			return $this->refuse('api', $target, 'The input must be an object.');
		}
		$answer = $this->api('POST', '/api/v1/action/' . $name, json_encode((object)$input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $target);
		if (!$answer['ok']) {
			return $answer;
		}
		return array('ok' => true, 'status' => $answer['status'], 'data' => $answer['decoded']['data'] ?? $answer['decoded']);
	}

	// ---- the loopback ----------------------------------------------------

	/**
	 * One request to this site's own API with the plugin's key.
	 *
	 * @return array {ok, status, decoded} or the refusal answer
	 */
	private function api(string $method, string $path, ?string $body, string $target): array {
		$credential = $this->credential();
		if ($credential === null) {
			return $this->refuse('api', $target, 'This plugin has no API credential (it has not been approved, or its key is off).');
		}
		$seconds = $this->secondsLeft();
		if ($seconds <= 0) {
			$this->note('api', $target, 'timeout', 'no time left');
			return array('ok' => false, 'error' => 'The call ran out of time.');
		}
		$headers = array(
			'public-key' => $credential['public_key'],
			'secret-key' => $credential['secret_key'],
			'Accept' => 'application/json',
		);
		if ($body !== null) {
			$headers['Content-Type'] = 'application/json';
		}
		if ($this->as_viewer) {
			$headers[self::VIEWER_HEADER] = (string)$this->viewer;
		}
		list($status, $raw) = self::$transport !== null
			? call_user_func(self::$transport, $method, $path, $body, $headers, $seconds)
			: self::loopback($method, $path, $body, $headers, $seconds);
		if ($status === 0) {
			$this->note('api', $target, 'error', mb_substr((string)$raw, 0, 200));
			return array('ok' => false, 'error' => 'The site did not answer.');
		}
		$decoded = json_decode((string)$raw, true);
		if (!is_array($decoded)) {
			$this->note('api', $target, 'error', 'HTTP ' . $status . ', not JSON');
			return array('ok' => false, 'status' => $status, 'error' => 'The site\'s answer could not be read.');
		}
		if ($status < 200 || $status >= 300) {
			$error = is_string($decoded['error'] ?? null) ? $decoded['error'] : ('HTTP ' . $status);
			$this->note('api', $target, 'refused', 'HTTP ' . $status . ': ' . mb_substr($error, 0, 300));
			return array('ok' => false, 'status' => $status, 'error' => mb_substr($error, 0, 500));
		}
		$this->note('api', $target, 'ok', 'HTTP ' . $status);
		return array('ok' => true, 'status' => $status, 'decoded' => $decoded);
	}

	/**
	 * The request itself: to this site's own name, pinned to this machine,
	 * over HTTPS and, where nothing listens on 443 here (a site container,
	 * whose host terminates TLS), over plain HTTP to port 80, saying it is
	 * HTTPS as the host's proxy would (X-Forwarded-Proto, trusted from a
	 * loopback peer: LibraryFunctions::isSecure()); the API refuses plain HTTP.
	 *
	 * What is assumed: whatever listens on this machine's 443 or 80 is this
	 * site's own web server, so its certificate is not checked (it is for the
	 * name, not for 127.0.0.1). A webDir naming another port is refused, since
	 * the key would go to whatever holds 443.
	 *
	 * @return array{0:int, 1:string} status (0 when nothing answered) and body, or the transport's error
	 */
	private static function loopback(string $method, string $path, ?string $body, array $headers, float $seconds): array {
		$host = self::siteHost();
		if ($host === '') {
			return array(0, 'the site has no webDir');
		}
		$lines = array();
		foreach ($headers as $name => $value) {
			$lines[] = $name . ': ' . $value;
		}
		$error = '';
		$until = microtime(true) + $seconds;
		foreach (array('https' => 443, 'http' => 80) as $scheme => $port) {
			$received = '';
			$over = false;
			$seconds = $until - microtime(true);
			if ($seconds <= 0) {
				break;
			}
			$send = $lines;
			if ($scheme === 'http') {
				$send[] = 'X-Forwarded-Proto: https';
			}
			$ch = curl_init($scheme . '://' . $host . $path);
			curl_setopt_array($ch, array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_FOLLOWLOCATION => false,
				// This machine's own web server, named as the site so it routes
				// there; its certificate is for the name, not for this address.
				CURLOPT_RESOLVE        => array($host . ':' . $port . ':127.0.0.1'),
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_CONNECTTIMEOUT_MS => (int)min(3000, $seconds * 1000),
				CURLOPT_TIMEOUT_MS     => (int)max(1, $seconds * 1000),
				CURLOPT_HTTPHEADER     => $send,
				CURLOPT_USERAGENT      => 'Joinery-SandboxRelay/1.0',
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$received, &$over) {
					if (strlen($received) + strlen($chunk) > self::MAX_ANSWER_BYTES) {
						$over = true;
						return 0;
					}
					$received .= $chunk;
					return strlen($chunk);
				},
			));
			if ($body !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			}
			curl_exec($ch);
			$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$errno = curl_errno($ch);
			$error = curl_error($ch);
			if ($over) {
				return array(0, 'the answer was larger than 1 MB');
			}
			if ($status > 0) {
				return array($status, $received);
			}
			if ($errno !== CURLE_COULDNT_CONNECT) {
				break;
			}
		}
		return array(0, $error);
	}

	/** The site's own host name, from webDir. */
	private static function siteHost(): string {
		$web = preg_replace('~^https?://~i', '', (string)Globalvars::get_instance()->get_setting('webDir'));
		$host = explode('/', (string)$web)[0];
		// No port: the relay reaches 443 or 80 on this machine and nothing else.
		return preg_match('~^[A-Za-z0-9.-]{1,253}$~D', $host) ? $host : '';
	}

	// ---- outside requests ------------------------------------------------

	private function http(array $ask): array {
		$method = strtoupper(is_string($ask['method'] ?? null) ? $ask['method'] : 'GET');
		$url = is_string($ask['url'] ?? null) ? $ask['url'] : '';
		$parts = parse_url($url);
		$host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
		// The log names the host and never the rest: a webhook's address is
		// often its secret.
		$target = $method . ' ' . ($host !== '' ? 'https://' . $host . '/…' : '(not a URL)');
		if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || $host === ''
				|| isset($parts['user']) || isset($parts['pass']) || preg_match('~[\x00-\x20\x7F]~', $url)) {
			return $this->refuse('http', $target, 'Only https addresses are allowed.');
		}
		if (isset($parts['port']) && (int)$parts['port'] !== 443) {
			return $this->refuse('http', $target, 'Only port 443 is allowed.');
		}
		if (!in_array($host, SandboxConsent::effective($this->plugin)['outbound_hosts'], true)) {
			return $this->refuse('http', $target, 'The host ' . $host . ' is not one this plugin may reach.');
		}
		if (!in_array($method, self::HTTP_METHODS, true)) {
			return $this->refuse('http', $target, 'The method is not allowed.');
		}
		$headers = self::outboundHeaders($ask['headers'] ?? array());
		if (is_string($headers)) {
			return $this->refuse('http', $target, $headers);
		}
		$body = $ask['body'] ?? null;
		if ($body !== null && !is_string($body)) {
			return $this->refuse('http', $target, 'The body must be text.');
		}
		$seconds = $this->secondsLeft();
		if ($seconds < 1) {
			$this->note('http', $target, 'timeout', 'no time left');
			return array('ok' => false, 'error' => 'The call ran out of time.');
		}
		try {
			if (self::$outbound !== null) {
				$response = call_user_func(self::$outbound, $method, $url, $body, $headers, $seconds);
			} else {
				$client = new SafeHttpClient(array(
					'allowed_ports'      => array(443),
					'allow_redirects'    => false,
					'connect_timeout'    => (int)max(1, min(5, floor($seconds))),
					'timeout'            => (int)max(1, min(self::MAX_REQUEST_SECONDS, floor($seconds))),
					'max_response_bytes' => self::MAX_ANSWER_BYTES,
					'user_agent'         => 'Joinery-SandboxRelay/1.0 (' . $this->plugin . ')',
				));
				$response = $client->request($method, $url, $body, $headers);
			}
		} catch (UnsafeUrlException $e) {
			$this->note('http', $target, 'refused', 'not a public address');
			return array('ok' => false, 'error' => 'The host does not resolve to a public address.');
		} catch (Throwable $e) {
			$detail = str_replace($url, $target, $e->getMessage());
			$this->note('http', $target, 'error', mb_substr($detail, 0, 300));
			return array('ok' => false, 'error' => 'The request failed: ' . mb_substr($detail, 0, 200));
		}
		$this->note('http', $target, 'ok', 'HTTP ' . $response->status);
		$answer = array('ok' => true, 'status' => $response->status, 'headers' => $response->headers);
		if (mb_check_encoding($response->body, 'UTF-8')) {
			$answer['body'] = $response->body;
		} else {
			$answer['body_base64'] = base64_encode($response->body);
		}
		return $answer;
	}

	/**
	 * A plugin's headers for an outside request, or why they are refused:
	 * a name that is a token and steers nothing, a value with no CR, LF or
	 * NUL. SafeHttpClient joins names and values as they are.
	 *
	 * @return array<string,string>|string
	 */
	public static function outboundHeaders($headers) {
		if ($headers === null) {
			return array();
		}
		if (!is_array($headers) || ($headers !== array() && array_is_list($headers))) {
			return 'Headers must be an object of name => value.';
		}
		if (count($headers) > self::MAX_HEADERS) {
			return 'At most ' . self::MAX_HEADERS . ' headers.';
		}
		$out = array();
		foreach ($headers as $name => $value) {
			$name = (string)$name;
			if (!preg_match(self::HEADER_NAME_PATTERN, $name)) {
				return 'A header name must be a plain token.';
			}
			if (in_array(strtolower($name), self::FORBIDDEN_HEADERS, true)) {
				return 'The header ' . $name . ' may not be set.';
			}
			if (!is_scalar($value) || is_bool($value)) {
				return 'The value of ' . $name . ' must be text.';
			}
			$value = (string)$value;
			if (strlen($value) > 8192 || preg_match('~[\r\n\x00]~', $value)) {
				return 'The value of ' . $name . ' may not hold a line break or be longer than 8 KB.';
			}
			$out[$name] = $value;
		}
		return $out;
	}

	// ---- email -----------------------------------------------------------

	/** The longest template name the site stores (emt_name). */
	const MAX_TEMPLATE_NAME = 255;

	private function email(string $kind, array $ask): array {
		if (empty(SandboxConsent::effective($this->plugin)['email'])) {
			return $this->refuse('email', $kind, 'This plugin was not approved to send email.');
		}
		if ($this->secondsLeft() < 1) {
			$this->note('email', $kind, 'timeout', 'no time left');
			return array('ok' => false, 'error' => 'The call ran out of time.');
		}
		if ($kind === 'email.render') {
			return $this->emailRender($ask);
		}
		try {
			$message = EmailMessage::fromTransfer(is_array($ask['message'] ?? null) ? $ask['message'] : array());
		} catch (InvalidArgumentException $e) {
			return $this->refuse('email', $kind, 'The message is not one EmailMessage wrote: ' . $e->getMessage());
		}
		$subject = mb_substr((string)$message->getSubject(), 0, 100);
		$sender = new EmailSender();
		if (self::$mail_seen !== null) {
			call_user_func(self::$mail_seen, $sender, $message);
		}
		try {
			if ($kind === 'email.batch') {
				$recipients = $ask['recipients'] ?? null;
				if (!is_array($recipients) || !array_is_list($recipients)) {
					return $this->refuse('email', $kind, 'recipients must be a list of addresses.');
				}
				foreach ($recipients as $address) {
					if (!is_string($address)) {
						return $this->refuse('email', $kind, 'recipients must be a list of addresses.');
					}
				}
				$result = $sender->sendBatch($message, $recipients);
				$failed = array_values(array_map('strval', (array)($result['failed_recipients'] ?? array())));
				$this->note('email', 'batch', !empty($result['success']) ? 'ok' : 'error',
					self::addressWords($recipients) . ', ' . count($failed) . ' failed: ' . $subject);
				return array('ok' => true, 'success' => !empty($result['success']), 'failed_recipients' => $failed,
					'from' => $message->getFrom(), 'from_name' => $message->getFromName(), 'reply_to' => $message->getReplyTo());
			}
				$sent = $sender->send($message, !empty($ask['queue_on_failure']));
		} catch (Exception $e) {
			// What native code would catch: a protected identity domain, a
			// missing address, a template that cannot be filled.
			$this->note('email', $kind === 'email.batch' ? 'batch' : 'send', 'refused', mb_substr($e->getMessage(), 0, 300));
			return array('ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500));
		}
		$this->note('email', 'send', $sent ? 'ok' : 'error', self::addressWords(array_merge(
			array_column($message->getRecipients() ?: array(), 'email'), array_column($message->getCc() ?: array(), 'email'),
			array_column($message->getBcc() ?: array(), 'email'))) . ': ' . $subject);
		$report = $sender->lastSendReport();
		// What send() filled in on the message, as native code finds it after.
		return array('ok' => true, 'sent' => (bool)$sent, 'from' => $message->getFrom(), 'from_name' => $message->getFromName(),
			'reply_to' => $message->getReplyTo(), 'report' => array(
			'direct_delivered' => array_values(array_map('strval', (array)($report['direct_delivered'] ?? array()))),
			'transport' => isset($report['transport']) ? (string)$report['transport'] : null,
			'receipt' => is_array($report['receipt'] ?? null) ? $report['receipt'] : null,
			'error' => isset($report['error']) ? (string)$report['error'] : null,
		));
	}

	// ---- files ---------------------------------------------------------------

	/** The origin tag on every file this plugin keeps (its label in the file catalog). */
	private function fileTag(): string {
		return 'sbx_' . $this->plugin;
	}

	/**
	 * This install's id: the files it keeps carry it, and its own-files
	 * reach is by it, so a later plugin of the same name reaches none of
	 * them (spec plugin_install_identity A1). '' when the row has none.
	 */
	private function installId(): string {
		if ($this->install_id === null) {
			$this->install_id = (string)PluginInstallIdentity::idOf($this->plugin);
		}
		return $this->install_id;
	}

	private function uploadsApproved(): bool {
		return !empty(SandboxConsent::effective($this->plugin)['uploads']);
	}

	/**
	 * Which files a File verb reaches for this plugin: 'all' with a records
	 * grant on File for the verb (an approval line of its own, as for any
	 * site record), 'own' (the files with its tag) when it was approved for
	 * uploads, or null. File is not a model the API serves, so its asks are
	 * carried out here either way.
	 */
	private function fileReach(string $verb): ?string {
		if (in_array($verb, SandboxConsent::effective($this->plugin)['records']['File'] ?? array(), true)) {
			return 'all';
		}
		return $this->uploadsApproved() ? 'own' : null;
	}

	/** What the fence's runtime itself holds of a call's memory (measured about 4.3 MB, File loaded). */
	const RUNTIME_MEMORY_MB = 6;

	/**
	 * The largest file the plugin may read or make from bytes in one call:
	 * half of the call's memory past the runtime's own, since the bytes are
	 * held whole and briefly twice (measured: a 9 MB read peaks 13 MB above
	 * the runtime). About 5 MB at the default 16 MB, 29 MB at 64.
	 */
	private function fileBytesCap(): int {
		return intdiv(max(0, $this->memory_mb - self::RUNTIME_MEMORY_MB) * 1048576, 2);
	}

	/** Why a file is too large, and how the author raises the cap. */
	private function capWords(): string {
		return 'at most ' . DiskSpace::format($this->fileBytesCap()) . ' in one call: half of the ' . $this->memory_mb
			. ' MB the plugin\'s calls have, past the ' . self::RUNTIME_MEMORY_MB . ' MB its runtime holds (its manifest\'s limits.memory_mb sets that, up to '
			. SandboxManifest::memoryCeilingMb() . ' MB, what this site gives a page request)';
	}

	private function file(string $kind, array $ask): array {
		if ($kind === 'file.read' ? $this->fileReach('read') === null : !$this->uploadsApproved()) {
			return $this->refuse('file', $kind, $kind === 'file.read' ? 'This plugin was not approved to read files.'
				: 'This plugin was not approved to take uploaded files.');
		}
		if ($this->secondsLeft() < 1) {
			$this->note('file', $kind, 'timeout', 'no time left');
			return array('ok' => false, 'error' => 'The call ran out of time.');
		}
		if ($kind === 'file.read') {
			return $this->fileRead($ask);
		}

		// Bytes larger than one message come in parts, kept in a temporary file here until the last.
		$target = $kind === 'file.upload' ? 'upload' : 'bytes';
		if ($kind === 'file.bytes') {
			$part = is_string($ask['part'] ?? null) ? base64_decode($ask['part'], true) : false;
			if (!is_string($part)) {
				$this->dropPending();
				return $this->refuse('file', $target, 'The bytes must be base64.');
			}
			if ($this->pending === null) {
				$this->pending = (string)tempnam(sys_get_temp_dir(), 'sbx_bytes_');
				$this->pending_size = 0;
			}
			$this->pending_size += strlen($part);
			if ($this->pending_size > $this->fileBytesCap()) {
				$this->dropPending();
				return $this->refuse('file', $target, 'A file made from bytes may be ' . $this->capWords() . '.');
			}
			if (file_put_contents($this->pending, $part, FILE_APPEND) === false) {
				$this->dropPending();
				$this->note('file', $target, 'error', 'not written');
				return array('ok' => false, 'error' => 'The bytes could not be kept.');
			}
			if (!empty($ask['more'])) {
				return array('ok' => true, 'received' => $this->pending_size);
			}
		}

		// A new file: the viewer's, private, tagged, with only a title and a description of the plugin's.
		if ($this->viewer === null) {
			$this->dropPending();
			return $this->refuse('file', $target, 'A file is kept in the account of the person viewing the page, and this call has no one viewing it.');
		}
		if (self::positiveInt($ask['owner_id'] ?? null) !== $this->viewer) {
			$this->dropPending();
			return $this->refuse('file', $target, 'A sandboxed plugin keeps a file in the account of the person viewing the page (owner ' . $this->viewer . ').');
		}
		$columns = $this->fileColumns($ask['restrictions'] ?? array());
		if (is_string($columns)) {
			$this->dropPending();
			return $this->refuse('file', $target, $columns);
		}
		if ($this->installId() === '') {
			$this->dropPending();
			return $this->refuse('file', $target, 'This plugin has no install id yet, so a file it kept would be no one\'s; an admin runs update_database.');
		}
		$columns['fil_private'] = true;
		$columns['fil_source'] = $this->fileTag();
		$columns['fil_install_id'] = $this->installId();
		$name = self::oneLine($ask['name'] ?? '', 255);
		$type = self::oneLine($ask['type'] ?? '', 255);

		if ($kind === 'file.upload') {
			$path = $ask['tmp_name'] ?? null;
			if (!is_string($path) || !array_key_exists($path, $this->uploads)) {
				return $this->refuse('file', $target, 'That is not a file uploaded to this page.');
			}
			if ($this->uploads[$path] !== UPLOAD_ERR_OK) {
				return $this->refuse('file', $target, 'That upload did not arrive (PHP upload error ' . $this->uploads[$path] . ').');
			}
			$uploaded = self::$upload_check !== null ? (bool)call_user_func(self::$upload_check, $path) : is_uploaded_file($path);
			if (!$uploaded || !is_file($path)) {
				return $this->refuse('file', $target, 'That upload is no longer here (it was kept already, or the request has ended).');
			}
		} else {
			$path = (string)$this->pending;
			$this->pending = null;
		}
		$size = (int)filesize($path);
		try {
			$room = DiskAllowance::refusal($size);
			if ($room !== '') {
				return $this->refuse('file', $target, $room);
			}
			// As createFromBytes() makes it: from a temporary file, and not announced.
			$file = File::createFromUpload($path, $name !== '' ? $name : ($kind === 'file.upload' ? 'upload' : 'attachment'), $type,
				$this->viewer, $columns, false);
		} finally {
			if ($kind === 'file.bytes' && is_file($path)) {
				@unlink($path);
			}
		}
		if (!$file->key) {
			$this->note('file', $target, 'error', 'not saved');
			return array('ok' => false, 'error' => 'The file could not be saved.');
		}
		$this->note('file', $target . ' ' . $file->key, 'ok', mb_substr($name, 0, 80) . ', ' . DiskSpace::format($size));
		return array('ok' => true, 'record' => $file->export_for_api());
	}

	/**
	 * One part of a file's bytes, from offset: read whole at the first part
	 * (as read_bytes() reads it) and kept for the rest of the call, so each
	 * part fits in one message.
	 */
	private function fileRead(array $ask): array {
		$offset = $ask['offset'] ?? 0;
		if (!is_int($offset) || $offset < 0) {
			return $this->refuse('file', 'read', 'The offset is a whole number from 0.');
		}
		$id = self::positiveInt($ask['id'] ?? null);
		if ($offset === 0 || $this->reading === null || $this->reading['id'] !== $id) {
			$this->reading = null;
			$file = $this->reachableFile($ask['id'] ?? null, 'read');
			if (!$file instanceof File) {
				return $this->refuse('file', 'read', $file);
			}
			if ($file->get('fil_delete_time')) {
				return $this->refuse('file', 'read ' . $file->key, 'That file is in the trash.');
			}
			$size = $file->plain_size_bytes();
			if ($size > $this->fileBytesCap()) {
				return $this->refuse('file', 'read ' . $file->key, 'A sandboxed plugin reads a file of ' . $this->capWords()
					. '; this one is ' . DiskSpace::format($size) . '.');
			}
			$bytes = $file->read_bytes('original');
			if (!is_string($bytes)) {
				return $this->refuse('file', 'read ' . $file->key, 'That file\'s bytes could not be read.');
			}
			$this->reading = array('id' => (int)$file->key, 'bytes' => $bytes);
			$this->note('file', 'read ' . $file->key, 'ok', DiskSpace::format(strlen($bytes)));
		}
		$total = strlen($this->reading['bytes']);
		$part = (string)substr($this->reading['bytes'], $offset, self::FILE_PART_BYTES);
		$next = $offset + strlen($part);
		if ($next >= $total) {
			$this->reading = null;
		}
		return array('ok' => true, 'bytes' => base64_encode($part), 'size' => $total, 'next' => $next < $total ? $next : null);
	}

	/** Forget a part-sent file the plugin did not finish. */
	private function dropPending(): void {
		if ($this->pending !== null && is_file($this->pending)) {
			@unlink($this->pending);
		}
		$this->pending = null;
		$this->pending_size = 0;
	}

	public function __destruct() {
		$this->dropPending();
	}

	/** Whether $file is one this install kept: its tag and its install id. */
	private function ownFile(File $file): bool {
		return $file->get('fil_source') === $this->fileTag() && $this->installId() !== ''
			&& (string)$file->get('fil_install_id') === $this->installId();
	}

	/** A file this plugin's verb reaches, by id (fileReach()), or why not. @return File|string */
	private function reachableFile($id, string $verb) {
		$id = self::positiveInt($id);
		if ($id === null) {
			return 'The id must be a whole number above 0.';
		}
		$reach = $this->fileReach($verb);
		$file = new File($id, TRUE);
		if (!$file->key || $reach === null || ($reach === 'own' && !$this->ownFile($file))) {
			// A file out of its reach is not this plugin's to know of: the same answer as none.
			return 'There is no file ' . $id . ($reach === 'all' ? '' : ' among this plugin\'s files') . '.';
		}
		return $file;
	}

	/** The columns a plugin sets on a file: the title and description only. @return array|string */
	private function fileColumns($given) {
		if (!is_array($given) || ($given !== array() && array_is_list($given))) {
			return 'The columns must be an object of column => value.';
		}
		$out = array();
		foreach ($given as $key => $value) {
			$column = self::readableColumn('File', $key);
			if ($column === null || !in_array($column, self::FILE_COLUMNS, true)) {
				return 'A sandboxed plugin sets only a file\'s title and description (fil_title, fil_description): its files are private to their owner and admins, '
					. mb_substr((string)$key, 0, 60) . ' is not one it sets.';
			}
			if (!is_string($value) && $value !== null) {
				return 'The ' . $column . ' must be text.';
			}
			$out[$column] = $column === 'fil_title' ? self::oneLine((string)$value, 255) : ($value === null ? null : mb_substr($value, 0, 10000));
		}
		return $out;
	}

	/** A record ask on File (fileReach() is not null for its verb). */
	private function fileRecord(string $kind, array $ask): array {
		if ($kind === 'record.create') {
			return $this->refuse('file', 'create', 'A file is made with File::createFromUpload() or File::createFromBytes().');
		}
		if ($kind === 'record.list') {
			$query = self::listQuery('File', $ask);
			if (is_string($query)) {
				return $this->refuse('file', 'list', $query);
			}
			$where = array('TRUE');
			$params = array();
			if ($this->fileReach('read') !== 'all') {
				// Its own files only: a filter the plugin cannot change.
				$where[] = 'fil_source = ? AND fil_install_id = ?';
				$params[] = $this->fileTag();
				$params[] = $this->installId();
			}
			foreach ($query as $key => $value) {
				if (in_array($key, array('sort', 'sdirection', 'numperpage', 'page'), true)) {
					continue;
				}
				if ($key === 'deleted') {
					$where[] = $value === '1' ? 'fil_delete_time IS NOT NULL' : 'fil_delete_time IS NULL';
					continue;
				}
				// A readable declared column (listQuery()), so a plain name; the value is bound.
				$where[] = $key . ' = ?';
				$params[] = $value;
			}
			$order = isset($query['sort']) ? $query['sort'] . ' ' . $query['sdirection'] . ', fil_file_id' : 'fil_file_id';
			$limit = (int)$query['numperpage'];
			$offset = (int)$query['page'] * $limit;
			$db = DbConnector::get_instance()->get_db_link();
			$count = $db->prepare('SELECT COUNT(*) FROM fil_files WHERE ' . implode(' AND ', $where));
			$count->execute($params);
			$ids = $db->prepare('SELECT fil_file_id FROM fil_files WHERE ' . implode(' AND ', $where)
				. ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset);
			$ids->execute($params);
			$records = array();
			foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
				$records[] = (new File((int)$id, TRUE))->export_for_api();
			}
			$total = (int)$count->fetchColumn();
			$this->note('file', 'list', 'ok', count($records) . ' of ' . $total);
			return array('ok' => true, 'status' => 200, 'records' => $records, 'total' => $total);
		}
		$verb = array('record.get' => 'get', 'record.update' => 'update', 'record.delete' => 'delete')[$kind];
		$file = $this->reachableFile($ask['id'] ?? null, self::RECORD_VERBS[$kind]);
		if (!$file instanceof File) {
			$this->note('file', $verb, 'refused', $file);
			// The API's answer for a row that is not there (the fence reads it as no row).
			return array('ok' => false, 'status' => 404, 'error' => $file);
		}
		if ($kind !== 'record.get' && $file->get('fil_source') === File::SOURCE_DRIVE) {
			// Drive's own rename and trash check the name, keep an encrypted
			// file's name sealed, and tell every device (FileChange); they run
			// only from a signed-in browser. A plugin reads Drive files (owner
			// 2026-11-21, reviewer2 B1).
			return $this->refuse('file', $verb . ' ' . $file->key, 'A Drive file changes only through Drive, which keeps every device in step; a sandboxed plugin may read it.');
		}
		if ($kind === 'record.update') {
			$columns = $this->fileColumns($ask['fields'] ?? array());
			if (is_string($columns)) {
				return $this->refuse('file', 'update ' . $file->key, $columns);
			}
			foreach ($columns as $column => $value) {
				$file->set($column, $value);
			}
			// Within its reach, whoever is viewing (reachableFile()): its own
			// files are its data, as its own rows are, and an approved records
			// grant reaches every file, as its key reaches every row of a model.
			SystemBase::rule_checked_write(function () use ($file) { $file->save(); });
			$file->load();
		} elseif ($kind === 'record.delete') {
			SystemBase::rule_checked_write(function () use ($file) { $file->soft_delete(); });
			$file->load();
		}
		$this->note('file', $verb . ' ' . $file->key, 'ok', mb_substr((string)$file->get('fil_title'), 0, 80));
		return array('ok' => true, 'status' => 200, 'record' => $file->export_for_api());
	}

	/** Text as one line (no control characters) of at most $max characters. */
	private static function oneLine($value, int $max): string {
		return is_scalar($value) ? mb_substr(trim((string)preg_replace('~[\x00-\x1F\x7F]+~', ' ', (string)$value)), 0, $max) : '';
	}

	private function emailRender(array $ask): array {
		$inner = $ask['inner'] ?? null;
		$outer = $ask['outer'] ?? null;
		$footer = $ask['footer'] ?? null;
		$values = $ask['values'] ?? array();
		$name_ok = function ($v) {
			return is_string($v) && $v !== '' && strlen($v) <= self::MAX_TEMPLATE_NAME;
		};
		if (!$name_ok($inner) || ($outer !== null && !$name_ok($outer))) {
			return $this->refuse('email', 'email.render', 'A template is named by text.');
		}
		if ($footer !== null && !$name_ok($footer) && !(is_array($footer) && is_string($footer['body'] ?? null))) {
			return $this->refuse('email', 'email.render', 'A footer is a template name or {body: text}.');
		}
		if (!is_array($values)) {
			return $this->refuse('email', 'email.render', 'values must be an object.');
		}
		if (is_array($footer) && stripos($footer['body'], '{loop') !== false) {
			return $this->refuse('email', 'email.render', 'A footer given as text may not hold a loop; use a stored footer template.');
		}
		$values = self::datesBack($values);
		try {
			$renderer = new EmailTemplateRenderer($inner, $outer, is_array($footer) ? array('body' => $footer['body']) : $footer);
			$set_values = $renderer->fill_template($values);
		} catch (EmailTemplateError $e) {
			$this->note('email', 'render ' . mb_substr($inner, 0, 60), 'refused', mb_substr($e->getMessage(), 0, 300));
			return array('ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500), 'template_error' => true);
		}
		$this->note('email', 'render ' . mb_substr($inner, 0, 60), 'ok', '');
		return array('ok' => true, 'subject' => $renderer->getSubject(), 'html' => $renderer->getHtml(),
			'text' => $renderer->getText(), 'has_content' => (bool)$renderer->hasContent(),
			'set_values' => is_array($set_values) ? $set_values : null);
	}

	/** The tag a date crosses the pipe in (SandboxRemoteMail::render()). */
	const DATE_TAG = '__sbx_datetime';

	/** Template values with each tagged date a DateTime again, as the renderer formats it. */
	private static function datesBack(array $values): array {
		foreach ($values as $key => $value) {
			if (!is_array($value)) {
				continue;
			}
			if (isset($value[self::DATE_TAG]) && is_string($value[self::DATE_TAG]) && is_string($value['tz'] ?? null)) {
				try {
					// EmailTemplateRenderer formats only a DateTime.
					$date = DateTime::createFromFormat('Y-m-d\TH:i:s.u', $value[self::DATE_TAG], new DateTimeZone($value['tz']));
				} catch (Exception $e) {
					$date = false;
				}
				if ($date !== false) {
					$values[$key] = $date;
					continue;
				}
			}
			$values[$key] = self::datesBack($value);
		}
		return $values;
	}

	/** Who a send went to, for the call log: the first three addresses and how many more. */
	private static function addressWords(array $addresses): string {
		$addresses = array_values(array_map('strval', $addresses));
		$shown = implode(', ', array_map(function ($a) { return mb_substr($a, 0, 80); }, array_slice($addresses, 0, 3)));
		$more = count($addresses) - 3;
		return ($shown !== '' ? 'to ' . $shown : 'to no one') . ($more > 0 ? ' and ' . $more . ' more' : '');
	}

	// ---- the log ---------------------------------------------------------

	private function log(array $ask): array {
		$level = is_string($ask['level'] ?? null) && in_array($ask['level'], self::LOG_LEVELS, true) ? $ask['level'] : 'info';
		$message = is_scalar($ask['message'] ?? null) ? (string)$ask['message'] : '';
		$message = (string)preg_replace('~[\x00-\x1F\x7F]+~', ' ', $message);
		$this->note('log', $level, 'ok', mb_substr($message, 0, 500));
		return array('ok' => true);
	}

	// ---- helpers ---------------------------------------------------------

	/** @return string[] */
	private function scope(): array {
		if ($this->scope === null) {
			$this->scope = SandboxCredentials::scopeFor($this->plugin);
		}
		return $this->scope;
	}

	/** @return array<string,string> */
	private function ownModels(): array {
		if ($this->own === null) {
			$this->own = array();
			foreach (SandboxPlugins::manifest($this->plugin)->models() as $model) {
				$this->own[$model['name']] = SandboxManifest::classFor($this->plugin, $model['name']);
			}
		}
		return $this->own;
	}

	private function credential(): ?array {
		if ($this->credential === null) {
			$this->credential = SandboxInstall::credentialFor($this->plugin) ?? false;
		}
		return $this->credential === false ? null : $this->credential;
	}

	private function secondsLeft(): float {
		return min(self::MAX_REQUEST_SECONDS, $this->deadline - microtime(true));
	}

	private function refuse(string $door, string $target, string $why): array {
		$this->note($door, $target, 'refused', $why);
		return array('ok' => false, 'error' => $why);
	}

	private function note(string $door, string $target, string $status, string $detail): void {
		if (count($this->calls) < 200) {
			$this->calls[] = array('door' => $door, 'target' => $target, 'status' => $status, 'detail' => $detail);
		}
	}

	private static function positiveInt($value): ?int {
		if (is_int($value)) {
			return $value > 0 ? $value : null;
		}
		if (is_string($value) && preg_match('~^[1-9][0-9]{0,17}$~D', $value)) {
			return (int)$value;
		}
		return null;
	}

	/** One filter value as query text: a string, a number, or true/false as 1/0. */
	private static function scalarText($value): ?string {
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_int($value) || is_float($value)) {
			return is_finite((float)$value) ? (string)$value : null;
		}
		if (is_string($value) && strlen($value) <= 2000) {
			return $value;
		}
		return null;
	}
}
