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
 * the operator console's one-click Fortress product creation.
 *
 * Creates scratch tier/group/member, api-key, and product rows; all deleted
 * LIFO. No mailbox tables are touched.
 *
 * Run: php tests/run.php db --filter=fleet_auto_enrollment
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetProvisionSeeding.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));
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

check(FleetProvisionSeeding::applies($buyer->key) === false,
	'no entitlement -> does not apply');

$tier->addUser($buyer->key);
SubscriptionTier::clearUserCache($buyer->key);

check(FleetProvisionSeeding::applies($buyer->key) === true,
	'fleet on + entitled buyer + local store -> applies');

harness_set_setting_mem('mailbox_fleet_service_enabled', '0');
check(FleetProvisionSeeding::applies($buyer->key) === false,
	'fleet service off -> does not apply');
harness_set_setting_mem('mailbox_fleet_service_enabled', '1');

harness_set_setting_mem('server_manager_getjoinery_api_url', 'https://some-other-store.example.com');
check(FleetProvisionSeeding::applies($buyer->key) === false,
	'remote store -> does not apply (buyer id is not a local user there)');
harness_set_setting_mem('server_manager_getjoinery_api_url', '');

check(FleetProvisionSeeding::applies(0) === false, 'user id 0 -> does not apply');

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

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

// An unpaired node has no route, and no key is minted for it: the provision
// re-asks every tick until the agent pairs, and a key per tick would churn.
$unpaired = new ManagedNode(NULL);
$unpaired->set('mgn_name', 'fleet unpaired');
$unpaired->set('mgn_slug', 'harnesstest-fleet-unpaired-' . substr(md5(uniqid('', true)), 0, 6));
$unpaired->set('mgn_host', '192.0.2.40');
$unpaired->set('mgn_uptime_enabled', false);
$unpaired->save();
$unpaired->load();
harness_register_row('mgn_managed_nodes', 'mgn_id', $unpaired->key);
check(FleetProvisionSeeding::nodeReady($unpaired) === false, 'an unpaired node is not ready to seed');
$before = 0;
foreach (new MultiApiKey(array('user_id' => $buyer->key)) as $k) { $before++; }
$res = FleetProvisionSeeding::seedNode($unpaired, $buyer->key);
check($res['ok'] === false && strpos($res['message'], 'fleet_enroll') !== false && strpos($res['message'], 'SSH') !== false,
	'seeding an unpaired node fails loudly, naming the primitive and that there is no SSH route');
$after = 0;
foreach (new MultiApiKey(array('user_id' => $buyer->key)) as $k) { $after++; }
check($after === $before, 'and mints no key for it');

// A paired node reporting fleet_enroll gets one job carrying the three values.
$paired = new ManagedNode(NULL);
$paired->set('mgn_name', 'fleet paired');
$paired->set('mgn_slug', 'harnesstest-fleet-paired-' . substr(md5(uniqid('', true)), 0, 6));
$paired->set('mgn_host', '192.0.2.41');
$paired->set('mgn_uptime_enabled', false);
$paired->set('mgn_agent_public_key', base64_encode(str_repeat("\x0f", 32)));
$paired->set('mgn_agent_version', '1.17.0');
$paired->set('mgn_agent_primitives', 'check_status,fleet_enroll');
$paired->save();
$paired->load();
harness_register_row('mgn_managed_nodes', 'mgn_id', $paired->key);
$res = FleetProvisionSeeding::seedNode($paired, $buyer->key);
check($res['ok'] === true && !empty($res['job_id']), 'a paired node is seeded by a job', $res['message']);
$job = new ManagementJob((int)$res['job_id'], TRUE);
harness_register_row('mjb_management_jobs', 'mjb_id', $job->key);
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

// ── D. The operator console's Fortress product ──────────────────────────────
section('Fortress product creation');

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
					|| strpos($created['message'], 'Tier "Fortress" created') !== false,
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
