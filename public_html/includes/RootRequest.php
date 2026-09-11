<?php
/**
 * RootRequest — how the web side asks for something only root can do.
 *
 * The code tree belongs to root and the PHP pool cannot write it
 * (specs/implemented/read_only_tree.md). That closes the door an attacker walked through —
 * one bug that made the web server write a file used to leave PHP in the tree,
 * and the tree is what the next request runs — but it also takes away the
 * upgrade, plugin and theme installs, and the other operator actions that used
 * to write the tree from inside a web request.
 *
 * Those become requests. The web side writes a small JSON file naming a KIND
 * and its arguments; the root actor (the host converger, running
 * _plugin_installers_start.sh as root on a timer) reads it, runs the command
 * that kind maps to from the root-owned tree, and records the transcript.
 *
 * What crosses is a name, never a command. `kind` is checked against a closed
 * set here and mapped to a command by the runner; `args` are stored verbatim
 * and validated by the CLI that carries the kind out, which is the only thing
 * that knows what a valid argument for it looks like. Nothing in a request file
 * is ever passed to a shell by this class.
 *
 * A request is a file rather than a database row on purpose: the root actor
 * runs with no settings and no database, and adding either to the one process
 * that repairs a broken box would mean a box too broken to reach its database
 * is a box that cannot be repaired.
 *
 * @version 1.0
 */
class RootRequest {

	/**
	 * Everything root will do on the web side's behalf. A kind not on this
	 * list is refused here, before anything is written.
	 *
	 * Each maps to one command in _plugin_installers_start.sh. Adding a kind
	 * means adding it in both places, and the mechanical test holds the two
	 * lists to each other.
	 */
	const KINDS = array(
		'upgrade',                 // php utils/upgrade.php --verbose
		'install_plugin',          // fetch from the upgrade source + DB install
		'reconcile_composer',
		'write_agent_files',
		'save_doc',
		'set_receives_upgrades',
	);

	/**
	 * WHY THERE IS NO KIND FOR INSTALLING AN UPLOADED PACKAGE.
	 *
	 * Both this queue and uploads/staging are www-data-writable — they have to
	 * be, the web side writes them — so a request file proves only that
	 * something running as the web user wrote it, never that an operator asked.
	 * A kind that installed a staged directory would therefore turn the spec's
	 * own premise (one bug that lets an attacker write one file) into root code
	 * execution: stage a plugin.json and a migrations/migrations.php, queue the
	 * request, and root moves it into plugins/ and includes the migration.
	 *
	 * The kinds that remain cannot be abused that way. `upgrade` and
	 * `install_plugin` fetch from the configured upgrade source, so the bytes
	 * come from somewhere the attacker does not control; the rest write a .md,
	 * a docs file, or one boolean in a theme manifest.
	 *
	 * Installing an uploaded package is a shell command:
	 *
	 *     php utils/install_extension.php plugin --staged=<dir>
	 *
	 * The admin page still unpacks and checks the upload, and then shows that
	 * command. A shell is a thing an attacker who can write one file does not
	 * have, and that is the whole difference.
	 */
	const NO_PACKAGE_KIND = true;

	/** Where requests, their transcripts and their outcomes live. */
	const QUEUE_DIR = 'cache/root_requests';
	const LOG_DIR   = 'logs/root_requests';

	/** A request still queued after this long is reported, never dropped. */
	const STALE_AFTER = 86400;

	/**
	 * The exit code the runner records against a request no run ever finished —
	 * one that was in flight when its runner was killed. EX_TEMPFAIL, chosen
	 * because no handler produces it and because it is a NUMBER: the word that
	 * used to be written here cast to 0, and the page said "Failed, exit 0".
	 */
	const ABANDONED_EXIT = 75;

	// ---- submitting --------------------------------------------------------

	/**
	 * Queue a request. Returns its id.
	 *
	 * The id carries the submission time so the runner can take the oldest
	 * first by name alone, without stat'ing every file, and enough randomness
	 * that two submissions in the same second cannot collide.
	 *
	 * @throws InvalidArgumentException on a kind that is not in KINDS
	 */
	public static function submit(string $kind, array $args = array(), ?int $user_id = null): string {
		if (!in_array($kind, self::KINDS, true)) {
			throw new InvalidArgumentException(
				'RootRequest: unknown kind "' . $kind . '". Known kinds: ' . implode(', ', self::KINDS));
		}

		$dir = self::queue_dir();
		if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
			throw new RuntimeException('RootRequest: could not create the queue directory at ' . $dir);
		}

		$id = time() . '-' . bin2hex(random_bytes(4));
		$body = json_encode(array(
			'kind'         => $kind,
			'args'         => $args,
			'requested_by' => $user_id,
			'requested_at' => time(),
		), JSON_UNESCAPED_SLASHES);

		// json_encode returns false on invalid UTF-8, which a save_doc body can
		// carry. Writing `false` queued an empty file that root refused with
		// exit 2 and no transcript — a save that failed for a reason nobody
		// could see. Refuse here, where the caller can say so.
		if ($body === false) {
			throw new InvalidArgumentException(
				'RootRequest: the arguments for "' . $kind . '" could not be encoded ('
				. json_last_error_msg() . '). Content has to be valid UTF-8.');
		}

		// Written under a temp name and renamed, so the runner can never read a
		// half-written request — it polls this directory on a timer and has no
		// way to know a write is in progress.
		$path = $dir . '/' . $id . '.json';
		$temp = $path . '.tmp';
		if (@file_put_contents($temp, $body) === false) {
			throw new RuntimeException('RootRequest: could not write the request at ' . $temp);
		}
		@chmod($temp, 0640);
		if (!@rename($temp, $path)) {
			@unlink($temp);
			throw new RuntimeException('RootRequest: could not place the request at ' . $path);
		}

