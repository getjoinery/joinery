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
 *  - protection only ever tightens;
 *  - Nothing leaves unsealed (the add-on on Private) blanks notifications and
 *    makes federation sealed-or-nothing, turns on only, and is recorded;
 *  - the shared picker shows the add-on switch only under Private;
 *  - the fold migration moves the old middle rung to Private + the add-on,
 *    and running it again changes nothing.
 *
 * Run: php tests/run.php db --filter=messenger_sealed
 *
 * @version 1.2 - Nothing leaves unsealed add-on, picker add-ons, fold migration
 * @version 1.1 - permanent delete of a room removes its key grants (the FK rule, exercised)
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
	vault_fixture_open_window($member['id'], $member['secret'],
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
harness_register_model('File', (int)$attachment->key);
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
	$alice['id'], vault_fixture_key($alice['secret']), 1, $new_public, 2);
check($result['failed'] === 0, 'every grant re-wraps');
check($result['attempted'] >= 1, 'and there was something to re-wrap');

$after = ConversationKeyGrant::forMember((int)$room->key, $alice['id']);
check((string)$after->get('ckg_wrapped_key') !== $before_wrapped, 'the wrapping changed');
check((int)$after->get('ckg_key_generation') === 2, 'onto the new generation');

lock_everyone(array($alice));
vault_fixture_open_window($alice['id'], $new_secret, UserEncryptionVault::SCOPE_USER,
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

$refused = '';
try { $room->raise('guarded', $alice['id']); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'the old middle rung is not a level any more');
check($room->protection_level() === ProtectionLevel::PRIVATE_, 'and the level did not move');
check(!in_array('guarded', ProtectionLevel::ORDER, true), 'the ladder has three rungs');
check((new Message((int)$first->key, TRUE))->get('msg_body') === 'said before protection',
	'and nothing already sealed was disturbed');

$refused = '';
try { $room->raise('fortress', $alice['id']); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'Fortress is not a level a conversation can have');

// =====================================================================
section('plain Private: notifications may preview, federation may fall back');

require_once(PathHelper::getIncludePath('data/notifications_class.php'));
require_once(PathHelper::getIncludePath('plugins/messenger/includes/MessengerFederation.php'));
$db = DbConnector::get_instance()->get_db_link();

function latest_note_for(int $user_id) {
	$q = DbConnector::get_instance()->get_db_link()->prepare(
		"SELECT ntf_title, ntf_body FROM ntf_notifications
		  WHERE ntf_usr_user_id = ? ORDER BY ntf_notification_id DESC LIMIT 1");
	$q->execute(array($user_id));
	return $q->fetch(PDO::FETCH_ASSOC);
}

$room->add_participant($bob['id'], $alice['id']);   // re-add for a recipient
check(!$room->sealed_exits_only(), 'a Private conversation starts without the add-on');

$plain_word = 'OKAPI' . strtoupper(LibraryFunctions::random_string(5));
$room->add_message($alice['id'], 'the ' . $plain_word . ' plan');
$note = latest_note_for($bob['id']);
check(is_array($note) && strpos((string)$note['ntf_body'], $plain_word) !== false,
	'without the add-on the notification previews the message');
check(MessengerFederation::sendOptions($room, 'alice@example.test')['require_sealed'] === false,
	'and federation does not demand sealing');

// =====================================================================
section('Nothing leaves unsealed: only on Private, only by a participant');

$standard_room = msgr_track(Conversation::create_conversation(array($alice['id'], $bob['id']),
	'Plain ' . $suffix, array('admin_user_id' => $alice['id'])));
$refused = '';
try { $standard_room->turn_on_sealed_exits_only($alice['id']); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'Private first') !== false, 'a Standard conversation is told to go Private first');
$standard_room->load();
check(!$standard_room->sealed_exits_only(), 'and nothing changed');

$outsider = make_user('SealO' . $suffix);
$refused = '';
try { $room->turn_on_sealed_exits_only((int)$outsider->key); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'not in this conversation') !== false, 'an outsider cannot turn it on');

// =====================================================================
section('Nothing leaves unsealed: turned on, recorded, one-way');

$systems_before = (int)$db->query("SELECT COUNT(*) FROM msg_messages WHERE msg_cnv_conversation_id = "
	. (int)$room->key . " AND msg_message_type = 'system'")->fetchColumn();
