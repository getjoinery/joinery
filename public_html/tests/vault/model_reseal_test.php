<?php
/** @joinery-test
 * name: model_reseal
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 *
 * The generic model re-seal — SystemBase::resealRows() and the
 * VaultUnlock::modelReseal() callback built on it.
 *
 * Only the WRAPPING of each row's key moves; the key itself and every byte of
 * ciphertext it seals are untouched, which is what makes a rotation cheap no
 * matter how much content a member holds. The two things that must not slip:
 * it touches exactly the draining generation (any other generation's rows would
 * fail to unwrap, and re-sealing an already-current row would be a no-op at
 * best), and it stays inside one member's rows.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_contacts_class.php'));

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

$crypto = new VaultCrypto();

/** A vault row plus the keypair behind it, so the test can play both ends. */
function mr_vault(string $suffix): array {
	$user = make_user('Reseal' . $suffix);
	$keypair = (new SealedBox())->generateKeypair();
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', (int)$user->key);
	$vault->set('uev_public_key', $keypair['public']);
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->set('uev_key_generation', 1);
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);
	return array(
		'user_id' => (int)$user->key,
		'vault'   => $vault,
		'public'  => $keypair['public'],
		'secret'  => $keypair['secret'],
		'key'     => vault_fixture_key($keypair['secret']),
	);
}

/** A sealed contact row on a chosen generation. */
function mr_contact(array $fx, string $address, int $generation) {
	$row = new MailboxContact(NULL);
	$row->set('imc_usr_user_id', $fx['user_id']);
	$row->set('imc_address_hash', hash('sha256', $address . '|' . $fx['user_id'] . '|' . random_bytes(8)));
	$row->save();
	harness_register_row('imc_mailbox_contacts', 'imc_mailbox_contact_id', (int)$row->key);
	MailboxContact::sealColumns((int)$row->key, $fx['vault'], array('imc_address' => $address));
	// sealColumns stamps the vault's CURRENT generation; force the one this row
	// is meant to be sitting on.
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'UPDATE imc_mailbox_contacts SET imc_key_generation = ? WHERE imc_mailbox_contact_id = ?');
	$q->execute(array($generation, (int)$row->key));
	return (int)$row->key;
}

function mr_raw(int $id): array {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT * FROM imc_mailbox_contacts WHERE imc_mailbox_contact_id = ?');
	$q->execute(array($id));
	return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}

$owner   = mr_vault('Owner');
$other   = mr_vault('Other');
$new_keypair  = (new SealedBox())->generateKeypair();
$new_public   = $new_keypair['public'];
$new_secret   = vault_fixture_key($new_keypair['secret']);

$draining  = mr_contact($owner, 'draining@example.com', 1);
$already   = mr_contact($owner, 'already@example.com', 2);
$stranger  = mr_contact($other, 'stranger@example.com', 1);

$blob_before      = (string)mr_raw($draining)['imc_address'];
$already_key      = (string)mr_raw($already)['imc_sealed_key'];
$stranger_key     = (string)mr_raw($stranger)['imc_sealed_key'];

// ---------------------------------------------------------------------------
section('The re-seal moves exactly the draining generation, for one member');
// ---------------------------------------------------------------------------
$result = MailboxContact::resealRows($owner['user_id'], $owner['key'], 1, $new_public, 2);
check($result['attempted'] === 1, 'exactly one row was on the draining generation');
check($result['failed'] === 0, 'and it re-sealed cleanly');

$after = mr_raw($draining);
check((int)$after['imc_key_generation'] === 2, 'the row moved to the new generation');
check((string)$after['imc_address'] === $blob_before,
	'the CIPHERTEXT is byte-for-byte unchanged — only the key wrapping moved, which is why rotation is cheap');

$dek = $crypto->openItemDek((string)$after['imc_sealed_key'], $new_secret);
check($crypto->openField((string)$after['imc_address'], $dek,
	MailboxContact::sealAd($draining, 'imc_address')) === 'draining@example.com',
	'and the content opens under the new key');

check((string)mr_raw($already)['imc_sealed_key'] === $already_key,
	'a row already on the current generation is left alone — the old secret could not open it anyway');
check((string)mr_raw($stranger)['imc_sealed_key'] === $stranger_key,
	"another member's rows are never touched");

// ---------------------------------------------------------------------------
section('An unopenable row is counted, not swallowed');
// ---------------------------------------------------------------------------
// Fail-loud is the whole contract: a swallowed failure here means the ceremony
// retires the old wrappings while content is still sealed to them.
$corrupt = mr_contact($owner, 'corrupt@example.com', 3);
$q = DbConnector::get_instance()->get_db_link()->prepare(
	'UPDATE imc_mailbox_contacts SET imc_sealed_key = ? WHERE imc_mailbox_contact_id = ?');
$q->execute(array('v1.aead.not-a-real-wrapping', $corrupt));

$fine = mr_contact($owner, 'fine@example.com', 3);
$result = MailboxContact::resealRows($owner['user_id'], $owner['key'], 3, $new_public, 4);
check($result['attempted'] === 2, 'both generation-3 rows were attempted');
check($result['failed'] === 1, 'the damaged one is reported as a failure');
check((int)mr_raw($fine)['imc_key_generation'] === 4,
	'and the healthy row still moved — every row is attempted before anything is reported');

// ---------------------------------------------------------------------------
section('modelReseal() composes models into one fail-loud callback');
// ---------------------------------------------------------------------------
$callback = VaultUnlock::modelReseal(array(MailboxContact::class));
check(is_callable($callback), 'modelReseal() hands back a callback with the onReseal signature');

