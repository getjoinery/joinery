<?php
/** @joinery-test
 * name: own_linode_purchase
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/server_manager/includes/ManagedSiteDraft.php, plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php, plugins/server_manager/includes/fulfillment_providers/JoineryServicesFulfillment.php, plugins/server_manager/includes/requirements/JoineryServiceRequirement.php, plugins/server_manager/includes/requirements/ManagedSiteRequirement.php, plugins/server_manager/data/customer_cloud_accounts_class.php, plugins/server_manager/includes/provisioning/ProvisionOwnLinode.php, plugins/server_manager/includes/provisioning/ServiceTenantWatch.php]
 */
/**
 * Buying On your own Linode (specs/own_linode_we_install.md): a free plan
 * beside Managed in the one configurator. We build the site in the buyer's own
 * Linode account, and the email and backups bundle can ride in the same
 * checkout.
 *
 *  - The plan is offered with its price read as free, and the draft records
 *    it (cvp_plan), in us-east; a row from before plans were recorded is read
 *    from its landing.
 *  - Before the charge: a draft of another plan is refused both ways; the
 *    buyer's Linode grant must have an hour left; the per-account limit holds.
 *  - Payment activates it in the buyer's account, with their grant, unencrypted
 *    by us.
 *  - The bundle line rides with the install line, names the draft, is refused
 *    without its install line in the cart, and is recorded on the provision at
 *    payment; a free site without it gets no backup space of ours.
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/fulfillment_providers/JoineryServicesFulfillment.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/ManagedSiteRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/requirements/JoineryServiceRequirement.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedSiteDraft.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionCustomerCloud.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionOwnLinode.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/Smtp2GoClient.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_accounts_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/product_versions_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_item_requirements_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));

class OlFulfillment extends CustomerCloudFulfillment {
	public static $alerts = array();
	protected function alert_activation_problem(User $user, OrderItem $order_item, string $reason): void {
		self::$alerts[] = array('order_item' => (int)$order_item->key, 'reason' => $reason);
	}
}

$run = substr(md5(uniqid('ol', true)), 0, 6);

function ol_product(string $name, string $link, array $fields, string $price, string $type) {
	$product = new Product(NULL);
	$product->set('pro_name', $name);
	$product->set('pro_link', $link);
	$product->set('pro_is_active', true);
	foreach ($fields as $k => $v) { $product->set($k, $v); }
	$product->save();
	$product->load();
	harness_register_row('pro_products', 'pro_product_id', $product->key);
	$version = new ProductVersion(NULL);
	$version->set('prv_pro_product_id', $product->key);
	$version->set('prv_version_name', 'V');
	$version->set('prv_version_price', $price);
	$version->set('prv_price_type', $type);
	$version->set('prv_status', 1);
	$version->save();
	$version->load();
	harness_register_row('prv_product_versions', 'prv_product_version_id', $version->key);
	return array($product, $version);
}

function ol_post(string $plan, string $domain, bool $services = false): array {
	$post = array('plan' => $plan, 'cvp_domain_source' => 'own', 'cvp_domain' => $domain,
		'cvp_sitename' => '', 'cvp_buyer_email' => 'admin@example.com');
	if ($services) {
		$post['with_services'] = '1';
	}
	return $post;
}

function ol_paid_line($buyer, $product, $version, array $form_data, $order = null): array {
	if ($order === null) {
		$order = new Order(NULL);
		$order->set('ord_usr_user_id', $buyer->key);
		$order->set('ord_status', Order::STATUS_PAID);
		$order->save();
		$order->load();
		harness_register_row('ord_orders', 'ord_order_id', $order->key);
	}
	$item = new OrderItem(NULL);
	$item->set('odi_ord_order_id', $order->key);
	$item->set('odi_pro_product_id', $product->key);
	$item->set('odi_prv_product_version_id', $version->key);
	$item->set('odi_usr_user_id', $buyer->key);
	$item->set('odi_product_info', base64_encode(serialize($form_data)));
	$item->set('odi_price', $version->get('prv_version_price'));
	$item->set('odi_status', OrderItem::STATUS_PAID);
	$item->save();
	$item->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $item->key);
	$item->save_cart_data($form_data);
	foreach (new MultiOrderItemRequirement(array('order_item_id' => $item->key)) as $row) {
		harness_register_row('oir_order_item_requirements', 'oir_order_item_requirement_id', $row->key);
	}
	return array($order, $item);
}

function ol_line_data($draft, $product): array {
	$req = new ManagedSiteRequirement();
	$req->validate(array('managed_site' => (int)$draft->key), $product);
	list($data) = $req->process(array('managed_site' => (int)$draft->key), $product, null, null);
	return $data;
}

function ol_register($row) {
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $row->key);
	return $row;
}

list($own, $own_version) = ol_product('Own Linode [test]', 'ol-own-' . $run, array(
	'pro_fulfillment_provider' => 'customer_cloud', 'pro_fulfillment_ref' => 0, 'pro_max_cart_count' => 1), '0.00', 'single');
list($managed, $managed_version) = ol_product('Managed hosting [test]', 'ol-managed-' . $run, array(
	'pro_fulfillment_provider' => 'customer_cloud', 'pro_fulfillment_ref' => 1, 'pro_max_cart_count' => 1), '9.99', 'month');
list($bundle, $bundle_version) = ol_product('Email and backups [test]', 'ol-services-' . $run, array(
	'pro_fulfillment_provider' => 'joinery_services', 'pro_fulfillment_ref' => 1), '2.99', 'month');
JoineryServicesFulfillment::forget_offers();

harness_set_setting_mem('server_manager_hosted_regions', 'us-southeast');
harness_set_setting_mem('server_manager_own_linode_sites_per_buyer', '1');
harness_set_setting_mem('server_manager_hosted_sites_limit_exempt', '');

$buyer = make_user('OlBuyer' . $run);
$_SESSION['loggedin'] = 1;
$_SESSION['usr_user_id'] = (int)$buyer->key;
$_SESSION['permission'] = 0;

// ---------------------------------------------------------------------------
section('The plan is offered, free, beside Managed');

$offered = ManagedSiteDraft::offered_plans();
check(isset($offered['own_linode']) && isset($offered['managed']), 'both plans are offered', implode(',', array_keys($offered)));
check(ManagedSiteDraft::price_sentence($own) === 'free', 'a free product\'s price reads free', ManagedSiteDraft::price_sentence($own));
check(strpos(ManagedSiteDraft::plans_sentence(), 'On your own Linode is free') !== false,
	'the plans sentence names it as free', ManagedSiteDraft::plans_sentence());
check(JoineryServicesFulfillment::bundle_offer() !== null, 'the email and backups bundle is on sale');

// ---------------------------------------------------------------------------
section('The draft records its plan, in us-east');

$r = ManagedSiteDraft::save_and_freeze(null, ol_post('own_linode', 'ol-a-' . $run . '.com', true), $buyer);
$draft = $r['row'] ? ol_register($r['row']) : null;
check($draft !== null && (string)$draft->get('cvp_plan') === 'own_linode' && ManagedSiteDraft::plan_of($draft) === 'own_linode',
	'an own-Linode draft records its plan', json_encode($r['errors']));
check($draft && (string)$draft->get('cvp_region') === 'us-east', 'its server is in us-east, whatever the hosted regions',
	$draft ? (string)$draft->get('cvp_region') : '');
check($draft && (bool)$draft->get('cvp_services_wanted'), 'the ticked bundle is recorded');
$r2 = ManagedSiteDraft::save_and_freeze(null, ol_post('managed', 'ol-m-' . $run . '.com', true), $buyer);
$mdraft = $r2['row'] ? ol_register($r2['row']) : null;
check($mdraft && ManagedSiteDraft::plan_of($mdraft) === 'managed' && !$mdraft->get('cvp_services_wanted'),
	'a Managed draft records Managed and never the bundle tick', json_encode($r2['errors']));
$legacy = new CustomerCloudProvision(NULL);
$legacy->set('cvp_landing', 'instance');
check(ManagedSiteDraft::plan_of($legacy) === 'managed', 'a row with no recorded plan is read from its landing');

// ---------------------------------------------------------------------------
section('Before the charge: the plan, the Linode grant and the limit');

$provider = new OlFulfillment();
$own_data = ol_line_data($draft, $own);
// A draft without the bundle, for the checks that are not about it (the
// install line of one with the bundle also needs the bundle line in the cart).
$rp = ManagedSiteDraft::save_and_freeze(null, ol_post('own_linode', 'ol-p-' . $run . '.com'), $buyer);
$plain = $rp['row'] ? ol_register($rp['row']) : null;
$plain_data = ol_line_data($plain, $own);
check(CustomerCloudFulfillment::plan_for_ref(0) === 'own_linode' && CustomerCloudFulfillment::plan_for_ref(1) === 'managed',
	'reference 0 is own-Linode, 1 Managed');
check($provider->checkAvailability($own, 0, 1, ol_line_data($mdraft, $managed)) === CustomerCloudFulfillment::WRONG_PLAN_SENTENCE,
	'a Managed draft is never paid for with the free product');
check($provider->checkAvailability($managed, 1, 1, $own_data) === CustomerCloudFulfillment::WRONG_PLAN_SENTENCE,
	'nor an own-Linode draft with the Managed product');
check($provider->checkAvailability($own, 0, 1, $plain_data) === CustomerCloudFulfillment::CONNECT_LINODE_SENTENCE,
	'with no Linode connection, the buyer is told to connect');

$account = new CustomerCloudAccount(NULL);
$account->set('cca_usr_user_id', $buyer->key);
$account->set('cca_provider', 'linode');
$account->set('cca_status', 'active');
$account->set('cca_access_token', 'sealed-test-token');
$account->set('cca_token_expires', gmdate('Y-m-d H:i:s', time() + 1800));
$account->save();
$account->load();
harness_register_row('cca_customer_cloud_accounts', 'cca_customer_cloud_account_id', $account->key);
check($provider->checkAvailability($own, 0, 1, $plain_data) === CustomerCloudFulfillment::CONNECT_LINODE_SENTENCE,
	'a grant with half an hour left cannot carry a build');
$account->set('cca_token_expires', gmdate('Y-m-d H:i:s', time() + 7000));
$account->save();
check(CustomerCloudAccount::live_for_build((int)$buyer->key) !== null, 'a grant with nearly two hours left can');
check($provider->checkAvailability($own, 0, 1, $plain_data) === null, 'and the line passes');

$other = new CustomerCloudProvision(NULL);
$other->set('cvp_origin', 'buyer');
$other->set('cvp_usr_user_id', $buyer->key);
$other->set('cvp_domain', 'ol-held-' . $run . '.com');
$other->set('cvp_slug', 'ol-held-' . $run . '-com');
$other->set('cvp_plan', 'own_linode');
$other->set('cvp_status', 'done');
$other->set('cvp_external_order_item_id', 900000000 + random_int(1, 99999999));
$other->save();
ol_register($other);
check($provider->checkAvailability($own, 0, 1, $plain_data) === CustomerCloudFulfillment::own_linode_limit_sentence(1),
	'past the per-account limit the line is refused', (string)$provider->checkAvailability($own, 0, 1, $plain_data));
$other->set('cvp_status', 'failed');
$other->set('cvp_delete_time', gmdate('Y-m-d H:i:s'));
$other->save();

// ---------------------------------------------------------------------------
section('The bundle rides with the install line');

$extra = (new ManagedSiteRequirement())->extra_cart_lines($own_data, $own);
$bundle_line = null;
foreach ($extra as $line) {
	if ((int)$line['product_id'] === (int)$bundle->key) { $bundle_line = $line['form_data']; }
}
check($bundle_line !== null && JoineryServiceRequirement::draft_id_from($bundle_line) === (int)$draft->key,
	'the install line brings the bundle line, naming the draft', json_encode($extra));
check($bundle_line !== null && JoineryServiceRequirement::row_id_from($bundle_line) === 0,
	'a draft answer is never read as a service row');
check((new ManagedSiteRequirement())->extra_cart_lines(ol_line_data($mdraft, $managed), $managed) === array(),
	'a Managed line brings no bundle line');
$services = new JoineryServicesFulfillment();
check($services->checkAvailability($bundle, 1, 1, $bundle_line ?? array()) === JoineryServicesFulfillment::DRAFT_NOT_IN_CART_SENTENCE,
	'the bundle line alone, without its install line in the cart, is refused');
$req = new JoineryServiceRequirement();
check($req->validate(array(JoineryServiceRequirement::FIELD => 'cvp:' . (int)$draft->key), $bundle) !== array(),
	'a product page cannot add a draft-anchored bundle line');

// ---------------------------------------------------------------------------
section('Payment: the site in the buyer\'s account, the bundle on the provision');

list($order, $item) = ol_paid_line($buyer, $own, $own_version, $own_data);
list(, $bundle_item) = ol_paid_line($buyer, $bundle, $bundle_version, $bundle_line ?? array(), $order);
$services->fulfill($buyer, $bundle, $bundle_item, $order, 1);
$provider->fulfill($buyer, $own, $item, $order, 0);
$paid = new CustomerCloudProvision((int)$draft->key, TRUE);
check((string)$paid->get('cvp_status') === 'ready' && (string)$paid->get('cvp_hosting_mode') === 'customer'
	&& (int)$paid->get('cvp_cca_customer_cloud_account_id') === (int)$account->key,
	'activated ready, in the buyer\'s account, on their grant',
	$paid->get('cvp_status') . '/' . $paid->get('cvp_hosting_mode'));
check(!$paid->get('cvp_data_root_encrypted'), 'not born with our data-root encryption');
check((int)$paid->get('cvp_services_order_item_id') === (int)$bundle_item->key && (string)$paid->get('cvp_mail_state') === 'pending',
	'the bundle is recorded on the provision, in any order the lines are fulfilled, and its mail is owed');
check(!ProvisionCustomerCloud::own_linode_without_bundle($paid), 'with the bundle, the node gets our backup space');
$paid->set('cvp_services_order_item_id', null);
check(ProvisionCustomerCloud::own_linode_without_bundle($paid), 'without it, none (B8)');

$r3 = ManagedSiteDraft::save_and_freeze(null, ol_post('managed', 'ol-wrong-' . $run . '.com'), $buyer);
$wrong = $r3['row'] ? ol_register($r3['row']) : null;
OlFulfillment::$alerts = array();
list(, $wrong_item) = ol_paid_line($buyer, $own, $own_version, ol_line_data($wrong, $managed));
$provider->fulfill($buyer, $own, $wrong_item, $order, 0);
check(count(OlFulfillment::$alerts) === 1 && (string)(new CustomerCloudProvision((int)$wrong->key, TRUE))->get('cvp_status') === 'pending_payment',
	'a Managed draft paid with the free product is not built, and a person is alerted', json_encode(OlFulfillment::$alerts));

// ---------------------------------------------------------------------------
section('In the cart: the bundle passes with its install line, and the install needs its bundle (B8)');

harness_set_setting_mem('server_manager_own_linode_sites_per_buyer', '5');
$cart = ShoppingCart::current();
$cart->clear_cart();
$r4 = ManagedSiteDraft::save_and_freeze(null, ol_post('own_linode', 'ol-cart-' . $run . '.com', true), $buyer);
$cdraft = $r4['row'] ? ol_register($r4['row']) : null;
$cdata = ol_line_data($cdraft, $own);
// The lines are placed the way ShoppingCart::add_item() stores them; adding a
// subscription through add_item() would call Stripe, which a test box has not.
$cart->items[] = array(1, $own, $cdata + array('product_version' => $own_version->key), '0.00', 0, $own_version);
check($provider->checkAvailability($own, 0, 1, $cdata) === CustomerCloudFulfillment::BUNDLE_MISSING_SENTENCE,
	'the install line is refused while the bundle the buyer ticked is not in the cart');
$cbundle = JoineryServiceRequirement::draft_line_data((int)$cdraft->key, (string)$cdraft->get('cvp_domain'));
$cart->items[] = array(1, $bundle, $cbundle + array('product_version' => $bundle_version->key), '2.99', 0, $bundle_version);
check($provider->checkAvailability($own, 0, 1, $cdata) === null, 'with the bundle line beside it, the install passes');
check(ManagedSiteDraft::in_cart($cdraft) && $services->checkAvailability($bundle, 1, 1, $cbundle) === null,
	'and the bundle line passes with its install line in the cart, at the same freeze');
ManagedSiteDraft::drop_from_cart($cdraft);
check(count(ShoppingCart::current()->items) === 0, 'Edit takes both lines out of the cart');

// ---------------------------------------------------------------------------
section('After the build: the grant is let go once, and only the build-only one (B1, B2)');

// The leg runs from the scheduled task, with nobody signed in.
$_SESSION['usr_user_id'] = 0;
$_SESSION['loggedin'] = 0;
$leg = new ProvisionOwnLinode();
$seller = make_user('OlGrant' . $run);
$gacct = new CustomerCloudAccount(NULL);
$gacct->set('cca_usr_user_id', $seller->key);
$gacct->set('cca_provider', 'linode');
$gacct->set('cca_status', 'active');
$gacct->set('cca_access_token', 'sealed-build-token');
$gacct->set('cca_scopes', 'linodes:read_write');
$gacct->save();
$gacct->load();
harness_register_row('cca_customer_cloud_accounts', 'cca_customer_cloud_account_id', $gacct->key);
$mk_done = function ($tag, $status = 'done') use ($seller, $gacct, $run) {
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'buyer');
	$p->set('cvp_usr_user_id', $seller->key);
	$p->set('cvp_domain', 'ol-' . $tag . '-' . $run . '.com');
	$p->set('cvp_slug', 'ol-' . $tag . '-' . $run . '-com');
	$p->set('cvp_plan', 'own_linode');
	$p->set('cvp_status', $status);
	$p->set('cvp_cca_customer_cloud_account_id', $gacct->key);
	$p->set('cvp_external_order_item_id', 900000000 + random_int(1, 99999999));
	$p->save();
	$p->load();
	return ol_register($p);
};
$g1 = $mk_done('g1');
$g2 = $mk_done('g2', 'failed');
check($leg->forget_grant($g1) === 0 && trim((string)(new CustomerCloudAccount((int)$gacct->key, TRUE))->get('cca_access_token')) !== '',
	'while another site of the buyer\'s may still be retried, the grant is kept');
$g2->set('cvp_status', 'done');
$g2->set('cvp_grant_released_time', gmdate('Y-m-d H:i:s'));
$g2->save();
check($leg->forget_grant($g1) === 1, 'once nothing is building, the site lets the grant go');
$after = new CustomerCloudAccount((int)$gacct->key, TRUE);
check(trim((string)$after->get('cca_access_token')) === '' && (string)$after->get('cca_status') === CustomerCloudAccount::STATUS_RELEASED
	&& CustomerCloudAccount::live_for_build((int)$seller->key) === null,
	'the token is gone and the account reads released, not connected');
$after->set('cca_access_token', 'sealed-wider-token');
$after->set('cca_scopes', 'linodes:read_write ips:read_write firewall:read_only');
$after->set('cca_status', 'active');
$after->save();
check($leg->forget_grant($g1) === 0, 'a site already let go is never looked at again');
$g3 = $mk_done('g3');
$leg->forget_grant($g3);
check(trim((string)(new CustomerCloudAccount((int)$gacct->key, TRUE))->get('cca_access_token')) === 'sealed-wider-token',
	'a wider grant the buyer gave for something else is never let go by a build');

// ---------------------------------------------------------------------------
section('After the build: the bundle\'s rows, even when the mail leg failed (B3, B4, B5)');

$mk_node = function ($tag) use ($run) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest own-linode ' . $tag . ' ' . $run);
	$n->set('mgn_slug', 'ol-node-' . $tag . '-' . $run);
	$n->set('mgn_host', '203.0.113.' . random_int(2, 250));
	$n->set('mgn_uptime_enabled', false);
	$n->save();
	$n->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return $n;
};
$mk_line = function ($status, $end) use ($buyer, $bundle, $bundle_version) {
	list(, $line) = ol_paid_line($buyer, $bundle, $bundle_version, array());
	$line->set('odi_is_subscription', true);
	$line->set('odi_subscription_status', $status);
	$line->set('odi_subscription_period_end', $end);
	$line->save();
	return $line;
};
$future = gmdate('Y-m-d H:i:s', time() + 20 * 86400);
$line = $mk_line('active', $future);
check(ProvisionOwnLinode::paid_until((int)$line->key) === $future, 'a paying line pays the rows to its period end');
$gone = $mk_line('canceled', $future);
$gone->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
$gone->save();
check(ProvisionOwnLinode::paid_until((int)$gone->key) <= gmdate('Y-m-d H:i:s'), 'a cancelled line pays nothing further');

$n1 = $mk_node('m');
$s1 = $mk_done('s1');
$s1->set('cvp_mgn_managed_node_id', $n1->key);
$s1->set('cvp_services_order_item_id', $line->key);
$s1->set('cvp_mail_state', 'done');
$s1->set('cvp_smtp2go_subaccount_id', 'sub-' . $run);
$s1->save();
$leg->link_services($s1);
$mail = ServiceTenant::forNode((int)$n1->key, ServiceTenant::SERVICE_MAIL);
$shelf = ServiceTenant::forNode((int)$n1->key, ServiceTenant::SERVICE_SHELF);
foreach (array($mail, $shelf) as $row) {
	if ($row) { harness_register_row('svt_service_tenants', 'svt_service_tenant_id', $row->key); }
}
check($mail !== null && $shelf !== null, 'with mail built, both node-linked rows are made');
check($mail && (int)$mail->get('svt_odi_order_item_id') === (int)$line->key && (string)$mail->get('svt_paid_until') === $future
	&& (int)$shelf->get('svt_odi_order_item_id') === (int)$line->key,
	'they follow the bundle line, paid to its period end', $mail ? (string)$mail->get('svt_paid_until') : '');
check($mail && (string)$mail->get('svt_mail_state') === ServiceTenant::MAIL_DOMAIN_ADDED,
	'the mail row is left for the services reconcile to verify (B5)');

// The services reconcile asks the provider for a node-linked row at
// domain_added, and records the answer (reviewer2 B5 re-read).
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ServiceTenantWatch.php'));
// A client on an in-memory handler: nothing reaches the network. Every
// call answers that the domain verifies; domain/verify calls are counted.
$asked = 0;
$handler = function ($request) use (&$asked) {
	if (strpos((string)$request->getUri()->getPath(), 'domain/verify') !== false) {
		$asked++;
	}
	$asked_for = (string)(json_decode((string)$request->getBody(), true)['domain'] ?? '');
	$body = array('data' => array('domains' => array(array('domain' => array(
		'fulldomain' => $asked_for, 'dkim_verified' => true, 'rpath_verified' => true)))));
	return new \GuzzleHttp\Promise\FulfilledPromise(new \GuzzleHttp\Psr7\Response(200, array(), json_encode($body)));
};
$watch = new ServiceTenantWatch();
$watch->client = new Smtp2GoClient('harness-master-key', new \GuzzleHttp\Client(array('handler' => $handler)));
if ($mail) {
	try {
		$watch->watch($mail);
	} catch (Smtp2GoException $e) {
		// The send-count meter later in the pass wants a reply this handler does not give.
	}
	$mail->load();
}
check($asked >= 1 && $mail && (string)$mail->get('svt_mail_state') === ServiceTenant::MAIL_DOMAIN_VERIFIED,
	'the services reconcile asks the provider and records the domain verified', $mail ? (string)$mail->get('svt_mail_state') : '');

$n2 = $mk_node('f');
$s2 = $mk_done('s2');
$s2->set('cvp_mgn_managed_node_id', $n2->key);
$s2->set('cvp_services_order_item_id', $line->key);
$s2->set('cvp_mail_state', 'failed');
$s2->set('cvp_mail_error', 'test failure');
$s2->save();
$leg->link_services($s2);
$fshelf = ServiceTenant::forNode((int)$n2->key, ServiceTenant::SERVICE_SHELF);
if ($fshelf) { harness_register_row('svt_service_tenants', 'svt_service_tenant_id', $fshelf->key); }
check($fshelf !== null && ServiceTenant::forNode((int)$n2->key, ServiceTenant::SERVICE_MAIL) === null,
	'a failed mail leg still gets its backups row, and no mail row');

harness_finish();