$room->turn_on_sealed_exits_only($alice['id']);
$room->load();
check($room->sealed_exits_only(), 'the add-on is on');
check($room->protection_level() === ProtectionLevel::PRIVATE_, 'and the level is still Private');

$q = $db->prepare("SELECT msg_message_id FROM msg_messages WHERE msg_cnv_conversation_id = ?
                    AND msg_message_type = 'system' ORDER BY msg_message_id DESC LIMIT 1");
$q->execute(array((int)$room->key));
$record_id = (int)$q->fetchColumn();
$systems_after = (int)$db->query("SELECT COUNT(*) FROM msg_messages WHERE msg_cnv_conversation_id = "
	. (int)$room->key . " AND msg_message_type = 'system'")->fetchColumn();
check($systems_after === $systems_before + 1, 'turning it on writes one system message');
check(strpos((string)raw_body($record_id)['msg_body'], 'v1.aead.') === 0, 'sealed like the rest of the room');
check(strpos((new Message($record_id, TRUE))->get('msg_body'), 'turned on Nothing leaves unsealed') !== false,
	'and it says who turned it on and what');

$room->turn_on_sealed_exits_only($bob['id']);
$systems_again = (int)$db->query("SELECT COUNT(*) FROM msg_messages WHERE msg_cnv_conversation_id = "
	. (int)$room->key . " AND msg_message_type = 'system'")->fetchColumn();
check($systems_again === $systems_after, 'turning it on again is a no-op');

$refused = '';
try { $room->set('cnv_sealed_exits_only', false); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'not off') !== false, 'turning it off is refused at the column');
$room->load();
check($room->sealed_exits_only(), 'and it stays on');

check(MessengerFederation::sendOptions($room, 'alice@example.test')['require_sealed'] === true,
	'federation now demands sealing (no unencrypted fallback)');

// =====================================================================
section('Nothing leaves unsealed keeps message content out of notifications');

// A token that appears ONLY in the message, so a hit anywhere in the
// notification means content leaked — the group's name is metadata and is
// allowed to travel ("New message in Ski Trip" is the shape the spec asks for).
$secret_word = 'ZEBRAFISH' . strtoupper(LibraryFunctions::random_string(5));
$room->add_message($alice['id'], 'the ' . $secret_word . ' surprise party');

$note = latest_note_for($bob['id']);
check(is_array($note), 'the other member is still told there is something new');
check(strpos((string)$note['ntf_body'], $secret_word) === false,
	'but the notification carries no fragment of what was said');
check(strpos((string)$note['ntf_title'], $secret_word) === false, 'nor does its title');
check(strpos((string)$note['ntf_title'], 'Sealed ' . $suffix) !== false,
	'while the group name still travels, so the member knows where to look');

$payload = Messenger::conversationPayload($room, $alice['id']);
check($payload['sealed_exits_only'] === true, 'the conversation payload carries the add-on');
check($payload['protection_summary'] === 'Private · Nothing leaves unsealed',
	'and the chip text names the level with its add-on');

// =====================================================================
section('the picker shows add-ons only under Private');

function render_picker(string $field, array $options): string {
	$fw = new FormWriterV2HTML5($field . '_form');
	ob_start();
	ProtectionLevelPicker::render($fw, $field, $options);
	return (string)ob_get_clean();
}

$base = array('service' => ProtectionLevelPicker::SERVICE_MESSAGING, 'levels' => Conversation::LEVELS);
$html = render_picker('pk_std', $base + array('value' => 'standard',
	'addons' => array(ProtectionLevelPicker::ADDON_SEALED_EXITS_ONLY => array('checked' => false))));
check(strpos($html, 'name="pk_std_sealed_exits_only"') !== false, 'the add-on is a submitted field');
check(strpos($html, 'role="switch"') !== false, 'drawn as a switch');
check(strpos($html, 'Extra protection') !== false, 'under the Extra protection heading');
check(strpos($html, 'crosses to another server unencrypted') !== false
	&& strpos($html, 'can&#039;t be reached') !== false, 'with what it protects and what it costs');
check(strpos($html, '"private":{"show":["pk_std_addons"]}') !== false, 'selecting Private shows it');
check(strpos($html, '"standard":{"hide":["pk_std_addons"]}') !== false, 'selecting Standard hides it');
check(strpos($html, 'id="pk_std_addons" class="jy-level-addons" style="display:none"') !== false,
	'and it starts hidden while Standard is selected');
check(stripos($html, 'guarded') === false, 'no card for the old middle rung');

