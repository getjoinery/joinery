<?php
/** @joinery-test
 * name: instance_transfer
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Handing a Managed site's server to its customer's own Linode account
 * (specs/managed_to_self_hosted_transfer.md).
 *
 * What would plausibly break while the thing still looked like it worked:
 *
 *  - **The check says what is really there.** Every provider-side blocker and
 *    warning, both interface generations, and a check that could not be asked
 *    reads as a blocker — never as "nothing attached". The create call's own
 *    refusal reaches the operator verbatim.
 *  - **Only a sold Managed site with no credential of ours on it moves.**
 *  - **The code never leaks.** Not into an email, a job, a log line or an
 *    error message; and it is erased the moment the row leaves code_issued.
 *  - **The row follows the provider**: stale, cancel, failure, and no cancel
 *    once accepted.
 *  - **The finish resumes where it stopped** and never runs a step twice; the
 *    store's cancel signal arriving afterwards leaves hosting `transferred`.
 *  - **Mail and backups carry on**: the mail row is the provision's provider
 *    pieces, the webhook counts against the tenant, and the node-linked
 *    backup acts switch the node's own fleet backups.
 *  - **Stop managing leaves nothing watching**, and nothing reopens.
 *
 * Everything runs inside one transaction that is rolled back; the provider,
 * the mailer and the signal bus are stood in for.
 *
 * Run: php plugins/server_manager/tests/instance_transfer_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ServiceTenantWatch.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/PollInstanceTransfers.php'));

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;

$db = DbConnector::get_instance()->get_db_link();
if (!$db->query("SELECT to_regclass('public.itx_instance_transfers') IS NOT NULL")->fetchColumn()) {
	section('schema');
	harness_skip('itx_instance_transfers is not in this database yet — run update_database');
	harness_finish();
	exit;
}
$db->beginTransaction();

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);

// ── Stand-ins ─────────────────────────────────────────────────────────────────

/** The operator's account, as a script. */
class XferFakeDriver implements CloudComputeProvider, CloudInstanceTransfers {
	public $eligibility = array();
	public $transfers = array();     // token => status
	public $instance_present = true;
	public $create_error = null;
	public $cancels = 0;
	public $last_token = '';
	/** @var callable|null run while a status is being asked: another writer acting in the meantime */
	public $on_status = null;
	public $status_error = null;

	public function createInstance(array $opts): array { throw new CloudComputeException('not here'); }
	public function getInstance(string $instance_id): array {
		if (!$this->instance_present) {
			throw new CloudComputeException('Linode API GET linode/instances/' . $instance_id . ' failed (404): Not found', 404);
		}
		return array('id' => $instance_id, 'status' => 'running', 'ip' => '203.0.113.9', 'ipv6' => '', 'label' => 'x', 'region' => 'us-east');
	}
	public function rebuildInstance(string $instance_id, array $opts): array { throw new CloudComputeException('not here'); }
	public function deleteInstance(string $instance_id): void { throw new CloudComputeException('never'); }
	public function shutdownInstance(string $instance_id): void {}
	public function bootInstance(string $instance_id): void {}
	public function getTransfer(): array { return array('used_gb' => 0, 'quota_gb' => 0, 'billable_gb' => 0); }
	public function setReverseDns(string $instance_id, string $ip, string $hostname): array { return array(); }

	public function transferEligibility(string $instance_id): array { return $this->eligibility; }
	public function createTransfer(string $instance_id): array {
		if ($this->create_error !== null) {
			throw new CloudComputeException($this->create_error, 400);
		}
		$token = 'xfer-' . bin2hex(random_bytes(16));
		$this->transfers[$token] = 'pending';
		$this->last_token = $token;
		return array('token' => $token, 'status' => 'pending', 'expiry' => gmdate('Y-m-d H:i:s', time() + 86400));
	}
	public function getTransferStatus(string $token): array {
		if ($this->status_error !== null) {
			throw $this->status_error;
		}
		if ($this->on_status !== null) {
			$hook = $this->on_status;
			$this->on_status = null;
			$hook($token);
		}
		return array('status' => $this->transfers[$token] ?? 'stale', 'expiry' => '');
	}
	public function cancelTransfer(string $token): void {
		if (($this->transfers[$token] ?? '') !== 'pending') {
			throw new CloudComputeException('only a pending transfer can be canceled', 400);
		}
		$this->transfers[$token] = 'canceled';
		$this->cancels++;
	}
}

$driver = new XferFakeDriver();
InstanceTransfers::$driver = $driver;
$mails = array();
InstanceTransfers::$mailer = function ($template, $to, $vars) use (&$mails) {
	$mails[] = array('template' => $template, 'to' => $to, 'vars' => $vars);
};
$signals = array();
InstanceTransfers::$signal = function ($name, $payload) use (&$signals) {
	$signals[] = array('name' => $name, 'payload' => $payload);
};
IncidentReconciler::$dispatch = function () {};

