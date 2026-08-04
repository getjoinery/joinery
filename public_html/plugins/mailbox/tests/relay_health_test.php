<?php
/** @joinery-test
 * name: relay_health
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Relay scanner health — can this deployment tell a relay that scanned and found
 * nothing from a relay whose scanner is dead?
 *
 * This exists because of a specific false conclusion. Investigating a quiet
 * mailbox behind a relay (2,273 messages, not one spam score), the stored-data
 * reading was that the relay had never scanned anything. A GTUBE probe sent
 * straight at the relay disproved it — 554 5.7.1 Gtube pattern, rspamd's own
 * refusal. The scanner had been alive the whole time; the mailbox was simply
 * quiet behind an RBL that rejects the worst before content is ever seen.
 *
 * The reason the inference is unfixable, not merely wrong: a content verdict is
 * recorded only when something is FLAGGED, and the relay accepts unscanned mail
 * rather than deferring it. Both states write nothing. So the answer has to come
 * from the relay, and the last section here is the guard that it always does.
 *
 * Also covered:
 *   - every answer shape the relay can give, including PONG from a relay built
 *     before this check, which must never read as "scanner dead"
 *   - the severity matrix, with the local scanner PINNED — whether a dead relay
 *     scanner is a warning or a failure depends on whether this server is
 *     covering for it, not on whether rspamd happens to run on the test machine
 *   - a drifted header contract on a perfectly healthy service, the exact fault
 *     a services-only ping would have called fine
 *   - transitions, so a relay that stays broken is announced once
 *   - the ping's output shape, asserted against the shell script itself
 *
 * Run: php plugins/mailbox/tests/relay_health_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSpamPolicy.php'));

class RelayHealthTest {

	/** A healthy ping payload, with $overrides folded in. */
	private function ping(array $overrides = array()): string {
		$payload = array(
			'status'   => 'ok',
			'services' => array('rspamd' => 'active', 'opendkim' => 'active', 'opendmarc' => 'active'),
			'milters'  => array('opendkim' => true, 'opendmarc' => true, 'rspamd' => true),
			'contract' => true,
			'provisioned' => '2.2',
			'slug'     => 'example',
		);
		foreach ($overrides as $k => $v) {
			if (is_array($v) && isset($payload[$k]) && is_array($payload[$k])) {
				$payload[$k] = array_merge($payload[$k], $v);
			} else {
				$payload[$k] = $v;
			}
		}
		return json_encode($payload);
	}

	private function eq($expected, $actual, $label) {
		return check($expected === $actual, $label, 'expected ' . var_export($expected, true)
			. ', got ' . var_export($actual, true));
	}

	function run() {
		// --- Reading the relay's answer ---------------------------------------
		section('every answer shape the relay can give');

		$ok = MailboxRelay::readHealth($this->ping());
		$this->eq(MailboxRelay::HEALTH_OK, $ok['state'], 'a healthy ping reads as healthy');
		$this->eq('', $ok['reason'], 'a healthy ping names no fault');
		$this->eq('2.2', $ok['provisioned'], 'the relay build is carried through');

		$dead = MailboxRelay::readHealth($this->ping(array('services' => array('rspamd' => 'inactive'))));
		$this->eq(MailboxRelay::HEALTH_NOT_DELIVERING, $dead['state'], 'a stopped scanner is not delivering');
		$this->eq('dead', $dead['reason'], '...and the fault is named as dead');

		$unwired = MailboxRelay::readHealth($this->ping(array('milters' => array('rspamd' => false))));
		$this->eq(MailboxRelay::HEALTH_NOT_DELIVERING, $unwired['state'],
			'a scanner running outside the mail path is not delivering either');
		$this->eq('unwired', $unwired['reason'], '...and that is a distinct fault from dead');

		// The whole point of D4: services alone would have called this healthy.
		$drift = MailboxRelay::readHealth($this->ping(array('contract' => false)));
		$this->eq(MailboxRelay::HEALTH_NOT_DELIVERING, $drift['state'],
			'a drifted header contract with every service active is NOT healthy');
		$this->eq('drift', $drift['reason'], '...and is reported as drift, not as a dead scanner');
		check(stripos($drift['detail'], 'header') !== false,
			'the drift detail says which of the two faults it was', 'detail: ' . $drift['detail']);

		// A dead scanner reports as dead even when the contract is also broken:
		// the first thing that stops a verdict is the thing to fix.
		$both = MailboxRelay::readHealth($this->ping(array(
			'services' => array('rspamd' => 'failed'), 'contract' => false)));
		$this->eq('dead', $both['reason'], 'a dead scanner outranks a drifted contract in the reason');

		section('answers that are not a health payload');

		// THE row that matters on ship day: every relay already deployed says this.
		$legacy = MailboxRelay::readHealth("PONG example\n");
		$this->eq(MailboxRelay::HEALTH_LEGACY, $legacy['state'],
			'PONG means the relay predates this check');
		check($legacy['state'] !== MailboxRelay::HEALTH_NOT_DELIVERING,
			'PONG is NEVER read as a dead scanner');

		$truncated = MailboxRelay::readHealth('{"status":"ok","services":{"rspamd":"acti');
		$this->eq(MailboxRelay::HEALTH_UNREADABLE, $truncated['state'], 'a truncated payload is unreadable');

		$this->eq(MailboxRelay::HEALTH_UNREADABLE, MailboxRelay::readHealth('not json at all')['state'],
			'non-JSON is unreadable');
		$this->eq(MailboxRelay::HEALTH_UNREADABLE, MailboxRelay::readHealth('')['state'],
			'an empty answer is unreadable');

		// A payload missing the keys the verdict rests on is unreadable, not "ok" —
		// a shape we do not recognise must never be scored as healthy.
		$this->eq(MailboxRelay::HEALTH_UNREADABLE, MailboxRelay::readHealth('{"status":"ok"}')['state'],
			'a payload without services/milters is unreadable, never healthy');

		$refused = MailboxRelay::readHealth('denied: unknown command', 1);
		$this->eq(MailboxRelay::HEALTH_UNREACHABLE, $refused['state'],
			'a non-zero exit is unreachable, not a scanner verdict');

		// --- The severity matrix ----------------------------------------------
		// Pinned, because "is a dead relay scanner a warning or a failure" depends
		// on whether THIS server is covering — never on what runs on the test box.
		section('severity depends on whether this server is covering');

		// Row 1: delivering — coverage is irrelevant, so assert it under both.
		foreach (array(true, false) as $covered) {
			$row = InboundEmailSetupCheck::relayScannerResult($ok, 'mx.example.com', '', $covered);
			$this->eq(InboundEmailSetupCheck::PASS, $row['status'],
				'delivering verdicts → pass (covering: ' . var_export($covered, true) . ')');
		}

		// Row 2: not delivering, this server covering → a warning, not a failure.
		$warn = InboundEmailSetupCheck::relayScannerResult($dead, 'mx.example.com', '', true);
		$this->eq(InboundEmailSetupCheck::WARN, $warn['status'], 'not delivering, local scan covering → warn');
		check(stripos($warn['summary'], 'scanning the mail itself') !== false,
			'the warning says this server is covering', 'summary: ' . $warn['summary']);

		// Same severity, different story: drift must not read as a quieter fault.
		$drift_row = InboundEmailSetupCheck::relayScannerResult($drift, 'mx.example.com', '', true);
		$this->eq($warn['status'], $drift_row['status'],
			'a drifted contract lands on the same severity as a dead scanner');
		check($drift_row['detail'] !== $warn['detail'],
			'...while still saying which of the two it was');

		// Row 3: not delivering, nothing covering → a failure.
		foreach (array($dead, $unwired, $drift) as $broken) {
			$row = InboundEmailSetupCheck::relayScannerResult($broken, 'mx.example.com', '', false);
			$this->eq(InboundEmailSetupCheck::FAIL, $row['status'],
				'not delivering (' . $broken['reason'] . '), nothing scanning locally → fail');
			check(stripos($row['detail'], 'not being checked') !== false,
				'...and says plainly that nothing is scanning anywhere', 'detail: ' . $row['detail']);
		}

		// Row 4: legacy is INFO in BOTH coverage states — every existing relay
		// answers PONG, and colouring that red on ship day would be a lie about
		// relays that are very likely scanning fine.
		foreach (array(true, false) as $covered) {
			$row = InboundEmailSetupCheck::relayScannerResult($legacy, 'mx.example.com', '', $covered);
			$this->eq(InboundEmailSetupCheck::INFO, $row['status'],
				'PONG → info, never a warning (covering: ' . var_export($covered, true) . ')');
		}

		// An answer we could not get, or could not read, is honestly unknown.
		foreach (array($refused, $truncated) as $unknowable) {
			$row = InboundEmailSetupCheck::relayScannerResult($unknowable, 'mx.example.com', '', false);
			$this->eq(InboundEmailSetupCheck::UNKNOWN, $row['status'],
				'no readable answer → unknown, never a scanner fault (' . $unknowable['state'] . ')');
		}

		// ...and with coverage left to the policy, a scanner this server cannot
		// reach can never come out as "covered" — the production path, exercised.
		section('with coverage left to the policy');
		$was = MailboxSpamPolicy::scanAtIngest();
		MailboxSpamPolicy::overrideScannerAvailable(false);
		$this->eq(InboundEmailSetupCheck::FAIL,
			InboundEmailSetupCheck::relayScannerResult($dead, 'mx.example.com')['status'],
			'no local scanner reachable → fail, whatever the settings say');
		MailboxSpamPolicy::overrideScannerAvailable(null);   // restore live probing
		check($was === MailboxSpamPolicy::scanAtIngest(), 'the policy pin is released again');

		// --- Transitions -------------------------------------------------------
		section('a relay that stays broken is announced once');
		$D = MailboxRelay::HEALTH_NOT_DELIVERING;
		$O = MailboxRelay::HEALTH_OK;
		$this->eq('down', MailboxRelay::healthTransition($O, $D), 'healthy → not delivering fires down');
		$this->eq('none', MailboxRelay::healthTransition($D, $D), 'still broken fires nothing');
		$this->eq('recovered', MailboxRelay::healthTransition($D, $O), 'not delivering → healthy fires recovery');
		$this->eq('none', MailboxRelay::healthTransition($O, $O), 'a pass that changes nothing is silent');
		$this->eq('down', MailboxRelay::healthTransition('', $D),
			'a first-ever answer of broken is announced — nobody has been told yet');
		foreach (array(MailboxRelay::HEALTH_LEGACY, MailboxRelay::HEALTH_UNREADABLE,
			MailboxRelay::HEALTH_UNREACHABLE) as $absence) {
			$this->eq('none', MailboxRelay::healthTransition($O, $absence),
				'losing the answer (' . $absence . ') is not a fault to announce');
			$this->eq('none', MailboxRelay::healthTransition('', $absence),
				'...nor is a first pass that learns nothing (' . $absence . ')');
		}

		// --- The ping's contract, against the shell script ---------------------
		// Asserted here so a provisioning edit that changes the answer's shape
		// fails in the test estate rather than in the field, on a relay nobody
		// can see, months later.
		section('the relay shell answers the shape this code parses');
		$this->assertPingShape();

		// --- Where the finding lands on the page -------------------------------
		section('a broken scanner comes up front; a working one stays in Advanced');
		$this->assertCardPromotion();

		// --- The regression guard ---------------------------------------------
		section('the verdict never comes from stored mail');
		$this->assertNoStoredMailInference();
	}

	/**
	 * Extract joinery-tenant-shell from provision_relay.sh, run its ping, and read
	 * the answer with the same parser production uses.
	 */
	private function assertPingShape() {
		$script = PathHelper::getIncludePath('plugins/mailbox/provisioning/provision_relay.sh');
		$source = (string)@file_get_contents($script);
		check($source !== '', 'provision_relay.sh is readable');
		if ($source === '') {
			return;
		}

		if (!preg_match('/^cat > "\$\{TENANT_SHELL\}" <<\'TENANTSHELL\'\n(.*?)\nTENANTSHELL$/ms', $source, $m)) {
			check(false, 'the tenant shell can be extracted from provision_relay.sh');
			return;
		}
		$shell = $m[1];
		check(strpos($shell, 'joinery-ping)') !== false, 'the tenant shell still has a ping verb');

		$tmp = tempnam(sys_get_temp_dir(), 'joinery_tenant_shell_');
		file_put_contents($tmp, $shell);

		$out = array();
		$code = 0;
		exec('SSH_ORIGINAL_COMMAND=joinery-ping bash ' . escapeshellarg($tmp) . ' example 2>&1', $out, $code);
		@unlink($tmp);

		$raw = implode("\n", $out);
		check($code === 0, 'ping exits clean', 'exit ' . $code . ': ' . $raw);

		$health = MailboxRelay::readHealth($raw, $code);
		check($health['state'] !== MailboxRelay::HEALTH_UNREADABLE,
			'the shell answers a payload readHealth() understands', 'answered: ' . substr($raw, 0, 300));
		check($health['state'] !== MailboxRelay::HEALTH_LEGACY,
			'the shipped shell answers health, not PONG');

		$decoded = json_decode(trim($raw), true);
		check(is_array($decoded), 'the answer is one JSON object');
		if (!is_array($decoded)) {
			return;
		}
		foreach (array('status', 'services', 'milters', 'contract', 'provisioned', 'slug') as $key) {
			check(array_key_exists($key, $decoded), 'the answer carries ' . $key);
		}
		$this->eq('example', (string)($decoded['slug'] ?? ''), 'the answer names the tenant it answered for');
		foreach (array('rspamd', 'opendkim', 'opendmarc') as $svc) {
			check(array_key_exists($svc, (array)$decoded['services']), 'services report ' . $svc);
			check(array_key_exists($svc, (array)$decoded['milters']), 'milters report ' . $svc);
		}
		check(is_bool($decoded['contract']), 'the contract check is a boolean, so PHP never parses rspamd config');

		// Multi-tenancy: several deployments share a shard, so one tenant's mail
		// volume must never ride out in another tenant's health answer.
		foreach (array('spool', 'messages', 'count', 'tenants', 'recipients') as $leak) {
			check(stripos(json_encode(array_keys($decoded)), $leak) === false,
				'the answer carries no ' . $leak . ' field — service state is not tenant data');
		}
		// Queue depth is the one measured exception, and it is GATED, not excepted:
		// emitted only where the relay has exactly one tenant, so the queue being
		// reported is wholly the asker's. This extraction runs with no relay home,
		// so the tenant count is zero and the key must be absent. The gate itself is
		// exercised at both counts in relay_upgrade_test.
		check(!array_key_exists('queue', $decoded),
			'no queue depth without a tenant registry proving a fleet-of-one');
	}

	/**
	 * A scanner fault is a Receiving card; a healthy scanner is not.
	 *
	 * The row is produced either way — this is only about whether it waits behind
	 * the Advanced disclosure, which nobody opens until mail already looks wrong.
	 * That is the same discovery problem the notification exists to solve, so the
	 * two must not disagree about what counts as news.
	 */
	private function assertCardPromotion() {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_scope.php'));

		$row = function ($status) {
			return array('id' => 'host.relay_scanner', 'scope' => '', 'layer' => 'host',
				'label' => InboundEmailSetupCheck::RELAY_SCANNER_LABEL,
				'severity' => InboundEmailSetupCheck::RECOMMENDED, 'status' => $status,
				'summary' => 'x', 'detail' => '', 'fix' => null, 'recheckable' => true);
		};

		foreach (array(InboundEmailSetupCheck::WARN, InboundEmailSetupCheck::FAIL) as $status) {
			check(_setup_is_receiving_row($row($status), 'example.com') === true,
				'a ' . $status . ' scanner is a Receiving card, not an Advanced row');
		}
		foreach (array(InboundEmailSetupCheck::PASS, InboundEmailSetupCheck::INFO,
			InboundEmailSetupCheck::UNKNOWN) as $status) {
			check(_setup_is_receiving_row($row($status), 'example.com') === false,
				'a ' . $status . ' scanner stays in Advanced — a working component is not a checklist item');
		}

		// The consequence, asserted deliberately rather than discovered later: a
		// promoted row is inside the set mailbox_setup_verdict() grades, so a
		// broken relay scanner also turns the reader's mailbox banner to
		// "attention". That is the point — but it fires on every mailbox behind
		// the relay at once, which is correct only because the fault really is
		// deployment-wide.
		foreach (array(InboundEmailSetupCheck::WARN, InboundEmailSetupCheck::FAIL) as $status) {
			$verdict = mailbox_setup_verdict(array(
				'receiving' => array($row($status)), 'forwarding' => array()));
			$this->eq('attention', $verdict['status'],
				'a ' . $status . ' scanner turns the mailbox banner to attention');
		}
		$verdict = mailbox_setup_verdict(array(
			'receiving' => array($row(InboundEmailSetupCheck::PASS)), 'forwarding' => array()));
		$this->eq('ok', $verdict['status'], 'a healthy scanner leaves the banner alone');

		// INFO is silent by design — otherwise every relay built before the health
		// ping would flip every mailbox to "attention" on the day this ships.
		$verdict = mailbox_setup_verdict(array(
			'receiving' => array($row(InboundEmailSetupCheck::INFO)), 'forwarding' => array()));
		check($verdict['status'] !== 'attention',
			'a relay too old to answer never turns the banner to attention',
			'got: ' . $verdict['status']);
	}

	/**
	 * The verdict is a function of the relay's answer and nothing else. Read the
	 * two methods that produce it and prove neither touches stored mail.
	 */
	private function assertNoStoredMailInference() {
		$methods = array(
			array('MailboxRelay', 'readHealth'),
			array('InboundEmailSetupCheck', 'relayScannerResult'),
			array('InboundEmailSetupCheck', 'checkRelayScannerHealth'),
		);
		foreach ($methods as $pair) {
			list($class, $method) = $pair;
			$ref = new ReflectionMethod($class, $method);
			$lines = file($ref->getFileName());
			$body = implode('', array_slice($lines, $ref->getStartLine() - 1,
				$ref->getEndLine() - $ref->getStartLine() + 1));
			foreach (array('iem_inbound_email_messages', 'iem_spam_score', 'iem_received_time',
				'INTERVAL') as $needle) {
				check(strpos($body, $needle) === false,
					$class . '::' . $method . ' never reads stored mail (' . $needle . ')');
			}
		}
	}
}

$test = new RelayHealthTest();
$test->run();
harness_finish();
