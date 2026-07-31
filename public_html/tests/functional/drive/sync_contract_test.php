<?php
/** @joinery-test
 * name: drive_sync_contract
 * tier: db
 * env: dev-only
 * needs: []
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/folders_class.php'));
require_once(PathHelper::getIncludePath('data/file_changes_class.php'));
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
require_once(PathHelper::getIncludePath('data/sync_devices_class.php'));
require_once(PathHelper::getIncludePath('data/device_links_class.php'));
require_once(PathHelper::getIncludePath('logic/drive_folder_create_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_share_sync_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_trash_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_changes_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_stat_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_index_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_vault_status_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_devices_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_device_rename_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_device_revoke_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_device_link_info_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_device_link_deny_logic.php'));

$made_files = array(); $made_folders = array(); $made_links = array(); $made_devices = array();
harness_defer(function () use (&$made_files, &$made_folders, &$made_links, &$made_devices) {
	$dblink = DbConnector::get_instance()->get_db_link();
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
	foreach (array_reverse($made_folders) as $fid) {
		$dblink->prepare("DELETE FROM fga_file_access_grants WHERE fga_entity_type='folder' AND fga_entity_id=?")->execute(array((int)$fid));
		$dblink->prepare("DELETE FROM fol_folders WHERE fol_folder_id=?")->execute(array((int)$fid));
	}
	foreach ($made_devices as $did) { $dblink->prepare("DELETE FROM sde_sync_devices WHERE sde_sync_device_id=?")->execute(array((int)$did)); }
	foreach ($made_links as $lid) { $dblink->prepare("DELETE FROM dlk_device_links WHERE dlk_device_link_id=?")->execute(array((int)$lid)); }
});

harness_set_setting_mem('drive_active', '1');

$owner   = make_user('synccontractowner');
$grantee = make_user('synccontractgrantee');

$session = SessionControl::get_instance();
$session->set_api_user($owner->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

$tag = bin2hex(random_bytes(3));

// ---------------------------------------------------------------------------
section('file_export carries the sync identity fields');

$rF = drive_folder_create_logic(array('name' => 'SyncF_' . $tag));
$F = (int)$rF->data['folder']['id']; $made_folders[] = $F;

$body = 'sync-' . bin2hex(random_bytes(8));
$X = File::createFromBytes($body, 'x.txt', 'text/plain', $owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$X->set('fil_fol_folder_id', $F);
$X->set('fil_content_modified_time', '2021-03-04 05:06:07');
$X->save();
$made_files[] = $X->key;
$created_change = FileChange::record(FileChange::KIND_CREATED, 'file', $X->key, $owner->key, $owner->key);

DriveHelper::forget_sync_meta($X->key);
$export = DriveHelper::file_export(new File($X->key, true));

check(array_key_exists('content_sha256', $export), 'file_export declares content_sha256');
check($export['content_sha256'] === hash('sha256', $body), 'content_sha256 is the head blob hash',
	var_export($export['content_sha256'], true));
check(strpos((string)$export['modified_time'], '2021-03-04 05:06:07') === 0,
	'modified_time round-trips the client mtime', var_export($export['modified_time'], true));
check((int)$export['head_change_id'] === (int)$created_change->key,
	'head_change_id points at the change that established the content',
	'got ' . var_export($export['head_change_id'], true) . ' want ' . $created_change->key);

$content_change = FileChange::record(FileChange::KIND_CONTENT, 'file', $X->key, $owner->key, $owner->key);
DriveHelper::forget_sync_meta($X->key);
$export2 = DriveHelper::file_export(new File($X->key, true));
check((int)$export2['head_change_id'] === (int)$content_change->key,
	'head_change_id advances with a new content change');

// A file that predates the feed has no proof, and says so rather than guessing.
$Y = File::createFromBytes('nofeed-' . $tag, 'y.txt', 'text/plain', $owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$Y->set('fil_fol_folder_id', $F); $Y->save();
$made_files[] = $Y->key;
DriveHelper::forget_sync_meta($Y->key);
$exportY = DriveHelper::file_export(new File($Y->key, true));
check((int)$exportY['head_change_id'] === 0, 'a file with no feed rows reports head_change_id 0');
check($exportY['modified_time'] === null || $exportY['modified_time'] === '',
	'a file with no client mtime reports none');

// ---------------------------------------------------------------------------
section('drive_stat: batch fetch, missing marking, URL suppression');

$stat = drive_stat_logic(array('entities' => array(
	array('entity_type' => 'file',   'entity_id' => (int)$X->key),
	array('entity_type' => 'folder', 'entity_id' => $F),
	array('entity_type' => 'file',   'entity_id' => 999888777),
)));
check(!$stat->error, 'drive_stat succeeds', (string)$stat->error);
check(count($stat->data['items']) === 2, 'drive_stat returns the visible entities');
check(count($stat->data['missing']) === 1, 'drive_stat reports the unknown entity as missing');
check($stat->data['missing'][0]['entity_id'] === 999888777, 'the missing entry names the entity asked for');

$stat_file = null;
foreach ($stat->data['items'] as $item) { if ($item['entity_type'] === 'file') { $stat_file = $item; } }
check($stat_file !== null && $stat_file['download_url'] === null,
	'drive_stat withholds signed URLs by default');

$stat_urls = drive_stat_logic(array(
	'entities' => array(array('entity_type' => 'file', 'entity_id' => (int)$X->key)),
	'urls' => true,
));
check(!empty($stat_urls->data['items'][0]['download_url']), 'urls:true mints the download URL');

// Duplicate requests collapse rather than multiplying the answer.
$stat_dupe = drive_stat_logic(array('entities' => array(
	array('entity_type' => 'file', 'entity_id' => (int)$X->key),
	array('entity_type' => 'file', 'entity_id' => (int)$X->key),
)));
check(count($stat_dupe->data['items']) === 1, 'a repeated entity is answered once');

// A file the caller cannot see is missing, not an error and not a leak.
$session->set_api_user($grantee->key);
$stat_other = drive_stat_logic(array('entities' => array(
	array('entity_type' => 'file', 'entity_id' => (int)$X->key),
)));
check(count($stat_other->data['items']) === 0 && count($stat_other->data['missing']) === 1,
	'an entity the caller cannot read is reported missing');
$session->set_api_user($owner->key);

$too_many = array();
for ($i = 0; $i < DRIVE_STAT_MAX + 1; $i++) { $too_many[] = array('entity_type' => 'file', 'entity_id' => $i + 1); }
check(drive_stat_logic(array('entities' => $too_many))->error !== null,
	'drive_stat refuses a batch over its cap');

// ---------------------------------------------------------------------------
section('drive_index: keyset walk, trash inclusion, shared scope');

$trashed = File::createFromBytes('trash-' . $tag, 'z.txt', 'text/plain', $owner->key,
	array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
$trashed->set('fil_fol_folder_id', $F); $trashed->save();
$made_files[] = $trashed->key;
drive_trash_logic(array('entity_type' => 'file', 'entity_id' => $trashed->key));

$seen_ids = array('folder' => array(), 'file' => array());
$cursor = null;
$pages = 0;
do {
	$page = drive_index_logic(array('after_id' => $cursor, 'limit' => 2));
	check(!$page->error, 'drive_index page succeeds', (string)$page->error);
	foreach ($page->data['items'] as $item) {
		$seen_ids[$item['entity_type']][] = (int)$item['id'];
	}
	$cursor = $page->data['next_after_id'];
	$pages++;
} while (empty($page->data['done']) && $pages < 50);

check($pages < 50, 'the walk terminates');
check(in_array($F, $seen_ids['folder'], true), 'the walk includes the owner\'s folder');
check(in_array((int)$X->key, $seen_ids['file'], true), 'the walk includes the owner\'s file');
check(in_array((int)$trashed->key, $seen_ids['file'], true), 'the walk includes trashed files');
check(count($seen_ids['file']) === count(array_unique($seen_ids['file'])),
	'paging never returns the same file twice');

$folder_page = drive_index_logic(array('limit' => 2000));
$trashed_row = null;
foreach ($folder_page->data['items'] as $item) {
	if ($item['entity_type'] === 'file' && (int)$item['id'] === (int)$trashed->key) { $trashed_row = $item; }
}
check($trashed_row !== null && $trashed_row['deleted'] === true, 'a trashed file is marked deleted, not omitted');
check($trashed_row !== null && $trashed_row['download_url'] === null, 'drive_index withholds signed URLs');

// Shared scope: what the grantee reaches, annotated with the granting root.
drive_share_sync_logic(array('entity_type' => 'folder', 'entity_id' => $F,
	'grants' => array((string)$grantee->key => 'viewer')));

$session->set_api_user($grantee->key);
$shared = drive_index_logic(array('scope' => 'shared', 'limit' => 2000));
check(!$shared->error, 'drive_index shared scope succeeds', (string)$shared->error);
$shared_folder = null; $shared_file = null;
foreach ($shared->data['items'] as $item) {
	if ($item['entity_type'] === 'folder' && (int)$item['id'] === $F) { $shared_folder = $item; }
	if ($item['entity_type'] === 'file' && (int)$item['id'] === (int)$X->key) { $shared_file = $item; }
}
check($shared_folder !== null, 'the shared scope returns the granted folder');
check($shared_file !== null, 'the shared scope reaches files inside the granted folder');
check($shared_folder !== null && isset($shared_folder['grant_root'])
	&& (int)$shared_folder['grant_root']['id'] === $F,
	'shared items name the granting root');

$mine_for_grantee = drive_index_logic(array('scope' => 'mine', 'limit' => 2000));
$leaked = false;
foreach ($mine_for_grantee->data['items'] as $item) {
	if ($item['entity_type'] === 'file' && (int)$item['id'] === (int)$X->key) { $leaked = true; }
}
check(!$leaked, 'the mine scope does not include another user\'s files');
$session->set_api_user($owner->key);

check(drive_index_logic(array('after_id' => 'garbage'))->error !== null,
	'an unparseable cursor is refused rather than silently restarting the walk');

// ---------------------------------------------------------------------------
section('drive_vault_status is lean');

$vault = drive_vault_status_logic(array('scope' => 'drive'));
check(!$vault->error, 'drive_vault_status succeeds', (string)$vault->error);
check(array_key_exists('set_up', $vault->data)
	&& array_key_exists('public_key', $vault->data)
	&& array_key_exists('key_generation', $vault->data),
	'drive_vault_status answers the three facts');
foreach (array('wrappings', 'salt', 'kdf_params', 'prf_context') as $forbidden) {
	check(!array_key_exists($forbidden, $vault->data),
		"drive_vault_status withholds $forbidden");
}
check(drive_vault_status_logic(array('scope' => 'passwords'))->error !== null,
	'drive_vault_status refuses a non-drive scope');

// ---------------------------------------------------------------------------
section('device link ceremony state machine');

$code = DeviceLink::generate_code();
check(strlen($code) === 8, 'a link code is eight characters');
check(DeviceLink::normalize_code('abcd-efgh') === 'ABCDEFGH', 'codes normalize case and separators');
check(DeviceLink::normalize_code('O1IL') === '0111', 'lookalike characters fold to what the user meant');
check(DeviceLink::hash_code('abcd-efgh') === DeviceLink::hash_code('ABCDEFGH'),
	'a code hashes the same however it was typed');

$poll_token = bin2hex(random_bytes(32));
$link = new DeviceLink(NULL);
$link->set('dlk_code_hash', DeviceLink::hash_code($code));
$link->set('dlk_poll_token_hash', DeviceLink::hash_token($poll_token));
$link->set('dlk_device_name', 'Test Workstation');
$link->set('dlk_platform', SyncDevice::PLATFORM_WINDOWS);
$link->set('dlk_device_pubkey', base64_encode(random_bytes(32)));
$link->set('dlk_request_ip', '198.51.100.7');
$link->set('dlk_status', DeviceLink::STATUS_PENDING);
$link->set('dlk_expires_time', gmdate('Y-m-d H:i:s', time() + DeviceLink::TTL_SECONDS));
$link->save();
$made_links[] = $link->key;

$found = DeviceLink::load_open_by_code(strtolower($code));
check($found && (int)$found->key === (int)$link->key, 'an open ceremony loads by its code');
check(DeviceLink::load_by_poll_token($poll_token) !== null, 'a ceremony loads by its poll token');
check(DeviceLink::load_open_by_code('ZZZZZZZZ') === null, 'an unknown code finds nothing');

$info = drive_device_link_info_logic(array('code' => $code));
check(!$info->error, 'the approval page can read the pending ceremony', (string)$info->error);
check($info->data['device_name'] === 'Test Workstation', 'the ceremony reports the device name');
check($info->data['request_ip'] === '198.51.100.7', 'the ceremony reports where the request came from');
check($info->data['supports_vault'] === true, 'a device with a public key can receive the vault key');

// The one-time secret survives exactly one collection.
$link->seal_secret('super-secret-plaintext');
$link->save();
$reloaded = new DeviceLink((int)$link->key, true);
check($reloaded->get('dlk_secret_once') !== 'super-secret-plaintext',
	'the minted secret is not stored in the clear');
check($reloaded->open_secret() === 'super-secret-plaintext', 'the minted secret round-trips');
$reloaded->scrub_secrets();
$after_scrub = new DeviceLink((int)$link->key, true);
check($after_scrub->open_secret() === null, 'scrubbing removes the secret');
check($after_scrub->get('dlk_sealed_vault_key') === null, 'scrubbing removes the sealed vault key');

// Expiry closes the door without any sweep having to run.
$link->set('dlk_expires_time', gmdate('Y-m-d H:i:s', time() - 60));
$link->save();
check(DeviceLink::load_open_by_code($code) === null, 'an expired ceremony no longer opens');
check((new DeviceLink((int)$link->key, true))->is_expired(), 'an expired ceremony knows it');

// Denial ends it early.
$link->set('dlk_expires_time', gmdate('Y-m-d H:i:s', time() + DeviceLink::TTL_SECONDS));
$link->set('dlk_status', DeviceLink::STATUS_PENDING);
$link->save();
$deny = drive_device_link_deny_logic(array('code' => $code));
check(!$deny->error && !empty($deny->data['denied']), 'a ceremony can be refused', (string)$deny->error);
check(DeviceLink::load_open_by_code($code) === null, 'a refused ceremony is closed');

// ---------------------------------------------------------------------------
section('sync devices: listing, rename, revoke, check-in');

$minted = ApiKey::CreateSessionKey($owner->key, 'Test Workstation');
harness_register_key_id($minted['api_key']->key);

$device = new SyncDevice(NULL);
$device->set('sde_usr_user_id', $owner->key);
$device->set('sde_apk_api_key_id', (int)$minted['api_key']->key);
$device->set('sde_device_name', 'Test Workstation');
$device->set('sde_platform', SyncDevice::PLATFORM_WINDOWS);
$device->save();
$made_devices[] = $device->key;

$listed = drive_devices_logic(array());
$found_device = null;
foreach ($listed->data['devices'] as $d) { if ((int)$d['id'] === (int)$device->key) { $found_device = $d; } }
check($found_device !== null, 'a linked device appears in the caller\'s device list');
check($found_device !== null && $found_device['has_vault_key'] === false,
	'a device with no public key is shown as not holding the vault key');

check(SyncDevice::for_api_key($minted['api_key']->key) !== null,
	'a device resolves from the credential it authenticates with');

// The change feed doubles as the check-in.
$dblink = DbConnector::get_instance()->get_db_link();
$live_cursor = (int)$dblink->query("SELECT COALESCE(MAX(fch_file_change_id), 0) FROM fch_file_changes")->fetchColumn();
$before = $device->get('sde_last_seen_time');
$session->set_api_user($owner->key, (int)$minted['api_key']->key);
drive_changes_logic(array('cursor' => $live_cursor));
$stamped = new SyncDevice((int)$device->key, true);
check($before === null && $stamped->get('sde_last_seen_time') !== null,
	'polling the change feed records the device check-in');
check((int)$stamped->get('sde_last_cursor') === $live_cursor, 'the acknowledged cursor is recorded',
	'got ' . var_export($stamped->get('sde_last_cursor'), true) . ' want ' . $live_cursor);

// A device too far behind to replay incrementally is still a device that
// checked in — the reset answer must not read as "this machine went silent".
$dblink->prepare("UPDATE sde_sync_devices SET sde_last_seen_time = NULL WHERE sde_sync_device_id = ?")
	->execute(array((int)$device->key));
$reset = drive_changes_logic(array('cursor' => 1));
check(!empty($reset->data['reset']), 'a cursor before the retained window still resets');
check((new SyncDevice((int)$device->key, true))->get('sde_last_seen_time') !== null,
	'a device that has to re-list still records its check-in');

// The recorded position only ever moves forward, so a late or replayed request
// cannot rewind what the owner sees.
check((int)(new SyncDevice((int)$device->key, true))->get('sde_last_cursor') === $live_cursor,
	'a backwards cursor does not rewind the recorded position');
$session->set_api_user($owner->key);

$renamed = drive_device_rename_logic(array('device_id' => (int)$device->key, 'name' => 'Studio PC'));
check(!$renamed->error && $renamed->data['device']['device_name'] === 'Studio PC',
	'a device can be renamed', (string)$renamed->error);

// Another user cannot touch it.
$session->set_api_user($grantee->key);
check(drive_device_rename_logic(array('device_id' => (int)$device->key, 'name' => 'Stolen'))->error !== null,
	'another user cannot rename a device that is not theirs');
check(drive_device_revoke_logic(array('device_id' => (int)$device->key))->error !== null,
	'another user cannot revoke a device that is not theirs');
$session->set_api_user($owner->key);

$revoked = drive_device_revoke_logic(array('device_id' => (int)$device->key));
check(!$revoked->error && !empty($revoked->data['revoked']), 'a device can be unlinked', (string)$revoked->error);
$dead_key = new ApiKey((int)$minted['api_key']->key, true);
check($dead_key->get('apk_delete_time') !== null,
	'unlinking a device also revokes the credential it was using');
check((new SyncDevice((int)$device->key, true))->get('sde_delete_time') !== null,
	'the device row is retired');
$after_revoke = drive_devices_logic(array());
$still_there = false;
foreach ($after_revoke->data['devices'] as $d) { if ((int)$d['id'] === (int)$device->key) { $still_there = true; } }
check(!$still_there, 'a revoked device leaves the list');

// ---------------------------------------------------------------------------
section('Range header parsing');

check(File::parse_range_header(null, 1000) === null, 'no Range header means no range');
check(File::parse_range_header('bytes=0-99', 1000) === array('start' => 0, 'end' => 99), 'a bounded range parses');
check(File::parse_range_header('bytes=500-', 1000) === array('start' => 500, 'end' => 999), 'an open-ended range runs to the end');
check(File::parse_range_header('bytes=-100', 1000) === array('start' => 900, 'end' => 999), 'a suffix range counts back from the end');
check(File::parse_range_header('bytes=0-5000', 1000) === array('start' => 0, 'end' => 999), 'a range past the end is clamped, not refused');
check(File::parse_range_header('bytes=1000-', 1000) === false, 'a range starting past the end is unsatisfiable');
check(File::parse_range_header('bytes=0-99', 0) === false, 'any range on an empty object is unsatisfiable');
check(File::parse_range_header('bytes=99-0', 1000) === false, 'a backwards range is unsatisfiable');
check(File::parse_range_header('bytes=0-10,20-30', 1000) === null, 'a multi-range request is served whole');
check(File::parse_range_header('items=0-10', 1000) === null, 'an unknown range unit is ignored');
check(File::parse_range_header('nonsense', 1000) === null, 'a malformed Range header is ignored');

harness_finish();