// Every log line the flow writes, so the code can be looked for in it.
$log_file = tempnam(sys_get_temp_dir(), 'xferlog');
$previous_log = ini_get('error_log');
ini_set('error_log', $log_file);

function xfer_node(string $slug): ManagedNode {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest transfer ' . $slug);
	$node->set('mgn_slug', $slug);
	$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_web_root', '/var/www/html/' . $slug . '/public_html');
	$node->set('mgn_container_name', $slug);
	$node->set('mgn_port', 8080);
	$node->set('mgn_uptime_enabled', true);
	$node->set('mgn_agent_public_key', 'xfer-agent-' . $slug);
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$node->set('mgn_agent_primitives', 'hosted_mail_settings,hosted_plan_notice,backup_run');
	$node->set('mgn_backup_shelf_bytes', 2 * 1073741824);
	// Named but not real: nothing here may reach a real bucket.
	$node->set('mgn_bkt_backup_target_id', 2000000000);
	$node->save();
	$node->load();
	return $node;
}

function xfer_site(string $slug, array $extra = array()): array {
	$node = xfer_node($slug);
	$provision = new CustomerCloudProvision(NULL);
	$provision->set('cvp_origin', 'order');
	$provision->set('cvp_external_order_item_id', 880000000 + random_int(0, 99999));
	$provision->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
	$provision->set('cvp_domain', $slug . '.example.com');
	$provision->set('cvp_slug', $slug);
	$provision->set('cvp_hosting_mode', 'operator');
	$provision->set('cvp_status', 'done');
	$provision->set('cvp_install_mode', 'fresh');
	$provision->set('cvp_instance_id', (string)random_int(10000000, 99999999));
	$provision->set('cvp_instance_ip', '203.0.113.9');
	$provision->set('cvp_instance_type', 'g6-nanode-1');
	$provision->set('cvp_install_password', 'retired');
	$provision->set('cvp_buyer_email', $slug . '@example.com');
	$provision->set('cvp_buyer_name', 'Pat Buyer');
	$provision->set('cvp_mgn_managed_node_id', (int)$node->key);
	$provision->set('cvp_mail_state', 'done');
	$provision->set('cvp_smtp2go_subaccount_id', 'sub-' . $slug);
	$provision->set('cvp_smtp2go_user_id', 'smtp-' . $slug);
	$provision->set('cvp_mail_records', json_encode(array(
		array('type' => 'CNAME', 'name' => 's1._domainkey.mail.' . $slug . '.example.com', 'value' => 'dkim.smtp2go.net', 'purpose' => 'DKIM'))));
	foreach ($extra as $k => $v) { $provision->set($k, $v); }
	$provision->save();
	$provision->load();

	$trial = new HostedTrial(NULL);
	$trial->set('htr_cvp_customer_cloud_provision_id', (int)$provision->key);
	$trial->set('htr_external_order_item_id', $provision->get('cvp_external_order_item_id'));
	$trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
	$trial->save();
	return array($provision, $node, $trial);
}

function xfer_items(array $check, string $result): array {
	$out = array();
	foreach ($check['items'] ?? $check as $item) {
		if ($item['result'] === $result) { $out[] = $item['key']; }
	}
	return $out;
}

// ── The real Linode driver's check, against a scripted API ────────────────────

/**
 * A LinodeComputeDriver whose HTTP answers come from $routes: "METHOD path" =>
 * [status, body]. Anything unrouted answers 200 with an empty list.
 */
function xfer_linode(array $routes, array &$seen = null): LinodeComputeDriver {
	$seen = array();
	$handler = function (RequestInterface $request, array $options) use ($routes, &$seen) {
		$path = ltrim(str_replace('/v4/', '', $request->getUri()->getPath()), '/');
		$key = $request->getMethod() . ' ' . $path;
		$seen[] = $key;
		list($status, $body) = $routes[$key] ?? array(200, array('data' => array()));
		return Create::promiseFor(new Response($status, array('Content-Type' => 'application/json'), json_encode($body)));
	};
	$client = new Client(array('base_uri' => LinodeComputeDriver::API_BASE, 'handler' => HandlerStack::create($handler)));
	return new LinodeComputeDriver('test-token', $client);
}

section('The provider-side check sees what is attached, and says so');

$clean = array(
	'GET linode/instances/42' => array(200, array('id' => 42, 'locks' => array(), 'interface_generation' => 'legacy_config',
		'backups' => array('enabled' => false))),
	'GET linode/instances/42/ips' => array(200, array('ipv4' => array('public' => array(array('address' => '203.0.113.9', 'reserved' => false)),
		'shared' => array()), 'ipv6' => array('global' => array()))),
	'GET linode/instances/42/configs' => array(200, array('data' => array(array('interfaces' => array(array('purpose' => 'public')))))),
	'GET account/settings' => array(200, array('managed' => false, 'backups_enabled' => false)),
);
$check = xfer_linode($clean)->transferEligibility('42');
check(xfer_items($check, 'blocker') === array() && xfer_items($check, 'warning') === array(),
	'an instance with nothing attached passes every check', json_encode(xfer_items($check, 'blocker')));

