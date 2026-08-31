<?php
/** @joinery-test
 * name: managed_domain_watch
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The custody half: counting a domain down to its expiry and noticing when it
 * has moved into the buyer's own account.
 *
 * Two properties carry the whole feature, and both are about restraint:
 *
 *  - **Silence for six months.** The buyer paid for a year and should get a
 *    year of not being nagged. Before the threshold the box is given the
 *    domain and its expiry but an EMPTY custody state, which renders nothing.
 *    A state pushed early would put a renewal warning on a site that was
 *    bought last week.
 *  - **inAccount() === false is success.** It is the only signal that the push
 *    landed, because the push itself has no API. Read backwards, a graduated
 *    buyer would keep being told to finish a step they already finished, and
 *    the operator queue would never empty.
 *
 * The notice is asserted as JOB ROWS. The watcher does not push at the box; it
 * computes what the box should be holding, compares that against the last
 * notice job that COMPLETED, and files one only when they differ. So the tests
 * file no doubles at that seam — they read the rows the watcher created, answer
 * them the way the node would, and check that a second tick files nothing.
 *
 * Two properties are only visible in that shape, and both are here:
 *
 *  - **A dispatch is not a prompt.** rdm_prompt_pushed_time is stamped from a
 *    completed job. A job that failed leaves the row unstamped and the next tick
 *    re-files, because a buyer who never saw the notice has not been told.
 *  - **A failed push self-heals.** The box not matching IS the trigger, so
 *    nothing has to remember to re-push.
 *
 * Sections: the six-month threshold; the four values and the converge check;
 * the prompt stamp; custody detection; a finished domain still gets its final
 * state; the unclaimed-line sweep.
 *
 * Run: php plugins/server_manager/tests/managed_domain_watch_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/data/registered_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ManagedDomainWatch.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));

$suffix = getmypid();
$buyer = make_user('MdwBuyer');

$node = new ManagedNode(NULL);
$node->set('mgn_name', 'mdw-test-' . $suffix);
$node->set('mgn_slug', 'mdw-test-' . $suffix);
$node->set('mgn_host', '198.51.100.30');
$node->set('mgn_ssh_user', 'root');
$node->set('mgn_ssh_key_path', '/dev/null');
$node->set('mgn_web_root', '/var/www/html/mdwtest/public_html');
$node->set('mgn_container_name', 'mdwtest');
$node->set('mgn_enabled', true);
// A paired agent reporting the managed-domain vocabulary; has_primitive() reads
// exactly these columns.
$node->set('mgn_agent_public_key', 'mdw-agent-key-' . $suffix);
$node->set('mgn_agent_version', '1.14.0');
$node->set('mgn_agent_primitives', 'managed_domain_prepare,managed_domain_notice');
$node->prepare();
$node->save();
$node->load();
harness_register_row('mgn_managed_nodes', 'mgn_id', $node->key);

/**
 * Belt and braces: every managed-domain job this node collects, gone.
 *
 * The helpers below register each job they enumerate, but the phase is what
 * creates them and a path this suite does not read would leak a row. Registered
 * here, right after the node, so LIFO teardown clears the jobs before the node
 * they point at.
 */
