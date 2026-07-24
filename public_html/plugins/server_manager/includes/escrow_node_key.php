<?php
/**
 * escrow_node_key.php — seal one node's backup key to the recovery key.
 *
 * Run on the control plane, by the management agent, as one step of an
 * escrow_backup_key (or encrypting backup) job:
 *
 *     php escrow_node_key.php --node=<node id>
 *
 * Why this is a script and not simply done in the web request that asks for it:
 * escrowing means reading the key off the node over SSH, and a node's SSH key
 * belongs to the operator account at mode 600 — the web-server user cannot read
 * it. Every other node operation already reaches the node through the agent,
 * which owns those keys; this puts key escrow on the same footing instead of
 * being the one operation that needs the web user to hold SSH access.
 *
 * Key material still never enters a job row (the invariant BackupKeyCustody
 * documents): the key is read, sealed, and dropped inside this process, and the
 * only thing printed is its fingerprint.
 *
 * Output markers:
 *   BACKUP_KEY_FPR=<sha256 of the key value>
 *   BACKUP_KEY_ESCROWED=1
 *
 * Exit 0 on success (including "already escrowed" — this is idempotent), 1 with
 * the reason on stderr otherwise.
 *
 * @version 1.0
 */

// Reject non-CLI access: this runs as the agent user and needs no web surface.
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupKeyCustody.php'));

// $_SERVER['argv'] rather than $argv: the latter is a plain global, absent when
// this file is read from inside a function scope (the PHP file validator does).
$node_id = 0;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
	if (preg_match('/^--node=(\d+)$/', $arg, $m)) {
		$node_id = (int)$m[1];
	} elseif (ctype_digit($arg)) {
		$node_id = (int)$arg;
	}
}

if ($node_id <= 0) {
	fwrite(STDERR, "Usage: php escrow_node_key.php --node=<node id>\n");
	exit(1);
}

// A missing row does not throw — SystemBase reports it and hands back an empty
// object — so the load is confirmed by what came back, not by the absence of an
// exception. Escrowing "node 0" would otherwise fail later with a confusing
// message about a node having no host configured.
try {
	$node = new ManagedNode($node_id, TRUE);
} catch (Exception $e) {
	$node = null;
}
if (!$node || (int)$node->get('mgn_id') !== $node_id) {
	fwrite(STDERR, "No managed node with id {$node_id}.\n");
	exit(1);
}

try {
	// Mints the key if the node has none (sealing and saving the escrow row
	// BEFORE the key is pushed), escrows an existing un-escrowed key, no-ops
	// when it is already escrowed.
	$fingerprint = BackupKeyCustody::fingerprint(BackupKeyCustody::ensureNodeKey($node));
} catch (Throwable $e) {
	fwrite(STDERR, 'Backup key escrow failed: ' . $e->getMessage() . "\n");
	exit(1);
}

// Stamp the key the node is running, so the Backups tab and the dashboard agree
// immediately instead of waiting for the next backup to report the fingerprint.
if ((string)$node->get('mgn_backup_key_fingerprint') !== $fingerprint) {
	$node->set('mgn_backup_key_fingerprint', $fingerprint);
	$node->save();
}

echo 'Backup key sealed to the recovery key for node ' . $node_id
	. ' (' . $node->get('mgn_name') . ").\n";
echo "BACKUP_KEY_FPR={$fingerprint}\n";
echo "BACKUP_KEY_ESCROWED=1\n";
exit(0);
