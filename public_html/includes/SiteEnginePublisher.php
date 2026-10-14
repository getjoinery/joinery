<?php
/**
 * SiteEnginePublisher - builds the site container engine at publish time when
 * its pinned sources changed, and publishes it beside the release
 * (specs/site_container_engine.md D1, D2).
 *
 * The engine is our own build of Podman, netavark and aardvark-dns, from the
 * commits maintenance_scripts/install_tools/site_engine/versions.env pins. It
 * is too large to ride the core archive for a file only container hosts use
 * (16 MB, against every site's upgrade download), so it is published under
 * static_files/site_engine/, and the tree carries site_engine/ENGINE: the
 * archive's name, its SHA-256 and the source it was built from. ENGINE ships
 * like any other file, hashed and covered by the signed manifest, so a host
 * that fetches the archive by that name installs it only when the hash matches
 * (install_site_engine.sh converge).
 *
 * The build runs in the Ubuntu 26.04 build root the owner makes once by hand
 * (site_engine/build_root.sh create), as root, which publish already is.
 *
 * Behaviour contract, GoBinaryPublisher's shape:
 * - ENGINE records this source and the archive is in static_files with its
 *   hash: nothing changes, ENGINE stays byte-identical.
 * - ENGINE records this source, the archive is gone from static_files but the
 *   build cache holds it with the hash: it is copied back, ENGINE unchanged.
 * - Otherwise a build is owed: built in the root into the cache, copied to
 *   static_files, ENGINE rewritten.
 * - Owed and not done (no build root, a failed build, an archive that is not
 *   what the build said): STATUS_FAILED. publish_upgrade.php refuses the
 *   release: a tree naming an engine that is not published would leave every
 *   host unable to install it.
 * - No site_engine/ in the tree: STATUS_ABSENT.
 *
 * A site that republishes the release it received (getjoinery.com, which
 * upgrades itself from the origin and serves what it runs) never builds: its
 * ENGINE is the origin's, received and signed with the rest of the tree, so it
 * fetches the archive ENGINE names from its upstream (the upgrade_source
 * setting), keeps it only when the hash is ENGINE's, and serves it from its own
 * static_files/site_engine/. A received ENGINE it cannot serve the archive for
 * is STATUS_FAILED, for the same reason as a build owed and not done.
 *
 * publish() never throws; it reports a status and the caller decides.
 *
 * @version 1.0
 */

class SiteEnginePublisher {

	const SOURCE_SUBDIR = 'maintenance_scripts/install_tools/site_engine';
	/** The files whose bytes decide the engine: the pins and the build. */
	const SOURCE_FILES = array('versions.env', 'build.sh');
	const STAMP_FILE = 'ENGINE';
	const STATIC_SUBDIR = 'static_files/site_engine';
	const CACHE_DIR = '/var/lib/joinery-site-engine-build/out';
	const ARCHIVE_PATTERN = '/^joinery-site-engine-\d+\.\d+\.\d+-[a-z0-9]+\.tar\.xz$/';

	const STATUS_BUILT = 'built';
	const STATUS_SKIPPED = 'skipped';
	const STATUS_RESTORED = 'restored';
	const STATUS_FETCHED = 'fetched';
	const STATUS_ABSENT = 'absent';
	const STATUS_FAILED = 'failed';

