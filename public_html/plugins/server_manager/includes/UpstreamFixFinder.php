<?php
/**
 * UpstreamFixFinder - the upstream commits that fix one CVE, as patches for
 * Ubuntu's build (specs/package_fixes_automatic.md WP2).
 *
 * Each watched source's upstream git is listed in fixes/UPSTREAMS. A bare
 * clone of each is kept on the publishing box (CLONE_DIR/<source>.git) and
 * fetched before each search. For one CVE:
 *
 *   1. the range: from the tag of the upstream version Ubuntu's build is based
 *      on, to the fixed release on the same release line (the first of the
 *      advisory's fixed versions on that line; with none, the line's newest
 *      tag; with no newer tag, the line's branch head where UPSTREAMS names one);
 *   2. the commits in that range whose message names the CVE or one of the
 *      project's own ids for it (PHP's GHSA), oldest first;
 *   3. each as `git format-patch`, with the parts touching NEWS, ChangeLog,
 *      CHANGES, release notes and docs left out.
 *
 * Runs as root under the automatic runner (WP9); nothing here writes outside
 * the clone directory.
 *
 * @version 1.1 - owner R-Q1 (b): a commit is used only when the release tag it is reached from verifies
 *                against the project's key a person committed (fixes/upstream_keys/<source>.asc); an unsigned
 *                tag, a branch head, or no key sends the CVE on (upstream's signed release, else a person)
 * @version 1.0
 */
class UpstreamFixFinder {

	/** The bare clones, one per source, in the build root's area. */
	const CLONE_DIR = '/var/lib/joinery-package-fixes-build/upstream';

	const CLONE_TIMEOUT = 3600;
	const FETCH_TIMEOUT = 900;
	const GIT_TIMEOUT = 300;

