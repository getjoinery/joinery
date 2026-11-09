<?php
/** @joinery-test
 * name: test_site_discard
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Discard test site (TestSiteDiscard), against a fake provider, DNS and mail
 * provider in the test database:
 *
 *  - offered only for a server in a cloud account of ours flagged test;
 *  - refused while the site is still being built, and while its server hosts
 *    another site;
 *  - the safety catch: an account whose company name, read at the click, is
 *    not the disposable one deletes nothing;
 *  - a full discard: the server, every record at or under the site's name and
 *    every address record naming the server in the test zone (nothing else),
 *    the mail subaccount, the test subscription, and the site's node and its
 *    Docker host's node, the provision with them;
 *  - a step that fails keeps the records here, and the next click finishes
 *    without deleting anything twice; a subaccount already closed is not
 *    closed again.
 *
 *  - refused for a live-payment order, and when another site's domain sits
 *    under its own, and when its box is on the Main side of the dashboard;
 *    the Docker host's node goes before the site's;
 *  - the subscription is cancelled on the test side, and the line stamped
 *    cancelled when the provider's part did not; a subaccount SMTP2GO no
 *    longer lists counts as gone.
 *
 * @version 1.2 - a test site's mail trap follows the same account (ProvisionHostedMail::test_trap)
 * @version 1.1 - fixture boxes are on the Test side; a Main-side box is refused
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

/** The test account: what it says its company is, and its servers. */
class FakeDiscardCloud implements CloudComputeProvider, CloudAccountCleanup, CloudTokenScopes, CloudAccountIdentity {
	public $company = TestCloudCleanup::COMPANY;
	public $servers = array();
	public $deleted = array();
	public $asked = 0;
	public function accountCompany(): string { return $this->company; }
	public function tokenScopes(): array { return array('*'); }
	public function accountName(): string { return 'Joinery Test disposable (user test)'; }
	public static function missingScopes(array $granted, array $needed): array { return array(); }
	public function getInstance(string $id): array {
		$this->asked++;
		if (!isset($this->servers[$id])) { throw new CloudComputeException('Not found', 404); }
		return $this->servers[$id];
	}
	public function deleteInstance(string $id): void { $this->deleted[] = $id; unset($this->servers[$id]); }
	public function listVolumes(): array { return array(); }
	public function deleteVolume(string $volume_id): void {}
	public function createInstance(array $opts): array { throw new CloudComputeException('not in this test'); }
	public function rebuildInstance(string $id, array $opts): array { throw new CloudComputeException('not in this test'); }
	public function shutdownInstance(string $id): void {}
	public function bootInstance(string $id): void {}
	public function getTransfer(): array { return array(); }
	public function setReverseDns(string $id, string $ip, string $hostname): array { return array(); }
}

/** The test zone's DNS. */
class FakeDiscardDns {
	public $records = array();
	public $fail_on = '';
	public function zoneFor(string $domain): ?string { return $domain === 'joinerytest.com' ? 'zone-1' : null; }
	public function listRecords(string $zone): array { return array_values($this->records); }
	public function deleteRecord(string $zone, DnsRecord $r): void {
		if ($this->fail_on !== '' && $r->name === $this->fail_on) { throw new DnsProviderException('the provider refused'); }
		unset($this->records[$r->provider_id]);
	}
	public function add(string $type, string $name, string $value): void {
		$r = new DnsRecord($type, $name, $value);
		$r->provider_id = 'r' . (count($this->records) + 1) . '-' . $name;
		$this->records[$r->provider_id] = $r;
	}
	public function names(): array {
		return array_map(function ($r) { return $r->type . ' ' . $r->name; }, array_values($this->records));
	}
}

