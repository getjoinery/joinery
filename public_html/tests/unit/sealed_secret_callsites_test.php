<?php
/** @joinery-test
 * name: sealed_secret_callsites
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The teeth, enforced by grep: no code outside SecretBox itself may call the raw
 * ->encrypt() / ->decrypt() crypto. Everything else seals through
 * SecretBox::seal($locator, ...) (which refuses an unregistered locator) and reads
 * through SecretBox::open() (the four-state contract). A raw call would seal a
 * value the registry cannot find or heal when a database moves — the exact hole
 * this whole subsystem closes — so it fails CI here, the same way
 * core_api_mechanical_test enumerates server_initiated_write() callers.
 *
 * SecretBox.php itself is the one file allowed the raw calls: seal()/open() and
 * the canary are built on them.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('Sealed-secret callsites');

$base = PathHelper::getBasePath();
$offenders = array();

$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
	$base, FilesystemIterator::SKIP_DOTS));
foreach ($scan as $file) {
	if ($file->getExtension() !== 'php') continue;
	$rel = ltrim(str_replace($base, '', $file->getPathname()), '/');

	// Skip the definition site, tests, specs, docs, and vendored code.
	if ($rel === 'includes/SecretBox.php') continue;
	if (strpos($rel, 'tests/') === 0 || strpos($rel, '/tests/') !== false) continue;
	if (strpos($rel, 'specs/') === 0 || strpos($rel, 'docs/') === 0) continue;
	if (strpos($rel, 'vendor/') !== false) continue;

	$src = file_get_contents($file->getPathname());
	// Only interested in files that actually use SecretBox; ->encrypt( on some
	// other class (e.g. a mail or vault helper) is none of our business.
	if (strpos($src, 'SecretBox') === false) continue;
	if (preg_match('/->\s*(encrypt|decrypt)\s*\(/', $src)) {
		$offenders[] = $rel;
	}
}
sort($offenders);

check(count($offenders) === 0,
	'no SecretBox consumer calls raw ->encrypt()/->decrypt() — all seal()/open()',
	$offenders ? implode(', ', $offenders) : 'all consumers migrated');

harness_finish();