	/**
	 * The commits that fix $cve in $source's upstream.
	 *
	 * @param array $ctx base_upstream  the upstream version Ubuntu's build is based on ('8.5.4')
	 *                   fixed_in       the advisory's fixed versions, comma separated (may be empty)
	 *                   ids            the project's own ids for the CVE (GHSA-...)
	 *                   tests only: git_dir (a repository searched as is: no clone, no fetch),
	 *                   upstreams_file (in place of fixes/UPSTREAMS)
	 * @return array{commits: list<array{sha:string, url:string, subject:string, patch:string}>, reason:string,
	 *               fixed_version:string, range:string}
	 *         reason is '' when a commit was found; fixed_version is the release the
	 *         range ends at ('' when it ends at a branch head or none was found)
	 */
	public static function find(string $source, string $cve, array $ctx): array {
		$out = array('commits' => array(), 'reason' => '', 'fixed_version' => '', 'range' => '');
		$fail = function ($why) use (&$out) { $out['reason'] = $why; return $out; };
		if (!preg_match('/^CVE-\d{4}-\d{4,}$/', $cve)) {
			return $fail("'{$cve}' is not a CVE id");
		}
		$base = (string)($ctx['base_upstream'] ?? '');
		if (!preg_match('/^\d+(\.\d+)*[0-9A-Za-z.]*$/', $base)) {
			return $fail("the upstream version Ubuntu's build is based on is not known ('{$base}')");
		}
		$up = self::upstreams($ctx['upstreams_file'] ?? null)[$source] ?? null;
		if ($up === null) {
			return $fail("fixes/UPSTREAMS lists no upstream for {$source}");
		}
		if (($ctx['git_dir'] ?? '') !== '') {
			$git_dir = (string)$ctx['git_dir'];
		} else {
			if ($up['git'] === '') {
				return $fail("{$source}'s upstream keeps no public git, so there is no commit to look for");
			}
			$got = self::clone_or_fetch($source, $up['git']);
			if ($got['error'] !== '') {
				return $fail($got['error']);
			}
			$git_dir = $got['dir'];
		}

		$r = self::git($git_dir, array('for-each-ref', '--format=%(refname)', 'refs/tags'));
		if ($r['code'] !== 0) {
			return $fail("could not list {$source}'s upstream tags: " . self::tail($r['err']));
		}
		$tags = array();
		foreach (preg_split('/\n/', trim($r['out']), -1, PREG_SPLIT_NO_EMPTY) as $ref) {
			if (strpos($ref, 'refs/tags/') === 0) { $tags[substr($ref, 10)] = true; }
		}
		$base_tag = self::expand($up['tag'], $base);
		if (!isset($tags[$base_tag])) {
			return $fail("{$source}'s upstream has no tag {$base_tag} for {$base}, the version Ubuntu's build is based on");
		}
		$line = $up['line'];
		$end = '';
		$fixed = self::line_version($base, (string)($ctx['fixed_in'] ?? ''), $line);
		if ($fixed !== '') {
			$t = self::expand($up['tag'], $fixed);
			if (isset($tags[$t])) {
				$end = $t;
				$out['fixed_version'] = $fixed;
			}
		} else {
			// No fixed release named: the line's newest release, if newer than the base.
			$newest = null;
			foreach (array_keys($tags) as $t) {
				$v = self::tag_version($up['tag'], (string)$t, $up['version']);
				if ($v === null || !self::same_line($v['parts'], self::parts($base), $line)) { continue; }
				if (self::compare_parts($v['parts'], self::parts($base)) <= 0) { continue; }
				if ($newest === null || self::compare_parts($v['parts'], $newest['parts']) > 0) {
					$newest = $v + array('tag' => (string)$t);
				}
			}
			if ($newest !== null) {
				$end = $newest['tag'];
				$out['fixed_version'] = $newest['version'];
			}
		}
		if ($end === '' && $up['branch'] !== '') {
			$branch = self::expand($up['branch'], $base);
			$b = self::git($git_dir, array('rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch));
			if ($b['code'] === 0) {
				$end = 'refs/heads/' . $branch;
			}
		}
		if ($end === '') {
			return $fail($fixed !== ''
				? "{$source}'s upstream has no tag " . self::expand($up['tag'], $fixed) . " for the fixed release {$fixed}"
				: "{$source}'s upstream has released nothing newer than {$base} on its " . implode('.', array_slice(self::parts($base), 0, $line)) . ' line');
		}
		$out['range'] = $base_tag . '..' . $end;
		// Owner R-Q1 (b): only what a release tag upstream signed reaches.
		// Every commit searched below is in that tag's history, which its
		// signature covers. A branch head has no signature to check.
		if (strpos($end, 'refs/') === 0) {
			return $fail("{$source} ends at a branch head ({$end}), which no one signs: its commits are not used automatically");
		}
		$verifier = $ctx['tag_verifier'] ?? array(self::class, 'verify_tag');
		$why = (string)call_user_func($verifier, $source, $git_dir, $end, $ctx);
		if ($why !== '') {
			return $fail($why);
		}

		$args = array('log', '--reverse', '--no-merges', '--format=%H%x1f%s', '-E', '--regexp-ignore-case', '--grep=' . self::id_pattern($cve));
		foreach ((array)($ctx['ids'] ?? array()) as $id) {
			if (is_string($id) && preg_match('/^[A-Za-z][A-Za-z0-9._-]{2,63}$/', $id)) {
				$args[] = '--grep=' . self::id_pattern($id);
			}
		}
		$args[] = 'refs/tags/' . $base_tag . '..' . (strpos($end, 'refs/') === 0 ? $end : 'refs/tags/' . $end);
		$r = self::git($git_dir, $args);
		if ($r['code'] !== 0) {
			return $fail("could not search {$source}'s upstream history: " . self::tail($r['err']));
		}
		// One fix picked onto several branches can show up more than once: the
		// newest copy is kept, the one made for the newest line.
		$by_subject = array();
		foreach (preg_split('/\n/', trim($r['out']), -1, PREG_SPLIT_NO_EMPTY) as $row) {
			$f = explode("\x1f", $row, 2);
			if (!preg_match('/^[0-9a-f]{40,64}$/', $f[0])) { continue; }
			unset($by_subject[$f[1] ?? '']);
			$by_subject[$f[1] ?? ''] = $f[0];
		}
		$only_docs = 0;
		foreach ($by_subject as $subject => $sha) {
			$p = self::git($git_dir, array('format-patch', '-1', '--stdout', '--no-signature', '--no-stat', $sha));
			if ($p['code'] !== 0) {
				return $fail("could not write upstream commit {$sha} as a patch: " . self::tail($p['err']));
			}
			$patch = self::drop_doc_parts($p['out']);
			if ($patch === '') { $only_docs++; continue; }
			$out['commits'][] = array('sha' => $sha, 'url' => self::commit_url($up['git'], $sha), 'subject' => (string)$subject, 'patch' => $patch);
		}
		if (!$out['commits']) {
			$named = implode(' or ', array_merge(array($cve), array_values(array_filter((array)($ctx['ids'] ?? array()), 'is_string'))));
			$out['reason'] = $only_docs
				? "the only upstream commits naming {$named} between {$base_tag} and {$end} change its news and docs, not its code"
				: "no upstream commit between {$base_tag} and {$end} names {$named}";
		}
		return $out;
	}

	/**
	 * fixes/UPSTREAMS, by source.
	 *
	 * @return array<string, array{git:string, tag:string, line:int, branch:string, version:string, ids:list<string>, release_url:string, sig_url:string}>
	 */
	public static function upstreams(?string $file = null): array {
		$file = $file ?? PathHelper::getSiteRoot() . '/' . PackageFixPublisher::SOURCE_SUBDIR . '/UPSTREAMS';
		$text = is_file($file) ? (string)file_get_contents($file) : '';
		$out = array();
		foreach (preg_split('/\n/', $text) as $l) {
			$l = trim($l);
			if ($l === '' || $l[0] === '#') { continue; }
			$f = array();
			foreach (preg_split('/\s+/', $l) as $tok) {
				$kv = explode('=', $tok, 2);
				if (count($kv) === 2) { $f[$kv[0]] = $kv[1]; }
			}
			$source = $f['source'] ?? '';
			if (!preg_match('/^[a-z0-9][a-z0-9.+-]+$/', $source) || ($f['tag'] ?? '') === '') { continue; }
			$git = (string)($f['git'] ?? '');
			$out[$source] = array(
				'git'         => preg_match('~^https://[^\s]+$~', $git) ? $git : '',
				'tag'         => (string)$f['tag'],
				'line'        => max(1, (int)($f['line'] ?? 2)),
				'branch'      => (string)($f['branch'] ?? ''),
				'version'     => (string)($f['version'] ?? ''),
				'ids'         => preg_split('/,/', (string)($f['ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
				'release_url' => (string)($f['release_url'] ?? ''),
				'sig_url'     => (string)($f['sig_url'] ?? ''),
			);
		}
		return $out;
	}

	/**
	 * A template with a version filled in: {v} is the version as given, {major}
	 * {minor} {patch} {build} its first four numbers.
	 */
	public static function expand(string $template, string $version): string {
		$p = array_pad(self::parts($version), 4, 0);
		return strtr($template, array('{v}' => $version, '{major}' => $p[0], '{minor}' => $p[1], '{patch}' => $p[2], '{build}' => $p[3]));
	}

	/** The upstream version of an Ubuntu package version: 1:10.2p1-2ubuntu3.8 is 10.2p1, 8:7.1.2.18+dfsg1-1 is 7.1.2.18. */
	public static function upstream_of(string $ubuntu_version): string {
		$v = preg_replace('/^\d+:/', '', $ubuntu_version);
		$v = preg_replace('/-[^-]*$/', '', $v);
		return (string)preg_replace('/[+~].*$/', '', $v);
	}

	/** Of $fixed_in (comma separated), the first release on $base's line newer than $base; '' when none. */
	public static function line_version(string $base, string $fixed_in, int $line): string {
		$bp = self::parts($base);
		$found = array();
		foreach (preg_split('/[\s,]+/', $fixed_in, -1, PREG_SPLIT_NO_EMPTY) as $v) {
			$vp = self::parts($v);
			if ($vp && self::same_line($vp, $bp, $line) && self::compare_parts($vp, $bp) > 0) { $found[] = $v; }
		}
		usort($found, function ($a, $b) { return self::compare_parts(self::parts($a), self::parts($b)); });
		return $found[0] ?? '';
	}

	/**
	 * A format-patch with the parts that touch NEWS, ChangeLog, CHANGES, release
	 * notes or docs left out: they are where a backport conflicts, and they are
	 * not the fix. '' when nothing else is left.
	 */
	public static function drop_doc_parts(string $patch): string {
		$pieces = preg_split('/^(?=diff --git )/m', $patch);
		$head = array_shift($pieces);
		$kept = '';
		foreach ($pieces as $piece) {
			if (preg_match('~^diff --git a/(\S+) b/(\S+)~', $piece, $m) && (self::is_doc_path($m[1]) || self::is_doc_path($m[2]))) {
				continue;
			}
			$kept .= $piece;
		}
		return $kept === '' ? '' : $head . $kept;
	}

	/** NEWS, ChangeLog, CHANGES, release notes, and anything under doc/, docs/ or Documentation/. */
	public static function is_doc_path(string $path): bool {
		$base = basename($path);
		return (bool)preg_match('/^(NEWS|ChangeLog|CHANGES|RELEASE[-_]NOTES)/i', $base)
			|| (bool)preg_match('~(^|/)(doc|docs|Documentation)/~', $path);
	}

	/** The commit on the project's web view where it has one; else the git URL and the commit. */
	public static function commit_url(string $git, string $sha): string {
		if (preg_match('~^https://github\.com/([^/]+)/([^/]+?)(\.git)?/?$~', $git, $m)) {
			return "https://github.com/{$m[1]}/{$m[2]}/commit/{$sha}";
		}
		if (preg_match('~^https://(gitlab\.[^/]+)/(.+?)(\.git)?/?$~', $git, $m)) {
			return "https://{$m[1]}/{$m[2]}/-/commit/{$sha}";
		}
		if (preg_match('~^https://git\.postgresql\.org/git/([^/]+)$~', $git, $m)) {
			return "https://git.postgresql.org/gitweb/?p={$m[1]};a=commit;h={$sha}";
		}
		if (preg_match('~^https://sourceware\.org/git/([^/]+)$~', $git, $m)) {
			return "https://sourceware.org/git/?p={$m[1]};a=commit;h={$sha}";
		}
		return $git . '#' . $sha;
	}

	// --- internals -------------------------------------------------------------

	/** The source's bare clone, made or brought up to date. */
	private static function clone_or_fetch(string $source, string $url): array {
		$dir = self::CLONE_DIR . '/' . $source . '.git';
		if (!is_dir(self::CLONE_DIR) && !@mkdir(self::CLONE_DIR, 0700, true) && !is_dir(self::CLONE_DIR)) {
			return array('dir' => $dir, 'error' => 'could not make ' . self::CLONE_DIR);
		}
		if (!is_dir($dir)) {
			$partial = $dir . '.partial';
			self::run(array('rm', '-rf', $partial), 60);
			$r = self::run(array('git', 'clone', '--bare', '--quiet', $url, $partial), self::CLONE_TIMEOUT);
			if ($r['code'] !== 0 || !@rename($partial, $dir)) {
				self::run(array('rm', '-rf', $partial), 60);
				return array('dir' => $dir, 'error' => "could not clone {$source}'s upstream {$url}: " . self::tail($r['err']));
			}
			return array('dir' => $dir, 'error' => '');
		}
		$r = self::git($dir, array('fetch', '--quiet', '--prune', '--tags', '--force', $url, '+refs/heads/*:refs/heads/*'), self::FETCH_TIMEOUT);
		if ($r['code'] !== 0) {
			return array('dir' => $dir, 'error' => "could not fetch {$source}'s upstream {$url}: " . self::tail($r['err']));
		}
		return array('dir' => $dir, 'error' => '');
	}

	private static function git(string $git_dir, array $args, int $timeout = self::GIT_TIMEOUT, array $env = array()): array {
		return self::run(array_merge(array('git', '--git-dir=' . $git_dir), $args), $timeout, $env);
	}

	/**
	 * Whether release tag $tag verifies against the key a person committed for
	 * $source (fixes/upstream_keys/<source>.asc). '' when it does; else why not.
	 */
	public static function verify_tag(string $source, string $git_dir, string $tag, array $ctx = array()): string {
		$keys = (string)($ctx['keys_dir'] ?? (PathHelper::getSiteRoot() . '/' . PackageFixPublisher::SOURCE_SUBDIR . '/upstream_keys'));
		$key = rtrim($keys, '/') . '/' . $source . '.asc';
		if (!is_file($key)) {
			return "no signing key is committed for {$source} (fixes/upstream_keys/{$source}.asc), so its release tag {$tag} cannot be checked";
		}
		$home = rtrim(sys_get_temp_dir(), '/') . '/upstream-fix-gpg-' . getmypid() . '-' . bin2hex(random_bytes(4));
		if (!@mkdir($home, 0700)) {
			return 'could not make a keyring to check ' . $tag . ' with';
		}
		try {
			$i = self::run(array('gpg', '--batch', '--quiet', '--homedir', $home, '--import', $key), 60);
			if ($i['code'] !== 0) {
				return "fixes/upstream_keys/{$source}.asc would not import as a key: " . self::tail($i['err']);
			}
			$v = self::git($git_dir, array('verify-tag', $tag), 60, array('GNUPGHOME' => $home));
			if ($v['code'] !== 0) {
				return "{$source}'s release tag {$tag} is not signed by the key committed for it: " . self::tail($v['err']);
			}
			return '';
		} finally {
			self::run(array('rm', '-rf', $home), 60);
		}
	}

	/** One command, no shell, under a time limit. */
	private static function run(array $cmd, int $timeout, array $extra_env = array()): array {
		$err_file = tempnam(sys_get_temp_dir(), 'upstream-fix-');
		$env = $extra_env + array('PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'GIT_TERMINAL_PROMPT' => '0', 'HOME' => sys_get_temp_dir(),
			'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null');
		$proc = proc_open(['timeout', (string)$timeout, ...$cmd], array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $err_file, 'w')), $pipes, null, $env);
		if (!is_resource($proc)) {
			@unlink($err_file);
			return array('code' => 127, 'out' => '', 'err' => 'could not run ' . $cmd[0]);
		}
		$out = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$code = proc_close($proc);
		$err = (string)@file_get_contents($err_file);
		@unlink($err_file);
		if ($code === 124) { $err = "timed out after {$timeout} seconds. " . $err; }
		return array('code' => $code, 'out' => (string)$out, 'err' => $err);
	}

	private static function tail(string $s): string {
		$lines = preg_split('/\n/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
		return $lines ? implode(' ', array_slice($lines, -3)) : 'no error given';
	}

	/** An extended regex matching $id as a whole word: CVE-2026-1 never matches CVE-2026-12. */
	private static function id_pattern(string $id): string {
		return '(^|[^0-9A-Za-z])' . str_replace('.', '\\.', $id) . '([^0-9A-Za-z]|$)';
	}

	/** A version's numbers: 10.2p1 is 10, 2, 1. */
	private static function parts(string $v): array {
		return array_map('intval', preg_split('/[^0-9]+/', $v, -1, PREG_SPLIT_NO_EMPTY));
	}

	private static function same_line(array $a, array $b, int $line): bool {
		return count($a) >= $line && count($b) >= $line && array_slice($a, 0, $line) === array_slice($b, 0, $line);
	}

	private static function compare_parts(array $a, array $b): int {
		$n = max(count($a), count($b));
		for ($i = 0; $i < $n; $i++) {
			$x = $a[$i] ?? 0;
			$y = $b[$i] ?? 0;
			if ($x !== $y) { return $x <=> $y; }
		}
		return 0;
	}

	/** The release a tag names, by the template: null when it is not one of its release tags. */
	private static function tag_version(string $template, string $tag, string $spelling = ''): ?array {
		$re = '';
		foreach (preg_split('/(\{v\}|\{major\}|\{minor\}|\{patch\}|\{build\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
			if ($piece === '{v}') {
				$re .= '(?P<v>\d+(?:\.\d+)*(?:p\d+)?)';
			} elseif (in_array($piece, array('{major}', '{minor}', '{patch}', '{build}'), true)) {
				$re .= '(?P<' . trim($piece, '{}') . '>\d+)';
			} else {
				$re .= preg_quote($piece, '/');
			}
		}
		if (!preg_match('/^' . $re . '$/', $tag, $m)) { return null; }
		if (isset($m['v'])) {
			return array('version' => $m['v'], 'parts' => self::parts($m['v']));
		}
		$parts = array();
		foreach (array('major', 'minor', 'patch', 'build') as $k) {
			if (!isset($m[$k])) { break; }
			$parts[] = (int)$m[$k];
		}
		$version = $spelling !== '' ? self::expand($spelling, implode('.', $parts)) : implode('.', $parts);
		return array('version' => $version, 'parts' => $parts);
	}
}
?>
