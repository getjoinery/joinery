<?php
/** @joinery-test
 * name: forward_and_store_ordering
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * forward_and_store store-first ordering (specs/mailbox_data_loss_fixes.md, Fix 5).
 *
 * The retained copy must be persisted BEFORE anything on the forward side runs,
 * so it survives a forward failure and is never skipped by a forward-side gate:
 *
 *  - store failure  → processEmail returns 75 (sender retries) and the forward
 *    is NOT attempted (so the retry's forward is the first forward — no dup);
 *  - rate-limited forward → the copy is stored and the forward is skipped (the
 *    old best-effort-tail order dropped the copy here);
 *  - happy path → copy stored AND forward attempted exactly once.
 *
 * Run: php plugins/mailbox/tests/forward_and_store_ordering_test.php  (schema synced).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/lib/mailbox_test_fixture.php');
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));

/**
 * Router that mocks the relay send and lets a test force a store failure or a
 * rate-limit block, without touching SMTP or the live rate-limit state.
 */
class ForwardStoreProbeRouter extends InboundEmailRouter {
	public $forward_calls = 0;
	public $fail_store = false;
	public $block_alias_rate = false;

	public function forwardEmail($raw_email, $parsed, $alias, $domain, $destinations) {
		$this->forward_calls++;
		$map = array();
		foreach ($destinations as $d) { $map[$d] = true; } // all-success
		return $map;
	}

	protected function persistRawAndManifest(int $message_id, string $raw_email, $alias = null, ?string $dek = null) {
		if ($this->fail_store) {
			throw new \RuntimeException('forced store failure (simulating store backend down)');
		}
		parent::persistRawAndManifest($message_id, $raw_email, $alias, $dek);
	}

	protected function checkAliasRateLimit($alias_id) {
		if ($this->block_alias_rate) { return false; }
		return parent::checkAliasRateLimit($alias_id);
	}
}

class ForwardAndStoreOrderingTest {
	private $db;
	private $suffix;
	private $domain_id;

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }

	function run() {
		section('forward_and_store store-first ordering');
		try {
			$this->setUp();
			$this->testStoreFailureDefersAndDoesNotForward();
			$this->testRateLimitedStillStores();
			$this->testHappyPathStoresAndForwardsOnce();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		mailbox_purge_domains('fas-test-%');
		$this->suffix = substr(md5(uniqid('fas', true)), 0, 8);
		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'fas-test-' . $this->suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->save();
		$this->domain_id = intval($domain->key);
		$this->out('  fixtures ready (suffix ' . $this->suffix . ')');
	}

	private function makeForwardStoreAlias($local) {
		$a = new InboundEmailAlias(NULL);
		$a->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$a->set('iea_alias', $local);
		$a->set('iea_delivery_mode', InboundEmailAlias::MODE_FORWARD_AND_STORE);
		$a->set('iea_destinations', 'dest@example.test');
		$a->set('iea_is_enabled', true);
		$a->prepare(); $a->save();
		return $a;
	}

	private function recipientFor($local) {
		return $local . '@fas-test-' . $this->suffix . '.example';
	}

	private function rawFor($local, $token) {
		return implode("\r\n", array(
			'From: Sender <sender@example.com>',
			'To: ' . $this->recipientFor($local),
			'Subject: forward_and_store ordering',
			'Message-ID: <' . $token . '@example.com>',
			'MIME-Version: 1.0',
			'Content-Type: text/plain; charset=UTF-8',
			'',
			'Body ' . $token . '.',
			'',
		));
	}

	private function countRows($token) {
		$stmt = $this->db->prepare(
			"SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_message_id_header = ?");
		$stmt->execute(array('<' . $token . '@example.com>'));
		return intval($stmt->fetchColumn());
	}

	private function testStoreFailureDefersAndDoesNotForward() {
		$local = 'fail' . $this->suffix;
		$this->makeForwardStoreAlias($local);
		$token = 'fas-fail-' . $this->suffix;

		$r = new ForwardStoreProbeRouter();
		$r->fail_store = true;
		$code = $r->processEmail($this->rawFor($local, $token), $this->recipientFor($local));

		check($code === 75, 'store failure defers (returns 75 so the sender retries)');
		check($r->forward_calls === 0, 'forward was NOT attempted when the store failed');
		check($this->countRows($token) === 0, 'no partial row survived the deferred store');

		// The retry: store now succeeds → stored once, forwarded once (no dup).
		$r2 = new ForwardStoreProbeRouter();
		$code2 = $r2->processEmail($this->rawFor($local, $token), $this->recipientFor($local));
		check($code2 === 0, 'retry succeeds (returns 0)');
		check($r2->forward_calls === 1, 'retry forwards exactly once (first forward — no duplicate)');
		check($this->countRows($token) === 1, 'retry stored exactly one copy');
	}

	private function testRateLimitedStillStores() {
		$local = 'rl' . $this->suffix;
		$this->makeForwardStoreAlias($local);
		$token = 'fas-rl-' . $this->suffix;

		$r = new ForwardStoreProbeRouter();
		$r->block_alias_rate = true; // forward is rate-limited
		$code = $r->processEmail($this->rawFor($local, $token), $this->recipientFor($local));

		check($code === 0, 'rate-limited forward_and_store still accepts (returns 0)');
		check($r->forward_calls === 0, 'rate-limited: forward skipped');
		check($this->countRows($token) === 1, 'rate-limited: the copy is STILL stored (Fix 5 — was dropped before)');
	}

	private function testHappyPathStoresAndForwardsOnce() {
		$local = 'ok' . $this->suffix;
		$this->makeForwardStoreAlias($local);
		$token = 'fas-ok-' . $this->suffix;

		$r = new ForwardStoreProbeRouter();
		$code = $r->processEmail($this->rawFor($local, $token), $this->recipientFor($local));

		check($code === 0, 'happy path accepts (returns 0)');
		check($r->forward_calls === 1, 'happy path forwards exactly once');
		check($this->countRows($token) === 1, 'happy path stores the copy');
	}

	private function tearDown() {
		try {
			if ($this->domain_id) {
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM iel_inbound_email_logs WHERE iel_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($this->domain_id));
			}
		} catch (\Throwable $e) {}
	}
}

$test = new ForwardAndStoreOrderingTest();
$test->run();
harness_finish();