$dirty = $clean;
$dirty['GET linode/instances/42'] = array(200, array('id' => 42, 'locks' => array('cannot_delete'),
	'interface_generation' => 'legacy_config', 'backups' => array('enabled' => true)));
$dirty['GET linode/instances/42/firewalls'] = array(200, array('data' => array(array('id' => 7, 'label' => 'fw-a'))));
$dirty['GET linode/instances/42/volumes'] = array(200, array('data' => array(array('id' => 8, 'label' => 'vol-a'))));
$dirty['GET linode/instances/42/nodebalancers'] = array(200, array('data' => array(array('id' => 9, 'label' => 'nb-a'))));
$dirty['GET linode/instances/42/ips'] = array(200, array(
	'ipv4' => array('public' => array(array('address' => '203.0.113.9', 'reserved' => true)),
		'shared' => array(array('address' => '203.0.113.10'))),
	'ipv6' => array('global' => array(array('range' => '2600:3c00:e000::', 'prefix' => 64)))));
$dirty['GET linode/instances/42/configs'] = array(200, array('data' => array(array('interfaces' => array(
	array('purpose' => 'public'), array('purpose' => 'vlan'))))));
$dirty['GET account/settings'] = array(200, array('managed' => true, 'backups_enabled' => true));
$dirty['GET account/service-transfers'] = array(200, array('data' => array(array('token' => 'x', 'status' => 'pending',
	'is_sender' => true, 'entities' => array('linodes' => array(42))))));
$check = xfer_linode($dirty)->transferEligibility('42');
$blockers = xfer_items($check, 'blocker');
foreach (array('locks', 'firewalls', 'volumes', 'nodebalancers', 'shared_ipv4', 'reserved_ipv4', 'ipv6_ranges',
		'private_networks', 'linode_managed', 'pending_transfer') as $key) {
	check(in_array($key, $blockers, true), 'blocker found: ' . $key);
}
$warnings = xfer_items($check, 'warning');
check(in_array('instance_backups', $warnings, true) && in_array('account_backups', $warnings, true),
	'Linode Backups on the instance and account auto-enrolment are warnings, not blockers', json_encode($warnings));

$new_gen = $clean;
$new_gen['GET linode/instances/42'] = array(200, array('id' => 42, 'interface_generation' => 'linode'));
$new_gen['GET linode/instances/42/interfaces'] = array(200, array('interfaces' => array(
	array('id' => 1, 'public' => array('ipv4' => array()), 'vpc' => null, 'vlan' => null),
	array('id' => 2, 'public' => null, 'vpc' => array('vpc_id' => 3, 'subnet_id' => 4), 'vlan' => null))));
$check = xfer_linode($new_gen, $seen)->transferEligibility('42');
check(in_array('private_networks', xfer_items($check, 'blocker'), true)
	&& in_array('GET linode/instances/42/interfaces', $seen, true) && !in_array('GET linode/instances/42/configs', $seen, true),
	'a Linode-interfaces instance is read through /interfaces, and a VPC there blocks');

$scope = $clean;
$scope['GET linode/instances/42/firewalls'] = array(401, array('errors' => array(array('reason' => 'Your OAuth token is not authorized to use this endpoint.'))));
$check = xfer_linode($scope)->transferEligibility('42');
$fw = null;
foreach ($check as $item) { if ($item['key'] === 'firewalls') { $fw = $item; } }
check($fw !== null && $fw['result'] === 'blocker' && strpos($fw['detail'], 'Could not be read') === 0,
	'a check the token cannot make is a blocker that says so — never a pass', json_encode($fw));

$gone = array('GET linode/instances/42' => array(404, array('errors' => array(array('reason' => 'Not found')))));
$check = xfer_linode($gone)->transferEligibility('42');
check(count($check) === 1 && $check[0]['key'] === 'instance' && $check[0]['result'] === 'blocker',
	'an instance no longer on our account is the one blocker, and nothing else is asked');

$refuse = array('POST account/service-transfers' => array(400, array('errors' => array(array('reason' => 'Linode 42 has a Cloud Firewall attached.')))));
$reason = '';
try { xfer_linode($refuse)->createTransfer('42'); } catch (CloudComputeException $e) { $reason = $e->getMessage(); }
check(strpos($reason, 'Linode 42 has a Cloud Firewall attached.') !== false,
	'the create call\'s refusal carries Linode\'s own reason', $reason);

$secret = 'abcdef0123456789abcdef0123456789';
$fail = array('GET account/service-transfers/' . $secret => array(500, array('errors' => array(array('reason' => 'boom')))));
$msg = '';
try { xfer_linode($fail)->getTransferStatus($secret); } catch (CloudComputeException $e) { $msg = $e->getMessage() . ' ' . ($e->getPrevious() ? $e->getPrevious()->getMessage() : ''); }
check($msg !== '' && strpos($msg, $secret) === false && strpos($msg, '[code]') !== false,
	'a failed status call\'s error names [code], never the code itself', $msg);

