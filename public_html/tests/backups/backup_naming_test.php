<?php
/** @joinery-test
 * name: backup_naming
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * What counts as a backup, and what a given artifact is.
 *
 * This is pinned because the previous arrangement — every caller spelling the
 * rules out for itself — drifted silently. Adding the encrypted project archive
 * made it invisible to the management API's listing and removed the Restore
 * button from the node Backups tab, while every surface kept reporting success.
 * A shared list only helps if it stays correct, so the ordering trap that caused
 * the worst of it is asserted directly.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupNaming.php'));

// ── Classification ──────────────────────────────────────────────────────────
section('What each artifact is');

$cases = array(
	// name                              is_backup  encrypted  restore type
	array('site-2026-08-02.tar.gz.enc',   true,      true,      'project'),
	array('site-2026-08-02.tar.gz',       true,      false,     'project'),
	array('db-2026-08-02.sql.gz.enc',     true,      true,      'database'),
	array('db-2026-08-02.sql.gz',         true,      false,     'database'),
	array('auto_pre_restore_2026.sql.gz', true,      false,     'database'),
	array('notes.txt',                    false,     false,     ''),
	array('site.tar',                     false,     false,     ''),
	array('site.tar.gz.enc.keys.json',    false,     false,     ''),
);

foreach ($cases as $c) {
	list($name, $is_backup, $encrypted, $type) = $c;
	check(BackupNaming::is_backup($name) === $is_backup,
		"is_backup({$name})", var_export(BackupNaming::is_backup($name), true));
	check(BackupNaming::is_encrypted($name) === $encrypted,
		"is_encrypted({$name})", var_export(BackupNaming::is_encrypted($name), true));
	check(BackupNaming::restore_type($name) === $type,
		"restore_type({$name}) === '{$type}'", BackupNaming::restore_type($name));
}

// The ordering trap: '.sql.gz' is a suffix of '.sql.gz.enc', so a shortest-first
// match classifies every encrypted dump as plaintext and hands the restore
// engine an encrypted file it will not decrypt.
section('Longest suffix wins');

check(BackupNaming::extension_of('db.sql.gz.enc') === '.sql.gz.enc',
	'an encrypted dump matches the encrypted suffix, not the plaintext one',
	BackupNaming::extension_of('db.sql.gz.enc'));
check(BackupNaming::extension_of('site.tar.gz.enc') === '.tar.gz.enc',
	'an encrypted archive matches the encrypted suffix',
	BackupNaming::extension_of('site.tar.gz.enc'));

$exts = BackupNaming::EXTENSIONS;
for ($i = 0; $i < count($exts); $i++) {
	for ($j = $i + 1; $j < count($exts); $j++) {
		// If a LATER entry is a suffix of an EARLIER one that is fine (longest
		// first). The failure mode is the reverse.
		check(substr($exts[$i], -strlen($exts[$j])) !== $exts[$j] || strlen($exts[$i]) > strlen($exts[$j]),
			"'{$exts[$j]}' does not shadow '{$exts[$i]}'");
	}
}

// ── Every shape the platform can produce is listed ──────────────────────────
section('Nothing the engines write is invisible');

// These are exactly the artifact names backup_database.sh and backup_project.sh
// produce. A shape missing here is a backup nobody can see.
foreach (array('x.sql.gz', 'x.sql.gz.enc', 'x.tar.gz', 'x.tar.gz.enc') as $produced) {
	check(BackupNaming::is_backup($produced), "the engines' {$produced} is recognized");
	check(BackupNaming::restore_type($produced) !== '', "{$produced} can be restored");
}

$patterns = BackupNaming::glob_patterns('/backups');
check(count($patterns) === count(BackupNaming::EXTENSIONS),
	'one glob per recognized shape', count($patterns) . ' patterns');
check(in_array('/backups/*.tar.gz.enc', $patterns, true),
	'the encrypted project archive is globbed');

$shell = BackupNaming::shell_glob('/backups');
check(strpos($shell, '/backups/*.tar.gz.enc') !== false && strpos($shell, '/backups/*.sql.gz') !== false,
	'the shell glob covers every shape', $shell);

// ── Sidecars ────────────────────────────────────────────────────────────────
section('Envelopes are not artifacts');

check(BackupNaming::is_sidecar('site.tar.gz.enc.keys.json'), 'an envelope is recognized');
check(!BackupNaming::is_sidecar('site.tar.gz.enc'), 'an archive is not an envelope');
check(!BackupNaming::is_backup('site.tar.gz.enc.keys.json'),
	'an envelope is never listed as a backup in its own right');
check(BackupNaming::artifact_for_sidecar('site.tar.gz.enc.keys.json') === 'site.tar.gz.enc',
	'an envelope names the artifact it belongs to',
	BackupNaming::artifact_for_sidecar('site.tar.gz.enc.keys.json'));
check(BackupNaming::artifact_for_sidecar('site.tar.gz.enc') === '',
	'asking a non-envelope which artifact it describes yields nothing');

// An envelope must not be swept up by the globs either, or it would be uploaded
// and listed as though it were a restore point.
foreach (BackupNaming::glob_patterns('/backups') as $p) {
	check(fnmatch($p, '/backups/site.tar.gz.enc.keys.json') === false,
		"envelope not matched by {$p}");
}

harness_finish();
