<?php
/** @joinery-test
 * name: support_desk_engine
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * The support desk answers from a release's documents through joinery_ai's
 * loop, and nobody but the site that opened a thread can speak in it
 * (specs/support_chatbot.md, WP1).
 *
 * Pins: splitting Markdown by heading (fences, the title, long sections);
 * the full-text index expression; search with the any-word fallback and the
 * FAQ ahead of a document; reading a section; the fixed prefix byte-identical
 * across threads on one release and the thread's facts after it, with the
 * one-hour breakpoint first and the default one after; a new thread refused
 * unless its host confirms the nonce; a later call refused without the
 * thread's token, from another host, or without the key it was opened with;
 * a turn that searches, reads and answers, written onto its row with its
 * trace and tokens; a tool the turn was not offered refused by the loop;
 * escalate handing the thread to a person; the per-site budget; the local FAQ
 * entering and leaving the index; thumbs-down landing on the gap report.
 *
 * The model is a scripted stand-in and the origin callback a stub client;
 * nothing leaves this box. The fixture release is built from a scratch tree
 * and removed afterwards, with every thread, transcript and entry it made.
 *
 * Run: php plugins/support_desk/tests/support_desk_engine_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/llm_fixtures.php'));

if (!class_exists('SupportDocIndex')) {
	section('Support desk engine');
	check(true, 'skipped: the support_desk plugin is not active on this site (nothing to test)');
	harness_finish();
	return;
}

/** A scripted model that keeps what each call was sent. */
class SdeRecordingProvider extends FakeLlmProvider {
	public $sent = array();
	private $responses;
	public function __construct(array $responses) { $this->responses = $responses; }
	public function createMessageStreamed(array $params, callable $onTextDelta, ?callable $shouldAbort = null): array {
		$this->calls++;
		$this->sent[] = $params;
		$resp = array_shift($this->responses) ?? self::textResponse('(script ran out)');
		foreach (($resp['content'] ?? array()) as $b) {
			if (($b['type'] ?? '') === 'text') { $onTextDelta((string)$b['text']); }
		}
		return $resp;
	}
}

/** The asking site, as the origin callback sees it. */
class SdeOriginClient extends SafeHttpClient {
	public $confirm = true;
	/** @var string|null the desk the site issued its code for; null is this node */
	public $destination = null;
	public $asked = array();
	public function post(string $url, string $body, array $headers = array()): SafeHttpResponse {
		$this->asked[] = array($url, $body);
		$data = $this->confirm ? array('confirmed' => true, 'version' => SDE_RELEASE,
			'destination' => $this->destination ?? ServicesClient::host()) : array('confirmed' => false);
		return new SafeHttpResponse(200, array(), json_encode(array('success' => true, 'data' => $data)), $url);
	}
}

const SDE_RELEASE = '9.9.901';
const SDE_HOST = 'support-fixture.example.org';

