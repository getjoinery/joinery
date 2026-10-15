<?php
/** @joinery-test
 * name: support_desk_node_reads
 * tier: live
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * A support turn reads a joined site through its own agent, one job per read,
 * and only as far as the site allowed (specs/support_chatbot.md, WP3).
 *
 * Against dev's own joined node: a thread from its host is matched to the
 * node from the host alone; with live reads allowed it is offered the plane
 * tools and every allowed word the agent reports; node_overview reads the
 * plane's record; schema_probe becomes a job on the node's own list, created
 * by the support desk's reserved user with requester support:{thread}, and
 * its redacted answer comes back inside the thread's envelope; with live reads
 * not allowed no read is offered or run; the day's allowance stops the next
 * read; a word the loop refused as not offered is counted on the gap report.
 *
 * Live tier: it waits on the real agent (it polls every 15 s). The reads are
 * observe words and change nothing on the node.
 *
 * Run: php plugins/support_desk/tests/support_desk_node_reads_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('SupportNodeTools') || !SupportNodeTools::available()) {
	section('Support desk node reads');
	check(true, 'skipped: support_desk or server_manager is not active on this site');
	harness_finish();
	return;
}

$host = strtolower((string)preg_replace('#^https?://#', '', rtrim((string)Globalvars::get_instance()->get_setting('webDir'), '/')));
$node_id = SupportIntake::nodeIdForHost($host);
if (!$node_id) {
	section('Support desk node reads');
	check(true, 'skipped: this site (' . $host . ') is not joined to its own management node');
	harness_finish();
	return;
}
$release = SupportDocRelease::releases()[0] ?? null;
if ($release === null) {
	section('Support desk node reads');
	check(false, 'no release is indexed; run the support desk sync first');
	harness_finish();
	return;
}

$made = array();
harness_defer(function () use (&$made) {
	foreach ($made as $id) {
		$t = new SupportThread($id, TRUE);
		if ($t->key) { $t->permanent_delete(); }
	}
});
harness_set_setting_mem('support_desk_node_reads_per_thread', '20');

// ---------------------------------------------------------------------------
section('A joined host is matched from the host alone');

list($thread) = SupportIntake::createThread(SupportThread::SURFACE_SITE, array(
	'host' => $host, 'version' => $release, 'verdict' => 'verified', 'ip' => '127.0.0.1', 'title' => 'node reads fixture',
));
$made[] = (int)$thread->key;
check((int)$thread->get('sth_node_id') === $node_id, 'the thread is matched to node #' . $node_id);
check(!array_intersect(SupportTools::forThread($thread), SupportNodeTools::WORDS),
	'without live-read consent no read is offered');
$thread->set('sth_consent_bundle', true);
$thread->set('sth_consent_live_reads', true);
$thread->save();
$tools = SupportTools::forThread($thread);
check(in_array('node_overview', $tools, true) && in_array('schema_probe', $tools, true) && in_array('site_log', $tools, true),
	'with consent: the plane tools and the agent\'s words', implode(',', $tools));
$schema = SupportNodeTools::inputSchema('site_log');
check(($schema['properties']['file']['enum'] ?? null) === array_keys(JobCommandBuilder::SITE_LOG_FILES),
	'a word\'s schema is the plane\'s closed set');

$ctx = new SupportTurnContext($thread, 0, $release, true);

// ---------------------------------------------------------------------------
section('The plane\'s record');

$tool = RecipeToolRegistry::get('node_overview');
$out = (string)$tool->execute(array(), $ctx);
$node = new ManagedNode($node_id, TRUE);
check(strpos($out, 'Agent version: ' . $node->get('mgn_agent_version')) !== false
	&& strpos($out, UntrustedEnvelope::open((string)$thread->get('sth_nonce'))) !== false,
	'node_overview reads the record, inside the thread\'s envelope');

// ---------------------------------------------------------------------------
section('One read is one job on the node\'s own list');

$before = (int)DbConnector::get_instance()->get_db_link()->query('SELECT COALESCE(MAX(mjb_management_job_id), 0) FROM mjb_management_jobs')->fetchColumn();
$t0 = microtime(true);
$r = SupportNodeTools::run($ctx, 'schema_probe', array('table' => 'usr_users'));
$secs = round(microtime(true) - $t0, 1);
check(!$r['is_error'] && strpos($r['content'], 'usr_users') !== false, 'schema_probe answered in ' . $secs . 's', mb_substr($r['content'], 0, 300));
$stmt = DbConnector::get_instance()->get_db_link()->prepare(
	'SELECT mjb_management_job_id, mjb_job_type, mjb_created_by, mjb_parameters FROM mjb_management_jobs
	  WHERE mjb_management_job_id > ? AND mjb_mgn_managed_node_id = ? ORDER BY 1 DESC LIMIT 1');
$stmt->execute(array($before, $node_id));
$job = $stmt->fetch(PDO::FETCH_ASSOC);
$params = json_decode((string)($job['mjb_parameters'] ?? ''), true);
check($job && $job['mjb_job_type'] === 'schema_probe' && (int)$job['mjb_created_by'] === SupportDesk::systemUserId()
	&& ($params['requested_by'] ?? '') === 'support:' . (int)$thread->key,
	'the job is the support desk\'s, with the thread as requester', json_encode($job));
check(strpos($r['content'], 'job #' . (int)$job['mjb_management_job_id']) !== false, 'the answer names the job the owner can read');
$thread->load();
check($thread->nodeReadsToday() === 1, 'the read counts against the day');

// ---------------------------------------------------------------------------
section('The layers that stop a read');

harness_set_setting_mem('support_desk_node_reads_per_thread', '1');
$r2 = SupportNodeTools::run($ctx, 'disk_usage', array());
check($r2['is_error'] && strpos($r2['content'], 'used its 1 reads') !== false, 'the day\'s allowance stops the next read');
harness_set_setting_mem('support_desk_node_reads_per_thread', '20');
$bad = SupportNodeTools::run($ctx, 'site_log', array('file' => '../../etc/passwd'));
check($bad['is_error'] && strpos($bad['content'], 'Refused') === 0, 'a value outside the closed set is refused by the plane', $bad['content']);
$thread->set('sth_consent_live_reads', false);
$thread->save();
$ctx_off = new SupportTurnContext($thread, 0, $release, true);
$r3 = SupportNodeTools::run($ctx_off, 'disk_usage', array());
check($r3['is_error'] && strpos($r3['content'], 'not allowed') !== false, 'with consent withdrawn no read runs');

// ---------------------------------------------------------------------------
section('A word the site could not read is counted');

$db = DbConnector::get_instance()->get_db_link();
$version = (string)AgentVocabulary::version($node);
$count = function () use ($db, $version) {
	$s = $db->prepare("SELECT swg_count FROM swg_support_word_gaps WHERE swg_word = 'unit_journal' AND swg_agent_version = ?");
	$s->execute(array($version));
	return (int)$s->fetchColumn();
};
$n0 = $count();
SupportGaps::noteRefusedWords($thread, array(array('name' => 'unit_journal', 'is_error' => true, 'output' => 'refused: not offered in this run')));
check($count() === $n0 + 1, 'counted by word and agent version');
harness_defer(function () use ($db, $version, $n0) {
	if ($n0 === 0) {
		$db->prepare("DELETE FROM swg_support_word_gaps WHERE swg_word = 'unit_journal' AND swg_agent_version = ?")->execute(array($version));
	} else {
		$db->prepare("UPDATE swg_support_word_gaps SET swg_count = ? WHERE swg_word = 'unit_journal' AND swg_agent_version = ?")->execute(array($n0, $version));
	}
});

harness_finish();
