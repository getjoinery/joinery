<?php
/**
 * PackageFixPublisher - builds each package fix recipe whose files changed, and
 * publishes it beside the release (specs/package_fixes_ahead_of_ubuntu.md WP2).
 *
 * A package fix is our build of one Ubuntu source package with upstream's
 * security patches added, for a fix Ubuntu has not shipped yet. Its recipe is
 * maintenance_scripts/install_tools/fixes/<source>/ (RECIPE and patches/; the
 * format is in fixes/build.sh). Each active recipe is built in the Ubuntu
 * 26.04 build root the owner makes once by hand (fixes/build_root.sh create),
 * as root, which publish already is, and published under static_files/fixes/.
 *
 * The tree carries fixes/ACTIVE: one line per active recipe, naming its
 * archive, the archive's SHA-256, the version, the CVEs, and the hash of the
 * recipe's files it was built from. ACTIVE ships like any other file, covered
 * by the signed manifest, so a machine that fetches an archive by that name
 * installs it only when the hash matches (install_package_fixes.sh converge).
 *
 * Behaviour contract, per recipe, SiteEnginePublisher's shape:
 * - state=none, or no RECIPE: not in ACTIVE.
 * - Ubuntu's tracker shows every CVE the recipe names released on 26.04: left
 *   out of ACTIVE, with a line saying so (Q2: no second install, and no second
 *   container replacement, for a fix Ubuntu beat us to). A tracker that cannot
 *   be read leaves it in.
 * - ACTIVE records this recipe's hash and the archive is in static_files with
 *   its hash: nothing changes.
 * - Likewise, the archive gone from static_files but in the build cache with
 *   the hash: copied back.
 * - ACTIVE records this version built from other recipe files: refused until
 *   the recipe's revision is bumped. Machines already on that version would
 *   keep the old binaries under the same number.
 * - Otherwise a build is owed: built in the root into the cache, copied to
 *   static_files, its line rewritten.
 * - Owed and not done: STATUS_FAILED, and publish_upgrade.php refuses the
 *   release, because a tree naming an archive that is not published leaves
 *   every machine unable to take the fix.
 * ACTIVE is rewritten only when what it says changed, so an unchanged set of
 * recipes leaves it byte-identical.
 *
 * A site that republishes the release it received never builds: its ACTIVE is
 * the origin's, so it fetches each archive ACTIVE names from its upstream (the
 * upgrade_source setting), keeps it only when the hash is ACTIVE's, and serves
 * it from its own static_files/fixes/.
 *
 * publish() never throws; it reports a status and the caller decides.
 *
 * @version 1.1 - reviewer2's build review (G7): a recipe that changed is refused until its revision is bumped,
 *                since machines already on that version would keep the old bytes under the same number;
 *                build.sh is no longer part of a recipe's hash, so editing the build script rebuilds nothing
 * @version 1.0
 */

class PackageFixPublisher {

	const SOURCE_SUBDIR = 'maintenance_scripts/install_tools/fixes';
	const STAMP_FILE = 'ACTIVE';
	const STATIC_SUBDIR = 'static_files/fixes';
	const CACHE_DIR = '/var/lib/joinery-package-fixes-build/out';
	const ARCHIVE_PATTERN = '/^joinery-fix-[a-z0-9][a-z0-9.+-]+-[0-9][A-Za-z0-9.+~-]*\+joinery\d+-[a-z0-9]+\.tar\.xz$/';
	/** The fields every ACTIVE line carries, in order. */
	const LINE_FIELDS = array('source', 'version', 'ubuntu_base', 'archive', 'sha256', 'recipe_sha256', 'changelog_from', 'cves');

	const STATUS_BUILT = 'built';
	const STATUS_SKIPPED = 'skipped';
	const STATUS_FETCHED = 'fetched';
	const STATUS_ABSENT = 'absent';
	const STATUS_FAILED = 'failed';

