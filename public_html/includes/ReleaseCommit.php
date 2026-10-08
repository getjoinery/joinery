<?php
/**
 * ReleaseCommit - a release is a commit, and this is how the publisher knows.
 *
 * specs/release_transparency.md D1: publish refuses to build unless the trees
 * it ships from are committed and on the public remote. "A release is a commit"
 * is what makes the archive reproducible by anyone: a verifier with the commit
 * can regenerate every manifest line, which an archive built from an uncommitted
 * working tree can never offer.
 *
 * What "clean" means here, for the joinery repository:
 *
 * - no tracked file is modified or deleted anywhere in the repository (a tracked
 *   file anywhere may be included by something that ships), and
 * - no untracked, un-ignored file sits on a path that SHIPS: under public_html/
 *   or maintenance_scripts/, except paths the archive never carries, which are
 *   exactly the ones the release manifest never lists (PackageSignature::excluded():
 *   specs/, uploads/, cache/, logs/, backups/, .claude/, node_modules/, .git,
 *   top-level config/ and vendor/). A half-written spec does not block a release
 *   because it cannot reach a node; an untracked public_html/includes/Foo.php
 *   does, because it would.
 *
 * For the agent repository every untracked, un-ignored file blocks: all of it
 * is source.
 *
 * What SHIPS from the joinery repository is narrower than what sits on disk:
 * a file git knows (tracked, or untracked and not ignored - the second kind
 * is a blocker above, so at build time it is always the first), or one of the
 * few files publish builds itself (D2: the agent bundle, the relay sealer
 * binaries, the license copies, each release statement). An ignored file is
 * never shipped, whatever directory it sits in: a screenshot left in
 * public_html, a stray build output beside its source, a local test corpus.
 * knownFiles() and joineryFileShips() are that rule; the manifest builder,
 * the component tree hash and the archive listing all read it.
 *
 * "On the remote" means the commit is an ancestor of origin/main after a fetch.
 *
 * WHO COMMITS WHAT. The owner commits their own work; publish refuses before
 * writing anything while it is uncommitted or unpushed. Publish then writes
 * the files every release carries (publishWrite(): VERSION, the install SQL,
 * the plugin and theme version bumps, the parser jail binaries it rebuilds)
 * and commits and pushes exactly those itself (commitRelease()), as
 * 'Release <version>', so one run goes from a committed tree to a release.
 *
 * @version 1.2 - publishWrite() and commitRelease(): publish commits and pushes the files it writes; git runs
 *                with the repository owner's home, so the owner's identity and key are the ones used
 * @version 1.1 - knownFiles() and joineryFileShips(): only a file git knows, or one publish builds, ships
 * @version 1.0
 */

class ReleaseCommit {

	/** Repository-root-relative directories that ship in the joinery repository. */
	const JOINERY_SHIP_ROOTS = array('public_html', 'maintenance_scripts/install_tools', 'maintenance_scripts/sysadmin_tools');

	/** Repository-root-relative files that ship from the joinery repository root. */
	const JOINERY_SHIP_FILES = array('LICENSE.md', 'LICENSE-BUSINESS.md');

	/**
	 * Repository-relative directories whose files publish builds and ships
	 * though no commit holds them (spec release_transparency D2): the agent
	 * bundle and the relay sealer binaries. Each is checked by the release
	 * statement's own record of it, not by the commit.
	 */
	const JOINERY_GENERATED_DIRS = array('public_html/agent_dist/', 'public_html/plugins/mailbox/provisioning/bin/');

	/** Repository-relative files publish writes into the core: the root license files, copied in. */
	const JOINERY_GENERATED_FILES = array('public_html/LICENSE.md', 'public_html/LICENSE-BUSINESS.md');

