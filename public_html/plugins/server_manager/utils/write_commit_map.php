<?php
/**
 * write_commit_map.php - sign, log and write the commit map after the public
 * history is rewritten (spec specs_private_repo, WP3 and WP4 step 6).
 *
 * Usage (on the origin, as root, the one reader of config/release_statement_key;
 * once per rewrite):
 *
 *   php plugins/server_manager/utils/write_commit_map.php \
 *       --filter-repo-map=MIRROR/filter-repo/commit-map --mirror=MIRROR [--out=FILE] [--dry-run]
 *
 *   --filter-repo-map=F  git filter-repo's commit-map from the rewritten mirror: a header line,
 *                        then "old new" for every commit
 *   --mirror=DIR         the rewritten repository; every new commit must be on its main
 *   --out=FILE           where the map is written, appending when the file exists
 *                        (default: this site's ReleaseHistoryMap::PATH)
 *   --reason=TEXT        the rewrite's reason (default: specs removed from the public repository)
 *   --dry-run            check and print the list; sign, log and write nothing
 *
 * It lists every upg_upgrades row that records a core commit, logged or not,
 * and translates each through the filter-repo map. It refuses, before signing
 * anything, when:
 *  - a recorded commit is missing from the map, or the rewrite dropped it;
 *  - a new commit is not on the mirror's main;
 *  - a logged release does not trust the statement key that signs the map
 *    (D4): a node checking that release could never accept it.
 * It then signs the list with config/release_statement_key, writes it to the
 * public log, checks that every logged release now resolves through the
 * written file, and writes it. The file is committed on top of the rewritten
 * main and ships with the next release.
 *
 * Exit 0 when written (or, with --dry-run, when it would be), 1 when refused,
 * 2 for a usage error.
 *
 * @version 1.1
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit("This tool runs from the command line only.\n");
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');

$opts = array('reason' => 'specs removed from the public repository');
foreach (array_slice($argv ?? array(), 1) as $arg) {
	if (preg_match('/^--(filter-repo-map|mirror|out|reason)=(.+)$/', $arg, $m)) {
		$opts[str_replace('-', '_', $m[1])] = $m[2];
	} elseif ($arg === '--dry-run') {
		$opts['dry_run'] = true;
	} else {
		fwrite(STDERR, "unknown argument {$arg}\n");
		exit(2);
	}
}
if (empty($opts['filter_repo_map']) || empty($opts['mirror'])) {
	fwrite(STDERR, "usage: php plugins/server_manager/utils/write_commit_map.php --filter-repo-map=FILE --mirror=DIR [--out=FILE] [--dry-run]\n");
	exit(2);
}

$site_root = PathHelper::getSiteRoot();
$out_path = $opts['out'] ?? $site_root . '/' . ReleaseHistoryMap::PATH;
$refuse = function ($why) {
	fwrite(STDERR, "REFUSED: {$why}\n");
	exit(1);
};

// --- filter-repo's map ---------------------------------------------------
$lines = @file($opts['filter_repo_map'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$lines) {
	$refuse("{$opts['filter_repo_map']} is missing or empty");
}
$rewritten = array();
foreach ($lines as $i => $line) {
	if ($i === 0 && preg_match('/^old\s+new$/', trim($line))) { continue; }
	if (!preg_match('/^([0-9a-f]{40})\s+([0-9a-f]{40})$/', trim($line), $m)) {
		$refuse("line " . ($i + 1) . " of {$opts['filter_repo_map']} is not two commit hashes");
	}
	$rewritten[$m[1]] = $m[2];
}

// safe.directory: a root run reads a mirror another user owns.
$mirror = 'git -c ' . escapeshellarg('safe.directory=' . realpath($opts['mirror'])) . ' -C ' . escapeshellarg($opts['mirror']);
exec($mirror . ' rev-parse --verify -q main 2>&1', $o, $code);
if ($code !== 0) {
	$refuse("{$opts['mirror']} has no main branch");
}

// --- the statement key, and who must trust it -----------------------------
$key_path = $site_root . '/config/' . ReleaseLogClient::STATEMENT_KEY_NAME;
if (!is_readable($key_path)) {
	$refuse("{$key_path} is not readable: the map is signed with the key releases are");
}
$pem = (string)file_get_contents($key_path);
$signer = ReleaseLogClient::publicDer($pem);
$signer_id = substr(TransparencyProof::keyId($signer), 0, 12);

// --- every release that records a core commit ------------------------------
$commits = array();
$logged = array();
$problems = array();
foreach (new MultiUpgrade(array(), array('upgrade_id' => 'ASC')) as $u) {
	$old = strtolower((string)$u->get('upg_core_commit'));
	if (!preg_match('/^[0-9a-f]{40}$/', $old)) { continue; }
	$version = UpgradeRetention::versionString($u);
	$new = $rewritten[$old] ?? null;
	if ($new === null) {
		$problems[] = "{$version}: core commit {$old} is not in filter-repo's map";
		continue;
	}
	if ($new === str_repeat('0', 40)) {
		$problems[] = "{$version}: the rewrite dropped core commit {$old} (rerun filter-repo with --prune-empty never)";
		continue;
	}
	exec($mirror . ' merge-base --is-ancestor ' . escapeshellarg($new) . ' main 2>&1', $o, $code);
	if ($code !== 0) {
		$problems[] = "{$version}: the new commit {$new} is not on the mirror's main";
		continue;
	}
	$statement = (string)$u->get('upg_release_statement');
	if ($statement !== '') {
		$payload = ReleaseStatementPublisher::payloadOf((array)json_decode($statement, true));
		if (!in_array($signer, ReleaseStatementPublisher::trustOf($payload)['statement'], true)) {
			$problems[] = "{$version}: the release does not trust statement key {$signer_id}, so it could never accept this map";
			continue;
		}
		$logged[] = array('version' => $version, 'old' => $old, 'new' => $new, 'payload' => $payload);
	}
	$commits[$version . ' ' . $old] = array('version' => $version, 'old' => $old, 'new' => $new);
}
if ($problems) {
	$refuse(count($problems) . " release(s) cannot be mapped:\n  " . implode("\n  ", $problems));
}
if (!$commits) {
	$refuse('no release here records a core commit; there is nothing to map');
}
$commits = array_values($commits);
foreach ($commits as $c) {
	echo "{$c['version']}  {$c['old']} -> {$c['new']}\n";
}
echo count($commits) . ' release(s), ' . count($logged) . " logged, signed by statement key {$signer_id}\n";

if (!empty($opts['dry_run'])) {
	echo "Dry run: nothing signed, logged or written.\n";
	exit(0);
}

// --- sign, log, check, write ----------------------------------------------
$existing = is_file($out_path) ? (string)file_get_contents($out_path) : '';
try {
	$payload = ReleaseHistoryMap::payload($commits, (string)$opts['reason']);
	$held = ReleaseStatementPublisher::held(ReleaseStatementPublisher::previous());
	$client = new ReleaseLogClient(ReleaseLogClient::repoLogKeys($site_root), $held['log']);
	$logged_map = $client->log($payload, $pem, TransparencyProof::COMMIT_MAP_TYPE);
	$bytes = ReleaseHistoryMap::append($existing, $logged_map['envelope'], $logged_map['entry']);
	$map = ReleaseHistoryMap::parse($bytes);
	foreach ($logged as $r) {
		$keys = ReleaseStatementPublisher::trustOf($r['payload']);
		$log_keys = array();
		foreach ($r['payload']['keys_installed']['log_keys'] ?? array() as $pair) {
			$log_keys[(string)$pair['origin']][] = (string)base64_decode((string)$pair['key'], true);
		}
		$found = $map->verifiedFor($r['version'], $r['old'], $keys['statement'], $log_keys);
		if ($found === null || $found['commit'] !== $r['new']) {
			throw new Exception("release {$r['version']} does not resolve to {$r['new']} through the written map");
		}
	}
} catch (Exception $e) {
	$refuse($e->getMessage() . (isset($logged_map) ? ' (the map was logged at index ' . $logged_map['entry']['log_index']
		. ' of ' . $logged_map['entry']['log_origin'] . '; nothing was written)' : ''));
}

if (!is_dir(dirname($out_path)) && !mkdir(dirname($out_path), 0755, true)) {
	$refuse('cannot create ' . dirname($out_path));
}
if (file_put_contents($out_path, $bytes) === false) {
	$refuse("cannot write {$out_path}");
}
// A root run leaves nothing root-owned behind: the file takes its directory's owner.
$dir_uid = fileowner(dirname($out_path));
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && $dir_uid !== false && $dir_uid !== 0) {
	@chown($out_path, $dir_uid);
	@chgrp($out_path, filegroup(dirname($out_path)));
}
echo "Logged at index {$logged_map['entry']['log_index']} of {$logged_map['entry']['log_origin']}; wrote {$out_path} ("
	. $map->count() . " rewrite(s)). Commit it on top of the rewritten main.\n";
exit(0);
