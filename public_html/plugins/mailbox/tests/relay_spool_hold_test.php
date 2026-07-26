<?php
/** @joinery-test
 * name: relay_spool_hold
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Relay spool hold/age-out outcomes (specs/mailbox_data_loss_fixes.md, Fixes 6/7).
 *
 * The pull consumer must not delete recoverable mail. ingestOne classifies each
 * blob into an outcome; the pull loop acks everything EXCEPT 'hold'. This test
 * drives ingestOne directly (via reflection — it is a private step of the pull)
 * against staged .seal/.meta pairs and asserts the classification:
 *
 *  - empty/malformed recipient      → 'unroutable' (genuinely undeliverable, ack-drop)
 *  - disabled/missing domain, recent → 'hold'       (leave on relay for recovery)
 *  - disabled/missing domain, old    → 'aged_out'   (past grace window, ack-drop)
 *  - Fortress blob, no owner, recent → 'hold'       (Fix 7 — never an invisible row)
 *
 * Run: php plugins/mailbox/tests/relay_spool_hold_test.php  (schema synced).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));

class RelaySpoolHoldTest {
	private $db;
	private $suffix;
	private $disabled_domain_id;
	private $stage;
	private $consumer;
	private $ingest; // ReflectionMethod

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	function run() {
		section('Relay spool hold / age-out outcomes');
		try {
			$this->setUp();
			$this->testUnroutable();
			$this->testDisabledDomainHolds();
			$this->testDisabledDomainAgesOut();
			$this->testFortressOwnerlessHolds();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		$this->suffix = substr(md5(uniqid('rsh', true)), 0, 8);

		// A domain that exists but is DISABLED — the "temporarily/accidentally
		// disabled" case whose still-sealed mail must be held, not deleted.
		$d = new InboundEmailDomain(NULL);
		$d->set('ied_domain', 'rsh-off-' . $this->suffix . '.example');
		$d->set('ied_is_enabled', false);
		$d->save();
		$this->disabled_domain_id = intval($d->key);

		$this->stage = sys_get_temp_dir() . '/rsh-' . $this->suffix;
		@mkdir($this->stage, 0777, true);

		// ingestOne never touches $this->relay on the hold/unroutable branches, so
		// an unsaved relay is enough to construct the consumer.
		$this->consumer = new RelaySpoolConsumer(new MailboxRelay(NULL));
		$ref = new ReflectionMethod(RelaySpoolConsumer::class, 'ingestOne');
		$ref->setAccessible(true);
		$this->ingest = $ref;
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . '  fixtures ready (suffix ' . $this->suffix . ")\n";
	}

	/** Stage a .seal/.meta pair and return [seal_path, meta_path, spool_id]. */
	private function stagePair(array $meta, string $seal_body = 'SEALEDBLOB'): array {
		$spool_id = '1700000000-' . bin2hex(random_bytes(4));
		$seal = $this->stage . '/' . $spool_id . '.seal';
		$metap = $this->stage . '/' . $spool_id . '.meta';
		file_put_contents($seal, $seal_body);
		file_put_contents($metap, json_encode($meta));
		return array($seal, $metap, $spool_id);
	}

	private function outcome(array $meta): string {
		list($seal, $metap, $spool_id) = $this->stagePair($meta);
		return (string)$this->ingest->invoke($this->consumer, $seal, $metap, $spool_id);
	}

	private function testUnroutable() {
		$o = $this->outcome(array('recipient' => '', 'key_kind' => 'transport',
			'received_utc' => gmdate('Y-m-d\TH:i:s\Z')));
		check($o === 'unroutable', "empty recipient → 'unroutable' (got '$o')");

		$o2 = $this->outcome(array('recipient' => 'no-at-sign', 'key_kind' => 'transport',
			'received_utc' => gmdate('Y-m-d\TH:i:s\Z')));
		check($o2 === 'unroutable', "malformed recipient (no @) → 'unroutable' (got '$o2')");
	}

	private function testDisabledDomainHolds() {
		$o = $this->outcome(array(
			'recipient'    => 'anyone@rsh-off-' . $this->suffix . '.example',
			'key_kind'     => 'transport',
			'received_utc' => gmdate('Y-m-d\TH:i:s\Z'), // just now → within grace
		));
		check($o === 'hold', "recent blob for a disabled domain → 'hold' (got '$o')");
	}

	private function testDisabledDomainAgesOut() {
		$o = $this->outcome(array(
			'recipient'    => 'anyone@rsh-off-' . $this->suffix . '.example',
			'key_kind'     => 'transport',
			'received_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 40 * 86400), // 40d > 30d grace
		));
		check($o === 'aged_out', "old blob past the grace window → 'aged_out' (got '$o')");
	}

	private function testFortressOwnerlessHolds() {
		// Fortress blob (key_kind=user) at a domain with no matching alias and an
		// empty seal public_key → no resolvable owner → hold (never a stored,
		// invisible ownerless row). Use an ENABLED domain so the domain gate
		// passes and we reach the owner-resolution branch.
		$d = new InboundEmailDomain(NULL);
		$d->set('ied_domain', 'rsh-on-' . $this->suffix . '.example');
		$d->set('ied_is_enabled', true);
		$d->save();
		$this->enabled_domain_id = intval($d->key);

		$o = $this->outcome(array(
			'recipient'    => 'ghost@rsh-on-' . $this->suffix . '.example',
			'key_kind'     => 'user',
			'public_key'   => '', // matches no vault
			'received_utc' => gmdate('Y-m-d\TH:i:s\Z'),
		));
		check($o === 'hold', "ownerless Fortress blob, recent → 'hold' not stored (got '$o')");

		// And it must NOT have created an (invisible) pending row.
		$stmt = $this->db->prepare(
			"SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_recipient = ?");
		$stmt->execute(array('ghost@rsh-on-' . $this->suffix . '.example'));
		check(intval($stmt->fetchColumn()) === 0, 'ownerless Fortress hold stored NO row');
	}

	private $enabled_domain_id = 0;

	private function tearDown() {
		try {
			foreach (array($this->disabled_domain_id, $this->enabled_domain_id) as $did) {
				if ($did) {
					$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . intval($did));
					$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($did));
				}
			}
			if ($this->stage && is_dir($this->stage)) {
				foreach (glob($this->stage . '/*') ?: array() as $f) { @unlink($f); }
				@rmdir($this->stage);
			}
		} catch (\Throwable $e) {}
	}
}

$test = new RelaySpoolHoldTest();
$test->run();
harness_finish();
