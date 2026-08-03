<?php
/** @joinery-test
 * name: blob_layer
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * File blob layer functional test (specs/file_blob_layer.md).
 *
 * Exercises the refcounted fbb_file_blobs layer through the File model:
 *   - createFromUpload / createFromBytes mint a blob and link the file
 *   - dedup (same sha256 + size + is_private) retains the existing blob, writes
 *     no second physical file, and gives the secondary its own fil_name
 *   - dedup scoping: same bytes public vs private = two blobs
 *   - visibility flip at refcount 1 moves the blob's bytes between dirs
 *   - copy-on-write split at refcount > 1 gives the changed file its own blob
 *   - release at refcount 0 deletes the original + variants + the blob row;
 *     a still-referenced blob survives
 *
 * Self-cleaning: every fixture File is permanently deleted in finally.
 *
 * @version 1.1.0
 */

if (php_sapi_name() !== 'cli') {
	echo "This test must be run from the command line.\n";
	exit(1);
}

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));

/** A GD-generated PNG big enough that resize() produces real variants. The
 *  seed pixel makes each call's bytes distinct so unrelated fixtures don't
 *  dedup onto each other. */
function make_png($seed = 0) {
	$im = imagecreatetruecolor(64, 48);
	imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 30, 120, 200));
	imagefilledrectangle($im, 10, 10, 30, 30, imagecolorallocate($im, 240, 200, 40));
	imagesetpixel($im, 0, 0, imagecolorallocate($im, $seed % 256, ($seed * 7) % 256, ($seed * 13) % 256));
	ob_start();
	imagepng($im);
	$data = ob_get_clean();
	return $data;
}

$made = array(); // Files to tear down
$track = function ($f) use (&$made) { if ($f && $f->key) { $made[] = $f; } return $f; };

/** Physical path of a blob's original, checking both dirs. */
function blob_original_path(FileBlob $b) {
	$settings = Globalvars::get_instance();
	$restricted = $settings->get_setting('upload_dir');
	$fast = dirname($restricted) . '/static_files/uploads';
	$name = $b->get('fbb_stored_name');
	foreach (array($fast . '/' . $name, $restricted . '/' . $name) as $p) {
		if (is_file($p)) return $p;
	}
	return null;
}

