<?php
/** @joinery-test
 * name: messenger_federation
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Chat across instances (specs/implemented/joinery_messenger.md § Cross-instance messaging).
 *
 * Chat is Joinery Direct's second payload kind, so almost nothing about the wire
 * is chat's to get wrong: signatures, freshness, replay, size bounds, rate
 * limits and hash verification are the framework's and are already pinned by the
 * Direct estate. A kind is two pure functions, and this drives those two
 * directly — which is the honest unit of test for a handler.
 *
 * What is pinned:
 *
 *  - an arriving message finds or creates the local conversation by its
 *    cross-instance identity, and stores the sender as an address rather than
 *    minting a shadow account;
 *  - the same message arriving twice stores once;
 *  - a conversation guid is bound to the peer who sent it, so one peer cannot
 *    land a message in a conversation with somebody else;
 *  - a declined delivery is discarded locally, never bounced — the sender was
 *    already told `accept`, and any other answer would make the endpoint a way
 *    to probe whose contacts you are in;
 *  - reactions and deletes cross; a delete only touches the sender's own message;
 *  - an address that publishes no capability record reads as not-chat-reachable,
 *    with email offered rather than silently substituted;
 *  - the sender-side ladder of specs/messenger_reachability_states.md: S2
 *    (Direct off) and S3 (no identity) offer no contacts, an identity opens
 *    the address book, S4 is a member-level absence not a site one; and R1 —
 *    an exact local address names its member, partials never do, and an
 *    own-site address opens a LOCAL conversation, never a wire.
 *
 * Run: php tests/run.php db --filter=messenger_federation
 *
 * @version 1.2
 * @changelog 1.2 - Sender-side ladder (S2-S4) and R1 local resolution
 * @changelog 1.1 - People picker contacts section
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));
require_once(PathHelper::getIncludePath('data/conversations_class.php'));
require_once(PathHelper::getIncludePath('data/conversation_remote_peers_class.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));
require_once(PathHelper::getIncludePath('data/message_reactions_class.php'));
require_once(PathHelper::getIncludePath('plugins/messenger/includes/ChatDirectHandler.php'));
require_once(PathHelper::getIncludePath('plugins/messenger/includes/MessengerFederation.php'));

$suffix = strtoupper(LibraryFunctions::random_string(6));
$bob = make_user('FedB' . $suffix);   // the local member things arrive for

$handler = new ChatDirectHandler();

/** An envelope as the framework hands one to a kind: already verified. */
function chat_envelope(string $sender, int $recipient_user_id): DirectEnvelope {
	return DirectEnvelope::fromVerified(array(
		'kind'              => MessengerFederation::KIND,
		'sender'            => $sender,
		'sender_domain'     => DirectProtocol::domainOf($sender),
		'recipient'         => 'bob@receiver.test',
		'recipient_user_id' => $recipient_user_id,
		'recipient_alias_id' => 0,
		'nonce'             => bin2hex(random_bytes(16)),
		'timestamp'         => gmdate('Y-m-d H:i:s'),
	));
}

/** The parts of a chat delivery, in the shape the wire produces. */
function chat_parts(array $header, ?string $body = null): array {
	$out = array();
	foreach (MessengerFederation::buildParts($header, $body) as $spec) {
		$out[] = new DirectPart($spec);
	}
	return $out;
}

