<?php
/**
 * BackupDownload — a whole-site backup a superadmin takes away in the browser.
 *
 * A site with no backup storage, or one whose management node or agent is
 * down, still has a way to put a copy of itself in somebody's hands. The copy
 * is the same standalone archive a full backup uploads — backup_project.sh in
 * stream mode, sealed with a fresh data key to this site's recovery key and
 * its own site key — written to disk here instead of to a bucket, and handed
 * over as one file: a tar holding a README, the envelope and the archive.
 *
 * Never plaintext. The archive carries config/ (the database password, the
 * secret box key), and a file in a Downloads folder is the worst place for
 * those in the clear. A site without a proven recovery key cannot build one,
 * and is told to finish that setup, which needs only the browser.
 *
 * Built first, then downloaded, rather than streamed into the response: a
 * dropped connection retries the download instead of the backup, and a slow
 * browser never holds the backup lock. The cost is disk the size of the
 * archive, checked before anything is built.
 *
 * One download at a time, in `{backup working directory}/download/`, described
 * by state.json there. It is not a backup run: no history row, no chain, no
 * snapshot, nothing that counts as offsite — the site cannot know whether the
 * file was kept. It is deleted KEEP_SECONDS after it is built, by the hourly
 * BackupDownloadSweep task and by any read of its state after that.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

class BackupDownloadException extends Exception {}

class BackupDownload {

	/** How long a built download stays on this server. */
	const KEEP_SECONDS = 86400;

	/** The download's directory, under the backup working directory. */
	const SUBDIR = 'download';

	const STATE_FILE = 'state.json';
	const README_NAME = 'README.txt';

	/** The file a person receives is named {project}-{stamp} plus this. */
	const DOWNLOAD_SUFFIX = '.joinery-backup.tar';

	/** The background build, started by start(). */
	const BUILDER = 'utils/build_backup_download.php';

	/**
	 * Seams for tests: 'dir', 'now', 'free_bytes', 'estimate', 'recipients',
	 * 'engine' (a shell command standing in for backup_project.sh; it is given
	 * the key file and the report path as $1 and $2), 'launch' (a callable
	 * returning a pid), 'alive' (a callable taking a pid).
	 */
	public static $test_hooks = array();

	// ----------------------------------------------------------------- state

	public static function dir() {
		if (isset(self::$test_hooks['dir'])) {
			return rtrim((string)self::$test_hooks['dir'], '/');
		}
		return BackupRunner::output_dir() . '/' . self::SUBDIR;
	}

	private static function now() {
		return isset(self::$test_hooks['now']) ? (int)self::$test_hooks['now'] : time();
	}

	/**
	 * The download as it stands, or null when there is none. Settles what time
	 * has decided first: a build whose process is gone failed, and a download
	 * or failure past its time is removed.
	 *
	 * Each: status (building|ready|failed), name, started, and by status:
	 * pid; finished, expires, archive, bytes, download_bytes; message.
	 */
	public static function state() {
		$s = self::read_state();
		if ($s === null) {
			return null;
		}
		// A build is given a minute to report its pid: start() writes the state
		// before the process it launches exists.
		$launching = (int)($s['pid'] ?? 0) === 0 && self::now() - (int)($s['started'] ?? 0) < 60;
		if ($s['status'] === 'building' && !$launching && !self::alive((int)($s['pid'] ?? 0))) {
			self::remove_files($s);
			$s = self::record_failure($s, 'The build stopped before it finished. Nothing was kept; build it again.');
		}
		if ($s['status'] !== 'building' && (int)($s['expires'] ?? 0) <= self::now()) {
			self::remove_all();
			return null;
		}
		return $s;
	}

	private static function read_state() {
		$raw = @file_get_contents(self::dir() . '/' . self::STATE_FILE);
		if ($raw === false) {
			return null;
		}
		$s = json_decode($raw, true);
		return (is_array($s) && in_array($s['status'] ?? '', array('building', 'ready', 'failed'), true)) ? $s : null;
	}

	private static function write_state(array $s) {
		$path = self::dir() . '/' . self::STATE_FILE;
		$tmp = $path . '.tmp' . getmypid();
		if (@file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
			|| !@rename($tmp, $path)) {
			@unlink($tmp);
			throw new BackupDownloadException('Could not record the download\'s state in ' . self::dir() . '.');
		}
		return $s;
	}

	private static function record_failure(array $s, $message) {
		$s['status']   = 'failed';
		$s['finished'] = self::now();
		$s['expires']  = self::now() + self::KEEP_SECONDS;
		$s['message']  = (string)$message;
		unset($s['pid']);
		return self::write_state($s);
	}

	/** Is the build process still running? Its pid, and its command line naming the builder. */
	private static function alive($pid) {
		if (isset(self::$test_hooks['alive'])) {
			return (bool)call_user_func(self::$test_hooks['alive'], $pid);
		}
		if ($pid <= 0) {
			return false;
		}
		$cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
		return $cmdline !== false && strpos($cmdline, basename(self::BUILDER)) !== false;
	}

	// ----------------------------------------------------------------- start

	/**
	 * Start building a download, in the background. Replaces a finished or
	 * failed one; refuses while one is building, without a proven recovery
	 * key, and when this disk cannot hold the archive.
	 */
	public static function start($user_id) {
		self::ensure_dir();
		// Read-only is enough to hold a lock another account's run created.
		$lock = @fopen(self::dir() . '/.start.lock', 'c') ?: @fopen(self::dir() . '/.start.lock', 'r');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			throw new BackupDownloadException('A download is already being started.');
		}
		try {
			$s = self::state();
			if ($s !== null && $s['status'] === 'building') {
				throw new BackupDownloadException('A backup is already being built for download. It appears on this page when it is ready.');
			}
			self::recipients();
			$refusal = self::disk_refusal();
			if ($refusal !== '') {
				throw new BackupDownloadException($refusal);
			}

			self::remove_all();
			$name = basename(PathHelper::getSiteRoot()) . '-' . gmdate('Ymd_His', self::now());
			$s = self::write_state(array(
				'status'  => 'building',
				'name'    => $name,
				'started' => self::now(),
				'by'      => (int)$user_id,
				'pid'     => 0,
			));
			$pid = self::launch();
			if ($pid <= 0) {
				return self::record_failure($s, 'The build could not be started.');
			}
			$s['pid'] = $pid;
			return self::write_state($s);
		} finally {
			if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
		}
	}

	/** Detach the builder; its pid, or 0. */
	private static function launch() {
		if (isset(self::$test_hooks['launch'])) {
			return (int)call_user_func(self::$test_hooks['launch']);
		}
		$runner = PathHelper::getIncludePath(self::BUILDER);
		$out = array();
		@exec('nohup php ' . escapeshellarg($runner) . ' > /dev/null 2>&1 & echo $!', $out);
		return (int)($out[0] ?? 0);
	}

	/** Every backup of this site seals to these; throws, in words, without a proven recovery key. */
	private static function recipients() {
		if (isset(self::$test_hooks['recipients'])) {
			return self::$test_hooks['recipients'];
		}
		try {
			return BackupEnvelope::recipients();
		} catch (\Throwable $e) {
			throw new BackupDownloadException('A backup to download is sealed to this site\'s recovery key, and that key is not '
				. 'set up and proven yet. Finish recovery key setup on this page first.');
		}
	}

	// ------------------------------------------------------------- the disk

	/**
	 * What the archive is expected to weigh: the newest successful full
	 * whole-site backup of this site, by whoever ran it. 0 when there has
	 * been none.
	 */
	public static function estimate() {
		if (isset(self::$test_hooks['estimate'])) {
			return (int)self::$test_hooks['estimate'];
		}
		$rows = new MultiBackupHistory(
			array('outcome' => 'success', 'type' => 'project', 'deleted' => false),
			array('bkh_start_time' => 'DESC'), 50, 0);
		foreach ($rows as $h) {
			if ((string)$h->get('bkh_chain_id') === '' || (int)$h->get('bkh_chain_seq') === 0) {
				return (int)$h->get('bkh_bytes');
			}
		}
		return 0;
	}

	/** Free bytes where the download is built, or null when the disk will not say. */
	public static function free_bytes() {
		if (array_key_exists('free_bytes', self::$test_hooks)) {
			return self::$test_hooks['free_bytes'];
		}
		$dir = is_dir(self::dir()) ? self::dir() : dirname(self::dir());
		$free = @disk_free_space($dir);
		return ($free === false) ? null : (int)$free;
	}

	/**
	 * The refusal when this disk cannot hold the archive, or ''. The rule a
	 * backup run's pre-flight uses (a fifth more, and the floor kept free),
	 * with the archive itself as the need, since here it does land on disk.
	 */
	public static function disk_refusal() {
		return BackupRunner::preflight_refusal(self::estimate() + BackupRunner::PREFLIGHT_LOCAL_OVERHEAD, self::free_bytes(), 0);
	}

	/**
	 * Offloaded files with no copy on this server: they are in the cloud file
	 * store only, so no archive built here holds them. Never throws.
	 */
	public static function cloud_only_count() {
		try {
			$n = 0;
			foreach (BackupObjects::cloud_objects() as $obj) {
				$here = false;
				foreach ((array)($obj['paths'] ?? array()) as $p) {
					if (is_file((string)$p)) { $here = true; break; }
				}
				if (!$here) { $n++; }
			}
			return $n;
		} catch (\Throwable $e) {
			error_log('BackupDownload: could not count offloaded files: ' . $e->getMessage());
			return 0;
		}
	}

	// ----------------------------------------------------------------- build

	/**
	 * Build the download the state names. Runs in the background process
	 * start() launched. Never throws: whatever happens is written to the
	 * state, which is what the page reads.
	 */
	public static function build() {
		@set_time_limit(0);
		$s = self::read_state();
		if ($s === null || $s['status'] !== 'building') {
			return null;
		}
		$s['pid'] = getmypid();
		self::write_state($s);

		// The machine-wide backup lock, so this never archives the tree while a
		// backup run does; the second lock is this directory's own.
		$locks = BackupRunner::take_locks(array('base_dir' => dirname(self::dir()), 'output_dir' => self::dir()));
		if ($locks === false) {
			return self::record_failure($s, 'A backup was running on this server, so nothing was built. Try again once it finishes.');
		}
		try {
			return self::build_locked($s);
		} catch (\Throwable $e) {
			self::remove_files($s);
			return self::record_failure($s, $e->getMessage());
		} finally {
			BackupRunner::release_locks($locks);
		}
	}

	private static function build_locked(array $s) {
		$dir      = self::dir();
		$name     = (string)$s['name'];
		$archive  = $name . '.tar.gz.enc';
		$mint     = BackupEnvelope::mint($archive, self::recipients());
		$key_file = $dir . '/.key-' . getmypid();
		$report   = $dir . '/.report-' . getmypid();
		$err_file = $dir . '/.stderr-' . getmypid();

		$old = umask(0077);
		$wrote = @file_put_contents($key_file, $mint['data_key']);
		umask($old);
		if ($wrote === false) {
			throw new BackupDownloadException('Could not write the run key.');
		}

		try {
			$rc = self::run_engine($name, $dir . '/' . $archive, $key_file, $report, $err_file);
		} finally {
			if (is_file($key_file)) {
				@exec('shred -u ' . escapeshellarg($key_file) . ' 2>/dev/null');
				@unlink($key_file);
			}
		}

		$r = self::parse_report((string)@file_get_contents($report));
		$stderr = trim((string)@file_get_contents($err_file));
		@unlink($report);
		@unlink($err_file);
		clearstatcache();
		$bytes = (int)@filesize($dir . '/' . $archive);
		$why = self::verdict($rc, $r, $bytes);
		if ($why !== '') {
			// The engine colours its lines for a terminal; the reason is read on a page.
			$stderr = trim((string)preg_replace('/\x1b\[[0-9;]*m/', '', $stderr));
			if ($stderr !== '') {
				// tar's own complaints name the file; the engine's closing lines only say it failed.
				$lines = explode("\n", $stderr);
				$tar = array_values(array_filter($lines, function ($l) {
					return strpos($l, 'tar: ') === 0 && strpos($l, 'Exiting with failure') === false
						&& strpos($l, ': socket ignored') === false;
				}));
				$why .= ' (' . implode(' | ', $tar ? array_slice($tar, 0, 3) : array_slice($lines, -3)) . ')';
			}
			throw new BackupDownloadException($why);
		}

		BackupEnvelope::write_sidecar($dir . '/' . $archive . BackupEnvelope::SIDECAR_SUFFIX, $mint['envelope']);
		if (@file_put_contents($dir . '/' . self::README_NAME, self::readme($name, $archive)) === false) {
			throw new BackupDownloadException('Could not write the README.');
		}

		$s['status']   = 'ready';
		$s['archive']  = $archive;
		$s['bytes']    = $bytes;
		$s['finished'] = self::now();
		$s['expires']  = self::now() + self::KEEP_SECONDS;
		unset($s['pid'], $s['message']);
		$s['download_bytes'] = self::tar_length(self::entry_sizes(self::entries($s)));
		return self::write_state($s);
	}

	/** Run the archive engine, its stdout into the archive file. Its exit status. */
	private static function run_engine($name, $out_path, $key_file, $report, $err_file) {
		if (isset(self::$test_hooks['engine'])) {
			$cmd = 'set -- ' . escapeshellarg($key_file) . ' ' . escapeshellarg($report) . '; ' . self::$test_hooks['engine'];
		} else {
			$tools = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';
			$cmd = 'bash ' . escapeshellarg($tools . '/backup_project.sh')
				. ' ' . escapeshellarg(basename(PathHelper::getSiteRoot()))
				. ' --non-interactive --output-dir ' . escapeshellarg(self::dir())
				. ' --key-file ' . escapeshellarg($key_file)
				. ' --name ' . escapeshellarg($name)
				. ' --archive - --report ' . escapeshellarg($report);
		}
		$proc = @proc_open(array('bash', '-c', $cmd), array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('file', $out_path, 'w'),
			2 => array('file', $err_file, 'w'),
		), $pipes);
		if (!is_resource($proc)) {
			throw new BackupDownloadException('The archive engine could not be started.');
		}
		return (int)proc_close($proc);
	}

	private static function parse_report($raw) {
		$out = array();
		foreach (preg_split('/\r?\n/', $raw) as $line) {
			if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m)) {
				$out[$m[1]] = $m[2];
			}
		}
		return $out;
	}

	/**
	 * Why the engine's output is not an archive, or '' when it is: exit 0, a
	 * report, tar 0 or 1 (a file changed while it was read), openssl 0, and
	 * more than an empty openssl envelope's bytes. A backup run's rule. Pure.
	 */
	public static function verdict($rc, array $report, $bytes) {
		if (!$report) {
			return 'The archive engine ' . ($rc !== 0 ? 'exited ' . $rc . ' and ' : '') . 'wrote no report';
		}
		$tar = (int)($report['TAR_RC'] ?? 2);
		$enc = (int)($report['ENC_RC'] ?? 1);
		if ($enc !== 0) { return 'Encrypting the archive failed (openssl exit ' . $enc . ')'; }
		if ($tar !== 0 && $tar !== 1) { return 'The archive failed (tar exit ' . $tar . ')'; }
		if ($rc !== 0) { return 'The archive engine exited ' . $rc; }
		if ($bytes < BackupRunner::MIN_ARCHIVE_BYTES) {
			return 'The archive is ' . $bytes . ' bytes, so nothing was archived';
		}
		return '';
	}

	// ----------------------------------------------------------- the file

	/** What the person receives, in tar order: [name in the tar => path here]. */
	private static function entries(array $s) {
		$dir = self::dir();
		$top = (string)$s['name'] . '/';
		$archive = (string)$s['archive'];
		return array(
			$top . self::README_NAME => $dir . '/' . self::README_NAME,
			$top . $archive . BackupEnvelope::SIDECAR_SUFFIX => $dir . '/' . $archive . BackupEnvelope::SIDECAR_SUFFIX,
			$top . $archive => $dir . '/' . $archive,
		);
	}

	private static function entry_sizes(array $entries) {
		$sizes = array();
		foreach ($entries as $path) {
			clearstatcache(true, $path);
			$size = @filesize($path);
			if ($size === false) {
				throw new BackupDownloadException('Part of the download is missing from this server: ' . basename($path) . '.');
			}
			$sizes[] = (int)$size;
		}
		return $sizes;
	}

	/** The name a person's browser saves it under. */
	public static function download_name(array $s) {
		return (string)$s['name'] . self::DOWNLOAD_SUFFIX;
	}

	/**
	 * Send the ready download to the browser and end the request. The tar is
	 * written as it is sent — headers, then each file's bytes — so nothing is
	 * copied on this disk, and its length is known before the first byte.
	 */
	public static function send() {
		$s = self::state();
		if ($s === null || $s['status'] !== 'ready') {
			throw new BackupDownloadException('There is no backup ready to download.');
		}
		$entries = self::entries($s);
		$length = self::tar_length(self::entry_sizes($entries));

		while (ob_get_level() > 0) { ob_end_clean(); }
		if (session_status() === PHP_SESSION_ACTIVE) {
			// A download can take an hour; the person's other pages must not wait on it.
			session_write_close();
		}
		@set_time_limit(0);
		header('Content-Type: application/x-tar');
		header('Content-Disposition: attachment; filename="' . self::download_name($s) . '"');
		header('Content-Length: ' . $length);
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		try {
			self::write_tar($entries, function ($bytes) { echo $bytes; flush(); });
		} catch (\Throwable $e) {
			// The headers are gone; a short body is how the browser learns it failed.
			error_log('BackupDownload: sending ' . self::download_name($s) . ' stopped: ' . $e->getMessage());
		}
		exit;
	}

	/** Bytes of a tar holding files of these sizes: a header and padded data each, then two zero blocks. Pure. */
	public static function tar_length(array $sizes) {
		$n = 1024;
		foreach ($sizes as $size) {
			$n += 512 + (int)(ceil($size / 512) * 512);
		}
		return $n;
	}

	/** Write a tar of $entries ([name => path]) through $out, a callable taking a string. */
	public static function write_tar(array $entries, callable $out) {
		foreach ($entries as $name => $path) {
			clearstatcache(true, $path);
			$size = (int)@filesize($path);
			$fh = @fopen($path, 'rb');
			if (!$fh) {
				throw new BackupDownloadException('Could not read ' . basename($path) . '.');
			}
			$out(self::tar_header($name, $size, (int)@filemtime($path)));
			$sent = 0;
			while ($sent < $size && !feof($fh)) {
				$chunk = fread($fh, min(1048576, $size - $sent));
				if ($chunk === false || $chunk === '') { break; }
				$sent += strlen($chunk);
				$out($chunk);
			}
			fclose($fh);
			if ($sent !== $size) {
				// The header promised $size bytes; anything else is a broken tar.
				throw new BackupDownloadException(basename($path) . ' changed while it was being sent.');
			}
			if ($size % 512) {
				$out(str_repeat("\0", 512 - $size % 512));
			}
		}
		$out(str_repeat("\0", 1024));
	}

	/**
	 * One ustar header. A size past ustar's eleven octal digits (8 GiB) is
	 * written in base-256, which GNU tar, bsdtar and Python's tarfile read; a
	 * name past 100 bytes is split into prefix and name at a slash. Pure.
	 */
	public static function tar_header($name, $size, $mtime) {
		$prefix = '';
		if (strlen($name) > 100) {
			$cut = strrpos(substr($name, 0, 156), '/');
			if ($cut === false || strlen($name) - $cut - 1 > 100) {
				throw new BackupDownloadException('The name ' . $name . ' is too long for the download.');
			}
			$prefix = substr($name, 0, $cut);
			$name = substr($name, $cut + 1);
		}
		$size_field = ($size < 077777777777)
			? sprintf('%011o', $size) . "\0"
			: "\x80" . substr(str_pad(self::big_endian($size), 11, "\0", STR_PAD_LEFT), -11);

		$h = str_pad($name, 100, "\0")
			. sprintf('%07o', 0644) . "\0"
			. sprintf('%07o', 0) . "\0"
			. sprintf('%07o', 0) . "\0"
			. $size_field
			. sprintf('%011o', max(0, $mtime)) . "\0"
			. str_repeat(' ', 8)
			. '0'
			. str_repeat("\0", 100)
			. "ustar\0" . '00'
			. str_repeat("\0", 32)
			. str_repeat("\0", 32)
			. str_repeat("\0", 8)
			. str_repeat("\0", 8)
			. str_pad($prefix, 155, "\0")
			. str_repeat("\0", 12);
		$sum = 0;
		for ($i = 0; $i < 512; $i++) { $sum += ord($h[$i]); }
		return substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8);
	}

	private static function big_endian($n) {
		$out = '';
		while ($n > 0) {
			$out = chr($n & 0xff) . $out;
			$n = intdiv($n, 256);
		}
		return $out;
	}

	/** The README inside the download: what it holds and the commands that open it. */
	public static function readme($name, $archive) {
		$keys = $archive . BackupEnvelope::SIDECAR_SUFFIX;
		return <<<TXT
Joinery site backup {$name} (the date and time are UTC).

  {$archive}
      The whole site: its files, its database and its web server
      configuration, encrypted.
  {$keys}
      The archive's key, sealed to the site's backup recovery key. Only the
      recovery private key (kept in your password manager) opens it.

Files moved to the cloud file store are not in this archive unless a copy was
still on the server; they stay in the file store.

Restoring onto a Joinery site (install the site first, then, as root):

  php maintenance_scripts/sysadmin_tools/backup_envelope.php open \\
      --sidecar {$keys} --private ~/recovery.key --key-out /tmp/k
  bash maintenance_scripts/sysadmin_tools/restore_project.sh PROJECT \\
      {$archive} --key-file /tmp/k
  shred -u /tmp/k

Opening it with no Joinery anywhere (openssl and PHP's sodium extension):

  1. Unseal the data key from {$keys} with the recovery private key: each
     recipients[].sealed entry is a libsodium sealed box over the data key.
  2. openssl enc -d -aes-256-cbc -pbkdf2 -pass file:data_key.txt \\
         -in {$archive} -out {$name}.tar.gz
  3. Shred data_key.txt.

The Backups documentation (docs/backups.md, "Opening a backup with no Joinery
anywhere") has step 1 as a ready-to-run command.

TXT;
	}

	// --------------------------------------------------------------- remove

	/** Delete the download, finished or failed. Refuses while it is building. */
	public static function discard() {
		$s = self::state();
		if ($s !== null && $s['status'] === 'building') {
			throw new BackupDownloadException('The backup is still being built. Delete it once it is ready.');
		}
		self::remove_all();
	}

	/**
	 * The hourly sweep: settle the state, which removes a download past its
	 * time. Returns a task result.
	 */
	public static function sweep() {
		if (!is_dir(self::dir())) {
			return array('status' => 'success', 'message' => 'no download');
		}
		$before = self::read_state();
		$after = self::state();
		if ($before !== null && $after === null) {
			return array('status' => 'success', 'message' => 'removed the download built for ' . $before['name']);
		}
		return array('status' => 'success', 'message' => $after === null ? 'no download' : 'download ' . $after['status']);
	}

	private static function remove_files(array $s) {
		$dir = self::dir();
		foreach (glob($dir . '/' . (string)($s['name'] ?? '-') . '*') ?: array() as $p) {
			if (is_file($p)) { @unlink($p); }
		}
		foreach (array(self::README_NAME) as $f) {
			@unlink($dir . '/' . $f);
		}
		foreach (glob($dir . '/.{key,report,stderr}-*', GLOB_BRACE) ?: array() as $p) {
			@unlink($p);
		}
		// backup_project.sh stages its dump beside its output.
		foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: array() as $p) {
			exec('rm -rf ' . escapeshellarg($p));
		}
	}

	/** Everything in the directory but its locks. */
	private static function remove_all() {
		$dir = self::dir();
		foreach (array_merge(glob($dir . '/*') ?: array(), glob($dir . '/.[!.]*') ?: array()) as $p) {
			if (substr(basename($p), -5) === '.lock') { continue; }
			if (is_dir($p)) {
				exec('rm -rf ' . escapeshellarg($p));
			} else {
				@unlink($p);
			}
		}
	}

	private static function ensure_dir() {
		$dir = self::dir();
		// Group-writable, like the backup directory it sits in: the web user
		// builds from the page and the deploy account from a shell, and both
		// are in the web server's group.
		// The group is the backup directory's, kept by setgid on what is made
		// inside, so whichever account built the download, the other can
		// replace it.
		if (!is_dir($dir)) {
			if (!@mkdir($dir, 0770, true)) {
				throw new BackupDownloadException('The download directory ' . $dir . ' could not be created.');
			}
			@chgrp($dir, filegroup(dirname($dir)));
			@chmod($dir, 02770);
		}
		if (!is_writable($dir)) {
			throw new BackupDownloadException('The download directory ' . $dir . ' is not writable.');
		}
	}
}
