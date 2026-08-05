<?php
/** @joinery-test
 * name: backup_envelope
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Backup envelope keys: the round trip that has to work at disaster time, the
 * refusals that stop an unopenable archive being produced, and the site key's
 * no-clobber mint.
 *
 * The property under test throughout: a data key sealed to N recipients opens
 * with ANY of their private halves and with nothing else.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

// Stand-in recipients, so the round trip never depends on (or disturbs) the
// site's real recovery key.
$recovery = sodium_crypto_box_keypair();
$site     = sodium_crypto_box_keypair();
$stranger = sodium_crypto_box_keypair();

$recipients = [
	['kind' => 'recovery', 'pub' => sodium_crypto_box_publickey($recovery)],
	['kind' => 'site',     'pub' => sodium_crypto_box_publickey($site)],
];

// ── Round trip ──────────────────────────────────────────────────────────────
section('Seal and open');

$data_key = base64_encode(random_bytes(32));
$env = BackupEnvelope::build($data_key, 'demo-2026-08-02.tar.gz.enc', $recipients);

check(count($env['recipients']) === 2, 'envelope seals to both recipients',
	'got ' . count($env['recipients']));
check($env['cipher'] === BackupEnvelope::CIPHER, 'envelope names the archive cipher');
check(strpos(json_encode($env), $data_key) === false,
	'the plaintext data key never appears in the envelope');

check(BackupEnvelope::open($env, $recovery) === $data_key, 'recovery key opens it');
check(BackupEnvelope::open($env, $site) === $data_key, 'site key opens it');

$refused = false;
try { BackupEnvelope::open($env, $stranger); }
catch (BackupEnvelopeException $e) { $refused = true; }
check($refused, 'an unrelated key is refused');

// Losing the site key must not cost anything: recovery alone still opens a
// backup that was sealed to both.
$recovery_only = ['recipients' => [$env['recipients'][0]]];
check(BackupEnvelope::open($recovery_only, $recovery) === $data_key,
	'recovery opens an envelope even with the site recipient stripped');

// ── Identity shapes ─────────────────────────────────────────────────────────
section('Key shapes a human might paste');

$sk = sodium_crypto_box_secretkey($recovery);
check(BackupEnvelope::open($env, $sk) === $data_key, 'a bare secret key works');
check(BackupEnvelope::open($env, base64_encode($sk)) === $data_key, 'base64 secret key works');
check(BackupEnvelope::open($env, base64_encode($recovery)) === $data_key, 'base64 keypair works');

// A public key is the same 32 bytes as a secret key, so only the envelope can
// tell them apart — and the error has to say which mistake was made, because
// the fixes are opposite.
$msg = '';
try { BackupEnvelope::open($env, base64_encode(sodium_crypto_box_publickey($recovery))); }
catch (BackupEnvelopeException $e) { $msg = $e->getMessage(); }
check(strpos($msg, 'PUBLIC half') !== false,
	'pasting the PUBLIC key is named as such, not reported as a wrong key', $msg);

$msg = '';
try { BackupEnvelope::open($env, 'nonsense'); }
catch (BackupEnvelopeException $e) { $msg = $e->getMessage(); }
check($msg !== '' && strpos($msg, 'PUBLIC half') === false,
	'an actually-wrong key still reports as a wrong key', $msg);

// ── Encoding ────────────────────────────────────────────────────────────────
section('Sidecar encoding');

$decoded = BackupEnvelope::decode(BackupEnvelope::encode($env));
check($decoded['artifact'] === $env['artifact'], 'encode/decode preserves the artifact name');
check(BackupEnvelope::open($decoded, $recovery) === $data_key, 'a decoded envelope still opens');

$bad_version = $env;
$bad_version['version'] = 99;
$threw = false;
try { BackupEnvelope::decode(json_encode($bad_version)); }
catch (BackupEnvelopeException $e) { $threw = true; }
check($threw, 'an envelope from a newer format is refused, not guessed at');

$threw = false;
try { BackupEnvelope::decode('not json'); }
catch (BackupEnvelopeException $e) { $threw = true; }
check($threw, 'unreadable JSON is refused');

check(BackupEnvelope::sidecar_name('a.tar.gz.enc') === 'a.tar.gz.enc.keys.json', 'sidecar naming');
check(BackupEnvelope::is_sidecar_name('a.tar.gz.enc.keys.json'), 'sidecar names are recognized');
check(!BackupEnvelope::is_sidecar_name('a.tar.gz.enc'), 'archives are not mistaken for sidecars');

// ── Refusals ────────────────────────────────────────────────────────────────
section('Refusals');

$threw = false;
try { BackupEnvelope::build('', 'x.tar.gz.enc', $recipients); }
catch (BackupEnvelopeException $e) { $threw = true; }
check($threw, 'an empty data key is refused');

$threw = false;
try { BackupEnvelope::build($data_key, 'x.tar.gz.enc', []); }
catch (BackupEnvelopeException $e) { $threw = true; }
check($threw, 'an envelope with no recipients is refused');

$threw = false;
try { BackupEnvelope::open(['recipients' => []], $recovery); }
catch (BackupEnvelopeException $e) { $threw = true; }
check($threw, 'opening a recipient-less envelope is refused');

// ── Site key ────────────────────────────────────────────────────────────────
section('Site key');

// The site key is real infrastructure, not a fixture: it is disposable by
// design (recovery still opens everything without it), so a run that mints one
// leaves it in place rather than deleting a key later backups may be sealed to.
//
// On a correctly-permissioned deployment the key is 600 www-data:www-data,
// because the backup task runs as the web user. This suite usually runs from a
// shell as somebody else, who therefore CANNOT read it — and must not, or the
// permissions would be wrong. So the real-path checks run only when this
// account can legitimately read the key. The behaviour itself is covered
// against a temporary path in backup_envelope_cli_test.php, which is where it
// belongs: it needs no production state at all.
$site_path = BackupEnvelope::site_key_path();
if (is_file($site_path) && @file_get_contents($site_path) === false) {
	harness_skip('site key belongs to the web user, as it should',
		$site_path . ' is not readable by ' . (function_exists('posix_getpwuid')
			? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?')
		. ' — covered against a temp path in backup_envelope_cli');
	$skip_site_key = true;
}

if (empty($skip_site_key)) {

$kp1 = BackupEnvelope::site_keypair();
check(strlen($kp1) === SODIUM_CRYPTO_BOX_KEYPAIRBYTES, 'site keypair is a sodium box keypair');
check(is_file($site_path), 'site key is persisted to config/');

$kp2 = BackupEnvelope::site_keypair();
check($kp1 === $kp2, 'a second call returns the same key, never a fresh one');

// 640, not 600. Backups run under more than one account — the web user on the
// scheduled run, the deploy account from a shell — and both are in the file's
// group, so an owner-only key locks every caller but one out of its own backups.
// What has to hold is that nothing outside the group can read it: this key opens
// every backup the site has ever made.
$perms = substr(sprintf('%o', fileperms($site_path)), -3);
check($perms === '640', 'site key is readable by its group and no wider', "perms {$perms}");
check((fileperms($site_path) & 0007) === 0, 'site key is not world-readable', "perms {$perms}");

$site_env = BackupEnvelope::build($data_key, 'x.tar.gz.enc',
	[['kind' => 'site', 'pub' => BackupEnvelope::site_public_key()]]);
check(BackupEnvelope::open_as_site($site_env) === $data_key, 'the site opens its own backup');

} // $skip_site_key

// ── Recovery key state machine ──────────────────────────────────────────────
section('Recovery key state');

$pub_b64 = base64_encode(sodium_crypto_box_publickey($recovery));
$fpr     = hash('sha256', sodium_crypto_box_publickey($recovery));

$c = BackupRecoveryKey::classify_key('', '');
check($c['state'] === 'unconfigured', 'no key set reads as unconfigured', $c['state']);

$c = BackupRecoveryKey::classify_key('this is not base64 key material', '');
check($c['state'] === 'invalid', 'a non-key value reads as invalid', $c['state']);

$c = BackupRecoveryKey::classify_key($pub_b64, '');
check($c['state'] === 'unproven', 'a set-but-unproven key reads as unproven', $c['state']);

$c = BackupRecoveryKey::classify_key($pub_b64, $fpr);
check($c['state'] === 'proven', 'a key whose proof matches reads as proven', $c['state']);

$c = BackupRecoveryKey::classify_key($pub_b64, hash('sha256', 'someone elses key'));
check($c['state'] === 'unproven', 'a proof for a DIFFERENT key does not count', $c['state']);

check(!BackupRecoveryKey::key_in_use($pub_b64, ''), 'an unproven key is free to replace');
check(BackupRecoveryKey::key_in_use(sodium_crypto_box_publickey($recovery), $fpr),
	'a proven key counts as in use');

// The possession sentence must bind to the key, or a proof recovered for one
// key would satisfy another.
$other_fpr = hash('sha256', sodium_crypto_box_publickey($stranger));
check(strpos(
	'Your recovery key opened this message. Backup recovery is proven for key fingerprint ' . $fpr . '.',
	$other_fpr) === false, 'the proof sentence names exactly one key');

// ── Live wiring ─────────────────────────────────────────────────────────────
section('This site');

$state = BackupRecoveryKey::setup_state();
check(in_array($state['state'], ['unconfigured', 'invalid', 'unproven', 'ready'], true),
	'setup_state reports a known state', $state['state']);

if ($state['is_ready'] && empty($skip_site_key)) {
	$live = BackupEnvelope::mint('live-check.tar.gz.enc');
	$kinds = array_map(function ($r) { return $r['kind']; }, $live['envelope']['recipients']);
	check(in_array('recovery', $kinds, true) && in_array('site', $kinds, true),
		'a real mint seals to both recovery and site', implode(',', $kinds));
	check(BackupEnvelope::open_as_site($live['envelope']) === $live['data_key'],
		'the site can open what it just minted');
} elseif (!empty($skip_site_key)) {
	harness_skip('a real mint needs the site key this account cannot read',
		'correct permissions; the mint path is exercised with stand-in recipients above');
} else {
	harness_skip('recovery key not set up on this site',
		'state=' . $state['state'] . ' — mint path exercised with stand-in recipients above');
}

harness_finish();