/** An SMTP2GO client answering $state for the subaccount, then OK to anything else, recording each path. */
function tsd_mail(string $sub, string $state, array &$history): Smtp2GoClient {
	$list = array('subaccounts' => array(array('id' => 'other1', 'state' => 'Active'), array('id' => $sub, 'state' => $state)), 'continue_token' => '');
	$responses = array(new \GuzzleHttp\Psr7\Response(200, array(), json_encode(array('data' => $list))));
	for ($i = 0; $i < 4; $i++) {
		$responses[] = new \GuzzleHttp\Psr7\Response(200, array(), json_encode(array('data' => array())));
	}
	$stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
	$stack->push(\GuzzleHttp\Middleware::history($history));
	return new Smtp2GoClient('test-key', new \GuzzleHttp\Client(array('handler' => $stack)));
}

function tsd_paths(array $history): array {
	return array_map(function ($h) {
		$p = $h['request']->getUri()->getPath();
		return basename(dirname($p)) . '/' . basename($p);
	}, $history);
}

/** Rows the discard is expected to remove: deleted at the end only if a failure left them. */
$tsd_rows = array();
harness_defer(function () use (&$tsd_rows) {
	$db = DbConnector::get_instance()->get_db_link();
	foreach (array_reverse($tsd_rows) as $r) {
		$db->prepare('DELETE FROM ' . $r[0] . ' WHERE ' . $r[1] . ' = ?')->execute(array($r[2]));
	}
});

$cloud = new FakeDiscardCloud();
OperatorCloudAccount::$driver_factory = function (string $token) use (&$cloud) { return $cloud; };
harness_defer(function () { OperatorCloudAccount::$driver_factory = null; });

$db = DbConnector::get_instance()->get_db_link();
$tag = bin2hex(random_bytes(3));
$super = make_user('Tsd' . $tag, 10);
$uid = (int)$super->key;

$test_account = OperatorCloudAccount::add_key('tok-test-' . $tag, $uid);
harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $test_account->key);
check((bool)$test_account->get('oca_is_test'), 'the fixture account is flagged test (its company is the disposable one)');

$node = function (string $name, string $slug, int $host_id = 0) use (&$tsd_rows) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest ' . $name);
	$n->set('mgn_slug', $slug);
	$n->set('mgn_host', '192.0.2.60');
	$n->set('mgn_ssh_user', 'root');
	$n->set('mgn_enabled', true);
	$n->set('mgn_cloud_account', CloudAccounts::TEST);
	if ($host_id) { $n->set('mgn_mgh_managed_host_id', $host_id); }
	$n->save();
	$n->load();
	$tsd_rows[] = array('mgn_managed_nodes', 'mgn_managed_node_id', (int)$n->key);
	return $n;
};

/** A test purchase on a Docker host of its own: [provision, site node, host agent node, host, order item]. */
$site = function (string $label, bool $test_mode = true) use ($node, $uid, $tag, $test_account, &$cloud, &$tsd_rows) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $uid);
	$order->set('ord_test_mode', $test_mode);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', 999999001);
	$item->set('odi_usr_user_id', $uid);
	$item->set('odi_price', '9.99');
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->set('odi_is_subscription', true);
	$item->set('odi_subscription_status', 'active');
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);

	$agent = $node($label . '-host ' . $tag, $label . '-host-' . $tag);
	$host = new ManagedHost(NULL);
	$host->set('mgh_slug', $label . '-h-' . $tag);
	$host->set('mgh_name', '192.0.2.60');
	$host->set('mgh_host', '192.0.2.60');
	$host->set('mgh_mgn_managed_node_id', $agent->key);
	$host->set('mgh_provisioning_enabled', true);
	$host->set('mgh_cloud_account', CloudAccounts::TEST);
	$host->save();
	$host->load();
	$tsd_rows[] = array('mgh_managed_hosts', 'mgh_managed_host_id', (int)$host->key);
	$agent->set('mgn_mgh_managed_host_id', $host->key);
	$agent->save();
	$site_node = $node('test_' . $label . '.joinerytest.com', $label . '-joinerytest-com-' . $tag, (int)$host->key);

	$instance = (string)random_int(100000, 999999);
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'order');
	$p->set('cvp_external_order_item_id', $item->key);
	$p->set('cvp_usr_user_id', $uid);
	$p->set('cvp_domain', $label . '.joinerytest.com');
	$p->set('cvp_slug', $label . '-joinerytest-com');
	$p->set('cvp_status', 'done');
	$p->set('cvp_provider', 'linode');
	$p->set('cvp_hosting_mode', 'operator');
	$p->set('cvp_oca_operator_cloud_account_id', (int)$test_account->key);
	$p->set('cvp_instance_id', $instance);
	$p->set('cvp_instance_ip', '192.0.2.60');
	$p->set('cvp_instance_ipv6', '2001:db8::60');
	$p->set('cvp_mgn_managed_node_id', $site_node->key);
	$p->set('cvp_smtp2go_subaccount_id', 'sub-' . $label);
	$p->save();
	$p->load();
	$tsd_rows[] = array('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', (int)$p->key);
	$cloud->servers[$instance] = array('id' => $instance, 'label' => 'test_' . $label . '-joinerytest-com');
	return array($p, $site_node, $agent, $host, $item);
};