	/**
	 * Build (or leave alone) the engine, and make sure what ENGINE names is
	 * published.
	 *
	 * @param string $full_site_dir e.g. /var/www/html/joinerytest
	 * @param callable|null $out    line-output callback (publish_output)
	 * @param array $opts           'republish' => true on a site republishing what it received;
	 *                              tests only: 'builder' => callable($cache_dir): void, 'cache_dir' => string,
	 *                              'upstream' => base URL, 'fetcher' => callable($url, $dest): void
	 * @return array{status:string, message:string, archive:?string, sha256:?string}
	 */
	public static function publish($full_site_dir, $out = null, array $opts = array()) {
		$say = function ($msg) use ($out) { if ($out) { call_user_func($out, $msg); } };
		$result = function ($status, $message, $archive = null, $sha = null) {
			return array('status' => $status, 'message' => $message, 'archive' => $archive, 'sha256' => $sha);
		};
		$root = rtrim($full_site_dir, '/');
		$src = $root . '/' . self::SOURCE_SUBDIR;
		$static_dir = $root . '/' . self::STATIC_SUBDIR;
		$cache_dir = $opts['cache_dir'] ?? self::CACHE_DIR;
		$builder = $opts['builder'] ?? function ($dir) use ($src) { self::buildInRoot($src, $dir); };
		if (!empty($opts['republish'])) {
			return self::republish($src, $static_dir, $say, $result, $opts);
		}

		try {
			foreach (self::SOURCE_FILES as $f) {
				if (!is_file($src . '/' . $f)) {
					$msg = 'Site engine: no ' . $f . ' at ' . $src . ' - nothing to build';
					$say($msg);
					return $result(self::STATUS_ABSENT, $msg);
				}
			}
			$source_hash = self::sourceHash($src);
			$stamp = self::readStamp($src);

			if ($stamp !== null && $stamp['source_sha256'] === $source_hash) {
				$published = $static_dir . '/' . $stamp['archive'];
				if (self::fileHas($published, $stamp['sha256'])) {
					$msg = 'Site engine: ' . $stamp['archive'] . ' already published for this source - unchanged';
					$say($msg);
					return $result(self::STATUS_SKIPPED, $msg, $stamp['archive'], $stamp['sha256']);
				}
				$cached = rtrim($cache_dir, '/') . '/' . $stamp['archive'];
				if (self::fileHas($cached, $stamp['sha256'])) {
					self::place($cached, $static_dir, $stamp['archive']);
					$msg = 'Site engine: ' . $stamp['archive'] . ' copied back to ' . self::STATIC_SUBDIR . ' from the build cache';
					$say($msg);
					return $result(self::STATUS_RESTORED, $msg, $stamp['archive'], $stamp['sha256']);
				}
			}
		} catch (\Throwable $e) {
			$msg = 'Site engine: FAILED - ' . $e->getMessage();
			$say($msg);
			return $result(self::STATUS_FAILED, $msg);
		}

		// Past this point a build is owed: ENGINE does not describe a published
		// engine built from this source.
		try {
			$say('Site engine: building (' . ($stamp === null ? 'no engine recorded' : ($stamp['source_sha256'] !== $source_hash ? 'versions.env or build.sh changed' : 'the recorded archive is gone')) . '; about ten minutes)');
			if (!is_dir($cache_dir) && !@mkdir($cache_dir, 0755, true)) {
				throw new Exception('cannot create the build cache ' . $cache_dir);
			}
			// Each build writes into a folder of its own, so the archive it made is
			// the one archive there, never a guess among older ones in the cache.
			$build_dir = $cache_dir . '/.build-' . getmypid() . '-' . time();
			if (!@mkdir($build_dir, 0755)) {
				throw new Exception('cannot create ' . $build_dir);
			}
			try {
				call_user_func($builder, $build_dir);
				$names = array_keys(self::archivesIn($build_dir));
				if (count($names) !== 1) {
					throw new Exception('the build left ' . count($names) . ' engine archives, not one');
				}
				$name = $names[0];
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
			$stamp_path = $src . '/' . self::STAMP_FILE;
			if (file_put_contents($stamp_path . '.new', self::stampText($source_hash, $name, $sha)) === false
				|| !chmod($stamp_path . '.new', 0644) || !rename($stamp_path . '.new', $stamp_path)) {
				throw new Exception('cannot write ' . self::STAMP_FILE);
			}
			$msg = 'Site engine: built ' . $name . ' (' . round(filesize($archive) / 1048576, 1) . ' MB), published to ' . self::STATIC_SUBDIR;
			$say($msg);
			return $result(self::STATUS_BUILT, $msg, $name, $sha);
		} catch (\Throwable $e) {
			$msg = 'Site engine: FAILED to build - ' . $e->getMessage();
			$say($msg);
			return $result(self::STATUS_FAILED, $msg);
		}
	}

	/**
	 * On a republishing site: serve the archive the received ENGINE names,
	 * fetched from upstream and kept only when its hash is ENGINE's.
	 */
	protected static function republish($src, $static_dir, $say, $result, array $opts) {
		try {
			if (!file_exists($src . '/' . self::STAMP_FILE)) {
				$msg = 'Site engine: the received release names no engine - nothing to serve';
				$say($msg);
				return $result(self::STATUS_ABSENT, $msg);
			}
			$stamp = self::readStamp($src);
			if ($stamp === null) {
				throw new Exception('the received ' . self::STAMP_FILE . ' is not whole');
			}
			if (self::fileHas($static_dir . '/' . $stamp['archive'], $stamp['sha256'])) {
				$msg = 'Site engine: ' . $stamp['archive'] . ' already served - unchanged';
				$say($msg);
				return $result(self::STATUS_SKIPPED, $msg, $stamp['archive'], $stamp['sha256']);
			}
			$upstream = $opts['upstream'] ?? (string)Globalvars::get_instance()->get_setting('upgrade_source');
			if ($upstream === '') {
				throw new Exception('no upgrade_source to fetch ' . $stamp['archive'] . ' from');
			}
			$url = rtrim($upstream, '/') . '/' . self::STATIC_SUBDIR . '/' . rawurlencode($stamp['archive']);
			$fetcher = $opts['fetcher'] ?? array(self::class, 'fetch');
			if (!is_dir($static_dir) && !@mkdir($static_dir, 0755, true)) {
				throw new Exception('cannot create ' . $static_dir);
			}
			$tmp = $static_dir . '/.' . $stamp['archive'] . '.fetch';
			try {
				call_user_func($fetcher, $url, $tmp);
				if (!self::fileHas($tmp, $stamp['sha256'])) {
					throw new Exception($url . ' is not the archive the received ' . self::STAMP_FILE . ' names (its SHA-256 differs)');
				}
				if (!chmod($tmp, 0644) || !rename($tmp, $static_dir . '/' . $stamp['archive'])) {
					throw new Exception('cannot serve ' . $stamp['archive'] . ' from ' . $static_dir);
				}
			} finally {
				@unlink($tmp);
			}
			file_put_contents($static_dir . '/' . $stamp['archive'] . '.sha256', $stamp['sha256'] . '  ' . $stamp['archive'] . "\n");
			@chmod($static_dir . '/' . $stamp['archive'] . '.sha256', 0644);
			$msg = 'Site engine: ' . $stamp['archive'] . ' fetched from ' . $upstream . ', checked against the received ' . self::STAMP_FILE . ', served';
			$say($msg);
			return $result(self::STATUS_FETCHED, $msg, $stamp['archive'], $stamp['sha256']);
		} catch (\Throwable $e) {
			$msg = 'Site engine: FAILED - ' . $e->getMessage();
			$say($msg);
			return $result(self::STATUS_FAILED, $msg);
		}
	}

	/** Download $url to $dest. The caller checks the bytes against ENGINE. */
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
		curl_close($ch);
		fclose($fh);
		if (!$ok) {
			throw new Exception('could not fetch ' . $url . ': ' . $err);
		}
	}

