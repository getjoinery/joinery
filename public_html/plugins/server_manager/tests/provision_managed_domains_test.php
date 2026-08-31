<?php
/** @joinery-test
 * name: provision_managed_domains
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The fulfillment half: a paid row becomes a registered name pointing at a
 * running box, one step per tick, without ever buying anything twice.
 *
 * Everything here is about not repeating an irreversible act. A DNS publish
 * repeated is harmless; a registration repeated is a second charge on the
 * operator's registrar account and a second domain nobody asked for. So the
 * two are guarded differently, and this test asserts that difference:
 *
 *  - A step timestamp guards a DNS write, and once stamped it is never redone.
 *  - STATUS guards the purchase, so a crash between the charge and the stamp
 *    cannot buy again — and the ambiguous case ("unavailable, but we may
 *    already own it") is resolved by asking whether we hold it, not by trying.
 *
 * The registrar, DNS driver, reconciler and the operator-alert mail are all
 * injected. No network, no registrar, no box.
 *
 * THE MAIL STEP IS ASYNCHRONOUS AND THE TESTS RUN IT THAT WAY. The node is
 * asked what its mail setup needs by a managed_domain_prepare job on the agent
 * channel, so the answer lands on a later tick than the question. Nothing is
 * mocked at that seam: the phase files a real job row, the test writes the
 * answer onto it the way AgentChannelEndpoint would, and the next tick reads
 * it. That is what makes the once-only consumption and the domain scoping
 * testable at all — both are properties of the job rows, not of a method call.
 *
 * Sections: the wait for a box; registration and its guards; DNS bootstrap;
 * mail DNS as a job (dispatch, park, consume, the DKIM hold, once-only
 * consumption, domain scoping, a node without the primitive); PTR including the
 * shared-host case; activation; terminal failure.
 *
 * Run: php plugins/server_manager/tests/provision_managed_domains_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));
require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));

// ---------------------------------------------------------------------------
// Doubles
// ---------------------------------------------------------------------------

class PmdFakeRegistrar implements DomainRegistrarProvider {
	public $available = true;
	public $owned_expiry = null;          // what getExpiry() reports
	public $register_calls = 0;
	public $throw_transient = false;
	public $throw_terminal = false;
	public $in_account = true;

	public static function getKey(): string { return 'pmdfake'; }
	public static function getLabel(): string { return 'Fake Registrar'; }
	public static function isConfigured(): bool { return true; }

	public function checkAvailability(array $domains): array {
		if ($this->throw_transient) { throw DomainRegistrarException::transient('registry blip'); }
		$out = array();
		foreach ($domains as $d) {
			$out[strtolower($d)] = array('available' => $this->available,
				'price_year' => '10.00', 'premium' => false,
				'message' => $this->available ? '' : 'That name is already taken.');
		}
		return $out;
	}
	public function register(string $domain, array $registrant, int $years): array {
		$this->register_calls++;
		if ($this->throw_transient) { throw DomainRegistrarException::transient('registry blip'); }
		if ($this->throw_terminal) { throw DomainRegistrarException::terminal('registry said no'); }
		return array('expiry' => gmdate('Y-m-d H:i:s', strtotime('+1 year')));
	}
	public function applyWhoisPrivacy(string $domain): void {}
	public function normalizeRegistrantPhone(string $phone): string { return $phone; }
	public function dnsDriverKey(): string { return 'pmdfakedns'; }
	public function dnsCredential(): array { return array(); }
	public function getExpiry(string $domain): ?string { return $this->owned_expiry; }
	public function inAccount(string $domain): bool { return $this->in_account; }
	public function graduationMechanism(): string { return 'account_push'; }
}

/** Records what would have been published, and can be told to refuse. */
class PmdFakeReconciler extends DnsReconciler {
	public $published = array();
	public $fail = false;
	public function apply(DnsProvider $driver, string $zone, DnsRecordPlan $plan,
			array $decisions = array(), string $mode = self::APPLY_CONFIRMED): array {
		$results = array();
		foreach ($plan->getRecords() as $record) {
			$this->published[] = $record->type . ' ' . $record->name . ' -> ' . $record->value;
			$results[] = array('key' => $record->name, 'record' => $record,
				'action' => $this->fail ? 'failed' : 'created',
				'ok' => !$this->fail, 'reason' => $this->fail ? 'provider refused' : '');
		}
		return $results;
	}
}

/**
 * The phase with its registrar, DNS and mail edges replaced.
 *
 * The AGENT edge is deliberately NOT replaced. The mail step's whole shape is
 * "file a job, come back for the answer", and a double at that seam would test
 * the double. So the phase files real rows into mjb_management_jobs and this
 * suite answers them.
 */
