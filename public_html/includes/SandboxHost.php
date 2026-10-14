<?php
/**
 * SandboxHost — how core asks the plugin host to run a sandboxed plugin.
 *
 * The host is a separate process (plugin_tiers.md WP7) that runs
 * WebAssembly modules and listens on a local socket. Core never runs a module
 * itself: it sends the host one request per call and waits for the answer,
 * bounded. Every request carries everything the call may use, so the host
 * keeps no configuration and reads no database:
 *
 *   POST /call
 *   {
 *     "call_id":  "c_…",
 *     "plugin":   "notifier",
 *     "module":   "/…/plugins/notifier/notifier.wasm",
 *     "export":   "on_ui" | "on_signal" | "on_schedule",
 *     "input":    { … what this call is about … },
 *     "limits":   {"memory_mb": 16, "timeout_ms": 5000},
 *     "settings": {"webhook_url": "…"},          secrets opened
 *     "outbound_hosts": ["hooks.slack.com"],      the approved ones
 *     "api":      {"base_url": "https://127.0.0.1:443", "hostname": "site.example",
 *                  "public_key": "…", "secret_key": "…"}   or null with no credential
 *   }
 *
 * and answers
 *
 *   {"ok": true|false, "output": <what the module returned, JSON>,
 *    "error": "…", "timed_out": false, "duration_ms": 12,
 *    "calls": [{"door": "api"|"http"|"log", "target": "…", "status": "ok"|"refused"|"error", "detail": "…"}]}
 *
 * `input` per export: on_ui {handler, values, user: {id, permission}};
 * on_signal {signal, message_id, timestamp, data}; on_schedule {schedule, time}.
 *
 * An answer larger than MAX_ANSWER_BYTES is refused unread past that point.
 *
 * Each call is recorded in SandboxLog. UI calls (door 4, Q14) are the one
 * place a page waits on a module: at most UI_SLOTS of them run at once per
 * site, one per person, each for at most UI_TIMEOUT_MS; past any of those,
 * the caller is told the plugin did not respond.
 *
 * @version 1.1 - 5 s UI ceiling, one UI call per person, answers capped at 1 MB, logged calls' fields capped
 * @version 1.0
 */

class SandboxHost {

	/** UI calls a site runs at once (Q14). */
	const UI_SLOTS = 2;

	/** The longest a page waits on a module, whatever its manifest asks (Q14). */
	const UI_TIMEOUT_MS = 5000;

	/** The most of a host's answer core reads; past it the call fails. */
	const MAX_ANSWER_BYTES = 1048576;

	/** Time allowed beyond a call's own limit for the host to start and answer. */
	const TRANSPORT_MARGIN_MS = 2000;

	/** A test may point core at another socket. */
	public static $socket_override = null;

	/** The host's socket: {site root}/run/sandbox-host/host.sock. */
	public static function socketPath(): string {
		if (self::$socket_override !== null) {
			return self::$socket_override;
		}
		return rtrim(PathHelper::getSiteRoot(), '/') . '/run/sandbox-host/host.sock';
	}

	/** Whether a host is listening on this site. */
	public static function isRunning(): bool {
		$path = self::socketPath();
		return file_exists($path) && filetype($path) === 'socket';
	}