	/**
	 * Build what is owed, and make sure every archive ACTIVE names is published.
	 *
	 * @param string $full_site_dir e.g. /var/www/html/joinerytest
	 * @param callable|null $out    line-output callback (publish_output)
	 * @param array $opts           'republish' => true on a site republishing what it received;
	 *                              tests only: 'builder' => callable($recipe_dir, $build_dir): void,
	 *                              'cache_dir' => string, 'tracker' => UbuntuCveTracker,
	 *                              'upstream' => base URL, 'fetcher' => callable($url, $dest): void
	 * @return array{status:string, message:string, fixes:array}
	 *         status: built when any recipe was built or ACTIVE changed, skipped when
	 *         nothing changed, absent when there is no fixes/ directory
	 */
	public static function publish($full_site_dir, $out = null, array $opts = array()) {
		$say = function ($msg) use ($out) { if ($out) { call_user_func($out, $msg); } };
		$root = rtrim($full_site_dir, '/');
		$src = $root . '/' . self::SOURCE_SUBDIR;
		$static_dir = $root . '/' . self::STATIC_SUBDIR;
		if (!is_dir($src)) {
			$msg = 'Package fixes: no ' . self::SOURCE_SUBDIR . ' - nothing to build';
			$say($msg);
			return self::result(self::STATUS_ABSENT, $msg);
		}
		if (!empty($opts['republish'])) {
			return self::republish($src, $static_dir, $say, $opts);
		}

		$cache_dir = $opts['cache_dir'] ?? self::CACHE_DIR;
		$builder = $opts['builder'] ?? function ($recipe_dir, $dir) use ($src) { self::buildInRoot($src, $recipe_dir, $dir); };
		$tracker = $opts['tracker'] ?? new UbuntuCveTracker();
		try {
			$recorded = self::readStamp($src);
			if ($recorded === null) {
				throw new Exception('the committed ' . self::STAMP_FILE . ' is not whole; correct or remove it');
			}
			$lines = array();
			$built_any = false;
			foreach (self::recipes($src) as $source => $recipe) {
				if ($recipe['state'] !== 'active') {
					continue;
				}
				$covered = self::coveredByUbuntu($tracker, $source, $recipe['cves']);
				if ($covered !== null) {
					$say('Package fixes: ' . $source . ' left out - Ubuntu has released every CVE it carries on 26.04 (' . $covered . '); set its RECIPE to state=none');
					continue;
				}
				$recipe_hash = self::recipeHash($src, $source);
				$have = $recorded[$source] ?? null;
				if ($have !== null && $have['recipe_sha256'] === $recipe_hash) {
					if (self::fileHas($static_dir . '/' . $have['archive'], $have['sha256'])) {
						$say('Package fixes: ' . $have['archive'] . ' already published for this recipe - unchanged');
						$lines[$source] = $have;
						continue;
					}
					$cached = rtrim($cache_dir, '/') . '/' . $have['archive'];
					if (self::fileHas($cached, $have['sha256'])) {
						self::place($cached, $static_dir, $have['archive']);
						$say('Package fixes: ' . $have['archive'] . ' copied back to ' . self::STATIC_SUBDIR . ' from the build cache');
						$lines[$source] = $have;
						continue;
					}
				}
				if ($have !== null && $have['version'] === $recipe['version'] && $have['recipe_sha256'] !== $recipe_hash) {
					throw new Exception($source . '/RECIPE changed since ' . $recipe['version'] . ' was published; bump its revision, so machines that have '
						. $recipe['version'] . ' take the new build');
				}
				$say('Package fixes: building ' . $source . ' ' . $recipe['version'] . ' ('
					. ($have === null ? 'new recipe' : ($have['recipe_sha256'] !== $recipe_hash ? 'its recipe changed' : 'the recorded archive is gone'))
					. '; a large source takes an hour with its tests)');
				$lines[$source] = self::build($src, $source, $recipe, $recipe_hash, $cache_dir, $static_dir, $builder);
				$say('Package fixes: built ' . $lines[$source]['archive'] . ' (' . round(filesize($static_dir . '/' . $lines[$source]['archive']) / 1048576, 1) . ' MB), published to ' . self::STATIC_SUBDIR);
				$built_any = true;
			}
			ksort($lines);
			$text = self::stampText($lines);
			$changed = @file_get_contents($src . '/' . self::STAMP_FILE) !== $text;
			if ($changed) {
				self::writeAtomic($src . '/' . self::STAMP_FILE, $text);
			}
			$msg = 'Package fixes: ' . (count($lines) === 0 ? 'none active' : count($lines) . ' active (' . implode(', ', array_keys($lines)) . ')')
				. ($changed ? ', ' . self::STAMP_FILE . ' rewritten' : '');
			$say($msg);
			return self::result(($built_any || $changed) ? self::STATUS_BUILT : self::STATUS_SKIPPED, $msg, array_values($lines));
		} catch (\Throwable $e) {
			$msg = 'Package fixes: FAILED - ' . $e->getMessage();
			$say($msg);
			return self::result(self::STATUS_FAILED, $msg);
		}
	}