// ---------------------------------------------------------------------------
section('Offered only on the test account');

list($p, $site_node, $agent, $host, $item) = $site('m1');
check(TestSiteDiscard::offered($p) && TestSiteDiscard::refusal($p) === '', 'a site on the test account is offered, and may go');

$p->set('cvp_hosting_mode', 'customer');
check(!TestSiteDiscard::offered($p), 'a site in a customer\'s own cloud account is not offered');
$p->set('cvp_hosting_mode', 'operator');
$test_account->set('oca_is_test', false);
$test_account->save();
check(!TestSiteDiscard::offered($p), 'a site in an account of ours not flagged test is not offered');
$test_account->set('oca_is_test', true);
$test_account->save();

$p->set('cvp_status', 'booting');
check(strpos(TestSiteDiscard::refusal($p), 'still being built') !== false, 'a site still being built is refused', TestSiteDiscard::refusal($p));
$p->set('cvp_status', 'done');

$neighbour = $node('neighbour ' . $tag, 'neighbour-' . $tag, (int)$host->key);
$why = TestSiteDiscard::refusal($p);
check(strpos($why, 'neighbour ' . $tag) !== false, 'a server that also hosts another site is refused, naming it', $why);
$neighbour->set('mgn_mgh_managed_host_id', null);
$neighbour->save();

list($inner) = $site('x.m1');
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $inner->key);
$why = TestSiteDiscard::refusal($p);
check(strpos($why, 'x.m1.joinerytest.com') !== false, 'a site whose domain holds another site\'s is refused, naming it', $why);
$inner->set('cvp_domain', 'x-m1.joinerytest.com');
$inner->save();
check(TestSiteDiscard::refusal($p) === '', 'a look-alike name (x-m1) does not count');

list($live) = $site('live1', false);
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $live->key);
$why = TestSiteDiscard::refusal($live);
check(TestSiteDiscard::offered($live) && strpos($why, 'live payment') !== false, 'a site bought with a live payment is refused, whatever account it is in', $why);

// A record whose node is a main-side box (dev's own getjoinery.com record
// pointed at its Getjoinery node): its server is not at the test account, so
// it would read as gone and the box would be removed from the dashboard.
$host->set('mgh_cloud_account', CloudAccounts::MAIN);
$host->save();
$why = TestSiteDiscard::refusal($p);
check(strpos($why, 'Main side') !== false, 'a site whose box is on the Main side of the dashboard is refused', $why);
$host->set('mgh_cloud_account', CloudAccounts::TEST);
$host->save();

// The same account decides where a test site's mail goes (ProvisionHostedMail).
$was_trap = Globalvars::get_instance()->get_setting('server_manager_test_site_mail_trap', false, true);
Setting::put('server_manager_test_site_mail_trap', '');
Globalvars::get_instance()->forget_setting('server_manager_test_site_mail_trap');
$no_trap = ProvisionHostedMail::test_trap($p);
Setting::put('server_manager_test_site_mail_trap', 'joineryemailtests@dev.getjoinery.com');
Globalvars::get_instance()->forget_setting('server_manager_test_site_mail_trap');
$trap = ProvisionHostedMail::test_trap($p);
$p->set('cvp_hosting_mode', 'customer');
$customer_trap = ProvisionHostedMail::test_trap($p);
$p->set('cvp_hosting_mode', 'operator');
Setting::put('server_manager_test_site_mail_trap', (string)$was_trap);
Globalvars::get_instance()->forget_setting('server_manager_test_site_mail_trap');
check($no_trap === '' && $trap === 'joineryemailtests@dev.getjoinery.com' && $customer_trap === '',
	'a test site\'s mail goes to the plane\'s trap when one is set, and a site in a customer\'s account is never trapped',
	json_encode(array($no_trap, $trap, $customer_trap)));

