<?php
/** @joinery-test
 * name: seal_on_save
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 *
 * Sealing on save: a consumer declares $sealed_fields and writes with ordinary
 * set()/save(), and core does the crypto.
 *
 * Two properties carry the weight. First, plaintext must never reach the
 * database — save() builds its columns through get(), which decrypts, so the
 * sealing path has to lift the sealed columns out before the statement is
 * built. Second, an update must REUSE the row's existing key: minting a fresh
 * one rewrites the wrapping and orphans every sealed column the update did not
 * rewrite, which is the trap consumers used to sidestep by threading the old
 * key through by hand.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
if (!vault_apcu_usable() || !vault_ensure_session()) {
	harness_skip('APCu/session unavailable, so no unlock window can be held in CLI');
	harness_finish();
}

/**
 * A consumer that has NOT written its own sealing path: same columns and the
 * same AD as MailboxContact, but core seals it. This is the shape a new plugin
 * gets by declaring $sealed_fields and nothing else.
 */
class SealOnSaveProbe extends MailboxContact {
	public static $seal_on_save = true;
}

/** The same model with the opt-out the in-tree consumers use. */
class SealOnSaveOptOutProbe extends MailboxContact {
	public static $seal_on_save = false;
}

/** A consumer whose sealing is conditional — the per-row policy hook. */
class SealOnSavePolicyProbe extends MailboxContact {
	public static $seal_on_save = true;
	protected static function shouldSeal(array $row): bool {
		return (string)($row['imc_source'] ?? '') !== 'import';
	}
}

/** Read a Postgres boolean back however this PDO driver spells it. */
function sos_flag($value): bool {
	if (is_bool($value)) { return $value; }
	return in_array(strtolower((string)$value), array('t', 'true', '1', 'yes'), true);
}

/** The row exactly as the database holds it — no model, no decryption. */
function sos_raw(int $id): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT * FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}

function sos_new(string $class, int $user_id, string $address, string $name, string $source = 'manual') {
	$row = new $class(NULL);
	$row->set('imc_usr_user_id', $user_id);
	$row->set('imc_address', $address);
	$row->set('imc_display_name', $name);
	$row->set('imc_address_hash', hash('sha256', $address . '|' . $user_id . '|' . random_bytes(8)));
	$row->set('imc_source', $source);
	$row->save();
	harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$row->key);
	return $row;
}

// A vault whose secret this test holds, so it can open and close the window at will.
$owner = make_user('SealOnSave');
$owner_id = (int)$owner->key;
$keypair = sodium_crypto_box_keypair();
$secret = SealedBox::b64url(sodium_crypto_box_secretkey($keypair));
$vault = new UserEncryptionVault(NULL);
$vault->set('uev_usr_user_id', $owner_id);
$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($keypair)));
$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
$vault->save();
harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);

$open_window = function () use ($owner_id, $secret) {
	VaultUnlock::open($owner_id, $secret, UserEncryptionVault::SCOPE_USER,
		array('idle' => null, 'absolute' => null));
};
$open_window();

// ---------------------------------------------------------------------------
section('An insert seals, with no ceremony in the consumer');
// ---------------------------------------------------------------------------
$contact = sos_new('SealOnSaveProbe', $owner_id, 'alice@example.com', 'Alice Example');
$raw = sos_raw((int)$contact->key);

check(!empty($raw), 'the row was inserted');
check(strpos((string)$raw['imc_address'], 'v1.aead.') === 0, 'the address is stored as ciphertext');
check(strpos((string)$raw['imc_display_name'], 'v1.aead.') === 0, 'so is the display name');
check(strpos((string)$raw['imc_address'], 'alice@example.com') === false,
	'and no fragment of the plaintext survives in the column');
check(!empty($raw['imc_sealed_key']), 'the row carries a wrapped key');
check((int)$raw['imc_sealed_owner_user_id'] === $owner_id, 'sealed to the row owner, resolved from imc_usr_user_id');
check((int)$raw['imc_key_generation'] === (int)$vault->get('uev_key_generation'),
	"stamped with the owner's current key generation, so a rotation can find it");
