<?php
/** @joinery-test
 * name: relay_upgrade
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Upgrading a relay nobody can log in to.
 *
 * A cloud-provisioned relay has no shell credential in existence: the root
 * password is random and discarded, sshd is key-only, and the platform's per-run
 * key is erased at the run's terminal state. So new code reaches a relay by
 * replacing the machine's contents — drain the spool, rebuild the instance in
 * place, run the provisioning script again
 * (specs/mailbox_relay_upgrade_without_server_manager.md).
 *
 * The assertions that matter most, and why:
 *
 *   - The DRAIN GATE. A wipe destroys whatever the platform has not pulled, so a
 *     held blob or a stalled drain must stop the run. This is the only thing
 *     standing between an elective upgrade and destroyed mail.
 *   - A failed upgrade must never DELETE the instance. Every cleanup path in the
 *     provisioner funnels through destroyInstanceQuietly(), which for a provision
 *     run is correct cleanup and for an upgrade would be deleting the customer's
 *     working relay.
 *   - version_compare(), never string comparison. '2.10' < '2.9' is true as text,
 *     which would silently stop offering upgrades one minor bump after the tenth.
 *   - Queue depth is emitted at one tenant and withheld at two — on a shard the
 *     Postfix queue is shared, so its depth reads out other tenants' volume.
 *
 * Run: php plugins/mailbox/tests/relay_upgrade_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayVersion.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));

class RelayUpgradeTest {

	function run() {
		section('Shipped version');
		$this->assertShippedVersion();

		section('Version comparison');
		$this->assertComparison();

		section('Which control is offered');
		$this->assertOffers();

		section('Queue depth is gated on a fleet-of-one');
		$this->assertQueueGate();

		section('The shared-relay wipe guard');
		$this->assertSoleGuard();

		section('Queue depth: absent is not zero');
		$this->assertAbsentIsNotZero();

		section('A failed upgrade destroys nothing');
		$this->assertUpgradeNeverDestroys();

		section('The drain gate');
		$this->assertDrainGate();

		section('An upgrade updates its relay rather than minting a second');
		$this->assertNoSecondRelayRow();

		section('The re-imaged machine is a new identity on the same row');
		$this->assertHostKeyForgotten();

		section('The shard version comes from its birth report');
		$this->assertShardMarker();
	}

	// ---------------------------------------------------------------- versions

	/**
	 * The shipped version is parsed from the script that already declares it, so a
	 * bump has one place to happen. If the declaration's SHAPE changes, this fails
	 * here rather than silently reading '' — which would read as unknown and offer
	 * an upgrade on every healthy relay.
	 */
	private function assertShippedVersion() {
		$shipped = RelayVersion::shipped();
		check($shipped !== '', 'a shipped relay version is found in provision_relay.sh',
			'got: ' . var_export($shipped, true));
		check(preg_match('/^\d+(\.\d+)*$/', $shipped) === 1,
			'the shipped version is dotted-numeric', 'got: ' . $shipped);

		// Asserted against the file so the parser and the declaration cannot drift.
		$source = (string)@file_get_contents(
			PathHelper::getIncludePath('plugins/mailbox/provisioning/provision_relay.sh'));
		check(strpos($source, 'RELAY_VERSION="' . $shipped . '"') !== false,
			'the parsed version is the one the script declares');

		// The version HISTORY comments above the declaration ("# Version: 2.2 - ...")
		// must never be mistaken for it — hence the line anchor in the pattern.
		check(strpos($source, '# Version: ') !== false,
			'the script carries version history comments the parser must skip');
	}

	private function assertComparison() {
		$this->eq(RelayVersion::CURRENT, RelayVersion::compare('2.3', '2.3'), 'same version reads current');
		$this->eq(RelayVersion::BEHIND,  RelayVersion::compare('2.2', '2.3'), 'older reads behind');
		$this->eq(RelayVersion::AHEAD,   RelayVersion::compare('2.4', '2.3'), 'newer reads ahead');
		$this->eq(RelayVersion::UNKNOWN, RelayVersion::compare('',    '2.3'), 'no answer reads unknown');

		// The off-by-one-decade bug a string comparison ships silently: '2.10' is
		// LESS than '2.9' as text, so a relay would report itself current forever
		// after the tenth minor release.
		$this->eq(RelayVersion::AHEAD, RelayVersion::compare('2.10', '2.9'),
			'2.10 is ahead of 2.9, not behind (version_compare, not strcmp)');
		$this->eq(RelayVersion::BEHIND, RelayVersion::compare('2.9', '2.10'),
			'2.9 is behind 2.10');
		check('2.10' < '2.9', 'string comparison really would get this wrong — hence the guard above');

		// Garbage in reads as unknown, which offers the upgrade: a relay that
		// cannot say what it runs is a relay nobody can vouch for.
		$this->eq(RelayVersion::UNKNOWN, RelayVersion::compare('PONG main', '2.3'),
			'a legacy PONG answer reads unknown');
		// '' is a real empty shipped version, NOT "go look it up" — a deployment
		// that cannot read provision_relay.sh must not report every relay current.
		$this->eq(RelayVersion::UNKNOWN, RelayVersion::compare('2.3', ''),
			'an empty shipped version reads unknown rather than matching anything');
		$this->eq(RelayVersion::CURRENT, RelayVersion::compare(RelayVersion::shipped()),
			'omitting the shipped version looks it up');
	}

	private function assertOffers() {
		check(RelayVersion::offersUpgrade(RelayVersion::BEHIND),  'behind offers the upgrade');
		check(RelayVersion::offersUpgrade(RelayVersion::UNKNOWN), 'unknown offers the upgrade');
		check(!RelayVersion::offersUpgrade(RelayVersion::CURRENT), 'current offers nothing');
		// Replacing newer code with older code is a downgrade wearing an upgrade's
		// label; the honest reading is that the DEPLOYMENT is behind.
		check(!RelayVersion::offersUpgrade(RelayVersion::AHEAD),
			'ahead offers nothing — the site is the thing that is behind');
	}

	// ------------------------------------------------------------- queue depth

	/**
	 * The shard case is a cross-tenant leak, not a cosmetic difference: several
	 * deployments share a shard's Postfix queue, so its depth is a readout of every
	 * other tenant's mail volume. Asserted by running the real relay listener at
	 * both tenant counts, with a collector file that claims a queue.
	 */
	private function assertQueueGate() {
		require_once(__DIR__ . '/lib/relay_ping_probe.php');
		$binary = RelayPingProbe::binary();
		if ($binary === null) {
			harness_skip('queue depth is gated on a fleet-of-one',
				'no prebuilt relay-sealer for this machine and no go toolchain');
			return;
		}
		$probe = new RelayPingProbe($binary);
		check($probe->addTenant('main'), 'tenant main registers');
		// What root's collector would have written: a queue of three.
		$probe->writePrivileged(array(
			'collected_utc' => gmdate('c'),
			'services' => array('rspamd' => array('active' => 'active')),
			'milters' => array('rspamd' => true, 'opendkim' => true, 'opendmarc' => true),
			'contract_ok' => true,
			'postfix' => array('connections_1h' => 0, 'queue_depth' => 3, 'accepted' => 1, 'rejected' => 0, 'deferred' => 0, 'bounced' => 0),
			'tenant_count' => 1,
		));
		if (!$probe->start()) {
			check(false, 'the relay listener starts', (string)@file_get_contents($probe->home . '/serve.log'));
			$probe->stop();
			return;
		}
		try {
			$one = $probe->ping('main');
			check(is_array($one), 'the ping answers JSON with one tenant');
			if (is_array($one)) {
				check(array_key_exists('queue', $one),
					'a fleet-of-one reports its queue depth — an upgrade destroys it, so it must be visible');
				check(is_int($one['queue'] ?? null) || is_numeric($one['queue'] ?? null),
					'the queue depth is a number');
				check(($one['sole'] ?? null) === true, 'a fleet-of-one reports sole = true');
				check(array_key_exists('spool', $one), 'a fleet-of-one reports its spool group');
			}

			check($probe->addTenant('other', 'other.test'), 'a second tenant registers');
			$two = $probe->ping('main');
			check(is_array($two), 'the ping still answers JSON with two tenants');
			if (is_array($two)) {
				check(!array_key_exists('queue', $two),
					'a SHARED shard reports no queue depth — one tenant\'s volume is not another\'s to read');
				check(!array_key_exists('spool', $two), 'a SHARED shard reports no spool group');
				// The wipe guard, asserted against a real second tenant on disk. This
				// is the answer that stops one tenant destroying another's mail.
				check(($two['sole'] ?? null) === false, 'a SHARED shard reports sole = false');
				// The gate must not cost the rest of the answer.
				check(array_key_exists('services', $two) && array_key_exists('milters', $two),
					'withholding the queue leaves the service liveness intact');
			}

			// No registry at all: the tenant's key is gone with it, so the relay
			// cannot even authenticate the asker. "Cannot tell" is not an answer
			// that authorises a wipe — it is no answer, and readHealth reads it as
			// unreachable, which offers nothing.
			$probe->dropRegistry();
			list($code, $raw) = $probe->pingRaw('main');
			check($code === 401, 'a relay with no tenant registry refuses the ping outright', 'code ' . $code);
			$none = MailboxRelay::readHealth($raw, 1);
			check($none['sole'] === null, 'an unanswered ping leaves sole null, never true');
		} finally {
			$probe->stop();
		}
	}

	/**
	 * A relay can serve deployments this one has never heard of. A rebuild wipes
	 * every byte on the machine — their accounts, allowlists, WireGuard peers and
	 * un-pulled mail — and the drain empties only THIS tenant's spool, so nothing
	 * else is preserved even in passing.
	 *
	 * The asking deployment can see only its own tenancy, so the relay answers.
	 * The three states must stay distinct, and NULL must never read as yes.
	 */
	private function assertSoleGuard() {
		$base = '{"status":"ok","services":{"rspamd":"active"},"milters":{"rspamd":true},'
			. '"contract":true,"provisioned":"2.4","slug":"main"';

		$sole   = MailboxRelay::readHealth($base . ',"sole":true}');
		$shared = MailboxRelay::readHealth($base . ',"sole":false}');
		$silent = MailboxRelay::readHealth($base . '}');

		check($sole['sole']   === true,  'a sole-tenant relay says so');
		check($shared['sole'] === false, 'a shared relay says so');
		check($silent['sole'] === null,  'a relay too old to answer reads NULL, not true');
		check($silent['sole'] !== true,  'silence is never consent to a wipe');

		// A legacy PONG relay is the real-world case: it predates the field
		// entirely, and must land on NULL rather than defaulting anywhere.
		$legacy = MailboxRelay::readHealth('PONG main');
		check(array_key_exists('sole', $legacy) && $legacy['sole'] === null,
			'a legacy PONG relay reads NULL for sole');

		// Junk must not become a boolean by accident — "false" as a STRING is
		// truthy in PHP, so a sloppy cast would turn a shared relay into a sole one.
		$junk = MailboxRelay::readHealth($base . ',"sole":"false"}');
		check($junk['sole'] === null, 'a non-boolean sole reads NULL rather than being cast');

		// The refusal has to exist in the drain, before anything is wiped, and it
		// has to key on an explicit false — not on falsiness, which NULL satisfies.
		$source = (string)@file_get_contents(
			PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
		check(preg_match('/handleDraining.*?sole.*?===\s*false.*?\$run->fail\(/s', $source) === 1,
			'the drain refuses a relay that reports itself shared');
		// Asked LIVE: a tenant may have been added since the relay last spoke.
		check(preg_match('/handleDraining.*?pollHealth\(\).*?sole/s', $source) === 1,
			'the guard re-asks the relay rather than trusting the cached answer');

		// And the button must not offer it in the first place.
		$section = (string)@file_get_contents(
			PathHelper::getIncludePath('plugins/mailbox/includes/relay_section.php'));
		check(preg_match('/\$sole\s*===\s*false.*?return/s', $section) === 1,
			'a shared relay is offered no upgrade control at all');
		$admin = (string)@file_get_contents(
			PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));
		check(preg_match('/\$sole\s*===\s*false.*?Cannot update a shared relay/s', $admin) === 1,
			'a hand-posted upgrade of a shared relay is refused server-side');
		check(preg_match('/\$sole\s*===\s*null\s*&&\s*empty\(\$input\[.shared_ack.\]\)/', $admin) === 1,
			'a relay that cannot answer needs an explicit acknowledgement, not a default');
	}

	/**
	 * NULL and 0 must not collapse. A relay whose postqueue is missing knows
	 * nothing about its queue; rendering that as "nothing queued" would tell the
	 * customer an upgrade costs nothing when it might cost every queued message.
	 */
	private function assertAbsentIsNotZero() {
		$base = '{"status":"ok","services":{"rspamd":"active"},"milters":{"rspamd":true},'
			. '"contract":true,"provisioned":"2.3","slug":"main"';

		$absent = MailboxRelay::readHealth($base . '}');
		check(array_key_exists('queue', $absent), 'readHealth always carries a queue key');
		check($absent['queue'] === null, 'an absent queue reads NULL, not 0');

		$zero = MailboxRelay::readHealth($base . ',"queue":0}');
		check($zero['queue'] === 0, 'a reported empty queue reads 0');
		check($zero['queue'] !== $absent['queue'], 'empty and unknown are distinguishable');

		$some = MailboxRelay::readHealth($base . ',"queue":7}');
		$this->eq(7, $some['queue'], 'a reported queue depth survives the parse');

		// Junk must not become a number by accident.
		$junk = MailboxRelay::readHealth($base . ',"queue":"lots"}');
		check($junk['queue'] === null, 'a non-numeric queue reads unknown rather than being cast');

		// The legacy and unreachable shapes carry the key too, so no caller has to
		// guard for its absence before comparing.
		$legacy = MailboxRelay::readHealth('PONG main');
		check(array_key_exists('queue', $legacy) && $legacy['queue'] === null,
			'a legacy PONG answer carries queue = NULL');
	}

	// ------------------------------------------------------- destruction guard

	/**
	 * THE guard. destroyInstanceQuietly() is the single choke point every cleanup
	 * path funnels through, and for an upgrade the instance is the customer's
	 * existing, working relay — deleting it would turn a failed upgrade into a
	 * destroyed mail server.
	 */
	private function assertUpgradeNeverDestroys() {
		$method = new ReflectionMethod('RelayCloudProvisioner', 'destroyInstanceQuietly');

		$deleted = array();
		$driver = new RuFakeDriver();
		$driver->on_delete = function ($id) use (&$deleted) { $deleted[] = $id; };
		RelayCloudProvisioner::$driver_factory = function () use ($driver) { return $driver; };

		try {
			$provisioner = new RelayCloudProvisioner();

			$upgrade = new RelayCloudProvision(NULL);
			$upgrade->set('rcp_kind', 'upgrade');
			$upgrade->set('rcp_instance_id', 'inst-live-relay');
			$method->invoke($provisioner, $upgrade);
			check(count($deleted) === 0,
				'an upgrade never deletes its instance — it is the customer\'s working relay',
				'deleted: ' . implode(',', $deleted));

			// The same call on a PROVISION run must still clean up, or a failed
			// provision would leave the customer paying for a half-built box.
			$provision = new RelayCloudProvision(NULL);
			$provision->set('rcp_kind', 'provision');
			$provision->set('rcp_instance_id', 'inst-half-built');
			$method->invoke($provisioner, $provision);
			$this->eq(array('inst-half-built'), $deleted,
				'a failed provision still cleans up the instance it created');
		} finally {
			RelayCloudProvisioner::$driver_factory = null;
		}

		// The refusal must live at the choke point, not at each call site, or the
		// next cleanup path added will miss it.
		$source = (string)@file_get_contents(
			PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
		check(preg_match('/function destroyInstanceQuietly.*?isUpgrade\(\)/s', $source) === 1,
			'the refusal is inside destroyInstanceQuietly, so every cleanup path inherits it');
	}

	// ------------------------------------------------------------- drain gate

	/**
	 * A held blob is mail the platform deliberately left on the relay because its
	 * owner is not yet resolvable. A wipe destroys it. So held > 0 must FAIL the
	 * run, not warn — and a drain that stops making progress must fail too, rather
	 * than looping or proceeding.
	 */
	private function assertDrainGate() {
		$source = (string)@file_get_contents(
			PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
		if ($source === '') {
			check(false, 'RelayCloudProvisioner is readable');
			return;
		}

		check(preg_match('/function handleDraining/', $source) === 1, 'the draining state exists');
		check(preg_match('/\$held\s*>\s*0.*?\$run->fail\(/s', $source) === 1,
			'a held blob FAILS the run rather than warning — the wipe would destroy it');
		check(preg_match('/\$remaining\s*>=\s*\$last_remaining.*?\$run->fail\(/s', $source) === 1,
			'a drain making no progress fails rather than looping or proceeding');
		check(strpos($source, 'DRAIN_MAX_PASSES') !== false,
			'the drain loop is bounded, so one cron tick cannot be held open indefinitely');

		// The order is the whole design: drain, THEN rebuild. A rebuild reachable
		// before the drain would be a wipe with mail still on the machine.
		$drain_at   = strpos($source, "case 'draining':");
		$rebuild_at = strpos($source, "case 'rebuilding':");
		check($drain_at !== false && $rebuild_at !== false && $drain_at < $rebuild_at,
			'draining is reached before rebuilding');
		check(preg_match('/handleDraining.*?rcp_status.,\s*.rebuilding./s', $source) === 1,
			'only a completed drain advances the run to rebuilding');

		// An upgrade must never take the CREATE path: that would leave the customer
		// paying for a second machine while their relay carried on untouched.
		check(preg_match('/isUpgrade\(\)\s*\?\s*\$this->handleDraining/', $source) === 1,
			'an upgrade in ready goes to the drain, never to instance creation');
		check(preg_match('/case .ready.:.*?isUpgrade\(\).*?nothing cheap to do/s', $source) === 1,
			'advanceCheap refuses an upgrade in ready — handleReady() would CREATE an instance');
	}

	// ------------------------------------------------------------- relay rows

	/**
	 * Two relay rows for one machine would split the alias map and the spool pull
	 * between two identities — the relay would half-work in a way that reads as a
	 * network fault. An upgrade names its relay outright for exactly this reason.
	 */
	private function assertNoSecondRelayRow() {
		$source = (string)@file_get_contents(
			PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
		check(preg_match('/function existingRowFor.*?isUpgrade\(\)\s*\?\s*\$run->relay\(\)/s', $source) === 1,
			'an upgrade resolves its relay from the run, not by scanning instance ids');

		// The run row has to be able to name it.
		check(array_key_exists('rcp_mrl_mailbox_relay_id', RelayCloudProvision::$field_specifications),
			'the run row carries the relay an upgrade targets');
		$kind = RelayCloudProvision::$field_specifications['rcp_kind'];
		check(in_array('upgrade', (array)($kind['allowed_values'] ?? array()), true),
			'the run row allows the upgrade kind');
		check(in_array('provision', (array)($kind['allowed_values'] ?? array()), true),
			'the run row still allows the provision kind');
	}

	/**
	 * A re-imaged machine keeps its address and arrives with a brand-new
	 * identity. The birth writes the new pin on the SAME row, so no stale pin is
	 * trusted; there is no host key anywhere to forget.
	 */
	private function assertHostKeyForgotten() {
		$source = (string)@file_get_contents(
			PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
		check(preg_match('/function registerBornRelay.*?mrl_identity_fingerprint.*?\$fingerprint/s', $source) === 1,
			'the birth writes the reported identity pin on the row');
		check(strpos($source, 'RelaySsh') === false && !file_exists(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php')),
			'nothing in the provisioner speaks ssh, and RelaySsh is gone');
	}

	// ----------------------------------------------------------- shard version

	/**
	 * The operator is not a tenant of their own shards, so no tenant key pings
	 * them. The version arrives in the shard's own birth report and is stamped on
	 * the shard row - and a shard that has not reported in must read as unknown,
	 * never as up to date.
	 */
	private function assertShardMarker() {
		$source = (string)@file_get_contents(
			PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
		check(preg_match('/function completeShardBirth.*?mfs_provisioned_version/s', $source) === 1,
			'a shard birth stamps the reported relay version on the shard row');
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayVersion.php'));
		check(RelayVersion::compare('', RelayVersion::shipped()) === RelayVersion::UNKNOWN,
			'a shard that has not reported reads as unknown, never current');
	}

	// ----------------------------------------------------------------- helpers

	private function rmTree(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (array_diff(scandir($dir) ?: array(), array('.', '..')) as $entry) {
			$path = $dir . '/' . $entry;
			is_dir($path) ? $this->rmTree($path) : @unlink($path);
		}
		@rmdir($dir);
	}

	private function eq($expected, $actual, string $label) {
		check($expected === $actual, $label,
			'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

/** Records deletes instead of performing them. */
class RuFakeDriver implements CloudComputeProvider {
	/** @var callable|null */
	public $on_delete = null;

	public function createInstance(array $opts): array { throw new CloudComputeException('not used'); }
	public function getInstance(string $instance_id): array { throw new CloudComputeException('not used'); }
	public function rebuildInstance(string $instance_id, array $opts): array {
		return array('id' => $instance_id, 'status' => 'rebuilding', 'ip' => '198.51.100.7', 'label' => 'x');
	}
	public function deleteInstance(string $instance_id): void {
		if ($this->on_delete !== null) {
			call_user_func($this->on_delete, $instance_id);
		}
	}
	public function setReverseDns(string $instance_id, string $ip, string $hostname): array {
		return array('ip' => $ip, 'rdns' => $hostname);
	}
}

(new RelayUpgradeTest())->run();
harness_finish();
?>