	/**
	 * Ask the plugin a UI handler's question (a form on its page was
	 * submitted). Waits, bounded.
	 *
	 * @return array the result: status (SandboxLog::STATUS_*), output, message, calls, duration_ms
	 */
	public static function ui(string $plugin, string $handler, array $values, ?User $user): array {
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
				'values'  => $values,
				'user'    => $user ? array('id' => (int)$user->key, 'permission' => (int)$user->get('usr_permission')) : null,
			), array('max_timeout_ms' => self::UI_TIMEOUT_MS, 'user_id' => $user ? (int)$user->key : null));
		} finally {
			foreach ($slot as $fh) {
				flock($fh, LOCK_UN);
				fclose($fh);
			}
		}
	}

	/** Deliver one signal to the plugin. */
	public static function signal(string $plugin, string $signal, array $payload, string $message_id, string $timestamp): array {
		return self::call($plugin, SandboxLog::KIND_SIGNAL, $signal, 'on_signal', array(
			'signal'     => $signal,
			'message_id' => $message_id,
			'timestamp'  => $timestamp,
			'data'       => $payload ?: new stdClass(),
		));
	}

	/** Run one of the plugin's declared schedules. */
	public static function schedule(string $plugin, string $schedule): array {
		return self::call($plugin, SandboxLog::KIND_SCHEDULE, $schedule, 'on_schedule', array(
			'schedule' => $schedule,
			'time'     => gmdate('Y-m-d\TH:i:s\Z'),
		));
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
		$result = self::transport($envelope);
		return self::record($plugin, $kind, $name, $result, $opts['user_id'] ?? null);
	}

	/**
	 * Everything the host needs for one call.
	 *
	 * @throws SandboxManifestException when the plugin is not an active, valid sandboxed plugin
	 */
	public static function envelope(string $plugin, string $export, array $input): array {
		if (!SandboxPlugins::isActive($plugin)) {
			throw new SandboxManifestException("'$plugin' is not an active sandboxed plugin.");
		}
		$manifest = SandboxPlugins::manifest($plugin);
		$credential = SandboxInstall::credentialFor($plugin);
		$api = null;
		if ($credential !== null) {
			$host = explode('/', (string)Globalvars::get_instance()->get_setting('webDir'))[0];
			$api = array(
				'base_url'   => 'https://127.0.0.1:443',
				'hostname'   => $host,
				'public_key' => $credential['public_key'],
				'secret_key' => $credential['secret_key'],
			);
		}
		return array(
			'call_id'        => 'c_' . bin2hex(random_bytes(8)),
			'plugin'         => $plugin,
			'module'         => SandboxPlugins::wasmPath($plugin),
			'export'         => $export,
			'input'          => $input,
			'limits'         => $manifest->limits(),
			'settings'       => SandboxSettings::values($plugin) ?: new stdClass(),
			// What it may reach is what it asks for AND was approved.
			'outbound_hosts' => SandboxConsent::effective($plugin)['outbound_hosts'],
			'api'            => $api,
		);
	}

	/**
	 * Send one envelope to the host and read its answer.
	 *
	 * @return array status, output, message, calls, duration_ms
	 */
	private static function transport(array $envelope): array {
		$started = microtime(true);
		if (!self::isRunning()) {
			return array(
				'status' => SandboxLog::STATUS_UNAVAILABLE,
				'message' => 'The plugin host is not running on this site, so the plugin did not respond.',
			);
		}
		$body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$raw = '';
		$too_large = false;
		$ch = curl_init('http://sandbox-host/call');
		curl_setopt_array($ch, array(
			CURLOPT_UNIX_SOCKET_PATH => self::socketPath(),
			CURLOPT_POST             => true,
			CURLOPT_POSTFIELDS       => $body,
			CURLOPT_HTTPHEADER       => array('Content-Type: application/json'),
			// Read at most MAX_ANSWER_BYTES; a larger answer aborts the call.
			CURLOPT_WRITEFUNCTION    => function ($ch, $chunk) use (&$raw, &$too_large) {
				if (strlen($raw) + strlen($chunk) > self::MAX_ANSWER_BYTES) {
					$too_large = true;
					return 0;
				}
				$raw .= $chunk;
				return strlen($chunk);
			},
			CURLOPT_CONNECTTIMEOUT_MS => 1000,
			CURLOPT_TIMEOUT_MS       => (int)$envelope['limits']['timeout_ms'] + self::TRANSPORT_MARGIN_MS,
		));
		$ok = curl_exec($ch);
		$errno = curl_errno($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		$elapsed = (int)round((microtime(true) - $started) * 1000);

		if ($too_large) {
			return array('status' => SandboxLog::STATUS_ERROR, 'duration_ms' => $elapsed,
				'message' => 'The plugin\'s answer was larger than ' . (self::MAX_ANSWER_BYTES >> 20) . ' MB, so it was not read.');
		}
		if ($errno === CURLE_OPERATION_TIMEDOUT) {
			return array('status' => SandboxLog::STATUS_TIMEOUT, 'duration_ms' => $elapsed,
				'message' => 'The plugin did not respond in time.');
		}
		if ($ok === false || $code !== 200) {
			return array('status' => SandboxLog::STATUS_UNAVAILABLE, 'duration_ms' => $elapsed,
				'message' => 'The plugin host did not answer (' . ($errno ? 'connection error ' . $errno : 'HTTP ' . $code) . ').');
		}
		$answer = json_decode((string)$raw, true);
		if (!is_array($answer)) {
			return array('status' => SandboxLog::STATUS_ERROR, 'duration_ms' => $elapsed,
				'message' => 'The plugin host answered with something that is not JSON.');
		}
		$status = !empty($answer['timed_out']) ? SandboxLog::STATUS_TIMEOUT
			: (!empty($answer['ok']) ? SandboxLog::STATUS_OK : SandboxLog::STATUS_ERROR);
		return array(
			'status'      => $status,
			'output'      => $answer['output'] ?? null,
			'message'     => $status === SandboxLog::STATUS_OK ? '' : (string)($answer['error'] ?? 'The plugin failed.'),
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
			$log->save();
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
		$dir = rtrim(PathHelper::getSiteRoot(), '/') . '/cache/sandbox';
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			error_log('[SandboxHost] cannot make ' . $dir . ' for the UI call locks');
			return 'error';
		}
		$held = array();
		if ($user_id !== null) {
			$mine = @fopen($dir . '/ui_user_' . $user_id . '.lock', 'c');
			if ($mine === false) {
				error_log('[SandboxHost] cannot open a UI call lock in ' . $dir);
				return 'error';
			}
			if (!flock($mine, LOCK_EX | LOCK_NB)) {
				fclose($mine);
				return 'user_busy';
			}
			$held[] = $mine;
		}
		$release = function () use (&$held) {
			foreach ($held as $fh) {
				flock($fh, LOCK_UN);
				fclose($fh);
			}
		};
		for ($i = 0; $i < self::UI_SLOTS; $i++) {
			$fh = @fopen($dir . '/ui_slot_' . $i . '.lock', 'c');
			if ($fh === false) {
				error_log('[SandboxHost] cannot open a UI call lock in ' . $dir);
				$release();
				return 'error';
			}
			if (flock($fh, LOCK_EX | LOCK_NB)) {
				$held[] = $fh;
				return $held;
			}
			fclose($fh);
		}
		$release();
		return 'busy';
	}
}