$clean = mr_contact($owner, 'clean@example.com', 5);
$callback($owner['user_id'], $owner['key'], 5, $new_public, 6);
check((int)mr_raw($clean)['imc_key_generation'] === 6, 'a clean pass re-seals and returns quietly');

$broken = mr_contact($owner, 'broken@example.com', 7);
$q->execute(array('v1.aead.also-not-real', $broken));
$threw = false;
$message = '';
try {
	$callback($owner['user_id'], $owner['key'], 7, $new_public, 8);
} catch (RuntimeException $e) {
	$threw = true;
	$message = $e->getMessage();
}
check($threw, 'a failure THROWS, so the ceremony refuses to retire the old wrappings');
check(strpos($message, 'MailboxContact') !== false, 'and the message names the model that failed');

// ---------------------------------------------------------------------------
section('modelReseal() pages through more rows than one batch');
// ---------------------------------------------------------------------------
// A rotation once read every sealed row a member had in one query, which a
// large consumer could not fit in memory (security_weak_spots_2026_11 WP2).
$many = array();
for ($i = 0; $i < VaultChainSweep::BATCH + 5; $i++) {
	$many[] = mr_contact($owner, 'many' . $i . '@example.com', 9);
}
$callback($owner['user_id'], $owner['key'], 9, $new_public, 10);
$ids = implode(',', array_map('intval', $many));
$left = (int)DbConnector::get_instance()->get_db_link()->query(
	'SELECT COUNT(*) FROM imc_mailbox_contacts WHERE imc_key_generation = 9 AND imc_mailbox_contact_id IN (' . $ids . ')')
	->fetchColumn();
check($left === 0, 'every one of ' . count($many) . ' rows moved, past the ' . VaultChainSweep::BATCH . '-row batch');
$last = mr_raw($many[count($many) - 1]);
$dek = $crypto->openItemDek((string)$last['imc_sealed_key'], $new_secret);
check($crypto->openField((string)$last['imc_address'], $dek,
	MailboxContact::sealAd($many[count($many) - 1], 'imc_address')) === 'many' . (count($many) - 1) . '@example.com',
	'and the last row, in the second batch, opens under the new key');

// ---------------------------------------------------------------------------
section('The re-seal reads the wrapping columns, not the sealed content');
// ---------------------------------------------------------------------------
$cols = MailboxContact::resealSelectColumns();
check(in_array('imc_sealed_key', $cols, true) && in_array('imc_key_generation', $cols, true)
	&& in_array('imc_mailbox_contact_id', $cols, true), 'the key, generation and id columns are read');
check(!in_array('imc_address', $cols, true), 'a sealed content column is not');

$cols = InboundEmailMessage::resealSelectColumns();
check(!in_array('iem_body_html', $cols, true) && !in_array('iem_raw_message', $cols, true),
	'a message re-seal reads neither its sealed body nor its large raw-message column');
// Each owner override must still see the columns it resolves the owner from.
// Found by scanning, not listed by hand: a new override, or a column the type
// rule drops, would otherwise resolve no owner, be passed over as another
// member's row, and stay on a generation the rotation then retires.
$root = PathHelper::getIncludePath('');
$override_files = array_merge(glob($root . 'data/*_class.php') ?: array(), glob($root . 'plugins/*/data/*_class.php') ?: array());
$overrides = 0;
foreach ($override_files as $file) {
	$src = (string)file_get_contents($file);
	if (strpos($src, 'function sealedOwnerUserIdFor(') === false) {
		continue;
	}
	if (!preg_match_all('/^class\s+(\w+)\s+extends\s+SystemBase\b/m', $src, $m)) {
		continue;
	}
	foreach ($m[1] as $class) {
		if (!class_exists($class)) {
			continue;   // an inactive plugin's model
		}
		$method = new ReflectionMethod($class, 'sealedOwnerUserIdFor');
		if ($method->getDeclaringClass()->getName() !== $class) {
			continue;
		}
		$overrides++;
		$lines = file($method->getFileName());
		$body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
		// One level of helpers the override hands $row to (conversationRow($row)).
		if (preg_match_all('/(?:self|static)::(\w+)\(\s*\$row\b/', $body, $calls)) {
			foreach (array_unique($calls[1]) as $helper) {
				if (method_exists($class, $helper)) {
					$h = new ReflectionMethod($class, $helper);
					$body .= implode('', array_slice($lines, $h->getStartLine() - 1, $h->getEndLine() - $h->getStartLine() + 1));
				}
			}
		}
		preg_match_all('/\$row\[\'(\w+)\'\]/', $body, $keys);
		$read = array_values(array_intersect(array_unique($keys[1]), array_keys($class::$field_specifications)));
		$missing = array_diff($read, $class::resealSelectColumns());
		check(count($read) > 0 && count($missing) === 0,
			$class . '\'s owner override reads ' . implode(', ', $read) . ', all in its re-seal select',
			$missing ? 'missing: ' . implode(', ', $missing) : '');
	}
}
check($overrides >= 3, 'the scan found the owner overrides (' . $overrides . ')');

// ---------------------------------------------------------------------------
section('A model with no sealing columns is refused rather than half-rotated');
// ---------------------------------------------------------------------------
$threw = false;
try {
	User::resealRows($owner['user_id'], $owner['key'], 1, $new_public, 2);
} catch (RuntimeException $e) {
	$threw = true;
}
check($threw, 'resealRows() on a model with no sealed columns raises rather than silently doing nothing');

harness_finish();
?>
