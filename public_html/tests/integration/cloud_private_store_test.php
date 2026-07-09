<?php
/** @joinery-test
 * name: cloud_private_store
 * tier: safe
 * env: dev-only
 * needs: []
 */
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

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageLifecycle.php'));

section('Privacy hard-gate verdict (the privacy-critical decision)');
ok('anonymous 200 ⇒ gate FAILS (bucket public)', CloudStorageLifecycle::privacyVerdict(200)['pass'] === false);
ok('anonymous 204 ⇒ gate FAILS', CloudStorageLifecycle::privacyVerdict(204)['pass'] === false);
ok('anonymous 403 ⇒ gate PASSES (denied)', CloudStorageLifecycle::privacyVerdict(403)['pass'] === true);
ok('anonymous 401 ⇒ gate PASSES', CloudStorageLifecycle::privacyVerdict(401)['pass'] === true);
ok('anonymous 404 ⇒ gate PASSES', CloudStorageLifecycle::privacyVerdict(404)['pass'] === true);
ok('connection refused (0) ⇒ gate PASSES', CloudStorageLifecycle::privacyVerdict(0)['pass'] === true);

section("forVisibility('private') null until configured AND gated");

// Latch off ⇒ null regardless of bucket.
harness_set_setting_mem('cloud_storage_private_enabled', '');
harness_set_setting_mem('cloud_storage_private_bucket', 'some-private-bucket');
CloudStorageDriverFactory::reset();
ok('latch off ⇒ private driver null', CloudStorageDriverFactory::forVisibility('private') === null);

// Latch on but no bucket ⇒ null.
harness_set_setting_mem('cloud_storage_private_enabled', '1');
harness_set_setting_mem('cloud_storage_private_bucket', '');
CloudStorageDriverFactory::reset();
ok('latch on + empty bucket ⇒ private driver null', CloudStorageDriverFactory::forVisibility('private') === null);

section('Public store resolution is independent of private state');

// Whatever the public store currently resolves to, toggling private settings
// must not change it.
harness_set_setting_mem('cloud_storage_private_enabled', '');
CloudStorageDriverFactory::reset();
$public_a_null = (CloudStorageDriverFactory::forVisibility('public') === null);

harness_set_setting_mem('cloud_storage_private_enabled', '1');
harness_set_setting_mem('cloud_storage_private_bucket', 'another-bucket');
CloudStorageDriverFactory::reset();
$public_b_null = (CloudStorageDriverFactory::forVisibility('public') === null);

ok('public store resolution unchanged by private toggle', $public_a_null === $public_b_null);

harness_finish();