check(sos_flag($raw['imc_content_sealed']), 'and the row is flagged sealed');

$reloaded = new SealOnSaveProbe((int)$contact->key, TRUE);
check($reloaded->get('imc_address') === 'alice@example.com', 'reading it back in-window returns the plaintext');
check($reloaded->get('imc_display_name') === 'Alice Example', 'for every sealed column');
check($contact->get('imc_address') === null,
	'and the instance that wrote it is not left holding cleartext once the row is ciphertext');

// ---------------------------------------------------------------------------
section('A partial update reuses the row DEK and leaves untouched columns readable');
// ---------------------------------------------------------------------------
$dek_before = (string)$raw['imc_sealed_key'];

$edit = new SealOnSaveProbe((int)$contact->key, TRUE);
$edit->set('imc_display_name', 'Alice Renamed');
$edit->save();

$raw_after = sos_raw((int)$contact->key);
check((string)$raw_after['imc_sealed_key'] === $dek_before,
	'the wrapping is untouched, so the row keeps one key across the update');

$after = new SealOnSaveProbe((int)$contact->key, TRUE);
check($after->get('imc_display_name') === 'Alice Renamed', 'the rewritten column reads back as the new value');
check($after->get('imc_address') === 'alice@example.com',
	'and the column the update never touched is still readable — the orphaning trap, closed');

// A non-sealed column moves on its own without disturbing anything sealed.
$bump = new SealOnSaveProbe((int)$contact->key, TRUE);
$bump->set('imc_use_count', 7);
$bump->save();
$after = new SealOnSaveProbe((int)$contact->key, TRUE);
check((int)$after->get('imc_use_count') === 7, 'an ordinary column saves normally on a sealed row');
check($after->get('imc_address') === 'alice@example.com', 'and the sealed columns survive it');

// ---------------------------------------------------------------------------
section('Create works with the vault locked; updating sealed content does not');
// ---------------------------------------------------------------------------
VaultUnlock::lockAll($owner_id);

$offline = sos_new('SealOnSaveProbe', $owner_id, 'ingest@example.com', 'Ingest');
$raw_offline = sos_raw((int)$offline->key);
check(strpos((string)$raw_offline['imc_address'], 'v1.aead.') === 0,
	'a brand-new row seals into a LOCKED vault — sealing needs only the public key, which is why ingest works offline');

$locked_edit = new SealOnSaveProbe((int)$contact->key, TRUE);
$locked_edit->set('imc_display_name', 'Nope');
$threw_locked = false;
try {
	$locked_edit->save();
} catch (VaultLockedException $e) {
	$threw_locked = true;
}
check($threw_locked,
	'updating a sealed column with the window closed throws VaultLockedException — reusing the DEK means unwrapping it');

$open_window();
$unchanged = new SealOnSaveProbe((int)$contact->key, TRUE);
check($unchanged->get('imc_display_name') === 'Alice Renamed', 'and the refused save changed nothing');

// ---------------------------------------------------------------------------
section('shouldSeal() decides per row');
// ---------------------------------------------------------------------------
$sealed_one = sos_new('SealOnSavePolicyProbe', $owner_id, 'keep@example.com', 'Keep', 'manual');
$plain_one  = sos_new('SealOnSavePolicyProbe', $owner_id, 'skip@example.com', 'Skip', 'import');

$raw_sealed = sos_raw((int)$sealed_one->key);
$raw_plain  = sos_raw((int)$plain_one->key);
check(strpos((string)$raw_sealed['imc_address'], 'v1.aead.') === 0, 'a row the policy seals is ciphertext');
check((string)$raw_plain['imc_address'] === 'skip@example.com', 'a row it declines is stored in the clear');
check(!sos_flag($raw_plain['imc_content_sealed']),
	'and its seal flag stays false, so reads never take the decrypt path');
check((new SealOnSavePolicyProbe((int)$plain_one->key, TRUE))->get('imc_address') === 'skip@example.com',
	'sealed and plaintext rows coexist in one table and both read correctly');

