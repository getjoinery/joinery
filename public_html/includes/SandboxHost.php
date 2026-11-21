<?php
/**
 * SandboxHost — how core asks a sandboxed plugin's code to run.
 *
 * Core never runs a sandboxed plugin's code itself. It builds one envelope
 * per call, carrying everything the call may use, and hands it to the runner
 * (SandboxHost::$runner), then records the answer:
 *
 *   {
 *     "call_id":  "c_…",
 *     "plugin":   "notifier",
 *     "export":   "on_ui" | "on_signal" | "on_schedule",
 *     "input":    { … what this call is about … },
 *     "limits":   {"memory_mb": 16, "timeout_ms": 5000},
 *     "settings": {"webhook_url": "…"},          secrets opened
 *     "outbound_hosts": ["hooks.slack.com"],      the approved ones
 *     "site":     {"site_name": "…", "webDir": "…", "default_timezone": "…", "default_email_template": "…"},
 *     "models":   [ … the manifest's model declarations … ]
 *   }
 *
 * The envelope carries no credential: it is written to the fenced process,
 * and the plugin's API key never travels that way. Core's relay
 * (SandboxRelay) loads the key by plugin name when it carries out an ask.
 *
 * The runner answers
 *
 *   {"ok": true|false, "output": <what the plugin returned, JSON>,
 *    "error": "…", "timed_out": false, "duration_ms": 12,
 *    "calls": [{"door": "api"|"http"|"log", "target": "…", "status": "ok"|"refused"|"error", "detail": "…"}]}
 *
 * `input` per export: on_ui {handler, values, user: {id, permission, timezone}};
 * on_ui also carries source ('form', or 'frame' with kind and page);
 * on_page {page, kind: admin|member, method, query, fields, user};
 * on_signal {signal, message_id, timestamp, data}; on_schedule {schedule, time}.
 *
 * The runner is SandboxRunner: one fenced process per call
 * (specs/sandboxed_php_plugins.md). Where it cannot run the plugin (no fence,
 * no plugin user yet, a shared host) it answers {"unavailable": "why"} and
 * the call is logged as unavailable, saying why.
 *
 * Each call is recorded in SandboxLog. UI calls (door 4, Q14) are the one
 * place a page waits on a plugin: at most UI_SLOTS of them run at once per
 * site, one per person, each given at most UI_TIMEOUT_MS; past any of those,
 * the caller is told the plugin did not respond. Signal and schedule calls
 * share BACKGROUND_SLOTS of their own: each ask of a call holds a second web
 * worker, and a plugin that loops asks must not hold the worker pool.
 *
 * @version 1.8 - the site's public facts include default_email_template, which EmailSender::quickSend() names
 * @version 1.7 - a UI call says where it came from (source, kind, page)
 * @version 1.6 - page(): a page drawn by the plugin's own view (on_page), with the UI call's bounds
 * @version 1.5 - the envelope carries the plugin's model declarations (models)
 * @version 1.4 - the envelope carries the site's public facts (site) and the viewer's time zone
 * @version 1.3 - the envelope carries no credential; the runner is SandboxRunner unless a test sets one;
 *                an {unavailable} answer is logged as unavailable; signal and schedule calls take a background slot
 * @version 1.2 - calls go to SandboxHost::$runner; with none set a call is unavailable
 * @version 1.1 - 5 s UI ceiling, one UI call per person, answers capped at 1 MB, logged calls' fields capped
 * @version 1.0
 */

class SandboxHost {

	/** UI calls a site runs at once (Q14). */
	const UI_SLOTS = 2;

	/** The longest a page waits on a plugin, whatever its manifest asks (Q14). */
	const UI_TIMEOUT_MS = 5000;

	/** Signal and schedule calls a site runs at once. */
	const BACKGROUND_SLOTS = 2;

	/** What a call is answered with when its runner gives no reason. */
	const NO_RUNNER_MESSAGE = 'Nothing on this server can run the plugin.';

	/**
	 * What runs a call: a callable taking the envelope (see the top of this
	 * file) and returning the answer array. Null means the platform's own,
	 * SandboxRunner::run(); tests set a stand-in.
	 *
	 * @var callable|null
	 */
	public static $runner = null;

