<?php
/**
 * Private store test — the verified-private store's safety properties.
 *
 *  - forVisibility('private') is null until the bucket is configured AND the
 *    privacy gate has latched cloud_storage_private_enabled true.
 *  - The privacy hard-gate verdict: an anonymous 2xx ⇒ FAIL (bucket public);
 *    any denied/unreachable status ⇒ PASS.
 *  - The private store's state never changes the public store's resolution
 *    (the two Saves are independent).
 *
 * Settings are toggled only in the Globalvars in-memory cache (this process).
 *
 * Run: php tests/integration/cloud_private_store_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));

$pass = 0; $fail = 0;
function ok($label, $cond) {
	global $pass, $fail;
	if ($cond) { echo "PASS: $label\n"; $pass++; }
	else       { echo "FAIL: $label\n"; $fail++; }
}

function set_mem($key, $value) {
	$gv = Globalvars::get_instance();
	$ref = new ReflectionProperty('Globalvars', 'settings');
	$ref->setAccessible(true);
	$arr = $ref->getValue($gv);
	if (!is_array($arr)) $arr = [];
	$arr[$key] = $value;
	$ref->setValue($gv, $arr);
}

echo "=== Privacy hard-gate verdict (the privacy-critical decision) ===\n";
ok('anonymous 200 ⇒ gate FAILS (bucket public)', CloudStorageLifecycle::privacyVerdict(200)['pass'] === false);
ok('anonymous 204 ⇒ gate FAILS', CloudStorageLifecycle::privacyVerdict(204)['pass'] === false);
ok('anonymous 403 ⇒ gate PASSES (denied)', CloudStorageLifecycle::privacyVerdict(403)['pass'] === true);
ok('anonymous 401 ⇒ gate PASSES', CloudStorageLifecycle::privacyVerdict(401)['pass'] === true);
ok('anonymous 404 ⇒ gate PASSES', CloudStorageLifecycle::privacyVerdict(404)['pass'] === true);
ok('connection refused (0) ⇒ gate PASSES', CloudStorageLifecycle::privacyVerdict(0)['pass'] === true);

echo "\n=== forVisibility('private') null until configured AND gated ===\n";

// Latch off ⇒ null regardless of bucket.
set_mem('cloud_storage_private_enabled', '');
set_mem('cloud_storage_private_bucket', 'some-private-bucket');
CloudStorageDriverFactory::reset();
ok('latch off ⇒ private driver null', CloudStorageDriverFactory::forVisibility('private') === null);

// Latch on but no bucket ⇒ null.
set_mem('cloud_storage_private_enabled', '1');
set_mem('cloud_storage_private_bucket', '');
CloudStorageDriverFactory::reset();
ok('latch on + empty bucket ⇒ private driver null', CloudStorageDriverFactory::forVisibility('private') === null);

echo "\n=== Public store resolution is independent of private state ===\n";

// Whatever the public store currently resolves to, toggling private settings
// must not change it.
set_mem('cloud_storage_private_enabled', '');
CloudStorageDriverFactory::reset();
$public_a_null = (CloudStorageDriverFactory::forVisibility('public') === null);

set_mem('cloud_storage_private_enabled', '1');
set_mem('cloud_storage_private_bucket', 'another-bucket');
CloudStorageDriverFactory::reset();
$public_b_null = (CloudStorageDriverFactory::forVisibility('public') === null);

ok('public store resolution unchanged by private toggle', $public_a_null === $public_b_null);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
