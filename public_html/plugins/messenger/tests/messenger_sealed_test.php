<?php
/** @joinery-test
 * name: messenger_sealed
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Protected conversations (specs/implemented/joinery_messenger.md § Protection levels).
 *
 * The messenger is the platform's first multi-participant sealed consumer:
 * one key per conversation, wrapped to every member, and the server reads a
 * message only while someone who holds a wrapping is present. That is a
 * different shape from every other sealed model, which seals to a single
 * owner — so what it promises is pinned here rather than inferred from the
 * generic machinery:
 *
 *  - a raise seals the whole history, bodies and attachment bytes alike;
 *  - any single present member's window is enough to read the thread;
 *  - a member added later can read it; a member removed cannot, from then on;
 *  - a vault key rotation re-wraps the grants and every message still opens;
 *  - with nobody present, reads are LOCKED — never ciphertext handed back as
 *    though it were data;
 *  - protection only ever tightens.
 *
 * Run: php tests/run.php db --filter=messenger_sealed
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../../../tests/lib/vault_fixtures.php');

require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/ConversationSealing.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/conversations_class.php'));
require_once(PathHelper::getIncludePath('data/conversation_key_grants_class.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/messenger/includes/Messenger.php'));

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process',
		'run manually: php -d apc.enable_cli=1 plugins/messenger/tests/messenger_sealed_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

$suffix = strtoupper(LibraryFunctions::random_string(6));

/**
 * A member with a server-custody vault, and the secret that opens it.
 *
 * A synthetic keypair rather than the setup ceremony: what is under test is the
 * conversation's key management, and a real WebAuthn PRF cannot run in CLI.
 */
function sealed_member(string $label): array {
	$user = make_user($label);
	$kp = sodium_crypto_box_keypair();
	$vault = new UserEncryptionVault(NULL);
	$vault->set('uev_usr_user_id', (int)$user->key);
	$vault->set('uev_public_key', SealedBox::b64url(sodium_crypto_box_publickey($kp)));
	$vault->set('uev_salt', SealedBox::b64url(random_bytes(16)));
	$vault->save();
	$vault->load();
	harness_register_row('uev_user_encryption_vaults', 'uev_user_encryption_vault_id', (int)$vault->key);
	return array(
		'user'   => $user,
		'id'     => (int)$user->key,
		'secret' => SealedBox::b64url(sodium_crypto_box_secretkey($kp)),
		'public' => SealedBox::b64url(sodium_crypto_box_publickey($kp)),
		'vault'  => $vault,
	);
}

function open_window(array $member): void {
	VaultUnlock::open($member['id'], $member['secret'],
		UserEncryptionVault::SCOPE_USER, array('idle' => null, 'absolute' => null));
}

function lock_everyone(array $members): void {
	foreach ($members as $m) { VaultUnlock::lockAll($m['id']); }
}

function msgr_track(Conversation $c): Conversation {
	harness_register_row('cnv_conversations', 'cnv_conversation_id', (int)$c->key);
	return $c;
}

/** The stored column, straight from the table — no model, no decryption. */
function raw_body(int $message_id) {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		'SELECT msg_body, msg_content_sealed FROM msg_messages WHERE msg_message_id = ?');
	$q->execute(array($message_id));
	return $q->fetch(PDO::FETCH_ASSOC);
}

$alice = sealed_member('SealA' . $suffix);
$bob   = sealed_member('SealB' . $suffix);
$carol = sealed_member('SealC' . $suffix);
$novault = make_user('SealN' . $suffix);   // deliberately has no vault

// =====================================================================
section('a raise is refused while anyone in the room has no protection');

$blocked = msgr_track(Conversation::create_conversation(
	array($alice['id'], $novault->key), null, array('admin_user_id' => $alice['id'])));
$blocked->add_message($alice['id'], 'in the clear');

$missing = $blocked->members_without_vault();
check(count($missing) === 1, 'the conversation knows who is not set up');
check(isset($missing[(int)$novault->key]), 'and names them');

$refused = '';
try { $blocked->raise(ProtectionLevel::PRIVATE_, $alice['id']); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'Waiting on') !== false, 'the refusal names who is holding it up');
check($blocked->protection_level() === ProtectionLevel::STANDARD, 'and nothing was changed');
check(raw_body((int)Messenger::latestMessage($blocked)->key)['msg_content_sealed'] !== true,
	'the history is still plaintext');

// =====================================================================
section('raising seals the whole history at once');

$room = msgr_track(Conversation::create_conversation(
	array($alice['id'], $bob['id']), 'Sealed ' . $suffix,
	array('admin_user_id' => $alice['id'])));
$first  = $room->add_message($alice['id'], 'said before protection');
$second = $room->add_message($bob['id'], 'and a reply before it too');