list($ordered) = TestSiteDiscard::nodes($p);
check(count($ordered) === 2 && (int)$ordered[0]->key === (int)$agent->key && (int)$ordered[1]->key === (int)$site_node->key,
	'the Docker host\'s node goes first, the site\'s node last');

// ---------------------------------------------------------------------------
section('The safety catch');

$dns = new FakeDiscardDns();
$dns->add('A', 'm1.joinerytest.com', '192.0.2.60');
$dns->add('AAAA', 'www.m1.joinerytest.com', '2001:db8::60');
$dns->add('CNAME', 's1187684._domainkey.m1.joinerytest.com', 'dkim.smtp2go.net');
$dns->add('CNAME', 'em1187684.m1.joinerytest.com', 'return.smtp2go.net');
$dns->add('A', 'stray.joinerytest.com', '192.0.2.60');
$dns->add('A', 'joinerytest.com', '192.0.2.99');
$dns->add('A', 'am1.joinerytest.com', '192.0.2.98');
$dns->add('TXT', 'm2.joinerytest.com', 'keep me');

$cloud->company = 'Joinery';
$history = array();
$cancelled = array();
$seams = array('dns' => $dns, 'mail' => tsd_mail('sub-m1', 'Active', $history),
	'cancel' => function ($i) use (&$cancelled) { $cancelled[] = array((int)$i->key, TestMode::active()); });
$refused = '';
try {
	TestSiteDiscard::discard($p, $seams);
} catch (DisplayableUserException $e) {
	$refused = $e->getMessage();
}
check(strpos($refused, 'not the test account') !== false && !$cloud->deleted && count($dns->records) === 8 && !$history && !$cancelled,
	'an account whose company is not the disposable one deletes nothing', $refused);
$cloud->company = TestCloudCleanup::COMPANY;

// ---------------------------------------------------------------------------
section('A step that fails keeps the records');

$dns->fail_on = 'em1187684.m1.joinerytest.com';
$out = TestSiteDiscard::discard($p, $seams);
check(!$out['finished'] && $out['failed'] && strpos(implode(' ', $out['failed']), 'em1187684') !== false,
	'the failed record is named, and the discard is not finished', json_encode($out['failed']));
check($cloud->deleted === array((string)$p->get('cvp_instance_id')), 'the server was deleted', json_encode($cloud->deleted));
check(tsd_paths($history) === array('subaccounts/search', 'subaccount/close'), 'the subaccount was closed', json_encode(tsd_paths($history)));
check($cancelled === array(array((int)$item->key, true)), 'the test subscription was cancelled, on the test side', json_encode($cancelled));
$item->load();
check((bool)$item->get('odi_subscription_cancelled_time') && (string)$item->get('odi_subscription_status') === 'canceled',
	'the line is stamped cancelled, though the provider\'s part did not stamp it');
$p->load();
$site_node->load();
check($site_node->key && !$p->get('cvp_delete_time') && trim((string)$p->get('cvp_smtp2go_subaccount_id')) === '',
	'the node and provision are kept, and the closed subaccount is no longer named');

// ---------------------------------------------------------------------------
section('The next click finishes');

$dns->fail_on = '';
$history = array();
$cancelled = array();
$seams['mail'] = tsd_mail('sub-m1', 'Active', $history);
$out = TestSiteDiscard::discard($p, $seams);
check($out['finished'] && !$out['failed'], 'it finishes', json_encode($out));
check(count($cloud->deleted) === 1 && !$history && !$cancelled, 'nothing is deleted, closed or cancelled twice');
$left = $dns->names();
sort($left);
check($left === array('A am1.joinerytest.com', 'A joinerytest.com', 'TXT m2.joinerytest.com'),
	'every record at or under the site\'s name and every record naming its server went; nothing else', json_encode($left));
