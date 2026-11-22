<?php
/**
 * PackageFixChannel - the package fix set machines take, published on its own,
 * signed and publicly logged, apart from code releases
 * (specs/package_fixes_automatic.md WP6, WP7, WP8).
 *
 * WHAT IT IS. One small package, served at static_files/fixes/channel/:
 *
 *   ACTIVE             the fix lines machines take, in fixes/ACTIVE's format
 *                      (PackageFixPublisher::LINE_FIELDS)
 *   WITHDRAWN          "<source> <version>" per line: a build pulled back; a
 *                      machine holding it installs Ubuntu's build again
 *   CHANNEL            serial=<n> (only ever goes up), issued=<UTC>,
 *                      wide=yes|no, release=<the release it was issued on>
 *   RELEASE_MANIFEST   + .sig (the release key, TreeManifestPublisher::write())
 *   RELEASE_STATEMENT  its own statement in the public log
 *
 * LOGGED LIKE A RELEASE. Every node requires the log, and a package is unlogged
 * unless a logged statement's artifacts name its manifest
 * (PackageSignature::checkLogged()). Each issue is its own statement: the
 * newest release's payload, its commits and keys unchanged, version
 * "<release>+fixes.<serial>", artifacts the release's core plus fixes_channel
 * (this manifest). It is recorded in rle_release_log_entries the moment the
 * log answers, as a publish records its own, so the log tail counts it ours.
 *
 * WHO ISSUES IT. Only the box that logs releases (ReleaseLogWatch::watches()),
 * from the automatic runner (package_fix_auto.php), as root.
 *
 * STAGES. A set that adds or changes a fix is issued wide=no: the machines
 * that fetch from the publishing box itself (its own test fleet) take it at
 * once; a mirror (mirror()) serves only wide=yes issues. The runner issues the
 * same set again as wide=yes once it has soaked (PackageFixAuto). A
 * withdrawal is always wide=yes.
 *
 * WHERE MACHINES GET IT. install_package_fixes.sh converge fetches channel/
 * from its fix servers, checks it with utils/verify_package.php, and takes it
 * when it is signed and its serial is at least the last one it took.
 *
 * @version 1.2 - reviewer2's re-review (B18): the last set issued to every machine is also served at
 *                channel-wide/, which a mirror reads, so a set for the test machines issued after it never
 *                hides it (a withdrawal reaches production while another fix soaks)
 * @version 1.1 - reviewer2's review: the record is a root-owned file, the serial goes above the served one and
 *                a served withdrawal is never dropped, whatever the record says (B5); every line is checked
 *                before it is signed (B6)
 * @version 1.0
 */
class PackageFixChannel {

	const DIR_REL = 'static_files/fixes/channel';
	/** The last set issued to every machine: what a mirror passes on. */
	const WIDE_DIR_REL = 'static_files/fixes/channel-wide';
	const FILES = array('ACTIVE', 'WITHDRAWN', 'CHANNEL');
	const SERVED = array('ACTIVE', 'WITHDRAWN', 'CHANNEL', 'RELEASE_MANIFEST', 'RELEASE_MANIFEST.sig', 'RELEASE_STATEMENT');
	/** The publishing box's record of what it issued: root's to write, everyone's to read. */
	const STATE_FILE = '/var/lib/joinery-package-fixes-build/channel-state.json';

	/** The served channel directory of a site. */
	public static function dir(string $full_site_dir): string {
		return rtrim($full_site_dir, '/') . '/' . self::DIR_REL;
	}

	/** The served copy of the last set issued to every machine. */
	public static function wideDir(string $full_site_dir): string {
		return rtrim($full_site_dir, '/') . '/' . self::WIDE_DIR_REL;
	}

	/** Copy a served channel directory to $to, in one rename. */
	private static function copyServed(string $from, string $to): void {
		$stage = dirname($to) . '/.channel.' . getmypid() . '.copy';
		self::removeDir($stage);
		if (!mkdir($stage, 0755, true)) {
			throw new Exception('cannot create ' . $stage);
		}
		foreach (self::SERVED as $name) {
			if (!copy($from . '/' . $name, $stage . '/' . $name) || !chmod($stage . '/' . $name, 0644)) {
				self::removeDir($stage);
				throw new Exception('cannot copy ' . $name . ' to ' . $to);
			}
		}
		self::swapIn($stage, $to);
	}