function mdw_clear_jobs_for($node_id) {
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

mdw_clear_jobs_for($node->key);

function mdw_row($buyer, $node, $domain, $expiry_modifier, $state) {
	$row = new RegisteredDomain(NULL);
	$row->set('rdm_registrar', 'mdwfake');
	$row->set('rdm_domain', $domain);
	$row->set('rdm_usr_user_id', $buyer->key);
	$row->set('rdm_buyer_email', $buyer->get('usr_email'));
	$row->set('rdm_mgn_node_id', $node->key);
	$row->set('rdm_status', RegisteredDomain::STATUS_ACTIVE);
	$row->set('rdm_graduation_state', $state);
	$row->set('rdm_expiry_time', gmdate('Y-m-d H:i:s', strtotime($expiry_modifier)));
	$row->set('rdm_expiry_checked_time', gmdate('Y-m-d H:i:s'));
	$row->set('rdm_dns_bootstrap_time', gmdate('Y-m-d H:i:s'));
	$row->set('rdm_dns_mail_time', gmdate('Y-m-d H:i:s'));
	$row->set('rdm_ptr_time', gmdate('Y-m-d H:i:s'));
	$row->prepare();
	$row->save();
	$row->load();
	harness_register_row('rdm_registered_domains', 'rdm_id', $row->key);
	return $row;
}

// A registrar whose answers are scripted, wired into a watch subclass that
// records the commands it would have sent instead of connecting anywhere.
class MdwFakeRegistrar implements DomainRegistrarProvider {
	public $in_account = true;
	public $expiry = null;
	public $expiry_calls = 0;
	public static function getKey(): string { return 'mdwfake'; }
	public static function getLabel(): string { return 'Watch Fake'; }
	public static function isConfigured(): bool { return true; }
	public function checkAvailability(array $domains): array { return array(); }
	public function register(string $domain, array $registrant, int $years): array { return array('expiry' => ''); }
	public function applyWhoisPrivacy(string $domain): void {}
	public function normalizeRegistrantPhone(string $phone): string { return $phone; }
	public function dnsDriverKey(): string { return 'namecheap'; }
	public function dnsCredential(): array { return array(); }
	public function getExpiry(string $domain): ?string { $this->expiry_calls++; return $this->expiry; }
	public function inAccount(string $domain): bool { return $this->in_account; }
	public function graduationMechanism(): string { return 'account_push'; }
}

class MdwWatch extends ManagedDomainWatch {
	public $registrar;
	public function __construct($registrar) { $this->registrar = $registrar; }
	protected function get_registrar() { return $this->registrar; }
	/** Run the private orphan sweep and report how many alerts it decided on. */
	public function sweep(): int {
		$m = new ReflectionMethod('ManagedDomainWatch', 'sweep_unclaimed_domain_lines');
		$m->setAccessible(true);
		return (int)$m->invoke($this);
	}
	/** Record the alert instead of mailing it. */
	protected function alert_unclaimed_order(int $order_id, int $unclaimed): bool {
		$this->alerted[] = array('order' => $order_id, 'unclaimed' => $unclaimed);
		return !$this->alerts_fail;
	}
	public $alerted = array();
	/** Simulate no recipient configured, or a send that threw. */
	public $alerts_fail = false;
	/** Run the private per-row converge check. */
	public function converge($row): int {
		$m = new ReflectionMethod('ManagedDomainWatch', 'converge_notice');
		$m->setAccessible(true);
		return (int)$m->invoke($this, $row);
	}
}

require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

/**
 * The notice jobs filed for one node and one domain, oldest first — registered
 * for cleanup as they are found, since the watcher is what creates them.
 */
function mdw_notice_jobs($node, string $domain): array {
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare(
		"SELECT mjb_id, mjb_status, mjb_commands, mjb_parameters, mjb_completed_time
		 FROM mjb_management_jobs
		 WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'managed_domain_notice'
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
 * Land or fail a notice job the way the node would.
 *
 * Completion is dated an hour back so the retry gap never masks what a
 * following tick decides to do.
 */
function mdw_finish(array $job, string $status = 'completed'): void {
	$record = new ManagementJob((int)$job['mjb_id'], TRUE);
	$record->set('mjb_status', $status);
	$record->set('mjb_completed_time', gmdate('Y-m-d H:i:s', strtotime('-1 hour')));
	$record->set('mjb_output', json_encode(array('api_version' => '1.0', 'data' => array(
		'output'       => "MANAGED_DOMAIN_NOTICE=ok\n",
		'output_bytes' => 24,
	))) . "\n");
	$record->save();
}

/** The params a notice job carries. */
function mdw_params(array $job): array {
	$params = json_decode((string)($job['mjb_parameters'] ?? ''), true);
	return is_array($params) ? $params : array();
}

/** Run the private per-row watcher, capturing what it pushed. */
function mdw_watch(MdwWatch $watch, $row) {
	$method = new ReflectionMethod('ManagedDomainWatch', 'watch');
	$method->setAccessible(true);
	$result = $method->invoke($watch, $row);
	$row->load();
	return $result;
}

// ---------------------------------------------------------------------------
section('The notice carries four values, and no setting name');

$far = mdw_row($buyer, $node, 'mdw-far-' . $suffix . '.com', '+300 days',
	RegisteredDomain::GRAD_OPERATOR);

$watch = new MdwWatch(new MdwFakeRegistrar());
check($watch->converge($far) === 1, 'a box holding nothing gets a notice job');
$jobs = mdw_notice_jobs($node, $far->get('rdm_domain'));
check(count($jobs) === 1, 'exactly one', 'jobs: ' . count($jobs));

$envelope = json_decode((string)$jobs[0]['mjb_commands'], true);
check(($envelope['primitive'] ?? '') === 'managed_domain_notice',
	'it is a primitive job, addressed by name', $jobs[0]['mjb_commands']);
$params = $envelope['params'] ?? array();
$keys = array_keys($params);
sort($keys);   // mjb_commands is jsonb; key order is the database's business
check($keys === array('domain', 'expiry_time', 'manage_url', 'state'),
	'carrying exactly the four values', json_encode($params));
check($params['domain'] === $far->get('rdm_domain'), 'the domain is one of them');
check(strpos((string)$params['expiry_time'], gmdate('Y-m-d', strtotime('+300 days'))) === 0,
	'and the expiry', 'got: ' . $params['expiry_time']);
check($params['manage_url'] === ManagedDomainWatch::manageUrl(),
	'the take-ownership link points back at this management node');

// The property this whole design exists for: the four SETTING names are
// compiled into the node's own script. A job that could name a setting would be
// a job that could name any setting, and stg_settings is where the credentials
// are.
$wire = json_encode($envelope);
foreach (array('managed_domain_name', 'managed_domain_expiry_time',
		'managed_domain_state', 'managed_domain_manage_url', 'stg_settings') as $name) {
	check(strpos($wire, $name) === false, $name . ' is nowhere on the wire', $wire);
}
check(strpos($wire, 'PGPASSWORD') === false && stripos($wire, 'psql') === false,
	'and neither is a database credential or a statement', $wire);

section('An empty custody state is a real value — it is what renders nothing');

check($params['state'] === '',
	'a domain 300 days out is told its facts with NO custody state, so its owner sees nothing',
	'state: ' . var_export($params['state'], true));
check(!$far->in_prompt_window(), 'because it is outside the prompt window');

section('The box is converged on, not pushed at');

mdw_finish($jobs[0]);
check($watch->converge($far) === 0, 'a box already holding the right four values is left alone');
check(count(mdw_notice_jobs($node, $far->get('rdm_domain'))) === 1,
	'no second job is filed');

// The expiry moves — a renewal made in the buyer's own account — and the box no
// longer matches. Nothing had to remember to push: not matching IS the trigger.
$far->set('rdm_expiry_time', gmdate('Y-m-d H:i:s', strtotime('+400 days')));
$far->save();
check($watch->converge($far) === 1, 'a changed expiry is noticed');
$jobs = mdw_notice_jobs($node, $far->get('rdm_domain'));
check(count($jobs) === 2, 'and a fresh notice is filed', 'jobs: ' . count($jobs));
check(strpos((string)mdw_params($jobs[1])['expiry_time'], gmdate('Y-m-d', strtotime('+400 days'))) === 0,
	'carrying the new date', json_encode(mdw_params($jobs[1])));

section('A job in flight is waited for, not piled on');

check($watch->converge($far) === 0, 'while it is pending, nothing further is filed');
check(count(mdw_notice_jobs($node, $far->get('rdm_domain'))) === 2, 'still two');

// ---------------------------------------------------------------------------
section('Nothing is said to the buyer until six months out');

$registrar = new MdwFakeRegistrar();
$watch = new MdwWatch($registrar);
$far->set('rdm_expiry_checked_time', gmdate('Y-m-d H:i:s'));   // no refresh due
$far->save();

mdw_watch($watch, $far);
check(trim((string)$far->get('rdm_prompt_pushed_time')) === '',
	'a domain 300 days out records no prompt, so no notice appears on their box');

$near = mdw_row($buyer, $node, 'mdw-near-' . $suffix . '.com', '+100 days',
	RegisteredDomain::GRAD_OPERATOR);
check($near->in_prompt_window(), 'a domain 100 days out is inside the window');
check($near->days_to_expiry() >= 99 && $near->days_to_expiry() <= 101,
	'and the countdown reads correctly', 'got: ' . var_export($near->days_to_expiry(), true));

$watch = new MdwWatch(new MdwFakeRegistrar());
check($watch->converge($near) === 1, 'inside the window, a notice is filed');
$near_jobs = mdw_notice_jobs($node, $near->get('rdm_domain'));
check(mdw_params($near_jobs[0])['state'] === RegisteredDomain::GRAD_OPERATOR,
	'carrying the real custody state — this is what makes the notice appear',
	json_encode(mdw_params($near_jobs[0])));

section('A dispatch is not a prompt; only a job that landed is');

// The buyer's FIRST mention of a deadline that takes their site and their email
// with it. Recording it from a dispatch would mean a job that failed — the agent
// down that hour, the node missing the primitive — reads as a prompt they saw.
$near->load();
check(trim((string)$near->get('rdm_prompt_pushed_time')) === '',
	'filing the job records nothing on the row');

mdw_finish($near_jobs[0], 'failed');
check($watch->converge($near) === 1, 'a failed job leaves the box not matching, so it is re-filed');
$near->load();
check(trim((string)$near->get('rdm_prompt_pushed_time')) === '',
	'and the row still records no prompt — the buyer has not been told');
$near_jobs = mdw_notice_jobs($node, $near->get('rdm_domain'));
check(count($near_jobs) === 2, 'the retry exists', 'jobs: ' . count($near_jobs));

mdw_finish($near_jobs[1]);
check($watch->converge($near) === 1, 'the completed job is what resolves it');
$near->load();
check(trim((string)$near->get('rdm_prompt_pushed_time')) !== '',
	'now the row records that the buyer has been told');

section('A prompt that reached the box is not filed again');

check($watch->converge($near) === 0, 'an already-matching row does nothing further');
check(count(mdw_notice_jobs($node, $near->get('rdm_domain'))) === 2,
	'and the box is not written to a third time');

// ---------------------------------------------------------------------------
section('Custody is only asked about once a push is in flight');

$registrar = new MdwFakeRegistrar();
$registrar->in_account = false;    // would report "graduated" if asked
$watch = new MdwWatch($registrar);

$untouched = mdw_row($buyer, $node, 'mdw-untouched-' . $suffix . '.com', '+300 days',
	RegisteredDomain::GRAD_OPERATOR);
$custody = new ReflectionMethod('ManagedDomainWatch', 'check_custody');
$custody->setAccessible(true);
check($custody->invoke($watch, $untouched) === 0, 'a domain nobody asked to move is not checked');
$untouched->load();
check($untouched->get('rdm_graduation_state') === RegisteredDomain::GRAD_OPERATOR,
	'and its custody state is untouched — no API quota spent on a settled question');

section('A domain that has left the account is recognised as the buyer\'s');

$moved = mdw_row($buyer, $node, 'mdw-moved-' . $suffix . '.com', '+60 days',
	RegisteredDomain::GRAD_SENT);
check($custody->invoke($watch, $moved) === 1, 'the check resolves the row');
$moved->load();
check($moved->get('rdm_graduation_state') === RegisteredDomain::GRAD_SELF,
	'"not in this account" flips it to self custody');

section('A domain still in the account stays where it is');

$registrar->in_account = true;
$waiting = mdw_row($buyer, $node, 'mdw-waiting-' . $suffix . '.com', '+60 days',
	RegisteredDomain::GRAD_SENT);
check($custody->invoke($watch, $waiting) === 0, 'nothing changes');
$waiting->load();
check($waiting->get('rdm_graduation_state') === RegisteredDomain::GRAD_SENT,
	'the buyer has not accepted yet, and we do not pretend otherwise');

section('A finished domain costs the registrar nothing, and still gets its last word');

// Self-custody means the REGISTRAR side is done: no expiry to re-read, no
// custody to ask about. The BOX side is not — self_custody is the value that
// makes the notice stop rendering, so a buyer whose job failed would still be
// told to do something they have already done.
$registrar = new MdwFakeRegistrar();
$watch = new MdwWatch($registrar);
$done = mdw_row($buyer, $node, 'mdw-done-' . $suffix . '.com', '+60 days',
	RegisteredDomain::GRAD_SELF);

check(mdw_watch($watch, $done) === 1, 'the box is told custody has moved');
check($registrar->expiry_calls === 0,
	'and not one registrar call is spent on a domain that is no longer ours');
$done_jobs = mdw_notice_jobs($node, $done->get('rdm_domain'));
check(count($done_jobs) === 1 && mdw_params($done_jobs[0])['state'] === RegisteredDomain::GRAD_SELF,
	'the final state is what it carries', json_encode(mdw_params($done_jobs[0] ?? array())));

mdw_finish($done_jobs[0]);
$registrar->expiry_calls = 0;
check(mdw_watch($watch, $done) === 0, 'once it has landed, there is nothing left to do at all');
check($registrar->expiry_calls === 0, 'still no registrar call');

section('The expiry is refreshed weekly, not every tick');

$registrar = new MdwFakeRegistrar();
$registrar->expiry = gmdate('Y-m-d H:i:s', strtotime('+400 days'));
$watch = new MdwWatch($registrar);
$refresh = new ReflectionMethod('ManagedDomainWatch', 'refresh_expiry');
$refresh->setAccessible(true);

$fresh = mdw_row($buyer, $node, 'mdw-fresh-' . $suffix . '.com', '+300 days',
	RegisteredDomain::GRAD_OPERATOR);
check($refresh->invoke($watch, $fresh) === 0, 'a recently-checked row is not re-read');
check($registrar->expiry_calls === 0, 'no call is made');

$stale = mdw_row($buyer, $node, 'mdw-stale-' . $suffix . '.com', '+300 days',
	RegisteredDomain::GRAD_OPERATOR);
$stale->set('rdm_expiry_checked_time', gmdate('Y-m-d H:i:s', strtotime('-10 days')));
$stale->save();
$refresh->invoke($watch, $stale);
$stale->load();
check($registrar->expiry_calls === 1, 'a stale row is re-read exactly once');
check(strpos((string)$stale->get('rdm_expiry_time'), gmdate('Y-m-d', strtotime('+400 days'))) === 0,
	'and adopts the registrar\'s answer — a renewal made in the buyer\'s own account shows up here',
	'got: ' . $stale->get('rdm_expiry_time'));

section('A domain year paid for but never registered is reported, once');

// The mirror of the pipeline's own paid-for gate. A buyer who removes the
// HOSTING line from their cart and keeps the domain line pays for a year whose
// intake never fires — no row is written, so nothing else in the system would
// ever notice. Only arithmetic on the order finds it.
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

$domain_product = new Product(NULL);
$domain_product->set('pro_name', 'Domain registration (1 year) [mdw]');
$domain_product->set('pro_link', 'mdw-domain-year-' . $suffix);
$domain_product->set('pro_is_active', true);
$domain_product->save();
$domain_product->load();
harness_register_row('pro_products', 'pro_product_id', $domain_product->key);
harness_set_setting_mem('store_domain_registration_product_id', (string)$domain_product->key);
$mdw_mark_before = ProvisioningSetup::readSetting('server_manager_domain_orphan_swept_id');
harness_defer(function () use ($mdw_mark_before) {
	ProvisioningSetup::writeSetting('server_manager_domain_orphan_swept_id', $mdw_mark_before);
});
ProvisioningSetup::writeSetting('server_manager_domain_orphan_swept_id', '0');

/** A paid domain-year line on its own order, settled long enough to judge. */
function mdw_paid_domain_line($buyer, $domain_product, $settled = true) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $buyer->key);
	$order->save();
	$order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);

	$line = new OrderItem(NULL);
	$line->set('odi_ord_order_id', $order->key);
	$line->set('odi_pro_product_id', $domain_product->key);
	$line->set('odi_usr_user_id', $buyer->key);
	$line->set('odi_price', '10.00');
	$line->set('odi_status', OrderItem::STATUS_PAID);
	$line->save();
	$line->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $line->key);

	// The sweep ignores anything too young to have settled; age the row when
	// the case under test is an already-settled one.
	if ($settled) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("UPDATE odi_order_items SET odi_status_change_time = now() - interval '1 hour' "
			. 'WHERE odi_order_item_id = ?');
		$q->execute(array($line->key));
	}
	return array((int)$order->key, (int)$line->key);
}