	/**
	 * Ask the plugin a UI handler's question (a form on its page was
	 * submitted). Waits, bounded.
	 *
	 * @return array the result: status (SandboxLog::STATUS_*), output, message, calls, duration_ms
	 */
	public static function ui(string $plugin, string $handler, array $values, ?User $user, array $from = array()): array {
		$user_id = $user ? (int)$user->key : null;
		$slot = self::takeUiSlot($user_id);
		if (!is_array($slot)) {
			$refusals = array(
				'busy'      => array(SandboxLog::STATUS_BUSY, 'The plugin is busy with other requests. Try again in a moment.'),
				'user_busy' => array(SandboxLog::STATUS_BUSY, 'The plugin is still answering your last request. Try again in a moment.'),
				'error'     => array(SandboxLog::STATUS_UNAVAILABLE, 'The plugin cannot be called on this site right now.'),
			);
			list($status, $message) = $refusals[$slot];
			return self::record($plugin, SandboxLog::KIND_UI, $handler, array('status' => $status, 'message' => $message), $user_id);
		}
		try {
			return self::call($plugin, SandboxLog::KIND_UI, $handler, 'on_ui', array(
				'handler' => $handler,
				// Where the call came from: a page's form, or a framed page's script.
				'source'  => (string)($from['source'] ?? 'form'),
				'kind'    => isset($from['kind']) ? (string)$from['kind'] : null,
				'page'    => isset($from['page']) ? (string)$from['page'] : null,
				'values'  => $values,
				'user'    => $user ? array('id' => (int)$user->key, 'permission' => (int)$user->get('usr_permission'),
					'timezone' => (string)($user->get('usr_timezone') ?: '')) : null,
			), array('max_timeout_ms' => self::UI_TIMEOUT_MS, 'user_id' => $user ? (int)$user->key : null));
		} finally {
			foreach ($slot as $fh) {
				flock($fh, LOCK_UN);
				fclose($fh);
			}
		}
	}

	/**
	 * Ask the plugin's own view to draw one of its pages (a page with no
	 * sections in its manifest; design 6). The same bounds as a UI call: one
	 * per person, UI_SLOTS a site, UI_TIMEOUT_MS. The output is
	 * {body, title, redirect}; SandboxPages cleans the body.
	 *
	 * @param string $kind 'admin' or 'member'
	 */
	public static function page(string $plugin, string $kind, string $slug, string $method, array $query, array $fields, ?User $user): array {
		$user_id = $user ? (int)$user->key : null;
		$slot = self::takeUiSlot($user_id);
		if (!is_array($slot)) {
			$status = $slot === 'error' ? SandboxLog::STATUS_UNAVAILABLE : SandboxLog::STATUS_BUSY;
			return self::record($plugin, SandboxLog::KIND_PAGE, $slug, array('status' => $status,
				'message' => $slot === 'error' ? 'The plugin cannot be called on this site right now.' : 'The plugin is busy. Try again in a moment.'), $user_id);
		}
		try {
			return self::call($plugin, SandboxLog::KIND_PAGE, $slug, 'on_page', array(
				'page'   => $slug,
				'kind'   => $kind === 'admin' ? 'admin' : 'member',
				'method' => $method === 'POST' ? 'POST' : 'GET',
				'query'  => $query ?: new stdClass(),
				'fields' => $fields ?: new stdClass(),
				'user'   => $user ? array('id' => (int)$user->key, 'permission' => (int)$user->get('usr_permission'),
					'timezone' => (string)($user->get('usr_timezone') ?: '')) : null,
			), array('max_timeout_ms' => self::UI_TIMEOUT_MS, 'user_id' => $user_id));
		} finally {
			foreach ($slot as $fh) {
				flock($fh, LOCK_UN);
				fclose($fh);
			}
		}
	}

	/** Deliver one signal to the plugin. */
	public static function signal(string $plugin, string $signal, array $payload, string $message_id, string $timestamp): array {
		return self::background($plugin, SandboxLog::KIND_SIGNAL, $signal, 'on_signal', array(
			'signal'     => $signal,
			'message_id' => $message_id,
			'timestamp'  => $timestamp,
			'data'       => $payload ?: new stdClass(),
		));
	}

	/** Run one of the plugin's declared schedules. */
	public static function schedule(string $plugin, string $schedule): array {
		return self::background($plugin, SandboxLog::KIND_SCHEDULE, $schedule, 'on_schedule', array(
			'schedule' => $schedule,
			'time'     => gmdate('Y-m-d\TH:i:s\Z'),
		));
	}

