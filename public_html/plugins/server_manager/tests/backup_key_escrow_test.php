<?php
/** @joinery-test
 * name: backup_key_escrow
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * BackupKeyEscrow — sealed-box custody of node backup keys.
 *
 * The security property under test: the control plane seals a key to a recovery
 * PUBLIC key and can never open it; only the offline private key can. And the
 * durability property: rotation appends rows (old archives stay recoverable),
 * every row's blob unseals, and a backup-kind row cannot exist without a node
 * while an agent-signing row cannot carry one.
 *
 * The sealing uses a throwaway keypair generated in-test (not the live escrow
 * setting), so it verifies the exact sodium primitives BackupKeyCustody relies
 * on without touching platform configuration.
 *
 * Run: php plugins/server_manager/tests/backup_key_escrow_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/backup_key_escrow_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupKeyCustody.php'));

// A throwaway recovery keypair — the control plane would hold only the public.
$kp   = sodium_crypto_box_keypair();
$priv = sodium_crypto_box_secretkey($kp);
$pub  = sodium_crypto_box_publickey($kp);

/** Seal a key string to $pub exactly as BackupKeyCustody::seal would. */
function bke_seal($key, $pub) { return base64_encode(sodium_crypto_box_seal($key, $pub)); }
/** Unseal a base64 blob with the full keypair. */
function bke_unseal($blob_b64, $kp) {
	$out = sodium_crypto_box_seal_open(base64_decode($blob_b64, true), $kp);
	return $out === false ? null : $out;
}

// A saved node so the FK is satisfied.
function bke_node() {
	$node = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$node->set('mgn_name', 'Escrow Test Node ' . $suffix);
	$node->set('mgn_slug', 'escrowtest-' . $suffix);
	$node->set('mgn_host', '192.0.2.20');
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_ssh_key_path', '/home/user1/.ssh/id_ed25519_claude');
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_id', $node->key);
	return $node;
}

// --------------------------------------------------------------------------
section('Sealed-box round-trip (the crypto BackupKeyCustody relies on)');

$key   = base64_encode(random_bytes(32));
$blob  = bke_seal($key, $pub);
$back  = bke_unseal($blob, $kp);
check($back === $key, 'a key sealed to the public key unseals to itself with the keypair');
check($blob !== $key && strlen($blob) > strlen($key),
	'the sealed blob is not the plaintext key');

$other_kp = sodium_crypto_box_keypair();
check(bke_unseal($blob, $other_kp) === null,
	'a different keypair cannot open the blob');

check(BackupKeyCustody::fingerprint($key) === hash('sha256', $key),
	'fingerprint is the sha256 hex of the raw key (matches sha256sum on the node)');

// --------------------------------------------------------------------------
section('Row validation invariants');

$node = bke_node();

// Backup kind requires a node.
$bad = new BackupKeyEscrow(NULL);
$bad->set('bke_key_fingerprint', hash('sha256', 'x'));
$bad->set('bke_sealed_blob', bke_seal('x', $pub));
$bad->set('bke_kind', 'backup');
$threw = false;
try { $bad->save(); } catch (BackupKeyEscrowException $e) { $threw = true; }
check($threw, 'a backup-kind row without a node is rejected');

// Agent-signing must NOT carry a node.
$bad2 = new BackupKeyEscrow(NULL);
$bad2->set('bke_mgn_node_id', $node->key);
$bad2->set('bke_key_fingerprint', hash('sha256', 'y'));
$bad2->set('bke_sealed_blob', bke_seal('y', $pub));
$bad2->set('bke_kind', 'agent_signing');
$threw = false;
try { $bad2->save(); } catch (BackupKeyEscrowException $e) { $threw = true; }
check($threw, 'an agent-signing row that references a node is rejected');

// A malformed fingerprint is rejected.
$bad3 = new BackupKeyEscrow(NULL);
$bad3->set('bke_mgn_node_id', $node->key);
$bad3->set('bke_key_fingerprint', 'not-a-sha256');
$bad3->set('bke_sealed_blob', bke_seal('z', $pub));
$threw = false;
try { $bad3->save(); } catch (BackupKeyEscrowException $e) { $threw = true; }
check($threw, 'a non-sha256 fingerprint is rejected');

// An empty blob is rejected.
$bad4 = new BackupKeyEscrow(NULL);
$bad4->set('bke_mgn_node_id', $node->key);
$bad4->set('bke_key_fingerprint', hash('sha256', 'w'));
$bad4->set('bke_sealed_blob', '');
$threw = false;
try { $bad4->save(); } catch (BackupKeyEscrowException $e) { $threw = true; }
check($threw, 'an empty sealed blob is rejected');

// --------------------------------------------------------------------------
section('Append-only rotation: newest wins, every blob still opens');

