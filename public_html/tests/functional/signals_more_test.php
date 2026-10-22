<?php
/** @joinery-test
 * name: signals_more
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * The signals plugins and webhooks are told about (plugin_tiers.md WP6):
 * post.published, account.updated, message.received, file.uploaded.
 *
 * Each is observed the way a receiver sees it: a delivery row queued for a
 * receiver that wants it. The receiver is a sandboxed plugin's, for a plugin
 * that does not exist, so the minute's drain sends nothing anywhere.
 *
 * What is pinned here:
 *  - each is declared in the catalog;
 *  - post.published when a post first gets a publish time, and again when it
 *    is published after being taken down; never on a save that changes
 *    nothing about it;
 *  - account.updated naming the profile fields a save changed; never on a
 *    save that changes none;
 *  - message.received with who and where, and nothing of what it says; never
 *    for a system message;
 *  - file.uploaded for a public file a person uploaded; never for a private
 *    one, nor for bytes the site made itself.
 *
 * Run: php tests/run.php db --filter=signals_more
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();
$signals = array('post.published', 'account.updated', 'message.received', 'file.uploaded');

$receiver = new SignalReceiver(NULL);
$receiver->set('sgr_kind', SignalReceiver::KIND_SANDBOX);
$receiver->set('sgr_plugin', 'hts_' . bin2hex(random_bytes(3)));
$receiver->set('sgr_name', 'HarnessTest signals');
$receiver->set('sgr_signals', $signals);
$receiver->set('sgr_is_active', true);
$receiver->save();
$receiver->load();
harness_register_row('sgr_signal_receivers', 'sgr_signal_receiver_id', (int)$receiver->key);
SignalDeliveryQueue::reset_cache();

/** The payloads queued for the receiver since $after, for one signal. */
$queued = function (string $signal, int $after) use ($db, $receiver): array {
	$q = $db->prepare('SELECT sgd_payload FROM sgd_signal_deliveries
		WHERE sgd_sgr_signal_receiver_id = ? AND sgd_signal = ? AND sgd_signal_delivery_id > ? ORDER BY sgd_signal_delivery_id');
	$q->execute(array((int)$receiver->key, $signal, $after));
	return array_map(function ($p) { return json_decode((string)$p, true); }, $q->fetchAll(PDO::FETCH_COLUMN));
};
$mark = function () use ($db): int {
	return (int)$db->query('SELECT COALESCE(MAX(sgd_signal_delivery_id), 0) FROM sgd_signal_deliveries')->fetchColumn();
};

try {
	section('Declared');
	$catalog = SignalBus::signals();
	foreach ($signals as $signal) {
		check(isset($catalog[$signal]['label'], $catalog[$signal]['payload']), "$signal is in the catalog");
	}

	section('post.published');
	$author = make_user('SigAuthor', 5);
	$m = $mark();
	$post = new Post(NULL);
	$post->set('pst_title', 'HarnessTest signal post');
	$post->set('pst_link', 'harnesstest-signal-' . bin2hex(random_bytes(3)));
	$post->set('pst_usr_user_id', (int)$author->key);
	$post->set('pst_body', 'Body');
	$post->set('pst_published_time', 'NOW()');
	$post->save();
	$post->load();
	harness_register_row('pst_posts', 'pst_post_id', (int)$post->key);
	$got = $queued('post.published', $m);
	check(count($got) === 1 && ($got[0]['post_id'] ?? 0) === (int)$post->key && ($got[0]['author_user_id'] ?? 0) === (int)$author->key
		&& strpos((string)($got[0]['post_url'] ?? ''), $post->get('pst_link')) !== false, 'A post saved with a publish time is published', json_encode($got));
	$m = $mark();
	$post->set('pst_body', 'Edited');
	$post->save();
	check($queued('post.published', $m) === array(), 'Editing a published post publishes nothing');
	$post->set('pst_published_time', null);
	$post->save();
	$post->set('pst_published_time', 'NOW()');
	$post->save();
	check(count($queued('post.published', $m)) === 1, 'Taken down and published again, it is published again');

	section('account.updated');
	$member = make_user('SigMember', 0);
	$m = $mark();
	$fresh = new User((int)$member->key, TRUE);
	$fresh->set('usr_first_name', 'Renamed');
	$fresh->set('usr_timezone', 'Europe/Paris');
	$fresh->save();
	$got = $queued('account.updated', $m);
	check(count($got) === 1 && ($got[0]['user_id'] ?? 0) === (int)$member->key && ($got[0]['changed'] ?? '') === 'first_name,timezone',
		'A save that changes the profile names what changed', json_encode($got));
	$m = $mark();
	$fresh->save();
	$fresh->set('usr_lastlogin_time', gmdate('Y-m-d H:i:s'));
	$fresh->save();
	check($queued('account.updated', $m) === array(), 'A save that changes nothing of the profile says nothing');

	// A name keeps every character a person typed (it used to lose any
	// outside A-Z, which also made saves report changes nobody made).
	$names = array('José' => 'José', 'Zoë' => 'Zoë', 'Mary Ann' => 'Mary Ann', 'van der Berg' => 'van der Berg', '李' => '李',
		'smith' => 'Smith', 'o\'brien' => 'O\'Brien');
	foreach ($names as $typed => $kept) {
		$fresh->set('usr_first_name', $typed);
		$fresh->prepare();
		$fresh->save();
		$fresh = new User((int)$member->key, TRUE);
		check($fresh->get('usr_first_name') === $kept, "The name $typed is kept as $kept", (string)$fresh->get('usr_first_name'));
	}
	$m = $mark();
	$fresh->prepare();
	$fresh->save();
	check($queued('account.updated', $m) === array(), 'and saving it again changes nothing');

	// A copy loaded before another save names only what it changed itself, and
	// leaves the other save's change in place.
	$m = $mark();
	$earlier = new User((int)$member->key, TRUE);
	$elsewhere = new User((int)$member->key, TRUE);
	$elsewhere->set('usr_last_name', 'Elsewhere');
	$elsewhere->save();
	$earlier->set('usr_nickname', 'Nick');
	$earlier->save();
	$got = $queued('account.updated', $m);
	check(count($got) === 2 && ($got[1]['changed'] ?? '') === 'nickname',
		'A save through an earlier copy names only what it changed', json_encode($got));
	check((string)(new User((int)$member->key, TRUE))->get('usr_last_name') === 'Elsewhere',
		'and keeps the change saved since that copy was loaded');

	section('message.received');
	$other = make_user('SigOther', 0);
	$m = $mark();
	$conversation = Conversation::get_or_create_conversation((int)$member->key, (int)$other->key);
	harness_register_row('cnv_conversations', 'cnv_conversation_id', (int)$conversation->key);
	$message = $conversation->add_message((int)$member->key, 'A private word');
	$got = $queued('message.received', $m);
	check(count($got) === 1 && ($got[0]['message_id'] ?? 0) === (int)$message->key && ($got[0]['conversation_id'] ?? 0) === (int)$conversation->key
		&& ($got[0]['sender_user_id'] ?? 0) === (int)$member->key && ($got[0]['recipient_count'] ?? 0) === 1,
		'A message says who sent it, where, and to how many', json_encode($got));
	check(strpos(json_encode($got), 'private word') === false, 'and never what it says');
	check(($got[0]['protection'] ?? '') === 'standard', 'naming its protection, under which who and where are said');

	section('file.uploaded');
	$m = $mark();
	$tmp = tempnam(sys_get_temp_dir(), 'sig');
	file_put_contents($tmp, "public bytes\n");
	$public = File::createFromUpload($tmp, 'harnesstest-public.txt', 'text/plain', (int)$member->key);
	harness_register_row('fil_files', 'fil_file_id', (int)$public->key);
	$tmp = tempnam(sys_get_temp_dir(), 'sig');
	file_put_contents($tmp, "private bytes\n");
	$private = File::createFromUpload($tmp, 'harnesstest-private.txt', 'text/plain', (int)$member->key, array('fil_private' => true));
	harness_register_row('fil_files', 'fil_file_id', (int)$private->key);
	$made = File::createFromBytes("made bytes\n", 'harnesstest-made.txt', 'text/plain', (int)$member->key);
	harness_register_row('fil_files', 'fil_file_id', (int)$made->key);
	$got = $queued('file.uploaded', $m);
	check(count($got) === 1 && ($got[0]['file_id'] ?? 0) === (int)$public->key && ($got[0]['file_title'] ?? '') === 'harnesstest-public.txt'
		&& ($got[0]['owner_user_id'] ?? 0) === (int)$member->key, 'A public upload is announced, and only it: not a private one, not bytes the site made', json_encode($got));
	$m = $mark();
	$public->announce_upload();
	(new File((int)$public->key, TRUE))->announce_upload();
	check($queued('file.uploaded', $m) === array() && (new File((int)$public->key, TRUE))->get('fil_announced_time'), 'A file is announced once');

	// A file uploaded in Files gets its restrictions after it lands: it is
	// announced when its details are saved public, not before.
	$tmp = tempnam(sys_get_temp_dir(), 'sig');
	file_put_contents($tmp, "library bytes\n");
	$library = File::createFromUpload($tmp, 'harnesstest-library.txt', 'text/plain', (int)$member->key, array(), false);
	harness_register_row('fil_files', 'fil_file_id', (int)$library->key);
	check($queued('file.uploaded', $m) === array(), 'An upload held back is not announced at upload');
	$library->set('fil_min_permission', 5);
	$library->save();
	$library->announce_upload();
	check($queued('file.uploaded', $m) === array(), 'nor once it is restricted');
	$library->set('fil_min_permission', null);
	$library->save();
	$library->announce_upload();
	check(count($queued('file.uploaded', $m)) === 1, 'and is announced when it is saved public');

	section('What each signal carries');
	foreach (array('account.signup', 'account.updated', 'message.received', 'file.uploaded') as $signal) {
		check(trim((string)($catalog[$signal]['personal_data'] ?? '')) !== '', "$signal says what personal data it carries");
	}
	$described = SandboxConsent::describe(array('actions' => array(), 'records' => array(), 'signals' => array('account.updated'), 'schedules' => array(), 'outbound_hosts' => array()));
	check(strpos(implode(' ', $described['Be told when these happen'] ?? array()), 'email address') !== false, 'and an admin approving a plugin is shown it');
} catch (Throwable $e) {
	check(false, 'No exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

harness_finish();