	/** A signal or schedule call, in one of the BACKGROUND_SLOTS; busy when every one is taken. */
	private static function background(string $plugin, string $kind, string $name, string $export, array $input): array {
		$slot = self::takeSlot('bg_slot_', self::BACKGROUND_SLOTS);
		if (!is_array($slot)) {
			$status = $slot === 'busy' ? SandboxLog::STATUS_BUSY : SandboxLog::STATUS_UNAVAILABLE;
			$message = $slot === 'busy' ? 'Other calls to sandboxed plugins are running. It will be tried again.'
				: 'The plugin cannot be called on this site right now.';
			return self::record($plugin, $kind, $name, array('status' => $status, 'message' => $message), null);
		}
		try {
			return self::call($plugin, $kind, $name, $export, $input);
		} finally {
			foreach ($slot as $fh) {
				flock($fh, LOCK_UN);
				fclose($fh);
			}
		}
	}

	/**
	 * Make one call and record it.
	 *
	 * @param array $opts max_timeout_ms (a ceiling under the manifest's), user_id (who asked)
	 */
	public static function call(string $plugin, string $kind, string $name, string $export, array $input, array $opts = array()): array {
		try {
			$envelope = self::envelope($plugin, $export, $input);
		} catch (Throwable $e) {
			return self::record($plugin, $kind, $name, array(
				'status' => SandboxLog::STATUS_ERROR,
				'message' => 'The plugin cannot be called: ' . $e->getMessage(),
			), $opts['user_id'] ?? null);
		}
		if (isset($opts['max_timeout_ms'])) {
			$envelope['limits']['timeout_ms'] = min($envelope['limits']['timeout_ms'], (int)$opts['max_timeout_ms']);
		}
		$result = self::run($envelope);
		return self::record($plugin, $kind, $name, $result, $opts['user_id'] ?? null);
	}

	/**
	 * Everything the runner needs for one call.
	 *
	 * @throws SandboxManifestException when the plugin is not an active, valid sandboxed plugin
	 */
	public static function envelope(string $plugin, string $export, array $input): array {
		if (!SandboxPlugins::isActive($plugin)) {
			throw new SandboxManifestException("'$plugin' is not an active sandboxed plugin.");
		}
		$manifest = SandboxPlugins::manifest($plugin);
		return array(
			'call_id'        => 'c_' . bin2hex(random_bytes(8)),
			'plugin'         => $plugin,
			'export'         => $export,
			'input'          => $input,
			'limits'         => $manifest->limits(),
			'settings'       => SandboxSettings::values($plugin) ?: new stdClass(),
			// What it may reach is what it asks for AND was approved.
			'outbound_hosts' => SandboxConsent::effective($plugin)['outbound_hosts'],
			// Public facts of the site, the only settings of the site's it sees.
			'site'           => self::siteFacts(),
			// Its own models' declarations, as core read them from its manifest.
			'models'         => $manifest->models(),
		);
	}

	/** The site's public facts for the envelope: site_name, webDir, default_timezone, default_email_template. */
	private static function siteFacts(): array {
		$settings = Globalvars::get_instance();
		return array(
			'site_name'        => (string)$settings->get_setting('site_name'),
			'webDir'           => (string)$settings->get_setting('webDir'),
			'default_timezone' => (string)($settings->get_setting('default_timezone') ?: 'UTC'),
			// The outer template's name, which EmailSender::quickSend() asks for.
			'default_email_template' => (string)$settings->get_setting('default_email_template'),
		);
	}

	/**
	 * Hand one envelope to the runner and read its answer.
	 *
	 * @return array status, output, message, calls, duration_ms
	 */
	private static function run(array $envelope): array {
		$runner = self::$runner ?? array('SandboxRunner', 'run');
		$started = microtime(true);
		try {
			$answer = call_user_func($runner, $envelope);
		} catch (Throwable $e) {
			// The class and where, never the message: it may quote what the call carried.
			error_log('[SandboxHost] the runner failed on a call to ' . $envelope['plugin'] . ': ' . get_class($e)
				. ' at ' . basename($e->getFile()) . ':' . $e->getLine());
			$answer = null;
		}
		$elapsed = (int)round((microtime(true) - $started) * 1000);
		if (is_array($answer) && array_key_exists('unavailable', $answer)) {
			return array('status' => SandboxLog::STATUS_UNAVAILABLE, 'duration_ms' => $elapsed,
				'message' => is_string($answer['unavailable']) && $answer['unavailable'] !== '' ? $answer['unavailable'] : self::NO_RUNNER_MESSAGE);
		}
		if (!is_array($answer)) {
			return array('status' => SandboxLog::STATUS_ERROR, 'duration_ms' => $elapsed,
				'message' => 'The plugin could not be run.');
		}
		$status = !empty($answer['timed_out']) ? SandboxLog::STATUS_TIMEOUT
			: (!empty($answer['ok']) ? SandboxLog::STATUS_OK : SandboxLog::STATUS_ERROR);
		$failed = array(
			SandboxLog::STATUS_TIMEOUT => 'The plugin did not respond in time.',
			SandboxLog::STATUS_ERROR   => 'The plugin failed.',
		);
		return array(
			'status'      => $status,
			'output'      => $answer['output'] ?? null,
			'message'     => $status === SandboxLog::STATUS_OK ? '' : (string)($answer['error'] ?? $failed[$status]),
			'calls'       => is_array($answer['calls'] ?? null) ? $answer['calls'] : array(),
			'duration_ms' => isset($answer['duration_ms']) ? (int)$answer['duration_ms'] : $elapsed,
		);
	}

