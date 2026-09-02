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
 * The tree identity is the HEAD commit plus every path git reports as changed or
 * untracked, each with a content hash. Editing a tracked file, adding a file,
 * committing, or deleting a file all change the identity; a stamp is evidence
 * for one tree and nothing else. Ignored paths (cache/, logs/, uploads/) are not
 * part of it, so writing the stamp does not invalidate it.
 *
 * Stored at {site root}/cache/test_tier_stamp.json, one entry per tier. Only a
 * FULL run of a tier writes or clears its entry - a run narrowed by --filter,
 * --only or --changed proves nothing about the tier as a whole.
 *
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
	 * @return array|null ['id' => sha256, 'head' => commit, 'files' => [path => hash]]
	 *                    or null when there is no git or no repository here.
	 */
	public static function treeId($public_html) {
		$repo = dirname($public_html);
		$head = self::git($repo, 'rev-parse HEAD');
		if ($head === null || !preg_match('/^[0-9a-f]{40,64}$/', $head)) {
			return null;
		}
		$status = self::git($repo, 'status --porcelain=v1 -z --untracked-files=all');
		if ($status === null) {
			return null;
		}
		$files = array();
		$entries = explode("\0", $status);
		for ($i = 0; $i < count($entries); $i++) {
			$entry = $entries[$i];
			if ($entry === '') continue;
			$code = substr($entry, 0, 2);
			$path = substr($entry, 3);
			// A rename or copy carries the original path in the next record.
			if (strpos($code, 'R') !== false || strpos($code, 'C') !== false) {
				$i++;
				if (isset($entries[$i])) {
					$files[$entries[$i]] = 'deleted';
				}
			}
			$files[$path] = self::hashPath($repo . '/' . $path);
		}
		ksort($files, SORT_STRING);
		$material = $head . "\n";
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
		if ($tree['head'] !== ($stamp['head'] ?? '')) {
			$changed[] = 'HEAD moved from ' . substr((string)$stamp['head'], 0, 10) . ' to ' . substr($tree['head'], 0, 10);
		}
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

	private static function hashPath($abs) {
		if (is_dir($abs)) return 'dir';
		if (!file_exists($abs)) return 'deleted';
		$h = @hash_file('sha256', $abs);
		return $h === false ? 'unreadable' : $h;
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