// ---------------------------------------------------------------------------
section('A member with no vault is stored in the clear, not refused');
// ---------------------------------------------------------------------------
$stranger = make_user('SealOnSaveNoVault');
$unsealed = sos_new('SealOnSaveProbe', (int)$stranger->key, 'nobody@example.com', 'Nobody');
$raw_unsealed = sos_raw((int)$unsealed->key);
check((string)$raw_unsealed['imc_address'] === 'nobody@example.com',
	'the default policy is "seal when this row\'s owner has a vault" — no vault, no ciphertext');
check(!sos_flag($raw_unsealed['imc_content_sealed']), 'and the row is not flagged sealed');

// ---------------------------------------------------------------------------
section('$seal_on_save = false behaves exactly as it did before');
// ---------------------------------------------------------------------------
// This is what keeps plugins/mailbox/tests/mailbox_reseal_test.php green
// untouched: the in-tree consumers own their sealing and save() stays out of it.
$optout = sos_new('SealOnSaveOptOutProbe', $owner_id, 'manual@example.com', 'Manual');
$raw_optout = sos_raw((int)$optout->key);
check((string)$raw_optout['imc_address'] === 'manual@example.com',
	'an opted-out model writes its columns verbatim and seals nothing');
check(empty($raw_optout['imc_sealed_key']), 'no key is minted for it');

// Its own sealing path still works, and save() still refuses to touch the
// sealed columns afterwards.
$vault_row = new UserEncryptionVault((int)$vault->key, TRUE);
SealOnSaveOptOutProbe::sealColumns((int)$optout->key, $vault_row,
	array('imc_address' => 'manual@example.com', 'imc_display_name' => 'Manual'));
$sealed_raw = sos_raw((int)$optout->key);
check(strpos((string)$sealed_raw['imc_address'], 'v1.aead.') === 0, 'sealColumns() still seals it');

$touch = new SealOnSaveOptOutProbe((int)$optout->key, TRUE);
$touch->set('imc_use_count', 3);
$touch->save();
$after_touch = sos_raw((int)$optout->key);
check((string)$after_touch['imc_address'] === (string)$sealed_raw['imc_address'],
	'and a later save() leaves the sealed columns exactly as the sealing path left them');

// ---------------------------------------------------------------------------
section('A FIRST-TIME seal of an existing row seals the whole row, not the dirty subset');
// ---------------------------------------------------------------------------
// A row created plaintext (policy declined, or its owner had no vault yet) and
// sealed later must not end up half-and-half: plaintext in a sealed column of a
// flag=true row is leaked at rest AND throws on every later read.
$latecomer = sos_new('SealOnSavePolicyProbe', $owner_id, 'later@example.com', 'Later', 'import');
check((string)sos_raw((int)$latecomer->key)['imc_address'] === 'later@example.com',
	'the row starts life plaintext (the policy declined it)');

$flip = new SealOnSavePolicyProbe((int)$latecomer->key, TRUE);
$flip->set('imc_source', 'manual');            // the policy now seals this row
$flip->set('imc_display_name', 'Later Sealed'); // but only ONE sealed column is dirty
$flip->save();

$raw_flip = sos_raw((int)$latecomer->key);
check(sos_flag($raw_flip['imc_content_sealed']), 'the row is now sealed');
check(strpos((string)$raw_flip['imc_display_name'], 'v1.aead.') === 0, 'the edited column is ciphertext');
check(strpos((string)$raw_flip['imc_address'], 'v1.aead.') === 0,
	'and so is the column the edit never touched — the whole row sealed, not the dirty subset');
$flip_back = new SealOnSavePolicyProbe((int)$latecomer->key, TRUE);
check($flip_back->get('imc_address') === 'later@example.com', 'the lifted column reads back');
check($flip_back->get('imc_display_name') === 'Later Sealed', 'and so does the edited one');