/** Find the conversation a guid landed in for one member, or null. */
function fed_conversation(string $guid, int $user_id): ?Conversation {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare(
		'SELECT cnv.cnv_conversation_id FROM cnv_conversations cnv
		 JOIN cnp_conversation_participants cnp
		   ON cnp.cnp_cnv_conversation_id = cnv.cnv_conversation_id AND cnp.cnp_usr_user_id = ?
		 WHERE cnv.cnv_guid = ? AND cnv.cnv_delete_time IS NULL LIMIT 1');
	$q->execute(array($user_id, $guid));
	$row = $q->fetch(PDO::FETCH_ASSOC);
	return $row ? new Conversation((int)$row['cnv_conversation_id'], TRUE) : null;
}

function fed_bodies(Conversation $c): array {
	$out = array();
	$rows = new MultiMessage(array('conversation_id' => (int)$c->key, 'message_type' => 'text'));
	foreach ($rows as $m) { $out[] = (string)$m->get('msg_body'); }
	return $out;
}

$alice_address = 'alice' . strtolower($suffix) . '@sender.test';
$cnv_guid = Conversation::mint_guid();
$msg_guid = Conversation::mint_guid();

// =====================================================================
section('a first message from a peer opens a conversation on this side');

$header = array(
	'type'        => MessengerFederation::TYPE_MESSAGE,
	'cnv_guid'    => $cnv_guid,
	'msg_guid'    => $msg_guid,
	'sent_time'   => gmdate('Y-m-d H:i:s'),
	'sender_name' => 'Alice Remote',
);
$handler->ingest(chat_envelope($alice_address, (int)$bob->key),
	chat_parts($header, 'hello from the other side'), true);

$conversation = fed_conversation($cnv_guid, (int)$bob->key);
check($conversation !== null, 'the conversation exists locally');
if ($conversation) {
	harness_register_row('cnv_conversations', 'cnv_conversation_id', (int)$conversation->key);
	check(fed_bodies($conversation) === array('hello from the other side'), 'and holds the message');
	check($conversation->participant_user_ids() === array((int)$bob->key),
		'with exactly one local participant — the recipient');
	check($conversation->is_federated(), 'and it knows it crosses instances');

	$peers = $conversation->remote_peers();
	check(isset($peers[$alice_address]), 'the sender is recorded as a remote peer');
	check($peers[$alice_address] === 'Alice Remote', 'with the name they gave');

	$sender_ids = array();
	$rows = new MultiMessage(array('conversation_id' => (int)$conversation->key, 'message_type' => 'text'));
	foreach ($rows as $m) {
		$sender_ids[] = $m->get('msg_usr_user_id_sender');
		check(strtolower((string)$m->get('msg_remote_sender_address')) === $alice_address,
			'the message is attributed to the sending address');
	}
	check($sender_ids === array(null), 'and to no local user — no shadow account was minted');
}

// =====================================================================
section('the same message arriving twice stores once');

$handler->ingest(chat_envelope($alice_address, (int)$bob->key),
	chat_parts($header, 'hello from the other side'), true);
check(count(fed_bodies($conversation)) === 1, 'a replayed delivery does not duplicate the message');

// =====================================================================
section('a second message lands in the same conversation');

$second_guid = Conversation::mint_guid();
$handler->ingest(chat_envelope($alice_address, (int)$bob->key),
	chat_parts(array('type' => 'message', 'cnv_guid' => $cnv_guid, 'msg_guid' => $second_guid),
		'and another'), true);
check(count(fed_bodies($conversation)) === 2, 'the thread grows rather than forking');

// =====================================================================
section('a conversation guid belongs to the peer who sent it');

$impostor = 'mallory' . strtolower($suffix) . '@elsewhere.test';
$handler->ingest(chat_envelope($impostor, (int)$bob->key),
	chat_parts(array('type' => 'message', 'cnv_guid' => $cnv_guid, 'msg_guid' => Conversation::mint_guid()),
		'let me in'), true);

check(count(fed_bodies($conversation)) === 2,
	'another peer reusing the same guid does not land in that conversation');

$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare('SELECT crp_cnv_conversation_id FROM crp_conversation_remote_peers WHERE crp_address = ?');
$q->execute(array($impostor));
$rows = $q->fetchAll(PDO::FETCH_COLUMN);
check(count($rows) === 1, 'they get a conversation of their own instead');
if ($rows) {
	harness_register_row('cnv_conversations', 'cnv_conversation_id', (int)$rows[0]);
	check((int)$rows[0] !== (int)$conversation->key, 'and it is a different one');
}

// =====================================================================
section('a declined delivery is discarded here, never answered on the wire');

$declined_guid = Conversation::mint_guid();
$handler->ingest(chat_envelope('stranger@nowhere.test', (int)$bob->key),
	chat_parts(array('type' => 'message', 'cnv_guid' => $declined_guid,
		'msg_guid' => Conversation::mint_guid()), 'let me in too'), false);

check(fed_conversation($declined_guid, (int)$bob->key) === null,
	'a non-contact\'s message stores nothing at all');
$stored = new MultiMessage(array('guid' => $declined_guid));
check($stored->count() === 0, 'and leaves no message behind');

// =====================================================================
section('reactions cross, attributed to the address that sent them');

$handler->ingest(chat_envelope($alice_address, (int)$bob->key),
	chat_parts(array(
		'type'     => MessengerFederation::TYPE_REACTION,
		'cnv_guid' => $cnv_guid,
		'msg_guid' => $msg_guid,
		'emoji'    => '👍',
	)), true);

$local_message = null;
$rows = new MultiMessage(array('guid' => $msg_guid));
foreach ($rows as $m) { $local_message = $m; }
check($local_message !== null, 'the reacted-to message is here');

$reactions = new MultiMessageReaction(array('message_id' => (int)$local_message->key));
$found = null;
foreach ($reactions as $r) { $found = $r; }
check($found !== null, 'the reaction was stored');
check($found !== null && (string)$found->get('msr_emoji') === '👍', 'with the emoji sent');
check($found !== null && $found->get('msr_usr_user_id') === null,
	'and no local user id — the reactor is on another site');
check($found !== null && strtolower((string)$found->get('msr_remote_address')) === $alice_address,
	'attributed to their address');

// =====================================================================
section('a delete only touches the sender\'s own message');

// Bob's own reply, which the far side must not be able to delete.
$bob_message = $conversation->add_message((int)$bob->key, 'mine, thanks');
$handler->ingest(chat_envelope($alice_address, (int)$bob->key),
	chat_parts(array(
		'type'     => MessengerFederation::TYPE_DELETE,
		'cnv_guid' => $cnv_guid,
		'msg_guid' => (string)$bob_message->get('msg_guid'),
	)), true);
$bob_message->load();
check(!$bob_message->get('msg_delete_time'),
	'a peer cannot delete a message they did not send');

$handler->ingest(chat_envelope($alice_address, (int)$bob->key),
	chat_parts(array(
		'type'     => MessengerFederation::TYPE_DELETE,
		'cnv_guid' => $cnv_guid,
		'msg_guid' => $msg_guid,
	)), true);
$local_message->load();
check((bool)$local_message->get('msg_delete_time'),
	'but they can delete their own, and it becomes a tombstone');

// =====================================================================
section('what goes on the wire says everything in its parts');

$outbound_header = MessengerFederation::messageHeader($conversation, $bob_message);
$parts = MessengerFederation::buildParts($outbound_header, 'body text');

check($parts[0]['role'] === DirectProtocol::ROLE_HEADERS,
	'the chat metadata rides as a header part, not as new envelope fields');
check($parts[0]['content_type'] === MessengerFederation::HEADERS_CONTENT_TYPE,
	'under chat\'s own content type');
$decoded = json_decode($parts[0]['bytes'], true);
check($decoded['cnv_guid'] === (string)$conversation->get('cnv_guid'),
	'carrying the conversation identity');
check($decoded['msg_guid'] === (string)$bob_message->get('msg_guid'), 'and the message identity');
check($parts[1]['role'] === DirectProtocol::ROLE_BODY_TEXT, 'the words ride as the body part');
check($parts[1]['bytes'] === 'body text', 'unchanged');

$reply = $conversation->add_message((int)$bob->key, 'quoting',
	array('reply_to_message_id' => (int)$bob_message->key));
$reply_header = MessengerFederation::messageHeader($conversation, $reply);
check($reply_header['reply_to_guid'] === (string)$bob_message->get('msg_guid'),
	'a reply names its parent by the identity both sides share, not by a local row id');

// =====================================================================
section('an unreachable address reads as unreachable, and offers email');

$reach = MessengerFederation::reachability('someone@definitely-not-a-joinery-site.invalid');
check($reach['reachable'] === false, 'an address with no capability record is not chat-reachable');
check(strpos(strtolower($reach['reason']), 'email') !== false,
	'and the member is offered the honest alternative rather than given one');

$reach = MessengerFederation::reachability('not-an-address');
check($reach['reachable'] === false, 'and neither is something that is not an address');


section('the picker\'s sender-side ladder: S2, S3, ready (spec: messenger_reachability_states)');

require_once(PathHelper::getIncludePath('plugins/messenger/logic/messenger_people_logic.php'));
require_once(PathHelper::getIncludePath('plugins/messenger/logic/messenger_action_logic.php'));
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));

