<?php
/** @joinery-test
 * name: backup_pairing
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Does an offsite backup come with the key that opens it?
 *
 * An encrypted archive and its .keys.json envelope are one backup in two files,
 * and the envelope is the only copy of the key — the database records that a run
 * was encrypted, never the sealed key. An archive uploaded without it is an
 * archive nobody can open, and it looks exactly like a good backup until the day
 * someone needs it.
 *
 * The policy is one sentence: an encrypted archive goes offsite alone only when
 * its envelope is already proven to be there. These tests pin the ways that
 * sentence can be got wrong — reading "we could not check" as "it is fine", and
 * warning about plaintext archives that have no key to lose.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupPairing.php'));

// ── Pairing a set of names ──────────────────────────────────────────────────
section('Which backups are missing their key, and which keys have lost their backup');

$names = array(
	'site-2026-08-01.tar.gz.enc',                  // paired below
	'site-2026-08-01.tar.gz.enc.keys.json',
	'db-2026-08-02.sql.gz.enc',                    // encrypted, no envelope
	'db-2026-08-03.sql.gz',                        // plaintext: nothing to pair
	'site-2026-07-01.tar.gz.enc.keys.json',        // envelope, archive long gone
	'notes.txt',                                   // not a backup at all
);
$report = BackupPairing::classify($names);

check($report['paired'] === array('site-2026-08-01.tar.gz.enc'),
	'an archive stored beside its envelope is paired', implode(',', $report['paired']));
check($report['missing_envelope'] === array('db-2026-08-02.sql.gz.enc'),
	'an encrypted archive with no envelope is reported', implode(',', $report['missing_envelope']));
check($report['orphan_envelope'] === array('site-2026-07-01.tar.gz.enc.keys.json'),
	'an envelope whose archive is gone is reported', implode(',', $report['orphan_envelope']));

// A plaintext archive has no key to lose. Reporting one would train an operator
// to ignore the warning that matters.
check(!in_array('db-2026-08-03.sql.gz', $report['missing_envelope'], true),
	'a plaintext archive is never reported as missing a key');
check($report['paired'] === array_values(array_unique($report['paired'])),
	'nothing is reported twice');

// Full object keys, not just filenames — the same rules serve a cloud listing.
$keyed = BackupPairing::classify(array(
	'joinery-backups/nodeslug/site-2026-08-01.tar.gz.enc',
	'joinery-backups/nodeslug/site-2026-08-01.tar.gz.enc.keys.json',
	'joinery-backups/nodeslug/db-2026-08-02.sql.gz.enc',
));
check($keyed['paired'] === array('site-2026-08-01.tar.gz.enc')
	&& $keyed['missing_envelope'] === array('db-2026-08-02.sql.gz.enc'),
	'object keys classify the same as bare filenames');

// ── The upload policy ───────────────────────────────────────────────────────
section('Whether an encrypted archive may go offsite on its own');

$checked_with    = array('checked' => true,  'envelope_present' => true,  'artifact_present' => false, 'reason' => '');
$checked_without = array('checked' => true,  'envelope_present' => false, 'artifact_present' => false, 'reason' => '');
$unreadable      = array('checked' => false, 'envelope_present' => false, 'artifact_present' => false, 'reason' => 'credentials rejected');

$v = BackupPairing::verdict($checked_without, 'db-2026-08-03.sql.gz', false);
check($v['verdict'] === BackupPairing::PROCEED,
	'a plaintext archive uploads on its own, always', $v['verdict']);

$v = BackupPairing::verdict($checked_with, 'db-2026-08-02.sql.gz.enc', false);
check($v['verdict'] === BackupPairing::PROCEED,
	'an archive whose envelope is already offsite uploads on its own', $v['verdict']);

$v = BackupPairing::verdict($checked_without, 'db-2026-08-02.sql.gz.enc', true);
check($v['verdict'] === BackupPairing::PAIR,
	'when the node holds the envelope, it travels with the archive', $v['verdict']);

$v = BackupPairing::verdict($checked_without, 'db-2026-08-02.sql.gz.enc', null);
check($v['verdict'] === BackupPairing::PAIR,
	'when nobody has asked the node, send it and let the attempt say what is true', $v['verdict']);

$v = BackupPairing::verdict($checked_without, 'db-2026-08-02.sql.gz.enc', false);
check($v['verdict'] === BackupPairing::BLOCKED,
	'when the key exists nowhere, the upload is refused rather than silently useless', $v['verdict']);
check(strpos($v['message'], 'nobody can restore') !== false,
	'and the refusal is stated as what it costs the operator', $v['message']);

// "We could not read the target" is not "the envelope is not there". Reading
// our own blindness as an answer is how a warning system starts lying.
$v = BackupPairing::verdict($unreadable, 'db-2026-08-02.sql.gz.enc', true);
check($v['verdict'] === BackupPairing::PAIR && strpos($v['message'], 'could not be read') !== false,
	'an unreadable target is reported as unknown, not as absent', $v['message']);

$v = BackupPairing::verdict($unreadable, 'db-2026-08-02.sql.gz.enc', null);
check($v['verdict'] === BackupPairing::PAIR,
	'an unreadable target never silently permits a lone encrypted upload', $v['verdict']);

// The envelope name a caller is told to send must be the one BackupEnvelope
// mints, or the pairing check and the upload disagree about the file.
$v = BackupPairing::verdict($checked_without, 'site-2026-08-01.tar.gz.enc', true);
check(strpos($v['message'], BackupEnvelope::sidecar_name('site-2026-08-01.tar.gz.enc')) !== false,
	'the operator is told the exact key file involved', $v['message']);

// ── Answering from a listing the caller already has ─────────────────────────
section('The check costs nothing when the caller has already listed the target');

// A page that has just listed a node's objects should not make a second request
// to answer a question that listing already contains. Keys or listing rows both
// work, because callers hold both shapes.
$listing = array(
	array('key' => 'joinery-backups/nodeslug/site-2026-08-01.tar.gz.enc', 'size' => 10),
	array('key' => 'joinery-backups/nodeslug/site-2026-08-01.tar.gz.enc.keys.json', 'size' => 1),
);
$state = BackupPairing::cloud_state(new BackupTarget(), 'nodeslug', 'site-2026-08-01.tar.gz.enc', $listing);
check($state['checked'] === true, 'an injected listing counts as checked');
check($state['artifact_present'] === true && $state['envelope_present'] === true,
	'both halves are found in the listing the caller supplied');
check(BackupPairing::verdict($state, 'site-2026-08-01.tar.gz.enc', false)['verdict'] === BackupPairing::PROCEED,
	'and an already-paired archive uploads on its own');

$state = BackupPairing::cloud_state(new BackupTarget(), 'nodeslug', 'db-2026-08-02.sql.gz.enc', $listing);
check($state['checked'] === true && $state['envelope_present'] === false,
	'an archive absent from the listing has no envelope there either');
check(BackupPairing::verdict($state, 'db-2026-08-02.sql.gz.enc', false)['verdict'] === BackupPairing::BLOCKED,
	'and with no key on the node either, it is refused');

// The envelope key is the archive key plus the suffix — if these two ever
// disagree the check would silently answer about the wrong object.
check($state['envelope_key'] === $state['artifact_key'] . '.keys.json',
	'the envelope key is the archive key plus the envelope suffix', $state['envelope_key']);

harness_finish();
