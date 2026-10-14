<?php
/** @joinery-test
 * name: agent_release_tree_rule
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The agent checks a release tree by what a published release carries.
 *
 * site_install installs a site from a release's core archive, which the host
 * agent verifies before anything in it runs (release_archive.go): every listed
 * file present and matching, and no file the manifest does not list but the
 * ones a published core archive carries unlisted (releaseUnlistedFiles). This
 * pins that list to the newest core archive this box has published: a file
 * the publisher starts shipping unlisted would make the agent refuse every
 * release, and one the agent allows that no archive carries would be a door
 * a plane could add a file through. Each allowed file must also be one a
 * release manifest never lists (PackageSignature::excluded), and the core
 * statement must be read where the publisher writes it. Read from the agent
 * checkout named by server_manager_agent_source_path; a box with no agent
 * source, or no published archive, skips that part.
 *
 * Run: php plugins/server_manager/tests/agent_release_tree_rule_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));

/** The Go string slice VAR = []string{...} in $go, or NULL. */
function go_string_list(string $go, string $var): ?array {
	if (!preg_match('/\b' . preg_quote($var, '/') . '\s*=\s*\[\]string\{([^}]*)\}/', $go, $m)) {
		return null;
	}
	preg_match_all('/"([^"]*)"/', $m[1], $s);
	return $s[1];
}

/** The files a core archive carries that its own manifest does not list, sorted, or NULL. */
function archive_unlisted(string $archive): ?array {
	$list = array();
	$rc = 0;
	exec('tar -tzvf ' . escapeshellarg($archive) . ' 2>/dev/null', $list, $rc);
	if ($rc !== 0) {
		return null;
	}
	$files = array();
	foreach ($list as $line) {
		// A plain file's line begins with '-'; its name is the rest after the date and time.
		if (!preg_match('/^-\S+\s+\S+\s+\d+\s+\S+\s+\S+\s+(.+)$/', $line, $m)) {
			continue;
		}
		$files[preg_replace('#^\./#', '', $m[1])] = true;
	}
	$manifest = array();
	exec('tar -xzOf ' . escapeshellarg($archive) . ' ./' . PackageSignature::MANIFEST_NAME . ' 2>/dev/null', $manifest, $rc);
	if ($rc !== 0 || !$manifest) {
		return null;
	}
	foreach ($manifest as $line) {
		if (preg_match('/^[0-9a-f]{64}\s+(\S.*)$/', $line, $m)) {
			unset($files[trim($m[1])]);
		}
	}
	unset($files[PackageSignature::MANIFEST_NAME], $files[PackageSignature::SIGNATURE_NAME]);
	$out = array_keys($files);
	sort($out);
	return $out;
}

section('The files a fetched release may carry unlisted are the ones a published release does');

$src = AgentDistPublisher::sourcePath();
$file = $src ? rtrim($src, '/') . '/release_archive.go' : '';
if ($file === '' || !is_file($file)) {
	harness_skip('the agent\'s release_archive.go is read from its source checkout', 'no agent source on this box');
} else {
	$go = (string)file_get_contents($file);
	$allowed = go_string_list($go, 'releaseUnlistedFiles');
	check($allowed !== null, 'release_archive.go declares releaseUnlistedFiles');
	$allowed = (array)$allowed;
	foreach ($allowed as $rel) {
		check(PackageSignature::excluded($rel), "{$rel} is a path a release manifest never lists",
			'the publisher would list it, so no archive carries it unlisted');
	}
	check(strpos($go, 'const releaseCoreStatement = "public_html/' . PackageSignature::STATEMENT_NAME . '"') !== false,
		'the core release\'s statement is read where the publisher writes it');
	check(strpos($go, '"' . PackageSignature::STATEMENT_NAME . '"') !== false,
		'and left out of the subject by the plane\'s name for it');

	$archives = glob(PathHelper::getSiteRoot() . '/static_files/joinery-core-*.tar.gz') ?: array();
	usort($archives, function ($a, $b) {
		$va = preg_replace('/^.*joinery-core-(.*)\.tar\.gz$/', '$1', $a);
		$vb = preg_replace('/^.*joinery-core-(.*)\.tar\.gz$/', '$1', $b);
		return version_compare($vb, $va);
	});
	if (!$archives) {
		harness_skip('the newest published core archive\'s unlisted files', 'this box has published none');
	} else {
		$unlisted = archive_unlisted($archives[0]);
		check($unlisted !== null, basename($archives[0]) . ' can be read, with its manifest');
		$want = $allowed;
		sort($want);
		check($unlisted === $want, 'releaseUnlistedFiles is exactly what ' . basename($archives[0]) . ' carries unlisted',
			'archive: ' . implode(',', (array)$unlisted) . '; agent: ' . implode(',', $want));
	}
}

harness_finish();