	/**
	 * Run git in a repository. The repository is read with safe.directory set,
	 * because a publish runs as root on a management node and git otherwise
	 * refuses a tree another account owns ("dubious ownership", exit 128).
	 *
	 * @return array{exit:int, out:string[]}
	 */
	public static function git($repo_root, array $args) {
		$cmd = 'git -c ' . escapeshellarg('safe.directory=' . $repo_root) . ' -C ' . escapeshellarg($repo_root);
		foreach ($args as $arg) {
			$cmd .= ' ' . escapeshellarg($arg);
		}
		$cmd = 'env GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND=' . escapeshellarg('ssh -o BatchMode=yes') . ' ' . $cmd;
		// A publish runs as root. git status refreshes .git/index and git fetch
		// writes FETCH_HEAD, each through a new file, so root would leave
		// root-owned files inside the owner's .git and break their next git
		// command. Every git call therefore runs as the account that owns the
		// repository, whoever started the publish.
		// With the owner's home too: a commit is authored by the owner's git
		// identity and a push uses the owner's key, both read from there.
		$owner = self::repoOwner($repo_root);
		if ($owner !== null && function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$home = function_exists('posix_getpwnam') ? (string)(posix_getpwnam($owner)['dir'] ?? '') : '';
			$cmd = 'runuser -u ' . escapeshellarg($owner) . ' -- sh -c '
				. escapeshellarg(($home !== '' ? 'HOME=' . escapeshellarg($home) . ' ' : '') . $cmd);
		}
		$out = array();
		$exit = 0;
		exec($cmd . ' 2>&1', $out, $exit);
		return array('exit' => (int)$exit, 'out' => $out);
	}

	/**
	 * The account git runs as for this repository: what config/tree_owner
	 * records when the repository is a site root (fix_permissions.sh writes
	 * it for exactly this question), else the owner of .git/index, the file
	 * git rewrites. Null when that is root or unknown.
	 */
	public static function repoOwner($repo_root) {
		$root = rtrim($repo_root, '/');
		$recorded = trim((string)@file_get_contents($root . '/config/tree_owner'));
		if ($recorded !== '' && $recorded !== 'root' && preg_match('/^[a-z_][a-z0-9_-]*$/', $recorded)) {
			return $recorded;
		}
		$uid = @fileowner($root . '/.git/index');
		if ($uid === false || $uid === 0 || !function_exists('posix_getpwuid')) {
			return null;
		}
		$pw = posix_getpwuid($uid);
		return is_array($pw) && !empty($pw['name']) ? $pw['name'] : null;
	}

	/** The HEAD commit, or null when the directory is not a repository. */
	public static function head($repo_root) {
		$r = self::git($repo_root, array('rev-parse', 'HEAD'));
		return ($r['exit'] === 0 && preg_match('/^[0-9a-f]{40}$/', trim($r['out'][0] ?? ''))) ? trim($r['out'][0]) : null;
	}

	/**
	 * Every path `git status --porcelain` reports, split into the two kinds
	 * that matter: tracked-and-changed, and untracked. Paths are repository
	 * relative; renames report the new name.
	 *
	 * @return array{changed:string[], untracked:string[]}|null null when git failed
	 */
	public static function status($repo_root) {
		$r = self::git($repo_root, array('status', '--porcelain', '--untracked-files=all', '--no-renames'));
		if ($r['exit'] !== 0) {
			return null;
		}
		$changed = array();
		$untracked = array();
		foreach ($r['out'] as $line) {
			if (strlen($line) < 4) {
				continue;
			}
			$code = substr($line, 0, 2);
			$path = substr($line, 3);
			if ($code === '??') {
				$untracked[] = $path;
			} else {
				$changed[] = $path;
			}
		}
		return array('changed' => $changed, 'untracked' => $untracked);
	}

	/**
	 * Whether an untracked path in the joinery repository would ship. Pure, so
	 * the rule can be asserted directly.
	 */
	public static function joineryPathShips($rel) {
		$rel = ltrim(str_replace('\\', '/', $rel), '/');
		if (in_array($rel, self::JOINERY_SHIP_FILES, true)) {
			return true;
		}
		$under_root = false;
		foreach (self::JOINERY_SHIP_ROOTS as $root) {
			if (strpos($rel, $root . '/') === 0) {
				$under_root = true;
				break;
			}
		}
		if (!$under_root) {
			return false;
		}
		// The manifest's exclusion rule is the archive's: what it never lists,
		// the archive never carries.
		return !PackageSignature::excluded($rel);
	}

