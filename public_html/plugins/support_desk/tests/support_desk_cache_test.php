<?php
/** @joinery-test
 * name: support_desk_cache
 * tier: live
 * env: dev-only
 * needs: []
 * timeout: 600
 */
/**
 * The standing check (specs/support_chatbot.md, The prompt, in three layers):
 * two turns of one thread against the model the support desk really answers
 * with, and the second reads from the provider's cache. A change to prompt
 * assembly that puts something per-turn above a breakpoint fails here rather
 * than on the bill.
 *
 * Live tier: it spends real tokens on the configured model (on dev, the local
 * model). A thread of the public widget's kind, so no site's facts are
 * involved; removed afterwards.
 *
 * Run: php plugins/support_desk/tests/support_desk_cache_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('The second turn reads the fixed prefix from cache');

if (!class_exists('SupportTurn') || !SupportDocRelease::releases()) {
	check(false, 'the support desk is not active here, or nothing is indexed');
	harness_finish();
	return;
}
try {
	$resolution = SupportTurn::resolve();
} catch (\Throwable $e) {
	check(false, 'no model resolves for the support desk: ' . $e->getMessage());
	harness_finish();
	return;
}

SupportIntake::$spawn = false;
list($thread) = SupportIntake::createThread(SupportThread::SURFACE_WIDGET, array('ip' => '127.0.0.1', 'title' => 'cache standing check'));
harness_defer(function () use ($thread) {
	$t = new SupportThread((int)$thread->key, TRUE);
	if ($t->key) { $t->permanent_delete(); }
});

$usage = array();
foreach (array('In one sentence: what does the backups document cover?', 'And in one sentence: what is a recovery key?') as $q) {
	$out = SupportIntake::addQuestion($thread, $q);
	$row = new AiConversationMessage((int)$out['message_id'], TRUE);
	$usage[] = SupportTurn::runAndFinalize($thread, $row, $resolution);
	$row->load();
	check((string)$row->get('aim_status') === 'complete', 'turn answered on ' . $resolution->modelId(), (string)$row->get('aim_error'));
}
check($usage[1] !== null && $usage[1]['cache_read_tokens'] > 0,
	'the second turn read ' . (int)($usage[1]['cache_read_tokens'] ?? 0) . ' tokens from cache', json_encode($usage));

harness_finish();