// A connection that never completes is not an HTTP error, and Guzzle names the
// whole URL — code included — in its message.
$refusing = function (RequestInterface $request, array $options) {
	return Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out for '
		. (string)$request->getUri(), $request));
};
$offline = new LinodeComputeDriver('test-token', new Client(array('base_uri' => LinodeComputeDriver::API_BASE,
	'handler' => HandlerStack::create($refusing))));
foreach (array('getTransferStatus', 'cancelTransfer') as $call) {
	$caught = null;
	try { $offline->$call($secret); } catch (Throwable $e) { $caught = $e; }
	$text = $caught ? get_class($caught) . ' ' . $caught->getMessage() . ' ' . ($caught->getPrevious() ? $caught->getPrevious()->getMessage() : '') : '';
	check($caught instanceof CloudComputeException && strpos($text, $secret) === false && strpos($text, '[code]') !== false,
		$call . ': a connection failure is a CloudComputeException that names [code], never the code', $text);
}

$created = xfer_linode(array('POST account/service-transfers' => array(200,
	array('token' => 'tok-1', 'status' => 'pending', 'expiry' => '2036-01-02T03:04:05'))))->createTransfer('42');
check($created['token'] === 'tok-1' && $created['expiry'] === '2036-01-02 03:04:05',
	'a created transfer comes back as its code and a UTC expiry', json_encode($created));

// ── The platform check ────────────────────────────────────────────────────────

section('Only a sold Managed site with no credential of ours on it can move');

$driver->eligibility = array(array('key' => 'instance', 'label' => 'x', 'result' => 'pass', 'detail' => '', 'fix' => ''));
list($p, $n, $t) = xfer_site('xfer-a-' . $suffix);
$check = InstanceTransfers::check($p);
check($check['blockers'] === 0, 'a sold, finished, keyless Managed site passes', InstanceTransfers::blocker_summary($check));

$cases = array(
	'install password held'   => array('cvp_install_password' => 'held'),
	'admin-made machine'      => array('cvp_origin' => 'admin', 'cvp_external_order_item_id' => null),
	'mail leg unfinished'     => array('cvp_mail_state' => 'records_published'),
);
foreach ($cases as $what => $extra) {
	list($pc) = xfer_site('xfer-c' . substr(md5($what), 0, 4) . '-' . $suffix, $extra);
	$c = InstanceTransfers::check($pc);
	check($c['blockers'] > 0, 'blocked: ' . $what, InstanceTransfers::blocker_summary($c));
}
list($pk, $nk) = xfer_site('xfer-k-' . $suffix);
$nk->set('mgn_ssh_key_path', '/home/user1/.ssh/id_ed25519');
$nk->save();
check(in_array('ssh_key', xfer_items(InstanceTransfers::check($pk), 'blocker'), true),
	'an operator SSH key recorded on the node blocks: it is a working credential of ours');
list($pr, $nr) = xfer_site('xfer-r-' . $suffix);
$nr->set('mgn_is_relay', true);
$nr->save();
check(in_array('managed_site', xfer_items(InstanceTransfers::check($pr), 'blocker'), true),
	'a relay is operator-mode too, and is not a site to hand over');
list($pg, , $tg) = xfer_site('xfer-g-' . $suffix);
$tg->set('htr_state', HostedTrial::STATE_GRACE);
$tg->save();
$cg = InstanceTransfers::check($pg);
check($cg['blockers'] === 0 && in_array('plan', xfer_items($cg, 'warning'), true),
	'a site in its grace period is a warning: the operator\'s call');
$driver->eligibility = array(array('key' => 'firewalls', 'label' => 'No Cloud Firewall attached', 'result' => 'blocker',
	'detail' => 'Cloud Firewall: fw-a.', 'fix' => 'Detach it.'));
check(in_array('firewalls', xfer_items(InstanceTransfers::check($p), 'blocker'), true),
	'the provider\'s blockers join the platform\'s');
$driver->eligibility = array();

// ── The flow ──────────────────────────────────────────────────────────────────

section('From request to code: the customer is told, and the code goes nowhere but the row');

$row = InstanceTransfers::request($p);
check($row->state() === InstanceTransfer::STATE_REQUESTED && (string)$row->get('itx_requested_by') === 'customer',
	'the customer\'s ask waits as requested');
check((int)InstanceTransfers::request($p)->key === (int)$row->key, 'asking twice finds the same row');
check(count(array_filter($signals, function ($s) { return $s['name'] === 'hosted.transfer_attention'; })) === 1,
	'the operator hears about the ask');

$mails = array();
$row = InstanceTransfers::start($p);
check($row->state() === InstanceTransfer::STATE_INVITED, 'Start invites');
check(count($mails) === 1 && $mails[0]['template'] === 'instance_transfer_invite' && $mails[0]['to'] === $p->get('cvp_buyer_email'),
	'the customer gets the invitation');

