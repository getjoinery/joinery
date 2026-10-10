<?php
/** @joinery-test
 * name: storage_profile_present
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * StorageProfileRegistry::present(): the declared profiles whose table exists.
 *
 * A plugin on disk that was never installed declares a storage profile but has
 * no table. Every reader that queries a profile's rows (the backup's object
 * enumeration, restore, the move and offload ticks) walks present(), so such a
 * plugin holds nothing instead of failing the reader. Found live: getjoinery
 * (mailbox never installed) failed every backup on
 * 'relation iem_inbound_email_messages does not exist' (2026-10-09).
 *
 * Run: php tests/unit/storage_profile_present_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('A declared profile with no table is not present');

$src = function ($cls, $table) {
	return "<?php\nclass $cls implements StorageProfile {\n"
		. "  public function table(): string { return '$table'; }\n"
		. "  public function pkeyColumn(): string { return 'id'; }\n"
		. "  public function driverColumn(): string { return 'drv'; }\n"
		. "  public function failedCountColumn(): string { return 'failed'; }\n"
		. "  public function lastAttemptColumn(): string { return 'last_attempt'; }\n"
		. "  public function lastErrorColumn(): string { return 'last_error'; }\n"
		. "  public function targetColumn(): string { return 'target_id'; }\n"
		. "  public function remoteKeyColumn(): string { return 'remote_key'; }\n"
		. "  public function visibility(): string { return 'private'; }\n"
		. "  public function eligibilityWhere(): string { return ''; }\n"
		. "  public function rowExists(int \$id): bool { return false; }\n"
		. "  public function isEligibleRow(int \$id): bool { return false; }\n"
		. "  public function itemsForRow(int \$id): ?array { return null; }\n"
		. "  public function reverseItemsForRow(int \$id): array { return []; }\n"
		. "  public function backupObjects(): array { throw new Exception('queried a table that does not exist'); }\n}\n";
};
$tmp = sys_get_temp_dir() . '/storage_profile_present_' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
harness_defer(function () use ($tmp) { foreach (glob($tmp . '/*') as $f) { @unlink($f); } @rmdir($tmp); });
harness_defer(function () { StorageProfileRegistry::reset(); });

$absent_cls = 'TmpAbsentProfile_' . bin2hex(random_bytes(3));
file_put_contents($tmp . '/' . $absent_cls . '.php', $src($absent_cls, 'tmp_never_installed_' . bin2hex(random_bytes(4))));

StorageProfileRegistry::reset();
StorageProfileRegistry::all();   // the real declarations first; a registration onto an empty cache would stand alone
$register = new ReflectionMethod('StorageProfileRegistry', '_load_and_register');
$register->setAccessible(true);
$register->invoke(null, $absent_cls, $tmp . '/' . $absent_cls . '.php');

$declared = array_map('get_class', StorageProfileRegistry::all());
$present  = array_map('get_class', StorageProfileRegistry::present());
check(in_array($absent_cls, $declared, true), 'The never-installed profile is declared (all() sees every plugin on disk)');
check(!in_array($absent_cls, $present, true), 'It is not present: its table does not exist, so it holds nothing');
check(in_array('BlobStorageProfile', $present, true), 'A profile whose table exists is present');

section('The backup enumeration does not query it');

try {
	BackupObjects::cloud_objects();
	check(true, 'cloud_objects() runs with a never-installed profile declared');
} catch (Throwable $e) {
	check(false, 'cloud_objects() runs with a never-installed profile declared', $e->getMessage());
}

harness_finish();