$k1 = base64_encode(random_bytes(32));
$r1 = new BackupKeyEscrow(NULL);
$r1->set('bke_mgn_node_id', $node->key);
$r1->set('bke_key_fingerprint', hash('sha256', $k1));
$r1->set('bke_sealed_blob', bke_seal($k1, $pub));
$r1->set('bke_kind', 'backup');
$r1->set('bke_source', 'generated');
$r1->save();
harness_register_row('bke_backup_key_escrow', 'bke_escrow_id', $r1->key);

$k2 = base64_encode(random_bytes(32));
$r2 = new BackupKeyEscrow(NULL);
$r2->set('bke_mgn_node_id', $node->key);
$r2->set('bke_key_fingerprint', hash('sha256', $k2));
$r2->set('bke_sealed_blob', bke_seal($k2, $pub));
$r2->set('bke_kind', 'backup');
$r2->set('bke_source', 'rotated');
$r2->save();
harness_register_row('bke_backup_key_escrow', 'bke_escrow_id', $r2->key);

$newest = MultiBackupKeyEscrow::newest_for_node($node->key);
check($newest !== null && (int)$newest->key === (int)$r2->key,
	'newest_for_node returns the most recent (rotated) row');

check(bke_unseal($r1->get('bke_sealed_blob'), $kp) === $k1,
	'the PREVIOUS key still unseals (old archives stay recoverable)');
check(bke_unseal($r2->get('bke_sealed_blob'), $kp) === $k2,
	'the ROTATED key unseals');

$all = new MultiBackupKeyEscrow(['node_id' => $node->key, 'kind' => 'backup']);
$all->load();
check(count($all) === 2, 'both rotation rows are retained (append-only)');

// "Is this key escrowed?" must search every row: a node restored to an OLDER
// escrowed key is still recoverable and must not be flagged regenerated.
$match_old = MultiBackupKeyEscrow::matching_for_node($node->key, hash('sha256', $k1));
check($match_old !== null && (int)$match_old->key === (int)$r1->key,
	'matching_for_node finds the older escrowed key (restored node is not a false alarm)');
$match_new = MultiBackupKeyEscrow::matching_for_node($node->key, hash('sha256', $k2));
check($match_new !== null && (int)$match_new->key === (int)$r2->key,
	'matching_for_node finds the newest key too');
check(MultiBackupKeyEscrow::matching_for_node($node->key, hash('sha256', 'not-a-real-key')) === null,
	'an unescrowed fingerprint matches nothing (true out-of-band regeneration is still caught)');

// --------------------------------------------------------------------------
section('Possession proof: an unproven recovery key is never honored');

// State-aware: exercises whichever state this control plane is in, and the
// failure path never mutates it.
$pp_configured = true;
try { BackupKeyCustody::parse_public_key(); } catch (BackupKeyCustodyException $e) { $pp_configured = false; }

if (!$pp_configured) {
	section('possession-proof checks limited: no recovery key configured on this site');
} elseif (BackupKeyCustody::needs_possession_proof()) {
	$threw = false;
	try { BackupKeyCustody::escrow_public_key(); }
	catch (BackupKeyCustodyException $e) { $threw = true; }
	check($threw, 'a configured-but-unproven key is refused by escrow_public_key');

	$challenge = BackupKeyCustody::possession_challenge();
	check(is_string($challenge) && base64_decode($challenge, true) !== false && strlen($challenge) > 40,
		'a sealed challenge blob is issued');

	$threw = false;
	try { BackupKeyCustody::record_possession_proof('definitely not the unsealed challenge'); }
	catch (BackupKeyCustodyException $e) { $threw = true; }
	check($threw, 'a wrong proof is rejected');
	check(BackupKeyCustody::needs_possession_proof(),
		'a rejected proof leaves the key unproven (nothing was persisted)');
} else {
	$raw = BackupKeyCustody::escrow_public_key();
	check(strlen($raw) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES,
		'a proven recovery key is honored by escrow_public_key');
	// The proof string is bound to THIS key's fingerprint — a proof for a
	// different key could never have produced it.
	check(BackupKeyCustody::expected_proof_string() === 'sm-escrow-proof:' . hash('sha256', $raw),
		'the proof string is bound to the proven key fingerprint');
}

// --------------------------------------------------------------------------
section('Refusal when escrow is unconfigured');

// escrow_public_key must either return a 32-byte key (configured) or throw a
// typed exception (unconfigured) — never silently succeed with nothing.
if (BackupKeyCustody::is_escrow_configured()) {
	$raw = BackupKeyCustody::escrow_public_key();
	check(strlen($raw) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES,
		'a configured escrow public key decodes to a valid box public key');
} else {
	$threw = false;
	try { BackupKeyCustody::escrow_public_key(); }
	catch (BackupKeyCustodyException $e) { $threw = true; }
	check($threw, 'with escrow unconfigured, sealing refuses loudly (no node-only fallback)');
}

harness_finish();