$row = InstanceTransfers::issue_code($row, 'customer');
$token = $driver->last_token;
check($row->state() === InstanceTransfer::STATE_CODE_ISSUED && $row->open_token() === $token,
	'the code is issued and sealed on the row');
check(strpos((string)$row->get('itx_token_sealed'), $token) === false, 'sealed, not stored in the clear');
check($mails[count($mails) - 1]['template'] === 'instance_transfer_code_ready', 'the customer is told the code is ready');

$driver->transfers[$token] = 'pending';
check(InstanceTransfers::poll($row) === false && $row->state() === InstanceTransfer::STATE_CODE_ISSUED,
	'a pending code changes nothing');

$driver->transfers[$token] = 'stale';
InstanceTransfers::poll($row);
check($row->state() === InstanceTransfer::STATE_INVITED && trim((string)$row->get('itx_token_sealed')) === '',
	'a code that ran out sends the row back to waiting, and the code is erased');
check($mails[count($mails) - 1]['template'] === 'instance_transfer_code_expired', 'and the customer is told it expired');

$row = InstanceTransfers::issue_code($row, 'customer');
$token2 = $driver->last_token;
$row = InstanceTransfers::cancel($row, 'customer');
check($row->state() === InstanceTransfer::STATE_CANCELED && $driver->transfers[$token2] === 'canceled' && $driver->cancels === 1,
	'cancel withdraws a pending code at the provider first');

section('Linode\'s refusal is shown verbatim; a refused cancel follows the provider');

$row = InstanceTransfers::start($p);
$driver->create_error = 'Linode 42 has a Cloud Firewall attached.';
$said = '';
try { InstanceTransfers::issue_code($row, 'operator'); } catch (InstanceTransferFlowException $e) { $said = $e->getMessage(); }
check(strpos($said, 'Linode 42 has a Cloud Firewall attached.') !== false && $row->state() === InstanceTransfer::STATE_INVITED,
	'the operator sees Linode\'s reason and the row stays where it was', $said);
$said = '';
try { InstanceTransfers::issue_code($row, 'customer'); } catch (InstanceTransferFlowException $e) { $said = $e->getMessage(); }
check(strpos($said, 'Cloud Firewall') === false && strpos($said, 'We have been told') !== false,
	'the customer is told we are on it, not the internals', $said);
$driver->create_error = null;

$row = InstanceTransfers::issue_code($row, 'operator');
$token3 = $driver->last_token;
$driver->transfers[$token3] = 'accepted';
$said = '';
try { InstanceTransfers::cancel($row, 'operator'); } catch (InstanceTransferFlowException $e) { $said = $e->getMessage(); }
check($said !== '' && $row->state() === InstanceTransfer::STATE_ACCEPTED && $driver->transfers[$token3] === 'accepted',
	'a code already accepted cannot be canceled; the row follows the provider to accepted', $said);
check(!$row->cancelable(), 'and Cancel is gone');

section('A poll and a cancel at the same moment do not undo each other');

list($pz) = xfer_site('xfer-z-' . $suffix);
$rz = InstanceTransfers::issue_code(InstanceTransfers::start($pz), 'operator');
$tz = $driver->last_token;
// While the poll is asking Linode, the customer cancels.
$driver->on_status = function ($token) use ($rz, $driver) {
	$other = new InstanceTransfer((int)$rz->key, TRUE);
	$driver->transfers[$token] = 'pending';
	InstanceTransfers::cancel($other, 'customer');
};
$signals = array();
InstanceTransfers::poll($rz);
$fresh = new InstanceTransfer((int)$rz->key, TRUE);
check($fresh->state() === InstanceTransfer::STATE_CANCELED && trim((string)$fresh->get('itx_token_sealed')) === '',
	'the cancel stands: the poll does not save its stale copy over it', $fresh->state());

section('A code Linode no longer knows, or one that cannot be read');

list($pe) = xfer_site('xfer-e-' . $suffix);
$re = InstanceTransfers::issue_code(InstanceTransfers::start($pe), 'operator');
$re->set('itx_token_expiry', gmdate('Y-m-d H:i:s', time() - 7200));
$re->save();
$driver->status_error = new CloudComputeException('Linode API GET account/service-transfers/[code] failed (404): Not found', 404);
$mails = array();
InstanceTransfers::poll($re);
$driver->status_error = null;
check($re->state() === InstanceTransfer::STATE_INVITED && count($mails) === 1 && $mails[0]['template'] === 'instance_transfer_code_expired',
	'an expired code Linode answers 404 for is stale: back to waiting, and the customer is told');

list($pu) = xfer_site('xfer-u-' . $suffix);
$ru = InstanceTransfers::issue_code(InstanceTransfers::start($pu), 'operator');
$driver->transfers[$driver->last_token] = 'accepted';
InstanceTransfers::poll($ru);
$ru->set('itx_token_sealed', 'not-a-sealed-value');
$ru->save();
check(InstanceTransfers::poll($ru) === false && $ru->state() === InstanceTransfer::STATE_ACCEPTED
	&& strpos((string)$ru->get('itx_error'), 'cannot be read') !== false,
	'an accepted move whose code cannot be read says so on the row, rather than being followed silently');

