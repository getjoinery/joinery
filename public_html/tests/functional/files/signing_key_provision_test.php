<?php
/** @joinery-test
 * name: signing_key_provision
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * First-mint provisioning of the file-URL signing key (docs/file_signed_urls.md).
 *
 * file_signed_url_key is declared in settings.json, so every deployment already
 * has the row — empty — before anything mints. Provisioning therefore has to
 * fill an existing empty row, and must never overwrite a key already in use:
 * outstanding signed URLs are signed with it. This pins both halves, plus the
 * refusal that made the gap visible — a deployment stuck with an empty key
 * cannot mint a URL at all.
 *
 * Self-cleaning: the deployment's real key is restored in the finally block, so
 * signed URLs minted before this test ran keep verifying afterwards.
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') {
	echo "This test must be run from the command line.\n";
	exit(1);
}

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/files_class.php'));

/** Clear the per-request key cache so the next call re-reads the row. */
function forget_cached_key() {
	$p = new ReflectionProperty('File', 'signing_key');
	$p->setAccessible(true);
	$p->setValue(null, null);
}

$original = get_setting_raw(File::SIGNING_KEY_SETTING);

try {
	section('The seeded row');

	check($original !== null, 'file_signed_url_key row exists',
		'declared in settings.json — a missing row means the seed did not run');

	// The state every fresh deployment is in: row present, value empty.
	set_setting_raw(File::SIGNING_KEY_SETTING, '');
	forget_cached_key();

	check(get_setting_raw(File::SIGNING_KEY_SETTING) === '', 'row blanked for the test');

	section('Minting fills the empty row');

	check(File::provisionSigningKey(), 'provisionSigningKey() reports a key');

	$minted = get_setting_raw(File::SIGNING_KEY_SETTING);
	check(is_string($minted) && $minted !== '', 'the empty row now holds a key',
		'a DO NOTHING upsert would leave it empty forever');
	check(strncmp((string)$minted, 'v1.', 3) === 0, 'the stored key is encrypted at rest',
		'got: ' . substr((string)$minted, 0, 12));

	section('A key in use is never overwritten');

	forget_cached_key();
	check(File::provisionSigningKey(), 'provisioning again still reports a key');
	check(get_setting_raw(File::SIGNING_KEY_SETTING) === $minted,
		'the second call left the existing key alone',
		'overwriting would break every outstanding signed URL');

	section('Minting works end to end');

	forget_cached_key();
	$file = new File(NULL);
	$ref = new ReflectionProperty('SystemBase', 'key');
	$ref->setAccessible(true);
	$ref->setValue($file, 424242);   // no row needed: signing covers id/size/expiry
	$file->set('fil_name', 'signing_key_provision_test.txt');

	$url = $file->mintSignedUrl('original', 300, 'short');
	check(strpos($url, 'sig=') !== false && strpos($url, 'expires=') !== false,
		'a signed URL mints once a key exists', $url);

	preg_match('/expires=(\d+)&sig=([0-9a-f]+)/', $url, $m);
	check(!empty($m) && $file->verify_signed_request('original', $m[1], $m[2]),
		'the minted signature verifies');

} finally {
	if ($original !== null) {
		set_setting_raw(File::SIGNING_KEY_SETTING, $original);
	}
	forget_cached_key();
}

harness_finish();
