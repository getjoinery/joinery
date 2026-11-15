<?php
/** @joinery-test
 * name: hosted_suspension
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Suspend and Unsuspend a hosted site, and what a suspension or a disputed
 * payment means for the buyer's next order (HostedSuspensions,
 * specs/hosting_launch.md S6).
 *
 *  - Suspend on a Managed site powers its instance off; on a Starter site it
 *    asks the host to stop and hold the container. Either way the mail
 *    subaccount is closed, backups and uptime checks are switched off, the
 *    customer is emailed the reason, and a record names who, when and why.
 *  - A refused stop records nothing: a site is never shown suspended while it
 *    still serves.
 *  - A Starter stop reads as asked until the host's answer says held, and one
 *    that did not take is asked again after an hour. The host page's Start and
 *    Replace, and a site copy, are refused for a suspended site.
 *  - Unsuspend reverses each step and puts back the node's own backup policy
 *    and uptime check, not a default. For a site whose hosting ended unpaid it
 *    lifts the suspension without starting it.
 *  - A won dispute clears the mark; a lost one keeps it.
 *  - An open suspension or a disputed payment, by account or by card, holds
 *    the buyer's next paid hosted order for a person.
 *
 * Everything runs inside one transaction that is rolled back. The cloud
 * provider, SMTP2GO and the customer email are stand-ins.
 *
 * Run: php plugins/server_manager/tests/hosted_suspension_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/node_detail_actions_logic.php'));

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();

$suffix = bin2hex(random_bytes(4));
$user_id = 980000 + random_int(0, 9999);
$card = 'fp-hsu-' . $suffix;

// ── Stand-ins ──
$calls = array();
HostedSuspensions::$driver_stand_in = function ($provision) use (&$calls) {
	return new class($calls) {
		private $calls;
		public $refuse = false;
		public function __construct(&$calls) { $this->calls = &$calls; }
		public function shutdownInstance(string $id): void {
			if (HsuState::$refuse) { throw new RuntimeException('the provider refused'); }
			$this->calls[] = 'shutdown:' . $id;
		}
		public function bootInstance(string $id): void { $this->calls[] = 'boot:' . $id; }
	};
};
class HsuState { public static $refuse = false; }
HostedSuspensions::$mail_client = new class($calls) {
	private $calls;
	public function __construct(&$calls) { $this->calls = &$calls; }
	public function closeSubaccount(string $id): void { $this->calls[] = 'mail-close:' . $id; }
	public function reopenSubaccount(string $id): void { $this->calls[] = 'mail-reopen:' . $id; }
};
$mails = array();
HostedSuspensions::$mailer = function ($to, $subject, $body) use (&$mails) {
	$mails[] = array('to' => $to, 'subject' => $subject, 'body' => $body);
};

// ── Fixtures ──
$mk_node = function (string $tag, array $fields = array()) use ($suffix): ManagedNode {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest hsu ' . $tag . ' ' . $suffix);
	$n->set('mgn_slug', 'harnesshsu-' . $tag . '-' . $suffix);
	$n->set('mgn_host', '192.0.2.71');
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_enabled', true);
	foreach ($fields as $k => $v) { $n->set($k, $v); }
	$n->save();
	$n->load();
	return $n;
};
$mk_provision = function (string $tag, ManagedNode $node, array $fields = array()) use ($suffix, $user_id, $card): CustomerCloudProvision {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'order');
	$p->set('cvp_external_order_item_id', 970000000 + random_int(0, 9999999));
	$p->set('cvp_usr_user_id', $user_id);
	$p->set('cvp_domain', 'hsu-' . $tag . '-' . $suffix . '.example.com');
	$p->set('cvp_slug', 'hsu' . $tag . $suffix);
	$p->set('cvp_hosting_mode', 'operator');
	$p->set('cvp_status', 'done');
	$p->set('cvp_buyer_email', 'hsu-' . $tag . '@example.com');
	$p->set('cvp_card_fingerprint', $card);
	$p->set('cvp_mgn_managed_node_id', (int)$node->key);
	foreach ($fields as $k => $v) { $p->set($k, $v); }
	$p->save();
	$p->load();
	return $p;
};

$m_node = $mk_node('managed', array('mgn_uptime_enabled' => true,
	'mgn_backup_policy' => json_encode(array('enabled' => true, 'hour' => 3))));
$managed = $mk_provision('m', $m_node, array('cvp_instance_id' => '424242', 'cvp_smtp2go_subaccount_id' => 'sub-m-' . $suffix));

// ---------------------------------------------------------------------------
section('Suspend a Managed site');

try {
	HostedSuspensions::suspend($managed, "  \n ", 1);
	check(false, 'an empty reason is refused');
} catch (RuntimeException $e) {
	check(HostedSuspension::open_for($managed->key) === null, 'an empty reason is refused, and nothing is recorded', $e->getMessage());
}

HsuState::$refuse = true;
try {
	HostedSuspensions::suspend($managed, 'Phishing page', 1);
	check(false, 'a refused power-off is reported');
} catch (Throwable $e) {
	check(HostedSuspension::open_for($managed->key) === null && !in_array('mail-close:sub-m-' . $suffix, $calls, true),
		'a power-off the provider refuses records nothing and leaves the mail alone', $e->getMessage());
}
HsuState::$refuse = false;

$row = HostedSuspensions::suspend($managed, "Phishing\n page   reported by a bank", 1);
$m_node->load();
check(in_array('shutdown:424242', $calls, true), 'the instance is powered off');
check(in_array('mail-close:sub-m-' . $suffix, $calls, true), 'the mail subaccount is closed');
check(!(bool)$m_node->get('mgn_uptime_enabled') && (json_decode((string)$m_node->get('mgn_backup_policy'), true)['enabled'] ?? null) === false,
	'backups and uptime checks are switched off');
check((string)$row->get('hsu_reason') === 'Phishing page reported by a bank' && (int)$row->get('hsu_suspend_usr_user_id') === 1,
	'the record names the reason, on one line, and who', (string)$row->get('hsu_reason'));
$last = end($mails);
check($last && $last['to'] === 'hsu-m@example.com' && strpos($last['body'], 'Phishing page reported by a bank') !== false,
	'the customer is emailed the reason');
check(HostedSuspension::open_for($managed->key) !== null, 'the site reads as suspended');

try {
	HostedSuspensions::suspend($managed, 'Again', 1);
	check(false, 'a second suspend is refused');
} catch (RuntimeException $e) {
	check(true, 'a site already suspended cannot be suspended again');
}

// ---------------------------------------------------------------------------
section('The buyer\'s next order');

check(strpos((string)CustomerCloudFulfillment::over_limit_reason($user_id, '', 0), 'suspended site') !== false,
	'the same account\'s next order is held for a person');
check(strpos((string)CustomerCloudFulfillment::over_limit_reason($user_id + 1, $card, 0), 'suspended site') !== false,
	'the same card on another account is held too');
check(CustomerCloudFulfillment::over_limit_reason($user_id + 2, 'fp-other-' . $suffix, 0) === null,
	'another buyer on another card is not held');

// ---------------------------------------------------------------------------
section('Unsuspend the Managed site');

$mails_before = count($mails);
$row = HostedSuspensions::unsuspend($managed, 2);
$m_node->load();
check(in_array('boot:424242', $calls, true) && in_array('mail-reopen:sub-m-' . $suffix, $calls, true),
	'the instance is powered on and the mail reopened');
check((bool)$m_node->get('mgn_uptime_enabled') && (json_decode((string)$m_node->get('mgn_backup_policy'), true)['hour'] ?? null) === 3,
	'the node\'s own backup policy and uptime check are put back');
check($row->get('hsu_unsuspend_time') && (int)$row->get('hsu_unsuspend_usr_user_id') === 2 && count($mails) === $mails_before + 1,
	'the record is closed with who lifted it, and the customer is emailed');
check(HostedSuspension::open_for($managed->key) === null
	&& CustomerCloudFulfillment::over_limit_reason($user_id, $card, 0) === null,
	'lifted, the site and the buyer are no longer flagged');

// ---------------------------------------------------------------------------
section('A site whose hosting ended unpaid stays off');

$trial = new HostedTrial(NULL);
$trial->set('htr_cvp_customer_cloud_provision_id', (int)$managed->key);
$trial->set('htr_state', HostedTrial::STATE_SHUTDOWN);
$trial->save();
$shutdowns = count(array_filter($calls, function ($c) { return strpos($c, 'shutdown:') === 0; }));
$boots = count(array_filter($calls, function ($c) { return strpos($c, 'boot:') === 0; }));
$row = HostedSuspensions::suspend($managed, 'Malware', 1);
check(count(array_filter($calls, function ($c) { return strpos($c, 'shutdown:') === 0; })) === $shutdowns
	&& strpos(implode(' ', $row->steps()), 'Not stopped again') !== false,
	'an instance its hosting already powered off is not powered off again (B6)');
$m_node->load();
$row = HostedSuspensions::unsuspend($managed, 2);
$m_node->load();
check(count(array_filter($calls, function ($c) { return strpos($c, 'boot:') === 0; })) === $boots
	&& $row->get('hsu_unsuspend_time') && HostedSuspension::open_for($managed->key) === null,
	'its suspension is lifted, and it is not started (B3)');
check(!(bool)$m_node->get('mgn_uptime_enabled'), 'backups and uptime stay off, as the end of its hosting set them');
$trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
$trial->save();

// ---------------------------------------------------------------------------
section('Suspend and Unsuspend a Starter site');

$words = array();
foreach (get_class_methods('JobCommandBuilder') as $m) {
	if (preg_match('/^build_([a-z0-9_]+)_primitive$/', $m, $mm)) { $words[] = $mm[1]; }
}
$host_node = $mk_node('host', array(
	'mgn_agent_public_key' => base64_encode(random_bytes(32)),
	'mgn_agent_version'    => AgentVocabulary::FLOOR,
	'mgn_agent_primitives' => implode(',', $words),
));
$mgh = new ManagedHost(NULL);
$mgh->set('mgh_name', 'HarnessTest hsu host ' . $suffix);
$mgh->set('mgh_slug', 'harnesshsu-' . $suffix);
$mgh->set('mgh_host', '192.0.2.71');
$mgh->set('mgh_mgn_managed_node_id', (int)$host_node->key);
$mgh->save();
$mgh->load();
$container = 'harnesshsu' . $suffix;
$s_node = $mk_node('starter', array('mgn_container_name' => $container, 'mgn_mgh_managed_host_id' => (int)$mgh->key,
	'mgn_uptime_enabled' => true));
$starter = $mk_provision('s', $s_node, array('cvp_landing' => 'container', 'cvp_mgh_managed_host_id' => (int)$mgh->key,
	'cvp_usr_user_id' => $user_id + 5, 'cvp_card_fingerprint' => 'fp-s-' . $suffix));

$row = HostedSuspensions::suspend($starter, 'Spam', 1);
check(HostedSuspension::open_for($starter->key) !== null && HostedSuspensions::hold_outcome($row) === 'asked',
	'the record is kept from the moment the stop is asked, and reads as asked, not confirmed (B1, B4)');
$job = new ManagementJob((int)$row->get('hsu_hold_job_id'), TRUE);
$params = json_decode((string)$job->get('mjb_parameters'), true);
check((int)$job->get('mjb_mgn_managed_node_id') === (int)$host_node->key && ($params['action'] ?? '') === 'stop'
	&& ($params['name'] ?? '') === $container, 'the host is asked to stop and hold the site\'s container', json_encode($params));
check(strpos((string)($params['reason'] ?? ''), 'Spam') !== false && strpos((string)$job->get('mjb_commands'), 'Spam') === false,
	'the reason is on the job, and the host is never sent it');
check(strpos(implode(' ', $row->steps()), 'No SMTP2GO subaccount') !== false, 'a site with no subaccount says so instead of failing');

// The host answers without holding it: the box says so, and an hour on it is asked again.
$job->set('mjb_status', 'completed');
$job->set('mjb_result', json_encode(array('action' => 'stop', 'done' => false, 'held' => false)));
$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
$job->save();
check(HostedSuspensions::hold_outcome($row) === 'refused', 'an answer that did not hold it reads as refused');
check(HostedSuspensions::recheck_hold($starter) === 0, 'it is not asked again within the hour');
$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s', time() - 7200));
$job->save();
check(HostedSuspensions::recheck_hold($starter) === 1, 'after an hour the host is asked again');
$row = HostedSuspension::open_for($starter->key);
$again = new ManagementJob((int)$row->get('hsu_hold_job_id'), TRUE);
check((int)$again->key !== (int)$job->key, 'the record follows the new ask');
$again->set('mjb_status', 'completed');
$again->set('mjb_result', json_encode(array('action' => 'stop', 'done' => true, 'held' => true)));
$again->save();
check(HostedSuspensions::hold_outcome($row) === 'held', 'an answer that held it reads as held');

// The host page's Start and Replace do not lift it (B2).
check(HostedSuspensions::open_for_container($host_node, $container) !== null, 'the host knows its container is suspended');
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$session = SessionControl::get_instance();
$base_url = '/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$host_node->key;
$holds = function () use ($db, $host_node): int {
	$q = $db->prepare("SELECT count(*) FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type IN ('hold_container', 'recreate_site')");
	$q->execute(array((int)$host_node->key));
	return (int)$q->fetchColumn();
};
$before = $holds();
foreach (array(array('action' => 'hold_container', 'op' => 'start'), array('action' => 'recreate_site')) as $post) {
	$_POST = $post + array('name' => $container, SmAdminCsrf::FIELD => SmAdminCsrf::token());
	$r = NodeDetailActions::dispatch($host_node, $session, $base_url, '/\/admin\/server_manager/');
	check($r === $base_url . '&tab=overview' && $holds() === $before, $post['action'] . ' is refused for a suspended site', (string)$r);
}
$_POST = array();
check(in_array(true, array_map(function ($w) { return strpos($w, 'suspended') !== false; },
	SiteCopyRunner::source_refusals($s_node)), true), 'a suspended site cannot be copied');

$row = HostedSuspensions::unsuspend($starter, 1);
$q = $db->prepare("SELECT mjb_parameters FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'hold_container' ORDER BY mjb_management_job_id DESC LIMIT 1");
$q->execute(array((int)$host_node->key));
$params = json_decode((string)$q->fetchColumn(), true);
check(($params['action'] ?? '') === 'start' && ($params['name'] ?? '') === $container, 'Unsuspend asks the host to start it again');

// ---------------------------------------------------------------------------
section('A disputed payment');

$line = (string)(int)$starter->get('cvp_external_order_item_id');
$dispute = function (string $signal, string $id, string $status = '') use ($line) {
	HostedSuspensions::handle_signal($signal, array('order_item_ids' => $line, 'dispute_id' => $id, 'status' => $status));
};
$dispute('order.disputed', 'dp_1');
$starter->load();
check((bool)$starter->get('cvp_disputed_time'), 'the disputed order\'s site is marked');
check(strpos((string)CustomerCloudFulfillment::over_limit_reason($user_id + 9, 'fp-s-' . $suffix, 0), 'disputed payment') !== false,
	'the disputed card\'s next order is held for a person, on any account');
$dispute('order.dispute_closed', 'dp_1', 'lost');
$starter->load();
check((bool)$starter->get('cvp_disputed_time'), 'a lost dispute keeps the mark');
$dispute('order.disputed', 'dp_2');
$dispute('order.disputed', 'dp_3');
$dispute('order.dispute_closed', 'dp_1', 'won');
$dispute('order.dispute_closed', 'dp_2', 'won');
$starter->load();
check((bool)$starter->get('cvp_disputed_time'), 'one won dispute does not clear a second still open on the same line (N2)');
$dispute('order.dispute_closed', 'dp_3', 'won');
$dispute('order.dispute_closed', 'dp_other', 'lost');
$starter->load();
check(!$starter->get('cvp_disputed_time'), 'once every dispute is won the mark clears, and a close it never had is ignored (B7)');
$dispute('order.disputed', 'dp_4');
$starter->load();
$starter->soft_delete();
check(strpos((string)CustomerCloudFulfillment::over_limit_reason($user_id + 5, '', 0), 'disputed payment') !== false,
	'removing the site does not clear the buyer');

// ---------------------------------------------------------------------------
section('Cleanup');

HostedSuspensions::$driver_stand_in = null;
HostedSuspensions::$mail_client = null;
HostedSuspensions::$mailer = null;
$db->rollBack();
$left = (int)$db->query("SELECT COUNT(*) FROM cvp_customer_cloud_provisions WHERE cvp_slug LIKE 'hsu%" . $suffix . "'")->fetchColumn();
check($left === 0, 'every provision this test created is gone', $left . ' left');

harness_finish();