$html = render_picker('pk_prv', $base + array('value' => 'private',
	'addons' => array(ProtectionLevelPicker::ADDON_SEALED_EXITS_ONLY => array('checked' => true, 'disabled' => true))));
check(strpos($html, 'id="pk_prv_addons" class="jy-level-addons">') !== false, 'with Private selected it starts visible');
check(strpos($html, 'Extra protection on: Nothing leaves unsealed.') !== false,
	'and the Private card lists the add-ons that are on');

$html = render_picker('pk_none', $base + array('value' => 'private'));
check(strpos($html, 'pk_none_addons') === false && strpos($html, 'role="switch"') === false,
	'a consumer that passes no add-ons gets none');

// =====================================================================
section('the fold migration: old middle rung to Private + the add-on, idempotent');

require_once(PathHelper::getIncludePath('migrations/messenger_sealed_exits_only_fold.php'));

$legacy = msgr_track(Conversation::create_conversation(array($alice['id'], $bob['id']),
	'Legacy ' . $suffix, array('admin_user_id' => $alice['id'])));
// The rotation section above re-wrapped grants onto a new key without
// changing the vault's public key, so a fresh raise wraps to the original one:
// open Alice's window with the matching secret.
lock_everyone(array($alice));
open_window($alice);
$legacy->raise(ProtectionLevel::PRIVATE_, $alice['id']);
// The row as an older release stored it — straight to the table, since the
// model no longer knows the value.
$q = $db->prepare("UPDATE cnv_conversations SET cnv_protection_level = 'guarded', cnv_sealed_exits_only = false
                    WHERE cnv_conversation_id = ?");
$q->execute(array((int)$legacy->key));

// Between new code going live and the migration running, the old value must
// read as Private + the add-on — never Standard (its content is sealed).
$legacy->load();
check($legacy->protection_level() === ProtectionLevel::PRIVATE_, 'an unmigrated row reads as Private');
check($legacy->is_sealed(), 'and as sealed');
check($legacy->sealed_exits_only(), 'with Nothing leaves unsealed on');
check(MessengerFederation::sendOptions($legacy, 'alice@example.test')['require_sealed'] === true,
	'so federation demands sealing for it');
$hydrated = new Conversation((int)$legacy->key);
$hydrated->load_from_data($db->query("SELECT * FROM cnv_conversations WHERE cnv_conversation_id = "
	. (int)$legacy->key)->fetch(PDO::FETCH_OBJ), array_keys(Conversation::$field_specifications));
check($hydrated->protection_level() === ProtectionLevel::PRIVATE_ && $hydrated->sealed_exits_only(),
	'the same through a collection-style load');
$legacy_word = 'NARWHAL' . strtoupper(LibraryFunctions::random_string(5));
$legacy->add_message($alice['id'], 'the ' . $legacy_word . ' secret');
$note = latest_note_for($bob['id']);
check(is_array($note) && strpos((string)$note['ntf_body'], $legacy_word) === false,
	'and its notifications carry no message text');
$refused = '';
try { $legacy->set('cnv_protection_level', 'guarded'); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'the legacy value is never written');
$refused = '';
try { $legacy->set('cnv_protection_level', ProtectionLevel::PRIVATE_); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'nor rewritten outside a raise (only the migration moves it)');
$legacy->load();

// Loading stored state is not a request: an unmigrated row loads, and loads
// again, through both load paths.
$legacy_row = $db->query("SELECT * FROM cnv_conversations WHERE cnv_conversation_id = "
	. (int)$legacy->key)->fetch(PDO::FETCH_OBJ);
$reloaded = new Conversation((int)$legacy->key);
$second_load = 'ok';
try {
	$reloaded->load_from_data($legacy_row, array_keys(Conversation::$field_specifications));
	$reloaded->load_from_data($legacy_row, array_keys(Conversation::$field_specifications));
	$reloaded->load();
	$reloaded->load();
} catch (Exception $e) { $second_load = get_class($e) . ': ' . $e->getMessage(); }
check($second_load === 'ok', 'an unmigrated row survives a second load (' . $second_load . ')');
check($reloaded->protection_level() === ProtectionLevel::PRIVATE_ && $reloaded->sealed_exits_only(),
	'and still reads as Private with the add-on');
$renamed = 'ok';
try {
	$reloaded->rename('Legacy renamed ' . $suffix, $alice['id']);
	$reloaded->load();
	$in_list = null;
	foreach (new MultiConversation(array('participant_user_id' => $alice['id'])) as $listed) {
		if ((int)$listed->key === (int)$legacy->key) { $in_list = $listed; }
	}
	if (!$in_list) { throw new Exception('not in the member\'s list'); }
	$in_list->load();
} catch (Exception $e) { $renamed = get_class($e) . ': ' . $e->getMessage(); }
check($renamed === 'ok', 'a group rename on an unmigrated row works (' . $renamed . ')');
check($db->query("SELECT cnv_protection_level FROM cnv_conversations WHERE cnv_conversation_id = "
	. (int)$legacy->key)->fetchColumn() === 'guarded', 'and leaves the stored level for the migration');
$legacy->load();

// The migration has nowhere to put the add-on without its column. Proven in a
// transaction that is rolled back, DDL included: it must fail, never record
// itself done while a row is still at the old value.
$db->beginTransaction();
$missing_column_result = 'did not run';
try {
	$db->exec("SET LOCAL lock_timeout = '5s'");
	$db->exec("ALTER TABLE cnv_conversations DROP COLUMN cnv_sealed_exits_only");
	ob_start();
	try { $missing_column_result = var_export(messenger_sealed_exits_only_fold(), true); }
	catch (Exception $e) { $missing_column_result = 'refused: ' . $e->getMessage(); }
	ob_end_clean();
} catch (Exception $e) {
	$missing_column_result = 'setup failed: ' . $e->getMessage();
} finally {
	$db->rollBack();
}
check(strpos($missing_column_result, 'refused: ') === 0 && strpos($missing_column_result, 'nowhere to go') !== false,
	'with the column missing and a row at the old value, the migration fails (' . $missing_column_result . ')');
check((bool)$db->query("SELECT count(1) FROM information_schema.columns WHERE table_name = 'cnv_conversations'
	AND column_name = 'cnv_sealed_exits_only'")->fetchColumn(), 'and the rollback restored the column');

harness_set_setting_mem('messenger_default_protection_level', 'guarded');
check(Messenger::defaultLevel() === ProtectionLevel::PRIVATE_, 'an unmigrated site default reads as Private');
check(Messenger::defaultSealedExitsOnly() === true, 'with the add-on default on');

$had_level_row  = get_setting_raw('messenger_default_protection_level');
$had_addon_row  = get_setting_raw('messenger_default_sealed_exits_only');
harness_defer(function () use ($had_level_row, $had_addon_row) {
	$db = DbConnector::get_instance()->get_db_link();
	if ($had_level_row !== null) { set_setting_raw('messenger_default_protection_level', $had_level_row); }
	if ($had_addon_row === null) {
		$db->prepare("DELETE FROM stg_settings WHERE stg_name = 'messenger_default_sealed_exits_only'")->execute();
	} else {
		set_setting_raw('messenger_default_sealed_exits_only', $had_addon_row);
	}
});
if ($had_level_row === null) {
	$db->prepare("INSERT INTO stg_settings (stg_name, stg_value) VALUES ('messenger_default_protection_level', 'guarded')")->execute();
	harness_defer(function () {
		DbConnector::get_instance()->get_db_link()
			->prepare("DELETE FROM stg_settings WHERE stg_name = 'messenger_default_protection_level'")->execute();
	});
} else {
	set_setting_raw('messenger_default_protection_level', 'guarded');
}

ob_start();
$first_run = messenger_sealed_exits_only_fold();
ob_end_clean();
check($first_run !== false, 'the migration runs');

$row = $db->query("SELECT cnv_protection_level, cnv_sealed_exits_only FROM cnv_conversations
                    WHERE cnv_conversation_id = " . (int)$legacy->key)->fetch(PDO::FETCH_ASSOC);
check($row['cnv_protection_level'] === 'private', 'the conversation is Private');
check($row['cnv_sealed_exits_only'] === true, 'with Nothing leaves unsealed on');
$legacy->load();
check($legacy->sealed_exits_only(), 'and the model reads it that way');
check(get_setting_raw('messenger_default_protection_level') === 'private', 'the site default is Private');
check(get_setting_raw('messenger_default_sealed_exits_only') === '1', 'with the add-on default on');

ob_start();
messenger_sealed_exits_only_fold();
$second_output = (string)ob_get_clean();
$row2 = $db->query("SELECT cnv_protection_level, cnv_sealed_exits_only FROM cnv_conversations
                     WHERE cnv_conversation_id = " . (int)$legacy->key)->fetch(PDO::FETCH_ASSOC);
check($row2 === $row, 'a second run leaves the conversation as it was');
check(strpos($second_output, 'no conversation at the old middle rung') !== false
	&& strpos($second_output, 'not at the old middle rung') !== false, 'and reports nothing to convert');
check((int)$db->query("SELECT COUNT(*) FROM cnv_conversations WHERE cnv_protection_level = 'guarded'")->fetchColumn() === 0,
	'no conversation is left at the old value');

// =====================================================================
section('a conversation cannot be created already protected');

$refused = '';
try {
	Conversation::create_conversation(array($alice['id'], $bob['id']), null,
		array('protection_level' => ProtectionLevel::PRIVATE_));
} catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'ceremony') !== false,
	'protection is a ceremony, not a column that can be set at insert');
foreach (array('guarded', 'privat', ProtectionLevel::FORTRESS) as $asked) {
	$refused = '';
	try {
		Conversation::create_conversation(array($alice['id'], $bob['id']), null,
			array('protection_level' => $asked));
	} catch (ConversationException $e) { $refused = $e->getMessage(); }
	check(strpos($refused, 'not a protection level') !== false,
		'asking to create at ' . $asked . ' is refused, never read as Standard');
}

// =====================================================================
section('the protection columns change only through their ceremonies');

// The generic REST PUT writes columns through set(); these are the writes it
// would make.
$plain = msgr_track(Conversation::create_conversation(array($alice['id'], $bob['id']),
	'Plain ' . $suffix, array('admin_user_id' => $alice['id'])));
$refused = '';
try { $plain->set('cnv_protection_level', ProtectionLevel::PRIVATE_); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'only by raising') !== false, 'a direct write of Private is refused');
$plain->load();
check($plain->protection_level() === ProtectionLevel::STANDARD, 'and the conversation stays Standard');
$plain->set('cnv_protection_level', ProtectionLevel::STANDARD);
check(true, 'writing the level it already has is not a change, and is allowed');

$fresh = new Conversation(NULL);
$refused = '';
try { $fresh->set('cnv_protection_level', ProtectionLevel::PRIVATE_); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'a new record cannot be written at Private');
$refused = '';
try { $fresh->set('cnv_sealed_exits_only', true); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'nor with Nothing leaves unsealed on');

lock_everyone(array($alice, $bob, $carol));
open_window($alice);
$plain->raise(ProtectionLevel::PRIVATE_, $alice['id']);
$plain->load();
check($plain->protection_level() === ProtectionLevel::PRIVATE_, 'raise() still moves it');
$refused = '';
try { $plain->set('cnv_sealed_exits_only', true); }
catch (ConversationException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'protection settings') !== false, 'a direct write turning the add-on on is refused');
$plain->load();
check(!$plain->sealed_exits_only(), 'and the add-on stays off');
$plain->turn_on_sealed_exits_only($alice['id']);
$plain->load();
check($plain->sealed_exits_only(), 'turn_on_sealed_exits_only() still turns it on');

// =====================================================================
section('permanent delete takes every key grant with it');

// A grant is a wrapped copy of the room key for one member. Once the room is
// gone the grants are keys to nothing, and keys to nothing must not outlive the
// thing they opened. The rule lives on ConversationKeyGrant::$foreign_key_actions
// (permanent_delete on the conversation); this proves the cascade actually runs.
$room_id = (int)$room->key;
$grants_before = (int)$db->query("SELECT COUNT(*) FROM ckg_conversation_key_grants WHERE ckg_cnv_conversation_id = " . $room_id)->fetchColumn();
check($grants_before > 0, 'the sealed room holds key grants (' . $grants_before . ')');
$room->permanent_delete();
$q = $db->prepare("SELECT COUNT(*) FROM cnv_conversations WHERE cnv_conversation_id = ?");
$q->execute(array($room_id));
check((int)$q->fetchColumn() === 0, 'the conversation row is gone');
$q = $db->prepare("SELECT COUNT(*) FROM ckg_conversation_key_grants WHERE ckg_cnv_conversation_id = ?");
$q->execute(array($room_id));
check((int)$q->fetchColumn() === 0, 'and every grant went with it');
check(count(new MultiConversationKeyGrant(array('conversation_id' => $room_id))) === 0,
	'the grant collection agrees');

lock_everyone(array($alice, $bob, $carol));
harness_finish();