	/** Hash of the files whose bytes decide the engine. */
	public static function sourceHash($src) {
		$parts = array();
		foreach (self::SOURCE_FILES as $f) {
			$parts[] = $f . ':' . hash_file('sha256', rtrim($src, '/') . '/' . $f);
		}
		return hash('sha256', implode("\n", $parts));
	}

	/**
	 * What ENGINE records, or null when there is none or it is not whole.
	 *
	 * @return array{source_sha256:string, archive:string, sha256:string}|null
	 */
	public static function readStamp($src) {
		$raw = @file_get_contents(rtrim($src, '/') . '/' . self::STAMP_FILE);
		if ($raw === false) {
			return null;
		}
		$v = array();
		foreach (explode("\n", $raw) as $line) {
			if (preg_match('/^([a-z0-9_]+)=(\S+)$/', trim($line), $m)) {
				$v[$m[1]] = $m[2];
			}
		}
		if (!preg_match('/^[0-9a-f]{64}$/', $v['source_sha256'] ?? '') || !preg_match('/^[0-9a-f]{64}$/', $v['sha256'] ?? '')
			|| !preg_match(self::ARCHIVE_PATTERN, $v['archive'] ?? '')) {
			return null;
		}
		return array('source_sha256' => $v['source_sha256'], 'archive' => $v['archive'], 'sha256' => $v['sha256']);
	}

	public static function stampText($source_hash, $archive, $sha) {
		return "# Written by the publish (SiteEnginePublisher): the engine this tree's hosts install,\n"
			. "# published beside the release under static_files/site_engine/. Do not edit.\n"
			. "source_sha256={$source_hash}\narchive={$archive}\nsha256={$sha}\n";
	}

	/** Run the build in the owner-made build root, as root. */
	protected static function buildInRoot($src, $cache_dir) {
		$script = $src . '/build_root.sh';
		exec('bash ' . escapeshellarg($script) . ' check 2>&1', $check_out, $check_code);
		if ($check_code !== 0) {
			throw new Exception(implode(' ', $check_out));
		}
		exec('bash ' . escapeshellarg($script) . ' build ' . escapeshellarg($cache_dir) . ' 2>&1', $build_out, $code);
		if ($code !== 0) {
			throw new Exception('the build exited ' . $code . ': ' . implode(' | ', array_slice($build_out, -5)));
		}
	}

	/** @return array<string,true> engine archive names in $dir */
	protected static function archivesIn($dir) {
		$names = array();
		foreach (glob(rtrim($dir, '/') . '/joinery-site-engine-*.tar.xz') ?: array() as $path) {
			if (preg_match(self::ARCHIVE_PATTERN, basename($path))) {
				$names[basename($path)] = true;
			}
		}
		return $names;
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
