<?php
/**
 * PackageFixRecipeWriter - writes the recipe for a package fix nobody wrote by
 * hand: upstream's fix commits on Ubuntu's build, in build.sh's recipe format
 * (specs/package_fixes_automatic.md WP3, WP4).
 *
 * Run by the automatic runner (WP9) as root. It writes only under AUTO_DIR
 * (AUTO_DIR/<source>/RECIPE and patches/), never the tree's fixes/:
 *
 *   - the base is the Ubuntu build every node runs (the caller names it);
 *     its .dsc's SHA-256 and its unpacked source come from the build root
 *     (build_root.sh source);
 *   - a person's recipe in fixes/<source>/ that is active on the same base is
 *     the starting point: its patches first, its CVEs kept; then this
 *     source's earlier automatic patches on the same base;
 *   - each CVE asked for: its upstream commits (UpstreamFixFinder), each a
 *     patch with an Origin: line. They are applied in order, with no fuzz, to
 *     a copy of the files they touch from Ubuntu's unpacked source, after
 *     every patch kept before them; a CVE whose patches do not apply is left
 *     out and its reason given;
 *   - when no CVE asked for could be carried and upstream publishes a signed
 *     release (fixes/UPSTREAMS release_url and sig_url), the recipe instead
 *     rebuilds on that release (upstream_release=, WP4);
 *   - the revision is one above the person's, the caller's floor and the
 *     last automatic one; nothing is written when the recipe would come out
 *     as it already is.
 *
 * @version 1.2 - reviewer2's re-review: the automatic recipes' folder is made passable (0755) and the dry-run
 *                checks nobody can reach the work tree before it reads a failure as the patch's (B19)
 * @version 1.1 - reviewer2's review: upstream's patch is test-applied as nobody when the runner is root (B13)
 * @version 1.0
 */
class PackageFixRecipeWriter {

	const AUTO_DIR = '/var/lib/joinery-package-fixes-build/auto';

	/** The longest an apt fetch and unpack of one source may take. */
	const SOURCE_TIMEOUT = 3600;

	/**
	 * Tests only: fn(string $source): string standing in for the build root's
	 * answer in newestUbuntuVersion() (it may throw, as the real one does).
	 *
	 * @var callable|null
	 */
	public static $newest_provider = null;