class PmdPhase extends ProvisionManagedDomains {
	public $registrar;
	public $reconciler;

	public function __construct($registrar, $reconciler) {
		$this->registrar = $registrar;
		$this->reconciler = $reconciler;
	}
	protected function get_registrar() { return $this->registrar; }
	protected function get_dns_driver() { return new PmdNullDnsDriver(array()); }
	protected function get_reconciler() { return $this->reconciler; }
	// The mail edge, intercepted like the other two. Before this override
	// existed, every parked row in this suite emailed a REAL operator through
	// dev's live Postfix — about sixty across one day's runs.
	protected function send_failure_alert($row, string $reason): void {
		$this->alerts[] = array('domain' => (string)$row->get('rdm_domain'), 'reason' => $reason);
	}
	public $alerts = array();
}

/** A DnsProvider that is never actually called (the reconciler is faked). */
class PmdNullDnsDriver extends DnsDriverBase {
	public static function getKey(): string { return 'pmdfakedns'; }
	public static function getLabel(): string { return 'Fake DNS'; }
	public function zoneFor(string $domain): ?string { return $domain; }
	public function listRecords(string $zone): array { return array(); }
	public function createRecord(string $zone, DnsRecord $record): void {}
	public function updateRecord(string $zone, DnsRecord $live, DnsRecord $desired): void {}
	public function deleteRecord(string $zone, DnsRecord $live): void {}
}

// ---------------------------------------------------------------------------
// Fixtures: a buyer, a box, and a domain row waiting on both.
// ---------------------------------------------------------------------------

$buyer = make_user('PmdBuyer');
$suffix = getmypid();

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'pmd-test-' . $suffix);
$node->set('mgn_slug', 'pmd-test-' . $suffix);
$node->set('mgn_host', '198.51.100.20');
$node->set('mgn_ssh_user', 'root');
$node->set('mgn_ssh_key_path', '/dev/null');
$node->set('mgn_web_root', '/var/www/html/pmdtest/public_html');
$node->set('mgn_site_url', 'https://pmd-test-' . $suffix . '.example.com');
$node->set('mgn_container_name', 'pmdtest');
$node->set('mgn_enabled', true);
// A paired agent that reports the managed-domain vocabulary. has_primitive()
// reads exactly these two columns, so this is what makes the phase route to the
// channel rather than throw.
$node->set('mgn_agent_public_key', 'pmd-agent-key-' . $suffix);
$node->set('mgn_agent_version', '1.14.0');
$node->set('mgn_agent_primitives', 'managed_domain_prepare,managed_domain_notice');
$node->prepare();
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_id', $node->key);

require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

/**
 * Belt and braces: every managed-domain job this node collects, gone.
 *
 * The helpers below register each job they enumerate, but the phase is what
 * creates them and a path this suite does not read would leak a row. Registered
 * here, right after the node, so LIFO teardown clears the jobs before the node
 * they point at.
 */
function pmd_clear_jobs_for($node_id) {
	harness_defer(function () use ($node_id) {
		$db = DbConnector::get_instance()->get_db_link();
		try {
			$q = $db->prepare("DELETE FROM mjb_management_jobs WHERE mjb_mgn_node_id = ? "
				. "AND mjb_job_type IN ('managed_domain_prepare', 'managed_domain_notice')");
			$q->execute(array((int)$node_id));
		} catch (\Throwable $e) {
			echo "  WARNING: could not clear managed-domain jobs for node $node_id: " . $e->getMessage() . "\n";
		}
	});
}

pmd_clear_jobs_for($node->key);

/**
 * The prepare jobs filed for one node and one domain, oldest first — and
 * registered for cleanup as they are found, since the phase is what creates
 * them.
 */
