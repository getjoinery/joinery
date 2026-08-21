<?php
/** @joinery-test
 * name: sealed_read_paths
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Every read of sealed content goes through a watched door — pinned.
 *
 * The hot-turn rule (docs/sealed_vault.md § The hot-turn rule) arms in exactly
 * one place: VaultCrypto::openField(). That only protects anything if code
 * cannot quietly decrypt sealed content some other way, so this test walks the
 * whole tree and asserts that the low-level SealedBox decrypt primitives
 * (openDek / openBinary / aeadDecrypt / openStreamFile) are called from a
 * closed, named set of files:
 *
 *  - includes/SealedBox.php      — defines the primitives, uses them internally;
 *  - includes/VaultCrypto.php    — the sanctioned wrapper: openField() and
 *                                  openFieldFile() (both arm; the file form is
 *                                  the streaming open of stored sealed content),
 *                                  openItemDek() (unwraps KEYS, not content; the
 *                                  content open that follows arms),
 *                                  openHeldDeliveryBlob() and openBulkDelivery()
 *                                  (the non-arming content opens — a Direct
 *                                  message held in transit, base64 DEK form and
 *                                  raw bulk form respectively, opened to complete
 *                                  first-time delivery);
 *  - plugins/mailbox/includes/RelaySpoolConsumer.php
 *                                — opens relay spool envelopes with the SERVER's
 *                                  own transport key; no owner key is involved,
 *                                  so it sits outside the rule's premise.
 *
 * A new direct caller anywhere else fails this test. That is the point: the
 * exemption list is a test, not folklore — a second candidate has to argue its
 * case against the held-in-transit criterion in review, loudly, instead of
 * copying the pattern.
 *
 * Test files are excluded: suites drive the primitives directly to test them.
 *
 * Run: php tests/run.php safe --filter=sealed_read_paths
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** Call-shaped uses only: `->openDek(` / `::aeadDecrypt(` etc. A mention in
 *  prose or a docblock without the call parenthesis does not count. */
const SRP_PATTERN = '/(?:->|::)\s*(?:openDek|openBinary|aeadDecrypt|openStreamFile)\s*\(/';

/** The closed set, relative to public_html. */
$allowed = array(
	'includes/SealedBox.php',
	'includes/VaultCrypto.php',
	'plugins/mailbox/includes/RelaySpoolConsumer.php',
);

$root = realpath(__DIR__ . '/../..');

section('the low-level decrypt primitives have a closed caller set');

$callers = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		function ($current) {
			$name = $current->getFilename();
			// Never descend into version control or dependency trees; test
			// estates drive the primitives on purpose and are out of scope.
			if ($current->isDir()) {
				return $name !== '.git' && $name !== 'node_modules' && $name !== 'vendor'
					&& $name !== 'tests';
			}
			return substr($name, -4) === '.php';
		}
	)
);
foreach ($iterator as $file) {
	$source = @file_get_contents($file->getPathname());
	if ($source === false || !preg_match(SRP_PATTERN, $source)) {
		continue;
	}
	$callers[] = str_replace($root . '/', '', $file->getPathname());
}
sort($callers);

$unexpected = array_diff($callers, $allowed);
check(count($unexpected) === 0,
	'no file outside the sanctioned set calls SealedBox::openDek/openBinary/aeadDecrypt/openStreamFile directly — '
	. 'sealed reads go through VaultCrypto::openField()/openFieldFile(), which arm the hot-turn rule',
	count($unexpected) ? ('new callers: ' . implode(', ', $unexpected)
		. ' — if one is genuinely held-in-transit delivery, argue it against the criterion in '
		. 'VaultCrypto::openHeldDeliveryBlob() and add it here in the same review') : '');

section('and the pinned set is still real, so this test cannot rot');

foreach ($allowed as $expected) {
	check(in_array($expected, $callers, true),
		$expected . ' still uses the primitives it is allowlisted for',
		in_array($expected, $callers, true) ? '' : 'it no longer matches — prune the allowlist');
}

harness_finish();
