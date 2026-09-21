<?php
/**
 * BackupVerifyLauncher — this site verifying its own backups.
 *
 * No agent, no management node. A site with a configured target and a proven
 * recovery key can prove one of its own backups restorable exactly the way a
 * management node proves a node's: by signing links to the backup's objects
 * from its own target and handing them to utils/verify_backup.php, which
 * stages the set, opens it with this site's own key and reads it to the end —
 * or rehearses a restore into scratch. This is the site side of the same
 * proof; server_manager drives OTHER machines and is not involved.
 *
 * Links are signed here, with S3Signer::presign_get, and expire with the
 * verify. No credential ever reaches the script: it receives signatures, one
 * object each, the same as a node does.
 *
 * Two ways to run it. The scheduled task (tasks/BackupVerify.php) runs a level
 * 2 synchronously and reports the result; the Backups page starts either
 * level in the background the way Run now starts a backup, and the result
 * lands on the run's own history row where Recent backups shows it.
 *
 * @version 1.2 - offloaded files travel with the request (specs/backup_offloaded_files.md § Verification):
 *                the run's index is read from backup storage, a link is signed for each epoch envelope it
 *                names, and a rehearsal's request also carries the sample — object_links() is the
 *                pure part, shared in shape with the management node's builder
 * @version 1.1 - due() has no settling wait on a never-verified site (the daily backup was always
 *                newer than a day, so the first verify never came); detach() hands the request to the background verify on an explicit descriptor:
 *                a backgrounded job's stdin is /dev/null in non-interactive bash, so the verify
 *                read nothing and refused, and both page buttons were silent no-ops
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

class BackupVerifyLauncherException extends Exception {}

class BackupVerifyLauncher {

	/**
	 * How long the signed links live: the verify's own ceiling. A link that
	 * outlived the verify would be a standing read on this site's backups.
	 */
	const LINK_SECONDS = 10800;

	/**
	 * The site's newest backup of its own that a verify can open: the newest
	 * successful, offsite, chained site-profile run. Null when the site has
	 * none — a site that takes no backups of its own has nothing to verify.
	 *
	 * @return BackupHistory|null
	 */
	public static function newest_run() {
		$rows = new MultiBackupHistory(
			array('profile' => BackupProfile::SITE, 'outcome' => 'success', 'offsite' => true,
			      'deleted' => false, 'chained' => true, 'type' => 'project'),
			array('bkh_start_time' => 'DESC'), 1, 0);
		foreach ($rows as $row) { return $row; }
		return null;
	}

	/**
	 * When this site's own backups were last verified, from the history rows:
	 * the newest site-profile row carrying a stamp, whatever its outcome.
	 *
	 * @return BackupHistory|null
	 */
	public static function last_verified() {
		$rows = new MultiBackupHistory(
			array('profile' => BackupProfile::SITE, 'verified' => true, 'deleted' => false),
			array('bkh_verify_time' => 'DESC'), 1, 0);
		foreach ($rows as $row) { return $row; }
		return null;
	}

	/**
	 * Whether the scheduled verify is due. The same rule the fleet applies to a
	 * node: the interval is on, there is a backup to verify, and either nothing
	 * has ever been verified or the last verify — pass or fail — is older than
	 * the interval and a newer backup exists.
	 *
	 * No settling wait on a never-verified site: a successful offsite run's
	 * row is written after its upload landed, and the two tasks run daily, so
	 * "wait until the newest backup is a day old" would never be satisfied —
	 * each morning's backup would be newer than that. The verify takes the
	 * backup locks, so it never reads a set a run is still writing.
	 *
	 * @return array{due:bool, reason:string, run:BackupHistory|null}
	 */
	public static function due($every_days, $now = null) {
		$every = max(0, (int)$every_days);
		$now_ts = strtotime(($now ?: gmdate('Y-m-d H:i:s')) . ' UTC');
		if ($every <= 0) {
			return array('due' => false, 'reason' => 'Verification is switched off (backup_verify_every_days is 0).', 'run' => null);
		}
		$run = self::newest_run();
		if ($run === null) {
			return array('due' => false, 'reason' => 'This site takes no backups of its own, so there is nothing to verify. '
				. 'A management node\'s backups of it are verified from there.', 'run' => null);
		}
		$run_ts = strtotime((string)$run->get('bkh_start_time') . ' UTC');
		$last = self::last_verified();
		if ($last === null) {
			return array('due' => true, 'reason' => 'never verified', 'run' => $run);
		}
		$last_ts = strtotime((string)$last->get('bkh_verify_time') . ' UTC');
		if ($run_ts !== false && $last_ts !== false && $run_ts <= $last_ts) {
			return array('due' => false, 'reason' => 'The newest backup is the one last verified ('
				. BackupVerifier::when_words((string)$last->get('bkh_verify_time')) . ').', 'run' => $run);
		}
		if ($last_ts !== false && ($now_ts - $last_ts) < $every * 86400) {
			$next = gmdate('Y-m-d', $last_ts + $every * 86400);
			return array('due' => false, 'reason' => 'Last verified ' . BackupVerifier::when_words((string)$last->get('bkh_verify_time'))
				. '; the next verification is due on ' . $next . '.', 'run' => $run);
		}
		return array('due' => true, 'reason' => 'the last verification is older than ' . $every . ' days', 'run' => $run);
	}

	/**
	 * The request utils/verify_backup.php takes, for one of this site's own
	 * runs: the chain, the level, the run, and a signed link to every object
	 * under the chain on this site's own target. Credentials are read from the
	 * site's plan and never leave this process.
	 *
	 * @throws BackupVerifyLauncherException
	 */
	public static function request(BackupHistory $run, $level, $seq = null) {
		if (!BackupVerifier::is_runnable_level($level)) {
			throw new BackupVerifyLauncherException('A verify is level 2 (open and read) or 3 (rehearse a restore).');
		}
		$chain_id = trim((string)$run->get('bkh_chain_id'));
		if (!preg_match('/^chain-[0-9_]+$/', $chain_id)) {
			throw new BackupVerifyLauncherException('That backup is not part of a set this site can verify.');
		}
		try {
			$plan = BackupRunner::plan(array('profile' => BackupProfile::SITE));
		} catch (BackupRunnerException $e) {
			throw new BackupVerifyLauncherException($e->getMessage());
		}
		$target = $plan['target'];
		$creds  = $target->get_credentials();
		$bucket = trim((string)$target->get('bkt_bucket'));
		if (empty($creds) || $bucket === '') {
			throw new BackupVerifyLauncherException('The backup target has no bucket or no stored credentials, so no link can be signed.');
		}
		$prefix = rtrim(trim((string)$target->get('bkt_path_prefix')) ?: 'joinery-backups', '/');
		$slug   = (string)($run->get('bkh_slug') ?: $plan['slug']);
		$chain_key = $prefix . '/' . $slug . '/' . BackupProfile::path_segment(BackupProfile::SITE) . '/' . $chain_id;

		$listing = S3Signer::list($creds, $bucket, $chain_key . '/');
		if (empty($listing) || !is_array($listing)) {
			throw new BackupVerifyLauncherException('Nothing is stored under that backup on the target, so there is nothing to verify.');
		}

		$manifest_url = '';
		$artifact_urls = array();
		foreach ($listing as $object) {
			$key = (string)($object['key'] ?? $object['Key'] ?? '');
			if ($key === '' || strpos($key, $chain_key . '/') !== 0) { continue; }
			$name = substr($key, strlen($chain_key) + 1);
			if ($name === '' || strpos($name, '/') !== false) { continue; }
			$url = S3Signer::presign_get($creds, $bucket, '/' . ltrim($key, '/'), self::LINK_SECONDS);
			if ($name === BackupChain::MANIFEST_NAME) {
				$manifest_url = $url;
			} elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
				$artifact_urls[$name] = $url;
			}
		}
		if ($manifest_url === '') {
			throw new BackupVerifyLauncherException('That backup has no manifest on the target, so its archives cannot be identified.');
		}
		if (!$artifact_urls) {
			throw new BackupVerifyLauncherException('That backup has a manifest but no archives on the target.');
		}

		$request = array(
			'chain_id'      => $chain_id,
			'profile'       => BackupProfile::SITE,
			'level'         => (int)$level,
			'manifest_url'  => $manifest_url,
			'artifact_urls' => $artifact_urls,
		);
		$seq = ($seq === null) ? (int)$run->get('bkh_chain_seq') : (int)$seq;
		$request['seq'] = $seq;

		// The run's offloaded files: its index, read from backup storage, says which
		// epoch envelopes the verify must open and — for a rehearsal — which
		// objects to open. A run with no index in backup storage gets no links;
		// the verify then fails on the missing artifact, by name.
		$index_name = BackupChain::artifact_name('objects', $seq);
		$index = null;
		if (isset($artifact_urls[$index_name])) {
			try {
				$resp = S3Signer::get($creds, $bucket, '/' . ltrim($chain_key . '/' . $index_name, '/'));
				if ((int)($resp['status'] ?? 0) === 200) {
					$index = BackupObjects::decode_index((string)($resp['body'] ?? ''), $index_name);
				}
			} catch (\Throwable $e) {
				error_log('BackupVerifyLauncher: could not read ' . $index_name . ': ' . $e->getMessage());
			}
		}
		if ($index !== null) {
			$objects_base = $prefix . '/' . $slug . '/' . BackupProfile::path_segment(BackupProfile::SITE) . '/';
			$links = self::object_links($index, (int)$level, function ($relname) use ($creds, $bucket, $objects_base) {
				return S3Signer::presign_get($creds, $bucket, '/' . ltrim($objects_base . $relname, '/'), self::LINK_SECONDS);
			});
			$request = array_merge($request, $links);
		}
		return $request;
	}

	/**
	 * The object-store links a verify request carries, from the run's index:
	 * a link per epoch envelope the index names, and at level 3 a link per
	 * sampled object (BackupVerifier::sample_objects). $sign turns a name
	 * relative to the profile's base key (objects/{epoch}/…) into a signed
	 * URL. Pure but for the sample's draw; the management node's builder
	 * composes the same two maps with its own signer.
	 *
	 * @return array{epoch_envelope_urls?:array, object_urls?:array}
	 */
	public static function object_links(array $index, $level, callable $sign) {
		$out = array();
		$entries = BackupObjects::index_entries($index);
		$epochs = array();
		foreach ($entries as $e) {
			if (preg_match(BackupStaging::EPOCH_ID_PATTERN, (string)$e['epoch'])) { $epochs[(string)$e['epoch']] = true; }
		}
		ksort($epochs);
		foreach (array_keys($epochs) as $epoch) {
			$out['epoch_envelope_urls'][$epoch] = $sign(BackupObjects::envelope_relname($epoch));
		}
		if ((int)$level === BackupVerifier::LEVEL_REHEARSE) {
			foreach (BackupVerifier::sample_objects($index) as $name) {
				$out['object_urls'][$name] = $sign(BackupObjects::object_relname((string)$entries[$name]['epoch'], $name));
			}
		}
		return $out;
	}

	/**
	 * Run a verify of this site's newest backup now, in this process, and
	 * return the parsed contract. What the scheduled task calls.
	 *
	 * @throws BackupVerifyLauncherException
	 */
	public static function run(BackupHistory $run, $level, $seq = null) {
		$request = self::request($run, $level, $seq);
		$script  = PathHelper::getIncludePath('utils/verify_backup.php');
		$descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		$proc = @proc_open(self::php() . ' ' . escapeshellarg($script), $descriptors, $pipes);
		if (!is_resource($proc)) {
			throw new BackupVerifyLauncherException('Could not start the verify.');
		}
		fwrite($pipes[0], json_encode($request));
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$rc = proc_close($proc);

		$result = BackupVerifier::parse_contract((string)$out);
		if (!preg_match('/^VERIFY_RESULT=/m', (string)$out)) {
			$result['result'] = BackupVerifier::RESULT_FAIL;
			$result['reason'] = 'the verify exited ' . (int)$rc . ' without a result'
				. (trim((string)$err) !== '' ? ': ' . trim(substr((string)$err, -400)) : '');
		}
		return $result;
	}

	/**
	 * Start a verify in the background, the way Run now starts a backup: the
	 * request crosses on stdin (never argv, never a file — it carries signed
	 * links) to a detached process, and the result lands on the run's history
	 * row for Recent backups to show.
	 *
	 * @throws BackupVerifyLauncherException
	 */
	public static function start(BackupHistory $run, $level, $seq = null) {
		$request = self::request($run, $level, $seq);
		self::detach(PathHelper::getIncludePath('utils/verify_backup.php'), json_encode($request));
		return true;
	}

	/**
	 * Run a PHP script detached from this process, with $payload as the whole
	 * of its stdin.
	 *
	 * bash is asked to fork the script and return at once. The payload crosses
	 * on a pipe, and the pipe is handed to the script on fd 3 explicitly:
	 * a job bash puts in the background (`&`) gets /dev/null as its stdin
	 * unless told otherwise, so the script would read nothing and refuse.
	 * `<&3` overrides that; `3<&-` closes the spare descriptor so the script
	 * holds the pipe on stdin only. setsid puts it in its own session so the
	 * web request ending does not end it.
	 *
	 * @throws BackupVerifyLauncherException
	 */
	public static function detach($script, $payload) {
		$cmd = 'bash -c ' . escapeshellarg('exec 3<&0; setsid nohup ' . self::php() . ' ' . escapeshellarg($script)
			. ' <&3 3<&- > /dev/null 2>&1 &');
		$descriptors = array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w'));
		$proc = @proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($proc)) {
			throw new BackupVerifyLauncherException('Could not start the verify.');
		}
		fwrite($pipes[0], (string)$payload);
		fclose($pipes[0]);
		proc_close($proc);
	}

	/** The PHP that is running this, so cron and the web tier launch the same one. */
	private static function php() {
		$bin = (string)(defined('PHP_BINARY') ? PHP_BINARY : '');
		// The web tier's PHP_BINARY is php-fpm, which runs no script.
		if ($bin === '' || strpos(basename($bin), 'fpm') !== false || !is_executable($bin)) {
			return 'php';
		}
		return escapeshellarg($bin);
	}
}