function pmd_prepare_jobs($node, string $domain): array {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare(
		"SELECT mjb_id, mjb_status, mjb_commands, mjb_parameters, mjb_completed_time
		 FROM mjb_management_jobs
		 WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'managed_domain_prepare'
		   AND mjb_delete_time IS NULL AND mjb_parameters->>'domain' = ?
		 ORDER BY mjb_id ASC");
	$q->execute(array((int)$node->key, $domain));
	$jobs = $q->fetchAll(PDO::FETCH_ASSOC) ?: array();
	foreach ($jobs as $job) {
		harness_register_row('mjb_management_jobs', 'mjb_id', $job['mjb_id']);
	}
	return $jobs;
}

/**
 * Answer a prepare job the way the node would.
 *
 * The envelope is the one AgentChannelEndpoint builds — {"api_version","data"}
 * around the script's text — because that is what the phase has to read, and a
 * test that wrote the bare JSON line would pass while the real transport
 * failed. The completion is dated an hour back so the retry gap never masks
 * what a following tick does.
 */
function pmd_answer(array $job, array $payload, string $status = 'completed'): void {
	$record = new ManagementJob((int)$job['mjb_id'], TRUE);
	$record->set('mjb_output', json_encode(array('api_version' => '1.0', 'data' => array(
		'output'       => "site bootstrap noise\n" . json_encode($payload) . "\n",
		'output_bytes' => 128,
	))) . "\n");
	$record->set('mjb_status', $status);
	$record->set('mjb_completed_time', gmdate('Y-m-d H:i:s', strtotime('-1 hour')));
	$record->save();
}

require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

$domain_product = new Product(NULL);
$domain_product->set('pro_name', 'Domain registration (1 year) [pmd]');
$domain_product->set('pro_link', 'pmd-domain-year-' . $suffix);
$domain_product->set('pro_is_active', true);
$domain_product->save();
$domain_product->load();
harness_register_row('pro_products', 'pro_product_id', $domain_product->key);
harness_set_setting_mem('store_domain_registration_product_id', (string)$domain_product->key);

/**
 * A paid order: $hosting_lines hosting lines (each the parent of one domain
 * row — rdm_external_order_item_id is unique, so one order item can only ever
 * back one domain) plus one paid domain-year line per entry in $domain_prices.
 *
 * @return int[] the hosting line ids, in order.
 */
function pmd_order($buyer, $domain_product, array $domain_prices, int $hosting_lines = 1): array {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);

	$ids = array();
	for ($i = 0; $i < $hosting_lines; $i++) {
		$hosting = new OrderItem(NULL);
		$hosting->set('odi_ord_order_id', $order->key);
		$hosting->set('odi_pro_product_id', 999999001);
		$hosting->set('odi_usr_user_id', $buyer->key);
		$hosting->set('odi_price', '99.00');
		$hosting->set('odi_status', OrderItem::STATUS_PAID);
		$hosting->save();
		$hosting->load();
		harness_register_row('odi_order_items', 'odi_order_item_id', $hosting->key);
		$ids[] = (int)$hosting->key;
	}

	foreach ($domain_prices as $price) {
		$line = new OrderItem(NULL);
		$line->set('odi_ord_order_id', $order->key);
		$line->set('odi_pro_product_id', $domain_product->key);
		$line->set('odi_usr_user_id', $buyer->key);
		$line->set('odi_price', $price);
		$line->set('odi_status', OrderItem::STATUS_PAID);
		$line->save();
		$line->load();
		harness_register_row('odi_order_items', 'odi_order_item_id', $line->key);
	}
	return $ids;
}

function pmd_row($buyer, $node, $domain, $with_node = true) {
	$row = new RegisteredDomain(NULL);
	$row->set('rdm_registrar', 'pmdfake');
	$row->set('rdm_domain', $domain);
	$row->set('rdm_usr_user_id', $buyer->key);
	$row->set('rdm_buyer_email', $buyer->get('usr_email'));
	$row->set('rdm_status', RegisteredDomain::STATUS_PENDING);
	if ($with_node) { $row->set('rdm_mgn_node_id', $node->key); }
	$row->seal_registrant(array(
		'first_name' => 'Jane', 'last_name' => 'Smith', 'address1' => '14 Elm Street',
		'city' => 'Springfield', 'state_province' => 'IL', 'postal_code' => '62704',
		'country' => 'US', 'phone' => '+1.5551234567', 'email' => 'jane@example.com'));
	$row->prepare();
	$row->save();
	$row->load();
	harness_register_row('rdm_registered_domains', 'rdm_id', $row->key);
	return $row;
}

/** Advance one row one tick, through the private state machine. */
function pmd_tick(PmdPhase $phase, $row) {
	$method = new ReflectionMethod('ProvisionManagedDomains', 'advance');
	$method->setAccessible(true);
	$result = $method->invoke($phase, $row);
	$row->load();
	return $result;
}

$good_payload = array('ok' => true, 'dkim_ready' => true, 'records' => array(
	array('type' => 'MX', 'name' => 'x', 'value' => 'mail.x', 'priority' => 10),
	array('type' => 'TXT', 'name' => 'x', 'value' => 'v=spf1 -all', 'priority' => null),
	array('type' => 'TXT', 'name' => 'mail._domainkey.x', 'value' => 'v=DKIM1; p=AAA', 'priority' => null),
));

// ---------------------------------------------------------------------------
section('Nothing is bought before there is a box to point it at');

