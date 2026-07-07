<?php
/**
 * Phase 3 gate conversation fixtures (phase3_gate.sh): a permanent peer
 * user and a seeded 1:1 conversation the native conversations leg reads
 * and replies into. Idempotent — safe to run every gate invocation. The
 * peer is a dedicated, permanent test account (never created/destroyed
 * per run) so its display name stays stable across gate runs.
 *
 * Usage:
 *   php phase3_conversation_fixtures.php ensure <user_email>
 *
 * This script:
 *   1. ensures the peer user exists (phase3.peer@getjoinery.com — off the
 *      live inbound domain on purpose; nothing ever logs in as it, so its
 *      password is random and discarded)
 *   2. ensures a 1:1 conversation exists between the fixture user and the
 *      peer (Conversation::get_or_create_conversation)
 *   3. ensures the conversation has at least one message from the peer,
 *      so the inbox row renders with a preview
 *
 * Prints "peer=<id> conversation=<id> other_name=<display name>" on
 * success — other_name is last because it contains a space.
 *
 * @version 1.0.0
 */

require_once('/var/www/html/joinerytest/public_html/tests/functional/api/api_test_harness.php');
harness_require_debug_mode();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/conversations_class.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));

$cmd = $argv[1] ?? '';
if ($cmd !== 'ensure' || !isset($argv[2])) {
	fwrite(STDERR, "usage: php phase3_conversation_fixtures.php ensure <user_email>\n");
	exit(1);
}
$user_email = $argv[2];
$peer_email = 'phase3.peer@getjoinery.com';

$user = User::GetByEmail($user_email);
if (!$user || !$user->key) {
	fwrite(STDERR, "no user for $user_email\n");
	exit(1);
}

// 1. Peer user, if missing.
$peer = User::GetByEmail($peer_email);
if (!$peer || !$peer->key) {
	$peer = new User(NULL);
	$peer->set('usr_first_name', 'Phase3');
	$peer->set('usr_last_name', 'Peer');
	$peer->set('usr_email', $peer_email);
	$peer->set('usr_password', User::GeneratePassword(LibraryFunctions::random_string(24)));
	$peer->set('usr_permission', 0);
	$peer->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
	$peer->save();
	$peer->load();
}

// 2. The 1:1 conversation, if missing.
$conversation = Conversation::get_or_create_conversation($user->key, $peer->key);

// 3. At least one message from the peer, so the inbox row has a preview.
$messages = new MultiMessage(array('conversation_id' => $conversation->key));
$messages->load();
if (count($messages) === 0) {
	$conversation->add_message($peer->key, 'Phase 3 gate seed message');
}

echo 'peer=' . $peer->key . ' conversation=' . $conversation->key
	. ' other_name=' . $peer->display_name() . "\n";
?>