$gone = function ($table, $col, $id) use ($db) {
	$q = $db->prepare("SELECT COUNT(*) FROM $table WHERE $col = ?");
	$q->execute(array($id));
	return (int)$q->fetchColumn() === 0;
};
check($gone('mgn_managed_nodes', 'mgn_managed_node_id', $site_node->key) && $gone('mgn_managed_nodes', 'mgn_managed_node_id', $agent->key),
	'the site\'s node and its Docker host\'s node are removed');
check($gone('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key), 'the provision is removed with them');
$host->load();
check((bool)$host->get('mgh_delete_time'), 'and the Docker host record, one that takes provisions, is retired');

// ---------------------------------------------------------------------------
section('A subaccount already closed');

list($p2) = $site('m3');
$history = array();
$out = TestSiteDiscard::discard($p2, array('dns' => new FakeDiscardDns(), 'mail' => tsd_mail('sub-m3', 'Closed', $history),
	'cancel' => function ($i) {}));
check($out['finished'] && tsd_paths($history) === array('subaccounts/search'), 'is not closed again', json_encode(tsd_paths($history)));

section('A subaccount SMTP2GO no longer lists');

list($p4) = $site('m4');
$history = array();
$out = TestSiteDiscard::discard($p4, array('dns' => new FakeDiscardDns(), 'mail' => tsd_mail('sub-someone-else', 'Active', $history),
	'cancel' => function ($i) {}));
check($out['finished'] && tsd_paths($history) === array('subaccounts/search'), 'counts as gone, and the discard finishes', json_encode($out));

// ---------------------------------------------------------------------------
section('A Starter site in a container on a shared host');

// A shared starter host on the Test side, with another customer's site on it.
$sh_agent = $node('starter-host ' . $tag, 'starter-host-' . $tag);
$sh_agent->set('mgn_agent_public_key', base64_encode(str_repeat("\x0c", 32)));
$sh_agent->set('mgn_agent_version', AgentVocabulary::FLOOR);
$sh_agent->set('mgn_agent_primitives', 'check_status,decommission_site');
$sh_agent->save();
$sh = new ManagedHost(NULL);
$sh->set('mgh_slug', 'starter-h-' . $tag);
$sh->set('mgh_name', 'HarnessTest starter host ' . $tag);
$sh->set('mgh_host', '192.0.2.61');
$sh->set('mgh_tier', 'starter');
$sh->set('mgh_max_sites', 12);
$sh->set('mgh_provisioning_enabled', true);
$sh->set('mgh_mgn_managed_node_id', $sh_agent->key);
$sh->set('mgh_cloud_account', CloudAccounts::TEST);
$sh->save();
$sh->load();
$tsd_rows[] = array('mgh_managed_hosts', 'mgh_managed_host_id', (int)$sh->key);
$sh_agent->set('mgn_mgh_managed_host_id', $sh->key);
$sh_agent->save();
$tenant = $node('tenant.joinerytest.com ' . $tag, 'tenant-joinerytest-com-' . $tag, (int)$sh->key);
$tenant->set('mgn_container_name', 'st900001');
$tenant->set('mgn_joinery_version', JobCommandBuilder::DECOMMISSION_PANEL_MIN_CORE_VERSION);
$tenant->save();

/** A test Starter purchase placed on the shared host: [provision, site node]. $stood_up: a site was seen on it. */
$starter = function (string $label, bool $stood_up) use ($node, $uid, $tag, $sh, &$tsd_rows) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $uid);
	$order->set('ord_test_mode', true);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', 999999002);
	$item->set('odi_usr_user_id', $uid);
	$item->set('odi_price', '4.99');
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->set('odi_is_subscription', true);
	$item->set('odi_subscription_status', 'active');
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'order');
	$p->set('cvp_external_order_item_id', $item->key);
	$p->set('cvp_usr_user_id', $uid);
	$p->set('cvp_domain', $label . '.joinerytest.com');
	$p->set('cvp_slug', $label . '-joinerytest-com');
	$p->set('cvp_status', 'done');
	$p->set('cvp_hosting_mode', 'operator');
	$p->set('cvp_landing', 'container');
	$p->set('cvp_mgh_managed_host_id', (int)$sh->key);
	$p->set('cvp_smtp2go_subaccount_id', 'sub-' . $label);
	$p->save();
	$p->load();
	$tsd_rows[] = array('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', (int)$p->key);
	$n = $node($label . '.joinerytest.com ' . $tag, $label . '-joinerytest-com-' . $tag, (int)$sh->key);
	$n->set('mgn_container_name', 'st' . (int)$p->key);
	$n->set('mgn_web_root', '/var/www/html/st' . (int)$p->key . '/public_html');
	if ($stood_up) {
		$n->set('mgn_joinery_version', JobCommandBuilder::DECOMMISSION_PANEL_MIN_CORE_VERSION);
	}
	$n->save();
	$p->set('cvp_mgn_managed_node_id', $n->key);
	$p->save();
	$p->load();
	return array($p, $n);
};
$decom_seen = array();
$decom_jobs = function ($victim) use ($db, $sh_agent, &$tsd_rows, &$decom_seen) {
	$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs WHERE mjb_mgn_managed_node_id = ?
		AND mjb_job_type = 'decommission_node' AND mjb_parameters->>'victim_node_id' = ? ORDER BY mjb_management_job_id");
	$q->execute(array((int)$sh_agent->key, (string)(int)$victim->key));
	$ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
	foreach ($ids as $id) {
		if (!isset($decom_seen[$id])) {
			$decom_seen[$id] = true;
			$tsd_rows[] = array('mjb_management_jobs', 'mjb_management_job_id', $id);
		}
	}
	return $ids;
};