$registrar = new PmdFakeRegistrar();
$phase = new PmdPhase($registrar, new PmdFakeReconciler());
$orphan = pmd_row($buyer, $node, 'pmd-orphan-' . $suffix . '.com', false);
check(pmd_tick($phase, $orphan) === 0, 'a row with no node takes no step');
check($registrar->register_calls === 0, 'and buys nothing while the compute leg is still working');
check($orphan->get('rdm_status') === RegisteredDomain::STATUS_PENDING, 'it stays pending');

// ---------------------------------------------------------------------------
section('Registration happens once, and only from pending');

$registrar = new PmdFakeRegistrar();
$reconciler = new PmdFakeReconciler();
$phase = new PmdPhase($registrar, $reconciler);
$row = pmd_row($buyer, $node, 'pmd-happy-' . $suffix . '.com');

check(pmd_tick($phase, $row) === 1, 'the first tick registers');
check($registrar->register_calls === 1, 'exactly one purchase');
check($row->get('rdm_status') === RegisteredDomain::STATUS_REGISTERED, 'status advances to registered');
check(trim((string)$row->get('rdm_registered_time')) !== '', 'the registration time is stamped');
check(trim((string)$row->get('rdm_expiry_time')) !== '', 'and the expiry is recorded');

pmd_tick($phase, $row);
check($registrar->register_calls === 1, 'the next tick does not buy again');

section('A transient registrar failure leaves the row exactly where it was');

$registrar = new PmdFakeRegistrar();
$registrar->throw_transient = true;
$phase = new PmdPhase($registrar, new PmdFakeReconciler());
$blip = pmd_row($buyer, $node, 'pmd-blip-' . $suffix . '.com');
check(pmd_tick($phase, $blip) === 0, 'the tick reports no progress');
check($blip->get('rdm_status') === RegisteredDomain::STATUS_PENDING, 'the row stays pending, to retry');
check(stripos((string)$blip->get('rdm_error'), 'transient') !== false,
	'and says so, so an operator reading the queue knows it is not stuck',
	'got: ' . $blip->get('rdm_error'));

section('A name that really is taken is parked for a person, not retried');

$registrar = new PmdFakeRegistrar();
$registrar->available = false;
$registrar->owned_expiry = null;     // and we do not already hold it
$phase = new PmdPhase($registrar, new PmdFakeReconciler());
$taken = pmd_row($buyer, $node, 'pmd-taken-' . $suffix . '.com');
check(pmd_tick($phase, $taken) === 1, 'the tick resolves the row');
check($taken->get('rdm_status') === RegisteredDomain::STATUS_FAILED, 'it fails terminally');
check($registrar->register_calls === 0, 'without ever attempting the purchase');
check(trim((string)$taken->get('rdm_error')) !== '', 'the reason is recorded for the queue page');
check(count($phase->alerts) === 1
		&& $phase->alerts[0]['domain'] === 'pmd-taken-' . $suffix . '.com'
		&& stripos($phase->alerts[0]['reason'], 'no longer available') !== false,
	'exactly one operator alert, naming the domain and the reason',
	'alerts: ' . var_export($phase->alerts, true));

section('"Unavailable" from a registrar that already holds it is not a second purchase');

// This is the crash-between-charge-and-stamp case: the create succeeded, the
// row never learned. Buying again would charge twice.
$registrar = new PmdFakeRegistrar();
$registrar->available = false;
$registrar->owned_expiry = gmdate('Y-m-d H:i:s', strtotime('+1 year'));
$phase = new PmdPhase($registrar, new PmdFakeReconciler());
$recovered = pmd_row($buyer, $node, 'pmd-recovered-' . $suffix . '.com');
check(pmd_tick($phase, $recovered) === 1, 'the tick resolves it');
check($recovered->get('rdm_status') === RegisteredDomain::STATUS_REGISTERED,
	'the row is recognised as already registered');
check($registrar->register_calls === 0, 'and nothing is bought a second time');
check(trim((string)$recovered->get('rdm_expiry_time')) !== '',
	'the expiry is adopted from the registrar');

// ---------------------------------------------------------------------------
section('DNS is published additively, and each step is stamped once');

$registrar = new PmdFakeRegistrar();
$reconciler = new PmdFakeReconciler();
$phase = new PmdPhase($registrar, $reconciler);
$row = pmd_row($buyer, $node, 'pmd-dns-' . $suffix . '.com');
pmd_tick($phase, $row);                       // register

check(pmd_tick($phase, $row) === 1, 'the next tick publishes the web records');
check(trim((string)$row->get('rdm_dns_bootstrap_time')) !== '', 'and stamps the step');
$web = implode(' | ', $reconciler->published);
check(strpos($web, 'A pmd-dns-' . $suffix . '.com -> 198.51.100.20') !== false,
	'the apex points at the box', $web);
