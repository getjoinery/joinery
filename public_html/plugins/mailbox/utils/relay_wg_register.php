#!/usr/bin/php
<?php
/**
 * CLI: record the main box's WireGuard PUBLIC key in settings
 * (mailbox_relay_wg_public_key) so the relay admin page and the provision_relay
 * job can peer the tunnel. Called by provisioning/provision_relay_main.sh.
 * Public material only — the private key never leaves /etc/wireguard.
 *
 * Usage: php relay_wg_register.php <wireguard-public-key>
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(1);
}

// Bootstrap Joinery (outside normal web request). DbConnector is loaded
// explicitly — Globalvars only pulls it in lazily via get_setting(), which
// this script never calls.
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

$pubkey = trim((string)($argv[1] ?? ''));
// A WireGuard public key is 32 bytes base64 — 43 chars + '='.
if (!preg_match('#^[A-Za-z0-9+/]{43}=$#', $pubkey)) {
	fwrite(STDERR, "Usage: relay_wg_register.php <wireguard-public-key>\n");
	exit(1);
}

$db = DbConnector::get_instance()->get_db_link();
$stmt = $db->prepare(
	"INSERT INTO stg_settings (stg_name, stg_value, stg_group_name)
	 VALUES (?, ?, 'mailbox')
	 ON CONFLICT (stg_name)
	 DO UPDATE SET stg_value = EXCLUDED.stg_value, stg_update_time = now()"
);
$stmt->execute(array('mailbox_relay_wg_public_key', $pubkey));

echo "mailbox_relay_wg_public_key registered.\n";
exit(0);
?>
