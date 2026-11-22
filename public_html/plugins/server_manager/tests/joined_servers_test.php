<?php
/** @joinery-test
 * name: joined_servers
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/server_manager/includes/JoinedServers.php, plugins/server_manager/includes/AgentChannelEndpoint.php]
 */
/**
 * A server its owner already runs is added from their own account
 * (walkthrough Q36): the join request their site filed is claimed by the
 * signed-in account, approved at once for an account that pays us and left
 * for a person otherwise; the node it becomes is listed under its owner, who
 * can let it go again.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$run = bin2hex(random_bytes(4));

function jsv_request(string $name, string $ip) {
	$key = base64_encode(random_bytes(32));
	$join = new AgentJoinRequest(NULL);
	$join->set('ajr_claimed_name', $name);
	$join->set('ajr_public_key', $key);
	$join->set('ajr_fingerprint', AgentJoinRequest::fingerprint($key));
	$join->set('ajr_source_ip', $ip);
	$join->set('ajr_status', AgentJoinRequest::STATUS_PENDING);
	$join->save();
	$join->load();
	harness_register_row('ajr_agent_join_requests', 'ajr_agent_join_request_id', $join->key);
	return $join;
}

function jsv_subscriber($user) {
	$order = new Order(NULL);
	$order->set('ord_usr_user_id', $user->key);
	$order->save(); $order->load();
	harness_register_row('ord_orders', 'ord_order_id', $order->key);
	$line = new OrderItem(NULL);
	$line->set('odi_ord_order_id', $order->key);
	$line->set('odi_usr_user_id', $user->key);
	$line->set('odi_pro_product_id', 999999003);
	$line->set('odi_price', '9.99');
	$line->set('odi_status', OrderItem::STATUS_PAID);
	$line->set('odi_is_subscription', true);
	$line->set('odi_subscription_status', 'active');
	$line->save(); $line->load();
	harness_register_row('odi_order_items', 'odi_order_item_id', $line->key);
	return $line;
}

section('The address a buyer types becomes their own site\'s Management Node page');
$own = rtrim((string)LibraryFunctions::get_absolute_url(), '/');
check(JoinedServers::site_connect_url('Example.com') === 'https://example.com/admin/admin_management_node?connect_to=' . rawurlencode($own),
	'a bare name', JoinedServers::site_connect_url('Example.com'));
check(JoinedServers::site_connect_url('https://my.site.example.org/some/page?x=1')
	=== 'https://my.site.example.org/admin/admin_management_node?connect_to=' . rawurlencode($own), 'a pasted address keeps only its host');
check(JoinedServers::site_connect_url('not a site') === '' && JoinedServers::site_connect_url('localhost') === ''
	&& JoinedServers::site_connect_url('') === '', 'what is not a site address is refused');
check(JoinedServers::site_connect_url((string)parse_url($own, PHP_URL_HOST)) === '', 'this management node itself is refused');

section('An account that does not pay us: claimed, and left for a person');
$free = make_user('JoinedFree');
$paying = make_user('JoinedPaying');
jsv_subscriber($paying);
check(!JoinedServers::pays_us((int)$free->key) && JoinedServers::pays_us((int)$paying->key), 'a current paid subscription is what paying means');
$waiting = jsv_request('jsv-free-' . $run . '.example.com', '198.51.100.' . random_int(2, 120));
check(JoinedServers::claim('0000000000000000', $free)['state'] === JoinedServers::STATE_NOT_FOUND, 'an unknown fingerprint finds nothing');
$result = JoinedServers::claim((string)$waiting->get('ajr_fingerprint'), $free);
$waiting->load();
check($result['state'] === JoinedServers::STATE_WAITING && (int)$waiting->get('ajr_usr_user_id') === (int)$free->key
	&& $waiting->get('ajr_status') === AgentJoinRequest::STATUS_PENDING, 'the request is theirs and still pending', json_encode($result['state']));
check(JoinedServers::waiting_for_user((int)$free->key) === array('jsv-free-' . $run . '.example.com'), 'and their page can say it waits');
check(JoinedServers::claim((string)$waiting->get('ajr_fingerprint'), $paying)['state'] === JoinedServers::STATE_TAKEN,
	'another account cannot take a request already claimed');

section('A person approves it: the node is the claimer\'s');
$_SESSION['permission'] = 10;
$adopted = AgentChannelEndpoint::adoptJoin($waiting);
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $adopted['node']->key);
$node = new ManagedNode((int)$adopted['node']->key, TRUE);
check((int)$node->get('mgn_owner_usr_user_id') === (int)$free->key, 'the approved node carries its owner');
check(count(JoinedServers::for_user((int)$free->key)) === 1 && JoinedServers::waiting_for_user((int)$free->key) === array(),
	'it is listed for them, and no longer waits');

section('An account that pays us: approved at once');
$instant = jsv_request('jsv-paid-' . $run . '.example.com', '198.51.100.' . random_int(130, 250));
$result = JoinedServers::claim((string)$instant->get('ajr_fingerprint'), $paying);
if ($result['node']) { harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $result['node']->key); }
$instant->load();
check($result['state'] === JoinedServers::STATE_APPROVED && $instant->get('ajr_status') === AgentJoinRequest::STATUS_APPROVED
	&& (string)$instant->get('ajr_decided_by') === 'owner', 'the request is approved by its owner\'s claim', json_encode($result['state']));
$mine = JoinedServers::for_user((int)$paying->key);
check(count($mine) === 1 && trim((string)$mine[0]->get('mgn_agent_public_key')) !== '', 'and the node is theirs, with its agent paired');

section('The owner lets it go');
$threw = false;
try { JoinedServers::let_go($mine[0], (int)$free->key); } catch (RuntimeException $e) { $threw = true; }
check($threw, 'nobody else can let go of it');
// A bundle bought for the server's site goes with it (walkthrough Q37).
check(JoinedServers::bundle_order_item_id($mine[0]) === 0, 'a server whose site has no bundle has none');
$bundle = jsv_subscriber($paying);
$site_host = 'jsv-' . $run . '.example.com';
$tenant = new ServiceTenant(NULL);
$tenant->set('svt_usr_user_id', (int)$paying->key);
$tenant->set('svt_service', ServiceTenant::SERVICE_MAIL);
$tenant->set('svt_state', ServiceTenant::STATE_ACTIVE);
$tenant->set('svt_paid_until', '2036-01-01 00:00:00');
$tenant->set('svt_slug', 'jsv' . preg_replace('/[^a-z0-9]/', '', strtolower((string)$run)));
$tenant->set('svt_host', $site_host);
$tenant->set('svt_odi_order_item_id', (int)$bundle->key);
$tenant->save();
harness_register_row('svt_service_tenants', 'svt_service_tenant_id', $tenant->key);
$mine[0]->set('mgn_site_url', 'https://' . $site_host);
$mine[0]->save();
check(JoinedServers::bundle_order_item_id($mine[0]) === (int)$bundle->key, 'the bundle bought for its site\'s address is found');
// The cancel comes first: this line is linked to no payment provider, so the
// store refuses it, and nothing has been stopped.
$refused = false;
try { JoinedServers::let_go($mine[0], (int)$paying->key); } catch (Throwable $e) { $refused = true; }
$still = new ManagedNode((int)$mine[0]->key, TRUE);
check($refused && $still->get('mgn_enabled') && (int)$still->get('mgn_owner_usr_user_id') === (int)$paying->key
	&& trim((string)$still->get('mgn_agent_public_key')) !== '',
	'a bundle the store cannot cancel stops the let-go before anything is changed');
$bundle->set('odi_subscription_cancel_at_period_end', true);
$bundle->save();
$let_go = JoinedServers::let_go($mine[0], (int)$paying->key);
check($let_go['bundle_cancelled'] === false, 'a bundle already ending is left as it is');
$gone = new ManagedNode((int)$mine[0]->key, TRUE);
check(JoinedServers::for_user((int)$paying->key) === array() && !$gone->get('mgn_enabled')
	&& trim((string)$gone->get('mgn_agent_public_key')) === '', 'it leaves their page, its agent forgotten and the node disabled');

section('As the signed-in owner, on the rule the page checks');
$again = jsv_request('jsv-again-' . $run . '.example.com', '198.51.100.' . random_int(2, 250));
$_SESSION['loggedin'] = 1; $_SESSION['usr_user_id'] = (int)$paying->key; $_SESSION['permission'] = 0;
$_SERVER['REQUEST_METHOD'] = 'POST';
$fp = (string)$again->get('ajr_fingerprint');
$result = SystemBase::rule_checked_write(function () use ($fp, $paying) { return JoinedServers::claim($fp, $paying); });
if ($result['node']) { harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $result['node']->key); }
check($result['state'] === JoinedServers::STATE_APPROVED, 'a member\'s claim is written on the page\'s rule', json_encode($result['state']));
$node_again = $result['node'];
SystemBase::rule_checked_write(function () use ($node_again, $paying) { JoinedServers::let_go(new ManagedNode((int)$node_again->key, TRUE), (int)$paying->key); });
check(JoinedServers::for_user((int)$paying->key) === array(), 'and so is letting it go');
unset($_SERVER['REQUEST_METHOD']);
$_SESSION = array();

harness_finish();