try {
	// =============================================================
	section('createFromBytes → blob row + link');
	$bytes = 'blob-layer-' . bin2hex(random_bytes(8)); // unique non-image payload
	$f1 = $track(File::createFromBytes($bytes, 'doc_' . bin2hex(random_bytes(4)) . '.txt', 'text/plain', 1, array()));
	check($f1 && $f1->key, 'file created');
	$blob_id = $f1->get('fil_fbb_file_blob_id');
	check(!empty($blob_id), 'file links a blob (fil_fbb_file_blob_id set)');
	$b1 = new FileBlob((int)$blob_id, true);
	check($b1->key && (int)$b1->get('fbb_reference_count') === 1, 'blob refcount is 1');
	check($f1->get('fil_name') === $b1->get('fbb_stored_name'), 'fresh upload: fil_name == fbb_stored_name');
	check(is_file(blob_original_path($b1)), 'physical bytes on disk under stored name');
	check(hash('sha256', $bytes) === $b1->get('fbb_sha256'), 'blob sha256 matches content');
	check((int)$b1->get('fbb_size_bytes') === strlen($bytes), 'blob size matches content');
	check($f1->read_bytes('original') === $bytes, 'read_bytes returns the content through the blob');

	// =============================================================
	section('dedup hit retains, no second physical file');
	$before_count = (int)(new MultiFileBlob(array('sha256' => hash('sha256', $bytes))))->count();
	$f2 = $track(File::createFromBytes($bytes, 'dup_' . bin2hex(random_bytes(4)) . '.txt', 'text/plain', 1, array()));
	$b2 = $f2->_blob();
	check((int)$b2->key === (int)$b1->key, 'dedup: secondary references the same blob');
	$b1r = new FileBlob((int)$b1->key, true);
	check((int)$b1r->get('fbb_reference_count') === 2, 'dedup: refcount bumped to 2');
	$after_count = (int)(new MultiFileBlob(array('sha256' => hash('sha256', $bytes))))->count();
	check($after_count === $before_count, 'dedup: no new blob row created');
	check($f2->get('fil_name') !== $b2->get('fbb_stored_name'), 'secondary fil_name differs from shared stored_name');
	check($f2->read_bytes('original') === $bytes, 'secondary serves the shared bytes');

	// =============================================================
	section('dedup scoping: same bytes public vs private = two blobs');
	$fp = $track(File::createFromBytes($bytes, 'priv_' . bin2hex(random_bytes(4)) . '.txt', 'text/plain', 1, array('fil_private' => true)));
	$bp = $fp->_blob();
	check((int)$bp->key !== (int)$b1->key, 'private upload of same bytes gets its own blob');
	check($bp->is_private_bool() === true, 'private blob is_private = true');
	check($b1->is_private_bool() === false, 'public blob is_private = false');

	// =============================================================
	section('visibility flip at refcount 1 moves bytes');
	$img_bytes = make_png(101);
	$fi = $track(File::createFromBytes($img_bytes, 'flip_' . bin2hex(random_bytes(4)) . '.png', 'image/png', 1, array()));
	$fi->resize();
	$bi = $fi->_blob();
	check((int)$bi->get('fbb_reference_count') === 1, 'flip fixture: refcount 1');
	check(!$bi->is_private_bool(), 'flip fixture: starts public');
	$fast_dir = dirname(Globalvars::get_instance()->get_setting('upload_dir')) . '/static_files/uploads';
	$restricted_dir = Globalvars::get_instance()->get_setting('upload_dir');
	$stored = $bi->get('fbb_stored_name');
	check(is_file($fast_dir . '/' . $stored), 'flip fixture: bytes in fast dir while public');
	// Make it private → blob flips at refcount 1.
	$fi->set('fil_private', true);
	$fi->save();
	$bi_after = new FileBlob((int)$bi->key, true);
	check($bi_after->is_private_bool() === true, 'flip: blob is now private');
	check(is_file($restricted_dir . '/' . $stored), 'flip: bytes moved to restricted dir');
	check(!is_file($fast_dir . '/' . $stored), 'flip: bytes no longer in fast dir');
	check((int)$bi_after->key === (int)$bi->key, 'flip: same blob (no split at refcount 1)');

	// =============================================================
	section('copy-on-write split at refcount > 1');
	$cow_bytes = 'cow-' . bin2hex(random_bytes(8));
	$c1 = $track(File::createFromBytes($cow_bytes, 'cow_a_' . bin2hex(random_bytes(4)) . '.txt', 'text/plain', 1, array()));
	$c2 = $track(File::createFromBytes($cow_bytes, 'cow_b_' . bin2hex(random_bytes(4)) . '.txt', 'text/plain', 1, array()));
	$shared_blob_id = (int)$c1->get('fil_fbb_file_blob_id');
	check((int)$c2->get('fil_fbb_file_blob_id') === $shared_blob_id, 'cow: both files share one blob');
	check((int)(new FileBlob($shared_blob_id, true))->get('fbb_reference_count') === 2, 'cow: shared refcount 2');
	// Change restriction on c1 → COW split (not a flip of the shared blob).
	$c1->set('fil_private', true);
	$c1->save();
	$c1_blob = $c1->_blob();
	check((int)$c1_blob->key !== $shared_blob_id, 'cow: changed file got a NEW blob');
	check($c1_blob->is_private_bool() === true, 'cow: new blob is private');
	$shared_after = new FileBlob($shared_blob_id, true);
	check((int)$shared_after->get('fbb_reference_count') === 1, 'cow: old blob decremented to 1');
	check($shared_after->is_private_bool() === false, 'cow: old (shared) blob stays public');
	$c2_reload = new File((int)$c2->key, true);
	check((int)$c2_reload->get('fil_fbb_file_blob_id') === $shared_blob_id, 'cow: untouched sibling still points at old blob');
	check($c2_reload->read_bytes('original') === $cow_bytes, 'cow: sibling still serves its bytes');
	check($c1->read_bytes('original') === $cow_bytes, 'cow: split file serves identical bytes');

	// =============================================================
	section('release: refcount decrement, delete at zero');
	// A dedicated 2-reference blob; delete one → blob survives, other → reclaimed.
	$rel_bytes = make_png(202); // image so variants exist to reclaim
	$r1 = File::createFromBytes($rel_bytes, 'rel_a_' . bin2hex(random_bytes(4)) . '.png', 'image/png', 1, array());
	$r1->resize();
	$r2 = File::createFromBytes($rel_bytes, 'rel_b_' . bin2hex(random_bytes(4)) . '.png', 'image/png', 1, array());
	$rel_blob_id = (int)$r1->get('fil_fbb_file_blob_id');
	check((int)$r2->get('fil_fbb_file_blob_id') === $rel_blob_id, 'release: two files share the blob');
	$rel_blob = new FileBlob($rel_blob_id, true);
	$orig_path = blob_original_path($rel_blob);
	require_once(PathHelper::getIncludePath('includes/ImageSizeRegistry.php'));
	$variant_path = dirname($orig_path) . '/' . array_key_first(ImageSizeRegistry::get_sizes()) . '/' . $rel_blob->get('fbb_stored_name');
	check(is_file($orig_path), 'release: original bytes present before delete');

	// Delete the first reference → blob survives, bytes stay.
	$r1->permanent_delete();
	$after1 = new FileBlob($rel_blob_id, true);
	$still_there = false;
	$chk = DbConnector::get_instance()->get_db_link()->prepare('SELECT fbb_reference_count FROM fbb_file_blobs WHERE fbb_file_blob_id = ?');
	$chk->execute(array($rel_blob_id));
	$rc = $chk->fetchColumn();
	check($rc !== false && (int)$rc === 1, 'release: refcount drops to 1, blob survives');
	check(is_file($orig_path), 'release: bytes retained while a reference remains');

	// Delete the last reference → blob row + bytes gone.
	$r2->permanent_delete();
	$chk->execute(array($rel_blob_id));
	check($chk->fetchColumn() === false, 'release-at-zero: blob row deleted');
	check(!is_file($orig_path), 'release-at-zero: original bytes deleted');
	check(!is_file($variant_path), 'release-at-zero: image variant deleted');

	// =============================================================
	section('replace_bytes isolates a dedup-shared blob (seal-corruption guard)');
	// Two byte-identical private files dedup onto one blob. Rewriting one
	// (as sealing an attachment does) must NOT touch the sibling's bytes.
	$seal_bytes = 'seal-' . bin2hex(random_bytes(8));
	$s1 = $track(File::createFromBytes($seal_bytes, 's1_' . bin2hex(random_bytes(4)) . '.bin', 'application/octet-stream', 1, array('fil_private' => true)));
	$s2 = $track(File::createFromBytes($seal_bytes, 's2_' . bin2hex(random_bytes(4)) . '.bin', 'application/octet-stream', 1, array('fil_private' => true)));
	$seal_blob_id = (int)$s1->get('fil_fbb_file_blob_id');
	check((int)$s2->get('fil_fbb_file_blob_id') === $seal_blob_id, 'seal: two identical private files share one blob');
	check((int)(new FileBlob($seal_blob_id, true))->get('fbb_reference_count') === 2, 'seal: shared refcount 2');
	$cipher1 = 'CIPHERTEXT-ONE-' . bin2hex(random_bytes(12));
	check($s1->replace_bytes($cipher1) === true, 'seal: replace_bytes succeeds');
	$s1_blob = $s1->_blob();
	check((int)$s1_blob->key !== $seal_blob_id, 'seal: rewritten file split off its own blob');
	check($s1->read_bytes('original') === $cipher1, 'seal: rewritten file serves the new bytes');
	$s2_reload = new File((int)$s2->key, true);
	check((int)$s2_reload->get('fil_fbb_file_blob_id') === $seal_blob_id, 'seal: sibling still on the original blob');
	check($s2_reload->read_bytes('original') === $seal_bytes, 'seal: sibling bytes UNCHANGED (no cross-corruption)');
	check((int)(new FileBlob($seal_blob_id, true))->get('fbb_reference_count') === 1, 'seal: original blob decremented to 1');

	// A rewritten blob is no longer a dedup target (bytes no longer describe a
	// shareable original), so its hash is cleared and its size updated.
	$s1_blob_reload = new FileBlob((int)$s1_blob->key, true);
	check($s1_blob_reload->get('fbb_sha256') === null, 'seal: rewritten blob hash cleared');
	check((int)$s1_blob_reload->get('fbb_size_bytes') === strlen($cipher1), 'seal: rewritten blob size updated to ciphertext length');
	$s3 = $track(File::createFromBytes($seal_bytes, 's3_' . bin2hex(random_bytes(4)) . '.bin', 'application/octet-stream', 1, array('fil_private' => true)));
	check((int)$s3->get('fil_fbb_file_blob_id') === $seal_blob_id, 'seal: identical plaintext dedups onto the intact original, never the rewritten blob');

	// =============================================================
	section('soft-delete of the last public reference flips shared bytes private (exposure guard)');
	$pub_bytes = 'pub-' . bin2hex(random_bytes(8));
	$p1 = $track(File::createFromBytes($pub_bytes, 'p1_' . bin2hex(random_bytes(4)) . '.txt', 'text/plain', 1, array()));
	$p2 = $track(File::createFromBytes($pub_bytes, 'p2_' . bin2hex(random_bytes(4)) . '.txt', 'text/plain', 1, array()));
	$pub_blob_id = (int)$p1->get('fil_fbb_file_blob_id');
	check((int)$p2->get('fil_fbb_file_blob_id') === $pub_blob_id, 'pub-del: two public files share one blob');
	$pub_stored = (new FileBlob($pub_blob_id, true))->get('fbb_stored_name');
	check(is_file($fast_dir . '/' . $pub_stored), 'pub-del: shared bytes start in the fast-serve dir');
	// One reference soft-deleted, a live public sibling remains → bytes stay public.
	$p1->soft_delete();
	check(is_file($fast_dir . '/' . $pub_stored), 'pub-del: bytes stay public while a live public sibling remains');
	check(!(new FileBlob($pub_blob_id, true))->is_private_bool(), 'pub-del: blob still public with a live referrer');
	// Last public reference soft-deleted → bytes must leave the world-served dir.
	$p2->soft_delete();
	$pub_after = new FileBlob($pub_blob_id, true);
	check($pub_after->is_private_bool() === true, 'pub-del: blob flipped private once no public referrer remains');
	check(!is_file($fast_dir . '/' . $pub_stored), 'pub-del: bytes left the fast-serve dir');
	check(is_file($restricted_dir . '/' . $pub_stored), 'pub-del: bytes moved to the restricted dir');

} finally {
	foreach ($made as $f) {
		if ($f && $f->key) {
			$reload = new File((int)$f->key, true);
			if ($reload->key) {
				$reload->permanent_delete();
			}
		}
	}
	echo "cleanup: fixtures removed\n";
}

harness_finish();