// ---------------------------------------------------------------------------
section('A STALE instance never tramples a row sealed behind its back');
// ---------------------------------------------------------------------------
// sealColumns() writes with a targeted UPDATE that never touches an already-
// loaded instance, so "loaded before the row sealed" is an ordinary state (a
// deferred ingest, another request). The database, not the instance, must
// answer "is this row sealed?" at save time.
$raced = sos_new('SealOnSavePolicyProbe', $owner_id, 'raced@example.com', 'Raced', 'import');
$stale = new SealOnSavePolicyProbe((int)$raced->key, TRUE);   // loads PLAINTEXT, flag false

$vault_now = new UserEncryptionVault((int)$vault->key, TRUE);
SealOnSavePolicyProbe::sealColumns((int)$raced->key, $vault_now,
	array('imc_address' => 'raced@example.com', 'imc_display_name' => 'Raced'));
$wrapping_before = (string)sos_raw((int)$raced->key)['imc_sealed_key'];
check($wrapping_before !== '', 'another process sealed the row behind the stale instance');

// First: a save whose policy still reads "plaintext" must not write plaintext
// (or its stale NULL metadata) over the sealed row.
$stale->set('imc_use_count', 41);
$stale->set('imc_display_name', 'Stale Trample');
$stale->save();
$raw_raced = sos_raw((int)$raced->key);
check((string)$raw_raced['imc_sealed_key'] === $wrapping_before, 'the key wrapping survived the stale save');
check(strpos((string)$raw_raced['imc_address'], 'v1.aead.') === 0, 'the sealed columns survived it too');
check((int)$raw_raced['imc_use_count'] === 41, 'while the ordinary column still saved normally');

// Second: a seal-on-save update from the same stale instance must REUSE the
// row's DEK it never knew about, not mint a second one over it.
$stale2 = new SealOnSavePolicyProbe((int)$raced->key, TRUE);
// Fake the staleness the targeted UPDATE creates in the wild: the instance
// believes the row is unsealed and still holds plaintext + NULL metadata.
$stale2->set('imc_content_sealed', false);
$stale2->set('imc_sealed_key', null);
$stale2->set('imc_source', 'manual');
$stale2->set('imc_display_name', 'Stale But Careful');
$stale2->set('imc_address', 'raced@example.com');
$stale2->save();
$raw_raced = sos_raw((int)$raced->key);
check((string)$raw_raced['imc_sealed_key'] === $wrapping_before,
	'the update re-used the existing DEK — a stale flag cannot cause a fresh mint over a live wrapping');
$reread = new SealOnSavePolicyProbe((int)$raced->key, TRUE);
check($reread->get('imc_display_name') === 'Stale But Careful', 'the new value reads back');
check($reread->get('imc_address') === 'raced@example.com', 'and every other sealed column still opens');

// ---------------------------------------------------------------------------
section('Clearing and refusing: null stays NULL, and a non-scalar is refused');
// ---------------------------------------------------------------------------
$cleared = new SealOnSaveProbe((int)$contact->key, TRUE);
$cleared->set('imc_display_name', null);
$cleared->save();
$raw_cleared = sos_raw((int)$contact->key);
check($raw_cleared['imc_display_name'] === null,
	'clearing a sealed column stores NULL, not an AEAD blob of nothing — IS NULL queries stay honest');
$after_clear = new SealOnSaveProbe((int)$contact->key, TRUE);
check($after_clear->get('imc_display_name') === null, 'and it reads back as null');
check($after_clear->get('imc_address') === 'alice@example.com', 'with the rest of the row intact');

$refused = new SealOnSaveProbe((int)$contact->key, TRUE);
$refused->set('imc_display_name', array('not', 'a', 'string'));
$threw_type = false;
try {
	$refused->save();
} catch (SystemBaseException $e) {
	$threw_type = true;
}
check($threw_type, 'an array value is refused loudly — never cast to the literal string "Array" and sealed');
$still = new SealOnSaveProbe((int)$contact->key, TRUE);
check($still->get('imc_address') === 'alice@example.com', 'and the refused save changed nothing');

VaultUnlock::lockAll($owner_id);
harness_finish();
?>