check(strpos($web, 'A www.pmd-dns-' . $suffix . '.com -> 198.51.100.20') !== false,
	'and so does www', $web);

$before = count($reconciler->published);
check(pmd_tick($phase, $row) === 0, 'the mail tick takes no step yet — it asks the node');
$jobs = pmd_prepare_jobs($node, $row->get('rdm_domain'));
check(count($jobs) === 1, 'exactly one prepare job is filed', 'jobs: ' . count($jobs));
$envelope = json_decode((string)$jobs[0]['mjb_commands'], true);
check(($envelope['primitive'] ?? '') === 'managed_domain_prepare',
	'it is a primitive job, addressed by name', $jobs[0]['mjb_commands']);
check(($envelope['params'] ?? array()) === array('domain' => $row->get('rdm_domain')),
	'and the domain is its whole vocabulary — no site name, no container, no credential',
	json_encode($envelope['params'] ?? null));

check(pmd_tick($phase, $row) === 0, 'while the job is pending the row parks');
check(count(pmd_prepare_jobs($node, $row->get('rdm_domain'))) === 1,
	'and no second job is filed on top of it');

pmd_answer($jobs[0], $good_payload);
check(pmd_tick($phase, $row) === 1, 'the tick after the answer publishes and completes the step');
check(trim((string)$row->get('rdm_dns_mail_time')) !== '', 'the mail step is stamped');
$mail = implode(' | ', array_slice($reconciler->published, $before));
check(strpos($mail, 'MX') !== false && strpos($mail, 'v=spf1') !== false
	&& strpos($mail, 'v=DKIM1') !== false,
	'the record set the BOX described is what gets published', $mail);

pmd_tick($phase, $row);
check(count(pmd_prepare_jobs($node, $row->get('rdm_domain'))) === 1,
	'a stamped step is never worked again — the node is not re-asked');

section('Records published without DKIM leave the step open, and come back for the key');

$reconciler = new PmdFakeReconciler();
$phase = new PmdPhase(new PmdFakeRegistrar(), $reconciler);
$nodkim_payload = array('ok' => true, 'dkim_ready' => false, 'records' => array(
	array('type' => 'MX', 'name' => 'y', 'value' => 'mail.y', 'priority' => 10),
	array('type' => 'TXT', 'name' => 'y', 'value' => 'v=spf1 -all', 'priority' => null),
));
$nodkim = pmd_row($buyer, $node, 'pmd-nodkim-' . $suffix . '.com');
pmd_tick($phase, $nodkim);                    // register
pmd_tick($phase, $nodkim);                    // web
pmd_tick($phase, $nodkim);                    // asks the node
$nodkim_jobs = pmd_prepare_jobs($node, $nodkim->get('rdm_domain'));
check(count($nodkim_jobs) === 1, 'the node is asked');
pmd_answer($nodkim_jobs[0], $nodkim_payload);

$published_before = count($reconciler->published);
check(pmd_tick($phase, $nodkim) === 0, 'the mail step reports no progress');
check(count($reconciler->published) > $published_before,
	'but MX and friends are published anyway — mail arrives');
check(trim((string)$nodkim->get('rdm_dns_mail_time')) === '',
	'and the step stays open so the signing key still gets published');

section('A completed prepare job is read exactly once');

// Without this the two paths that deliberately do not stamp — no DKIM, and a
// node that refused — would re-read the same answer forever: the same records
// re-published every tick and no new job ever filed, so the signing key the
// DKIM path exists to collect would never be asked for again.
$published_after_first = count($reconciler->published);
check(pmd_tick($phase, $nodkim) === 0, 'the next tick still reports no progress');
check(count($reconciler->published) === $published_after_first,
	'and does NOT re-publish the first answer\'s records',
	'published ' . (count($reconciler->published) - $published_after_first) . ' more');
$nodkim_jobs = pmd_prepare_jobs($node, $nodkim->get('rdm_domain'));
check(count($nodkim_jobs) === 2, 'it asks the node again instead',
	'jobs: ' . count($nodkim_jobs));

pmd_answer($nodkim_jobs[1], $good_payload);
check(pmd_tick($phase, $nodkim) === 1, 'and once the key exists, the step completes');
$nodkim->load();
check(trim((string)$nodkim->get('rdm_dns_mail_time')) !== '', 'the mail step is stamped at last');

section('A node that refuses parks the row without failing it');

$phase = new PmdPhase(new PmdFakeRegistrar(), new PmdFakeReconciler());
$refuser = pmd_row($buyer, $node, 'pmd-refused-mail-' . $suffix . '.com');
pmd_tick($phase, $refuser);                   // register
pmd_tick($phase, $refuser);                   // web
pmd_tick($phase, $refuser);                   // asks
$refuser_jobs = pmd_prepare_jobs($node, $refuser->get('rdm_domain'));
pmd_answer($refuser_jobs[0], array('ok' => false, 'error' => 'the mailbox plugin is not active here'));

