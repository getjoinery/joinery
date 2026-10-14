<?php
/** @joinery-test
 * name: node_overview_running_list
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The node page's Overview tab, rendered for fixture nodes, for what agent
 * 1.76.0 and host_report 1.23 add (specs/agent_recipes_and_vocabulary.md, the
 * running list): the Machine box's Listening ports, All containers and Reboot,
 * with the last reboot's state, and the reason a reboot is not offered; the
 * Data root gauge's script version and a service kept there with no drop-in;
 * the Plugins card with Spam learning.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

/** Every word this plane builds, as a current node reports them. */
function nor_words() {
	$words = array();
	foreach (get_class_methods('JobCommandBuilder') as $m) {
		if (preg_match('/^build_([a-z0-9_]+)_primitive$/', $m, $mm)) { $words[] = $mm[1]; }
	}
	return implode(',', $words);
}

function nor_node(array $fields) {
	$n = new ManagedNode(NULL);
	$suffix = bin2hex(random_bytes(3));
	$n->set('mgn_name', 'HarnessTest NOR ' . $suffix);
	$n->set('mgn_slug', 'harnessnor-' . $suffix);
	$n->set('mgn_host', '192.0.2.61');
	$n->set('mgn_web_root', '/var/www/html/nor/public_html');
	$n->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
	$n->set('mgn_agent_version', '1.76.0');
	$n->set('mgn_agent_primitives', nor_words());
	foreach ($fields as $k => $v) { $n->set($k, $v); }
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
}

/** The Overview tab's HTML for $node, as node_detail.php includes it. */
function nor_render(ManagedNode $node) {
	$session = SessionControl::get_instance();
	$page = new AdminPage();
	$base_url = '/admin/server_manager/node_detail?mgn_managed_node_id=' . $node->key;
	$page_regex = '/\/admin\/server_manager/';
	$node_name = htmlspecialchars($node->get('mgn_name'));
	$skip_joinery = $node->get('mgn_skip_joinery_checks');
	$tab = 'overview';
	$dns_box = null;
	ob_start();
	try {
		require(PathHelper::getIncludePath('plugins/server_manager/includes/node_detail_tabs/overview.php'));
	} catch (Throwable $e) {
		ob_end_clean();
		return 'RENDER FAILED: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
	}
	return ob_get_clean();
}

$boot_a = '11111111-2222-4333-8444-555555555555';
$report = array(
	'failed_units' => array(), 'expected_units' => array(), 'reboot_required' => true, 'reboot_required_since' => time() - 3600,
	'boot_id' => $boot_a,
	'disk_pool' => array('used_bytes' => 10 * 1073741824, 'total_bytes' => 100 * 1073741824, 'avail_bytes' => 90 * 1073741824, 'prjquota' => true, 'device' => ''),
	'data_root_encryption' => 'none',
	'data_root_script' => array('installed_version' => '1.21', 'release_version' => '1.22', 'consumers' => array(
		array('unit' => 'postgresql.service', 'drop_in' => true, 'installed' => true),
		array('unit' => 'apache2.service', 'drop_in' => false, 'installed' => true),
		array('unit' => 'podman-restart.service', 'drop_in' => true, 'installed' => false),
	)),
	'containers' => array(),
	'generated_at' => time(),
);
$status = array('plugins' => array(
	array('name' => 'mailbox', 'version' => '1.143.0', 'active' => true, 'status' => 'active'),
	array('name' => 'bookings', 'version' => '1.3.1', 'active' => true, 'status' => 'stale'),
	array('name' => 'store', 'version' => '1.0.0', 'active' => false, 'status' => 'inactive'),
	array('name' => '<script>x</script>', 'version' => '"><b>', 'active' => true, 'status' => 'active'),
));

// ---------------------------------------------------------------------------
section('A machine the node page can ask (agent 1.76.0, host_report 1.23)');

$node = nor_node(array(
	'mgn_last_host_report' => json_encode($report),
	'mgn_last_host_report_time' => gmdate('Y-m-d H:i:s'),
	'mgn_last_status_data' => json_encode($status),
));
$html = nor_render($node);
check(strpos($html, 'RENDER FAILED') === false, 'the Overview tab renders', substr($html, 0, 400));
check(strpos($html, 'form="nodeActionListeningSockets"') !== false && strpos($html, '>Listening ports</button>') !== false,
	'Listening ports is offered');