section('The collection\'s state options narrow together');

check(count(new MultiInstanceTransfer(array('provision_id' => (int)$pu->key, 'state' => InstanceTransfer::STATE_DONE,
		'open' => true, 'deleted' => false))) === 0
	&& count(new MultiInstanceTransfer(array('provision_id' => (int)$pu->key, 'state' => InstanceTransfer::STATE_ACCEPTED,
		'open' => true, 'deleted' => false))) === 1,
	'state and open together mean both, not whichever was written last');

section('A failed move tells both sides; the operator can issue again');

list($pf) = xfer_site('xfer-f-' . $suffix);
$rf = InstanceTransfers::issue_code(InstanceTransfers::start($pf), 'operator');
$driver->transfers[$driver->last_token] = 'failed';
$signals = array();
InstanceTransfers::poll($rf);
check($rf->state() === InstanceTransfer::STATE_FAILED, 'failed at Linode is failed here');
check(count($signals) === 1 && strpos($signals[0]['payload']['detail'], InstanceTransfers::label_hint($pf)) !== false,
	'the operator is alerted, with the label most likely to clash');
check($mails[count($mails) - 1]['template'] === 'instance_transfer_failed', 'the customer is told we are looking into it');
$rf = InstanceTransfers::issue_code($rf, 'operator');
check($rf->state() === InstanceTransfer::STATE_CODE_ISSUED, 'and a failed row can be given a new code');

// ── The finish ────────────────────────────────────────────────────────────────

section('The finish waits for the instance to leave, then resumes where it stopped');

$driver->transfers[$token3] = 'completed';
InstanceTransfers::poll($row);
check($row->state() === InstanceTransfer::STATE_FINISHING, 'completed hands the row to the finish');
check(trim((string)$row->get('itx_token_sealed')) === '', 'and the code, no longer needed, is erased');

$driver->instance_present = true;
check(InstanceTransferFinish::run($row) === false && (string)$row->get('itx_finish_step') === ''
	&& !$p->is_transferred(), 'while our token still sees the instance, nothing is done');

$driver->instance_present = false;
InstanceTransferFinish::run($row);
check($row->state() === InstanceTransfer::STATE_DONE, 'once it is gone, the finish runs to done');
$p->load();
$t->load();
check($p->is_transferred() && !$p->is_operator_hosted(), 'the provision says transferred, and is no longer ours to host');
check((string)$t->get('htr_state') === HostedTrial::STATE_TRANSFERRED, 'hosting is transferred');
check(strpos((string)$row->get('itx_operator_todo'), 'Cancel the hosting subscription') !== false,
	'a subscription in a store elsewhere is a to-do on the row, not a silent skip');

$mail = ServiceTenant::forNode((int)$n->key, ServiceTenant::SERVICE_MAIL);
$shelf = ServiceTenant::forNode((int)$n->key, ServiceTenant::SERVICE_SHELF);
check($mail !== null && $shelf !== null, 'two node-linked Services rows exist: mail and backup storage');
check($mail && (string)$mail->get('svt_provider_subaccount_id') === 'sub-xfer-a-' . $suffix
	&& (string)$mail->get('svt_provider_user_id') === 'smtp-xfer-a-' . $suffix
	&& (string)$mail->get('svt_provider_domain') === 'mail.xfer-a-' . $suffix . '.example.com'
	&& (string)$mail->get('svt_mail_state') === ServiceTenant::MAIL_DOMAIN_VERIFIED
	&& count($mail->mailRecords()) === 1 && (string)$mail->get('svt_state') === ServiceTenant::STATE_ACTIVE,
	'the mail row is the provision\'s provider pieces: subaccount, user, sender domain (not its id), records');
check(trim((string)$p->get('cvp_smtp2go_subaccount_id')) === '' && trim((string)$p->get('cvp_smtp2go_user_id')) === '',
	'and the provision lets go of them, so the webhook stops matching it');
check($mail && $shelf && $mail->get('svt_paid_until') && $mail->get('svt_paid_until') === $shelf->get('svt_paid_until')
	&& $mail->get('svt_paid_until') === $row->get('itx_paid_until'), 'both are paid to the end of the hosting period');
check(JoineryServices::countWebhookSend('smtp-xfer-a-' . $suffix, '') === true, 'a webhook send now counts against the tenant');
$mail->load();
check((int)$mail->get('svt_figure') === 1, 'and moves its figure');

$step_after = (string)$row->get('itx_finish_step');
$tenants_before = count(new MultiServiceTenant(array('node_id' => (int)$n->key, 'deleted' => false)));
$row->set('itx_state', InstanceTransfer::STATE_FINISHING);
$row->set('itx_finish_step', 'billing');
$row->save();
InstanceTransferFinish::run($row);
check($step_after === 'told' && count(new MultiServiceTenant(array('node_id' => (int)$n->key, 'deleted' => false))) === $tenants_before,
	'resumed after billing, the services step finds its rows and makes no second set');