$room->raise(ProtectionLevel::PRIVATE_, $alice['id']);
check($room->protection_level() === ProtectionLevel::PRIVATE_, 'the conversation is Private');
check($room->is_sealed(), 'and reads as sealed');

foreach (array($first, $second) as $m) {
	$raw = raw_body((int)$m->key);
	check(strpos((string)$raw['msg_body'], 'v1.aead.') === 0,
		'message ' . $m->key . ' is ciphertext in the database');
	check(strpos((string)$raw['msg_body'], 'before') === false,
		'and no fragment of it survives in the clear');
}

$grants = new MultiConversationKeyGrant(array('conversation_id' => (int)$room->key));
check($grants->count() === 2, 'both members hold a key grant');

// =====================================================================
section('with nobody present, a read is locked — never ciphertext');

lock_everyone(array($alice, $bob, $carol));

$locked = false;
try { (new Message((int)$first->key, TRUE))->get('msg_body'); }
catch (VaultLockedException $e) { $locked = true; }
check($locked, 'reading a sealed body with every window closed raises locked');

$payload = Messenger::messagesPayload(
	array(new Message((int)$first->key, TRUE)), $alice['id']);
check($payload[0]['is_locked'] === true, 'and the thread payload says locked');
check($payload[0]['body'] === '', 'with nothing where the words would be');

// =====================================================================
section('any one present member is enough');

open_window($bob);
$read = (new Message((int)$first->key, TRUE))->get('msg_body');
check($read === 'said before protection',
	'a message Alice wrote opens on Bob\'s window — grants, not ownership');
lock_everyone(array($bob));

open_window($alice);
$read = (new Message((int)$second->key, TRUE))->get('msg_body');
check($read === 'and a reply before it too', 'and on Alice\'s');

// =====================================================================
section('a message sent into a sealed conversation is never stored in the clear');

$fresh = $room->add_message($alice['id'], 'after the raise');
$raw = raw_body((int)$fresh->key);
check(strpos((string)$raw['msg_body'], 'v1.aead.') === 0, 'the new message is ciphertext');
check((new Message((int)$fresh->key, TRUE))->get('msg_body') === 'after the raise',
	'and reads back correctly in the window');

// =====================================================================
section('attachment bytes are sealed under the same key');

require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/message_attachments_class.php'));

$plain_bytes = 'PLAINTEXT-' . $suffix . str_repeat('.', 2048);
$attachment = File::createFromBytes($plain_bytes, 'note-' . $suffix . '.txt', 'text/plain',
	$alice['id'], array('fil_private' => true, 'fil_source' => File::SOURCE_MESSENGER_ATTACHMENT));
harness_register_row('fil_files', 'fil_file_id', (int)$attachment->key);
$attachment->set('fil_access_provider', 'messenger_conversation');
$attachment->set('fil_access_ref', (int)$room->key);
$attachment->set('fil_private', false);
$attachment->save();

$with_file = $room->add_message($alice['id'], 'here it is', array('attachments' => array($attachment)));
$attachment->load();

check($attachment->is_sealed(), 'the stored file is marked Private');
check((int)$attachment->get('fil_plain_size_bytes') === strlen($plain_bytes),
	'and remembers its plaintext size, which is what a member is shown');

$stored = $attachment->read_bytes();
check(strpos((string)$stored, 'PLAINTEXT-') === false,
	'the bytes on disk carry no fragment of the file');
check(substr((string)$stored, 0, 4) === SealedFileContainer::MAGIC,
	'they are a sealed container');

$rows = new MultiMessageAttachment(array('message_id' => (int)$with_file->key));
$manifest = null;
foreach ($rows as $r) { $manifest = $r; }
check($manifest !== null && Messenger::isTrue($manifest->get('msa_is_sealed')),
	'and the manifest records that they are sealed');

$key = ConversationSealing::attachmentKey($attachment);
check($key !== null, 'a present member resolves the key that opens it');
check(SealedFileContainer::openBytes((string)$stored, $key) === $plain_bytes,
	'and the bytes come back byte-for-byte');

lock_everyone(array($alice, $bob, $carol));
check(ConversationSealing::attachmentKey($attachment) === null,
	'with nobody present there is no key, so the bytes stay closed');
open_window($alice);

// =====================================================================
section('adding a member grants the key; removing takes it away');

$room->add_participant($carol['id'], $alice['id']);
$grant = ConversationKeyGrant::forMember((int)$room->key, $carol['id']);
check($grant !== null, 'a member added to a sealed conversation is given the key');

lock_everyone(array($alice, $bob));
open_window($carol);
check((new Message((int)$first->key, TRUE))->get('msg_body') === 'said before protection',
	'and can read what was said before they arrived');

