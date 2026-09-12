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
 * A second, narrower pin covers the vault SECRET (specs/unseal_daemon.md § The
 * PHP seam): the primitives that take or produce it — SealedBox::openDek,
 * openBinary, unwrapKey, wrapKey and generateKeypair — are called from
 * PoolVaultKey (the one class that holds the bytes), SealedBox itself, and
 * the two places that use SealedBox with a key that is NOT a vault key (the
 * relay transport keypair). VaultCrypto opens through VaultKey::unseal() and
 * touches none of them. So the bytes are unreachable outside PoolVaultKey by
 * test, not by convention, and a standalone wrap anywhere (spec B1) fails
 * the suite.
 *
 * Test files are excluded: suites drive the primitives directly to test them.
 *
 * Run: php tests/run.php safe --filter=sealed_read_paths
 *
 * @version 1.2 - the vault-secret pin (openDek/openBinary/unwrapKey/wrapKey/generateKeypair)
 * @version 1.1
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** Call-shaped uses only: `->openDek(` / `::aeadDecrypt(` etc. A mention in
 *  prose or a docblock without the call parenthesis does not count. */
const SRP_PATTERN = '/(?:->|::)\s*(?:openDek|openBinary|aeadDecrypt|openStreamFile)\s*\(/';

/** The closed set, relative to public_html. */
$allowed = array(
	'includes/PoolVaultKey.php',
	'includes/SealedBox.php',
	'includes/VaultCrypto.php',
	'plugins/mailbox/includes/RelaySpoolConsumer.php',
);

/** Instance calls only (`->openDek(`): every SealedBox primitive is an instance
 *  method, and the instance shape keeps MailboxDkimSigner::generateKeypair()
 *  (RSA, a static of another class) out of the match. */
const SRP_SECRET_PATTERN = '/->\s*(?:openDek|openBinary|unwrapKey|wrapKey|generateKeypair)\s*\(/';

/** Where the vault secret may be unwrapped, wrapped, minted or used to open. */
$secret_allowed = array(
	'includes/PoolVaultKey.php',                          // holds the bytes; the pool `open` and `unwrap`
	'includes/SealedBox.php',                             // openDek() is unframeSeal() + openBinary()
	'plugins/mailbox/includes/RelaySpoolConsumer.php',    // the SERVER's relay transport key, not a vault key
	'plugins/mailbox/data/mailbox_relay_class.php',       // mints that transport keypair
);

$root = realpath(__DIR__ . '/../..');

section('the low-level decrypt primitives have a closed caller set');

/** Every production .php file under public_html. */
function srp_php_files(string $root): RecursiveIteratorIterator {
	return new RecursiveIteratorIterator(
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
}

$callers = array();
foreach (srp_php_files($root) as $file) {
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

section('the vault secret is unreachable outside PoolVaultKey');

$secret_callers = array();
foreach (srp_php_files($root) as $file) {
	$source = @file_get_contents($file->getPathname());
	if ($source === false || !preg_match(SRP_SECRET_PATTERN, $source)) {
		continue;
	}
	$secret_callers[] = str_replace($root . '/', '', $file->getPathname());
}
sort($secret_callers);

$unexpected = array_diff($secret_callers, $secret_allowed);
check(count($unexpected) === 0,
	'no file outside PoolVaultKey (and the two non-vault transport-key users) calls SealedBox::openDek/openBinary/'
	. 'unwrapKey/wrapKey/generateKeypair — a vault key is used through VaultKey, and a wrapping is produced only by '
	. 'VaultUnlock::open()/openKey() (specs/unseal_daemon.md B1)',
	count($unexpected) ? ('new callers: ' . implode(', ', $unexpected)) : '');

foreach ($secret_allowed as $expected) {
	check(in_array($expected, $secret_callers, true),
		$expected . ' still uses the secret-taking primitives it is allowlisted for',
		in_array($expected, $secret_callers, true) ? '' : 'it no longer matches — prune the allowlist');
}

check(!file_exists($root . '/includes/VaultKey.php')
		|| !preg_match('/function\s+(?:secret|bytes|export|wrap)\w*\s*\(/i', (string)file_get_contents($root . '/includes/VaultKey.php')),
	'the VaultKey interface has no getter for the bytes and no wrap method');

harness_finish();