	/**
	 * The newest Ubuntu version of $source on 26.04 (its -security pocket, else
	 * the release pocket), as the build root's deb-src indexes list it
	 * (build_root.sh newest): the ubuntu_version write() is given.
	 *
	 * @throws RuntimeException when the build root cannot tell
	 */
	public static function newestUbuntuVersion(string $source): string {
		if (self::$newest_provider !== null) {
			$v = (string)call_user_func(self::$newest_provider, $source);
		} else {
			if (!preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $source)) {
				throw new RuntimeException("'{$source}' is not a source package name");
			}
			$script = PathHelper::getSiteRoot() . '/' . PackageFixPublisher::SOURCE_SUBDIR . '/build_root.sh';
			$err_file = tempnam(sys_get_temp_dir(), 'package-fix-newest-');
			$proc = proc_open(array('timeout', '600', 'bash', $script, 'newest', $source), array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $err_file, 'w')),
				$pipes, null, array('PATH' => '/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin', 'LC_ALL' => 'C'));
			if (!is_resource($proc)) {
				@unlink($err_file);
				throw new RuntimeException('could not run build_root.sh newest');
			}
			$v = trim((string)stream_get_contents($pipes[1]));
			fclose($pipes[1]);
			$code = proc_close($proc);
			$err = trim((string)@file_get_contents($err_file));
			@unlink($err_file);
			if ($code !== 0) {
				throw new RuntimeException("the newest Ubuntu version of {$source} could not be read: " . ($err !== '' ? $err : "exit {$code}"));
			}
		}
		if (!preg_match('/^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*$/', $v) || strpos($v, '+joinery') !== false) {
			throw new RuntimeException("the build root gave '{$v}' as the newest Ubuntu version of {$source}, which is not an Ubuntu package version");
		}
		return $v;
	}

	/**
	 * Write (or leave as it is) the automatic recipe for $source.
	 *
	 * @param array $cves  cve => array('fixed_in' => '8.5.13, 8.4.20', 'ids' => array('GHSA-...'))
	 * @param array $opts  ubuntu_version  required: the Ubuntu build to patch ('8.5.4-0ubuntu1.6')
	 *                     min_revision    a revision the new one must be above (a channel's last)
	 *                     base_upstream   default: read from ubuntu_version
	 *                     changelog_from  used when no earlier recipe names one
	 *                     tests only: auto_dir, fixes_dir, upstreams_file, source_dir, date,
	 *                     provider fn(source, version, out_dir): array{dsc_sha256, tree}|array{error},
	 *                     finder fn(source, cve, ctx): UpstreamFixFinder::find()'s answer,
	 *                     finder_ctx (merged into each find's ctx)
	 * @return array{status:string, dir:string, version:string, revision:int, carried:list<string>,
	 *               failed:array<string,string>, fallback:?string, reason:string}
	 *         status: written | unchanged | failed. carried: every CVE the recipe carries
	 *         (a person's kept ones too). failed: each CVE asked for that no patch carries, and
	 *         why (still listed when the upstream release carries it: fallback = 'upstream_release').
	 *         reason: when status is failed, why, in plain words.
	 */
	public static function write(string $source, array $cves, array $opts): array {
		$auto_root = rtrim((string)($opts['auto_dir'] ?? self::AUTO_DIR), '/');
		$dir = $auto_root . '/' . $source;
		$res = array('status' => 'failed', 'dir' => $dir, 'version' => '', 'revision' => 0, 'carried' => array(), 'failed' => array(), 'fallback' => null, 'reason' => '');
		$fail_all = function (array $list, string $why) use (&$res) {
			foreach ($list as $cve) { $res['failed'][$cve] = $why; }
			$res['reason'] = $why;
			return $res;
		};
		$ubuntu = (string)($opts['ubuntu_version'] ?? '');
		if (!preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $source)) {
			return $fail_all(array_keys($cves), "'{$source}' is not a source package name");
		}
		if (!preg_match('/^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*$/', $ubuntu)) {
			return $fail_all(array_keys($cves), "the Ubuntu build to patch is not a package version ('{$ubuntu}')");
		}
		foreach (array_keys($cves) as $cve) {
			if (!preg_match('/^CVE-\d{4}-\d{4,}$/', (string)$cve)) {
				return $fail_all(array_keys($cves), "'{$cve}' is not a CVE id");
			}
		}
		$fixes_dir = rtrim((string)($opts['fixes_dir'] ?? PathHelper::getSiteRoot() . '/' . PackageFixPublisher::SOURCE_SUBDIR), '/');
		$upstreams_file = (string)($opts['upstreams_file'] ?? $fixes_dir . '/UPSTREAMS');
		$up = UpstreamFixFinder::upstreams($upstreams_file)[$source] ?? null;
		$base_upstream = (string)($opts['base_upstream'] ?? UpstreamFixFinder::upstream_of($ubuntu));

		// --- What the recipe starts from --------------------------------------
		$person = self::read_recipe($fixes_dir . '/' . $source);
		$auto = self::read_recipe($dir);
		$patches = array();      // list of array(suffix, text, origin)
		$kept_cves = array();
		if ($person && $person['state'] === 'active' && $person['version'] === $ubuntu) {
			foreach ($person['patches'] as $name => $text) {
				$patches[] = array(preg_replace('/^\d{4}-/', '', $name), $text, self::origin($text));
			}
			$kept_cves = $person['cves'];
		}
		$want = $cves;
		if ($auto && $auto['version'] === $ubuntu && $auto['upstream_release'] === '') {
			$origins = array_column($patches, 2);
			foreach ($auto['patches'] as $name => $text) {
				if (!in_array(self::origin($text), $origins, true)) {
					$patches[] = array(preg_replace('/^\d{4}-/', '', $name), $text, self::origin($text));
				}
			}
			$kept_cves = array_values(array_unique(array_merge($kept_cves, $auto['cves'])));
		} elseif ($auto) {
			// Made on another base, or on upstream's release: its CVEs are looked for again.
			foreach ($auto['cves'] as $cve) {
				if (!isset($want[$cve])) { $want[$cve] = array('fixed_in' => '', 'ids' => array()); }
			}
		}
		$new = array_values(array_diff(array_keys($want), $kept_cves));
		if (!$new && !$auto) {
			// A person's recipe carries every CVE asked for already: nothing to add to it.
			$res['status'] = 'unchanged';
			$res['carried'] = $kept_cves;
			$res['revision'] = (int)($person['revision'] ?? 0);
			$res['version'] = $res['revision'] ? $ubuntu . '+joinery' . $res['revision'] : '';
			$res['dir'] = $fixes_dir . '/' . $source;
			return $res;
		}

		// --- Ubuntu's source ---------------------------------------------------
		$source_dir = (string)($opts['source_dir'] ?? dirname($auto_root) . '/source/' . $source . '_' . $ubuntu);
		$provider = $opts['provider'] ?? self::default_provider($fixes_dir, isset($opts['source_dir']) ? null : dirname($auto_root) . '/source');
		$got = call_user_func($provider, $source, $ubuntu, $source_dir);
		if (!is_array($got) || ($got['error'] ?? '') !== '' || !preg_match('/^[0-9a-f]{64}$/', (string)($got['dsc_sha256'] ?? '')) || !is_dir((string)($got['tree'] ?? ''))) {
			$why = is_array($got) && ($got['error'] ?? '') !== '' ? (string)$got['error'] : "Ubuntu's source for {$source} {$ubuntu} could not be fetched";
			return $fail_all($new, $why);
		}
		$tree = (string)$got['tree'];
		$changelog_from = $person['changelog_from'] ?? '';
		if ($changelog_from === '') { $changelog_from = $auto['changelog_from'] ?? ''; }
		if ($changelog_from === '') { $changelog_from = (string)($opts['changelog_from'] ?? ''); }
		if ($changelog_from === '') { $changelog_from = self::first_binary($tree); }
		if (!preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $changelog_from)) {
			return $fail_all($new, "no binary package of {$source} to read the changelog from");
		}

		// The automatic recipes' folder: everyone may pass through it, so the
		// patch dry-run, as nobody, reaches the work tree (reviewer2 B19).
		if (!is_dir($auto_root) && !@mkdir($auto_root, 0755, true) && !is_dir($auto_root)) {
			return $fail_all($new, 'could not make ' . $auto_root);
		}
		@chmod($auto_root, 0755);
		$work = $auto_root . '/.work-' . getmypid() . '-' . bin2hex(random_bytes(4));
		if (!@mkdir($work, 0700, true) && !is_dir($work)) {
			return $fail_all($new, 'could not make a work folder under ' . $auto_root);
		}
		try {
			if ($patches) {
				$a = self::apply_all($tree, array_column($patches, 1), $work);
				if ($a !== '') {
					return $fail_all($new, "the patches already carried for {$source} no longer apply to {$ubuntu}: {$a}");
				}
			}
			// --- Each CVE asked for ---------------------------------------------
			$carried_new = array();
			$fixed_versions = array();
			foreach ($new as $cve) {
				$info = $want[$cve];
				$ctx = (array)($opts['finder_ctx'] ?? array()) + array('upstreams_file' => $upstreams_file);
				$ctx['base_upstream'] = $base_upstream;
				$ctx['fixed_in'] = (string)($info['fixed_in'] ?? '');
				$ctx['ids'] = array_values(array_filter((array)($info['ids'] ?? array()), 'is_string'));
				$found = isset($opts['finder']) ? call_user_func($opts['finder'], $source, $cve, $ctx) : UpstreamFixFinder::find($source, $cve, $ctx);
				$fv = (string)($found['fixed_version'] ?? '');
				if ($fv === '' && $up !== null) { $fv = UpstreamFixFinder::line_version($base_upstream, $ctx['fixed_in'], $up['line']); }
				$fixed_versions[$cve] = $fv;
				if (!$found['commits']) {
					$res['failed'][$cve] = $found['reason'] !== '' ? $found['reason'] : 'no upstream commit found';
					continue;
				}
				$origins = array_column($patches, 2);
				$add = array();
				$n = 0;
				foreach ($found['commits'] as $c) {
					if (in_array($c['url'], $origins, true)) { continue; }
					$n++;
					$text = "Description: {$cve}, upstream's fix as committed (its news and docs left out): " . preg_replace('/\s+/', ' ', $c['subject']) . "\n"
						. "Origin: {$c['url']}\n"
						. "Bug: https://ubuntu.com/security/{$cve}\n\n"
						. $c['patch'];
					$add[] = array($cve . ($n > 1 ? '-' . $n : '') . '.patch', $text, $c['url']);
				}
				if ($add) {
					$a = self::apply_all($tree, array_merge(array_column($patches, 1), array_column($add, 1)), $work);
					if ($a !== '') {
						$res['failed'][$cve] = "upstream's fix does not apply cleanly to Ubuntu's {$ubuntu}: {$a}";
						continue;
					}
					$patches = array_merge($patches, $add);
				}
				$carried_new[] = $cve;
			}
		} finally {
			self::run(array('rm', '-rf', $work), 600);
		}

		// --- The recipe -------------------------------------------------------
		$recipe_cves = array_values(array_unique(array_merge($kept_cves, $carried_new)));
		$release_line = '';
		// The CVEs asked for: the caller's, or with none named, those looked for again on a new base.
		$asked_for = $cves ? array_keys($cves) : $new;
		if ($new && !array_intersect($asked_for, $recipe_cves)) {
			// Nothing asked for could be carried: upstream's fixed release, where it signs one (WP4).
			$v = '';
			foreach ($new as $cve) {
				$fv = $fixed_versions[$cve] ?? '';
				if ($fv === '') { $v = ''; break; }
				if ($v === '' || version_compare($fv, $v) > 0) { $v = $fv; }
			}
			if ($v === '' || $up === null || $up['release_url'] === '' || $up['sig_url'] === '') {
				$res['reason'] = 'no upstream fix applies to Ubuntu\'s ' . $ubuntu . ', and ' . ($v === ''
					? 'the release that fixes ' . (count($new) === 1 ? 'it' : 'them all') . ' is not known'
					: $source . '\'s upstream publishes no signed release to rebuild on') . '; a person is needed';
				return $res;
			}
			$release_line = 'upstream_release=' . $v . ' ' . UpstreamFixFinder::expand($up['release_url'], $v) . ' ' . UpstreamFixFinder::expand($up['sig_url'], $v);
			$recipe_cves = array_values(array_unique(array_merge($kept_cves, $new)));
			$patches = array();
			$res['fallback'] = 'upstream_release';
		}
		if (!$recipe_cves) {
			$res['reason'] = 'no CVE was asked for';
			return $res;
		}
		$files = array();
		foreach (array_values($patches) as $i => $p) {
			$files[sprintf('%04d-%s', $i + 1, $p[0])] = $p[1];
		}
		$test_skip = $person['test_skip'] ?? '';
		if ($test_skip === '') { $test_skip = $auto['test_skip'] ?? ''; }
		$date = (string)($opts['date'] ?? gmdate('Y-m-d'));
		$comment = "# Package fix recipe for {$source} (see build.sh in fixes/ for the format), written by\n"
			. "# PackageFixRecipeWriter: the next automatic run rewrites it; a person's recipe goes in fixes/{$source}/.\n";
		foreach ($files as $name => $text) {
			$comment .= "# {$name}: " . self::origin($text) . "\n";
		}
		if ($release_line !== '') {
			$comment .= "# No upstream commit applied to Ubuntu's {$ubuntu}, so this rebuilds on upstream's fixed release:\n";
			foreach ($res['failed'] as $cve => $why) {
				$comment .= '#   ' . $cve . ': ' . preg_replace('/\s+/', ' ', $why) . "\n";
			}
		}
		$body = array(
			'state=active',
			"ubuntu_source={$source} {$ubuntu}",
			'dsc_sha256=' . $got['dsc_sha256'],
			'revision=@REVISION@',
			'cves=' . implode(' ', $recipe_cves),
			"changelog_from={$changelog_from}",
			"date={$date}",
			'test=default',
		);
		if ($test_skip !== '') { $body[] = "test_skip={$test_skip}"; }
		if ($release_line !== '') { $body[] = $release_line; }
		$body[] = 'made_by=automatic';
		$recipe = $comment . implode("\n", $body) . "\n";

		$res['carried'] = $recipe_cves;
		if ($auto && self::normal($auto['text']) === self::normal($recipe) && $auto['patches'] === $files) {
			$res['status'] = 'unchanged';
			$res['revision'] = $auto['revision'];
			$res['version'] = $ubuntu . '+joinery' . $auto['revision'];
			return $res;
		}
		$revision = max((int)($person['revision'] ?? 0), (int)($opts['min_revision'] ?? 0), (int)($auto['revision'] ?? 0)) + 1;
		$recipe = str_replace('@REVISION@', (string)$revision, $recipe);
		$err = self::put($auto_root, $source, $recipe, $files);
		if ($err !== '') {
			$res['fallback'] = null;
			return $fail_all($new, $err);
		}
		$res['status'] = 'written';
		$res['revision'] = $revision;
		$res['version'] = $ubuntu . '+joinery' . $revision;
		return $res;
	}

	/**
	 * Apply $patches in order, with no fuzz, to a copy of the files they touch
	 * from $tree. '' when every one applies; else which did not, and patch's words.
	 */
	public static function apply_all(string $tree, array $patches, string $work): string {
		$mini = $work . '/tree';
		self::run(array('rm', '-rf', $mini), 600);
		@mkdir($mini, 0700, true);
		foreach ($patches as $text) {
			foreach (self::touched($text) as $rel) {
				if (is_file($tree . '/' . $rel) && !is_file($mini . '/' . $rel)) {
					@mkdir(dirname($mini . '/' . $rel), 0700, true);
					copy($tree . '/' . $rel, $mini . '/' . $rel);
				}
			}
		}
		// Upstream's text is applied as nobody, never as root: the runner is root.
		$as_nobody = function_exists('posix_geteuid') && posix_geteuid() === 0;
		if ($as_nobody) {
			self::run(array('chown', '-R', '65534:65534', $mini), 600);
			@chmod($work, 0711);
			// Folder permissions, not the patch, must never be what fails.
			$reach = self::run(array('setpriv', '--reuid=65534', '--regid=65534', '--clear-groups', '--', 'test', '-w', $mini), 60);
			if ($reach['code'] !== 0) {
				throw new RuntimeException('the patch dry-run runs as nobody, and nobody cannot reach ' . $mini
					. ': a folder above it is not passable by everyone');
			}
		}
		foreach (array_values($patches) as $i => $text) {
			$file = $work . '/p' . $i . '.patch';
			file_put_contents($file, $text);
			if ($as_nobody) {
				@chmod($file, 0644);
			}
			$cmd = array('patch', '-p1', '-F', '0', '-N', '--batch', '-s', '--no-backup-if-mismatch', '-d', $mini, '-i', $file);
			if ($as_nobody) {
				$cmd = array_merge(array('setpriv', '--reuid=65534', '--regid=65534', '--clear-groups', '--'), $cmd);
			}
			$r = self::run($cmd, 300);
			@unlink($file);
			if ($r['code'] !== 0) {
				$words = trim(preg_replace('/\s+/', ' ', $r['out'] . ' ' . $r['err']));
				return 'patch ' . ($i + 1) . ' (' . self::origin($text) . ') failed: ' . substr($words, 0, 400);
			}
		}
		return '';
	}

	/** The paths a patch touches, relative to the source's top (-p1). */
	public static function touched(string $text): array {
		$out = array();
		foreach (preg_split('/\n/', $text) as $l) {
			if (!preg_match('/^(?:---|\+\+\+) (\S+)/', $l, $m) || $m[1] === '/dev/null') { continue; }
			$rel = preg_replace('~^[^/]*/~', '', $m[1]);
			if ($rel === '' || $rel === $m[1] || $rel[0] === '/' || preg_match('~(^|/)\.\.(/|$)~', $rel)) { continue; }
			$out[$rel] = true;
		}
		return array_keys($out);
	}

	/** A patch's Origin: URL, from the lines above its diff. */
	public static function origin(string $text): string {
		foreach (preg_split('/\n/', $text) as $l) {
			if (preg_match('/^(--- |diff |Index: )/', $l)) { break; }
			if (preg_match('/^Origin: +(\S+)/', $l, $m)) { return $m[1]; }
		}
		return '';
	}

	// --- internals -------------------------------------------------------------

	/** A recipe folder, read: null when it has no RECIPE. */
	private static function read_recipe(string $dir): ?array {
		if (!is_file($dir . '/RECIPE')) { return null; }
		$text = (string)file_get_contents($dir . '/RECIPE');
		$v = function ($key) use ($text) {
			$val = null;
			foreach (preg_split('/\n/', $text) as $l) {
				if (strpos($l, $key . '=') === 0) { $val = substr($l, strlen($key) + 1); }
			}
			return trim((string)$val);
		};
		$us = preg_split('/\s+/', $v('ubuntu_source'), -1, PREG_SPLIT_NO_EMPTY);
		$patches = array();
		foreach (glob($dir . '/patches/*.patch') ?: array() as $p) {
			$patches[basename($p)] = (string)file_get_contents($p);
		}
		ksort($patches);
		return array(
			'text'             => $text,
			'state'            => $v('state'),
			'version'          => $us[1] ?? '',
			'revision'         => (int)$v('revision'),
			'cves'             => preg_split('/\s+/', $v('cves'), -1, PREG_SPLIT_NO_EMPTY),
			'changelog_from'   => $v('changelog_from'),
			'test_skip'        => $v('test_skip'),
			'upstream_release' => $v('upstream_release'),
			'patches'          => $patches,
		);
	}

	/** A recipe as compared: its comments, revision and date left out. */
	private static function normal(string $text): string {
		$keep = array();
		foreach (preg_split('/\n/', $text) as $l) {
			if ($l === '' || $l[0] === '#' || strpos($l, 'revision=') === 0 || strpos($l, 'date=') === 0) { continue; }
			$keep[] = $l;
		}
		return implode("\n", $keep);
	}

	/** Write the recipe folder whole, in place of the old one. '' or what went wrong. */
	private static function put(string $root, string $source, string $recipe, array $files): string {
		if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
			return 'could not make ' . $root;
		}
		$tmp = $root . '/.' . $source . '.new.' . getmypid();
		$old = $root . '/.' . $source . '.old.' . getmypid();
		self::run(array('rm', '-rf', $tmp, $old), 600);
		if (!@mkdir($tmp . '/patches', 0700, true)) {
			return 'could not make ' . $tmp;
		}
		$ok = file_put_contents($tmp . '/RECIPE', $recipe) !== false;
		foreach ($files as $name => $text) {
			$ok = $ok && file_put_contents($tmp . '/patches/' . $name, $text) !== false;
		}
		if (!$ok) {
			self::run(array('rm', '-rf', $tmp), 600);
			return 'could not write the recipe under ' . $tmp;
		}
		$dir = $root . '/' . $source;
		if (is_dir($dir) && !@rename($dir, $old)) {
			self::run(array('rm', '-rf', $tmp), 600);
			return 'could not move the old recipe aside';
		}
		if (!@rename($tmp, $dir)) {
			if (is_dir($old)) { @rename($old, $dir); }
			return 'could not put the new recipe in place';
		}
		self::run(array('rm', '-rf', $old), 600);
		return '';
	}

	/** The first binary package debian/control names: a recipe's changelog_from when nothing else names one. */
	private static function first_binary(string $tree): string {
		$control = is_file($tree . '/debian/control') ? (string)file_get_contents($tree . '/debian/control') : '';
		return preg_match('/^Package:\s*(\S+)/m', $control, $m) ? $m[1] : '';
	}

	/**
	 * Ubuntu's source from the build root (build_root.sh source), kept in
	 * $out until the base moves on: a source's older unpacked versions in
	 * $cache_root are removed.
	 */
	private static function default_provider(string $fixes_dir, ?string $cache_root): callable {
		return function (string $source, string $version, string $out) use ($fixes_dir, $cache_root) {
			if (!is_file($out . '/DSC_SHA256') || !is_dir($out . '/tree')) {
				$r = self::run(array('bash', $fixes_dir . '/build_root.sh', 'source', $source, $version, $out), self::SOURCE_TIMEOUT);
				if ($r['code'] !== 0) {
					$lines = preg_split('/\n/', trim($r['err'] . "\n" . $r['out']), -1, PREG_SPLIT_NO_EMPTY);
					return array('error' => "Ubuntu's source for {$source} {$version} could not be fetched: " . implode(' ', array_slice($lines, -3)));
				}
			}
			if ($cache_root !== null) {
				foreach (glob($cache_root . '/' . $source . '_*') ?: array() as $d) {
					if ($d !== $out) { self::run(array('rm', '-rf', $d), 600); }
				}
			}
			return array('dsc_sha256' => trim((string)@file_get_contents($out . '/DSC_SHA256')), 'tree' => $out . '/tree');
		};
	}

	/** One command, no shell, under a time limit. */
	private static function run(array $cmd, int $timeout): array {
		// Its error output joins its output: one pipe never waits on the other.
		$proc = proc_open(['timeout', (string)$timeout, ...$cmd], array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes,
			null, array('PATH' => '/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin', 'LC_ALL' => 'C'));
		if (!is_resource($proc)) {
			return array('code' => 127, 'out' => '', 'err' => 'could not run ' . $cmd[0]);
		}
		$out = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		return array('code' => proc_close($proc), 'out' => (string)$out, 'err' => '');
	}
}
?>
