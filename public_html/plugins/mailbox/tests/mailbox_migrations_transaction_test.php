<?php
/** @joinery-test
 * name: mailbox_migrations_transaction
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A plugin's migrations run inside the transaction the plugin installer holds
 * (PluginManager::install opens one and commits it after the migrations and the
 * foreign-key sync). A migration that opens its own with a bare beginTransaction()
 * throws 'There is already an active transaction', and the whole plugin lands in
 * an error state on every fresh install, though it passes on a site that already
 * has the data. So a migration may open a transaction only after asking whether
 * one is already open, and may commit or roll back only one it opened.
 *
 * Mailbox's ilb_001 did this (0.8.485). Every migration file, core's and each
 * plugin's, is read here, so the next one is caught before it ships.
 *
 * Run:  php plugins/mailbox/tests/mailbox_migrations_transaction_test.php
 *
 * @version 1.1 - every migration file, core and plugins, not only mailbox's
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('A migration joins the installer\'s transaction');

$root = dirname(PathHelper::getIncludePath('VERSION'));
$files = array_merge(glob($root . '/migrations/*.php') ?: array(), glob($root . '/plugins/*/migrations/*.php') ?: array());
check(in_array($root . '/plugins/mailbox/migrations/migrations.php', $files, true), 'mailbox\'s migrations are among the files read',
	count($files) . ' files');

$total = 0;
foreach ($files as $file) {
	$lines = preg_split('/\R/', (string)file_get_contents($file));
	foreach ($lines as $i => $line) {
		if (strpos($line, '->beginTransaction()') === false) { continue; }
		$total++;
		// The begin must sit inside an if on a flag, and that flag must be set
		// from inTransaction() in the lines just above.
		$window = implode("\n", array_slice($lines, max(0, $i - 8), 9));
		$guarded = preg_match('/\$(\w+)\s*=\s*!\s*\$\w+->inTransaction\(\)/', $window, $m) === 1
			&& preg_match('/if\s*\(\s*\$' . preg_quote($m[1], '/') . '\s*\)\s*\{\s*$/m', $window) === 1;
		check($guarded, substr($file, strlen($root) + 1) . ':' . ($i + 1) . ': beginTransaction() runs only when no transaction is open');
	}
}
check($total >= 1, 'at least one migration opens a transaction, so the check above means something', (string)$total);

harness_finish();