check(strpos($html, 'All containers') !== false, 'All containers is offered where the report lists containers (a machine with an engine)');
check(preg_match('/onclick="JoineryModal\.confirm\(&quot;Reboot HarnessTest NOR [0-9a-f]{6}\?[^"]*nodeActionRebootMachine\'\)\.submit\(\)/', $html) === 1,
	'Reboot asks in the system modal, naming the machine, then posts the reboot form');
check(strpos($html, 'id="nodeActionRebootMachine"') !== false && strpos($html, 'value="reboot_machine"') !== false,
	'the reboot form posts the reboot_machine action');
check(strpos($html, 'Data root script 1.22') !== false && strpos($html, 'the copy a run starts: 1.21') !== false,
	'the Data root gauge names its script\'s version and the copy a run starts');
check(strpos($html, 'not this release') !== false, 'and says when the copy a run starts is not this release\'s');
check(strpos($html, 'Not ordered after the data root: apache2.service') !== false,
	'a service kept on the data root that is installed with no drop-in is named in red');
check(strpos($html, 'podman-restart.service: not installed here, ordered after the data root') !== false,
	'each service is listed, a drop-in for a unit not installed said as such');
check(strpos($html, 'Plugins</div>') !== false && strpos($html, 'mailbox <span class="text-muted">1.143.0</span>') !== false,
	'the Plugins card lists a plugin switched on with its version');
check(strpos($html, 'bookings') !== false && strpos($html, '>stale</span>') !== false && strpos($html, '1 installed and switched off') !== false,
	'a stale plugin is marked, and the ones switched off are counted');
check(strpos($html, '<script>x</script>') === false && strpos($html, '"><b>') === false,
	'a node\'s plugin name and version are held to plain characters');
check(strpos($html, 'form="nodeActionSpamLearning"') !== false, 'Spam learning is offered where the Mailbox plugin is on');

// The last reboot: waiting, then back.
$rj = ManagementJob::createFromBuild($node->key, 'reboot_machine', JobCommandBuilder::build_reboot_machine_primitive($node), null, null);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $rj->key);
$rj->set('mjb_status', 'completed');
$rj->set('mjb_result', json_encode(array('read' => true, 'scheduled' => true, 'boot_id_before' => $boot_a, 'came_back_at' => null)));
$rj->save();
$html = nor_render($node);
check(strpos($html, 'waiting for the machine to come back') !== false, 'a reboot asked and not yet seen back says so');
$r = json_decode((string)$rj->get('mjb_result'), true);
$r['came_back_at'] = gmdate('Y-m-d H:i:s');
$rj->set('mjb_result', json_encode($r));
$rj->save();
$html = nor_render($node);
check(strpos($html, '>Rebooted</a>: asked') !== false && strpos($html, 'back by') !== false, 'one seen back says when');

// ---------------------------------------------------------------------------
section('Where a reboot is not offered');

$container = nor_node(array('mgn_container_name' => 'harnessnor', 'mgn_last_host_report' => json_encode($report)));
$html = nor_render($container);
check(strpos($html, 'nodeActionRebootMachine\').submit') === false, 'a container site\'s row offers no reboot');

$enc_report = $report;
$enc_report['data_root_encryption'] = array('backing' => 'luks-file', 'open' => true, 'identity' => base64_encode(str_repeat("\x61", 32)),
	'holder' => '', 'slot' => null, 'reencrypt' => '');
$locked = nor_node(array('mgn_last_host_report' => json_encode($enc_report)));
$html = nor_render($locked);
check(strpos($html, 'Not rebooted from here: Its data root is encrypted and has no escrow holder') !== false
	&& strpos($html, 'nodeActionRebootMachine\').submit') === false,
	'an encrypted data root with no escrow holder: the reason in place of the button');

$old = nor_node(array('mgn_agent_version' => '1.75.5', 'mgn_agent_primitives' => 'check_status,host_report,disk_usage',
	'mgn_last_host_report' => json_encode($report), 'mgn_last_status_data' => json_encode($status)));
$html = nor_render($old);
check(strpos($html, '>Listening ports</button>') === false && strpos($html, 'form="nodeActionSpamLearning"') === false
	&& strpos($html, 'it does not offer') !== false && strpos($html, 'reboot_machine') !== false,
	'a node whose agent lacks the words is offered none, and the page names what it lacks');

harness_finish();