$done_mails = count(array_filter($mails, function ($m) { return $m['template'] === 'instance_transfer_done'; }));
$row->set('itx_state', InstanceTransfer::STATE_FINISHING);
$row->set('itx_finish_step', 'banner');
$row->save();
$alerts_before = count($signals);
InstanceTransferFinish::run($row);
check($done_mails === 1 && count(array_filter($mails, function ($m) { return $m['template'] === 'instance_transfer_done'; })) === 1
	&& count($signals) === $alerts_before, 'the last step run again sends no second email and no second alert');

$done_mail = null;
foreach ($mails as $m) { if ($m['template'] === 'instance_transfer_done') { $done_mail = $m; } }
check($done_mail['template'] === 'instance_transfer_done' && strpos($done_mail['vars']['dns_records'], 's1._domainkey') !== false,
	'the customer\'s last email lists the DNS records we hold for them');

section('The store\'s cancel arriving afterwards changes nothing');

HostedTrialSignals::handle_signal('subscription.cancelled', array('order_item_id' => (int)$p->get('cvp_external_order_item_id')));
HostedTrialSignals::handle_signal('subscription.payment_failed', array('order_item_id' => (int)$p->get('cvp_external_order_item_id')));
$t->load();
check((string)$t->get('htr_state') === HostedTrial::STATE_TRANSFERRED && !$t->get('htr_grace_ends_time'),
	'a transferred hosting row never enters grace');

section('Reverse DNS is the customer\'s now');

$said = '';
try { NodeReverseDns::set($n, 'mail.xfer-a-' . $suffix . '.example.com', null, true); } catch (NodeReverseDnsException $e) { $said = $e->getMessage(); }
check($said === NodeReverseDns::TRANSFERRED_MESSAGE && strpos($said, 'reconnect') === false,
	'a moved site is told to use its own Cloud Manager, with no reconnect', $said);

// ── Node-linked Services rows ─────────────────────────────────────────────────

section('Backup storage for a moved site acts on the node\'s own fleet backups');

$watch = new ServiceTenantWatch();
$watch->client = null;   // no mail provider: nothing here may reach SMTP2GO
$shelf->load();
$cap = JoineryServices::allowance(ServiceTenant::SERVICE_SHELF);
$n->set('mgn_backup_shelf_bytes', $cap + 1);
$n->save();
$watch->watch($shelf);
$n->load();
check(NodeBackupShelf::paused_for_shelf($n) && (int)$shelf->get('svt_figure') === $cap + 1,
	'over the allowance, the node\'s backups pause and the figure is the node\'s bytes');
$n->set('mgn_backup_shelf_bytes', 1024);
$n->save();
$watch->watch($shelf);
$n->load();
check(FleetBackupPolicy::stored_mode($n) === 'default', 'back under it, the pause it made lifts');

JoineryServices::suspend($shelf);
$n->load();
check(NodeBackupShelf::suspended_for_services($n) && FleetBackupPolicy::stored_mode($n) === 'off'
	&& $shelf->get('svt_prune_after_time'), 'suspended: fleet backups off with the reason, and the retention clock runs');
JoineryServices::reactivate($shelf);
$n->load();
check(FleetBackupPolicy::stored_mode($n) === 'default', 'paid again: back on');

$n->set('mgn_backup_policy', json_encode(array('enabled' => false)));
$n->save();
JoineryServices::reactivate($shelf);
$n->load();
check(FleetBackupPolicy::stored_mode($n) === 'off', 'a policy a person switched off stays off');
$n->set('mgn_backup_policy', null);
$n->save();

$banner = ServiceTenantWatch::node_banner_settings((int)$n->key);
check($banner['state'] === 'services' && count(json_decode($banner['allowances'], true)) === 2 && $banner['until_time'] !== '',
	'the moved site\'s banner is the services standing: both rows, the paid-through date');
// The finish already filed one; say the node took it, so a changed banner files anew.
$db->exec("UPDATE mjb_management_jobs SET mjb_status = 'completed' WHERE mjb_mgn_managed_node_id = " . (int)$n->key
	. " AND mjb_job_type = 'hosted_plan_notice'");
$first = ServiceTenantWatch::push_node_banner($n);
$second = ServiceTenantWatch::push_node_banner($n);
check($first === 'filed' && $second === 'same', 'it is pushed once, and not again while it is unchanged', $first . '/' . $second);

$shelf->set('svt_state', ServiceTenant::STATE_SUSPENDED);
$shelf->set('svt_prune_after_time', '2000-01-01 00:00:00');
$shelf->set('svt_paid_until', '2000-01-01 00:00:00');
$shelf->set('svt_lapse_time', '2000-01-01 00:00:00');
$shelf->set('svt_revoked_time', '2000-01-01 00:00:00');
$shelf->save();
$watch->watch($shelf);
check($shelf->get('svt_pruned_time') === null,
	'a prune that cannot reach backup storage is not recorded as done');

