<?php
/** @joinery-test
 * name: spam_policy
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * MailboxSpamPolicy — the derived spam posture
 * (specs/mailbox_spam_filtering_simplification.md D2/D3/D4).
 *
 * A site owner answers one question (file spam?) plus one optional capability
 * (learn from corrections?). The scanner itself ships with the mail stack and
 * is never derived — what IS derived is how it is used: whether learning
 * resolves on (clamped by filing), what scanned a message upstream, and
 * whether arriving mail is re-scored here. This test walks the full matrix:
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
 * The cell that matters most: relay/webhook + learning on → mail IS re-scored
 * here through the tenant corpus (the capability a stateless shared relay
 * cannot provide), while colocated mail never is — its own milter already
 * scored it.
 *
 * Run: php tests/run.php db --filter=spam_policy
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

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
	private function assertCell(string $label, bool $filing, bool $learning, array $expect): void {
		harness_set_setting_mem('mailbox_spam_filtering_enabled', $filing ? '1' : '0');
		harness_set_setting_mem('mailbox_spam_learning_enabled', $learning ? '1' : '0');

		check(MailboxSpamPolicy::filingEnabled() === $filing,
			$label . ': filing is ' . ($filing ? 'on' : 'off'));
		check(MailboxSpamPolicy::learningEnabled() === $expect['learning'],
			$label . ': learning resolves ' . ($expect['learning'] ? 'on' : 'off'),
			'got ' . var_export(MailboxSpamPolicy::learningEnabled(), true));
		check(MailboxSpamPolicy::scanAtIngest() === $expect['scan'],
			$label . ': ingest re-scan ' . ($expect['scan'] ? 'on' : 'off'),
			'got ' . var_export(MailboxSpamPolicy::scanAtIngest(), true));
	}

	/**
	 * The four (filing, learning) cells for a deployment where NOTHING upstream
	 * scans — this box is the MX and its own milter is the only scanner.
	 */
	private function walkColocated(string $prefix): void {
		// No ingest re-scan in any colocated cell: the box's own milter already
		// scored the mail through the same rspamd and the same corpus.
		$this->assertCell($prefix . ' filing=on learning=off', true, false,
			array('learning' => false, 'scan' => false));
		// Learning adds the corpus but not a second scan of the same mail.
		$this->assertCell($prefix . ' filing=on learning=on', true, true,
			array('learning' => true, 'scan' => false));
		// Filing off: nothing is filed, so nothing needs scanning or learning.
		$this->assertCell($prefix . ' filing=off learning=off', false, false,
			array('learning' => false, 'scan' => false));
		// Learning is CLAMPED by filing — a stored preference, inert.
		$this->assertCell($prefix . ' filing=off learning=on (clamped)', false, true,
			array('learning' => false, 'scan' => false));
	}

	/**
	 * The four cells for a deployment where something upstream (a relay or a
	 * webhook provider) already scanned every message.
	 */
	private function walkFronted(string $prefix): void {
		// Filing on, learning off: the upstream verdict is enough — no corpus
		// here means a local re-score would add nothing.
		$this->assertCell($prefix . ' filing=on learning=off', true, false,
			array('learning' => false, 'scan' => false));
		// Learning on: the corpus lives nowhere else, so the upstream verdict
		// is re-scored here against what it knows.
		$this->assertCell($prefix . ' filing=on learning=on', true, true,
			array('learning' => true, 'scan' => true));
		$this->assertCell($prefix . ' filing=off learning=off', false, false,
			array('learning' => false, 'scan' => false));
		$this->assertCell($prefix . ' filing=off learning=on (clamped)', false, true,
			array('learning' => false, 'scan' => false));
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

		// Endpoint resolution is not a choice; assert the default and move on.
		section('controller endpoint');
		harness_set_setting_mem('mailbox_rspamd_controller_url', '');
		check(MailboxSpamPolicy::controllerUrl() === MailboxSpamPolicy::DEFAULT_CONTROLLER_URL,
			'empty setting falls back to the loopback controller',
			'got ' . MailboxSpamPolicy::controllerUrl());
		harness_set_setting_mem('mailbox_rspamd_controller_url', 'http://127.0.0.1:11334/');
		check(MailboxSpamPolicy::controllerUrl() === 'http://127.0.0.1:11334',
			'a trailing slash is trimmed so callers can append a path');

		// --- Colocated: this box IS the MX -----------------------------------
		section('colocated Postfix (nothing upstream scans)');
		MailboxSpamPolicy::reset();
		harness_set_setting_mem('mailbox_provider', 'postfix');
		check(MailboxSpamPolicy::upstreamScanner() === 'none',
			'colocated + postfix → nothing upstream scans',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkColocated('colocated/postfix');

		// An empty or misspelled provider must not flip the derivation: the
		// registry resolves both to Postfix, and the policy reads the RESOLVED
		// provider, never the raw row.
		section('colocated, unresolvable provider setting');
		harness_set_setting_mem('mailbox_provider', '');
		check(MailboxSpamPolicy::upstreamScanner() === 'none',
			'empty provider resolves to postfix → nothing upstream scans',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkColocated('colocated/empty-provider');

		harness_set_setting_mem('mailbox_provider', 'not-a-real-provider');
		check(MailboxSpamPolicy::upstreamScanner() === 'none',
			'unknown provider resolves to postfix → nothing upstream scans',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkColocated('colocated/unknown-provider');

		// --- Webhook provider, no relay --------------------------------------
		section('webhook provider (provider scans upstream)');
		harness_set_setting_mem('mailbox_provider', 'mailgun');
		check(MailboxSpamPolicy::upstreamScanner() === 'provider',
			'webhook provider → the provider scanned it',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkFronted('webhook/mailgun');

		// --- Self-hosted relay ------------------------------------------------
		section('self-hosted relay (relay scans upstream)');
		harness_set_setting_mem('mailbox_provider', 'postfix');
		$this->makeRelay(false);
		check(MailboxSpamPolicy::upstreamScanner() === 'relay',
			'self-hosted relay → the relay scanned it',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkFronted('relay/self-hosted');

		// A webhook provider in front of a relay row: the provider is the thing
		// actually delivering mail, so it wins the description.
		harness_set_setting_mem('mailbox_provider', 'mailgun');
		check(MailboxSpamPolicy::upstreamScanner() === 'provider',
			'webhook provider outranks a relay row in the description',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkFronted('relay+webhook');
		$this->dropRelays();

		// --- Hosted fleet slot -------------------------------------------------
		section('hosted fleet slot (relay scans upstream)');
		harness_set_setting_mem('mailbox_provider', 'postfix');
		$this->makeRelay(true);
		check(MailboxSpamPolicy::upstreamScanner() === 'relay',
			'fleet slot behaves exactly like a self-hosted relay here',
			'got ' . MailboxSpamPolicy::upstreamScanner());
		$this->walkFronted('fleet');
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