check(pmd_tick($phase, $refuser) === 0, 'the refusal is not progress');
check($refuser->get('rdm_status') === RegisteredDomain::STATUS_REGISTERED,
	'the row is NOT parked at failed — a paid-for domain with no mail must have a way back');
check(stripos((string)$refuser->get('rdm_error'), 'mailbox plugin is not active') !== false,
	'the node\'s own reason reaches the Domains page', 'got: ' . $refuser->get('rdm_error'));
// Reading the refusal consumes it; asking again is the NEXT tick's work, so
// that one tick never both reads an answer and files a fresh question.
pmd_tick($phase, $refuser);
check(count(pmd_prepare_jobs($node, $refuser->get('rdm_domain'))) === 2,
	'and the tick after that asks again');

section('Two domains on one shared host advance independently');

// One node, many managed domains: the lookup is scoped by domain as well as by
// node, or each domain would read the answer meant for whichever was asked last.
$phase = new PmdPhase(new PmdFakeRegistrar(), new PmdFakeReconciler());
$share_a = pmd_row($buyer, $node, 'pmd-share-a-' . $suffix . '.com');
$share_b = pmd_row($buyer, $node, 'pmd-share-b-' . $suffix . '.com');
foreach (array($share_a, $share_b) as $shared_row) {
	pmd_tick($phase, $shared_row);            // register
	pmd_tick($phase, $shared_row);            // web
	pmd_tick($phase, $shared_row);            // asks
}
$jobs_a = pmd_prepare_jobs($node, $share_a->get('rdm_domain'));
$jobs_b = pmd_prepare_jobs($node, $share_b->get('rdm_domain'));
check(count($jobs_a) === 1 && count($jobs_b) === 1,
	'each domain files its own job', 'a: ' . count($jobs_a) . ', b: ' . count($jobs_b));

// Only A is answered. B must not read A's answer.
pmd_answer($jobs_a[0], $good_payload);
check(pmd_tick($phase, $share_b) === 0, 'the unanswered domain still parks');
check(trim((string)$share_b->get('rdm_dns_mail_time')) === '',
	'it does not stamp itself on the other domain\'s answer');
check(pmd_tick($phase, $share_a) === 1, 'while the answered one completes');
check(trim((string)$share_a->get('rdm_dns_mail_time')) !== '', 'and stamps');

section('A node whose agent lacks the primitive is told so, and retried');

$bare_node = new ManagedNode(NULL);
$bare_node->set('mgn_name', 'pmd-bare-' . $suffix);
$bare_node->set('mgn_slug', 'pmd-bare-' . $suffix);
$bare_node->set('mgn_host', '198.51.100.21');
$bare_node->set('mgn_web_root', '/var/www/html/pmdbare/public_html');
$bare_node->set('mgn_enabled', true);
// Paired, but its agent predates the vocabulary.
$bare_node->set('mgn_agent_public_key', 'pmd-bare-key-' . $suffix);
$bare_node->set('mgn_agent_version', '1.13.1');
$bare_node->set('mgn_agent_primitives', 'check_status,backup_run');
$bare_node->prepare();
$bare_node->save();
$bare_node->load();
harness_register_row('mgn_managed_nodes', 'mgn_id', $bare_node->key);
pmd_clear_jobs_for($bare_node->key);

$phase = new PmdPhase(new PmdFakeRegistrar(), new PmdFakeReconciler());
$stranded = pmd_row($buyer, $bare_node, 'pmd-stranded-' . $suffix . '.com');
pmd_tick($phase, $stranded);                  // register
pmd_tick($phase, $stranded);                  // web
check(pmd_tick($phase, $stranded) === 0, 'the mail tick takes no step');
check($stranded->get('rdm_status') === RegisteredDomain::STATUS_REGISTERED,
	'the row is retried, not failed');
check(stripos((string)$stranded->get('rdm_error'), 'managed_domain_prepare') !== false,
	'and the Domains page names the missing primitive rather than showing nothing',
	'got: ' . $stranded->get('rdm_error'));
check(count(pmd_prepare_jobs($bare_node, $stranded->get('rdm_domain'))) === 0,
	'no job is filed at a node that would only refuse it');

section('A refused publish is transient, not a failure');