list($orphan_order, $orphan_line) = mdw_paid_domain_line($buyer, $domain_product);

$watch = new MdwWatch(new MdwFakeRegistrar());
check($watch->sweep() === 1, 'the unclaimed line is found');
check(count($watch->alerted) === 1 && $watch->alerted[0]['order'] === $orphan_order,
	'and the alert names the order');
check($watch->alerted[0]['unclaimed'] === 1, 'reporting one unregistered domain year');

$mark = (int)ProvisioningSetup::readSetting('server_manager_domain_orphan_swept_id');
check($mark >= $orphan_line, 'the watermark advanced past the line it examined',
	'mark: ' . $mark . ', line: ' . $orphan_line);

$again = new MdwWatch(new MdwFakeRegistrar());
check($again->sweep() === 0, 'a second tick does not report it again');
check(count($again->alerted) === 0, 'the operator is told once, not every fifteen minutes');

section('An alert that never went does not consume its order');

// The mark is what makes this report once-only, so stepping past an order
// nobody was actually told about would lose it forever — the exact failure the
// sweep exists to prevent, reintroduced by the sweep itself.
list($stuck_order, $stuck_line) = mdw_paid_domain_line($buyer, $domain_product);
// The mark starts immediately below this order's line, so it is the ONLY
// candidate in range — otherwise an earlier orphan would be the one the sweep
// stops on and this would assert nothing about the order it names.
ProvisioningSetup::writeSetting('server_manager_domain_orphan_swept_id', (string)($stuck_line - 1));

