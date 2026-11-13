<?php
/** @joinery-test
 * name: seo_inventory_restore
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * An entity that drops off the public list (a page unpublished, a feature
 * switched off) has its SEO row soft-deleted by the sweep. When it comes back,
 * the sweep brings that row back, with the SEO copy the operator wrote, rather
 * than making a new empty one.
 *
 * Everything runs inside one transaction that is rolled back.
 *
 * Run: php tests/run.php db --filter=seo_inventory_restore
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$restore = new ReflectionMethod('SeoPageMetadata', 'restore_entity_row');
$tag = 'seo-restore-' . getmypid();
$fake_id = -1 * getmypid();

$row = function ($id) use ($db) {
	$q = $db->prepare('SELECT spm_path, spm_title, spm_delete_time FROM spm_seo_page_metadata WHERE spm_seo_page_metadata_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC);
};
$insert = function ($path, $type, $id, $title, $deleted) use ($db) {
	$q = $db->prepare('INSERT INTO spm_seo_page_metadata (spm_path, spm_entity_type, spm_entity_id, spm_title, spm_create_time, spm_delete_time)
		VALUES (?, ?, ?, ?, now(), ' . ($deleted ? 'now()' : 'NULL') . ') RETURNING spm_seo_page_metadata_id');
	$q->execute(array($path, $type, $id, $title));
	return (int)$q->fetchColumn();
};

$db->beginTransaction();
try {
	section('an entity that comes back gets its own row back');
	$old = $insert("/page/$tag-old", 'page', $fake_id, 'Kept title', true);
	$lazy = $insert("/page/$tag-new", null, null, null, false);
	$back = $restore->invoke(null, $db, array('path' => "/page/$tag-new", 'entity_type' => 'page', 'entity_id' => $fake_id));
	check($back === true, 'the soft-deleted row is restored');
	$r = $row($old);
	check($r['spm_delete_time'] === null, 'the row is live again');
	check($r['spm_path'] === "/page/$tag-new", 'it takes the entity\'s current path', $r['spm_path']);
	check($r['spm_title'] === 'Kept title', 'it keeps the SEO copy the operator wrote');
	check($row($lazy)['spm_delete_time'] !== null, 'an untyped row made at that path in between gives way');

	section('nothing to restore, or the path is another entity\'s');
	check($restore->invoke(null, $db, array('path' => "/page/$tag-none", 'entity_type' => 'page', 'entity_id' => $fake_id - 1)) === false,
		'an entity with no soft-deleted row restores nothing (the sweep inserts)');
	$gone = $insert("/page/$tag-held-old", 'page', $fake_id - 2, 'Old', true);
	$holder = $insert("/page/$tag-held", 'page', $fake_id - 3, 'Holder', false);
	check($restore->invoke(null, $db, array('path' => "/page/$tag-held", 'entity_type' => 'page', 'entity_id' => $fake_id - 2)) === false,
		'a path another entity\'s live row holds is left alone');
	check($row($gone)['spm_delete_time'] !== null && $row($holder)['spm_delete_time'] === null, 'both rows are as they were');
} finally {
	$db->rollBack();
}

harness_finish();