	/**
	 * Every recipe directory, by source: state, version, cves, changelog_from,
	 * ubuntu_base. A recipe that is not whole throws, naming what is wrong.
	 *
	 * @return array<string, array{state:string, version:string, ubuntu_base:string, cves:string, changelog_from:string}>
	 */
	public static function recipes($src) {
		$out = array();
		foreach (glob(rtrim($src, '/') . '/*/RECIPE') ?: array() as $path) {
			$source = basename(dirname($path));
			$v = self::keyValues((string)file_get_contents($path));
			$state = $v['state'] ?? '';
			if ($state === 'none') {
				$out[$source] = array('state' => 'none', 'version' => '', 'ubuntu_base' => '', 'cves' => '', 'changelog_from' => '');
				continue;
			}
			if ($state !== 'active') {
				throw new Exception($source . '/RECIPE: state must be active or none');
			}
			$parts = preg_split('/\s+/', trim($v['ubuntu_source'] ?? ''));
			if (count($parts) !== 2 || $parts[0] !== $source || !preg_match('/^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*$/', $parts[1])) {
				throw new Exception($source . '/RECIPE: ubuntu_source must be "' . $source . ' <version>"');
			}
			if (!preg_match('/^[1-9]\d*$/', $v['revision'] ?? '')) {
				throw new Exception($source . '/RECIPE: revision must be a whole number from 1');
			}
			$cves = preg_split('/\s+/', trim($v['cves'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
			foreach ($cves as $cve) {
				if (!preg_match('/^CVE-\d{4}-\d{4,}$/', $cve)) {
					throw new Exception($source . '/RECIPE: "' . $cve . '" is not a CVE id');
				}
			}
			if (count($cves) === 0) {
				throw new Exception($source . '/RECIPE: names no CVE');
			}
			if (!preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $v['changelog_from'] ?? '')) {
				throw new Exception($source . '/RECIPE: changelog_from must name a binary package');
			}
			$out[$source] = array('state' => 'active', 'version' => $parts[1] . '+joinery' . $v['revision'], 'ubuntu_base' => $parts[1],
				'cves' => implode(' ', $cves), 'changelog_from' => $v['changelog_from']);
		}
		ksort($out);
		return $out;
	}

	/**
	 * Null when the tracker does not show every CVE released on 26.04 (or cannot
	 * be read); otherwise the version Ubuntu fixed them in.
	 */
	protected static function coveredByUbuntu(UbuntuCveTracker $tracker, $source, $cves) {
		$versions = array();
		foreach (explode(' ', $cves) as $cve) {
			$st = $tracker->cveStatus($cve, $source);
			if ($st === null || $st['status'] !== 'released') {
				return null;
			}
			$versions[$st['version']] = true;
		}
		return implode(', ', array_keys($versions));
	}

	/** Build one recipe into the cache and publish it; its ACTIVE line. */
	protected static function build($src, $source, array $recipe, $recipe_hash, $cache_dir, $static_dir, $builder) {
		if (!is_dir($cache_dir) && !@mkdir($cache_dir, 0755, true)) {
			throw new Exception('cannot create the build cache ' . $cache_dir);
		}
		// Each build writes into a folder of its own, so the archive it made is
		// the one archive there.
		$build_dir = $cache_dir . '/.build-' . $source . '-' . getmypid() . '-' . time();
		if (!@mkdir($build_dir, 0755)) {
			throw new Exception('cannot create ' . $build_dir);
		}
		try {
			call_user_func($builder, rtrim($src, '/') . '/' . $source, $build_dir);
			$names = array();
			foreach (glob($build_dir . '/joinery-fix-*.tar.xz') ?: array() as $p) {
				$names[] = basename($p);
			}
			if (count($names) !== 1) {
				throw new Exception($source . ': the build left ' . count($names) . ' archives, not one');
			}
			$name = $names[0];
			$want = 'joinery-fix-' . $source . '-' . preg_replace('/^\d+:/', '', $recipe['version']) . '-';
			if (strpos($name, $want) !== 0 || !preg_match(self::ARCHIVE_PATTERN, $name)) {
				throw new Exception($source . ': the build made ' . $name . ', not ' . $want . '<arch>.tar.xz');
			}
			$sha = self::readSidecar($build_dir . '/' . $name);
			if ($sha === null || !self::fileHas($build_dir . '/' . $name, $sha)) {
				throw new Exception($name . ' does not match the SHA-256 its build wrote beside it');
			}
			$archive = $cache_dir . '/' . $name;
			if (!rename($build_dir . '/' . $name . '.sha256', $archive . '.sha256') || !rename($build_dir . '/' . $name, $archive)) {
				throw new Exception('cannot move ' . $name . ' into the build cache');
			}
		} finally {
			exec('rm -rf ' . escapeshellarg($build_dir));
		}
		self::place($archive, $static_dir, $name);
		return array('source' => $source, 'version' => $recipe['version'], 'ubuntu_base' => $recipe['ubuntu_base'], 'archive' => $name,
			'sha256' => $sha, 'recipe_sha256' => $recipe_hash, 'changelog_from' => $recipe['changelog_from'], 'cves' => $recipe['cves']);
	}

	/**
	 * On a republishing site: serve each archive the received ACTIVE names,
	 * fetched from upstream and kept only when its hash is ACTIVE's.
	 */
	protected static function republish($src, $static_dir, $say, array $opts) {
		try {
			$lines = self::readStamp($src);
			if ($lines === null) {
				throw new Exception('the received ' . self::STAMP_FILE . ' is not whole');
			}
			if (count($lines) === 0) {
				$msg = 'Package fixes: the received release names none - nothing to serve';
				$say($msg);
				return self::result(self::STATUS_SKIPPED, $msg);
			}
			$fetched = 0;
			foreach ($lines as $line) {
				if (self::fileHas($static_dir . '/' . $line['archive'], $line['sha256'])) {
					continue;
				}
				$upstream = $opts['upstream'] ?? (string)Globalvars::get_instance()->get_setting('upgrade_source');
				if ($upstream === '') {
					throw new Exception('no upgrade_source to fetch ' . $line['archive'] . ' from');
				}
				$url = rtrim($upstream, '/') . '/' . self::STATIC_SUBDIR . '/' . rawurlencode($line['archive']);
				$fetcher = $opts['fetcher'] ?? array(self::class, 'fetch');
				if (!is_dir($static_dir) && !@mkdir($static_dir, 0755, true)) {
					throw new Exception('cannot create ' . $static_dir);
				}
				$tmp = $static_dir . '/.' . $line['archive'] . '.fetch';
				try {
					call_user_func($fetcher, $url, $tmp);
					if (!self::fileHas($tmp, $line['sha256'])) {
						throw new Exception($url . ' is not the archive the received ' . self::STAMP_FILE . ' names (its SHA-256 differs)');
					}
					if (!chmod($tmp, 0644) || !rename($tmp, $static_dir . '/' . $line['archive'])) {
						throw new Exception('cannot serve ' . $line['archive'] . ' from ' . $static_dir);
					}
				} finally {
					@unlink($tmp);
				}
				file_put_contents($static_dir . '/' . $line['archive'] . '.sha256', $line['sha256'] . '  ' . $line['archive'] . "\n");
				@chmod($static_dir . '/' . $line['archive'] . '.sha256', 0644);
				$fetched++;
			}
			$msg = 'Package fixes: ' . count($lines) . ' named by the received release, ' . $fetched . ' fetched and checked, all served';
			$say($msg);
			return self::result($fetched > 0 ? self::STATUS_FETCHED : self::STATUS_SKIPPED, $msg, array_values($lines));
		} catch (\Throwable $e) {
			$msg = 'Package fixes: FAILED - ' . $e->getMessage();
			$say($msg);
			return self::result(self::STATUS_FAILED, $msg);
		}
	}

	/**
	 * Hash of the files whose bytes decide one recipe's build: RECIPE and every
	 * patch. Not build.sh: a change to how builds are made must not rebuild a
	 * published version under the same number.
	 */
	public static function recipeHash($src, $source) {
		$dir = rtrim($src, '/') . '/' . $source;
		$parts = array('RECIPE:' . hash_file('sha256', $dir . '/RECIPE'));
		$patches = glob($dir . '/patches/*.patch') ?: array();
		sort($patches, SORT_STRING);
		foreach ($patches as $p) {
			$parts[] = 'patches/' . basename($p) . ':' . hash_file('sha256', $p);
		}
		return hash('sha256', implode("\n", $parts));
	}

	/**
	 * What ACTIVE records, by source: an empty array when it is absent or names
	 * none, null when a line is not whole.
	 *
	 * @return array<string, array<string,string>>|null
	 */
	public static function readStamp($src) {
		$raw = @file_get_contents(rtrim($src, '/') . '/' . self::STAMP_FILE);
		if ($raw === false) {
			return array();
		}
		$out = array();
		foreach (explode("\n", $raw) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') {
				continue;
			}
			$v = self::parseLine($line);
			if ($v === null) {
				return null;
			}
			$out[$v['source']] = $v;
		}
		return $out;
	}

	/** One ACTIVE line's fields, or null when it is not whole. */
	public static function parseLine($line) {
		$v = array();
		// cves= is last and holds spaces; every other value is one token.
		if (!preg_match('/^(.*?)\s+cves=(CVE-\d{4}-\d{4,}(?: CVE-\d{4}-\d{4,})*)$/', trim($line), $m)) {
			return null;
		}
		foreach (preg_split('/\s+/', $m[1]) as $tok) {
			if (!preg_match('/^([a-z0-9_]+)=(\S+)$/', $tok, $kv)) {
				return null;
			}
			$v[$kv[1]] = $kv[2];
		}
		$v['cves'] = $m[2];
		foreach (self::LINE_FIELDS as $f) {
			if (!isset($v[$f])) {
				return null;
			}
		}
		if (!preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $v['source']) || !preg_match(self::ARCHIVE_PATTERN, $v['archive'])
			|| !preg_match('/^[0-9a-f]{64}$/', $v['sha256']) || !preg_match('/^[0-9a-f]{64}$/', $v['recipe_sha256'])
			|| strpos($v['archive'], 'joinery-fix-' . $v['source'] . '-') !== 0) {
			return null;
		}
		return $v;
	}

