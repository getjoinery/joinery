<?php
/** @joinery-test
 * name: message_read_scope
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Message::authenticate_read() — the REST per-record read gate admits staff and
 * anyone in the message's conversation (specs/group_sends_one_row.md §3.6, B6).
 * A group participant who neither sent a message nor is named on it reads it;
 * a non-participant does not.
 *
 * Run: php tests/run.php db --filter=message_read_scope
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/conversations_class.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));

function mrs_can_read($message, $uid, $permission = 0) {
	try {
		$message->authenticate_read(array('current_user_id' => $uid, 'current_user_permission' => $permission));
		return true;
	} catch (SystemAuthenticationError $e) {
		return false;
	}
}

try {
	$alice = make_user('MrsAlice');
	$bob = make_user('MrsBob');
	$carol = make_user('MrsCarol');
	$dave = make_user('MrsDave');

	section('Group conversation');
	$conversation = Conversation::create_conversation(array($alice->key, $bob->key, $carol->key), 'HarnessTest read scope',
		array('admin_user_id' => $alice->key));
	harness_register_model('Conversation', $conversation->key);
	$message = $conversation->add_message($alice->key, 'hello, room');
	check($message instanceof Message && $message->key > 0, 'Alice posted a message');

	check(mrs_can_read($message, $alice->key), 'the sender reads it');
	check(mrs_can_read($message, $bob->key), 'a participant who did not send it reads it');
	check(mrs_can_read($message, $carol->key), 'so does the third participant');
	check(!mrs_can_read($message, $dave->key), 'a non-participant is refused');
	check(mrs_can_read($message, $dave->key, 5), 'staff read it');

	section('AI owner scope');
	check(Message::$ai_owner_field === 'msg_usr_user_id_sender', 'the AI owner field is the sender column alone');

} catch (\Throwable $e) {
	check(false, 'no exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
