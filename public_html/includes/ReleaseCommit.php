<?php
/**
 * ReleaseCommit - a release is a commit, and this is how the publisher knows.
 *
 * spec release_transparency D1: publish refuses to build unless the trees
 * it ships from are committed and on the public remote. "A release is a commit"
 * is what makes the archive reproducible by anyone: a verifier with the commit
 * can regenerate every manifest line, which an archive built from an uncommitted
 * working tree can never offer.
 *
 * A release is BUILT FROM THE COMMIT, not from the working tree: exportTree()
 * writes the commit's shipping paths into a build directory, and every archive,
 * manifest and tree hash is cut from there. Uncommitted work in the tree -
 * another session's half-finished edit in a shared checkout - is simply not
 * in the release, so it does not stop one.
 *
 * What is uncommitted is still read (joineryBlockers()), and split three ways:
 *
 * - publish's own files (publishWrite()), which it commits itself;
 * - HELD paths (heldPath()), whose uncommitted change still refuses, because
 *   the release reads them from somewhere other than the commit: the install
 *   SQL is dumped from this box's database, so a data class or migration
 *   update_database already applied is in it; the parser jail, the relay
 *   sealer and the mail gateway are compiled from their source on this
 *   disk; the release key lists are read from this disk into the agent bundle; a plugin or theme manifest
 *   is where publish writes its version bump and commits the whole file;
 *   publish's own code (publish_upgrade.php, this class, every *Publisher
 *   class) runs from this disk and decides what the release holds - which
 *   scripts the support bundle carries, what each manifest lists - so a
 *   half-finished edit there would build this release to another commit's plan;
 * - everything else, which is LEFT OUT: named in the publish output, absent
 *   from the release, and no reason to refuse it.
 *
 * Which paths count: every tracked file modified or deleted anywhere, and
 * every untracked, un-ignored file on a path that SHIPS: under public_html/ or
 * maintenance_scripts/, except paths the archive never carries, which are
 * exactly the ones the release manifest never lists (PackageSignature::excluded():
 * specs/, uploads/, cache/, logs/, backups/, .claude/, node_modules/, .git,
 * top-level config/ and vendor/).
 *
 * The agent repository is built from its commit the same way (exportCommit()),
 * and nothing in it is read from the working tree, so none of it is held:
 * every uncommitted change there is left out.
 *
 * What SHIPS from the joinery repository is narrower than what sits on disk:
 * a file git knows (tracked, or untracked and not ignored - the second kind
 * is a blocker above, so at build time it is always the first), or one of the
 * few files publish builds itself (D2: the agent bundle, the relay sealer and
 * mail gateway binaries, the license copies, each release statement). An ignored file is
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
 * @version 1.11 - restoreWrites() and fastForward(), so a publish that refuses leaves the checkout as it
 *               found it and can catch up with the remote; commitRelease() flags a moved remote
 * @version 1.10 - commitRelease() refuses when the remote branch moved during the publish, and undoes its
 *               commit when the push is refused, so a fast-forward follower is never left off the branch (B172)
 * @version 1.9 - publish's own code is held: publish_upgrade.php, ReleaseCommit and every *Publisher class run
 *               from this disk, and an uncommitted support bundle list named scripts the commit did not have,
 *               so 0.8.496 shipped with no bundle at all (B70)
 * @version 1.8 - the sandboxed plugin host's source is held and its bin/ is publish's to commit, like the parser jail's
 * @version 1.7 - the site container engine's source is held and its ENGINE record is publish's to commit
 *                (specs/site_container_engine.md D1, D2)
 * @version 1.6 - blockerNotes(): what each refusing file is, when it was written, and which session prepared it
 * @version 1.5.2 - the mail gateway's bin/ is generated and its source held, beside the relay sealer's
 * @version 1.5.1 - the build is written 644/755 (tar.umask 0022), not git's group-writable default
 * @version 1.5 - the agent is built from its commit too (exportCommit()); uncommitted agent work is left out,
 *                like the core's, instead of refusing the release
 * @version 1.4 - a release is built from the commit (exportTree(), commitFiles()); only a HELD path
 *                (heldPath()) still refuses while uncommitted, everything else is left out and named
 * @version 1.3 - commitRelease() adds its paths before committing them, so a file new to git is committed too
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
	 * bundle and the bin/ of every Go program a plugin ships prebuilt
	 * (GoBinaryPublisher::PLUGIN_PUBLISHERS). Each is checked by the release
	 * statement's own record of it, not by the commit.
	 */
	const JOINERY_GENERATED_DIRS = array(
		'public_html/agent_dist/',
		'public_html/plugins/mailbox/provisioning/bin/',    // RelaySealerPublisher::BIN_SUBDIR
		'public_html/plugins/mail_apps/provisioning/bin/',  // MailGatewayPublisher::BIN_SUBDIR
	);

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
		$out = array();
		$exit = 0;
		exec(self::gitCommand($repo_root, $args) . ' 2>&1', $out, $exit);
		return array('exit' => (int)$exit, 'out' => $out);
	}

	/** The shell command git() runs, for a caller that pipes its output instead of reading lines. */
	private static function gitCommand($repo_root, array $args) {
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
		return $cmd;
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
	 * Repository-relative paths whose uncommitted change still refuses a
	 * release built from the commit, because the release reads them from this
	 * box and not from the commit. See the class comment for why each is here.
	 */
	const JOINERY_HELD_FILES = array(
		'public_html/settings.json',             // seeded into the database the install SQL is dumped from
		'public_html/admin_menus.json',          // likewise
		'public_html/install_bundles.json',      // read from this disk by publish's own bundle guard
		'public_html/utils/create_install_sql.php', // what dumps the install SQL
		'public_html/plugins/server_manager/includes/publish_upgrade.php', // publish itself, run from this disk
		'public_html/includes/ReleaseCommit.php',  // what decides what a release holds, likewise
	);

	/** Repository-relative directories held the same way. */
	const JOINERY_HELD_DIRS = array(
		'public_html/data/',                                       // the schema the install SQL is dumped with
		'public_html/migrations/',                                 // likewise
		'maintenance_scripts/install_tools/release_keys/',         // copied into the agent bundle from this disk
		'maintenance_scripts/install_tools/joinery_jail/',         // ParserJailPublisher::SOURCE_SUBDIR, compiled here
		'maintenance_scripts/install_tools/sandbox_host/',         // SandboxHostPublisher::SOURCE_SUBDIR, compiled here
		'maintenance_scripts/install_tools/site_engine/',          // SiteEnginePublisher::SOURCE_SUBDIR, built here
		'public_html/plugins/mailbox/provisioning/relay-sealer/',  // RelaySealerPublisher::SOURCE_SUBDIR, compiled here
		'public_html/plugins/mail_apps/provisioning/mail-gateway/', // MailGatewayPublisher::SOURCE_SUBDIR, compiled here
	);

	/**
	 * Whether an uncommitted path refuses a release (held) rather than being
	 * left out of it. Pure, so the rule can be asserted directly. A plugin's or
	 * theme's manifest is held because publish writes its version bump into
	 * that file and commits the whole file; a plugin's data classes and
	 * migrations for the same reason as core's. A *Publisher class, core's or
	 * a plugin's, builds part of the release from this disk (B70).
	 */
	public static function heldPath($rel) {
		$rel = ltrim(str_replace('\\', '/', $rel), '/');
		if (in_array($rel, self::JOINERY_HELD_FILES, true)) {
			return true;
		}
		foreach (self::JOINERY_HELD_DIRS as $dir) {
			if (strpos($rel, $dir) === 0) {
				return true;
			}
		}
		return (bool)preg_match('~^public_html/(plugins/[^/]+/(data|migrations)/|plugins/[^/]+/plugin\.json$|theme/[^/]+/theme\.json$'
			. '|(plugins/[^/]+/)?includes/[A-Za-z0-9]+Publisher\.php$)~', $rel);
	}

	/**
	 * The files of one commit, keyed by repository-relative path: knownFiles()
	 * for a release built from that commit. Read from the commit, not the
	 * index, so another session's staging cannot add to or take from it.
	 *
	 * @return array<string,bool>|null null when git failed
	 */
	public static function commitFiles($repo_root, $commit) {
		$r = self::git($repo_root, array('ls-tree', '-r', '-z', '--name-only', $commit));
		if ($r['exit'] !== 0) {
			return null;
		}
		$files = array();
		foreach (explode("\0", implode("\n", $r['out'])) as $path) {
			if ($path !== '') {
				$files[$path] = true;
			}
		}
		return $files;
	}

	/**
	 * Write $commit's shipping paths (JOINERY_SHIP_ROOTS, JOINERY_SHIP_FILES)
	 * into the empty directory $to_dir, laid out like the repository, then copy
	 * in from the working tree the directories publish builds and no commit
	 * holds (overlayGenerated()). That directory is the release: what the
	 * commit says, plus what publish built. Nothing else in the working tree
	 * reaches it.
	 *
	 * @return array{ok:bool, reason:string}
	 */
	public static function exportTree($repo_root, $commit, $to_dir) {
		$repo_root = rtrim($repo_root, '/');
		$to_dir = rtrim($to_dir, '/');
		if (!preg_match('/^[0-9a-f]{40}$/', (string)$commit)) {
			return array('ok' => false, 'reason' => 'not a commit id: ' . $commit);
		}
		if (!is_dir($to_dir) || (new FilesystemIterator($to_dir))->valid()) {
			return array('ok' => false, 'reason' => $to_dir . ' is not an empty directory');
		}
		$paths = array();
		foreach (array_merge(self::JOINERY_SHIP_ROOTS, self::JOINERY_SHIP_FILES) as $path) {
			// git archive refuses a pathspec that matches nothing.
			if (self::git($repo_root, array('cat-file', '-e', $commit . ':' . $path))['exit'] === 0) {
				$paths[] = $path;
			}
		}
		if ($paths === array()) {
			return array('ok' => false, 'reason' => 'commit ' . $commit . ' holds none of the paths a release ships');
		}
		$archived = self::archiveInto($repo_root, $commit, $paths, $to_dir);
		return $archived['ok'] ? self::overlayGenerated($repo_root, $to_dir) : $archived;
	}

	/**
	 * Write the whole of $commit into the empty directory $to_dir: the agent
	 * source a release's agent is built from, so an uncommitted edit beside it
	 * is not compiled into the release.
	 *
	 * @return array{ok:bool, reason:string}
	 */
	public static function exportCommit($repo_root, $commit, $to_dir) {
		$to_dir = rtrim($to_dir, '/');
		if (!preg_match('/^[0-9a-f]{40}$/', (string)$commit)) {
			return array('ok' => false, 'reason' => 'not a commit id: ' . $commit);
		}
		if (!is_dir($to_dir) || (new FilesystemIterator($to_dir))->valid()) {
			return array('ok' => false, 'reason' => $to_dir . ' is not an empty directory');
		}
		return self::archiveInto(rtrim($repo_root, '/'), $commit, array(), $to_dir);
	}

	/**
	 * git archive $commit (limited to $paths when any are given) into $to_dir.
	 * With tar.umask 0022: git's default (0002) writes every file group-writable,
	 * and the build is what the archives are cut from, so they would ship 664
	 * files where a site's tree is held at 644.
	 */
	private static function archiveInto($repo_root, $commit, array $paths, $to_dir) {
		$args = array('-c', 'tar.umask=0022', 'archive', '--format=tar', $commit);
		if ($paths !== array()) {
			$args = array_merge($args, array('--'), $paths);
		}
		$out = array();
		$exit = 0;
		exec('bash -c ' . escapeshellarg('set -o pipefail; ' . self::gitCommand($repo_root, $args)
			. ' | tar -xf - --no-same-owner -C ' . escapeshellarg($to_dir)) . ' 2>&1', $out, $exit);
		if ($exit !== 0) {
			return array('ok' => false, 'reason' => 'git archive of ' . $commit . ' failed: ' . trim(implode(' | ', array_slice($out, -3))));
		}
		return array('ok' => true, 'reason' => '');
	}

	/**
	 * Copy the directories publish builds (JOINERY_GENERATED_DIRS) from the
	 * working tree into a build directory, replacing what is there: called by
	 * exportTree(), and again by a publish whose builds ran after its export.
	 *
	 * @return array{ok:bool, reason:string}
	 */
	public static function overlayGenerated($repo_root, $to_dir) {
		$repo_root = rtrim($repo_root, '/');
		$to_dir = rtrim($to_dir, '/');
		foreach (self::JOINERY_GENERATED_DIRS as $dir) {
			$from = $repo_root . '/' . rtrim($dir, '/');
			$to = $to_dir . '/' . rtrim($dir, '/');
			$out = array();
			$exit = 0;
			exec('rm -rf ' . escapeshellarg($to) . ' 2>&1', $out, $exit);
			if (!is_dir($from)) {
				continue;
			}
			if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0755, true)) {
				return array('ok' => false, 'reason' => 'could not create ' . dirname($to));
			}
			// Group and other write cleared, as the export's are: whatever mode
			// the working tree's copy had, the archive carries no writable file.
			exec('cp -a ' . escapeshellarg($from) . ' ' . escapeshellarg($to) . ' && chmod -R go-w ' . escapeshellarg($to) . ' 2>&1', $out, $exit);
			if ($exit !== 0) {
				return array('ok' => false, 'reason' => 'could not copy ' . $dir . ' into the build: ' . trim(implode(' | ', array_slice($out, -3))));
			}
		}
		return array('ok' => true, 'reason' => '');
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

	/**
	 * Everything uncommitted that would stop a publish from this box, before
	 * the publish is queued: the HELD changes in the core repository
	 * (heldPath(); not the files a publish writes itself), and any repository
	 * git cannot read. The check the publish job makes first,
	 * asked ahead of it so the button is not offered while the job could only
	 * refuse. Every other uncommitted change, in the core or the agent source,
	 * is in 'left_out' (agent paths prefixed 'agent: '): it does not refuse, it
	 * is simply not in the release.
	 *
	 * @param string|null $agent_root null when the box has no agent source path
	 * @return array{core:string[], left_out:string[], agent:string[], unreadable:string[]} 'agent' is
	 *         always empty: nothing in the agent source is held
	 *         'unreadable' names a repository git could not read, with the reason
	 */
	public static function ownerBlockers($core_root, $agent_root) {
		$out = array('core' => array(), 'left_out' => array(), 'agent' => array(), 'unreadable' => array());
		$core = self::joineryBlockers($core_root);
		if ($core === null) {
			$out['unreadable'][] = 'the core repository at ' . $core_root . ' could not be read by git';
		} else {
			foreach ($core as $path) {
				if (self::publishWrite($core_root, $path)) {
					continue;
				}
				if (self::heldPath($path)) {
					$out['core'][] = $path;
				} else {
					$out['left_out'][] = $path;
				}
			}
		}
		if ($agent_root === null || $agent_root === '' || !is_dir($agent_root . '/.git')) {
			$out['unreadable'][] = 'the agent source at ' . ($agent_root ?: '(no path set)')
				. ' is not a git checkout on this box, and a release names the agent commit it was built from';
		} else {
			$agent = self::agentBlockers($agent_root);
			if ($agent === null) {
				$out['unreadable'][] = 'the agent repository at ' . $agent_root . ' could not be read by git';
			} else {
				foreach ($agent as $path) {
					$out['left_out'][] = 'agent: ' . $path;
				}
			}
		}
		return $out;
	}

	/**
	 * What a person needs to deal with each file that refuses a publish: how big
	 * the change is, what it adds (the first added @version line, function or
	 * class), when the file was last written, the commit that last touched it,
	 * and the session whose prepared commit already holds it. The refusal alone
	 * names a file and nothing else, and the person publishing often did not
	 * make the change.
	 *
	 * A prepared commit is a session's private index under .git/work-commits/NAME
	 * (its 'paths' file is NUL-separated, repository-relative; 'message' is the
	 * commit message); a box without them says nothing about it.
	 *
	 * @param string[] $paths repository-relative
	 * @return array<string,string[]> path => short lines
	 */
	public static function blockerNotes($repo_root, array $paths) {
		$prepared = array();
		$dir = $repo_root . '/.git/work-commits';
		foreach (is_dir($dir) ? (scandir($dir) ?: array()) : array() as $name) {
			if ($name === '.' || $name === '..' || !is_file("{$dir}/{$name}/paths")) {
				continue;
			}
			$held = array_filter(explode("\0", (string)@file_get_contents("{$dir}/{$name}/paths")));
			$message = (string)@file_get_contents("{$dir}/{$name}/message");
			foreach ($held as $held_path) {
				$prepared[$held_path][] = array($name, trim((string)strtok($message, "\n")));
			}
		}
		$notes = array();
		foreach ($paths as $path) {
			$lines = array();
			$abs = $repo_root . '/' . $path;
			$stat = self::git($repo_root, array('diff', '--numstat', 'HEAD', '--', $path));
			$numstat = ($stat['exit'] === 0 && isset($stat['out'][0])) ? preg_split('/\s+/', trim($stat['out'][0])) : null;
			if (!is_file($abs)) {
				$what = 'deleted';
			} elseif ($numstat !== null && count($numstat) >= 2) {
				$what = '+' . $numstat[0] . ' -' . $numstat[1] . ' lines';
			} else {
				$what = 'a new file';
			}
			$when = is_file($abs) ? ', last written ' . gmdate('Y-m-d H:i', (int)filemtime($abs)) . ' UTC' : '';
			$lines[] = $what . $when;
			$diff = self::git($repo_root, array('diff', '-U0', 'HEAD', '--', $path));
			$adds = array();
			foreach ($diff['exit'] === 0 ? $diff['out'] : array() as $d) {
				if (isset($d[0]) && $d[0] === '+' && strpos($d, '+++') !== 0
					&& preg_match('/@version|function\s+\w+\s*\(|^\+\s*class\s+\w+|"version"/', $d)) {
					$adds[] = trim(substr($d, 1), " \t*/");
				}
				if (count($adds) >= 2) {
					break;
				}
			}
			foreach ($adds as $a) {
				$lines[] = 'adds: ' . (strlen($a) > 110 ? substr($a, 0, 107) . '...' : $a);
			}
			$last = self::git($repo_root, array('log', '-1', '--format=%h %cd %s', '--date=format:%Y-%m-%d', '--', $path));
			if ($last['exit'] === 0 && isset($last['out'][0]) && $last['out'][0] !== '') {
				$lines[] = 'last commit: ' . (strlen($last['out'][0]) > 100 ? substr($last['out'][0], 0, 97) . '...' : $last['out'][0]);
			}
			foreach ($prepared[$path] ?? array() as $p) {
				$lines[] = "already prepared by session '{$p[0]}' ({$p[1]}): it commits with: work {$p[0]} --commit";
			}
			if (empty($prepared[$path])) {
				$lines[] = 'no session has prepared this file';
			}
			$notes[$path] = $lines;
		}
		return $notes;
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
	 * jail's prebuilt binary directory, the site container engine's ENGINE
	 * record, or a plugin or theme manifest whose
	 * only change from HEAD is its version line. Anything else is the owner's
	 * work.
	 */
	public static function publishWrite($repo_root, $rel) {
		$rel = ltrim(str_replace('\\', '/', $rel), '/');
		if (in_array($rel, self::JOINERY_RELEASE_FILES, true) || strpos($rel, ParserJailPublisher::BIN_SUBDIR . '/') === 0
			|| strpos($rel, SandboxHostPublisher::BIN_SUBDIR . '/') === 0
			|| $rel === SiteEnginePublisher::SOURCE_SUBDIR . '/' . SiteEnginePublisher::STAMP_FILE) {
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
	 * The release goes on the commit it was built from, and that commit must
	 * still be the remote branch's head: a commit pushed elsewhere while the
	 * publish ran (from localdev, 2026-10-27, B172) makes the push a non-fast-
	 * forward. So the remote is read first and a moved branch is refused with
	 * nothing committed. A push refused anyway (a commit landing in the
	 * seconds between) undoes the release commit, so a checkout that follows
	 * the branch by fast-forward (dev's follow_main.sh) is not left off it.
	 *
	 * @return array{ok:bool, commit:?string, reason:string, moved?:bool} moved: the
	 *         remote branch was not the commit this release was built from
	 */
	public static function commitRelease($repo_root, array $paths, $message, $remote = 'origin', $branch = 'main') {
		$base = self::head($repo_root);
		$fetch = self::git($repo_root, array('fetch', '--quiet', $remote, $branch));
		if ($fetch['exit'] === 0) {
			$remote_head = self::git($repo_root, array('rev-parse', 'FETCH_HEAD'));
			$remote_head = trim($remote_head['out'][0] ?? '');
			if ($base !== null && $remote_head !== '' && $remote_head !== $base) {
				return array('ok' => false, 'commit' => null, 'moved' => true, 'reason' => "{$remote} {$branch} moved to "
					. substr($remote_head, 0, 12) . ' while this release was built from ' . substr($base, 0, 12)
					. '. Nothing was committed. Publish again once this checkout has the new commit; it builds from that.');
			}
		}
		// Added first: a path git does not know yet (a new jail binary, a first
		// statement key's .pub) is refused by a commit that names it. Naming
		// the paths on the commit still commits only them; whatever else sits
		// staged in the shared index stays staged.
		$add = self::git($repo_root, array_merge(array('add', '--'), $paths));
		if ($add['exit'] !== 0) {
			return array('ok' => false, 'commit' => null, 'reason' => 'git add failed: ' . trim(implode(' | ', array_slice($add['out'], -3))));
		}
		$commit = self::git($repo_root, array_merge(array('commit', '--quiet', '-m', $message, '--'), $paths));
		if ($commit['exit'] !== 0) {
			return array('ok' => false, 'commit' => null, 'reason' => 'git commit failed: ' . trim(implode(' | ', array_slice($commit['out'], -3))));
		}
		$head = self::head($repo_root);
		$push = self::git($repo_root, array('push', '--quiet', $remote, 'HEAD:' . $branch));
		if ($push['exit'] !== 0) {
			$reason = "committed {$head}, but git push to {$remote} {$branch} failed: "
				. trim(implode(' | ', array_slice($push['out'], -3)));
			$undo = $base === null ? array('exit' => 1, 'out' => array('no commit to return to'))
				: self::undoRelease($repo_root, $base, $paths);
			$reason .= $undo['exit'] === 0
				? '. The release commit was undone; this checkout is back on ' . substr($base, 0, 12) . '.'
				: '. Undoing the release commit failed too (' . trim(implode(' | ', array_slice($undo['out'], -2)))
					. '): this checkout is a commit ahead of ' . $remote . ' ' . $branch . ' until it is reset to '
					. ($base === null ? 'it' : substr($base, 0, 12)) . '.';
			return array('ok' => false, 'commit' => $head, 'reason' => $reason);
		}
		return array('ok' => true, 'commit' => $head, 'reason' => '');
	}

	/**
	 * Put the checkout back on $base after a release commit that never left
	 * it: HEAD moves back, and each release path returns to what $base holds
	 * (one $base never had, a first jail binary, is removed). Only those paths
	 * are touched, so whatever else is staged in a shared index stays staged.
	 *
	 * @return array{exit:int, out:string[]}
	 */
	private static function undoRelease($repo_root, $base, array $paths) {
		$reset = self::git($repo_root, array('reset', '--quiet', '--soft', $base));
		if ($reset['exit'] !== 0) {
			return $reset;
		}
		return self::restoreWrites($repo_root, $paths, $base);
	}

	/**
	 * Put the files a publish wrote back to what $source (HEAD by default)
	 * holds, in the index and on disk; a file $source never had is removed.
	 * A publish that refuses after writing calls it, so the checkout is left
	 * as it found it — dev's follow_main.sh will not pull over local changes.
	 *
	 * @return array{exit:int, out:string[]}
	 */
	public static function restoreWrites($repo_root, array $paths, $source = 'HEAD') {
		foreach ($paths as $path) {
			if (self::git($repo_root, array('cat-file', '-e', $source . ':' . $path))['exit'] === 0) {
				$step = self::git($repo_root, array('restore', '--source=' . $source, '--staged', '--worktree', '--', $path));
			} else {
				// New to git: staged (a release commit undone) or never added.
				$step = self::git($repo_root, array('rm', '--quiet', '--cached', '--ignore-unmatch', '--', $path));
				@unlink(rtrim($repo_root, '/') . '/' . $path);
			}
			if ($step['exit'] !== 0) {
				return $step;
			}
		}
		return array('exit' => 0, 'out' => array());
	}

	/**
	 * Bring a clean checkout up to the remote branch by fast-forward only, as
	 * dev's follow_main.sh does. Refuses local changes and a branch that is
	 * not a fast-forward.
	 *
	 * @return array{ok:bool, reason:string, head:?string}
	 */
	public static function fastForward($repo_root, $remote = 'origin', $branch = 'main') {
		$status = self::status($repo_root);
		if ($status === null || $status['changed'] !== array() || $status['untracked'] !== array()) {
			return array('ok' => false, 'reason' => 'the checkout has local changes', 'head' => self::head($repo_root));
		}
		$fetch = self::git($repo_root, array('fetch', '--quiet', $remote, $branch));
		if ($fetch['exit'] !== 0) {
			return array('ok' => false, 'reason' => 'could not fetch: ' . trim(implode(' | ', array_slice($fetch['out'], -2))), 'head' => self::head($repo_root));
		}
		$merge = self::git($repo_root, array('merge', '--quiet', '--ff-only', 'FETCH_HEAD'));
		if ($merge['exit'] !== 0) {
			return array('ok' => false, 'reason' => "{$remote} {$branch} is not a fast-forward of this checkout", 'head' => self::head($repo_root));
		}
		return array('ok' => true, 'reason' => '', 'head' => self::head($repo_root));
	}
}
?>