$reconciler = new PmdFakeReconciler();
$reconciler->fail = true;
$phase = new PmdPhase(new PmdFakeRegistrar(), $reconciler);
$refused = pmd_row($buyer, $node, 'pmd-refused-' . $suffix . '.com');
pmd_tick($phase, $refused);                   // register
check(pmd_tick($phase, $refused) === 0, 'the publish tick reports no progress');
check(trim((string)$refused->get('rdm_dns_bootstrap_time')) === '', 'nothing is stamped');
check($refused->get('rdm_status') === RegisteredDomain::STATUS_REGISTERED,
	'and the row is not parked — it retries next tick');
check(stripos((string)$refused->get('rdm_error'), 'provider refused') !== false,
	'the provider\'s own reason is kept', 'got: ' . $refused->get('rdm_error'));

// ---------------------------------------------------------------------------
section('A box on a shared address finishes the PTR step by having none');

// The node above has no cloud provision, which is exactly the shared-host
// shape: one address serving many domains, where a per-domain PTR is not a
// thing that can exist. It is a completed step, not a stuck one.
$registrar = new PmdFakeRegistrar();
$reconciler = new PmdFakeReconciler();
$phase = new PmdPhase($registrar, $reconciler);
$shared = pmd_row($buyer, $node, 'pmd-shared-' . $suffix . '.com');
pmd_tick($phase, $shared);                    // register
pmd_tick($phase, $shared);                    // web
pmd_tick($phase, $shared);                    // asks the node
pmd_answer(pmd_prepare_jobs($node, $shared->get('rdm_domain'))[0], $good_payload);
pmd_tick($phase, $shared);                    // mail
check(pmd_tick($phase, $shared) === 1, 'the PTR tick resolves');
check(trim((string)$shared->get('rdm_ptr_time')) !== '',
	'the step is stamped rather than retried forever');

section('Every step done means active, and nothing is pushed from here');

check($shared->steps_complete(), 'all three steps are stamped');
check(pmd_tick($phase, $shared) === 1, 'the final tick activates');
check($shared->get('rdm_status') === RegisteredDomain::STATUS_ACTIVE, 'the row is active');

