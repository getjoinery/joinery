<?php
/**
 * TestTierStamp - the runner's record that a tier passed on an exact tree.
 *
 * A publish archives whatever is on the publisher's disk, so it wants proof that
 * the development tiers passed on that exact content. It cannot run them itself:
 * the publish job runs as root through the local job queue, and the development
 * tiers exercise installers and system tools inside sandboxes that only hold for
 * an unprivileged user (docs/testing.md § deploy is not a development tier). So
 * the developer runs the tier, the runner stamps the tree it passed on, and the
 * publisher checks the stamp against the tree it is about to archive.
 *
 * The tree identity is the CONTENT of every file in the working tree, tracked or
 * untracked, keyed by path — nothing about git state. Editing, adding or deleting
 * a file changes it; committing, staging or amending does not, because the bytes
 * a publish would archive are the same bytes either way. Unchanged tracked files
 * contribute the blob hash git already holds in the index, so identifying a
 * 4,000-file tree costs a few file reads, not a few thousand. Ignored paths
 * (cache/, logs/, uploads/) are not part of it, so writing the stamp does not
 * invalidate it.
 *
 * Stored at {site root}/cache/test_tier_stamp.json, one entry per tier. Only a
 * FULL run of a tier writes or clears its entry - a run narrowed by --filter,
 * --only or --changed proves nothing about the tier as a whole.
 *
 * @version 1.1 - identity is file content only; a commit of the same bytes keeps the stamp valid
 * @version 1.0
 */
class TestTierStamp {

	const FILE = 'test_tier_stamp.json';

	/** Stamp file for a public_html root. */
	public static function path($public_html) {
		return dirname($public_html) . '/cache/' . self::FILE;
	}

	/**
	 * Identify the working tree of the repository containing $public_html.
	 *
	 * @return array|null ['id' => sha256, 'head' => commit (informational),
	 *                     'files' => [path => blob hash]] or null when there is
	 *                     no git or no repository here.
	 */
	public static function treeId($public_html) {
		$repo = dirname($public_html);
		$head = self::git($repo, 'rev-parse HEAD');
		if ($head === null || !preg_match('/^[0-9a-f]{40,64}$/', $head)) {
			return null;
		}
		// Every tracked file, with the blob hash the index holds for it.
		$index = self::git($repo, 'ls-files -s -z');
		if ($index === null) return null;
		$files = array();
		foreach (explode("\0", $index) as $entry) {
			if ($entry === '' || !preg_match('/^\d+ ([0-9a-f]+) \d\t(.+)$/s', $entry, $m)) continue;
			$files[$m[2]] = $m[1];
		}
		$algo = (isset($m[1]) && strlen($m[1]) === 64) ? 'sha256' : 'sha1';
		// Tracked files whose working copy differs from the index: hash the copy
		// (or drop a deletion). --name-status -z emits status\0path\0.
		$dirty = self::git($repo, 'diff-files -z --name-status');
		if ($dirty === null) return null;
		$parts = explode("\0", $dirty);
		for ($i = 0; $i + 1 < count($parts); $i += 2) {
			$status = $parts[$i]; $path = $parts[$i + 1];
			if ($path === '') continue;
			if ($status[0] === 'D') { unset($files[$path]); continue; }
			$files[$path] = self::blobHash($repo . '/' . $path, $algo);
		}
		// Untracked, not ignored: content that is about to ship all the same.
		$untracked = self::git($repo, 'ls-files -o --exclude-standard -z');
		if ($untracked === null) return null;
		foreach (explode("\0", $untracked) as $path) {
			if ($path === '') continue;
			$files[$path] = self::blobHash($repo . '/' . $path, $algo);
		}
		ksort($files, SORT_STRING);
		$material = '';
		foreach ($files as $path => $hash) {
			$material .= $path . "\0" . $hash . "\n";
		}
		return array('id' => hash('sha256', $material), 'head' => $head, 'files' => $files);
	}

	/** Record a PASS for each tier on this tree. */
	public static function record($public_html, array $tiers, array $tree, array $totals) {
		$all = self::read($public_html);
		$entry = array(
			'tree_id'   => $tree['id'],
			'head'      => $tree['head'],
			'files'     => $tree['files'],
			'passed_at' => gmdate('c'),
			'user'      => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : (string)getenv('USER'),
			'totals'    => $totals,
		);
		foreach ($tiers as $tier) {
			$all[$tier] = $entry;
		}
		return self::write($public_html, $all);
	}