list($sp, $sp_node) = $starter('st1', true);
check((string)$sp->get('cvp_oca_operator_cloud_account_id') === '' && trim((string)$sp->get('cvp_instance_id')) === '',
	'a container site\'s row names no cloud account and no server of its own');
$acct = TestSiteDiscard::test_account($sp);
check($acct !== null && (bool)$acct->get('oca_is_test'), 'it is a test site by its host: the host is on the Test side, and the account is a test one');
check(TestSiteDiscard::offered($sp) && TestSiteDiscard::refusal($sp) === '',
	'it is offered, and the other site on its host does not refuse it', TestSiteDiscard::refusal($sp));
list($s_nodes, $s_others) = TestSiteDiscard::nodes($sp);
check(count($s_nodes) === 1 && (int)$s_nodes[0]->key === (int)$sp_node->key && !$s_others,
	'only the site\'s own node goes with it: never the shared host\'s node');

$was_trap = Globalvars::get_instance()->get_setting('server_manager_test_site_mail_trap', false, true);
Setting::put('server_manager_test_site_mail_trap', 'joineryemailtests@dev.getjoinery.com');
Globalvars::get_instance()->forget_setting('server_manager_test_site_mail_trap');
$s_trap = ProvisionHostedMail::test_trap($sp);
$sh->set('mgh_cloud_account', CloudAccounts::MAIN);
$sh->save();
$main_offered = TestSiteDiscard::offered($sp);
$main_trap = ProvisionHostedMail::test_trap($sp);
$sh->set('mgh_cloud_account', CloudAccounts::TEST);
$sh->save();
Setting::put('server_manager_test_site_mail_trap', (string)$was_trap);
Globalvars::get_instance()->forget_setting('server_manager_test_site_mail_trap');
check($s_trap === 'joineryemailtests@dev.getjoinery.com', 'its mail goes to the plane\'s trap, as any test site\'s does', $s_trap);
check(!$main_offered && $main_trap === '', 'the same site on a host on the Main side is a customer\'s: not offered, and never trapped');

