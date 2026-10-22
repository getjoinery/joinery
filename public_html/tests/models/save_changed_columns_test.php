<?php
/** @joinery-test
 * name: save_changed_columns
 * tier: db
 * env: dev-only
 * needs: []
 *
 * Saving an existing record writes the columns the caller set, and leaves the
 * copy holding the row as stored. A copy loaded a moment before another save
 * therefore cannot put back what that save changed.
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();

function scc_group(string $name, string $category) {
	$g = new Group(NULL);
	$g->set('grp_name', $name);
	$g->set('grp_category', $category);
	$g->save();
	harness_register_row('grp_groups', 'grp_group_id', (int)$g->key);
	return new Group((int)$g->key, TRUE);
}

/** The row's version as Postgres counts it: it moves on every UPDATE, even one that changes nothing. */
function scc_xmin(int $id): string {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT xmin::text FROM grp_groups WHERE grp_group_id = ?');
	$q->execute(array($id));
	return (string)$q->fetchColumn();
}

/** A copy's own data, without get()'s decryption. */
function scc_data($model): array {
	return (function () { return get_object_vars($this->data); })->call($model);
}

$tag = bin2hex(random_bytes(4));

// ---------------------------------------------------------------------------
section('two copies that change different columns keep both changes');
// ---------------------------------------------------------------------------
$g = scc_group("scc-both-$tag", 'scc-a');
$namer = new Group((int)$g->key, TRUE);
$sorter = new Group((int)$g->key, TRUE);
$namer->set('grp_name', "scc-renamed-$tag");
$namer->save();
$sorter->set('grp_category', 'scc-b');
$sorter->save();
$now = new Group((int)$g->key, TRUE);
check($now->get('grp_name') === "scc-renamed-$tag", 'the first save survives the second', (string)$now->get('grp_name'));
check($now->get('grp_category') === 'scc-b', 'and the second is written');

// ---------------------------------------------------------------------------
section('a save after a targeted UPDATE keeps it');
// ---------------------------------------------------------------------------
$g = scc_group("scc-targeted-$tag", 'scc-a');
$copy = new Group((int)$g->key, TRUE);
Group::updateColumns((int)$g->key, array('grp_category' => 'scc-moved'));
$copy->set('grp_name', "scc-targeted-renamed-$tag");
$copy->save();
check((new Group((int)$g->key, TRUE))->get('grp_category') === 'scc-moved', 'the targeted UPDATE is not put back');

// ---------------------------------------------------------------------------
section('after a save the copy holds the row as stored');
// ---------------------------------------------------------------------------
$g = scc_group("scc-stored-$tag", 'scc-a');
$stale = new Group((int)$g->key, TRUE);
$other = new Group((int)$g->key, TRUE);
$other->set('grp_category', 'scc-other');
$other->save();
$stale->set('grp_name', "scc-stored-renamed-$tag");
$stale->save();
check($stale->get('grp_category') === 'scc-other', "the copy picks up the other save's change", (string)$stale->get('grp_category'));
check($stale->get('grp_name') === "scc-stored-renamed-$tag", 'and keeps its own');
$fresh_data = scc_data(new Group((int)$g->key, TRUE));
$drift = array();
foreach (scc_data($stale) as $col => $value) {
	if ($col === 'grp_name') continue;
	if (!array_key_exists($col, $fresh_data) || $fresh_data[$col] !== $value) {
		$drift[] = $col . ' ' . var_export($value, true) . ' vs ' . var_export($fresh_data[$col] ?? null, true);
	}
}
check(!$drift, 'every column it did not set is identical, type and all, to a fresh load', implode('; ', $drift));

// ---------------------------------------------------------------------------
section('saving a copy with nothing changed writes nothing');
// ---------------------------------------------------------------------------
$g = scc_group("scc-clean-$tag", 'scc-a');
$clean = new Group((int)$g->key, TRUE);
$before = scc_xmin((int)$g->key);
$result = $clean->save();
check(scc_xmin((int)$g->key) === $before, 'no UPDATE runs');
check($result !== false, 'and the save reports success like any other');
$clean->set('grp_category', 'scc-once');
$clean->save();
$after_one = scc_xmin((int)$g->key);
$clean->save();
check(scc_xmin((int)$g->key) === $after_one, 'a copy is clean again once it has been saved');

