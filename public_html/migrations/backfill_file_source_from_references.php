<?php
/**
 * Give untagged files an origin, from what actually references them.
 *
 * fil_source names the subsystem that created a file, and File::source_catalog()
 * turns that into whether a browse surface lists it and where. Files stored
 * before the tags existed carry NULL, so a deployment with any history shows a
 * large "Unclassified" bucket that is really uploads, photos and mail
 * attachments mixed together.
 *
 * This classifies ONLY what a referencing row proves. Every rule below is a
 * foreign key from a table whose meaning is unambiguous — an inbound message
 * attachment row means the file arrived on an email, an entity-photo row means
 * the entity-photo uploader made it (the one path that stamps
 * SOURCE_ENTITY_PHOTO today). A file nothing points at stays NULL and keeps
 * reading as "Unclassified", which is the honest answer: guessing from filename
 * or MIME type would produce a confident wrong label, and Unclassified is a
 * listed, browsable bucket rather than a hiding place.
 *
 * Content tables (posts, pages, products, events, locations, mailing lists,
 * session files, purchase requirements) PICK an existing image rather than
 * uploading their own — the file itself came through the generic uploader, the
 * one that stamps SOURCE_USER_UPLOAD today. So a reference from one of them
 * proves a deliberate human upload, not an entity photo.
 *
 * Drive is deliberately absent: Drive has tagged its files from the beginning,
 * so there is nothing of its to carry over and a rule for it could only
 * mislabel something else.
 *
 * Safety properties:
 *  - Only ever writes rows where fil_source IS NULL. An existing tag is never
 *    overwritten, so re-running cannot reclassify anything.
 *  - Idempotent: a second run finds no NULL rows left for those ids.
 *  - Every table is existence-checked first. Most belong to plugins, and a
 *    deployment that never installed one has no such table — an unguarded
 *    UPDATE there would fail and halt every migration behind it.
 *  - Ordered machine-owned first, so a file referenced from two places lands on
 *    the more specific meaning rather than the more general one.
 */
function backfill_file_source_from_references() {
	$dblink = DbConnector::get_instance()->get_db_link();
	require_once(PathHelper::getIncludePath('data/files_class.php'));

	// Ordered: machine-owned and unambiguous first, generic human upload last.
	$rules = array(
		array(
			'source' => File::SOURCE_MAILBOX_SEARCH_INDEX,
			'table'  => 'imi_inbound_mailbox_search_index',
			'column' => 'imi_fil_file_id',
			'why'    => 'sealed mailbox search index',
		),
		array(
			'source' => File::SOURCE_MAIL_IMPORT_ARCHIVE,
			'table'  => 'mir_mail_import_runs',
			'column' => 'mir_fil_file_id',
			'why'    => 'archive uploaded for a mail import run',
		),
		array(
			'source' => File::SOURCE_EMAIL_ATTACHMENT,
			'table'  => 'ima_inbound_message_attachments',
			'column' => 'ima_fil_file_id',
			'why'    => 'attachment on an inbound email',
		),
		array(
			'source' => File::SOURCE_AI_CHAT_UPLOAD,
			'table'  => 'aia_message_attachments',
			'column' => 'aia_fil_file_id',
			'why'    => 'file attached to an AI chat message',
		),
		array(
			'source' => File::SOURCE_ENTITY_PHOTO,
			'table'  => 'eph_entity_photos',
			'column' => 'eph_fil_file_id',
			'why'    => 'entity photo gallery item',
		),
		// Everything below: a person uploaded the file and something points at it.
		array('source' => File::SOURCE_USER_UPLOAD, 'table' => 'pst_posts',                'column' => 'pst_fil_file_id', 'why' => 'image chosen for a blog post'),
		array('source' => File::SOURCE_USER_UPLOAD, 'table' => 'pag_pages',                'column' => 'pag_fil_file_id', 'why' => 'image chosen for a page'),
		array('source' => File::SOURCE_USER_UPLOAD, 'table' => 'evt_events',               'column' => 'evt_fil_file_id', 'why' => 'image chosen for an event'),
		array('source' => File::SOURCE_USER_UPLOAD, 'table' => 'loc_locations',            'column' => 'loc_fil_file_id', 'why' => 'image chosen for a location'),
		array('source' => File::SOURCE_USER_UPLOAD, 'table' => 'pro_products',             'column' => 'pro_fil_file_id', 'why' => 'image chosen for a product'),
		array('source' => File::SOURCE_USER_UPLOAD, 'table' => 'mlt_mailing_lists',        'column' => 'mlt_fil_file_id', 'why' => 'image chosen for a mailing list'),
		array('source' => File::SOURCE_USER_UPLOAD, 'table' => 'esf_event_session_files',  'column' => 'esf_fil_file_id', 'why' => 'file attached to an event session'),
		array('source' => File::SOURCE_USER_UPLOAD, 'table' => 'prq_product_requirements', 'column' => 'prq_fil_file_id', 'why' => 'file supplied to satisfy a purchase requirement'),
	);

	$before = (int)$dblink->query("SELECT COUNT(*) FROM fil_files WHERE fil_source IS NULL")->fetchColumn();
	echo "  Untagged files before: {$before}\n";
	if ($before === 0) {
		echo "  Nothing to classify.\n";
		return true;
	}

	$total = 0;
	foreach ($rules as $rule) {
		$exists = $dblink->query(
			"SELECT to_regclass(" . $dblink->quote('public.' . $rule['table']) . ")"
		)->fetchColumn();
		if (!$exists) {
			// A plugin this site never installed. Not a failure.
			continue;
		}

		$sql = "UPDATE fil_files SET fil_source = :source
		        WHERE fil_source IS NULL
		          AND fil_file_id IN (
		              SELECT " . $rule['column'] . " FROM " . $rule['table'] . "
		              WHERE " . $rule['column'] . " IS NOT NULL
		          )";
		$update = $dblink->prepare($sql);
		$update->execute(array(':source' => $rule['source']));

		$changed = $update->rowCount();
		if ($changed > 0) {
			$total += $changed;
			echo "  {$changed} → {$rule['source']} ({$rule['why']})\n";
		}
	}

	$after = (int)$dblink->query("SELECT COUNT(*) FROM fil_files WHERE fil_source IS NULL")->fetchColumn();
	echo "  Classified {$total}; {$after} remain unclassified (nothing references them).\n";

	return true;
}
?>