	/** Forget the stamps for tiers that just failed a full run. */
	public static function clear($public_html, array $tiers) {
		$all = self::read($public_html);
		$changed = false;
		foreach ($tiers as $tier) {
			if (isset($all[$tier])) {
				unset($all[$tier]);
				$changed = true;
			}
		}
		return $changed ? self::write($public_html, $all) : true;
	}

	/** All stamps, keyed by tier. */
	public static function read($public_html) {
		$path = self::path($public_html);
		if (!is_file($path)) return array();
		$data = json_decode((string)file_get_contents($path), true);
		return is_array($data) ? $data : array();
	}

	/**
	 * Does a PASS stamp for $tier describe the tree on disk right now?
	 *
	 * @return array ['ok' => bool, 'reason' => string, 'stamp' => array|null, 'changed' => string[]]
	 *               `changed` names the paths whose content differs from the stamped tree,
	 *               so a refusal says what to look at.
	 */
	public static function verify($public_html, $tier) {
		$all = self::read($public_html);
		$stamp = isset($all[$tier]) && is_array($all[$tier]) ? $all[$tier] : null;
		if ($stamp === null) {
			return array('ok' => false, 'stamp' => null, 'changed' => array(),
				'reason' => "no PASS stamp for the {$tier} tier");
		}
		$tree = self::treeId($public_html);
		if ($tree === null) {
			return array('ok' => false, 'stamp' => $stamp, 'changed' => array(),
				'reason' => 'the tree cannot be identified (no git repository here)');
		}
		if ($tree['id'] === $stamp['tree_id']) {
			return array('ok' => true, 'stamp' => $stamp, 'changed' => array(), 'reason' => '');
		}
		$changed = array();
		$stamped_files = isset($stamp['files']) && is_array($stamp['files']) ? $stamp['files'] : array();
		foreach (array_unique(array_merge(array_keys($stamped_files), array_keys($tree['files']))) as $path) {
			if (($stamped_files[$path] ?? null) !== ($tree['files'][$path] ?? null)) {
				$changed[] = $path;
			}
		}
		return array('ok' => false, 'stamp' => $stamp, 'changed' => $changed,
			'reason' => "the {$tier} stamp is for a different tree (passed " . ($stamp['passed_at'] ?? '?') . ')');
	}

	// -------------------------------------------------------------------------

	private static function write($public_html, array $all) {
		$path = self::path($public_html);
		$dir = dirname($path);
		if (!is_dir($dir)) return false;
		$tmp = $path . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT)) === false) return false;
		@chmod($tmp, 0666);
		if (!@rename($tmp, $path)) {
			@unlink($tmp);
			return false;
		}
		return true;
	}

	/**
	 * Git's own object hash for a file, so a working copy that matches the index
	 * gets the same value the index carries: "blob <size>\0<content>".
	 */
	private static function blobHash($abs, $algo) {
		if (is_dir($abs) || !is_file($abs)) return 'missing';
		$size = filesize($abs);
		$ctx = hash_init($algo);
		hash_update($ctx, 'blob ' . $size . "\0");
		$fh = @fopen($abs, 'rb');
		if ($fh === false) return 'unreadable';
		hash_update_stream($ctx, $fh);
		fclose($fh);
		return hash_final($ctx);
	}

	/**
	 * Run one git command in $repo. safe.directory is passed explicitly because
	 * the publisher runs as root over a checkout owned by another account, and
	 * git refuses "dubious ownership" without it. Returns null on any failure.
	 */
	private static function git($repo, $args) {
		if (!is_dir($repo . '/.git')) return null;
		$cmd = 'git -c ' . escapeshellarg('safe.directory=' . $repo) . ' -C ' . escapeshellarg($repo) . ' ' . $args
			. ' 2>/dev/null; printf "\n__GIT_RC=%d" "$?"';
		$raw = @shell_exec($cmd);
		if (!is_string($raw) || !preg_match('/\n__GIT_RC=(\d+)$/', $raw, $m) || (int)$m[1] !== 0) return null;
		return trim(substr($raw, 0, -strlen($m[0])), "\n");
	}
}