	/**
	 * The files that stop the joinery repository from being a release.
	 *
	 * @return string[]|null repository-relative paths; null when git failed
	 */
	public static function joineryBlockers($repo_root) {
		$status = self::status($repo_root);
		if ($status === null) {
			return null;
		}
		$blockers = $status['changed'];
		foreach ($status['untracked'] as $path) {
			if (self::joineryPathShips($path)) {
				$blockers[] = $path;
			}
		}
		sort($blockers);
		return $blockers;
	}

	/**
	 * Every file git does not ignore: the tracked files, and untracked ones no
	 * ignore rule covers. Keys are repository-relative paths.
	 *
	 * @return array<string,bool>|null null when git failed
	 */
	public static function knownFiles($repo_root) {
		$r = self::git($repo_root, array('ls-files', '-z', '--cached', '--others', '--exclude-standard'));
		if ($r['exit'] !== 0) {
			return null;
		}
		// exec() splits on newlines; a path may hold one, so the lines are
		// joined back before splitting on the NULs git separates paths with.
		$known = array();
		foreach (explode("\0", implode("\n", $r['out'])) as $path) {
			if ($path !== '') {
				$known[$path] = true;
			}
		}
		return $known;
	}

	/**
	 * Whether a file ships from the joinery repository: git knows it, or
	 * publish builds it. Pure, so the rule can be asserted directly.
	 *
	 * @param string $rel   repository-relative path (equally, relative to a staged core)
	 * @param array  $known from knownFiles()
	 */
	public static function joineryFileShips($rel, array $known) {
		$rel = ltrim(str_replace('\\', '/', $rel), '/');
		if (isset($known[$rel]) || in_array($rel, self::JOINERY_GENERATED_FILES, true)
			|| basename($rel) === PackageSignature::STATEMENT_NAME) {
			return true;
		}
		foreach (self::JOINERY_GENERATED_DIRS as $dir) {
			if (strpos($rel, $dir) === 0) {
				return true;
			}
		}
		return false;
	}

	/** The files that stop the agent repository from being a release: all of them. */
	public static function agentBlockers($repo_root) {
		$status = self::status($repo_root);
		if ($status === null) {
			return null;
		}
		$blockers = array_merge($status['changed'], $status['untracked']);
		sort($blockers);
		return $blockers;
	}

	/**
	 * The public URL of the repository's origin: an SSH GitHub remote
	 * rewritten to its https form, anything else as it is. The check fetches
	 * this, not `origin`, because a publish runs as root and root holds no
	 * SSH key — and because "public" means fetchable with no credential at
	 * all, which is the fact being checked.
	 */
	public static function publicUrl($repo_root, $remote = 'origin') {
		$r = self::git($repo_root, array('remote', 'get-url', $remote));
		if ($r['exit'] !== 0) {
			return null;
		}
		$url = trim($r['out'][0] ?? '');
		if (preg_match('#^git@([^:]+):(.+)$#', $url, $m)) {
			return 'https://' . $m[1] . '/' . $m[2];
		}
		if (preg_match('#^ssh://git@([^/]+)/(.+)$#', $url, $m)) {
			return 'https://' . $m[1] . '/' . $m[2];
		}
		return $url !== '' ? $url : null;
	}