section('The Connected sites page shows it, and Disconnect cannot close its mail');

$sites = ServicesConnect::sitesFor((int)$p->get('cvp_usr_user_id'));
check(count($sites) === 1 && $sites[0]['node_linked'] === true && $sites[0]['active'] === false,
	'a moved site is listed as moved from Managed, with no live key');
$said = '';
try { ServicesConnect::disconnect((int)$p->get('cvp_usr_user_id'), 'xfer-a-' . $suffix . '.example.com'); }
catch (Throwable $e) { $said = $e->getMessage(); }
$mail->load();
check($said !== '' && (string)$mail->get('svt_state') === ServiceTenant::STATE_ACTIVE,
	'Disconnect refuses it, and its mail stays open', $said);

// ── Stop managing ─────────────────────────────────────────────────────────────

section('Stop managing leaves nothing watching, and nothing reopens');

$shelf->set('svt_state', ServiceTenant::STATE_ACTIVE);
$shelf->set('svt_prune_after_time', null);
$shelf->set('svt_revoked_time', null);
$shelf->set('svt_lapse_time', null);
$shelf->set('svt_paid_until', gmdate('Y-m-d H:i:s', time() + 30 * 86400));
$shelf->save();
$inc = new IncidentRecord(NULL);
$inc->set('inc_mgn_managed_node_id', (int)$n->key);
$inc->set('inc_source', 'agent_silent');
$inc->set('inc_node_case_id', 1);
$inc->set('inc_status', IncidentRecord::STATUS_OPEN);
$inc->set('inc_triage', IncidentRecord::TRIAGE_NEW);
$inc->set('inc_title', 'The agent went quiet');
$inc->set('inc_severity', IncidentRecord::SEVERITY_WARNING);
$inc->set('inc_opened_time', gmdate('Y-m-d H:i:s'));
$inc->set('inc_first_seen_time', gmdate('Y-m-d H:i:s'));
$inc->set('inc_last_seen_time', gmdate('Y-m-d H:i:s'));
$inc->save();

$result = InstanceTransferFinish::stop_managing($p);
$n->load();
$shelf->load();
$mail->load();
check(!$n->get('mgn_agent_public_key') && !$n->get('mgn_enabled') && !$n->get('mgn_uptime_enabled')
	&& FleetBackupPolicy::stored_mode($n) === 'off', 'the agent is forgotten; backups, uptime checks and the node are off');
check($result['incidents'] === 1 && IncidentRecord::open_count((int)$n->key) === 0, 'its open incidents are closed');
check((string)$shelf->get('svt_state') === ServiceTenant::STATE_RELEASED && $shelf->get('svt_prune_after_time'),
	'backup storage is released, which starts its retention clock');
check((string)$mail->get('svt_state') === ServiceTenant::STATE_ACTIVE, 'mail carries on');
$counts = IncidentReconciler::run(null, array((int)$n->key));
check($counts['busy'] || ($counts['opened'] === 0 && $counts['reopened'] === 0 && IncidentRecord::open_count((int)$n->key) === 0),
	'the reconciler does not watch a disabled node, so nothing reopens', json_encode($counts));

// ── The code went nowhere ─────────────────────────────────────────────────────

section('The code is in no email, no job, no log line and no error');

$codes = array_filter(array($token, $token2, $token3, $driver->last_token));
$everything = json_encode($mails) . json_encode($signals) . file_get_contents($log_file);
$jobs = $db->query("SELECT COALESCE(string_agg(mjb_commands::text || ' ' || COALESCE(mjb_parameters::text, ''), ' '), '')
	FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id IN (" . (int)$n->key . ', ' . (int)$nk->key . ')')->fetchColumn();
$errors = $db->query("SELECT COALESCE(string_agg(COALESCE(itx_error, '') || ' ' || COALESCE(itx_operator_todo, ''), ' '), '')
	FROM itx_instance_transfers")->fetchColumn();
$leaked = array();
foreach ($codes as $code) {
	foreach (array('emails/signals/log' => $everything, 'jobs' => $jobs, 'row errors' => $errors) as $where => $haystack) {
		if (strpos((string)$haystack, $code) !== false) { $leaked[] = $where; }
	}
}
check(count($codes) >= 4 && $leaked === array(), 'no transfer code appears anywhere but its sealed column',
	implode(', ', array_unique($leaked)));

// ---------------------------------------------------------------------------
section('Cleanup');
ini_set('error_log', $previous_log ?: '');
@unlink($log_file);
InstanceTransfers::$driver = null;
InstanceTransfers::$mailer = null;
InstanceTransfers::$signal = null;
IncidentReconciler::$dispatch = null;
$db->rollBack();
check(true, 'every fixture rolled back');

harness_finish();