	/** Write the call's log row and return the result with every key present. */
	private static function record(string $plugin, string $kind, string $name, array $result, ?int $user_id): array {
		$result += array('output' => null, 'message' => '', 'calls' => array(), 'duration_ms' => null);
		try {
			$log = new SandboxLog(NULL);
			$log->set('sbl_plugin', substr($plugin, 0, 20));
			$log->set('sbl_kind', $kind);
			$log->set('sbl_name', mb_substr($name, 0, 100));
			$log->set('sbl_status', $result['status']);
			$log->set('sbl_duration_ms', $result['duration_ms']);
			$log->set('sbl_message', mb_substr((string)$result['message'], 0, 500));
			$calls = array();
			foreach (array_slice($result['calls'], 0, 200) as $call) {
				if (!is_array($call)) {
					continue;
				}
				$calls[] = array(
					'door'   => mb_substr((string)($call['door'] ?? ''), 0, 20),
					'target' => mb_substr((string)($call['target'] ?? ''), 0, 300),
					'status' => mb_substr((string)($call['status'] ?? ''), 0, 20),
					'detail' => mb_substr((string)($call['detail'] ?? ''), 0, 500),
				);
			}
			$log->set('sbl_calls', $calls);
			$log->set('sbl_usr_user_id', $user_id);
			// The call's own record, which a page view (a page the plugin draws)
			// makes too: the platform's bookkeeping, not the viewer's change.
			SystemBase::server_initiated_write(function () use ($log) {
				$log->save();
			});
		} catch (Throwable $e) {
			error_log('[SandboxHost] could not record a call to ' . $plugin . ': ' . $e->getMessage());
		}
		return $result;
	}

	/**
	 * The locks a UI call holds: the asking person's own (one call each) and
	 * one of the UI_SLOTS site-wide. 'user_busy' when that person has a call
	 * running, 'busy' when every slot is taken, 'error' when the locks cannot
	 * be made (logged).
	 *
	 * @return resource[]|string
	 */
	private static function takeUiSlot(?int $user_id) {
		$dir = self::lockDir();
		if ($dir === null) {
			return 'error';
		}
		$mine = null;
		if ($user_id !== null) {
			$mine = @fopen($dir . '/ui_user_' . $user_id . '.lock', 'c');
			if ($mine === false) {
				error_log('[SandboxHost] cannot open a call lock in ' . $dir);
				return 'error';
			}
			if (!flock($mine, LOCK_EX | LOCK_NB)) {
				fclose($mine);
				return 'user_busy';
			}
		}
		$site = self::takeSlot('ui_slot_', self::UI_SLOTS);
		if (is_array($site)) {
			return $mine ? array_merge(array($mine), $site) : $site;
		}
		if ($mine) {
			flock($mine, LOCK_UN);
			fclose($mine);
		}
		return $site;
	}

	/**
	 * One of $slots site-wide locks named $prefix{n}: the held lock, 'busy'
	 * when every one is taken, 'error' when they cannot be made (logged).
	 *
	 * @return resource[]|string
	 */
	private static function takeSlot(string $prefix, int $slots) {
		$dir = self::lockDir();
		if ($dir === null) {
			return 'error';
		}
		for ($i = 0; $i < $slots; $i++) {
			$fh = @fopen($dir . '/' . $prefix . $i . '.lock', 'c');
			if ($fh === false) {
				error_log('[SandboxHost] cannot open a call lock in ' . $dir);
				return 'error';
			}
			if (flock($fh, LOCK_EX | LOCK_NB)) {
				return array($fh);
			}
			fclose($fh);
		}
		return 'busy';
	}

	/** Where the call locks live, made if missing; null when it cannot be (logged). */
	private static function lockDir(): ?string {
		$dir = rtrim(PathHelper::getSiteRoot(), '/') . '/cache/sandbox';
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			error_log('[SandboxHost] cannot make ' . $dir . ' for the call locks');
			return null;
		}
		return $dir;
	}
}
