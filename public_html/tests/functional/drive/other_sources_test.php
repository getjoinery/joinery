<?php
/** @joinery-test
 * name: drive_other_sources
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Drive's "Also in your account" views: a member's own files that another
 * feature stored (a mail attachment, a chat upload) are reachable from the
 * Drive rail, read-only, without ever leaking into My Drive.
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('logic/drive_list_logic.php'));

$made_files = array();

harness_set_setting_mem('drive_active', '1');

function osrc_file($content, $name, $user_id, $source) {
	global $made_files;
	$restrictions = array('fil_private' => true);
	if ($source !== null) { $restrictions['fil_source'] = $source; }
	$f = File::createFromBytes($content, $name, 'application/octet-stream', $user_id, $restrictions);
	$made_files[] = $f->key;
	return $f;
}
function osrc_ids($result) {
	$ids = array();
	foreach (($result->data['items'] ?? array()) as $it) { $ids[] = (int)$it['id']; }
	return $ids;
}
function osrc_source_entry($result, $key) {
	foreach (($result->data['other_sources'] ?? array()) as $s) { if ($s['key'] === $key) return $s; }
	return null;
}

$owner = make_user('osrcowner');
$other = make_user('osrcother');
// Deferred steps run last-registered first: the files go before their owners,
// so the user teardown never cascades into rows this step still expects.
harness_defer(function () use (&$made_files) {
	foreach ($made_files as $fid) { $f = new File((int)$fid, true); if ($f->key) { $f->permanent_delete(); } }
});

$session = SessionControl::get_instance();
$session->set_api_user($owner->key);
harness_defer(function () use ($session) { $session->clear_api_user(); });

$salt = bin2hex(random_bytes(6));
$drive_file = osrc_file("drive-$salt", "mine-$salt.bin", $owner->key, File::SOURCE_DRIVE);
$att_a      = osrc_file("att-a-$salt", "invoice-$salt.pdf", $owner->key, File::SOURCE_EMAIL_ATTACHMENT);
$att_b      = osrc_file("att-b-$salt", "photo-$salt.jpg", $owner->key, File::SOURCE_EMAIL_ATTACHMENT);
$chat       = osrc_file("chat-$salt", "notes-$salt.txt", $owner->key, File::SOURCE_AI_CHAT_UPLOAD);
$untagged   = osrc_file("legacy-$salt", "legacy-$salt.bin", $owner->key, null);
$index      = osrc_file("idx-$salt", "index-$salt.db", $owner->key, File::SOURCE_MAILBOX_SEARCH_INDEX);
$foreign    = osrc_file("foreign-$salt", "theirs-$salt.pdf", $other->key, File::SOURCE_EMAIL_ATTACHMENT);

// ---------------------------------------------------------------------------
section('the rail lists every other listable source the member owns files under, with counts');

$mine = drive_list_logic(array('view' => 'mine'));
check($mine->error === null, 'My Drive lists');
$mine_ids = osrc_ids($mine);
check(in_array((int)$drive_file->key, $mine_ids, true), 'the Drive file is in My Drive');
check(!in_array((int)$att_a->key, $mine_ids, true) && !in_array((int)$chat->key, $mine_ids, true) && !in_array((int)$untagged->key, $mine_ids, true),
	'attachments, chat uploads and untagged files stay out of My Drive');

$att_entry = osrc_source_entry($mine, File::SOURCE_EMAIL_ATTACHMENT);
check($att_entry !== null && $att_entry['count'] >= 2 && $att_entry['label'] === 'Mail attachments',
	'Mail attachments listed with a count of at least the two made here', json_encode($att_entry));
$chat_entry = osrc_source_entry($mine, File::SOURCE_AI_CHAT_UPLOAD);
check($chat_entry !== null && $chat_entry['count'] >= 1, 'AI chat uploads listed');
check(osrc_source_entry($mine, File::SOURCE_UNCLASSIFIED) !== null, 'untagged files are offered as Unclassified, not hidden');
check(osrc_source_entry($mine, File::SOURCE_DRIVE) === null, 'Drive itself is not an "other" source');
check(osrc_source_entry($mine, File::SOURCE_MAILBOX_SEARCH_INDEX) === null, 'an internal source (the search index) is never offered');

// ---------------------------------------------------------------------------
section('a source view lists only the caller\'s own files of that source, newest first');

$att = drive_list_logic(array('view' => 'source', 'source' => File::SOURCE_EMAIL_ATTACHMENT));
check($att->error === null && $att->data['view'] === 'source' && $att->data['source'] === File::SOURCE_EMAIL_ATTACHMENT, 'source view answers');
$att_ids = osrc_ids($att);
check(in_array((int)$att_a->key, $att_ids, true) && in_array((int)$att_b->key, $att_ids, true), 'both attachments listed');
check(!in_array((int)$drive_file->key, $att_ids, true) && !in_array((int)$chat->key, $att_ids, true), 'no file of another source');
check(!in_array((int)$foreign->key, $att_ids, true), 'another member\'s attachment is not listed');
$pos_a = array_search((int)$att_a->key, $att_ids, true);
$pos_b = array_search((int)$att_b->key, $att_ids, true);
check($pos_b < $pos_a, 'newest first (the later-made attachment comes before the earlier)');
foreach ($att->data['items'] as $it) {
	if ((int)$it['id'] === (int)$att_a->key) {
		check(!empty($it['requires_window']) && empty($it['is_image']) && empty($it['syncable']) && $it['source'] === File::SOURCE_EMAIL_ATTACHMENT,
			'an attachment export says: opened in-window, no thumbnail, not syncable', json_encode($it));
	}
}

$un = drive_list_logic(array('view' => 'source', 'source' => File::SOURCE_UNCLASSIFIED));
check($un->error === null && in_array((int)$untagged->key, osrc_ids($un), true), 'the Unclassified view reaches an untagged file');
check(!in_array((int)$att_a->key, osrc_ids($un), true), '…and only untagged files');

// ---------------------------------------------------------------------------
section('search stays inside the source; refused sources are refused');

$srch = drive_list_logic(array('view' => 'source', 'source' => File::SOURCE_EMAIL_ATTACHMENT, 'search' => "invoice-$salt"));
$srch_ids = osrc_ids($srch);
check($srch->error === null && $srch_ids === array((int)$att_a->key), 'a search in the attachments view finds only the matching attachment', json_encode($srch_ids));

$plain = drive_list_logic(array('search' => "invoice-$salt"));
check($plain->error === null && !in_array((int)$att_a->key, osrc_ids($plain), true), 'the Drive-wide search does not surface attachments');

$bad = drive_list_logic(array('view' => 'source', 'source' => File::SOURCE_MAILBOX_SEARCH_INDEX));
check($bad->error !== null, 'an internal source is refused as a view');
$bad2 = drive_list_logic(array('view' => 'source', 'source' => File::SOURCE_DRIVE));
check($bad2->error !== null, 'Drive\'s own tag is refused as an "other" source view');
$bad3 = drive_list_logic(array('view' => 'source', 'source' => 'no_such_source'));
check($bad3->error !== null, 'an unknown tag is refused');

// ---------------------------------------------------------------------------
section('the meter counts everything the member owns, live, minus internal sources');

require_once(PathHelper::getIncludePath('data/drive_usage_class.php'));
$expected = 0;
foreach (array($drive_file, $att_a, $att_b, $chat, $untagged) as $f) {
	$expected += $f->size_bytes();
}
$live = DriveUsage::current_bytes($owner->key);
check($live === $expected, 'current_bytes sums Drive + attachments + chat upload + untagged file', "live=$live expected=$expected");
check($index->size_bytes() > 0 && $live === $expected, 'the internal search-index file weighs something and is not billed');
$mine2 = drive_list_logic(array('view' => 'mine'));
check((int)$mine2->data['usage']['bytes_used'] === $expected, 'the listing\'s meter carries the same live sum');
check(DriveUsage::current_bytes($other->key) === $foreign->size_bytes(), 'another member is billed only their own file');

// Nothing wrote the usage row on a GET; recompute persists the same number.
check(DriveUsage::recompute($owner->key) === $expected, 'recompute persists the live sum unchanged');

// ---------------------------------------------------------------------------
section('the site-wide meter is an admin\'s to see');

check(!isset($mine2->data['site_storage']) || $mine2->data['site_storage'] === null, 'a member gets no site_storage');
$session->clear_api_user();
$admin = make_user('osrcadmin', 10);
$session->set_api_user($admin->key);
$adm = drive_list_logic(array('view' => 'mine'));
$site = $adm->data['site_storage'] ?? null;
check(is_array($site) && $site['bytes_used'] >= $expected && $site['files'] >= 7, 'an admin gets site totals covering at least this test\'s files', json_encode($site));
check(is_array($site) && array_key_exists('bytes_available', $site), 'site totals carry the free-space figure (null when unknowable)');

harness_finish();
