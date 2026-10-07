<?php
/** @joinery-test
 * name: setting_save_forgets_cache
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * A setting saved through the model is read back as written in the same
 * process. Globalvars memoizes each value it reads; a save that left the memo
 * alone made the Provisioning Setup page check a newly entered cloud token's
 * account and scopes against the token it replaced.
 *
 * Run: php tests/run.php test-db --filter=setting_save_forgets_cache
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$name = 'harnesstest_setting_cache_' . bin2hex(random_bytes(3));
$settings = Globalvars::get_instance();

// ---------------------------------------------------------------------------
section('A save is read back as written');

$row = new Setting(NULL);
$row->set('stg_name', $name);
$row->set('stg_value', 'first');
$row->save();
$row->load();
harness_register_row('stg_settings', 'stg_setting_id', $row->key);
check($settings->get_setting($name, false, true) === 'first', 'a new row is read as written');

$row->set('stg_value', 'second');
$row->save();
check($settings->get_setting($name, false, true) === 'second', 'an updated row is read as updated, not as the value read before');

ProvisioningSetup::writeSetting($name, 'third');
check($settings->get_setting($name, false, true) === 'third', 'a write through ProvisioningSetup::writeSetting is read back');

harness_finish();
