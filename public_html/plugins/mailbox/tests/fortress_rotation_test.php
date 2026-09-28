<?php
/** @joinery-test
 * name: fortress_rotation
 * tier: test-db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * Rotating the `mail` vault's key (specs/client_custody_mail.md § R11, WP6).
 *
 *  - the rotation walk lists every one of the owner's Fortress rows on the old
 *    generation (a pending-parse row and a deleted one included) and the
 *    search key, and nothing of another owner's;
 *  - mail arriving while the rotation is pending seals to the new key and is
 *    not in the walk;
 *  - commit refuses while one row is left, then takes the new key, and every
 *    row opens under it with its DEK unchanged;
 *  - VaultUnlock::onClientRotation() listeners hear begin and commit, the
 *    mailbox registers one, and a listener that fails does not stop either;
 *  - a mail vault that opens through the root (every one made since the one
 *    vault) rotates with one `root` wrapping and nothing else, and the
 *    Security page lists it.
 *
 * Run: php tests/run.php test-db --filter=fortress_rotation
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');
require_once(__DIR__ . '/lib/fortress_fixture.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}

/** The exception $fn throws, or null. */
function frt_throws(callable $fn): ?Throwable {
	try { $fn(); } catch (Throwable $e) { return $e; }
	return null;
}

try {
	$db = DbConnector::get_instance()->get_db_link();
	$fx = fortress_fixture('Rotate');
	$other = fortress_fixture('RotateOther');
	$owner_id = $fx['owner_id'];

	// ------------------------------------------------------------ the rows
	section('The owner\'s Fortress rows on the old key');

	$ids = array(fortress_ingest($fx, 'Rotation one'), fortress_ingest($fx, 'Rotation two'), fortress_ingest($fx, 'Rotation three'));
	check(count(array_filter($ids)) === 3, 'three messages are stored');
	list($read_id, $pending_id, $deleted_id) = $ids;
	// A relay row waiting for the browser to parse it carries the same key; a
	// deleted row can be restored, so it must move too.
	$db->prepare('UPDATE iem_inbound_email_messages SET iem_pending_parse = true WHERE iem_inbound_email_message_id = ?')
		->execute(array($pending_id));
	$db->prepare('UPDATE iem_inbound_email_messages SET iem_delete_time = now() WHERE iem_inbound_email_message_id = ?')
		->execute(array($deleted_id));
	$search_key = 'v1.edgeseal.mail.' . $fx['box']->sealEdge(random_bytes(32), $fx['pub']);
	MailboxSearchKey::acceptBrowserKey($owner_id, $search_key, $fx['pub']);
	$others_id = fortress_ingest($other, 'Not the owner\'s');
	check($others_id > 0, 'another owner has a Fortress message too');

	$deks = array();
	foreach ($ids as $id) {
		$deks[$id] = fortress_open_dek($fx, (string)fortress_row($id)['iem_sealed_key']);
	}
	$search_dek = fortress_open_dek($fx, $search_key);

	// ------------------------------------------------------------ listeners
	section('Listeners');

	$prop = new ReflectionProperty('VaultUnlock', 'client_rotation_callbacks');
	$prop->setAccessible(true);
	VaultUnlock::loadConsumerBootstraps();
	check(count($prop->getValue()['mail'] ?? array()) === 1, 'the mailbox listens for mail rotations (its relay map push)');
	$heard = array();
	VaultUnlock::onClientRotation('mail', function (int $uid, string $phase) {
		throw new RuntimeException('a listener that fails');
	});
	VaultUnlock::onClientRotation('mail', function (int $uid, string $phase) use (&$heard) {
		$heard[] = $uid . ':' . $phase;
	});
	VaultUnlock::onClientRotation('drive', function (int $uid, string $phase) use (&$heard) {
		$heard[] = 'drive:' . $phase;
	});

	// ------------------------------------------------------------ begin
	section('begin');

	// The new key opens with a passphrase, offered only to someone whose
	// passkeys cannot hold a key.
	$passkey = vault_fixture_passkey($owner_id);
	$passkey->set('pkc_prf_capable', false);
	$passkey->set('pkc_prf_failed_time', gmdate('Y-m-d H:i:s'));
	$passkey->save();
	$new_pair = $fx['box']->generateKeypair();
	$new_pub = base64_encode(SealedBox::b64url_decode($new_pair['public']));
	$began = VaultClientRotation::begin($owner_id, 'mail', $new_pub, array(
		array('unlocker_type' => 'passphrase', 'wrapped_secret_key' => 'new-passphrase-wrapping', 'salt' => 'salt'),
		array('unlocker_type' => 'recovery', 'wrapped_secret_key' => 'new-recovery-wrapping', 'salt' => 'salt'),
	));
	check($began['pending_key_generation'] === 2, 'the new key is pending as generation 2');
	check($heard === array($owner_id . ':begin'), 'the mail listener heard begin once, after a failing listener, and drive\'s heard nothing');

	// Mail arriving now seals to the new key.
	$new_fx = array_merge($fx, array('pair' => $new_pair, 'pub' => $new_pub));
	$arrived = fortress_ingest($fx, 'Arrived mid-rotation');
	$arow = fortress_row($arrived);
	check($arrived > 0 && intval($arow['iem_key_generation']) === 2, 'a message arriving mid-rotation is on generation 2');
	check(frt_throws(function () use ($new_fx, $arow) { fortress_open_dek($new_fx, (string)$arow['iem_sealed_key']); }) === null,
		'and opens with the new key');

	// ------------------------------------------------------------ the walk
	section('The walk');

	$listed = array();
	$cursor = array('model' => '', 'after_id' => 0);
	$remaining = null;
	for ($guard = 0; $guard < 20; $guard++) {
		$page = VaultClientRotation::resealPage($owner_id, 'mail', $cursor['model'], $cursor['after_id'], 2);
		$remaining = $remaining ?? $page['remaining'];
		$listed = array_merge($listed, $page['rows']);
		if ($page['next'] === null) break;
		$cursor = $page['next'];
	}
	$by_model = array();
	foreach ($listed as $r) { $by_model[$r['model']][] = $r['id']; }
	$message_ids = $by_model['InboundEmailMessage'] ?? array();
	sort($message_ids);
	check($message_ids === $ids, 'the walk lists the three messages, the pending and deleted ones included');
	check(count($by_model['MailboxSearchKey'] ?? array()) === 1, 'and the search key');
	check(count($listed) === 4 && $remaining === 4, 'nothing else: not the mid-rotation arrival, not another owner\'s row');

	// The browser's half: open each DEK with the old key, seal it to the new one.
	$reseal = function (array $r) use ($fx, $new_pub) {
		$dek = fortress_open_dek($fx, $r['sealed_dek']);
		return array('model' => $r['model'], 'id' => $r['id'],
			'sealed_dek' => 'v1.edgeseal.mail.' . $fx['box']->sealEdge($dek, $new_pub));
	};
	$last = array_pop($listed);
	check(VaultClientRotation::resealRows($owner_id, array_map($reseal, $listed)) === 3, 'three are re-sealed');

	// ------------------------------------------------------------ commit
	section('commit');

	$refused = frt_throws(function () use ($owner_id) { VaultClientRotation::commit($owner_id, 'mail'); });
	check($refused !== null && strpos($refused->getMessage(), '1 sealed item is') === 0, 'commit refuses with one left');
	check($heard === array($owner_id . ':begin'), 'a refused commit tells no listener');
	VaultClientRotation::resealRows($owner_id, array($reseal($last)));
	$done = VaultClientRotation::commit($owner_id, 'mail');
	check($done['key_generation'] === 2, 'with none left, it commits generation 2');
	check($heard === array($owner_id . ':begin', $owner_id . ':commit'), 'the listener heard commit');
	$vault = VaultClientCustody::loadVault($owner_id, 'mail');
	check((string)$vault->get('uev_public_key') === $new_pub && $vault->get('uev_pending_key_generation') === null,
		'the new key is the key');

	// ------------------------------------------------------------ after
	section('Every row opens under the new key');

	$opens = 0;
	foreach ($ids as $id) {
		$row = fortress_row($id);
		$dek = fortress_open_dek($new_fx, (string)$row['iem_sealed_key']);
		if ($dek === $deks[$id] && intval($row['iem_key_generation']) === 2
				&& strpos(fortress_open_field($new_fx, (string)$row['iem_subject'], $dek, 'mail:' . $id . ':iem_subject'), 'Rotation ') === 0) {
			$opens++;
		}
	}
	check($opens === 3, 'each message\'s DEK is the same, on generation 2, and its subject reads');
	check(fortress_open_dek($new_fx, (string)MailboxSearchKey::sealedKeyFor($owner_id)) === $search_dek,
		'the search key is the same key, so no browser rebuilds its index');
	check(frt_throws(function () use ($fx, $ids) { fortress_open_dek($fx, (string)fortress_row($ids[0])['iem_sealed_key']); }) !== null,
		'the old key opens none of it');
	check(strpos((string)fortress_row($others_id)['iem_sealed_key'], 'v1.edgeseal.mail.') === 0
		&& fortress_open_dek($other, (string)fortress_row($others_id)['iem_sealed_key']) !== '',
		'another owner\'s row is untouched');

	// ------------------------------------------------------------ through the root
	section('A mail vault that opens through the root rotates under it');

	// Every mail vault made since the one vault holds a `root` wrapping and no
	// unlocker of its own (specs/implemented/one_vault_experience.md).
	$rfx = fortress_fixture('RotateRoot');
	$rid = $rfx['owner_id'];
	$rvault = VaultClientCustody::loadVault($rid, 'mail');
	$db->prepare("INSERT INTO uew_user_encryption_wrappings (uew_uev_user_encryption_vault_id, uew_unlocker_type, uew_wrapped_secret_key, uew_key_generation)
		VALUES (?, 'root', 'root-wrapping-gen1', 1)")->execute(array((int)$rvault->key));
	$rmsg = fortress_ingest($rfx, 'Rotation through the root');
	check(VaultClientCustody::opensThroughRoot($rvault), 'the vault opens through the root');
	check(VaultClientCustody::throughRootVaults($rid) === array(array('scope' => 'mail', 'label' => VaultScopes::labelFor('mail'), 'pending' => false)),
		'the Security page lists it for rotating');
	check(VaultClientCustody::throughRootVaults($owner_id) === array(), 'and not a vault with unlockers of its own');

	$rpair = $rfx['box']->generateKeypair();
	$rpub = base64_encode(SealedBox::b64url_decode($rpair['public']));
	$root_w = array('unlocker_type' => 'root', 'wrapped_secret_key' => 'root-wrapping-gen2');
	foreach (array(
		'its own unlockers' => array(
			array('unlocker_type' => 'passphrase', 'wrapped_secret_key' => 'p', 'salt' => 'salt'),
			array('unlocker_type' => 'recovery', 'wrapped_secret_key' => 'r', 'salt' => 'salt')),
		'a root wrapping beside a recovery code' => array($root_w, array('unlocker_type' => 'recovery', 'wrapped_secret_key' => 'r', 'salt' => 'salt')),
		'two root wrappings' => array($root_w, $root_w),
		'nothing' => array(),
	) as $why => $ws) {
		check(frt_throws(function () use ($rid, $rpub, $ws) { VaultClientRotation::begin($rid, 'mail', $rpub, $ws); }) !== null,
			'begin refuses ' . $why);
	}
	check(VaultClientCustody::loadVault($rid, 'mail')->get('uev_pending_key_generation') === null, 'and nothing was stored');
	check(VaultClientRotation::begin($rid, 'mail', $rpub, array($root_w))['pending_key_generation'] === 2,
		'one root wrapping begins it');
	$st = VaultClientCustody::statusPayload($rid, 'mail');
	check(count($st['pending_wrappings']) === 1 && $st['pending_wrappings'][0]['unlocker_type'] === 'root'
		&& $st['pending_wrappings'][0]['wrapped_secret_key'] === 'root-wrapping-gen2' && $st['root_wrapped'] === true,
		'the browser finishing it finds the new key\'s root wrapping apart from the old one');
	check(VaultClientCustody::throughRootVaults($rid)[0]['pending'] === true, 'the Security page says it stopped part way');

	$rrows = VaultClientRotation::resealPage($rid, 'mail', '', 0, 200)['rows'];
	check(count($rrows) === 1 && $rrows[0]['id'] === $rmsg, 'the walk lists its message');
	VaultClientRotation::resealRows($rid, array_map(function ($r) use ($rfx, $rpub) {
		return array('model' => $r['model'], 'id' => $r['id'],
			'sealed_dek' => 'v1.edgeseal.mail.' . $rfx['box']->sealEdge(fortress_open_dek($rfx, $r['sealed_dek']), $rpub));
	}, $rrows));
	VaultClientRotation::commit($rid, 'mail');
	$after = VaultClientCustody::statusPayload($rid, 'mail');
	check($after['key_generation'] === 2 && $after['root_wrapped'] === true && count($after['wrappings']) === 1
		&& $after['wrappings'][0]['wrapped_secret_key'] === 'root-wrapping-gen2',
		'after commit the new key opens through the root, by its new wrapping alone');
	check(in_array($rid . ':commit', $heard, true), 'the listener heard this rotation too');
} catch (Throwable $e) {
	check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage());
}

harness_finish();
