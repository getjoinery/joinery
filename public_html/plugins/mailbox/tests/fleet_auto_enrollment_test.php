<?php
/** @joinery-test
 * name: fleet_auto_enrollment
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Order-time fleet auto-enrollment (specs/mailbox_relay_shared_fleet.md
 * § Follow-up): the seeding gate (FleetProvisionSeeding::applies), the buyer
 * credential mint, the remote seeding command (secret stays out of it), and
 * the operator console's one-click Relay Hosting product creation.
 *
 * Creates scratch tier/group/member, api-key, and product rows; all deleted
 * LIFO. No mailbox tables are touched.
 *
 * Run: php tests/run.php db --filter=fleet_auto_enrollment
 *
 * @version 1.4 - a site key never reaches the tier slot; a record in the way is left for the buyer; a removed site's slot is released
 * @version 1.3 - a Starter site's inbound records: listed for the buyer on their own name, published on ours
 * @version 1.2 - applies() and seedNode() take the provision; a buyer's two Starter sites each get a key and a
 *                slot of their own, and losing one site's hosting leaves the other's (hosting_launch S5)
 * @version 1.1 - the created product is Relay Hosting
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetProvisionSeeding.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_fleet_logic.php'));   // the fleet console's functions
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

$db = DbConnector::get_instance()->get_db_link();

// ── Fixtures ────────────────────────────────────────────────────────────────
$buyer = make_user('FleetBuyer');

// A scratch tier whose features grant the fleet slot. Its auto-created group
// and the membership row are registered for cleanup alongside it.
$tier = new SubscriptionTier(NULL);
$tier->set('sbt_name', 'harnesstest_fleet_' . substr(md5(uniqid('', true)), 0, 6));
$tier->set('sbt_display_name', 'Harness Fleet Tier');
$tier->set('sbt_tier_level', 9000);
$tier->setFeatures(array('mailbox_fleet_slot' => true, 'mailbox_fleet_max_domains' => 3));
$tier->save();
$tier->load();
harness_register_row('sbt_subscription_tiers', 'sbt_subscription_tier_id', $tier->key);
harness_register_row('grp_groups', 'grp_group_id', (int)$tier->get('sbt_grp_group_id'));
harness_defer(function () use ($db, $tier) {
	$q = $db->prepare("DELETE FROM grm_group_members WHERE grm_grp_group_id = ?");
	$q->execute(array((int)$tier->get('sbt_grp_group_id')));
});
harness_defer(function () use ($buyer) {
	SubscriptionTier::clearUserCache($buyer->key);
});

// ── A. The seeding gate ─────────────────────────────────────────────────────
section('applies() gating');

harness_set_setting_mem('mailbox_fleet_service_enabled', '1');
harness_set_setting_mem('server_manager_getjoinery_api_url', '');

require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
// A site on a server of its own, which its buyer's tier (not its plan) gives a slot.
$tier_site_of = function (int $user_id) {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'admin');
	$p->set('cvp_usr_user_id', $user_id);
	$p->set('cvp_domain', 'harnesstest-fleet-tier-site.example.com');
	return $p;
};
$tier_site = $tier_site_of((int)$buyer->key);

check(FleetProvisionSeeding::applies($tier_site) === false,
	'no entitlement -> does not apply');

$tier->addUser($buyer->key);
SubscriptionTier::clearUserCache($buyer->key);

check(FleetProvisionSeeding::applies($tier_site) === true,
	'fleet on + entitled buyer + local store -> applies');

harness_set_setting_mem('mailbox_fleet_service_enabled', '0');
check(FleetProvisionSeeding::applies($tier_site) === false,
	'fleet service off -> does not apply');
harness_set_setting_mem('mailbox_fleet_service_enabled', '1');

harness_set_setting_mem('server_manager_getjoinery_api_url', 'https://some-other-store.example.com');
check(FleetProvisionSeeding::applies($tier_site) === false,
	'remote store -> does not apply (buyer id is not a local user there)');
harness_set_setting_mem('server_manager_getjoinery_api_url', '');

check(FleetProvisionSeeding::applies($tier_site_of(0)) === false, 'user id 0 -> does not apply');

// ── B. The buyer credential ─────────────────────────────────────────────────
section('mintTenantKey');

$first = FleetProvisionSeeding::mintTenantKey($buyer->key);
check(strpos($first['public_key'], 'public_') === 0, 'public key has the public_ prefix');
check(strpos($first['secret_key'], 'secret_') === 0, 'secret has the secret_ prefix');
check((bool)preg_match('/^[a-z0-9_]+$/', $first['secret_key']),
	'secret charset is the quote-free set the remote heredoc assumes');

$second = FleetProvisionSeeding::mintTenantKey($buyer->key);
$keys = new MultiApiKey(array('user_id' => $buyer->key));
$keys->load();
$active = array();
$total = 0;
foreach ($keys as $key_row) {
	if ($key_row->get('apk_name') === FleetProvisionSeeding::KEY_NAME) {
		$total++;
		harness_register_key_id($key_row->key);
		if ($key_row->get('apk_is_active')) {
			$active[] = $key_row;
		}
	}
}
check($total === 2, 'two mints -> two key rows', "got {$total}");
check(count($active) === 1, 're-mint deactivates the previous key (exactly one active)');
if (count($active) === 1) {
	check($active[0]->get('apk_public_key') === $second['public_key'],
		'the active key is the latest mint');
	check((int)$active[0]->get('apk_permission') === FleetProvisionSeeding::KEY_PERMISSION,
		'key permission is read+write, no delete');
	check($active[0]->get('apk_type') === ApiKey::TYPE_MACHINE, 'key is a machine key');
}

// ── C. Seeding travels as ONE fleet_enroll job on the node's own agent ──────
section('seedNode dispatches a fleet_enroll primitive');

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));

// An unpaired node has no route, and no key is minted for it: the provision
// re-asks every tick until the agent pairs, and a key per tick would churn.
$unpaired = new ManagedNode(NULL);
$unpaired->set('mgn_name', 'HarnessTest fleet unpaired');
$unpaired->set('mgn_slug', 'harnesstest-fleet-unpaired-' . substr(md5(uniqid('', true)), 0, 6));
$unpaired->set('mgn_host', '192.0.2.40');
$unpaired->set('mgn_uptime_enabled', false);
$unpaired->save();
$unpaired->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $unpaired->key);
check(FleetProvisionSeeding::nodeReady($unpaired) === false, 'an unpaired node is not ready to seed');
$before = 0;
foreach (new MultiApiKey(array('user_id' => $buyer->key)) as $k) { $before++; }
$res = FleetProvisionSeeding::seedNode($unpaired, $tier_site);
check($res['ok'] === false && strpos($res['message'], 'fleet_enroll') !== false && strpos($res['message'], 'SSH') !== false,
	'seeding an unpaired node fails loudly, naming the primitive and that there is no SSH route');
$after = 0;
foreach (new MultiApiKey(array('user_id' => $buyer->key)) as $k) { $after++; }
check($after === $before, 'and mints no key for it');

// A paired node reporting fleet_enroll gets one job carrying the three values.
$paired = new ManagedNode(NULL);
$paired->set('mgn_name', 'HarnessTest fleet paired');
$paired->set('mgn_slug', 'harnesstest-fleet-paired-' . substr(md5(uniqid('', true)), 0, 6));
$paired->set('mgn_host', '192.0.2.41');
$paired->set('mgn_uptime_enabled', false);
$paired->set('mgn_agent_public_key', base64_encode(str_repeat("\x0f", 32)));
$paired->set('mgn_agent_version', AgentVocabulary::FLOOR);
$paired->set('mgn_agent_primitives', 'check_status,fleet_enroll');
$paired->save();
$paired->load();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $paired->key);
$res = FleetProvisionSeeding::seedNode($paired, $tier_site);
check($res['ok'] === true && !empty($res['job_id']), 'a paired node is seeded by a job', $res['message']);
$job = new ManagementJob((int)$res['job_id'], TRUE);
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
$env = json_decode((string)$job->get('mjb_commands'), true);
check(($env['primitive'] ?? '') === 'fleet_enroll', 'the job is the fleet_enroll primitive');
check(isset($env['params']['service_url'], $env['params']['public_key'], $env['params']['secret_key'])
	&& count($env['params']) === 3,
	'it carries the service URL and the key pair, and nothing else', json_encode($env['params'] ?? null));
check(strpos($env['params']['service_url'], 'https://') === 0, 'the service URL is this deployment, over https');
check(strpos((string)json_encode($env), 'mailbox_fleet_') === false,
	'no setting name is on the wire — they are compiled into utils/fleet_enroll.php');
$keys = new MultiApiKey(array('user_id' => $buyer->key));
$keys->load();
$minted_matches = false;
foreach ($keys as $key_row) {
	harness_register_key_id($key_row->key);
	if ($key_row->get('apk_public_key') === $env['params']['public_key'] && $key_row->get('apk_is_active')) {
		$minted_matches = true;
	}
}
check($minted_matches, 'the public key on the job is the buyer\'s newly minted active key');
check(FleetProvisionSeeding::outcome($paired)['state'] === 'pending', 'before the node answers, the outcome is pending');

// One slot per subscription: a buyer whose seeding is already done on ANOTHER
// site is refused, naming that site, and no key is minted — minting would have
// revoked the credential the first site enrolled with. The same site again is
// a rotation and goes through.
section('One seeded site per buyer');
$holder = new CustomerCloudProvision(NULL);
$holder->set('cvp_origin', 'admin');
$holder->set('cvp_usr_user_id', $buyer->key);
$holder->set('cvp_domain', 'harnesstest-fleet-holder.example.com');
$holder->set('cvp_slug', 'harnesstest-fleet-holder-' . substr(md5(uniqid('', true)), 0, 6));
$holder->set('cvp_status', 'done');
$holder->set('cvp_install_mode', 'fresh');
$holder->set('cvp_docker_mode', 'docker');
$holder->set('cvp_mgn_managed_node_id', $unpaired->key);
$holder->set('cvp_fleet_seed_state', 'done');
$holder->save();
$holder->load();
harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $holder->key);
check(FleetProvisionSeeding::seededElsewhere($paired, $buyer->key) === 'harnesstest-fleet-holder.example.com',
	'the slot holder is found by the buyer, on a node other than the one being seeded');
check(FleetProvisionSeeding::seededElsewhere($unpaired, $buyer->key) === null,
	'seen from the holder itself there is no other holder — re-seeding it is a rotation');
$keys_before = 0;
foreach (new MultiApiKey(array('user_id' => $buyer->key)) as $k) { $keys_before++; }
$res = FleetProvisionSeeding::seedNode($paired, $tier_site);
check($res['ok'] === false && strpos($res['message'], 'harnesstest-fleet-holder.example.com') !== false
	&& strpos($res['message'], 'Not seeded') !== false,
	'seeding a second site is refused, naming the site that holds the slot', $res['message']);
$keys_after = 0;
foreach (new MultiApiKey(array('user_id' => $buyer->key)) as $k) { $keys_after++; }
check($keys_after === $keys_before, 'and no key was minted, so the holder\'s credential stands');

// A decommissioned site holds nothing: its node row is soft-deleted, so the
// slot is free for the account's next site (testing day 2026-09-08, B16).
$unpaired->set('mgn_delete_time', gmdate('Y-m-d H:i:s'));
$unpaired->save();
check(FleetProvisionSeeding::seededElsewhere($paired, $buyer->key) === null,
	'a holder whose site node is soft-deleted (decommissioned) no longer holds the slot');
$unpaired->set('mgn_delete_time', null);
$unpaired->save();
check(FleetProvisionSeeding::seededElsewhere($paired, $buyer->key) === 'harnesstest-fleet-holder.example.com',
	'and holds it again once its node is live');

// ── D. A Starter site's plan grants it a slot of its own (hosting_launch S5) ──
section('Each Starter site has its own key and slot');
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trials_class.php'));

// A buyer with no slot tier at all: the plan alone grants the slots.
$starter_buyer = make_user('FleetStarterBuyer');
$mk_site_node = function (string $tag) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest fleet ' . $tag);
	$n->set('mgn_slug', 'harnesstest-fleet-' . $tag . '-' . substr(md5(uniqid('', true)), 0, 6));
	$n->set('mgn_host', '192.0.2.42');
	$n->set('mgn_uptime_enabled', false);
	$n->set('mgn_agent_public_key', base64_encode(random_bytes(32)));
	$n->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$n->set('mgn_agent_primitives', 'check_status,fleet_enroll');
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$mk_starter_site = function (string $tag, $node) use ($starter_buyer) {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'order');
	$p->set('cvp_usr_user_id', $starter_buyer->key);
	$p->set('cvp_domain', 'harnesstest-fleet-' . $tag . '.example.com');
	$p->set('cvp_slug', 'harnesstest-fleet-' . $tag . '-' . substr(md5(uniqid('', true)), 0, 6));
	$p->set('cvp_status', 'done');
	$p->set('cvp_landing', 'container');
	$p->set('cvp_hosting_mode', 'operator');
	// An order's row names its order item; no row here reads it.
	$p->set('cvp_external_order_item_id', 900000000 + random_int(1, 99999999));
	$p->set('cvp_install_mode', 'fresh');
	$p->set('cvp_docker_mode', 'docker');
	$p->set('cvp_mgn_managed_node_id', $node->key);
	$p->save();
	$p->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
	return $p;
};
$node_a = $mk_site_node('site-a');
$node_b = $mk_site_node('site-b');
$site_a = $mk_starter_site('site-a', $node_a);
$site_b = $mk_starter_site('site-b', $node_b);

check(FleetService::site_plan_grants_slot($site_a) && !FleetService::site_plan_grants_slot($tier_site),
	'a sold container site\'s plan grants a slot; an admin\'s site does not');

// A Managed site: its own instance on our account, the site in a container
// on it (hosting_paid_launch B11, Q12).
$managed = $mk_starter_site('managed', $mk_site_node('managed'));
$managed->set('cvp_landing', 'instance');
$managed->set('cvp_instance_id', 'lin-' . random_int(100000, 999999));
$managed->save();
check(FleetService::site_plan_grants_slot($managed), 'a sold Managed site on our account is granted a slot of its own');
$managed->set('cvp_hosting_mode', 'customer');
check(!FleetService::site_plan_grants_slot($managed), 'the same site on its buyer\'s own cloud account is not');
$managed->set('cvp_hosting_mode', 'transferred');
check(!FleetService::site_plan_grants_slot($managed), 'nor once it moved to its buyer\'s own account');
$managed->set('cvp_hosting_mode', 'operator');
$managed->set('cvp_docker_mode', 'bare-metal');
check(!FleetService::site_plan_grants_slot($managed), 'nor a site installed on the machine itself, which runs its own mail server');
$managed->set('cvp_docker_mode', 'docker');
$managed->set('cvp_install_mode', 'bare');
check(!FleetService::site_plan_grants_slot($managed), 'nor a bare instance with no site');
$managed->set('cvp_install_mode', 'fresh');
check(FleetProvisionSeeding::applies($site_a) === true,
	'a Starter site is seeded with no slot tier on its buyer');

$seed_a = FleetProvisionSeeding::seedNode($node_a, $site_a);
$seed_b = FleetProvisionSeeding::seedNode($node_b, $site_b);
check($seed_a['ok'] && $seed_b['ok'], 'the buyer\'s second Starter site is seeded too, not refused',
	$seed_a['message'] . ' / ' . $seed_b['message']);
foreach (array($seed_a, $seed_b) as $r) {
	if (!empty($r['job_id'])) { harness_register_row('mjb_management_jobs', 'mjb_management_job_id', (int)$r['job_id']); }
}
$site_a->load();
$site_b->load();
$key_a = (int)$site_a->get('cvp_fleet_apk_api_key_id');
$key_b = (int)$site_b->get('cvp_fleet_apk_api_key_id');
foreach (new MultiApiKey(array('user_id' => $starter_buyer->key)) as $k) { harness_register_key_id($k->key); }
check($key_a > 0 && $key_b > 0 && $key_a !== $key_b, 'each site records a key of its own');
$ka = new ApiKey($key_a ?: null, (bool)$key_a);
$kb = new ApiKey($key_b ?: null, (bool)$key_b);
check($ka->get('apk_is_active') && $kb->get('apk_is_active'), 'seeding the second site left the first site\'s key active');
check($ka->get('apk_name') === FleetProvisionSeeding::KEY_NAME . ' #' . $site_a->key,
	'a site\'s key is named for the site', (string)$ka->get('apk_name'));

// Re-seeding site A rotates A's key only.
$reseed = FleetProvisionSeeding::seedNode($node_a, $site_a);
if (!empty($reseed['job_id'])) { harness_register_row('mjb_management_jobs', 'mjb_management_job_id', (int)$reseed['job_id']); }
foreach (new MultiApiKey(array('user_id' => $starter_buyer->key)) as $k) { harness_register_key_id($k->key); }
$site_a->load();
$ka->load();
$kb->load();
check((int)$site_a->get('cvp_fleet_apk_api_key_id') !== $key_a && !$ka->get('apk_is_active') && $kb->get('apk_is_active'),
	're-seeding a site retires that site\'s old key and leaves the other site\'s');
$key_a = (int)$site_a->get('cvp_fleet_apk_api_key_id');

$site_a->set('cvp_fleet_seed_state', 'done');
$site_a->save();
check(FleetProvisionSeeding::seededElsewhere($node_b, (int)$starter_buyer->key) === null,
	'a seeded Starter site holds no part of the buyer\'s tier slot');

// The key finds its site; enrolling with each key makes two slots.
check(FleetService::provisionForKey((int)$starter_buyer->key, $key_a)->key == $site_a->key,
	'a site\'s key answers for that site');
check(FleetService::provisionForKey((int)$buyer->key, $key_a) === null,
	'another user\'s session with that key id finds no site');

harness_set_setting_mem('mailbox_fleet_mx_zone', 'fleet.harnesstest.example');
$shard = new MailboxFleetShard(NULL);
$shard->set('mfs_name', 'fleet_auto_enrollment shard');
$shard->set('mfs_capacity', 1000);
$shard->set('mfs_identity_fingerprint', base64_encode(str_repeat("\x03", 32)));
$shard->set('mfs_public_ip', '203.0.113.10');
$shard->save();
harness_register_row('mfs_mailbox_fleet_shards', 'mfs_mailbox_fleet_shard_id', $shard->key);
$slot_keys = function () { return array('public_key' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))); };
$slot_a = $slot_b = null;
try {
	$slot_a = FleetService::enroll((int)$starter_buyer->key, $slot_keys(), $site_a);
	$slot_b = FleetService::enroll((int)$starter_buyer->key, $slot_keys(), $site_b);
} catch (FleetServiceException $e) {
	check(false, 'enrolling both sites', $e->getMessage());
}
foreach (array($slot_a, $slot_b) as $sl) {
	if ($sl) { harness_register_row('mft_mailbox_fleet_slots', 'mft_mailbox_fleet_slot_id', $sl->key); }
}
if ($slot_a && $slot_b) {
	check($slot_a->key != $slot_b->key, 'the buyer\'s two sites hold two slots');
	check((int)$slot_a->get('mft_cvp_customer_cloud_provision_id') === (int)$site_a->key,
		'a site\'s slot records its site');
	check(MailboxFleetSlot::activeForUser((int)$starter_buyer->key) === null,
		'a site\'s slot is not the buyer\'s tier slot');
	$again = FleetService::enroll((int)$starter_buyer->key, $slot_keys(), $site_a);
	check($again->key == $slot_a->key, 're-enrolling a site returns its own slot');

	$session_of = function (int $user_id, int $key_id) {
		return new class($user_id, $key_id) {
			private $u; private $k;
			public function __construct($u, $k) { $this->u = $u; $this->k = $k; }
			public function get_user_id() { return $this->u; }
			public function get_api_key_id() { return $this->k; }
		};
	};
	$caller_b = FleetService::callerSlot($session_of((int)$starter_buyer->key, $key_b));
	check($caller_b !== null && $caller_b->key == $slot_b->key, 'a call signed with site B\'s key speaks for site B\'s slot');

	// A site's key never falls through to its buyer's tier slot.
	$tier_slot = new MailboxFleetSlot(NULL);
	$tier_slot->set('mft_mfs_mailbox_fleet_shard_id', intval($shard->key));
	$tier_slot->set('mft_usr_user_id', (int)$starter_buyer->key);
	$tier_slot->set('mft_status', MailboxFleetSlot::STATUS_ACTIVE);
	$tier_slot->set('mft_public_key', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)));
	$tier_slot->save();
	harness_register_row('mft_mailbox_fleet_slots', 'mft_mailbox_fleet_slot_id', $tier_slot->key);
	check(FleetService::isSiteKey($key_b) && !FleetService::isSiteKey(0), 'site B\'s key is a site key');
	$site_b->set('cvp_delete_time', gmdate('Y-m-d H:i:s'));
	$site_b->save();
	check(FleetService::callerSlot($session_of((int)$starter_buyer->key, $key_b)) === null,
		'a removed site\'s key speaks for no slot, not the buyer\'s tier slot');
	$site_b->set('cvp_delete_time', null);
	$site_b->save();
	$tier_caller = FleetService::callerSlot($session_of((int)$starter_buyer->key, 0));
	check($tier_caller !== null && $tier_caller->key == $tier_slot->key, 'a browser session (no key) gets the tier slot');
	$tier_slot->permanent_delete();

	check(FleetService::slotEntitled($slot_a) && FleetService::slotEntitled($slot_b), 'both live sites are entitled');
	// Site A's hosting ends: its slot loses entitlement, site B's keeps it.
	$trial = new HostedTrial(NULL);
	$trial->set('htr_cvp_customer_cloud_provision_id', $site_a->key);
	$trial->set('htr_state', HostedTrial::STATE_SHUTDOWN);
	$trial->save();
	harness_register_row('htr_hosted_trials', 'htr_hosted_trial_id', $trial->key);
	check(!FleetService::slotEntitled($slot_a) && FleetService::slotEntitled($slot_b),
		'one site\'s hosting shut down leaves the other site\'s slot entitled');
	$node_b->set('mgn_delete_time', gmdate('Y-m-d H:i:s'));
	$node_b->save();
	check(!FleetService::slotEntitled($slot_b), 'a removed site (node soft-deleted) is no longer entitled');
	$node_b->set('mgn_delete_time', null);
	$node_b->save();
	// A site moved to its buyer's own account is gone from its slot too (B11).
	$site_b->set('cvp_landing', 'instance');
	$site_b->set('cvp_instance_id', 'lin-' . random_int(100000, 999999));
	$site_b->set('cvp_hosting_mode', 'transferred');
	$site_b->save();
	check(FleetService::siteGone($slot_b), 'a site moved to its buyer\'s own account is gone from its slot, so the slot is released');
	$site_b->set('cvp_hosting_mode', 'operator');
	$site_b->set('cvp_instance_id', null);
	$site_b->set('cvp_landing', 'container');
	$site_b->save();
	check(!FleetService::siteGone($slot_b), 'and back on our account it is not');
	$slot_a->set('mft_cvp_customer_cloud_provision_id', -1);
	check(!FleetService::slotEntitled($slot_a), 'a slot whose site was purged (-1) is not entitled');

	// A Managed site seeded before its plan granted a slot holds the buyer's
	// tier key: it still holds the tier slot, so another site of the buyer is
	// refused rather than handed its key (reviewer2 B11 B3).
	$managed->set('cvp_fleet_seed_state', 'done');
	$managed->set('cvp_fleet_apk_api_key_id', null);
	$managed->save();
	check(FleetProvisionSeeding::seededElsewhere($node_a, (int)$starter_buyer->key) === (string)$managed->get('cvp_domain'),
		'a site seeded with the tier key still holds the tier slot, whatever its plan grants now');
	$managed->set('cvp_fleet_seed_state', null);
	$managed->save();

	// ── The records site B's domain needs, and where they go (S5-3) ──
	section('A Starter site\'s inbound records');
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionSiteInboundMail.php'));
	require_once(PathHelper::getIncludePath('tests/dns/fixtures/FakeDnsDriver.php'));
	$domain_b = (string)$site_b->get('cvp_domain');
	check(FleetService::site_inbound_records($site_b) === array(), 'no records before the site files its claim');
	$claim_b = FleetService::claimDomain($slot_b, $domain_b);
	harness_register_row('mfd_mailbox_fleet_domain_claims', 'mfd_mailbox_fleet_domain_claim_id', $claim_b->key);
	$records = FleetService::site_inbound_records($site_b);
	$by_type = array();
	foreach ($records as $r) { $by_type[$r['type']] = $r; }
	check(isset($by_type['MX']) && $by_type['MX']['name'] === $domain_b
		&& $by_type['MX']['value'] === (string)$slot_b->get('mft_mx_hostname'),
		'the MX names the slot\'s mail name', json_encode($records));
	check(isset($by_type['TXT']) && $by_type['TXT']['name'] === '_joinery-fleet-challenge.' . $domain_b
		&& $by_type['TXT']['value'] === (string)$claim_b->get('mfd_txt_token'),
		'and the ownership TXT carries the claim\'s token');

	/** The leg, with its DNS host in memory. */
	$leg = new class extends ProvisionSiteInboundMail {
		public $fake;
		protected function dns_driver() { return $this->fake; }
	};
	$leg->fake = new FakeDnsDriver(array());
	$site_b->set('cvp_fleet_seed_state', 'done');
	$site_b->save();
	$leg->run(array('provision_id' => (int)$site_b->key));
	$site_b->load();
	check((string)$site_b->get('cvp_inbound_dns_state') === 'buyer' && !$leg->fake->writes,
		'on the buyer\'s own domain nothing is written; the records are left for the buyer');
	require_once(PathHelper::getIncludePath('plugins/server_manager/logic/profile_sites_logic.php'));
	$listed = profile_sites_inbound_records($site_b);
	$listed_types = array_map(function ($r) { return $r['type']; }, $listed);
	check(in_array('MX', $listed_types, true) && in_array('TXT', $listed_types, true),
		'and their sites page lists the MX and the TXT', json_encode($listed));

	// The same site as Managed (its own instance on our account): the leg
	// takes it too, and leaves the records for the buyer (B11).
	$site_b->set('cvp_inbound_dns_state', null);
	$site_b->set('cvp_landing', 'instance');
	$site_b->set('cvp_instance_id', 'lin-' . random_int(100000, 999999));
	$site_b->save();
	$leg->run(array('provision_id' => (int)$site_b->key));
	$site_b->load();
	check((string)$site_b->get('cvp_inbound_dns_state') === 'buyer',
		'a Managed site is placed by the inbound leg too: its records are left for its buyer',
		var_export($site_b->get('cvp_inbound_dns_state'), true));
	$site_b->set('cvp_instance_id', null);
	$site_b->set('cvp_landing', 'container');
	$site_b->save();

	// The same site on a name we registered: published, additively.
	$site_b->set('cvp_inbound_dns_state', null);
	$site_b->save();
	$rdm = new RegisteredDomain(NULL);
	$rdm->set('rdm_registrar', 'fake');
	$rdm->set('rdm_domain', $domain_b);
	$rdm->set('rdm_usr_user_id', $starter_buyer->key);
	$rdm->set('rdm_buyer_email', $starter_buyer->get('usr_email'));
	$rdm->set('rdm_status', RegisteredDomain::STATUS_ACTIVE);
	$rdm->set('rdm_external_order_item_id', (int)$site_b->get('cvp_external_order_item_id'));
	$rdm->seal_registrant(array(
		'first_name' => 'Jane', 'last_name' => 'Smith', 'address1' => '14 Elm Street',
		'city' => 'Springfield', 'state_province' => 'IL', 'postal_code' => '62704',
		'country' => 'US', 'phone' => '+1.5551234567', 'email' => 'jane@example.com'));
	$rdm->prepare();
	$rdm->save();
	harness_register_row('rdm_registered_domains', 'rdm_registered_domain_id', $rdm->key);
	harness_defer(function () use ($db, $domain_b) {
		$q = $db->prepare('DELETE FROM dnr_managed_dns_records WHERE dnr_domain = ?');
		$q->execute(array($domain_b));
	});
	$leg->fake->zones[$domain_b] = array();
	$leg->run(array('provision_id' => (int)$site_b->key));
	$site_b->load();
	$written = implode(' | ', $leg->fake->writes);
	check((string)$site_b->get('cvp_inbound_dns_state') === 'published'
		&& stripos($written, 'MX') !== false && stripos($written, 'TXT') !== false,
		'on a name we registered the MX and the TXT are published', $written);
	check(profile_sites_inbound_records($site_b) === array(), 'and the buyer is asked for nothing');
	$writes_before = count($leg->fake->writes);
	$leg->run(array('provision_id' => (int)$site_b->key));
	check(count($leg->fake->writes) === $writes_before, 'a placed site is not written again');

	// A record we did not set already in the way is never overwritten.
	$db->prepare('DELETE FROM dnr_managed_dns_records WHERE dnr_domain = ?')->execute(array($domain_b));
	$leg->fake = new FakeDnsDriver(array());
	$leg->fake->seed($domain_b, array(new DnsRecord('MX', $domain_b, 'mx.elsewhere.example', 3600, 10)));
	$site_b->set('cvp_inbound_dns_state', null);
	$site_b->save();
	$blocked = $leg->run(array('provision_id' => (int)$site_b->key));
	$site_b->load();
	check($site_b->get('cvp_inbound_dns_state') === null && !$leg->fake->writes,
		'an MX we did not set is not overwritten, and the site is not marked placed', implode(' | ', $leg->fake->writes));
	check($blocked['status'] === 'error' && strpos($blocked['message'], 'did not set') !== false,
		'the run reports it', $blocked['message']);
	$again_blocked = $leg->run(array('provision_id' => (int)$site_b->key));
	check($again_blocked['status'] === 'error', 'and reports it again on the next pass, until it is cleared');
	$leg->fake->zones[$domain_b] = array();
	$leg->run(array('provision_id' => (int)$site_b->key));
	$site_b->load();
	check((string)$site_b->get('cvp_inbound_dns_state') === 'published', 'once it is cleared the records are published');

	// A verified claim needs no TXT any more.
	$claim_b->set('mfd_status', MailboxFleetDomainClaim::STATUS_VERIFIED);
	$claim_b->save();
	$after = array_map(function ($r) { return $r['type']; }, FleetService::site_inbound_records($site_b));
	check($after === array('MX'), 'once the claim is verified only the MX is asked for');

	// A removed site's slot is released, its claims revoked, its key retired.
	check(!FleetService::siteGone($slot_b), 'a live site\'s slot is not gone');
	$site_b->set('cvp_delete_time', gmdate('Y-m-d H:i:s'));
	$site_b->save();
	check(FleetService::siteGone($slot_b), 'a deleted site\'s slot is gone');
	FleetService::releaseSiteSlot($slot_b);
	$slot_b->load();
	$claim_b->load();
	$kb_now = new ApiKey($key_b, TRUE);
	$ka_now = new ApiKey($key_a, TRUE);
	check((string)$slot_b->get('mft_status') === MailboxFleetSlot::STATUS_RELEASED
		&& (string)$claim_b->get('mfd_status') === MailboxFleetDomainClaim::STATUS_REVOKED,
		'its slot is released and its claim revoked');
	check(MailboxFleetDomainClaim::liveClaimByOtherSlot($domain_b, 0) === null,
		'so the buyer\'s next site can claim the same domain');
	check(!$kb_now->get('apk_is_active') && $ka_now->get('apk_is_active'),
		'the removed site\'s key is retired; the live site\'s is not');
}

