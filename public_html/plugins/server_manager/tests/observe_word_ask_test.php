<?php
/** @joinery-test
 * name: observe_word_ask
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * Asking a node one observe word from off the node page (ObserveWordAsk), and
 * the API action a working session uses for it (server_manager/ask_node).
 *
 *   - The list holds observe words only: every word is a look (7) on the node
 *     page's own action levels, so an operate word cannot slip in.
 *   - build() refuses a word off the list and a value outside the builder's
 *     closed set, and refuses a node that does not report the word.
 *   - ask() queues one job on the node's list with who asked recorded, and a
 *     node that never answers comes back 'waiting', not failed.
 *   - ask_node is reachable only by a scoped machine key at the look level,
 *     and refuses an unknown slug, a word off the list, and a word the node
 *     does not report, before anything is queued.
 *   - SupportNodeTools narrows the same list and offers only what it holds.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/ask_node_logic.php'));

function owa_node(array $fields = array()) {
	$node = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$node->set('mgn_name', 'HarnessTest OWA ' . $suffix);
	$node->set('mgn_slug', 'harnessowa-' . $suffix);
	$node->set('mgn_host', '192.0.2.41');
	$node->set('mgn_web_root', '/var/www/html/owa/public_html');
	$node->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$node->set('mgn_agent_primitives', 'apply_update,check_status,host_report,site_log,schema_probe,restart_unit');
	$node->set('mgn_agent_log_access', 'on');
	foreach ($fields as $k => $v) { $node->set($k, $v); }
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}

function owa_refused(callable $fn) {
	try { $fn(); } catch (\Throwable $e) { return $e->getMessage(); }
	return '';
}

$node = owa_node();

// ---------------------------------------------------------------------------
section('The list holds observe words only');

$looks = SmActionLevels::PAGES['node_detail'];
$not_looks = array();
foreach (ObserveWordAsk::WORDS as $w) {
	if (($looks[$w] ?? 10) !== 7) { $not_looks[] = $w; }
}
check(!$not_looks, 'every ObserveWordAsk word is a look (7) on the node page', implode(', ', $not_looks));
check(!array_diff(SupportNodeTools::WORDS, ObserveWordAsk::WORDS),
	'the support desk list is a subset of the shared list', implode(', ', array_diff(SupportNodeTools::WORDS, ObserveWordAsk::WORDS)));

// ---------------------------------------------------------------------------
section('build(): the builder\'s closed sets, nothing else');

check(strpos(owa_refused(function () use ($node) { ObserveWordAsk::build($node, 'restart_unit', array('unit' => 'cron')); }),
	'not an observe word') !== false, 'an operate word is refused, though the node reports it');
check(owa_refused(function () use ($node) { ObserveWordAsk::build($node, 'site_log', array('file' => '/etc/shadow')); }) !== '',
	'a site_log file outside the compiled list is refused');
check(owa_refused(function () use ($node) { ObserveWordAsk::build($node, 'disk_usage', array()); }) !== '',
	'a word the node does not report is refused');
$built = ObserveWordAsk::build($node, 'host_report', array());
check(($built['primitive'] ?? null) === 'host_report', 'a reported observe word builds its own primitive', var_export($built, true));

check(ObserveWordAsk::offered($node) === array('host_report', 'check_status', 'site_log', 'schema_probe'),
	'offered() is the list intersected with what the node reported, in list order', implode(',', ObserveWordAsk::offered($node)));
check(SupportNodeTools::offered($node) === array('host_report', 'site_log', 'schema_probe'),
	'the support desk offers its narrower list', implode(',', SupportNodeTools::offered($node)));

// ---------------------------------------------------------------------------
section('ask(): one job, who asked recorded, a silent node is waiting');

$asked = ObserveWordAsk::ask($node, 'schema_probe', array('table' => 'usr_users'), 'harness:owa', null, 0);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $asked['job']->key);
check($asked['status'] === 'waiting', 'a node that has not answered comes back waiting', $asked['status']);
$params = json_decode((string)$asked['job']->get('mjb_parameters'), true) ?: array();
check(($params['requested_by'] ?? '') === 'harness:owa' && ($params['table'] ?? '') === 'usr_users',
	'the job records who asked beside the word\'s own parameters', var_export($params, true));
check((int)$asked['job']->get('mjb_mgn_managed_node_id') === (int)$node->key && $asked['job']->get('mjb_job_type') === 'schema_probe',
	'the job is on the node\'s own list, typed as the word');

// ---------------------------------------------------------------------------
section('ask_node: who may call it, and what it refuses before queueing');

$d = ask_node_logic_descriptor();
check(($d['auth']['requires_scoped_key'] ?? false) === true && ($d['auth']['requires_machine_key'] ?? false) === true
	&& ($d['auth']['min_user_permission'] ?? null) === 7,
	'only a scoped machine key, at the look level', var_export($d['auth'], true));

require_once(PathHelper::getIncludePath('plugins/server_manager/logic/mail_test_send_logic.php'));
$md = mail_test_send_logic_descriptor();
check(($md['auth']['requires_scoped_key'] ?? false) === true && ($md['auth']['min_user_permission'] ?? null) === 8,
	'mail_test_send: only a scoped machine key, at the change level', var_export($md['auth'], true));
check(!in_array('mail_test_send', ObserveWordAsk::WORDS, true), 'ask_node cannot reach the operate word');

function owa_jobs($node) {
	return count(new MultiManagementJob(array('node_id' => (int)$node->key)));
}
$before = owa_jobs($node);
$r = ask_node_logic(array('node' => 'no-such-node-' . bin2hex(random_bytes(3)), 'word' => 'host_report'));
check($r->error !== null && strpos((string)$r->error, 'No node') !== false, 'an unknown slug is refused', (string)$r->error);
$r = ask_node_logic(array('node' => $node->get('mgn_slug'), 'word' => 'restart_unit', 'params' => array('unit' => 'cron')));
check($r->error !== null && strpos((string)$r->error, 'not an observe word') !== false, 'an operate word is refused', (string)$r->error);
$r = ask_node_logic(array('node' => $node->get('mgn_slug'), 'word' => 'disk_usage'));
check($r->error !== null && strpos((string)$r->error, 'does not report') !== false, 'a word the node does not report is refused', (string)$r->error);
$r = ask_node_logic(array('node' => $node->get('mgn_slug'), 'word' => 'host_report', 'params' => 'not json'));
check($r->error !== null && strpos((string)$r->error, 'params') !== false, 'params that are not an object are refused', (string)$r->error);
check(owa_jobs($node) === $before, 'none of the refusals queued a job');

harness_finish();
