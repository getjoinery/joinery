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

// ── C. The remote seeding command ───────────────────────────────────────────
section('buildRemoteCommand');

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

$bare = new ManagedNode(NULL);
$bare->set('mgn_ssh_user', 'user1');
$cmd = FleetProvisionSeeding::buildRemoteCommand($bare, 'customersite',
	'https://operator.example.com', 'public_abc123');
check(strpos($cmd, 'sudo bash -c ') === 0, 'bare-metal non-root command runs under sudo');
check(strpos($cmd, 'mailbox_fleet_service_url') !== false
	&& strpos($cmd, 'mailbox_fleet_api_public_key') !== false
	&& strpos($cmd, 'mailbox_fleet_api_secret_key') !== false,
	'command seeds all three fleet settings');
check(strpos($cmd, 'ON CONFLICT (stg_name)') !== false, 'settings write is an upsert');
// The secret is structurally absent: buildRemoteCommand never receives it.
// The command must read it from stdin and expand it only inside the heredoc.
check(strpos($cmd, 'IFS= read -r FLEET_SECRET') !== false
	&& strpos($cmd, '${FLEET_SECRET}') !== false,
	'the secret arrives via stdin and expands only inside the psql heredoc');
check(strpos($cmd, '/var/www/html/customersite/config/Globalvars_site.php') !== false,
	'DB credentials come from the site config, not from the operator');

$docker = new ManagedNode(NULL);
$docker->set('mgn_ssh_user', 'root');
$docker->set('mgn_container_name', 'customersite');
$dcmd = FleetProvisionSeeding::buildRemoteCommand($docker, 'customersite',
	'https://operator.example.com', 'public_abc123');
check(strpos($dcmd, 'docker exec -i ') === 0,
	'docker site seeds inside the container (root, stdin kept open)');

$bad = FleetProvisionSeeding::seedNode($bare, $buyer->key, 'Bad;Name');
check($bad['ok'] === false && strpos($bad['message'], 'not a plain slug') !== false,
	'a non-slug sitename is refused before anything runs');

$unreachable = new ManagedNode(NULL);
$unreachable->set('mgn_ssh_user', 'root');
$unreachable->set('mgn_ssh_key_path', '/nonexistent/provisioning_key');
$unreachable->set('mgn_host', '');
$res = FleetProvisionSeeding::seedNode($unreachable, $buyer->key, 'customersite');
check($res['ok'] === false && strpos($res['message'], 'SSH') !== false,
	'incomplete SSH coordinates fail loudly, never silently');
// That seedNode minted a key before failing — register it for cleanup.
$keys = new MultiApiKey(array('user_id' => $buyer->key));
$keys->load();
foreach ($keys as $key_row) {
	harness_register_key_id($key_row->key);
}

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