		return $id;
	}

	// ---- reading back ------------------------------------------------------

	/**
	 * What became of a request.
	 *
	 * State is where the file is, not a field inside it: the runner moves a
	 * request between directories as it goes, so a run killed mid-way leaves a
	 * request that reads as `running` rather than one whose file claims a state
	 * nothing reached.
	 *
	 * @return array{state:string, id:string, kind:?string, exit_code:?int,
	 *               requested_at:?int, finished_at:?int}
	 */
	public static function status(string $id): array {
		$id = self::clean_id($id);
		$out = array('state' => 'unknown', 'id' => $id, 'kind' => null,
			'exit_code' => null, 'abandoned' => false,
			'requested_at' => null, 'finished_at' => null);
		if ($id === '') {
			return $out;
		}

		foreach (array('' => 'queued', 'running/' => 'running',
		               'done/' => 'done', 'failed/' => 'failed') as $sub => $state) {
			$path = self::queue_dir() . '/' . $sub . $id . '.json';
			if (!is_file($path)) {
				continue;
			}
			$out['state'] = $state;
			$body = json_decode((string)@file_get_contents($path), true);
			if (is_array($body)) {
				$out['kind'] = isset($body['kind']) ? (string)$body['kind'] : null;
				$out['requested_at'] = isset($body['requested_at']) ? (int)$body['requested_at'] : null;
			}
			$exit_file = self::queue_dir() . '/' . $sub . $id . '.exit';
			if (is_file($exit_file)) {
				$raw = trim((string)@file_get_contents($exit_file));
				// Only a number is an exit code. Anything else is a file that
				// was not written by the runner, and reading it as 0 would
				// report a failure as a success.
				$out['exit_code'] = preg_match('/^\d+$/', $raw) === 1 ? (int)$raw : null;
				$out['abandoned'] = ($out['exit_code'] === self::ABANDONED_EXIT);
				$out['finished_at'] = (int)@filemtime($exit_file);
			}
			return $out;
		}

		return $out;
	}

	/** The run's output so far. Empty until the runner has started it. */
	public static function transcript(string $id): string {
		$id = self::clean_id($id);
		if ($id === '') {
			return '';
		}
		$path = PathHelper::getSiteRoot() . '/' . self::LOG_DIR . '/' . $id . '.log';
		return is_file($path) ? (string)@file_get_contents($path) : '';
	}

	/**
	 * Requests waiting to be carried out, oldest first, with the age of each.
	 * Used by the admin notice: a queue that is not moving is a box whose root
	 * actor has stopped, and that is worth saying out loud.
	 */
	public static function pending(): array {
		$out = array();
		foreach (glob(self::queue_dir() . '/*.json') ?: array() as $path) {
			$body = json_decode((string)@file_get_contents($path), true);
			$out[] = array(
				'id'           => basename($path, '.json'),
				'kind'         => is_array($body) && isset($body['kind']) ? (string)$body['kind'] : null,
				'requested_at' => is_array($body) && isset($body['requested_at'])
					? (int)$body['requested_at'] : (int)@filemtime($path),
			);
		}
		usort($out, function ($a, $b) { return $a['requested_at'] <=> $b['requested_at']; });
		return $out;
	}

	/** The oldest pending request's age in seconds, or null when none wait. */
	public static function oldest_pending_age(): ?int {
		$pending = self::pending();
		if (!$pending) {
			return null;
		}
		return max(0, time() - (int)$pending[0]['requested_at']);
	}

	// ---- is there anybody to carry it out ----------------------------------

	/**
	 * Whether this machine has a root actor, which decides whether a page
	 * should promise that a request will be carried out.
	 *
	 * present — a converger is installed and has run recently.
	 * stale   — installed, but its last run is older than a converger's day.
	 * absent  — no timer and no cron entry: nothing will pick a request up.
	 */
	public static function actorState(): string {
		$facts = HostConvergerNotice::facts();
		if (empty($facts['installed'])) {
			return 'absent';
		}
		$last = $facts['last_run'];
		if ($last === null || (time() - (int)$last) >= HostConvergerNotice::STALE_AFTER) {
			return 'stale';
		}
		return 'present';
	}

	/**
	 * The line a page shows above a button that submits a request, when this
	 * machine cannot promise to carry one out. Empty when it can.
	 *
	 * The request is still written either way — a converger that comes back
	 * finds it waiting. What is never done is letting a button look like it
	 * worked on a box where nothing will act on it.
	 */
	public static function actorWarning(): string {
		switch (self::actorState()) {
			case 'absent':
				return 'This machine has no root actor; the request will wait until one runs.';
			case 'stale':
				return 'This machine\'s root actor has not run recently; the request will wait until it does.';
			default:
				return '';
		}
	}

	// ---- internals ---------------------------------------------------------

	private static function queue_dir(): string {
		return PathHelper::getSiteRoot() . '/' . self::QUEUE_DIR;
	}

	/**
	 * An id is `<unix>-<8 hex>` and nothing else. Every caller of status() and
	 * transcript() is reachable from a request parameter, and both build a path
	 * from what they are given.
	 */
	private static function clean_id(string $id): string {
		return preg_match('/^\d{9,12}-[0-9a-f]{8}$/', $id) === 1 ? $id : '';
	}
}