function sde_tool_use($id, $name, array $input) {
	return array('stop_reason' => 'tool_use', 'content' => array(array('type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input)),
		'usage' => array('input_tokens' => 100, 'output_tokens' => 20, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0));
}

$db = DbConnector::get_instance()->get_db_link();
$cleanup_release = function () use ($db) {
	$db->prepare('DELETE FROM sds_doc_sections WHERE sds_release = ?')->execute(array(SDE_RELEASE));
	$db->prepare('DELETE FROM sdr_doc_releases WHERE sdr_release = ?')->execute(array(SDE_RELEASE));
};
$cleanup_release();
harness_defer($cleanup_release);
$threads_made = array();
harness_defer(function () use (&$threads_made) {
	foreach ($threads_made as $id) {
		$t = new SupportThread($id, TRUE);
		if ($t->key) { $t->permanent_delete(); }
	}
});

$client = new SdeOriginClient();
SupportIntake::useClient($client);
SupportIntake::$spawn = false;
harness_set_setting_mem('support_desk_notify_email', '');
harness_set_setting_mem('support_desk_daily_tokens_per_host', '0');
harness_set_setting_mem('support_desk_daily_tokens_total', '0');

// ---------------------------------------------------------------------------
section('Markdown splits by heading');

$md = "# Backups\n\nIntro line about backups.\n\n## Restore\n\nHow to restore.\n\n```\n# not a heading\n```\n\n### From backup storage\n\nPick a run.\n\n## Empty\n\n## Keys\n\nKeep the key.\n";
$s = SupportDocIndex::sectionsOf('docs/backups.md', $md);
$headings = array_column($s, 'heading');
check($headings === array('Backups', 'Backups > Restore', 'Backups > Restore > From backup storage', 'Backups > Keys'),
	'heading chains, the title first, an empty section left out', json_encode($headings));
check(strpos($s[1]['body'], '# not a heading') !== false, 'a heading inside a code fence stays text');
$long = "# T\n\n" . implode("\n\n", array_fill(0, 30, str_repeat('word ', 60)));
$parts = SupportDocIndex::sectionsOf('docs/t.md', $long);
check(count($parts) > 1 && max(array_map(function ($p) { return mb_strlen($p['body']); }, $parts)) <= SupportDocIndex::SECTION_CAP,
	'a long section is split at paragraphs under the cap, heading repeated', count($parts) . ' parts');
check(SupportDocSection::$index_specifications[1] === FullTextSearch::indexSpec('sds_search_text'),
	'the declared index is exactly the query expression');

// ---------------------------------------------------------------------------
section('A release is indexed once from its tree');

$tree = harness_scratch_dir('support_tree');
@mkdir($tree . '/public_html/docs/faq', 0700, true);
@mkdir($tree . '/public_html/plugins/fixtureplugin/docs', 0700, true);
file_put_contents($tree . '/public_html/docs/backups.md', $md);
file_put_contents($tree . '/public_html/docs/email_system.md', "# Email\n\nHow mail is sent.\n\n## Sender domain\n\nVerify the sender domain with DKIM records before sending.\n");
file_put_contents($tree . '/public_html/docs/faq/mail.md', "# Mail\n\n## Why does my site say the sender domain is not verified?\n\nAdd the DKIM and SPF records the Email step shows, then press Check again.\n");
file_put_contents($tree . '/public_html/plugins/fixtureplugin/docs/overview.md', "# Fixture plugin\n\n## Zebra crossings\n\nStripes.\n");
file_put_contents($tree . '/public_html/plugins/fixtureplugin/plugin.json', '{"name": "Fixture plugin"}');
@mkdir($tree . '/public_html/plugins/keptplugin/docs', 0700, true);
file_put_contents($tree . '/public_html/plugins/keptplugin/docs/overview.md', "# Kept plugin\n\n## Okapi\n\nKept to this node.\n");
file_put_contents($tree . '/public_html/plugins/keptplugin/plugin.json', '{"name": "Kept plugin", "included_in_publish": false}');
$r = SupportDocIndex::buildFromTree(SDE_RELEASE, $tree . '/public_html');
check($r['built'] && $r['sections'] >= 6, 'built from the tree', $r['message']);
$again = SupportDocIndex::buildFromTree(SDE_RELEASE, $tree . '/public_html');
check(!$again['built'], 'a release is built once and never again', $again['message']);
$rel = SupportDocRelease::forRelease(SDE_RELEASE);
check($rel && strpos((string)$rel->get('sdr_map'), 'docs/backups.md') !== false
	&& strpos((string)$rel->get('sdr_map'), 'plugins/fixtureplugin/docs/overview.md') !== false,
	'the map names core and plugin documents');
check(strpos((string)$rel->get('sdr_map'), 'plugins/keptplugin/') === false,
	'a plugin that does not go out with releases is not indexed');
check(strpos((string)$rel->get('sdr_map'), 'docs/faq/mail.md') === false
	&& strpos((string)$rel->get('sdr_faq'), 'sender domain is not verified') !== false,
	'the FAQ is carried whole, not as a map line');
list($picked, $exact) = SupportDocIndex::releaseFor(SDE_RELEASE);
check($picked === SDE_RELEASE && $exact, 'a site on an indexed release reads its own');
list($picked2, $exact2) = SupportDocIndex::releaseFor('9.9.999');
check($picked2 !== null && !$exact2, 'a site on an unindexed release reads the nearest, and is told so', (string)$picked2);

// ---------------------------------------------------------------------------
section('Search and read');

$hits = SupportDocIndex::search(SDE_RELEASE, 'sender domain verified');
$top_two = array_slice($hits, 0, 2);
check(in_array('faq', array_column($top_two, 'kind'), true) && in_array('docs/email_system.md', array_column($top_two, 'path'), true),
	'the FAQ question and the document both come first', json_encode(array_column($hits, 'path')));
$any = SupportDocIndex::search(SDE_RELEASE, 'zebra unicorn');
check(count($any) === 1 && $any[0]['path'] === 'plugins/fixtureplugin/docs/overview.md', 'no all-words hit falls back to any word');
$sec = SupportDocIndex::readSection(SDE_RELEASE, 'docs/backups.md', 'Restore');
check($sec && $sec['found'] && strpos($sec['text'], 'How to restore') !== false, 'a section reads by its last heading');
$miss = SupportDocIndex::readSection(SDE_RELEASE, 'docs/backups.md', 'Nope');
check($miss && !$miss['found'] && in_array('Backups > Keys', $miss['headings'], true), 'a wrong heading lists the real ones');

// ---------------------------------------------------------------------------
section('A new thread proves its origin');

$ask = array('site' => array('host' => SDE_HOST, 'version' => SDE_RELEASE), 'message' => 'How do I restore a backup?',
	'route' => '/admin/admin_backups', 'consent' => array('bundle' => true, 'live_reads' => false),
	'origin_nonce' => str_repeat('n', 24),
	'bundle' => array('format' => 1, 'site' => array('host' => SDE_HOST, 'version' => SDE_RELEASE)));
$client->confirm = false;
try {
	SupportIntake::ask($ask, array(), '203.0.113.9');
	check(false, 'an unconfirmed host is refused');
} catch (SupportRefusal $e) {
	check($e->reason === 'unconfirmed', 'an unconfirmed host is refused outright', $e->getMessage());
}
$no_nonce = $ask;
unset($no_nonce['origin_nonce']);
try {
	SupportIntake::ask($no_nonce, array(), '203.0.113.9');
	check(false, 'a new thread without the site\'s code is refused');
} catch (SupportRefusal $e) {
	check($e->reason === 'unconfirmed', 'a new thread without the site\'s code is refused', $e->getMessage());
}
$client->confirm = true;
$client->destination = 'another-desk.example.net';
try {
	SupportIntake::ask($ask, array(), '203.0.113.9');
	check(false, 'a code issued for another desk is refused');
} catch (SupportRefusal $e) {
	check($e->reason === 'unconfirmed' && strpos($e->getMessage(), 'another-desk.example.net') !== false,
		'a code the site issued for another desk opens nothing here', $e->getMessage());
}
$client->destination = null;
$first = SupportIntake::ask($ask, array(), '203.0.113.9');
$threads_made[] = $first['thread_id'];
check($first['thread_id'] > 0 && strlen($first['token'] ?? '') >= 32 && $first['message_id'] > 0,
	'a confirmed host gets a thread, its token and a running answer');
check(strpos($client->asked[count($client->asked) - 1][0], 'https://' . SDE_HOST . '/api/v1/action/support_origin_confirm') === 0,
	'the callback goes to the claimed host');
$thread = new SupportThread($first['thread_id'], TRUE);
check((string)$thread->get('sth_token_hash') !== $first['token'] && $thread->tokenOpens($first['token']), 'only the hash of the token is kept');

foreach (array(
	'no token'      => array($first['thread_id'], '', SDE_HOST),
	'wrong token'   => array($first['thread_id'], str_repeat('a', 48), SDE_HOST),
	'another host'  => array($first['thread_id'], $first['token'], 'other.example.org'),
) as $label => $args) {
	try {
		SupportIntake::openThread($args[0], $args[1], $args[2], null);
		check(false, 'refused: ' . $label);
	} catch (SupportRefusal $e) {
		check($e->reason === 'no_thread', 'a later call is refused with ' . $label);
	}
}

// ---------------------------------------------------------------------------
section('The prompt, in three layers');

$second = SupportIntake::ask(array_merge($ask, array('message' => 'Another thread', 'origin_nonce' => str_repeat('m', 24))), array(), '203.0.113.9');
$threads_made[] = $second['thread_id'];
$thread2 = new SupportThread($second['thread_id'], TRUE);

// A thread an account opened: that account's key opens it, with no token (a
// reinstalled site); without the key, or with another account's, nothing does.
$t2_token = (string)$second['token'];
SupportThread::updateColumns((int)$thread2->key, array('sth_account_user_id' => 424242));
$acct_key = array('key_id' => 1, 'user_id' => 424242);
$opened = SupportIntake::openThread((int)$thread2->key, '', SDE_HOST, $acct_key);
check((int)$opened->key === (int)$thread2->key, 'the account\'s key opens its thread with no token');
foreach (array(
	'the token but no key'       => array($t2_token, null),
	'another account\'s key'     => array('', array('key_id' => 2, 'user_id' => 434343)),
	'the key and a wrong token'  => array(str_repeat('a', 48), $acct_key),
) as $label => $args) {
	try {
		SupportIntake::openThread((int)$thread2->key, $args[0], SDE_HOST, $args[1]);
		check(false, 'refused: ' . $label);
	} catch (SupportRefusal $e) {
		check($e->reason === 'no_thread', 'an account\'s thread is refused with ' . $label);
	}
}
SupportThread::updateColumns((int)$thread2->key, array('sth_account_user_id' => null));
$tools = SupportTools::forThread($thread);
$b1 = SupportPrompt::systemBlocks($thread, SDE_RELEASE, true, $tools);
$b2 = SupportPrompt::systemBlocks($thread2, SDE_RELEASE, true, SupportTools::forThread($thread2));
check($b1[0]['text'] === $b2[0]['text'], 'the fixed prefix is byte-identical for two threads on one release');
check(($b1[0]['cache_control'] ?? null) === array('type' => 'ephemeral', 'ttl' => '1h')
	&& ($b1[1]['cache_control'] ?? null) === array('type' => 'ephemeral'),
	'the prefix carries the one-hour breakpoint, the thread\'s facts the default one');
check(strpos($b1[0]['text'], (string)$thread->get('sth_nonce')) === false && strpos($b1[0]['text'], SDE_HOST) === false,
	'nothing of the thread is above the first breakpoint');
check($b1[1]['text'] !== $b2[1]['text'] && strpos($b1[1]['text'], UntrustedEnvelope::open((string)$thread->get('sth_nonce'))) !== false,
	'the thread\'s facts sit after it, inside the thread\'s envelope');
check(strpos($b1[0]['text'], 'sender domain is not verified') !== false && strpos($b1[0]['text'], 'docs/backups.md') !== false,
	'the prefix carries the map and the FAQ');
check(!array_intersect($tools, SupportNodeTools::WORDS) && in_array('search_docs', $tools, true) && in_array('escalate', $tools, true),
	'a thread from a host not joined here is offered no reads');

// ---------------------------------------------------------------------------
section('A turn searches, reads and answers');

$assistant = new AiConversationMessage($first['message_id'], TRUE);
$provider = new SdeRecordingProvider(array(
	sde_tool_use('t1', 'search_docs', array('query' => 'restore backup')),
	sde_tool_use('t2', 'fetch_url', array('url' => 'https://evil.example/x')),
	sde_tool_use('t3', 'read_doc_section', array('path' => 'docs/backups.md', 'heading' => 'Restore')),
	array('stop_reason' => 'end_turn', 'content' => array(array('type' => 'text', 'text' => 'Open Backups and pick a run (docs/backups.md > Restore).')),
		'usage' => array('input_tokens' => 50, 'output_tokens' => 30, 'cache_creation_input_tokens' => 10, 'cache_read_input_tokens' => 400)),
));
SupportTurn::runAndFinalize($thread, $assistant, $provider->resolution('fake/support-model'));
$assistant->load();
check((string)$assistant->get('aim_status') === 'complete', 'the row finishes complete', (string)$assistant->get('aim_error'));
check(strpos((string)$assistant->get('aim_content'), 'docs/backups.md > Restore') !== false, 'the answer is on the row');
$trace = json_decode((string)$assistant->get('aim_tool_calls'), true);
$names = array_column($trace, 'name');
check($names === array('search_docs', 'fetch_url', 'read_doc_section'), 'the trace keeps every call', json_encode($names));
check(!empty($trace[1]['is_error']) && $trace[1]['output'] === 'refused: not offered in this run', 'a tool the turn was not offered is refused by the loop');
check(strpos((string)$trace[0]['output'], 'docs/backups.md') !== false, 'the search found the section');
check((int)$assistant->get('aim_input_tokens') === 3 * 100 + 50 && (int)$assistant->get('aim_output_tokens') === 3 * 20 + 30,
	'tokens are recorded', (int)$assistant->get('aim_input_tokens') . ' in');
check((int)$assistant->get('aim_cache_read_tokens') === 400 && (int)$assistant->get('aim_cache_write_tokens') === 10,
	'cache reads and writes are recorded on their own columns');
check(SupportBudget::spentToday(null, null) >= 3 * 100 + 50 + 10 + 400 + 3 * 20 + 30,
	'the daily budget counts the cached tokens too');
$sent = $provider->sent[0];
check(count($sent['system']) === 2 && $sent['tools'][0]['name'] === 'search_docs', 'the model got both layers and the docs tools');
$last = end($sent['messages']);
check(is_array($last['content']) && ($last['content'][0]['cache_control'] ?? null) === array('type' => 'ephemeral')
	&& strpos($last['content'][0]['text'], '(Asked from the page /admin/admin_backups)') === 0,
	'the question carries its page and the conversation breakpoint');
check(SupportTurn::requirement()->thinkingLevel() === 'medium', 'the desk asks its model to reason at medium');
$thread->load();
check((int)$thread->get('sth_input_tokens') === 760 && (int)$thread->get('sth_output_tokens') === 90, 'the thread totals the turn');

// ---------------------------------------------------------------------------
section('Follow-up, poll, escalate');

// What a running turn wrote must survive a question that overlaps it, and the
// node is matched again from the host (here: none).
SupportThread::updateColumns((int)$thread->key, array('sth_node_reads' => 3, 'sth_node_id' => 999999));
$follow = SupportIntake::ask(array_merge($ask, array('thread_id' => $first['thread_id'], 'token' => $first['token'],
	'message' => 'It still fails.', 'origin_nonce' => null)), array(), '203.0.113.9');
$after = new SupportThread($first['thread_id'], TRUE);
check((int)$after->get('sth_node_reads') === 3 && (int)$after->get('sth_input_tokens') === 760,
	'a later question writes only its own columns: the turn\'s reads and tokens stand');
check(!$after->get('sth_node_id'), 'the joined node is matched again from the host on every question');
check($follow['thread_id'] === $first['thread_id'] && empty($follow['token']) && $follow['message_id'] > 0,
	'a later question continues the thread without a new token');
$poll = SupportIntake::poll($thread, $follow['message_id']);
check($poll['status'] === 'running', 'the poll shows it running');
$esc_row = new AiConversationMessage($follow['message_id'], TRUE);
$p2 = new SdeRecordingProvider(array(
	sde_tool_use('e1', 'escalate', array('reason' => 'restore fails after following the doc')),
	array('stop_reason' => 'end_turn', 'content' => array(array('type' => 'text', 'text' => 'I have handed this to a person.')), 'usage' => array()),
));
SupportTurn::runAndFinalize($thread, $esc_row, $p2->resolution('fake/support-model'));
$esc_row->load();
$thread->load();
check((string)$thread->get('sth_state') === SupportThread::STATE_WAITING, 'escalate leaves the thread waiting on a person');
check(strpos((string)$esc_row->get('aim_content'), SupportTurn::ESCALATED_SENTENCE) !== false, 'the answer ends with the fixed sentence');
$held = SupportIntake::ask(array_merge($ask, array('thread_id' => $first['thread_id'], 'token' => $first['token'],
	'message' => 'Any news?', 'origin_nonce' => null)), array(), '203.0.113.9');
check($held['message_id'] === 0 && $held['state'] === SupportThread::STATE_WAITING,
	'while it waits on a person, a message is kept for the person and no model turn starts');
$gaps = SupportGaps::unanswered();
check((bool)array_filter($gaps, function ($g) use ($first) { return $g['thread_id'] === $first['thread_id']; }),
	'the escalated turn is on the gap report');
SupportGaps::close($thread, 'docs commit abc123');
$still = array_filter(SupportGaps::unanswered(), function ($g) use ($first) { return $g['thread_id'] === $first['thread_id']; });
$thread->load();
check(!$still && (string)$thread->get('sth_gap_note') === 'docs commit abc123', 'closing it by naming what answers it takes it off');

// ---------------------------------------------------------------------------
section('Budgets');

harness_set_setting_mem('support_desk_daily_tokens_per_host', '100');
$callbacks_before = count($client->asked);
$threads_before = count(new MultiSupportThread(array('host' => SDE_HOST)));
try {
	SupportIntake::ask(array_merge($ask, array('message' => 'over budget', 'origin_nonce' => str_repeat('o', 24))), array(), '203.0.113.9');
	check(false, 'a site over its day is refused');
} catch (SupportRefusal $e) {
	check($e->reason === 'budget', 'a site over its day is refused, in words the panel shows', $e->getMessage());
}
check(count($client->asked) === $callbacks_before && count(new MultiSupportThread(array('host' => SDE_HOST))) === $threads_before,
	'refused before the call back: no callback, no empty thread');
// The first thread waits on a person (escalated above): a message for a person
// is kept even over the day, because budgets bound model turns only.
$kept = SupportIntake::ask(array_merge($ask, array('thread_id' => $first['thread_id'], 'token' => $first['token'],
	'message' => 'over budget too', 'origin_nonce' => null)), array(), '203.0.113.9');
check($kept['message_id'] === 0 && $kept['state'] === SupportThread::STATE_WAITING, 'a message for a person is kept over the day');
harness_set_setting_mem('support_desk_daily_tokens_per_host', '0');

// ---------------------------------------------------------------------------
section('Local FAQ enters and leaves the index');

$entry = new SupportFaqEntry(NULL);
$entry->set('sfq_question', 'How do I rotate the quokka key?');
$entry->set('sfq_answer', 'Open the quokka page and press Rotate.');
$entry->set('sfq_sth_support_thread_id', $first['thread_id']);
$entry->set('sfq_release', SDE_RELEASE);
$entry->save();
$entry->load();
harness_defer(function () use ($entry) { if ($entry->key) { $entry->permanent_delete(); } });
$hits = SupportDocIndex::search(SDE_RELEASE, 'quokka key');
check(count($hits) === 1 && $hits[0]['path'] === 'faq/local/' . (int)$entry->key && $hits[0]['kind'] === 'local_faq', 'a saved entry is found');
check(strpos(SupportPrompt::fixedPrefix($thread, SDE_RELEASE), 'quokka') !== false, 'and rides in the fixed prefix');
$entry->set('sfq_answer', 'Press Rotate on the wombat page.');
$entry->save();
check(count(SupportDocIndex::search(SDE_RELEASE, 'wombat')) === 1 && count(SupportDocIndex::search(SDE_RELEASE, 'quokka page Rotate')) <= 1,
	'an edit re-indexes it');
$entry->soft_delete();
check(count(SupportDocIndex::search(SDE_RELEASE, 'wombat')) === 0, 'a deleted entry leaves the index');

harness_finish();