// ---------------------------------------------------------------------------
section('the retired no-update marker is refused, not written');
// ---------------------------------------------------------------------------
$g = scc_group("scc-marker-$tag", 'scc-a');
$marker = new Group((int)$g->key, TRUE);
$marker->set('grp_category', '-NOUPDATE-');
$refused = false;
try { $marker->save(); } catch (SystemBaseException $e) { $refused = true; }
check($refused, 'a column set to -NOUPDATE- makes the save throw');
check((new Group((int)$g->key, TRUE))->get('grp_category') === 'scc-a', 'and nothing is written');

// ---------------------------------------------------------------------------
section('a trash and a rename of one file keep each other, in either order');
// ---------------------------------------------------------------------------
$owner = make_user('SaveChangedCols');
$made_files = array();
harness_defer(function () use (&$made_files) {
	foreach ($made_files as $fid) {
		$f = new File((int)$fid, true);
		if ($f->key) { $f->permanent_delete(); }
	}
});
$new_file = function (string $name) use ($owner, &$made_files) {
	$f = File::createFromBytes('scc-' . bin2hex(random_bytes(8)), $name, 'text/plain', $owner->key,
		array('fil_private' => true, 'fil_source' => File::SOURCE_DRIVE));
	$made_files[] = $f->key;
	return (int)$f->key;
};

$fid = $new_file("trash-then-rename-$tag.txt");
$renamer = new File($fid, true);
$trasher = new File($fid, true);
$trasher->soft_delete();
$renamer->set('fil_title', "renamed-after-trash-$tag.txt");
$renamer->save();
$f = new File($fid, true);
check(!empty($f->get('fil_delete_time')), 'a rename through a copy loaded before the trash leaves the file in the trash');
check($f->get('fil_title') === "renamed-after-trash-$tag.txt", 'and renames it');

$fid = $new_file("rename-then-trash-$tag.txt");
$renamer = new File($fid, true);
$trasher = new File($fid, true);
$renamer->set('fil_title', "renamed-before-trash-$tag.txt");
$renamer->save();
$trasher->soft_delete();
$f = new File($fid, true);
check(!empty($f->get('fil_delete_time')), 'a trash through a copy loaded before the rename trashes it');
check($f->get('fil_title') === "renamed-before-trash-$tag.txt", 'and keeps the new name', (string)$f->get('fil_title'));

// ---------------------------------------------------------------------------
section('a clean copy saved after the file went public places the bytes as the row says');
// ---------------------------------------------------------------------------
$fid = $new_file("published-$tag.txt");
$stale_file = new File($fid, true);
$publisher = new File($fid, true);
$publisher->set('fil_private', false);
$publisher->save();
$stale_file->save();
$f = new File($fid, true);
check($f->is_public(), 'the row stays public', var_export($f->get('fil_private'), true));
check(!(new FileBlob((int)$f->get('fil_fbb_file_blob_id'), true))->is_private_bool(), 'and so do its bytes');

// ---------------------------------------------------------------------------
section('an insert_only column a caller set is not written, and the copy holds the stored value');
// ---------------------------------------------------------------------------
$fid = $new_file("insert-only-$tag.txt");
$head = (int)(new File($fid, true))->get('fil_fbb_file_blob_id');
$setter = new File($fid, true);
$setter->set('fil_fbb_file_blob_id', $head + 1000000);
$setter->set('fil_title', "insert-only-renamed-$tag.txt");
$setter->save();
check((int)(new File($fid, true))->get('fil_fbb_file_blob_id') === $head, 'the head is not moved by save()');
check((int)$setter->get('fil_fbb_file_blob_id') === $head, 'and the copy holds the stored head');