$sdns = new FakeDiscardDns();
$sdns->add('A', 'st1.joinerytest.com', '192.0.2.61');
$sdns->add('TXT', '_dmarc.st1.joinerytest.com', 'v=DMARC1');
$sdns->add('A', 'tenant.joinerytest.com', '192.0.2.61');
$s_history = array();
$s_cancelled = array();
$s_seams = array('dns' => $sdns, 'mail' => tsd_mail('sub-st1', 'Active', $s_history),
	'cancel' => function ($i) use (&$s_cancelled) { $s_cancelled[] = (int)$i->key; });
$servers_before = count($cloud->deleted);

// The first click: its host is asked to remove the container, and nothing else goes.
$out = TestSiteDiscard::discard($sp, $s_seams);
$jobs = $decom_jobs($sp_node);
check(!$out['finished'] && count($out['failed']) === 1 && strpos($out['failed'][0], 'was asked to remove its container st' . (int)$sp->key) !== false,
	'the first click asks its host to remove the container', json_encode($out));
check(count($jobs) === 1, 'through one Permanently Delete Site job on the host\'s agent, naming the site as its victim', json_encode($jobs));
check(count($sdns->names()) === 3 && !$s_history && !$s_cancelled,
	'and nothing else is removed yet: the site approves its own removal, which its name and mail must still reach');
check(count($cloud->deleted) === $servers_before, 'no server is deleted at the cloud account');

// A second click while that job is open asks nothing twice.
$out = TestSiteDiscard::discard($sp, $s_seams);
check(!$out['finished'] && strpos($out['failed'][0], 'is being removed by its host') !== false && count($decom_jobs($sp_node)) === 1,
	'a click while the host is still at it queues no second job', json_encode($out));

// Its host verified the container gone: the next click finishes.
$db->prepare("UPDATE mjb_management_jobs SET mjb_status = 'completed', mjb_result = '{}' WHERE mjb_management_job_id = ?")->execute(array($jobs[0]));
$sp_node->load();
$sp_node->set('mgn_site_removed_time', gmdate('Y-m-d H:i:s'));
$sp_node->save();
$sp->load();
$out = TestSiteDiscard::discard($sp, $s_seams);
check($out['finished'] && !$out['failed'], 'once its host has verified the container gone, the next click finishes', json_encode($out));
check(count($decom_jobs($sp_node)) === 1, 'with no further job');
$left = $sdns->names();
sort($left);
check($left === array('A tenant.joinerytest.com'),
	'the records under its own name went; the other site\'s record naming the shared host\'s address stays', json_encode($left));
check(tsd_paths($s_history) === array('subaccounts/search', 'subaccount/close') && count($s_cancelled) === 1,
	'its mail subaccount is closed and its test subscription cancelled', json_encode(tsd_paths($s_history)));
check(count($cloud->deleted) === $servers_before, 'still no server deleted: the host is not this site\'s');
check($gone('mgn_managed_nodes', 'mgn_managed_node_id', $sp_node->key) && $gone('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $sp->key),
	'the site\'s node and its provision are removed');
$sh->load();
check(!$gone('mgn_managed_nodes', 'mgn_managed_node_id', $sh_agent->key) && !$gone('mgn_managed_nodes', 'mgn_managed_node_id', $tenant->key)
		&& !$sh->get('mgh_delete_time'),
	'the shared host, its own node and the other site on it are all still here');

// An install that never stood a site up has no container to remove: one click.
list($sp2, $sp2_node) = $starter('st2', false);
$sp2->set('cvp_status', 'failed');
$sp2->save();
$s2_history = array();
$out = TestSiteDiscard::discard($sp2, array('dns' => new FakeDiscardDns(), 'mail' => tsd_mail('sub-st2', 'Active', $s2_history),
	'cancel' => function ($i) {}));
check($out['finished'] && !$out['failed'] && !$decom_jobs($sp2_node), 'a Starter site that never stood up goes in one click, with no job for its host', json_encode($out));
$sh->load();
check(!$sh->get('mgh_delete_time') && !$gone('mgn_managed_nodes', 'mgn_managed_node_id', $sh_agent->key),
	'and the shared host is not retired with it');

harness_finish();