	public static function stampText(array $lines) {
		$text = "# Written by the publish (PackageFixPublisher): the package fixes this tree's machines take,\n"
			. "# published beside the release under static_files/fixes/. Do not edit.\n";
		foreach ($lines as $line) {
			$parts = array();
			foreach (self::LINE_FIELDS as $f) {
				$parts[] = $f . '=' . $line[$f];
			}
			$text .= implode(' ', $parts) . "\n";
		}
		return $text;
	}

	/** Run one recipe's build in the owner-made build root, as root. */
	protected static function buildInRoot($src, $recipe_dir, $build_dir) {
		$script = rtrim($src, '/') . '/build_root.sh';
		exec('bash ' . escapeshellarg($script) . ' check 2>&1', $check_out, $check_code);
		if ($check_code !== 0) {
			throw new Exception(implode(' ', $check_out));
		}
		exec('bash ' . escapeshellarg($script) . ' build ' . escapeshellarg($recipe_dir) . ' ' . escapeshellarg($build_dir) . ' 2>&1', $build_out, $code);
		if ($code !== 0) {
			throw new Exception(basename($recipe_dir) . ': the build exited ' . $code . ': ' . implode(' | ', array_slice($build_out, -5)));
		}
	}

	/** Download $url to $dest. The caller checks the bytes against ACTIVE. */
	protected static function fetch($url, $dest) {
		$fh = fopen($dest, 'wb');
		if ($fh === false) {
			throw new Exception('cannot write ' . $dest);
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FILE => $fh,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_FAILONERROR => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 900,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
		));
		$ok = curl_exec($ch);
		$err = curl_error($ch);
		fclose($fh);
		if (!$ok) {
			throw new Exception('could not fetch ' . $url . ': ' . $err);
		}
	}

	protected static function keyValues($text) {
		$v = array();
		foreach (explode("\n", $text) as $line) {
			if (preg_match('/^([a-z0-9_]+)=(.*)$/', trim($line), $m)) {
				$v[$m[1]] = trim($m[2]);
			}
		}
		return $v;
	}

	protected static function result($status, $message, array $fixes = array()) {
		return array('status' => $status, 'message' => $message, 'fixes' => $fixes);
	}

	protected static function readSidecar($archive) {
		$raw = @file_get_contents($archive . '.sha256');
		if ($raw === false || !preg_match('/^([0-9a-f]{64})\s+' . preg_quote(basename($archive), '/') . '\s*$/', trim($raw), $m)) {
			return null;
		}
		return $m[1];
	}

	protected static function fileHas($path, $sha) {
		return is_file($path) && hash_file('sha256', $path) === $sha;
	}

	protected static function writeAtomic($path, $text) {
		if (file_put_contents($path . '.new', $text) === false || !chmod($path . '.new', 0644) || !rename($path . '.new', $path)) {
			@unlink($path . '.new');
			throw new Exception('cannot write ' . basename($path));
		}
	}

	/** Copy $from into $dir as $name, in one rename, with its .sha256 beside it. */
	protected static function place($from, $dir, $name) {
		if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
			throw new Exception('cannot create ' . $dir);
		}
		$tmp = $dir . '/.' . $name . '.new';
		if (!copy($from, $tmp) || !chmod($tmp, 0644) || !rename($tmp, $dir . '/' . $name)) {
			@unlink($tmp);
			throw new Exception('cannot publish ' . $name . ' to ' . $dir);
		}
		if (is_file($from . '.sha256')) {
			copy($from . '.sha256', $dir . '/' . $name . '.sha256');
			@chmod($dir . '/' . $name . '.sha256', 0644);
		}
	}
}
?>