// ---------------------------------------------------------------------------
section('an event saved through an earlier copy keeps its UTC time matching its local time');
// ---------------------------------------------------------------------------
if (class_exists('Event')) {
	$utc_of = function ($local, $tz) { return LibraryFunctions::convert_time($local, $tz, 'UTC', 'Y-m-d H:i:s'); };
	$ev = new Event(NULL);
	$ev->set('evt_name', "HarnessTest SaveChanged $tag");
	$ev->set('evt_timezone', 'America/New_York');
	$ev->set('evt_start_time_local', '2026-11-03 10:00:00');
	$ev->set('evt_end_time_local', '2026-11-03 11:00:00');
	$ev->save();
	harness_register_row('evt_events', 'evt_event_id', (int)$ev->key);
	$renamer = new Event((int)$ev->key, TRUE);
	$mover = new Event((int)$ev->key, TRUE);
	$mover->set('evt_start_time_local', '2026-11-10 10:00:00');
	$mover->set('evt_end_time_local', '2026-11-10 11:00:00');
	$mover->save();
	$renamer->set('evt_name', "HarnessTest SaveChanged renamed $tag");
	$renamer->save();
	$e = new Event((int)$ev->key, TRUE);
	check(substr((string)$e->get('evt_start_time_local'), 0, 19) === '2026-11-10 10:00:00', 'the move survives the rename');
	check(substr((string)$e->get('evt_start_time'), 0, 19) === $utc_of('2026-11-10 10:00:00', 'America/New_York'),
		'and the UTC start is derived from the local start stored', (string)$e->get('evt_start_time'));
	check(substr((string)$e->get('evt_end_time'), 0, 19) === $utc_of('2026-11-10 11:00:00', 'America/New_York'),
		'and so is the UTC end', (string)$e->get('evt_end_time'));

	// A copy that sets only the end time leaves the UTC start to the start time
	// another save moved.
	$ender = new Event((int)$ev->key, TRUE);
	$starter = new Event((int)$ev->key, TRUE);
	$starter->set('evt_start_time_local', '2026-11-10 09:00:00');
	$starter->save();
	$ender->set('evt_end_time_local', '2026-11-10 12:00:00');
	$ender->save();
	$e = new Event((int)$ev->key, TRUE);
	check(substr((string)$e->get('evt_start_time'), 0, 19) === $utc_of('2026-11-10 09:00:00', 'America/New_York'),
		'an earlier copy setting only the end leaves the UTC start matching the stored start', (string)$e->get('evt_start_time'));
	check(substr((string)$e->get('evt_end_time'), 0, 19) === $utc_of('2026-11-10 12:00:00', 'America/New_York'),
		'while deriving the UTC end from the end it set', (string)$e->get('evt_end_time'));

	$se = new EventSession(NULL);
	$se->set('evs_evt_event_id', (int)$ev->key);
	$se->set('evs_title', "HarnessTest session $tag");
	$se->set('evs_start_time_local', '2026-11-10 10:00:00');
	$se->set('evs_end_time_local', '2026-11-10 10:30:00');
	$se->save();
	harness_register_row('evs_event_sessions', 'evs_event_session_id', (int)$se->key);
	$titler = new EventSession((int)$se->key, TRUE);
	$shifter = new EventSession((int)$se->key, TRUE);
	$shifter->set('evs_start_time_local', '2026-11-10 14:00:00');
	$shifter->set('evs_end_time_local', '2026-11-10 14:30:00');
	$shifter->save();
	$titler->set('evs_title', "HarnessTest session renamed $tag");
	$titler->save();
	$s2 = new EventSession((int)$se->key, TRUE);
	check(substr((string)$s2->get('evs_start_time'), 0, 19) === $utc_of('2026-11-10 14:00:00', 'America/New_York'),
		"a session's UTC start follows its stored local start through an earlier copy's save", (string)$s2->get('evs_start_time'));
	check(substr((string)$s2->get('evs_end_time'), 0, 19) === $utc_of('2026-11-10 14:30:00', 'America/New_York'),
		'and its UTC end', (string)$s2->get('evs_end_time'));
	$s_ender = new EventSession((int)$se->key, TRUE);
	$s_starter = new EventSession((int)$se->key, TRUE);
	$s_starter->set('evs_start_time_local', '2026-11-10 13:00:00');
	$s_starter->save();
	$s_ender->set('evs_end_time_local', '2026-11-10 15:00:00');
	$s_ender->save();
	$s3 = new EventSession((int)$se->key, TRUE);
	check(substr((string)$s3->get('evs_start_time'), 0, 19) === $utc_of('2026-11-10 13:00:00', 'America/New_York'),
		"a session copy setting only the end leaves the UTC start matching the stored start", (string)$s3->get('evs_start_time'));
	check(substr((string)$s3->get('evs_end_time'), 0, 19) === $utc_of('2026-11-10 15:00:00', 'America/New_York'),
		'and derives the UTC end it set', (string)$s3->get('evs_end_time'));
}

harness_finish();