// ── E. The operator console's Relay Hosting product ─────────────────────────
section('Relay Hosting product creation');

if (!PluginHelper::isPluginActive('store') || !PluginHelper::isPluginActive('server_manager')) {
	harness_skip('store/server_manager inactive', 'product creation needs both plugins');
} else {
	$pre_existing = admin_mailbox_relay_fleet_products();
	if (!empty($pre_existing)) {
		$already = admin_mailbox_relay_create_fleet_product();
		check(strpos($already['message'], 'already exists') !== false,
			'an existing fleet product makes creation a no-op');
	} else {
		$created = admin_mailbox_relay_create_fleet_product();
		$products = admin_mailbox_relay_fleet_products();
		check(count($products) === 1, 'one fleet product exists after creation');
		if (count($products) === 1) {
			require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
			$product = new Product($products[0]['id'], TRUE);
			harness_register_row('pro_products', 'pro_product_id', $product->key);
			check((bool)$product->get('pro_is_active') === false,
				'the product is born inactive — pricing/publishing is the operator\'s act');
			check((string)$product->get('pro_name') === 'Relay Hosting'
					&& strpos((string)$product->get('pro_link'), 'relay-hosting') === 0,
				'a new fleet product is named Relay Hosting, linked relay-hosting');
			check($product->get('pro_fulfillment_provider') === 'customer_cloud',
				'the product fulfills onto a customer-cloud server');
			$linked_tier = new SubscriptionTier((int)$product->get('pro_sbt_subscription_tier_id'), TRUE);
			check((bool)$linked_tier->getFeature('mailbox_fleet_slot', false) === true,
				'the granted tier carries the fleet-slot feature');
			// The scratch entitlement tier (created above, level 9000) is the
			// reuse candidate on a clean DB; a pre-existing operator tier also
			// satisfies this — either way, creation must not have minted a
			// SECOND slot tier.
			check(strpos($created['message'], 'Reused tier') !== false
					|| strpos($created['message'], 'Tier "Relay" created') !== false,
				'creation reports what it did about the tier');

			$again = admin_mailbox_relay_create_fleet_product();
			check(strpos($again['message'], 'already exists') !== false,
				'creation is idempotent');
			$after = admin_mailbox_relay_fleet_products();
			check(count($after) === 1, 'idempotent re-run creates no second product');
		}
	}
}

harness_finish();
