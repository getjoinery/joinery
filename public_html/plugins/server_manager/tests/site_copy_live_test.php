<?php
/** @joinery-test
 * name: site_copy_live
 * tier: live
 * env: dev-only
 * needs: [test-cloud]
 * timeout: 5400
 * covers: [plugins/server_manager/includes/SiteCopyRunner.php, plugins/server_manager/includes/SiteCopyLiveRun.php, plugins/server_manager/includes/ProxiedOriginMove.php]
 */
/**
 * A site copied once, for real, the way most copies go
 * (site_copy_final_test.md, the smoke test): run it after changing
 * copy code, before the release that carries the change.
 *
 * The run (SiteCopyLiveRun), on this management node and its own code:
 *
 *   1. A source: a throwaway server in the test cloud account, installed at
 *      the oldest release this management node serves that a copy accepts
 *      (the copy installs its source's own release, so this is the release a
 *      copy's install is most likely to get wrong), named in the Cloudflare
 *      test zone with its records proxied, joined here. Its recovery key is a
 *      key made for the run, proven as its owner proves it; its first backup
 *      taken here (the newest backup sizes the copy); seeded; its security
 *      read as the baseline.
 *   2. Copied onto a new server made here in the test account, through the
 *      Copy tab's own path, this management node's scheduled tasks moving it.
 *      The source's owner approves the export on the source once it names
 *      the copy's own key. Then dormant.
 *   3. Seeded again (only a final copy carries this one), then a switch-over
 *      by the proxy, gone back from at ready: the copy dormant again, the
 *      records unmoved, the source answering with both seeds.
 *   4. The switch-over again, moved this time: both seeds through the proxy
 *      and at the copy itself, the node now the copy's machine, the old
 *      server's row retired, the copy's probes passing, its security no
 *      weaker than the source's, and kept.
 *
 * Both servers, every node and copy record, the backups, the join requests
 * and the zone's records are removed whatever happened.
 *
 * Run: php tests/run.php live --only=plugins/server_manager/tests/site_copy_live_test.php
 *      SITE_COPY_LIVE_RELEASE=0.8.530 to install the source at another release this node serves
 *      SITE_COPY_LIVE_KEEP_ON_FAILURE=1 keeps a failed run's servers and nodes to look at
 *
 * Needs the Cloudflare test zone's token in the runner's home
 * (~/.config/joinery-test/cloudflare_test_token); without it nothing is made.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$say = function ($m) { echo '    ' . $m . "\n"; };

section('What the run needs');

if (!is_readable(SiteCopyLiveRun::token_path())) {
	harness_skip('a site copied live', 'no Cloudflare test token at ' . SiteCopyLiveRun::token_path() . ': no server made');
	harness_finish();
}
$accounts = TestCloudCleanup::accounts();
check($accounts !== array() && $accounts[0]->provider() === 'linode', 'a Linode test account to make both servers in',
	$accounts ? $accounts[0]->provider() : 'none');
$release = trim((string)getenv('SITE_COPY_LIVE_RELEASE')) ?: SiteCopyLiveRun::oldest_copyable_release();
$floor = JobCommandBuilder::COPY_SOURCE_MIN_VERSION;
check($release !== null && version_compare($release, $floor, '>=') && ReleaseStream::serves_releases($release),
	'the source is installed at ' . $release . ', the oldest release this management node serves that a copy accepts (' . $floor . ' or newer)');
$uid = (int)DbConnector::get_instance()->get_db_link()->query("SELECT usr_user_id FROM usr_users WHERE usr_permission >= 10
	AND usr_delete_time IS NULL ORDER BY usr_user_id LIMIT 1")->fetchColumn();
check($uid > 0, 'a superadmin to press the Copy tab\'s buttons as (user ' . $uid . ')');
if (!$accounts || $release === null || $uid <= 0 || !empty($GLOBALS['__harness']['failed'])) {
	harness_finish();
}
$account = 'oca:' . (int)$accounts[0]->key;
foreach (SiteCopyLiveRun::forget_dead_runs() as $gone) {
	echo '    removed what a killed run left: ' . $gone . "\n";
}

$hex = bin2hex(random_bytes(3));
$label = SiteCopyLiveRun::LABEL_PREFIX . $hex;
$site = str_replace('-', '', SiteCopyLiveRun::LABEL_PREFIX) . $hex;
$domain = $label . '.' . SiteCopyLiveRun::ZONE;
$server = null;
$node = null;
$keypair = sodium_crypto_box_keypair();
harness_defer(function () use (&$node, &$server, $domain, &$keypair) {
	sodium_memzero($keypair);
	if (getenv('SITE_COPY_LIVE_KEEP_ON_FAILURE') === '1' && !empty($GLOBALS['__harness']['failed']) && $server) {
		echo '    KEPT for a look: ssh root@' . $server['ipv4'] . ' (sshpass -f ' . $server['password_file'] . '), node #'
			. ($node ? $node->key : '?') . ', https://' . $domain . '; the test cloud\'s cleanup removes the servers and their nodes'
			. ' within a day, and the next run the zone\'s records' . "\n";
		return;
	}
	$left = SiteCopyLiveRun::teardown($node, $server, $domain);
	echo $left ? '    TEARDOWN LEFT: ' . implode('; ', $left) . "\n"
		: "    torn down: both servers, the nodes, the copy's records, the backups, the join requests and the zone's records\n";
});

try {

section('A source at ' . $release . ', behind the proxy, joined here');
$server = AgentLiveProofRun::make_server($label, SiteCopyLiveRun::SERVER_TYPE);
$server['site'] = $site;
check(true, 'server ' . $label . ' (Linode ' . $server['id'] . ') at ' . $server['ipv4']);
$fingerprint = SiteCopyLiveRun::install_source($server, $site, $domain, $release, $say);
$node = AgentLiveProofRun::adopt($fingerprint, $server);
check((bool)$node->get('mgn_agent_test_build'), 'its agent joined from the server\'s own address as ' . $site
	. ': node #' . $node->key . ', marked a test build');
SiteCopyLiveRun::await_words($node);
AgentLiveProofRun::await_site($node);
// What a copy asks of its source: its release and address, what else its
// server serves, and its recovery key, each from the source itself.
SiteCopyLiveRun::job($node, 'check_status', JobCommandBuilder::build_check_status($node), null, 300, $uid);
SiteCopyLiveRun::job($node, 'host_report', JobCommandBuilder::build_host_report($node), null, 600, $uid);
SiteCopyLiveRun::push_rig($server);
$state = SiteCopyLiveRun::prove_recovery_key($server, $site, $keypair);
check($state === 'ready', 'its recovery key is the run\'s, proven as its owner proves it: the challenge opened here', $state);
$report = SiteCopyLiveRun::job($node, 'recovery_key_report', JobCommandBuilder::build_recovery_key_report($node), null, 300, $uid);
$node->load();
$key_state = RecoveryKeyFleet::node_state($node);
check(RecoveryKeyFleet::has_own_key($key_state), 'this management node reads its key as proven (job ' . $report->key . ')',
	'state ' . $key_state['state'] . '; job ' . $report->get('mjb_status') . ', result ' . (string)$report->get('mjb_result')
	. ', status blob says ' . json_encode((json_decode((string)$node->get('mgn_last_status_data'), true) ?: array())['backup_recovery_state'] ?? null)
	. '; it printed: ' . trim(substr(SiteCopyLiveRun::output($report), -400)));
// A first backup, as the node page's Run Backup takes it: the newest backup
// sizes the copy's server, so the Copy tab offers nothing until there is one.
$policy = FleetBackupPolicy::for_node($node);
$params = array('type' => 'project', 'mode' => $policy['mode'], 'full_interval_days' => $policy['full_interval_days']);
$backup = SiteCopyLiveRun::job($node, 'backup_run', JobCommandBuilder::build_backup_run($node, $params), $params, 1800, $uid);
check((string)$backup->get('mjb_status') === 'completed',
	'its first backup ran here (job ' . $backup->key . ')', (string)$backup->get('mjb_error_message'));
$node->load();
$why = SiteCopyRunner::source_refusals($node);
try {
	$chain = SiteCopyRunner::newest_chain($node, null);
} catch (SiteCopyException $e) {
	$why[] = $e->getMessage();
	$chain = null;
}
check($why === array(), 'nothing refuses copying it (release ' . $node->get('mgn_joinery_version') . ', ' . SiteCopyRunner::site_domain($node)
	. ($chain ? ', its backup ' . $chain['chain_id'] . ' of ' . BackupRunner::human($chain['bytes']) : '') . ')', implode(' | ', $why));
if ($why) {
	throw new Exception('the source cannot be copied');
}

section('Seeded, and its security read as the baseline');
$seeds = array(SiteCopyLiveRun::seed($server, $site));
$wrong = SiteCopyLiveRun::markers_wrong($domain, $seeds, 120);
check($wrong === array(), 'seed ' . $seeds[0]['id'] . ' reads right through the proxy', json_encode($wrong));
$baseline = SiteCopyLiveRun::security_rows($node, $uid);
foreach (CopySecurityCheck::lines($baseline) as $line) {
	echo '      ' . $line . "\n";
}
check($baseline !== array(), 'the source\'s security is read: ' . count($baseline) . ' rows, '
	. count(SiteCopyLiveRun::failed_rows($baseline)) . ' failing, the baseline the copy is held to');

section('Copied onto a new server made here');
$target = HeldCloudAccounts::resolve($account);
$born = SiteCopyRunner::copy_encryption($node, (string)$target['hosting_mode']);
$say('its encryption, as the Copy tab says it: ' . $born['says']);
$copy = SiteCopyRunner::start_new_server($node, array('account' => $account, 'region' => SiteCopyLiveRun::REGION,
	'type' => SiteCopyLiveRun::SERVER_TYPE), $uid);
check($copy->status() === SiteCopy::STATUS_WAITING && (string)$copy->get('scp_release') === (string)$node->get('mgn_joinery_version'),
	'copy #' . $copy->key . ' waits for its server, pinned to the source\'s release ' . $copy->get('scp_release'));
$approved = array();
$each = function (SiteCopy $c) use (&$server, $site, &$keypair, &$approved, $say) {
	// The copy's node is the run's too: the test cloud's cleanup takes it with its server.
	$row = SiteCopyRunner::copy_row($c, false);
	if ($row && !$row->get('mgn_agent_test_build')) {
		$row->set('mgn_agent_test_build', true);
		$row->save();
		$say('the copy\'s node #' . $row->key . ' is marked a test build');
	}
	// Its owner is asked on the source's own page while the export waits.
	if (strpos(SiteCopyLiveRun::step_line($c), 'copy_export ') === 0) {
		$job = SiteCopyLiveRun::answer_export($server, $site, $keypair, $c);
		if ($job !== null) {
			$approved[] = $job;
			$say('approved the export (job ' . $job . ') on the source: it names the copy\'s own key');
		}
	}
};
SiteCopyLiveRun::wait_status($copy, array(SiteCopy::STATUS_DORMANT), 3600, $say, $each);
check(count($approved) >= 1, 'the source\'s owner approved its export, once it named the copy\'s own key', count($approved) . ' approvals');
$row = SiteCopyRunner::copy_row($copy, false);
$prov = SiteCopyRunner::provision($copy);
$copy_v4 = $prov ? trim((string)$prov->get('cvp_instance_ip')) : '';
$copy_v6 = $prov ? trim((string)strtok((string)$prov->get('cvp_instance_ipv6'), '/')) : '';
check($row && (string)$row->get('mgn_install_state') === 'copy' && (int)$row->get('mgn_copy_of_node_id') === (int)$node->key && $copy_v4 !== '',
	'dormant: node #' . ($row ? $row->key : '?') . ' is the source\'s copy, on Linode ' . ($prov ? $prov->get('cvp_instance_id') : '?') . ' at ' . $copy_v4);
check((bool)$prov->get('cvp_data_root_encrypted') === (bool)$born['ours'],
	'its server was born with the layer the Copy tab said (' . ($born['ours'] ? 'ours' : 'the provider\'s default') . ')');

section('Seeded again, then the way back midway through a switch-over');
$seeds[] = SiteCopyLiveRun::seed($server, $site);
check(true, 'seed ' . $seeds[1]['id'] . ' made after the export: only a final copy carries it');
$driver = SiteCopyLiveRun::switch_driver($domain);
$refused = SiteCopyRunner::switch_refusals($copy);
check($refused === array(), 'nothing refuses a switch-over by the proxy', implode(' | ', $refused));
$methods = SiteCopyRunner::switch_methods($copy);
check(array_key_exists(SiteCopyRunner::METHOD_PROXIED, $methods) && $methods[SiteCopyRunner::METHOD_PROXIED] === array(),
	'its address can move by the proxy', json_encode($methods));
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_PROXIED, $driver, $uid);
SiteCopyLiveRun::wait_status($copy, array(SiteCopy::STATUS_READY), 1500, $say, $each);
check(true, 'frozen, copied once more, both censuses the same: ready');
SiteCopyRunner::go_back($copy, $driver);
SiteCopyLiveRun::wait_status($copy, array(SiteCopy::STATUS_DORMANT), 900, $say, $each);
$source_addrs = array((string)$server['ipv4'], (string)strtok((string)($server['ipv6'] ?? ''), '/'));
$recs = SiteCopyLiveRun::records($domain);
check($recs !== array() && !array_filter($recs, function ($r) use ($source_addrs) { return !in_array((string)$r['content'], $source_addrs, true); }),
	'gone back: dormant again, and the proxied records still name the source', json_encode(array_column($recs, 'content')));
$wrong = SiteCopyLiveRun::markers_wrong($domain, $seeds, 300);
check($wrong === array(), 'the source answers through the proxy again, both seeds right', json_encode($wrong));

section('Switched over by the proxy, and kept');
SiteCopyRunner::begin_switch($copy, SiteCopyRunner::METHOD_PROXIED, $driver, $uid);
SiteCopyLiveRun::wait_status($copy, array(SiteCopy::STATUS_READY), 1500, $say, $each);
SiteCopyRunner::move_address($copy, $driver, $uid);
SiteCopyLiveRun::wait_status($copy, array(SiteCopy::STATUS_SWITCHED), 1800, $say, $each);
$recs = SiteCopyLiveRun::records($domain);
$copy_addrs = array_filter(array($copy_v4, $copy_v6), 'strlen');
check($recs !== array() && !array_filter($recs, function ($r) use ($copy_addrs) { return !in_array((string)$r['content'], $copy_addrs, true); }),
	'switched: the proxied records name the copy', json_encode(array_column($recs, 'content')));
$wrong = SiteCopyLiveRun::markers_wrong($domain, $seeds, 300);
check($wrong === array(), 'both seeds read right through the proxy', json_encode($wrong));
$wrong = SiteCopyLiveRun::markers_wrong($domain, $seeds, 60, $copy_v4);
check($wrong === array(), 'and at the copy itself', json_encode($wrong));
$node->load();
$old = SiteCopyRunner::copy_row($copy, false);
check((string)$node->get('mgn_host') === $copy_v4 && $node->is_operational(),
	'node #' . $node->key . ' is the copy\'s machine now, at ' . $node->get('mgn_host'));
check($old && (int)$old->key !== (int)$node->key && (string)$old->get('mgn_host') === (string)$server['ipv4'],
	'and the old server is row #' . ($old ? $old->key : '?') . ' (' . ($old ? $old->get('mgn_name') . ', ' . $old->install_state_label() : 'none') . ')');
$probes = SiteCopyLiveRun::probes($node, $site, (string)$server['ipv4'], $uid);
check($probes['ok'], 'the copy\'s probes pass, as the site (no trust left for the old address ' . $server['ipv4'] . ')',
	trim(substr($probes['out'], -1500)));
$rows = SiteCopyLiveRun::security_rows($node, $uid);
foreach (CopySecurityCheck::lines($rows) as $line) {
	echo '      ' . $line . "\n";
}
$weaker = CopySecurityCheck::weaker($baseline, $rows);
check($weaker === array(), 'nothing on the copy is weaker than on the source',
	implode(' | ', array_map(function ($r) { return $r['what'] . ': ' . $r['evidence']; }, $weaker)));
check(SiteCopyLiveRun::failed_rows($rows) === array(), 'and no security row fails there',
	implode(' | ', array_map(function ($r) { return $r['what'] . ': ' . $r['evidence']; }, SiteCopyLiveRun::failed_rows($rows))));
$delete = SiteCopyRunner::finish($copy, $driver);
$copy->load();
check($copy->status() === SiteCopy::STATUS_FINISHED, 'kept; the old server is named for deleting: ' . $delete);

} catch (Throwable $e) {
	// The run stopped: said as a failure naming why. The teardown deferred
	// above removes whatever it had made.
	check(false, 'the run went to its end', get_class($e) . ': ' . $e->getMessage());
}

harness_finish();
