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

/** Read a settings row exactly as stored (null when there is no row at all). */
function bke_get_setting($name) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$q->execute([$name]);
	$v = $q->fetchColumn();
	return $v === false ? null : (string)$v;
}

function bke_set_setting($name, $value) {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare(
		"INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
		 VALUES (?, ?, 1, NOW(), NOW(), 'server_manager')
		 ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value, stg_update_time = NOW()");
	$q->execute([$name, $value]);
}

/** Put a setting back as found — including "there was no row". */
function bke_restore_setting($name, $value) {
	if ($value === null) {
		$q = DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM stg_settings WHERE stg_name = ?');
		$q->execute([$name]);
		return;
	}
	bke_set_setting($name, $value);
}

/** Minimal FormWriter double: emits just enough markup to locate the form. */
class BkeStubFormWriter {
	public function begin_form() { echo '<form>'; }
	public function end_form() { echo '</form>'; }
	public function hiddeninput($name, $label = '', $options = []) { echo '<input type="hidden" name="' . $name . '">'; }
	public function textinput($name, $label = '', $options = []) {
		echo '<input name="' . $name . '" placeholder="' . htmlspecialchars($options['placeholder'] ?? '') . '">';
		if (!empty($options['help'])) { echo '<small>' . htmlspecialchars($options['help']) . '</small>'; }
	}
	public function submitbutton($name = 'btn', $label = 'Submit', $options = []) {
		echo '<button type="submit">' . htmlspecialchars($label) . '</button>';
	}
}

/** Minimal page double: the walkthrough only needs box chrome and a FormWriter. */
class BkeStubPage {
	public function begin_box($options = []) { echo '<div class="box">'; }
	public function end_box() { echo '</div>'; }
	public function getFormWriter($name) { return new BkeStubFormWriter(); }
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
	$proof_sentence = BackupKeyCustody::expected_proof_string();
	check(strpos($proof_sentence, hash('sha256', $raw)) !== false,
		'the proof string is bound to the proven key fingerprint');
	check(strpos($proof_sentence, 'Your recovery key opened this message') === 0,
		'the proof string reads as a sentence, not as another blob of ciphertext');
	check($proof_sentence === trim($proof_sentence) && preg_match('/^[\x20-\x7e]+$/', $proof_sentence) === 1,
		'the proof string is plain ASCII with no stray whitespace (it survives a copy-paste through a terminal)');
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

// --------------------------------------------------------------------------
section('Setup state machine (what the walkthrough, the node tab and the dashboard all read)');

// Driven through the pure classifier, never by writing the live settings: a
// throwaway key made to look verified — even for a moment — would let a backup
// job running concurrently seal a real node key to a key nobody keeps.
$test_pub_b64 = base64_encode($pub);
$true_proof   = hash('sha256', $pub);

$k = BackupKeyCustody::classify_key('', '');
check($k['state'] === 'unconfigured', 'no recovery key set -> unconfigured (step 1)');
check($k['error'] !== '', 'the unconfigured state carries the reason to show');

$k = BackupKeyCustody::classify_key('this is not a key', '');
check($k['state'] === 'invalid', 'an unusable value -> invalid (step 1, with the parse error)');
check($k['error'] !== '', 'the invalid state says what is wrong with the value');

// Half a key is the realistic paste error: valid base64, wrong length.
check(BackupKeyCustody::classify_key(base64_encode(random_bytes(16)), '')['state'] === 'invalid',
	'a short but well-formed base64 value is still invalid (length is checked)');

$k = BackupKeyCustody::classify_key($test_pub_b64, '');
check($k['state'] === 'unproven', 'a key that is set but unproven -> unproven (step 2)');
check($k['fingerprint'] !== '', 'the unproven state still exposes the key fingerprint');

check(BackupKeyCustody::classify_key($test_pub_b64, hash('sha256', 'some other key'))['state'] === 'unproven',
	'a proof recorded for a different key does not carry over to this one');
check(BackupKeyCustody::classify_key($test_pub_b64, $true_proof)['state'] === 'proven',
	'the matching proof marker is what makes a key proven');
check(BackupKeyCustody::classify_key(' ' . $test_pub_b64 . " \n", $true_proof)['state'] === 'proven',
	'a pasted key with stray whitespace still resolves');

// Live state: read-only, whatever this control plane is currently in. Setup is
// about the recovery key alone — it never waits on individual nodes.
$st = BackupKeyCustody::setup_state();
check(in_array($st['state'], ['unconfigured', 'invalid', 'unproven', 'ready'], true),
	'setup_state reports one of the four known states');
check($st['is_ready'] === ($st['state'] === 'ready'), 'is_ready means exactly the ready state');
check(!isset($st['pending_nodes']),
	'setup readiness does not depend on any node having sealed its key yet');

// The node survey is separate, and only reports — the dashboard reads it.
$survey = BackupKeyCustody::survey_nodes();
check($survey['escrowed'] + count($survey['pending']) === $survey['targeted'],
	'every node with a cloud target is counted either escrowed or pending');
foreach ($survey['pending'] as $p) {
	check(isset($p['id'], $p['name'], $p['slug'], $p['reason'])
		&& in_array($p['reason'], ['never_escrowed', 'regenerated'], true),
		'pending node ' . $p['id'] . ' is renderable (id, name, slug, known reason)');
}

// --------------------------------------------------------------------------
// The write-path checks below do touch the live settings, so the values are
// captured here and restored at the end whatever the checks find. They only
// ever leave escrow unconfigured or unproven — states in which sealing refuses
// outright — so a concurrent job fails closed rather than sealing to a key
// this test is about to throw away.
$live_pub_setting   = bke_get_setting(BackupKeyCustody::PUBLIC_KEY_SETTING);
$live_proof_setting = bke_get_setting(BackupKeyCustody::PROOF_SETTING);

try {

	bke_set_setting(BackupKeyCustody::PUBLIC_KEY_SETTING, $test_pub_b64);
	bke_set_setting(BackupKeyCustody::PROOF_SETTING, '');

	// ----------------------------------------------------------------------
	section('Setting write path: parse before write, and no silent rotation');

	$threw = false;
	try { BackupKeyCustody::set_escrow_public_key(''); }
	catch (BackupKeyCustodyException $e) { $threw = true; }
	check($threw, 'an empty paste is refused');
	check(bke_get_setting(BackupKeyCustody::PUBLIC_KEY_SETTING) === $test_pub_b64,
		'a refused paste writes nothing');

	$threw = false;
	try { BackupKeyCustody::set_escrow_public_key('not base64 at all !!'); }
	catch (BackupKeyCustodyException $e) { $threw = true; }
	check($threw, 'a value that is not a key is refused');

	$threw = false;
	try { BackupKeyCustody::set_escrow_public_key(base64_encode(random_bytes(16))); }
	catch (BackupKeyCustodyException $e) { $threw = true; }
	check($threw, 'a well-formed base64 value of the wrong length is refused');
	check(bke_get_setting(BackupKeyCustody::PUBLIC_KEY_SETTING) === $test_pub_b64,
		'none of the refusals disturbed the stored key');

	// Re-pasting the same key must not disturb the proof marker either way.
	bke_set_setting(BackupKeyCustody::PROOF_SETTING, 'sentinel-marker-value');
	BackupKeyCustody::set_escrow_public_key($test_pub_b64);
	check(bke_get_setting(BackupKeyCustody::PROOF_SETTING) === 'sentinel-marker-value',
		're-pasting the same key leaves the possession proof alone');

	// Whether a swap is a rotation is decided by this pure rule, exercised here
	// rather than by making a throwaway key look verified on a live control plane.
	$other_pub = sodium_crypto_box_publickey(sodium_crypto_box_keypair());
	check(BackupKeyCustody::key_in_use($pub, hash('sha256', $pub), 1) === true,
		'a proven key with sealed blobs counts as in use (swapping it is a rotation)');
	check(BackupKeyCustody::key_in_use($pub, hash('sha256', $pub), 0) === false,
		'a proven key with nothing sealed to it yet is still free to replace');
	check(BackupKeyCustody::key_in_use($pub, '', 5) === false,
		'an unproven key is never in use, however long it has been sitting there');
	check(BackupKeyCustody::key_in_use($pub, hash('sha256', $other_pub), 5) === false,
		'a proof belonging to another key does not put this one in use');

	// The unproven key in the setting is therefore free to swap and to discard.
	bke_set_setting(BackupKeyCustody::PROOF_SETTING, '');
	BackupKeyCustody::set_escrow_public_key(base64_encode($other_pub));
	check(bke_get_setting(BackupKeyCustody::PUBLIC_KEY_SETTING) === base64_encode($other_pub),
		'an unproven key can be replaced outright');
	check(BackupKeyCustody::needs_possession_proof(),
		'saving a key clears any earlier proof — the new value must prove itself');

	// ----------------------------------------------------------------------
	section('Browser challenge (what the in-page paste box opens)');

	// Put the test key back so the challenge is built for a keypair we hold.
	bke_set_setting(BackupKeyCustody::PUBLIC_KEY_SETTING, $test_pub_b64);

	$browser_blob = BackupKeyCustody::browser_challenge();
	$raw = base64_decode($browser_blob, true);
	check(is_string($raw) && strlen($raw) > 32 + 12 + 16,
		'the browser challenge is a base64 blob of ephemeral key + iv + ciphertext + tag');

	// Open it exactly as the JS does: X25519 -> HKDF-SHA256 -> AES-256-GCM.
	$eph_pub = substr($raw, 0, 32);
	$iv      = substr($raw, 32, 12);
	$body    = substr($raw, 44);
	$ct      = substr($body, 0, -16);
	$tag     = substr($body, -16);
	$shared  = sodium_crypto_scalarmult($priv, $eph_pub);
	$aes_key = hash_hkdf('sha256', $shared, 32,
		BackupKeyCustody::BROWSER_INFO . $eph_pub . $pub, '');
	$opened  = openssl_decrypt($ct, 'aes-256-gcm', $aes_key, OPENSSL_RAW_DATA, $iv, $tag);
	check($opened === BackupKeyCustody::expected_proof_string(),
		'the recovery private key opens the browser challenge to the same proof string');

	// A different key must not open it — that is the whole check.
	$wrong_priv = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());
	$wrong_key  = hash_hkdf('sha256', sodium_crypto_scalarmult($wrong_priv, $eph_pub), 32,
		BackupKeyCustody::BROWSER_INFO . $eph_pub . $pub, '');
	check(openssl_decrypt($ct, 'aes-256-gcm', $wrong_key, OPENSSL_RAW_DATA, $iv, $tag) === false,
		'a different recovery key cannot open the browser challenge');

	check(BackupKeyCustody::browser_challenge() !== $browser_blob,
		'each challenge is freshly sealed (a replayed blob is never the same bytes)');

	BackupKeyCustody::clear_escrow_public_key();
	check(BackupKeyCustody::setup_state()['state'] === 'unconfigured',
		'an unproven key can be discarded and setup returns to step 1');

	// ----------------------------------------------------------------------
	section('Walkthrough panel');

	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupKeyWalkthrough.php'));

	$ready_state = [
		'state' => 'ready', 'error' => '', 'fingerprint' => 'abc123def4567890',
		'agent_signing' => 'pending', 'is_ready' => true,
	];
	ob_start();
	BackupKeyWalkthrough::render(new BkeStubPage(), $ready_state, '/admin/server_manager/targets');
	$html = ob_get_clean();
	check(strpos($html, 'Backup key recovery is set up') !== false,
		'the finished panel states plainly that recovery is set up');
	check(strpos($html, 'abc123def4567890') === false,
		'the finished panel does not restate the fingerprint (it was just proven against the kept copy)');
	check(strpos($html, 'escrow_keypair.php') === false,
		'the finished panel carries no recovery instructions — recovery is handled when it is needed');
	check(strpos($html, 'Agent signing key') === false,
		'the finished panel carries no signing-key plumbing (it seals itself at the next publish)');
	check(strpos($html, 'own Backups tab') !== false,
		'the finished panel points at the node Backups tab, where a node key is actually sealed');
	check(strpos($html, 'escrow_pending_nodes') === false,
		'setup offers no fleet-wide sealing action — that belongs to each node');

	// Step 2's markup, rendered against the test key still installed above.
	// The paste box holds the operator's PRIVATE key: it must sit outside every
	// form on the page, so there is no field for it to be submitted in.
	$unproven_state = [
		'state' => 'unproven', 'error' => '', 'fingerprint' => substr(hash('sha256', $pub), 0, 16),
		'agent_signing' => 'none', 'is_ready' => false,
	];
	bke_set_setting(BackupKeyCustody::PUBLIC_KEY_SETTING, $test_pub_b64); // step 2 seals a challenge to it
	ob_start();
	BackupKeyWalkthrough::render(new BkeStubPage(), $unproven_state, '/admin/server_manager/targets');
	$html = ob_get_clean();

	$key_at  = strpos($html, 'id="sm-escrow-privkey"');
	$form_at = strpos($html, '<form');
	check($key_at !== false && $form_at !== false && $key_at < $form_at,
		'the private-key paste box is rendered before any form opens (it can never be submitted)');
	check(strpos($html, 'name="escrow_proof"') > $form_at,
		'the proof field — the only thing that is submitted — is inside the form');
	check(strpos($html, 'Your recovery key opened this message') !== false,
		'the result box shows what a successful result reads like');
	check(strpos($html, 'not a code') !== false,
		'the result box says to expect a sentence rather than a code');
	check(strpos($html, 'nothing is sent to the server') !== false,
		'the paste box states that the key stays in the browser');

	check(strpos(BackupKeyWalkthrough::outstanding_summary(['state' => 'unconfigured']),
		'not been set up') !== false,
		'the one-line summary other pages borrow describes the unconfigured state');

} finally {
	bke_restore_setting(BackupKeyCustody::PUBLIC_KEY_SETTING, $live_pub_setting);
	bke_restore_setting(BackupKeyCustody::PROOF_SETTING, $live_proof_setting);
}

check(bke_get_setting(BackupKeyCustody::PUBLIC_KEY_SETTING) === $live_pub_setting,
	'the live recovery key setting is left exactly as it was found');
check(bke_get_setting(BackupKeyCustody::PROOF_SETTING) === $live_proof_setting,
	'the live possession proof is left exactly as it was found');

// ─────────────────────────────────────────────────────────────────────────────
section('Escrow runs on the agent side, and no key rides in a job');

// Node SSH keys are operator-owned (mode 600) and unreadable by the web-server
// user, so escrow has to happen where the agent runs — as a control-plane step,
// not inside the web request. What must never follow it there is the key itself:
// job command and output rows are kept forever.
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));

