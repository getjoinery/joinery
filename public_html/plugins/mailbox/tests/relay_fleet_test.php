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
 *   - MailboxRelay tenant identity helpers (tenantSlug/pullUser/spoolPath/
 *     fragmentDir) — the coordinates every relay consumer derives from.
 *   - RelayMapExporter emits a valid tenancy-native fragment (fragment_format
 *     1, tenant slug, deterministic version 0).
 *   - FleetService tunnel allocation skips the relay's .1 and every live
 *     slot's address; domain-claim uniqueness is fleet-wide.
 *
 * Creates scratch shard/slot/claim/relay rows and deletes them. Skips the
 * fleet sections when the fleet tables have not been created yet (run
 * update_database).
 *
 * Run: php tests/run.php db --filter=relay_fleet
 *
 * @version 1.2
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

class RelayFleetTest {

	private $db;
	private $cleanup = array(); // [table, pkey_col, id]

	function run() {
		$this->db = DbConnector::get_instance()->get_db_link();
		try {
			$this->testRelayTenantHelpers();
			$this->testExporterFragment();
			$this->testFleetAllocationAndClaims();
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
		check($relay->pullUser() === 'jt-main', 'pull account derives jt-main');
		check($relay->spoolPath() === '/var/spool/joinery-relay/main', 'spool path derives from slug');
		check($relay->fragmentDir() === '/opt/joinery-relay/home/main/fragments', 'fragment dir derives from slug');

		$relay->set('mrl_tenant_slug', 't42');
		check($relay->pullUser() === 'jt-t42', 'slug drives the pull account');
		check($relay->spoolPath() === '/var/spool/joinery-relay/t42', 'slug drives the spool path');

		$relay->set('mrl_ssh_user', 'custom-user');
		$relay->set('mrl_spool_path', '/srv/spool/x/');
		check($relay->pullUser() === 'custom-user', 'explicit ssh user wins');
		check($relay->spoolPath() === '/srv/spool/x', 'explicit spool path wins (trailing slash trimmed)');

		$relay->set('mrl_tenant_slug', 'Bad Slug!');
		check($relay->tenantSlug() === 'main', 'invalid slug falls back to main');
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

		check(FleetService::allocateTunnelIp($shard) === '10.99.0.2',
			'first tenant allocation is .2 (the self-hosted fixed address)');

		$slot_a = new MailboxFleetSlot(NULL);
		$slot_a->set('mft_mfs_shard_id', intval($shard->key));
		$slot_a->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
		$slot_a->set('mft_tunnel_ip', '10.99.0.2');
		$slot_a->save();
		$slot_a->set('mft_slug', 't' . intval($slot_a->key));
		$slot_a->set('mft_mx_hostname', 't' . intval($slot_a->key) . '.mx.fleet-test.example');
		$slot_a->save();
		$this->cleanup[] = array('mft_mailbox_fleet_slots', 'mft_mailbox_fleet_slot_id', intval($slot_a->key));

		// The coordinates a tenant folds into its MailboxRelay row: slug-derived
		// pull account and spool, the slot's MX hostname (id-derived — DNS names
		// no tenant), and the shard's tunnel identity.
		$coords = FleetService::coordinates($slot_a);
		$slug = 't' . intval($slot_a->key);
		check($coords['slug'] === $slug, 'coordinates carry the id-derived slug');
		check($coords['mx_hostname'] === $slug . '.mx.fleet-test.example', 'coordinates carry the slot MX hostname');
		check($coords['ssh_user'] === 'jt-' . $slug, 'pull account derives from the slug');
		check($coords['spool_path'] === '/var/spool/joinery-relay/' . $slug, 'spool path derives from the slug');
		check($coords['tunnel_ip'] === '10.99.0.2', 'coordinates carry the allocated tunnel address');
		check($coords['relay_tunnel_ip'] === '10.99.0.1', 'the shard relay listens at .1');

		check(FleetService::allocateTunnelIp($shard) === '10.99.0.3',
			'allocation skips the live slot\'s address');
		check($shard->slotCount() === 1, 'slot count sees the live slot');
		check($shard->hasCapacity(), 'shard under capacity');

		$slot_b = new MailboxFleetSlot(NULL);
		$slot_b->set('mft_mfs_shard_id', intval($shard->key));
		$slot_b->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
		$slot_b->set('mft_tunnel_ip', '10.99.0.3');
		$slot_b->save();
		$this->cleanup[] = array('mft_mailbox_fleet_slots', 'mft_mailbox_fleet_slot_id', intval($slot_b->key));

		// Claim uniqueness is FLEET-WIDE: slot B cannot claim what slot A holds.
		$claim = new MailboxFleetDomainClaim(NULL);
		$claim->set('mfd_mft_slot_id', intval($slot_a->key));
		$claim->set('mfd_domain', 'relay-fleet-test.example');
		$claim->set('mfd_txt_token', 'joinery-fleet-verify-test');
		$claim->set('mfd_status', MailboxFleetDomainClaim::STATUS_VERIFIED);
		$claim->save();
		$this->cleanup[] = array('mfd_mailbox_fleet_domain_claims', 'mfd_mailbox_fleet_domain_claim_id', intval($claim->key));

		$other = MailboxFleetDomainClaim::liveClaimByOtherSlot('relay-fleet-test.example', intval($slot_b->key));
		check($other !== null && intval($other->get('mfd_mft_slot_id')) === intval($slot_a->key),
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
		$claim2->set('mfd_mft_slot_id', intval($slot_a->key));
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