// The notice settings have ONE author, and it is the watcher. Activation used
// to be a second one, firing a push it never checked the result of. The
// watcher's first tick over this row computes exactly the same thing — the
// domain and its expiry, with an empty custody state — and knows whether it
// landed.
$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare("SELECT count(*) FROM mjb_management_jobs
	WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'managed_domain_notice' AND mjb_delete_time IS NULL
	  AND mjb_parameters->>'domain' = ?");
$q->execute(array((int)$node->key, $shared->get('rdm_domain')));
check((int)$q->fetchColumn() === 0,
	'activation files no notice job — ManagedDomainWatch converges on that state');

// And the phase never looks at an active row again: its work queue is the two
// unfinished statuses, which is what stops a finished domain being re-worked
// on every cron tick forever.
$queue = new MultiRegisteredDomain(array(
	'statuses' => array(RegisteredDomain::STATUS_PENDING, RegisteredDomain::STATUS_REGISTERED),
	'deleted'  => false));
$queue->load();
$queued_ids = array();
foreach ($queue as $queued) { $queued_ids[] = (int)$queued->key; }
check(!in_array((int)$shared->key, $queued_ids, true),
	'an active row has left the phase\'s work queue');

// ---------------------------------------------------------------------------
section('A row with no readable registrant never reaches the registrar');

$registrar = new PmdFakeRegistrar();
$phase = new PmdPhase($registrar, new PmdFakeReconciler());
$blank = new RegisteredDomain(NULL);
$blank->set('rdm_registrar', 'pmdfake');
$blank->set('rdm_domain', 'pmd-blank-' . $suffix . '.com');
$blank->set('rdm_usr_user_id', $buyer->key);
$blank->set('rdm_mgn_node_id', $node->key);
$blank->set('rdm_status', RegisteredDomain::STATUS_PENDING);
$blank->prepare();
$blank->save();
$blank->load();
harness_register_row('rdm_registered_domains', 'rdm_id', $blank->key);

check(pmd_tick($phase, $blank) === 1, 'the tick resolves it');
check($blank->get('rdm_status') === RegisteredDomain::STATUS_FAILED,
	'a domain with no owner of record is never registered');
check($registrar->register_calls === 0, 'the registrar is not called at all');
check(count($phase->alerts) === 1 && stripos($phase->alerts[0]['reason'], 'registrant') !== false,
	'the operator alert names the missing registrant block',
	'alerts: ' . var_export($phase->alerts, true));

// ---------------------------------------------------------------------------
section('A domain the order did not pay for is never registered');

// The cart offers Edit and Remove on every line, so a buyer can delete the
// domain-year line and submit the hosting line unchanged. The intake reads the
// hosting line's answers, so without a paid-for gate the domain would be bought
// on the operator's card, for free, and nobody would ever know.
$registrar = new PmdFakeRegistrar();
$phase = new PmdPhase($registrar, new PmdFakeReconciler());
$removed = pmd_row($buyer, $node, 'pmd-unpaid-' . $suffix . '.com');
$removed->set('rdm_external_order_item_id', pmd_order($buyer, $domain_product, array())[0]);
$removed->set('rdm_price_paid', '10.00');
$removed->save();

check(pmd_tick($phase, $removed) === 1, 'the tick resolves the row');
check($registrar->register_calls === 0, 'nothing is bought');
check($removed->get('rdm_status') === RegisteredDomain::STATUS_FAILED,
	'it parks for a person instead of proceeding');
check(stripos((string)$removed->get('rdm_error'), 'paid') !== false,
	'and says the order did not pay for it', 'got: ' . $removed->get('rdm_error'));

section('A domain-year line worth less than the quote does not count');

$registrar = new PmdFakeRegistrar();
$phase = new PmdPhase($registrar, new PmdFakeReconciler());
$underpaid = pmd_row($buyer, $node, 'pmd-underpaid-' . $suffix . '.com');
$underpaid->set('rdm_external_order_item_id', pmd_order($buyer, $domain_product, array('0.01'))[0]);
$underpaid->set('rdm_price_paid', '10.00');
$underpaid->save();

check(pmd_tick($phase, $underpaid) === 1, 'the tick resolves the row');
check($registrar->register_calls === 0, 'a repriced line buys nothing');
check($underpaid->get('rdm_status') === RegisteredDomain::STATUS_FAILED, 'it parks');

section('A domain the order DID pay for goes through');

$registrar = new PmdFakeRegistrar();
$phase = new PmdPhase($registrar, new PmdFakeReconciler());
$paid = pmd_row($buyer, $node, 'pmd-paid-' . $suffix . '.com');
$paid->set('rdm_external_order_item_id', pmd_order($buyer, $domain_product, array('10.00'))[0]);
$paid->set('rdm_price_paid', '10.00');
$paid->save();

check(pmd_tick($phase, $paid) === 1, 'the tick registers');
check($registrar->register_calls === 1, 'exactly one purchase');
check($paid->get('rdm_status') === RegisteredDomain::STATUS_REGISTERED, 'the row advances');

section('One paid line backs one domain, not two');

// Two hosting items in one cart, each asking for a domain, but the buyer
// removed one of the two domain-year lines before paying. One registration is
// owed; the second must not quietly happen on the operator's card.
$hosting_ids = pmd_order($buyer, $domain_product, array('10.00'), 2);
$registrar = new PmdFakeRegistrar();
$phase = new PmdPhase($registrar, new PmdFakeReconciler());

$first = pmd_row($buyer, $node, 'pmd-first-' . $suffix . '.com');
$first->set('rdm_external_order_item_id', $hosting_ids[0]);
$first->set('rdm_price_paid', '10.00');
$first->save();
pmd_tick($phase, $first);
check($first->get('rdm_status') === RegisteredDomain::STATUS_REGISTERED,
	'the domain the order paid for registers');

$second = pmd_row($buyer, $node, 'pmd-second-' . $suffix . '.com');
$second->set('rdm_external_order_item_id', $hosting_ids[1]);
$second->set('rdm_price_paid', '10.00');
$second->save();
check(pmd_tick($phase, $second) === 1, 'the second resolves');
check($registrar->register_calls === 1, 'without a second purchase');
check($second->get('rdm_status') === RegisteredDomain::STATUS_FAILED,
	'because the order only paid for one');

section('Two domains, two paid lines, both register');

$hosting_ids = pmd_order($buyer, $domain_product, array('10.00', '10.00'), 2);
$registrar = new PmdFakeRegistrar();
$phase = new PmdPhase($registrar, new PmdFakeReconciler());
foreach (array('pmd-both-a-' . $suffix . '.com', 'pmd-both-b-' . $suffix . '.com') as $i => $name) {
	$both = pmd_row($buyer, $node, $name);
	$both->set('rdm_external_order_item_id', $hosting_ids[$i]);
	$both->set('rdm_price_paid', '10.00');
	$both->save();
	pmd_tick($phase, $both);
	check($both->get('rdm_status') === RegisteredDomain::STATUS_REGISTERED,
		$name . ' registers — the order paid for both');
}
check($registrar->register_calls === 2, 'two purchases, no more');

// ---------------------------------------------------------------------------
section('The alert recipient chain resolves');

$recipient = ProvisionManagedDomains::resolve_alert_recipient();
check($recipient !== '', 'a failure always has somewhere to be reported',
	'settings alert email, then webmaster_email, then the first superadmin');

harness_finish();