$failing = new MdwWatch(new MdwFakeRegistrar());
$failing->alerts_fail = true;
check($failing->sweep() === 0, 'a failed send reports no alert');
check(count($failing->alerted) === 1 && $failing->alerted[0]['order'] === $stuck_order,
	'though it did try, on the order in question');
$mark = (int)ProvisioningSetup::readSetting('server_manager_domain_orphan_swept_id');
check($mark < $stuck_line, 'and the watermark stays behind the order it could not report',
	'mark: ' . $mark . ', line: ' . $stuck_line);

$retry = new MdwWatch(new MdwFakeRegistrar());
check($retry->sweep() === 1, 'so the next tick tries again');
$reported = array();
foreach ($retry->alerted as $a) { $reported[] = $a['order']; }
check(in_array($stuck_order, $reported, true), 'and the order is still reported');
$mark = (int)ProvisioningSetup::readSetting('server_manager_domain_orphan_swept_id');
check($mark >= $stuck_line, 'now that it landed, the mark moves past it');

section('A line that did produce a registration is not reported');

list($ok_order, $ok_line) = mdw_paid_domain_line($buyer, $domain_product);
$claimed = mdw_row($buyer, $node, 'mdw-claimed-' . $suffix . '.com', '+300 days',
	RegisteredDomain::GRAD_OPERATOR);
$claimed->set('rdm_external_order_item_id', $ok_line);
$claimed->save();

$watch = new MdwWatch(new MdwFakeRegistrar());
check($watch->sweep() === 0, 'an order whose domain line produced a row is silent');
check(count($watch->alerted) === 0, 'and nobody is emailed about it');

section('A line too young to have settled is left for the next tick');

ProvisioningSetup::writeSetting('server_manager_domain_orphan_swept_id', '0');
list($fresh_order, $fresh_line) = mdw_paid_domain_line($buyer, $domain_product, false);
$watch = new MdwWatch(new MdwFakeRegistrar());
$watch->sweep();
$reported = array();
foreach ($watch->alerted as $a) { $reported[] = $a['order']; }
check(!in_array($fresh_order, $reported, true),
	'a charge from a moment ago is given time to file its row');
$mark = (int)ProvisioningSetup::readSetting('server_manager_domain_orphan_swept_id');
check($mark < $fresh_line, 'and the watermark does not step over it',
	'mark: ' . $mark . ', line: ' . $fresh_line);

harness_finish();