	/** What this box last issued: serial, issued, wide, release, set (the set's hash). */
	public static function state(): array {
		return self::readStateFile(self::STATE_FILE);
	}

	/** A JSON record file, or an empty array when there is none. */
	public static function readStateFile(string $path): array {
		$s = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
		return is_array($s) ? $s : array();
	}

	/** Write a JSON record file in one rename, 0644 (the Fleet Security page reads it). Throws. */
	public static function writeStateFile(string $path, array $state): void {
		$dir = dirname($path);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
			throw new Exception('cannot create ' . $dir);
		}
		$tmp = $path . '.' . getmypid() . '.new';
		if (file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES)) === false || !chmod($tmp, 0644) || !rename($tmp, $path)) {
			@unlink($tmp);
			throw new Exception('cannot write ' . $path);
		}
	}

	/**
	 * Whether one ACTIVE line may be signed: every field in the shape machines
	 * accept, and its archive in static_files with that hash. Pure apart from
	 * reading the archive. The reason, or '' when it may.
	 */
	public static function lineRefusal(array $line, string $static_dir): string {
		foreach (PackageFixPublisher::LINE_FIELDS as $f) {
			if (!isset($line[$f]) || !is_string($line[$f])) {
				return 'a line has no ' . $f;
			}
		}
		$checks = array(
			'source'         => '/^[a-z0-9][a-z0-9.+-]+$/',
			'version'        => '/^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*\+joinery[0-9]+$/',
			'ubuntu_base'    => '/^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*$/',
			'archive'        => PackageFixPublisher::ARCHIVE_PATTERN,
			'sha256'         => '/^[0-9a-f]{64}$/',
			'recipe_sha256'  => '/^[0-9a-f]{64}$/',
			'changelog_from' => '/^[a-z0-9][a-z0-9.+-]+$/',
			'cves'           => '/^CVE-\d{4}-\d{4,}( CVE-\d{4}-\d{4,})*$/',
		);
		foreach ($checks as $f => $re) {
			if (!preg_match($re, $line[$f])) {
				return $line['source'] . ': its ' . $f . ' is not in the shape machines take';
			}
		}
		if (strpos($line['archive'], 'joinery-fix-' . $line['source'] . '-' . preg_replace('/^\d+:/', '', $line['version']) . '-') !== 0) {
			return $line['source'] . ': its archive is not named for its version';
		}
		$path = rtrim($static_dir, '/') . '/' . $line['archive'];
		if (!is_file($path) || !hash_equals($line['sha256'], (string)hash_file('sha256', $path))) {
			return $line['source'] . ': ' . $line['archive'] . ' is not served with that hash';
		}
		return '';
	}

	/**
	 * The text of a set: ACTIVE and WITHDRAWN, as written. Pure.
	 *
	 * @param array $lines     ACTIVE lines by source (PackageFixPublisher::parseLine() shape)
	 * @param array $withdrawn source => list of versions
	 * @return array{ACTIVE:string, WITHDRAWN:string}
	 */
	public static function setText(array $lines, array $withdrawn): array {
		ksort($lines);
		$active = "# The package fix channel (PackageFixChannel): the fixes machines take. Do not edit.\n";
		foreach ($lines as $line) {
			$parts = array();
			foreach (PackageFixPublisher::LINE_FIELDS as $f) {
				$parts[] = $f . '=' . $line[$f];
			}
			$active .= implode(' ', $parts) . "\n";
		}
		ksort($withdrawn);
		$w = "# Builds pulled back: a machine holding one installs Ubuntu's build again. Do not edit.\n";
		foreach ($withdrawn as $source => $versions) {
			$versions = array_values(array_unique((array)$versions));
			sort($versions);
			foreach ($versions as $v) {
				$w .= $source . ' ' . $v . "\n";
			}
		}
		return array('ACTIVE' => $active, 'WITHDRAWN' => $w);
	}

	/** A set's identity: what changes when a line or a withdrawal does. Pure. */
	public static function setHash(array $lines, array $withdrawn): string {
		$t = self::setText($lines, $withdrawn);
		return hash('sha256', $t['ACTIVE'] . "\0" . $t['WITHDRAWN']);
	}

	/**
	 * Merge the tree's fixes (a person's recipes, the last publish's ACTIVE)
	 * with the automatic ones: per source the higher version wins, and a
	 * withdrawn version is never offered. Pure.
	 *
	 * @param array $tree      source => line
	 * @param array $automatic source => line
	 * @param array $withdrawn source => list of versions
	 * @return array source => line
	 * @throws Exception when two lines give one version different archives
	 */
	public static function merge(array $tree, array $automatic, array $withdrawn): array {
		$out = array();
		foreach (array($tree, $automatic) as $set) {
			foreach ($set as $source => $line) {
				if (in_array($line['version'], (array)($withdrawn[$source] ?? array()), true)) {
					continue;
				}
				$have = $out[$source] ?? null;
				if ($have === null) {
					$out[$source] = $line;
					continue;
				}
				$cmp = UpstreamFixWatch::compare_versions($line['version'], $have['version']);
				if ($cmp === 0 && $line['sha256'] !== $have['sha256']) {
					throw new Exception($source . ' ' . $line['version'] . ' is two different builds (a person\'s recipe and an automatic one); '
						. 'bump the person\'s recipe revision past it');
				}
				if ($cmp > 0) {
					$out[$source] = $line;
				}
			}
		}
		ksort($out);
		return $out;
	}

	/**
	 * Issue the set: write, sign, log, record and serve it. Root, on the box
	 * that logs releases. Never throws; returns what happened.
	 *
	 * @param array $opts tests only: 'keys' (release signing keys), 'log' => callable($payload_facts): string
	 *                    (the RELEASE_STATEMENT bytes), 'previous' (payload), 'record' => callable($version, $bytes),
	 *                    'save' => false, 'state' => array, 'now' => int, 'release' => string
	 * @return array{status:string, message:string, serial:int}
	 */
	public static function issue(string $full_site_dir, array $lines, array $withdrawn, bool $wide, array $opts = array()): array {
		$now = $opts['now'] ?? time();
		$state = $opts['state'] ?? self::state();
		$dir = self::dir($full_site_dir);
		// Above both the record and what is served: a lost or restored record
		// never issues a serial machines already passed.
		$served = is_dir($dir) ? self::read($dir) : null;
		$serial = max((int)($state['serial'] ?? 0), (int)($served['serial'] ?? 0)) + 1;
		// A withdrawal once served stays withdrawn, whatever the caller holds.
		foreach ((array)($served['withdrawn'] ?? array()) as $source => $versions) {
			$withdrawn[$source] = array_values(array_unique(array_merge((array)($withdrawn[$source] ?? array()), (array)$versions)));
			foreach ((array)$versions as $v) {
				if (($lines[$source]['version'] ?? null) === $v) { unset($lines[$source]); }
			}
		}
		$stage = dirname($dir) . '/.channel.' . getmypid() . '.new';
		try {
			$release = $opts['release'] ?? trim((string)@file_get_contents(rtrim($full_site_dir, '/') . '/public_html/VERSION'));
			if (!preg_match('/^\d+\.\d+\.\d+$/', $release)) {
				throw new Exception('this tree\'s VERSION does not name a release');
			}
			if (empty($opts['skip_line_check'])) {
				foreach ($lines as $l) {
					$why = self::lineRefusal($l, rtrim($full_site_dir, '/') . '/' . PackageFixPublisher::STATIC_SUBDIR);
					if ($why !== '') {
						throw new Exception('refusing to sign the set: ' . $why);
					}
				}
			}
			foreach ($withdrawn as $source => $versions) {
				foreach ((array)$versions as $v) {
					if (!preg_match('/^[a-z0-9][a-z0-9.+-]+$/', (string)$source) || !preg_match('/^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*\+joinery[0-9]+$/', (string)$v)) {
						throw new Exception('refusing to sign the set: a withdrawal is not "<source> <our version>"');
					}
				}
			}
			self::removeDir($stage);
			if (!mkdir($stage, 0755, true)) {
				throw new Exception('cannot create ' . $stage);
			}
			$text = self::setText($lines, $withdrawn);
			$files = array(
				'ACTIVE'    => $text['ACTIVE'],
				'WITHDRAWN' => $text['WITHDRAWN'],
				'CHANNEL'   => 'serial=' . $serial . "\nissued=" . gmdate('Y-m-d\TH:i:s\Z', $now) . "\nwide=" . ($wide ? 'yes' : 'no')
					. "\nrelease=" . $release . "\n",
			);
			foreach ($files as $name => $body) {
				if (file_put_contents($stage . '/' . $name, $body) === false) {
					throw new Exception('cannot write ' . $name);
				}
				chmod($stage . '/' . $name, 0644);
			}
			$keys = $opts['keys'] ?? null;
			if ($keys === null) {
				$authority = TreeManifestPublisher::authority($full_site_dir);
				if (!$authority['may_sign']) {
					throw new Exception($authority['reason']);
				}
				$keys = $authority['keys'];
			}
			TreeManifestPublisher::write($stage, $stage, $keys);
			$body = (string)file_get_contents($stage . '/' . PackageSignature::MANIFEST_NAME);
			$subject = PackageSignature::statementSubject($body);
			$version = $release . '+fixes.' . $serial;
			if (strlen($version) > 40) {
				throw new Exception($version . ' is longer than the ledger holds (rle_version, 40)');
			}

			// The statement: logged and recorded before it is placed, as a publish does.
			if (isset($opts['log'])) {
				$statement = (string)call_user_func($opts['log'], array('version' => $version, 'subject' => $subject));
			} else {
				$statement = self::logStatement($full_site_dir, $version, $subject);
			}
			try {
				if (isset($opts['record'])) {
					call_user_func($opts['record'], $version, $statement);
				} else {
					ReleaseLogEntry::record($version, $statement);
				}
			} catch (\Throwable $e) {
				$entry = json_decode($statement, true)['entry'] ?? array();
				throw new Exception('the log holds the statement for ' . $version . ' (' . ($entry['log_origin'] ?? '?') . ' index '
					. ($entry['log_index'] ?? '?') . ') and recording it failed: ' . $e->getMessage() . '. This line is its only record');
			}
			$statement_path = $stage . '/' . PackageSignature::STATEMENT_NAME;
			if (file_put_contents($statement_path, $statement) === false) {
				throw new Exception('cannot write ' . PackageSignature::STATEMENT_NAME);
			}
			chmod($statement_path, 0644);
			$body = TreeManifestPublisher::restamp($stage, $keys, array(PackageSignature::STATEMENT_NAME => $statement_path));
			if (PackageSignature::statementSubject($body) !== $subject) {
				throw new Exception('the channel changed on disk while its statement was being logged');
			}
			self::swapIn($stage, $dir);
			if ($wide) {
				self::copyServed($dir, self::wideDir($full_site_dir));
			}
			$state = array('serial' => $serial, 'issued' => $now, 'wide' => $wide, 'release' => $release,
				'set' => self::setHash($lines, $withdrawn), 'version' => $version);
			if (($opts['save'] ?? true) !== false) {
				// The served CHANNEL is the floor the next serial starts from, so
				// a record that fails to save never repeats a serial.
				self::writeStateFile(self::STATE_FILE, $state);
			}
			return array('status' => 'issued', 'serial' => $serial, 'state' => $state,
				'message' => 'Package fix channel: issued ' . $version . ($wide ? ' to every machine' : ' to the test machines first') . ', '
					. count($lines) . ' fix(es), ' . array_sum(array_map('count', $withdrawn)) . ' withdrawn');
		} catch (\Throwable $e) {
			self::removeDir($stage);
			return array('status' => 'failed', 'serial' => (int)($state['serial'] ?? 0),
				'message' => 'Package fix channel: not issued - ' . $e->getMessage());
		}
	}

	/** Sign and log one channel statement; the RELEASE_STATEMENT bytes. */
	private static function logStatement(string $full_site_dir, string $version, string $subject): string {
		$previous = ReleaseStatementPublisher::previous();
		if ($previous === null) {
			throw new Exception('no release has been logged from here, so a channel statement has nothing to stand on');
		}
		$prev = ReleaseStatementPublisher::payloadOf($previous);
		if (!isset($prev['artifacts']['core'])) {
			throw new Exception('the newest logged release records no core manifest');
		}
		$facts = array(
			'version'        => $version,
			'core_commit'    => $prev['core_commit'] ?? null,
			'agent_commit'   => $prev['agent_commit'] ?? null,
			'go_toolchain'   => $prev['go_toolchain'] ?? null,
			'compressors'    => $prev['compressors'] ?? null,
			'artifacts'      => array('core' => $prev['artifacts']['core'], 'fixes_channel' => $subject),
			'keys_installed' => $prev['keys_installed'] ?? null,
		);
		if (isset($prev['authority'])) {
			$facts['authority'] = $prev['authority'];
		}
		$payload = ReleaseStatementPublisher::payload($facts);
		$held = ReleaseStatementPublisher::held($previous);
		$client = new ReleaseLogClient(ReleaseLogClient::repoLogKeys($full_site_dir), $held['log']);
		$shard = $client->discover();
		$key = ReleaseStatementPublisher::statementKey($full_site_dir, $previous);
		return ReleaseStatementPublisher::log($client, $shard, $payload, $key['pem'], $previous);
	}

	/**
	 * Read a channel directory: its CHANNEL fields, ACTIVE lines, withdrawals.
	 * Null when it is not whole. Says nothing about its signature: verify() does.
	 *
	 * @return array{serial:int, issued:string, wide:bool, release:string, lines:array, withdrawn:array}|null
	 */
	public static function read(string $dir): ?array {
		$ch = @file_get_contents($dir . '/CHANNEL');
		if ($ch === false) {
			return null;
		}
		$v = array();
		foreach (explode("\n", $ch) as $l) {
			if (preg_match('/^([a-z]+)=(\S+)$/', trim($l), $m)) {
				$v[$m[1]] = $m[2];
			}
		}
		if (!isset($v['serial']) || !ctype_digit($v['serial']) || !in_array($v['wide'] ?? '', array('yes', 'no'), true)) {
			return null;
		}
		$lines = PackageFixPublisher::readStamp($dir);
		if ($lines === null) {
			return null;
		}
		$withdrawn = array();
		foreach (explode("\n", (string)@file_get_contents($dir . '/WITHDRAWN')) as $l) {
			$l = trim($l);
			if ($l === '' || $l[0] === '#') {
				continue;
			}
			if (!preg_match('/^([a-z0-9][a-z0-9.+-]+) ([0-9][A-Za-z0-9.+~:-]*)$/', $l, $m)) {
				return null;
			}
			$withdrawn[$m[1]][] = $m[2];
		}
		return array('serial' => (int)$v['serial'], 'issued' => (string)($v['issued'] ?? ''), 'wide' => $v['wide'] === 'yes',
			'release' => (string)($v['release'] ?? ''), 'lines' => $lines, 'withdrawn' => $withdrawn);
	}

	/** Is this channel directory one we issued? The node's own keys and log. */
	public static function verify(string $dir, ?string $keys_file = null, array $opts = array()): PackageVerdict {
		return PackageSignature::verify($dir, $keys_file, $opts + array('fresh' => true));
	}

	/**
	 * A site other sites upgrade from serves the channel its upstream issued,
	 * once it is wide: fetched, checked as a machine checks it, its archives
	 * fetched and checked by hash, then swapped in. Root (static_files is
	 * root's), from the automatic runner. Never throws.
	 *
	 * @param array $opts tests only: 'upstream' => base URL, 'fetcher' => callable($url, $dest), 'keys_file', 'verify_opts'
	 * @return array{status:string, message:string}
	 */
	public static function mirror(string $full_site_dir, array $opts = array()): array {
		$upstream = rtrim((string)($opts['upstream'] ?? Globalvars::get_instance()->get_setting('upgrade_source')), '/');
		if ($upstream === '') {
			return array('status' => 'skipped', 'message' => 'Package fix channel: no upgrade_source to mirror from');
		}
		$dir = self::dir($full_site_dir);
		$stage = dirname($dir) . '/.channel.' . getmypid() . '.fetch';
		$fetcher = $opts['fetcher'] ?? array(self::class, 'fetchFile');
		try {
			self::removeDir($stage);
			if (!mkdir($stage, 0755, true)) {
				throw new Exception('cannot create ' . $stage);
			}
			// The last set upstream issued to every machine (channel-wide/): a
			// newer one for its test machines in channel/ never hides it.
			foreach (self::SERVED as $name) {
				call_user_func($fetcher, $upstream . '/static_files/fixes/channel-wide/' . $name, $stage . '/' . $name);
			}
			$verdict = self::verify($stage, $opts['keys_file'] ?? null, $opts['verify_opts'] ?? array());
			if (!$verdict->signed()) {
				throw new Exception($upstream . '\'s channel is not one we issued: ' . $verdict->line());
			}
			$got = self::read($stage);
			if ($got === null) {
				throw new Exception($upstream . '\'s channel is signed but not whole');
			}
			$served = is_dir($dir) ? self::read($dir) : null;
			if ($served !== null && $got['serial'] <= $served['serial']) {
				self::removeDir($stage);
				return array('status' => 'skipped', 'message' => 'Package fix channel: serving serial ' . $served['serial'] . ', upstream has nothing newer');
			}
			if (!$got['wide']) {
				self::removeDir($stage);
				return array('status' => 'skipped', 'message' => 'Package fix channel: upstream\'s serial ' . $got['serial']
					. ' is with its test machines first; still serving ' . ($served === null ? 'none' : 'serial ' . $served['serial']));
			}
			$archives = PackageFixPublisher::serveReceived($stage, rtrim($full_site_dir, '/') . '/' . PackageFixPublisher::STATIC_SUBDIR,
				null, array('upstream' => $upstream) + (isset($opts['fetcher']) ? array('fetcher' => $opts['fetcher']) : array()));
			if (($archives['status'] ?? '') === PackageFixPublisher::STATUS_FAILED) {
				throw new Exception($archives['message']);
			}
			self::swapIn($stage, $dir);
			// Served at both paths, so a site mirroring this one reads it too.
			self::copyServed($dir, self::wideDir($full_site_dir));
			return array('status' => 'mirrored', 'message' => 'Package fix channel: now serving serial ' . $got['serial']
				. ' (' . count($got['lines']) . ' fix(es))');
		} catch (\Throwable $e) {
			self::removeDir($stage);
			return array('status' => 'failed', 'message' => 'Package fix channel: not mirrored - ' . $e->getMessage());
		}
	}

	/** GET $url into $dest, or throw. */
	public static function fetchFile(string $url, string $dest): void {
		$fh = fopen($dest, 'wb');
		if ($fh === false) {
			throw new Exception('cannot write ' . $dest);
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_FAILONERROR => true,
			CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 120,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
		));
		$ok = curl_exec($ch);
		$err = curl_error($ch);
		fclose($fh);
		if (!$ok) {
			throw new Exception('could not fetch ' . $url . ': ' . $err);
		}
		chmod($dest, 0644);
	}

	/** Replace $dir with $stage in one rename; the old copy goes after. */
	private static function swapIn(string $stage, string $dir): void {
		$old = dirname($dir) . '/.channel.' . getmypid() . '.old';
		self::removeDir($old);
		if (is_dir($dir) && !rename($dir, $old)) {
			throw new Exception('cannot move the served channel aside');
		}
		if (!rename($stage, $dir)) {
			if (is_dir($old)) { @rename($old, $dir); }
			throw new Exception('cannot serve the new channel');
		}
		chmod($dir, 0755);
		self::removeDir($old);
	}

	private static function removeDir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: array() as $f) {
			if ($f !== '.' && $f !== '..') {
				@unlink($dir . '/' . $f);
			}
		}
		@rmdir($dir);
	}
}
?>