	/**
	 * Whether $commit is on the public remote: an ancestor of the remote's
	 * $branch after a credential-free fetch of its public URL. The fetch is
	 * the one network call; a box that cannot reach the remote cannot prove
	 * the commit is public, so it is refused with the reason rather than
	 * assumed.
	 *
	 * @return array{on_remote:bool, reason:string}
	 */
	public static function onRemote($repo_root, $commit, $remote = 'origin', $branch = 'main') {
		$url = self::publicUrl($repo_root, $remote);
		if ($url === null) {
			return array('on_remote' => false, 'reason' => "could not fetch: the repository has no {$remote} remote");
		}
		$fetch = self::git($repo_root, array('-c', 'credential.helper=', 'fetch', '--quiet', $url, $branch));
		if ($fetch['exit'] !== 0) {
			return array('on_remote' => false,
				'reason' => "could not fetch {$branch} from {$url}: " . trim(implode(' | ', array_slice($fetch['out'], -3))));
		}
		$anc = self::git($repo_root, array('merge-base', '--is-ancestor', $commit, 'FETCH_HEAD'));
		if ($anc['exit'] === 0) {
			return array('on_remote' => true, 'reason' => substr($commit, 0, 12) . " is on {$url} {$branch}");
		}
		if ($anc['exit'] === 1) {
			return array('on_remote' => false, 'reason' => substr($commit, 0, 12) . " is not on {$url} {$branch}; push first");
		}
		return array('on_remote' => false, 'reason' => 'git merge-base failed: ' . trim(implode(' | ', array_slice($anc['out'], -3))));
	}

	/** Repository-relative files every release writes into the core (D2). */
	const JOINERY_RELEASE_FILES = array('public_html/VERSION', 'maintenance_scripts/install_tools/joinery-install.sql.gz');

	/**
	 * Whether an uncommitted path is one a publish writes, and so one a
	 * publish may commit: VERSION, the install SQL, a file in the parser
	 * jail's prebuilt binary directory, or a plugin or theme manifest whose
	 * only change from HEAD is its version line. Anything else is the owner's
	 * work.
	 */
	public static function publishWrite($repo_root, $rel) {
		$rel = ltrim(str_replace('\\', '/', $rel), '/');
		if (in_array($rel, self::JOINERY_RELEASE_FILES, true) || strpos($rel, ParserJailPublisher::BIN_SUBDIR . '/') === 0) {
			return true;
		}
		if (!preg_match('~^public_html/(plugins/[^/]+/plugin|theme/[^/]+/theme)\.json$~', $rel)) {
			return false;
		}
		$diff = self::git($repo_root, array('diff', '--no-color', '--unified=0', 'HEAD', '--', $rel));
		if ($diff['exit'] !== 0) {
			return false;
		}
		$changed = 0;
		foreach ($diff['out'] as $line) {
			if (preg_match('/^(\+\+\+|---) /', $line) || !preg_match('/^[+-]/', $line)) {
				continue;
			}
			if (!preg_match('/^[+-]\s*"version"\s*:\s*"[^"]*",?\s*$/', $line)) {
				return false;
			}
			$changed++;
		}
		return $changed > 0;
	}

	/**
	 * Commit exactly $paths as $message and push the branch. The paths are
	 * named on the commit, so nothing else staged in the index (another
	 * session's work in a shared tree) goes with them.
	 *
	 * @return array{ok:bool, commit:?string, reason:string}
	 */
	public static function commitRelease($repo_root, array $paths, $message, $remote = 'origin', $branch = 'main') {
		$commit = self::git($repo_root, array_merge(array('commit', '--quiet', '-m', $message, '--'), $paths));
		if ($commit['exit'] !== 0) {
			return array('ok' => false, 'commit' => null, 'reason' => 'git commit failed: ' . trim(implode(' | ', array_slice($commit['out'], -3))));
		}
		$head = self::head($repo_root);
		$push = self::git($repo_root, array('push', '--quiet', $remote, 'HEAD:' . $branch));
		if ($push['exit'] !== 0) {
			return array('ok' => false, 'commit' => $head, 'reason' => "committed {$head}, but git push to {$remote} {$branch} failed: "
				. trim(implode(' | ', array_slice($push['out'], -3))));
		}
		return array('ok' => true, 'commit' => $head, 'reason' => '');
	}
}
?>
