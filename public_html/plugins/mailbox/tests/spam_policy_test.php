<?php
/** @joinery-test
 * name: spam_policy
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * MailboxSpamPolicy — the derived spam posture (spam_learning_in_core.md).
 *
 * A site owner answers one question (file spam?) plus one optional capability
 * (learn from corrections?). Learning lives in the application, so the only
 * other thing derived is what scanned a message before it reached this box.
 * This test walks the matrix:
 *
 *   topology  colocated / self-hosted relay / hosted fleet slot
 *   provider  postfix / webhook (mailgun) / empty string (resolves to postfix)
 *   filing    on / off
 *   learning  on / off
 *
 * Topology comes from REAL relay rows, not injected facts: the derivation runs
 * through InboundEmailSetupCheck::topology(), which loads a Multi collection,
 * and a hand-built fact array would step straight over the class of bug where
 * an unloaded collection silently reports nothing.
 *
 * Learning resolves the same way on every topology — webhook-only included —
 * and is clamped off by filing.
 *
 * Run: php tests/run.php db --filter=spam_policy
 *
 * @version 2.0 - learning in core: the re-scan and controller posture are gone
 * @version 1.2
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));

class SpamPolicyTest {

	/** @var array<int> Relay rows created by this run, deleted at teardown. */
	private $relay_ids = array();

	/**
	 * Set the two switches, then assert the whole derived posture at once.
	 *
	 * $expect is written out by hand per topology class — never recomputed from
	 * the same rule the implementation uses, which would only prove the code
	 * agrees with itself.
	 */
	private function assertCell(string $label, bool $filing, bool $learning, bool $expect_learning): void {
		harness_set_setting_mem('mailbox_spam_filtering_enabled', $filing ? '1' : '0');
		harness_set_setting_mem('mailbox_spam_learning_enabled', $learning ? '1' : '0');

		check(MailboxSpamPolicy::filingEnabled() === $filing,
			$label . ': filing is ' . ($filing ? 'on' : 'off'));
		check(MailboxSpamPolicy::learningEnabled() === $expect_learning,
			$label . ': learning resolves ' . ($expect_learning ? 'on' : 'off'),
			'got ' . var_export(MailboxSpamPolicy::learningEnabled(), true));
	}

	/**
	 * The four (filing, learning) cells. They are the same on every topology:
	 * the corpus is in the application, so nothing about where mail is scanned
	 * makes learning more or less available.
	 */
	private function walkCells(string $prefix): void {
		$this->assertCell($prefix . ' filing=on learning=off', true, false, false);
		$this->assertCell($prefix . ' filing=on learning=on', true, true, true);
		$this->assertCell($prefix . ' filing=off learning=off', false, false, false);
		// Learning is CLAMPED by filing — a stored preference, inert.
		$this->assertCell($prefix . ' filing=off learning=on (clamped)', false, true, false);
	}

	/** Create a relay row so topology() resolves through a real collection load. */
	private function makeRelay(bool $hosted): void {
		$relay = new MailboxRelay(NULL);
		$relay->set('mrl_name', 'spam_policy_test scratch');
		$relay->set('mrl_tenant_slug', 'main');
		$relay->set('mrl_is_hosted', $hosted);
		$relay->set('mrl_mx_hostname', $hosted ? 't1.mx.example' : 'relay.tenant.example');
		$relay->set('mrl_is_enabled', true);
		$relay->save();
		$id = intval($relay->key);
		$this->relay_ids[] = $id;
		harness_register_row('mrl_mailbox_relays', 'mrl_mailbox_relay_id', $id);
		MailboxSpamPolicy::reset();
	}

	/** Hard-delete this run's relay rows so the next topology resolves clean. */
	private function dropRelays(): void {
		if (!$this->relay_ids) {
			MailboxSpamPolicy::reset();
			return;
		}
		$db = DbConnector::get_instance()->get_db_link();
		foreach ($this->relay_ids as $id) {
			$q = $db->prepare('DELETE FROM mrl_mailbox_relays WHERE mrl_mailbox_relay_id = ?');
			$q->execute(array($id));
		}
		$this->relay_ids = array();
		MailboxSpamPolicy::reset();
	}

	function run() {
		// A relay row already on this deployment would win the topology lookup
		// (topology() takes the first non-deleted relay), silently pinning every
		// "colocated" cell to a relay answer. Refuse to report a green run on
		// state we do not control.
		$db = DbConnector::get_instance()->get_db_link();
		$existing = (int)$db->query(
			'SELECT count(*) FROM mrl_mailbox_relays WHERE mrl_delete_time IS NULL')->fetchColumn();
		if ($existing > 0) {
			harness_skip('this deployment already has a relay row — topology cannot be controlled here');
			return;
		}

		section('no scanner controller is consulted');
		foreach (array('controllerUrl', 'controllerReachable', 'scannerAvailable', 'scanAtIngest',
				'localVerdictReplaces', 'overrideScannerAvailable') as $gone) {
			check(!method_exists('MailboxSpamPolicy', $gone), 'MailboxSpamPolicy::' . $gone . ' is gone');
		}

		// --- Colocated: this box IS the MX -----------------------------------
		section('colocated Postfix (nothing upstream scans)');
		MailboxSpamPolicy::reset();
		harness_set_setting_mem('mailbox_provider', 'postfix');
		check(MailboxSpamPolicy::upstreamScanner() === 'none',
			'colocated + postfix → nothing upstream scans',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkCells('colocated/postfix');

		// An empty or misspelled provider must not flip the derivation: the
		// registry resolves both to Postfix, and the policy reads the RESOLVED
		// provider, never the raw row.
		section('colocated, unresolvable provider setting');
		harness_set_setting_mem('mailbox_provider', '');
		check(MailboxSpamPolicy::upstreamScanner() === 'none',
			'empty provider resolves to postfix → nothing upstream scans',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkCells('colocated/empty-provider');

		harness_set_setting_mem('mailbox_provider', 'not-a-real-provider');
		check(MailboxSpamPolicy::upstreamScanner() === 'none',
			'unknown provider resolves to postfix → nothing upstream scans',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkCells('colocated/unknown-provider');

		// --- Webhook provider, no relay --------------------------------------
		section('webhook provider (provider scans upstream)');
		harness_set_setting_mem('mailbox_provider', 'mailgun');
		check(MailboxSpamPolicy::upstreamScanner() === 'provider',
			'webhook provider → the provider scanned it',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkCells('webhook/mailgun');

		// --- Self-hosted relay ------------------------------------------------
		section('self-hosted relay (relay scans upstream)');
		harness_set_setting_mem('mailbox_provider', 'postfix');
		$this->makeRelay(false);
		check(MailboxSpamPolicy::upstreamScanner() === 'relay',
			'self-hosted relay → the relay scanned it',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkCells('relay/self-hosted');

		// A webhook provider in front of a relay row: the provider is the thing
		// actually delivering mail, so it wins the description.
		harness_set_setting_mem('mailbox_provider', 'mailgun');
		check(MailboxSpamPolicy::upstreamScanner() === 'provider',
			'webhook provider outranks a relay row in the description',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkCells('relay+webhook');
		$this->dropRelays();

		// --- Hosted fleet slot -------------------------------------------------
		section('hosted fleet slot (relay scans upstream)');
		harness_set_setting_mem('mailbox_provider', 'postfix');
		$this->makeRelay(true);
		check(MailboxSpamPolicy::upstreamScanner() === 'relay',
			'fleet slot behaves exactly like a self-hosted relay here',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkCells('fleet');
		$this->dropRelays();

		// --- Truthiness of the stored rows -------------------------------------
		// Settings are written as strings by several paths; the policy must read
		// every shape the platform actually stores, and treat nothing else as on.
		section('setting truthiness');
		harness_set_setting_mem('mailbox_provider', 'postfix');
		foreach (array('1', 'true', 't', 'yes', 'on', 'TRUE') as $on) {
			harness_set_setting_mem('mailbox_spam_filtering_enabled', $on);
			check(MailboxSpamPolicy::filingEnabled() === true, "stored '$on' reads as on");
		}
		// '' is deliberately absent: Globalvars::get_setting treats a blank
		// value as "not set" and falls through to the stored row, so a blank
		// mem-override reads whatever the database holds — that is get_setting
		// semantics, not a truthiness case this policy can see.
		foreach (array('0', 'false', 'f', 'no') as $off) {
			harness_set_setting_mem('mailbox_spam_filtering_enabled', $off);
			check(MailboxSpamPolicy::filingEnabled() === false, "stored '$off' reads as off");
		}
	}
}

$test = new SpamPolicyTest();
$test->run();
harness_finish();
