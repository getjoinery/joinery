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
 *    with email offered rather than silently substituted.
 *
 * Run: php tests/run.php db --filter=messenger_federation
 *
 * @version 1.0
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

harness_finish();
