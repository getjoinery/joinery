<?php
/** @joinery-test
 * name: relay_fleet
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Tests for the shared relay fleet's tenant-side and operator-side pieces
 * (specs/mailbox_relay_shared_fleet.md):
 *
 *   - MailboxRelay tenant identity (tenantSlug, the identity pin) — what every
 *     signed request names and every consumer reaches the relay with.
 *   - RelayMapExporter emits a valid tenancy-native fragment (fragment_format
 *     1, tenant slug, deterministic version 0).
 *   - FleetService coordinates carry the shard's identity pin and address; no tunnel;
 *     slot counting sees only live
 *     slot's address; domain-claim uniqueness is fleet-wide.
 *   - The fleet sits on the core enrolment skeleton: FleetClient is a
 *     ServiceClient, the slot's states are the ServiceTenantLadder's, and the
 *     reconcile's entitlement ladder persists through a real slot row.
 *
 * Creates scratch shard/slot/claim/relay rows and deletes them. Skips the
 * fleet sections when the fleet tables have not been created yet (run
 * update_database).
 *
 * Run: php tests/run.php db --filter=relay_fleet
 *
 * @version 1.4 - the fleet on the core skeleton: client, state vocabulary, the ladder on a slot row
 * @version 1.3 - fleet_status exercised through the action
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));

class RelayFleetTest {

	private $db;
	private $cleanup = array(); // [table, pkey_col, id]

	function run() {
		$this->db = DbConnector::get_instance()->get_db_link();
		try {
			$this->testRelayTenantHelpers();
			$this->testExporterFragment();
			$this->testFleetAllocationAndClaims();
			$this->testFleetOnTheSkeleton();
		} catch (\Throwable $e) {
			check(false, 'uncaught ' . get_class($e), $e->getMessage());
		} finally {
			$this->cleanupRows();
		}
	}

	private function tableExists(string $table): bool {
		$stmt = $this->db->prepare("SELECT to_regclass(?)");
		$stmt->execute(array('public.' . $table));
		return $stmt->fetchColumn() !== null;
	}

	private function columnExists(string $table, string $column): bool {
		$stmt = $this->db->prepare(
			"SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
		$stmt->execute(array($table, $column));
		return (bool)$stmt->fetchColumn();
	}

	// The identity every consumer derives from: slug → account, spool, drop area.
	private function testRelayTenantHelpers() {
		section('tenant identity helpers');
		$relay = new MailboxRelay(NULL);
		check($relay->tenantSlug() === 'main', 'default slug is main');
		$relay->set('mrl_tenant_slug', 't42');
		check($relay->tenantSlug() === 't42', 'the slug is what every signed request names');
		$relay->set('mrl_tenant_slug', 'Bad Slug!');
		check($relay->tenantSlug() === 'main', 'invalid slug falls back to main');
		// The relay is reached only through its API: no pin, no reach.
		check(!$relay->usesRelayApi(), 'a row without an identity pin is unreachable');
		$relay->set('mrl_identity_fingerprint', base64_encode(str_repeat("\x01", 32)));
		check($relay->usesRelayApi(), 'a row with an identity pin speaks the relay API');
	}

	// The pushed artifact is one JSON fragment the shard-side merge validates.
	private function testExporterFragment() {
		section('exporter fragment');
		if (!$this->columnExists('mrl_mailbox_relays', 'mrl_tenant_slug')) {
			harness_skip('mrl tenant columns not created yet — run update_database');
			return;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapExporter.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapSync.php'));

		$relay = new MailboxRelay(NULL);
		$relay->set('mrl_name', 'relay_fleet_test scratch');
		$relay->set('mrl_tenant_slug', 'main');
		$relay->set('mrl_is_enabled', false);
		$relay->save();
		$this->cleanup[] = array('mrl_mailbox_relays', 'mrl_mailbox_relay_id', intval($relay->key));

		$artifacts = (new RelayMapExporter($relay))->build();
		check(isset($artifacts['fragment']), 'build() returns the fragment artifact');
		$fragment = json_decode((string)$artifacts['fragment'], true);
		check(is_array($fragment), 'fragment is valid JSON');
		check(($fragment['fragment_format'] ?? 0) === 1, 'fragment_format is 1');
		check(($fragment['tenant'] ?? '') === 'main', 'fragment names its tenant');
		check(($fragment['version'] ?? -1) === 0, 'built fragment is deterministic (version 0 — RelayMapSync stamps the real one)');
		check(is_array($fragment['recipients'] ?? null) || ($fragment['recipients'] ?? null) === array(),
			'fragment carries recipients');
		check(array_key_exists('transport_public_key', $fragment), 'fragment carries the transport key');

		$rebuilt = (new RelayMapExporter($relay))->build();
		check(RelayMapSync::contentHash($artifacts) === RelayMapSync::contentHash($rebuilt),
			'unchanged routing state hashes identically (push-skip contract)');

		// The merge's typed unmarshal needs empty maps as JSON OBJECTS — a
		// domainless deployment's first push ships exactly that shape.
		$body = RelayMapSync::encodeFragmentBody(array(
			'tenant' => 'main', 'recipients' => array(), 'domains' => array(), 'forwarding_domains' => array(),
		));
		check(strpos($body, '"recipients": {}') !== false && strpos($body, '"domains": {}') !== false,
			'empty recipients/domains encode as objects for the Go merge');
		check(strpos($body, '"forwarding_domains": []') !== false,
			'list-typed fields stay arrays');
	}

	// The operator-side brain: allocation and claim uniqueness.
	private function testFleetAllocationAndClaims() {
		section('fleet allocation + claims');
		if (!$this->tableExists('mfs_mailbox_fleet_shards') || !$this->tableExists('mft_mailbox_fleet_slots')
			|| !$this->tableExists('mfd_mailbox_fleet_domain_claims')) {
			harness_skip('fleet tables not created yet — run update_database');
			return;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

		$shard = new MailboxFleetShard(NULL);
		$shard->set('mfs_name', 'relay_fleet_test shard');
		$shard->set('mfs_capacity', 3);
		$shard->save();
		$this->cleanup[] = array('mfs_mailbox_fleet_shards', 'mfs_mailbox_fleet_shard_id', intval($shard->key));

		$shard->set('mfs_identity_fingerprint', base64_encode(str_repeat("\x02", 32)));
		$shard->set('mfs_public_ip', '203.0.113.9');
		$shard->save();

		$slot_a = new MailboxFleetSlot(NULL);
		$slot_a->set('mft_mfs_mailbox_fleet_shard_id', intval($shard->key));
		$slot_a->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
		$slot_a->set('mft_public_key', base64_encode(str_repeat("\x03", 32)));
		$slot_a->save();
		$slot_a->set('mft_slug', 't' . intval($slot_a->key));
		$slot_a->set('mft_mx_hostname', 't' . intval($slot_a->key) . '.mx.fleet-test.example');
		$slot_a->save();
		$this->cleanup[] = array('mft_mailbox_fleet_slots', 'mft_mailbox_fleet_slot_id', intval($slot_a->key));

		// The coordinates a tenant folds into its MailboxRelay row: the slot's MX
		// hostname (id-derived — DNS names no tenant), and the shard's identity
		// pin and address, which are everything RelayClient needs.
		$coords = FleetService::coordinates($slot_a);
		$slug = 't' . intval($slot_a->key);
		check($coords['slug'] === $slug, 'coordinates carry the id-derived slug');
		check($coords['mx_hostname'] === $slug . '.mx.fleet-test.example', 'coordinates carry the slot MX hostname');
		check($coords['spool_path'] === '/var/spool/joinery-relay/' . $slug, 'spool path derives from the slug');
		check($coords['identity_fingerprint'] === (string)$shard->get('mfs_identity_fingerprint'), 'coordinates carry the shard identity pin');
		check($coords['shard_public_ip'] === '203.0.113.9', 'coordinates carry the shard address');
		check(!isset($coords['ssh_user']) && !isset($coords['tunnel_ip']) && !isset($coords['wg_public_key']),
			'no tunnel or ssh coordinate survives');
		check($shard->slotCount() === 1, 'slot count sees the live slot');
		check($shard->hasCapacity(), 'shard under capacity');

		$slot_b = new MailboxFleetSlot(NULL);
		$slot_b->set('mft_mfs_mailbox_fleet_shard_id', intval($shard->key));
		$slot_b->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
		$slot_b->set('mft_public_key', base64_encode(str_repeat("\x04", 32)));
		$slot_b->save();
		$this->cleanup[] = array('mft_mailbox_fleet_slots', 'mft_mailbox_fleet_slot_id', intval($slot_b->key));

		// Claim uniqueness is FLEET-WIDE: slot B cannot claim what slot A holds.
		$claim = new MailboxFleetDomainClaim(NULL);
		$claim->set('mfd_mft_mailbox_fleet_slot_id', intval($slot_a->key));
		$claim->set('mfd_domain', 'relay-fleet-test.example');
		$claim->set('mfd_txt_token', 'joinery-fleet-verify-test');
		$claim->set('mfd_status', MailboxFleetDomainClaim::STATUS_VERIFIED);
		$claim->save();
		$this->cleanup[] = array('mfd_mailbox_fleet_domain_claims', 'mfd_mailbox_fleet_domain_claim_id', intval($claim->key));

		$other = MailboxFleetDomainClaim::liveClaimByOtherSlot('relay-fleet-test.example', intval($slot_b->key));
		check($other !== null && intval($other->get('mfd_mft_mailbox_fleet_slot_id')) === intval($slot_a->key),
			'another slot\'s live claim blocks the domain fleet-wide');
		check(MailboxFleetDomainClaim::liveClaimByOtherSlot('relay-fleet-test.example', intval($slot_a->key)) === null,
			'the owning slot is not blocked by its own claim');
		check($claim->challengeHost() === '_joinery-fleet-challenge.relay-fleet-test.example',
			'TXT challenge host shape');

		check($slot_a->verifiedDomains() === array('relay-fleet-test.example'),
			'verifiedDomains lists the verified claim');

		// Revoked/evicted rows stop blocking.
		$claim->set('mfd_status', MailboxFleetDomainClaim::STATUS_REVOKED);
		$claim->save();
		check(MailboxFleetDomainClaim::liveClaimByOtherSlot('relay-fleet-test.example', intval($slot_b->key)) === null,
			'a revoked claim frees the domain');

		// Release is the exit ramp: the slot goes released AND its live claims
		// are revoked immediately — the domains' next home must be able to
		// claim them before this slot finishes evicting.
		$claim2 = new MailboxFleetDomainClaim(NULL);
		$claim2->set('mfd_mft_mailbox_fleet_slot_id', intval($slot_a->key));
		$claim2->set('mfd_domain', 'relay-fleet-release.example');
		$claim2->set('mfd_txt_token', 'joinery-fleet-verify-test2');
		$claim2->set('mfd_status', MailboxFleetDomainClaim::STATUS_VERIFIED);
		$claim2->save();
		$this->cleanup[] = array('mfd_mailbox_fleet_domain_claims', 'mfd_mailbox_fleet_domain_claim_id', intval($claim2->key));

		FleetService::releaseSlot($slot_a);
		check((string)$slot_a->get('mft_status') === MailboxFleetSlot::STATUS_RELEASED,
			'releaseSlot marks the slot released');
		$claim2_reload = new MailboxFleetDomainClaim(intval($claim2->key), TRUE);
		check((string)$claim2_reload->get('mfd_status') === MailboxFleetDomainClaim::STATUS_REVOKED,
			'releaseSlot revokes the slot\'s live claims');
		check(MailboxFleetDomainClaim::liveClaimByOtherSlot('relay-fleet-release.example', intval($slot_b->key)) === null,
			'a released slot\'s domains are claimable elsewhere');

		// The status action a tenant polls: it renders the slot as the reconcile
		// task left it. Exercised through the action so a call into a method
		// FleetService no longer has fails here, not on a tenant's relay page.
		require_once(PathHelper::getIncludePath('tests/lib/logic.php'));
		$tenant = make_user('fleet_status');
		$slot_b->set('mft_usr_user_id', intval($tenant->key));
		$slot_b->set('mft_slug', 't' . intval($slot_b->key));
		$slot_b->set('mft_mx_hostname', 't' . intval($slot_b->key) . '.mx.fleet-test.example');
		$slot_b->save();
		$saved_session = $_SESSION ?? array();
		$_SESSION = array('loggedin' => 1, 'usr_user_id' => intval($tenant->key), 'permission' => 0);
		try {
			$status = harness_call_logic('plugins/mailbox/logic/fleet_status_logic.php', 'fleet_status_logic', array());
			check(!$status->error && !empty($status->data['enrolled']), 'fleet_status renders an enrolled slot');
			check((string)($status->data['coordinates']['status'] ?? '') === MailboxFleetSlot::STATUS_ACTIVE,
				'fleet_status carries the slot status the reconcile task wrote');
			check(($status->data['coordinates']['slug'] ?? '') === 't' . intval($slot_b->key),
				'fleet_status carries the caller\'s own slot');
		} finally {
			$_SESSION = $saved_session;
		}
	}

	// The fleet on the core skeleton (specs/services_phase2_platform.md §10
	// item 1): the pieces the relay reconcile and the tenant client now share
	// with every other rented service.
	private function testFleetOnTheSkeleton() {
		section('fleet on the skeleton');
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
		check(is_subclass_of('FleetClient', 'ServiceClient'), 'FleetClient is a ServiceClient');
		check(MailboxFleetSlot::STATUS_ACTIVE === ServiceTenantLadder::STATE_ACTIVE
			&& MailboxFleetSlot::STATUS_SUSPENDED === ServiceTenantLadder::STATE_SUSPENDED
			&& MailboxFleetSlot::STATUS_RELEASED === ServiceTenantLadder::STATE_RELEASED
			&& MailboxFleetSlot::STATUS_PROVISIONING === ServiceTenantLadder::STATE_PROVISIONING,
			'the slot\'s four shared states are the ladder\'s vocabulary');

		if (!$this->tableExists('mfs_mailbox_fleet_shards') || !$this->tableExists('mft_mailbox_fleet_slots')) {
			harness_skip('fleet tables not created yet — run update_database');
			return;
		}
		$shard = new MailboxFleetShard(NULL);
		$shard->set('mfs_name', 'relay_fleet_test ladder shard');
		$shard->set('mfs_capacity', 1);
		$shard->save();
		$this->cleanup[] = array('mfs_mailbox_fleet_shards', 'mfs_mailbox_fleet_shard_id', intval($shard->key));
		$slot = new MailboxFleetSlot(NULL);
		$slot->set('mft_mfs_mailbox_fleet_shard_id', intval($shard->key));
		$slot->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
		$slot->set('mft_public_key', base64_encode(str_repeat("\x05", 32)));
		$slot->save();
		$this->cleanup[] = array('mft_mailbox_fleet_slots', 'mft_mailbox_fleet_slot_id', intval($slot->key));

		// The same column map and the same closures the reconcile hands the
		// ladder, with the shard act stubbed: what is pinned here is that the
		// rungs land in the slot's own columns.
		$columns = array('state' => 'mft_status', 'lapse_time' => 'mft_entitlement_lapse_time',
			'check_time' => 'mft_entitlement_check_time');
		$acts = array();
		$resync = function (MailboxFleetSlot $row) use (&$acts) {
			$row->set('mft_needs_domain_sync', true);
			$row->save();
			$acts[] = (string)$row->get('mft_status');
		};
		ServiceTenantLadder::advance($slot, $columns, false, 14, $resync, $resync, '2026-09-01 00:00:00');
		$fresh = new MailboxFleetSlot(intval($slot->key), TRUE);
		check(substr((string)$fresh->get('mft_entitlement_lapse_time'), 0, 19) === '2026-09-01 00:00:00',
			'the lapse lands in mft_entitlement_lapse_time');
		$step = ServiceTenantLadder::advance($fresh, $columns, false, 14, $resync, $resync, '2026-09-16 00:00:00');
		$fresh = new MailboxFleetSlot(intval($slot->key), TRUE);
		check($step === ServiceTenantLadder::STEP_SUSPENDED && (string)$fresh->get('mft_status') === MailboxFleetSlot::STATUS_SUSPENDED,
			'past the grace window the slot row is suspended');
		check((bool)$fresh->get('mft_needs_domain_sync') && $acts === array('suspended'),
			'the suspend act flags the allowlist re-sync on the saved row');
		$step = ServiceTenantLadder::advance($fresh, $columns, true, 14, $resync, $resync, '2026-09-20 00:00:00');
		$fresh = new MailboxFleetSlot(intval($slot->key), TRUE);
		check($step === ServiceTenantLadder::STEP_REACTIVATED && (string)$fresh->get('mft_status') === MailboxFleetSlot::STATUS_ACTIVE
			&& $fresh->get('mft_entitlement_lapse_time') === null
			&& substr((string)$fresh->get('mft_entitlement_check_time'), 0, 19) === '2026-09-20 00:00:00',
			'entitlement returning reactivates the slot in place');
		check($acts === array('suspended', 'active'), 'the reactivate act ran once more');
	}

	private function cleanupRows() {
		foreach (array_reverse($this->cleanup) as $row) {
			list($table, $col, $id) = $row;
			try {
				$this->db->exec("DELETE FROM {$table} WHERE {$col} = " . intval($id));
			} catch (\Throwable $e) {
				echo "cleanup failed for {$table} #{$id}: " . $e->getMessage() . "\n";
			}
		}
	}
}

$test = new RelayFleetTest();
$test->run();
harness_finish();