/**
 * Flip a Direct setting for this run — the row AND the settings singleton's
 * in-memory copy, because get_setting() caches what it has already read
 * (same device as tests/direct/joinery_direct_receive_test.php).
 */
function fed_test_set(string $name, ?string $value): void {
	$db = DbConnector::get_instance()->get_db_link();
	if ($value === null) {
		$db->prepare('DELETE FROM stg_settings WHERE stg_name = ?')->execute(array($name));
	} else {
		$db->prepare("INSERT INTO stg_settings (stg_name, stg_value, stg_create_time, stg_update_time)
			VALUES (?, ?, now(), now()) ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value")
			->execute(array($name, $value));
	}
	$prop = new ReflectionProperty('Globalvars', 'settings');
	$prop->setAccessible(true);
	$live = $prop->getValue(Globalvars::get_instance());
	if ($value === null) { unset($live[$name]); } else { $live[$name] = $value; }
	$prop->setValue(Globalvars::get_instance(), $live);
}

if (!PluginHelper::isPluginActive('mailbox')) {
	harness_skip('the picker ladder', 'mailbox plugin inactive here (S1)');
} else {
	$db = DbConnector::get_instance()->get_db_link();

	// The enabled flag goes back exactly as found, whatever happens mid-test.
	$enabled_before = $db->query("SELECT stg_value FROM stg_settings WHERE stg_name = 'joinery_direct_enabled'")->fetchColumn();
	harness_defer(function () use ($enabled_before) {
		fed_test_set('joinery_direct_enabled', $enabled_before === false ? null : (string)$enabled_before);
	});

	// A mailbox of Bob's own to hold the contact.
	$domain = new InboundEmailDomain(NULL);
	$domain->set('ied_domain', 'picker-' . strtolower($suffix) . '.example');
	$domain->set('ied_is_enabled', true);
	$domain->save();
	$domain_id = (int)$domain->key;
	harness_defer(function () use ($db, $domain_id) {
		$db->prepare('DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = ?')->execute(array($domain_id));
	});

	$alias = new InboundEmailAlias(NULL);
	$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
	$alias->set('iea_alias', 'picker' . strtolower($suffix));
	$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
	$alias->set('iea_is_enabled', true);
	$alias->prepare();
	$alias->save();
	$alias_id = (int)$alias->key;
	harness_defer(function () use ($db, $alias_id) {
		$db->prepare('DELETE FROM iea_inbound_email_aliases WHERE iea_inbound_email_alias_id = ?')->execute(array($alias_id));
	});

	$grant = new InboundEmailMailboxGrant(NULL);
	$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
	$grant->set('ieg_usr_user_id', (int)$bob->key);
	$grant->save();
	$grant_id = (int)$grant->key;
	harness_defer(function () use ($db, $grant_id) {
		$db->prepare('DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_inbound_email_mailbox_grant_id = ?')->execute(array($grant_id));
	});

	$store = new MailboxContacts();
	check($store->manualAdd((int)$bob->key, 'Remote Tester <remote.tester@far-away.example>', $alias_id),
		'the contact lands in the address book');
	$bob_id = (int)$bob->key;
	harness_defer(function () use ($db, $bob_id) {
		$db->prepare('DELETE FROM imc_mailbox_contacts WHERE imc_usr_user_id = ?')->execute(array($bob_id));
	});

	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION = array('usr_user_id' => $bob_id, 'loggedin' => true, 'permission' => 1);

	// ---- S2: Direct off. The address book is full; the picker offers nothing.
	fed_test_set('joinery_direct_enabled', '0');
	check(MessengerFederation::siteReady() === false, 'S2: Direct off is not site-ready');
	$s2 = messenger_people_logic(array('q' => 'remote.tes'));
	check(empty($s2->data['contacts']), 'S2: with Direct off the picker offers no contacts');

	// ---- S3: Direct on, but no signing identity for this run's domain set.
	fed_test_set('joinery_direct_enabled', '1');
	$identities_before = (int)$db->query('SELECT count(*) FROM jdi_direct_identities WHERE jdi_is_active')->fetchColumn();
	if ($identities_before > 0) {
		harness_skip('S3', 'this deployment already holds a signing identity');
	} else {
		check(MessengerFederation::siteReady() === false, 'S3: enabled but identity-less is not site-ready');
		$s3 = messenger_people_logic(array('q' => 'remote.tes'));
		check(empty($s3->data['contacts']), 'S3: still no contacts before an identity exists');
	}

	// ---- Ready: mint an identity for the fixture domain. Now the site can
	// speak, and the address book surfaces.
	DirectSigningIdentity::ensureFor('picker-' . strtolower($suffix) . '.example');
	DirectSigningIdentity::resetForTests();
	harness_defer(function () use ($db, $suffix) {
		$db->prepare('DELETE FROM jdi_direct_identities WHERE jdi_domain = ?')
		   ->execute(array('picker-' . strtolower($suffix) . '.example'));
	});
	check(MessengerFederation::siteReady() === true, 'an identity makes the site ready');

	$by_address = messenger_people_logic(array('q' => 'remote.tes'));
	check(in_array('remote.tester@far-away.example',
		array_column($by_address->data['contacts'] ?? array(), 'address'), true),
		'a partial address finds the contact', $by_address->error ?? '');

	$by_name = messenger_people_logic(array('q' => 'remote test'));
	check(in_array('remote.tester@far-away.example',
		array_column($by_name->data['contacts'] ?? array(), 'address'), true),
		'a case-insensitive name match finds it too', $by_name->error ?? '');

	$miss = messenger_people_logic(array('q' => 'nobody-by-that-name'));
	check(empty($miss->data['contacts']), 'a non-matching term offers nothing');

	// The address book is the member's own: the same search as somebody else
	// must come back empty.
	$alice = make_user('FedA' . $suffix);
	$alice_id = (int)$alice->key;
	$_SESSION['usr_user_id'] = $alice_id;
	$other = messenger_people_logic(array('q' => 'remote.tes'));
	check(empty($other->data['contacts']), 'another member never sees them');

	// ---- S4: the site is ready, but Alice holds no mailbox on a signable
	// domain — the definition of "cannot send", which the picker explains at
	// pick time rather than by hiding her contacts.
	check(MessengerFederation::addressFor($alice_id) === null,
		'S4: a member without a signable mailbox has no sending address');
	check(MessengerFederation::addressFor($bob_id) !== null,
		'while the mailbox holder has one');


	section('R1: an address on this site resolves internally, never over the wire');

	$bob_address = 'picker' . strtolower($suffix) . '@picker-' . strtolower($suffix) . '.example';

	// The exact typed address names Bob in the people search (via_address).
	$as_alice = messenger_people_logic(array('q' => $bob_address));
	$resolved = null;
	foreach (($as_alice->data['people'] ?? array()) as $person) {
		if (!empty($person['via_address'])) { $resolved = $person; }
	}
	check($resolved !== null && (int)$resolved['user_id'] === $bob_id,
		'an exact typed address resolves to the member behind the mailbox');

	$partial = messenger_people_logic(array('q' => substr($bob_address, 0, 12)));
	$leaked = false;
	foreach (($partial->data['people'] ?? array()) as $person) {
		if ((int)$person['user_id'] === $bob_id) { $leaked = true; }
	}
	check($leaked === false, 'a partial address never matches a member — no enumeration');

	// Reachability answers "local member", and open_remote opens the LOCAL
	// conversation rather than putting an own-site address on the wire.
	$reach = messenger_action_logic(array('action' => 'reachability', 'address' => $bob_address));
	check(($reach->data['local_member']['user_id'] ?? 0) === $bob_id,
		'reachability names the local member', json_encode($reach->data));

	$opened = messenger_action_logic(array('action' => 'open_remote', 'address' => $bob_address));
	$conversation_id = (int)($opened->data['conversation_id'] ?? 0);
	check($conversation_id > 0, 'open_remote with a local address opens a conversation', $opened->error ?? '');
	harness_defer(function () use ($db, $conversation_id) {
		if ($conversation_id <= 0) { return; }
		$db->prepare('DELETE FROM msg_messages WHERE msg_cnv_conversation_id = ?')->execute(array($conversation_id));
		$db->prepare('DELETE FROM cnp_conversation_participants WHERE cnp_cnv_conversation_id = ?')->execute(array($conversation_id));
		$db->prepare('DELETE FROM cnv_conversations WHERE cnv_conversation_id = ?')->execute(array($conversation_id));
	});
	$peer_rows = (int)$db->query('SELECT count(*) FROM crp_conversation_remote_peers WHERE crp_cnv_conversation_id = ' . $conversation_id)->fetchColumn();
	check($peer_rows === 0, 'and that conversation is local — no remote peer row');

	$again = messenger_action_logic(array('action' => 'open_remote', 'address' => $bob_address));
	check((int)($again->data['conversation_id'] ?? 0) === $conversation_id,
		'opening the same address again resumes the same conversation');

	// An own-domain address that maps to no mailbox is email-only (R1 → R3).
	$nobody = messenger_action_logic(array('action' => 'reachability',
		'address' => 'nobody-here@picker-' . strtolower($suffix) . '.example'));
	check(($reach_ok = ($nobody->data['reachable'] ?? null)) === false,
		'an own-domain address with no member behind it is not chat-reachable');
	check(strpos(strtolower((string)($nobody->data['reason'] ?? '')), 'email') !== false,
		'and offers email instead');


	section('S6: an identity in the database is not a publication in DNS');

	// Bob's domain holds a minted identity but publishes nothing — the fixture
	// domain has no DNS at all. A pick must answer with OUR state, before any
	// recipient lookup happens.
	$_SESSION['usr_user_id'] = $bob_id;
	$s6 = messenger_action_logic(array('action' => 'reachability', 'address' => 'someone@far-away.example'));
	check(($s6->data['reachable'] ?? null) === false,
		'an unpublished sender is not offered chat, however reachable the other side');
	check(strpos((string)($s6->data['reason'] ?? ''), 'not published') !== false,
		'and the reason names OUR missing DNS records, not theirs', (string)($s6->data['reason'] ?? ''));

	$s6_open = messenger_action_logic(array('action' => 'open_remote', 'address' => 'someone@far-away.example'));
	check($s6_open->error !== null && strpos((string)$s6_open->error, 'not published') !== false,
		'open_remote refuses with the same reason', (string)$s6_open->error);

	// The send path enforces the same thing below every surface.
	$blocked = JoineryDirect::send('someone@far-away.example', 'chat',
		array(array('role' => DirectProtocol::ROLE_BODY_TEXT, 'content_type' => 'text/plain', 'bytes' => 'hi')),
		array('sender' => $bob_address));
	check($blocked->status === DirectSendResult::NO_CAPABILITY
		&& strpos($blocked->detail, 'not published') !== false,
		'JoineryDirect::send() refuses before the wire for an unpublished sender',
		$blocked->status . ' ' . $blocked->detail);
}

harness_finish();