$bke_stub_node = new class { public $key = 4242; };
$escrow_step   = JobCommandBuilder::step_escrow_backup_key($bke_stub_node);
$escrow_script = PathHelper::getIncludePath('plugins/server_manager/includes/escrow_node_key.php');

check(($escrow_step['type'] ?? '') === 'local',
	'the escrow step runs on the control plane, where the node SSH keys are readable');
check(strpos($escrow_step['cmd'], '--node=4242') !== false,
	'the step names the node to escrow');
check(strpos($escrow_step['cmd'], 'escrow_node_key.php') !== false,
	'the step invokes the escrow script rather than carrying key handling inline');
check(strpos($escrow_step['cmd'], $escrow_script) !== false,
	'the script path is absolute (the agent runs steps from its own working directory)');

$bke_steps = JobCommandBuilder::build_escrow_backup_key($bke_stub_node);
check(count($bke_steps) === 1 && $bke_steps[0] === $escrow_step,
	'the standalone escrow job is exactly that one step');

check(is_file($escrow_script), 'the escrow script the step invokes exists');
$bke_script_src = (string)file_get_contents($escrow_script);
check(strpos($bke_script_src, "php_sapi_name() !== 'cli'") !== false,
	'the escrow script refuses web access (it is an agent-side tool)');
check(preg_match('/echo\s+.*\$key\b/', $bke_script_src) !== 1,
	'the escrow script never prints the key — only its fingerprint');
check(strpos($bke_script_src, 'BACKUP_KEY_FPR=') !== false,
	'the escrow script reports the fingerprint, which is what the node row records');

harness_finish();