lock_everyone(array($carol));
open_window($alice);
$room->remove_participant($carol['id'], $alice['id']);
check(ConversationKeyGrant::forMember((int)$room->key, $carol['id']) === null,
	'removing a member deletes their grant');

lock_everyone(array($alice, $bob));
open_window($carol);
$locked = false;
try { (new Message((int)$first->key, TRUE))->get('msg_body'); }
catch (VaultLockedException $e) { $locked = true; }
check($locked, 'and from then on their window opens nothing here');
lock_everyone(array($carol));

// =====================================================================
section('a member with no vault cannot be added to a sealed conversation');

open_window($alice);
$refused = '';
try { $room->add_participant((int)$novault->key, $alice['id']); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'protection') !== false, 'the refusal explains why');
check(!$room->has_participant((int)$novault->key),
	'and they are not added at all, rather than added and unable to read');

// =====================================================================
section('a key rotation re-wraps the grants and every message still opens');

$new_kp = sodium_crypto_box_keypair();
$new_public = SealedBox::b64url(sodium_crypto_box_publickey($new_kp));
$new_secret = SealedBox::b64url(sodium_crypto_box_secretkey($new_kp));

$before = ConversationKeyGrant::forMember((int)$room->key, $alice['id']);
$before_wrapped = (string)$before->get('ckg_wrapped_key');

$result = ConversationKeyGrant::resealForUser(
	$alice['id'], $alice['secret'], 1, $new_public, 2);
check($result['failed'] === 0, 'every grant re-wraps');
check($result['attempted'] >= 1, 'and there was something to re-wrap');

$after = ConversationKeyGrant::forMember((int)$room->key, $alice['id']);
check((string)$after->get('ckg_wrapped_key') !== $before_wrapped, 'the wrapping changed');
check((int)$after->get('ckg_key_generation') === 2, 'onto the new generation');

lock_everyone(array($alice));
VaultUnlock::open($alice['id'], $new_secret, UserEncryptionVault::SCOPE_USER,
	array('idle' => null, 'absolute' => null));
check((new Message((int)$first->key, TRUE))->get('msg_body') === 'said before protection',
	'and the old messages still open under the new key');

// =====================================================================
section('protection only tightens');

$refused = '';
try { $room->raise(ProtectionLevel::STANDARD, $alice['id']); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'not lowered') !== false, 'lowering is refused, in those words');
check($room->protection_level() === ProtectionLevel::PRIVATE_, 'and the level did not move');

$room->raise(ProtectionLevel::GUARDED, $alice['id']);
check($room->protection_level() === ProtectionLevel::GUARDED, 'raising Private to Guarded works');
check((new Message((int)$first->key, TRUE))->get('msg_body') === 'said before protection',
	'and does not disturb what was already sealed');

$refused = '';
try { $room->raise('fortress', $alice['id']); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'Fortress is not a level a conversation can have');

// =====================================================================
section('Guarded keeps message content out of notifications');

require_once(PathHelper::getIncludePath('data/notifications_class.php'));
$db = DbConnector::get_instance()->get_db_link();
// A token that appears ONLY in the message, so a hit anywhere in the
// notification means content leaked — the group's name is metadata and is
// allowed to travel ("New message in Ski Trip" is the shape the spec asks for).
$secret_word = 'ZEBRAFISH' . strtoupper(LibraryFunctions::random_string(5));
$room->add_participant($bob['id'], $alice['id']);   // re-add for a recipient
$room->add_message($alice['id'], 'the ' . $secret_word . ' surprise party');

$q = $db->prepare("SELECT ntf_title, ntf_body FROM ntf_notifications
                   WHERE ntf_usr_user_id = ? ORDER BY ntf_notification_id DESC LIMIT 1");
$q->execute(array($bob['id']));
$note = $q->fetch(PDO::FETCH_ASSOC);
check(is_array($note), 'the other member is still told there is something new');
check(strpos((string)$note['ntf_body'], $secret_word) === false,
	'but the notification carries no fragment of what was said');
check(strpos((string)$note['ntf_title'], $secret_word) === false, 'nor does its title');
check(strpos((string)$note['ntf_title'], 'Sealed ' . $suffix) !== false,
	'while the group name still travels, so the member knows where to look');

// =====================================================================
section('a conversation cannot be created already protected');

$refused = '';
try {
	Conversation::create_conversation(array($alice['id'], $bob['id']), null,
		array('protection_level' => ProtectionLevel::PRIVATE_));
} catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'ceremony') !== false,
	'protection is a ceremony, not a column that can be set at insert');

lock_everyone(array($alice, $bob, $carol));
harness_finish();
