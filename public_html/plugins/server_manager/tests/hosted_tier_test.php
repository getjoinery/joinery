<?php
/** @joinery-test
 * name: hosted_tier
 * tier: db
 * env: any
 * needs: []
 */
/**
 * The hosted tier: a site the operator runs, pays for and can switch off.
 *
 * Five properties carry the feature, and each of them is one somebody could
 * plausibly break later while the thing still looked like it worked:
 *
 *  - **A provisioned site is one its buyer can log into.** The install job
 *    names the buyer's address and declares that its session's stdin carries
 *    the admin password. The password is NOT in the stored steps: mjb_commands
 *    is readable on this plane and job output is logged, so the one place it
 *    may travel is a pipe (B1).
 *  - **Whose account the server is born on is the PRODUCT's decision.** The
 *    fulfillment reference decides it; no buyer chooses, and a hosted
 *    provision skips the Connect wait entirely because there is nothing to
 *    connect.
 *  - **The reveal is once.** Showing the first password erases it in the same
 *    request. A first password that stays readable is a permanent second key.
 *  - **The grace clock is set by the store's own signals, and acting on it is
 *    somebody else's job.** A failed payment moves dates; it never powers a
 *    machine off from inside a webhook. A second failure inside the same grace
 *    does not extend it, or a card that never works buys unlimited hosting.
 *  - **A converge carries only what the node declared it accepts.** The
 *    builder's bound is the shape; the ALLOWLIST is the node's own
 *    declarations, and the plane deliberately keeps no second copy of it.
 *  - **A test site mails only us.** Its trap travels with its credential, only
 *    to an agent that takes it, and the site writes it before the credential.
 *
 * Everything runs inside one transaction that is rolled back, so no fixture
 * row is ever visible to the live provisioning worker.
 *
 * Run: php plugins/server_manager/tests/hosted_tier_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trials_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/HostedTrialSignals.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/HostedTrialWatch.php'));

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);

/** A node with an agent new enough to carry the settings writer. */
function ht_node($slug) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest hosted ' . $slug);
	$node->set('mgn_slug', $slug);
	$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_web_root', '/var/www/html/' . $slug . '/public_html');
	$node->set('mgn_container_name', $slug);
	$node->set('mgn_port', 8080);
	$node->set('mgn_uptime_enabled', false);
	$node->set('mgn_agent_public_key', 'ht-agent-' . $slug);
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$node->set('mgn_agent_primitives', 'hosted_mail_settings,hosted_plan_notice,backup_run');
	$node->save();
	$node->load();
	return $node;
}

function ht_provision($slug, $mode, array $extra = array()) {
	$provision = new CustomerCloudProvision(NULL);
	$provision->set('cvp_origin', 'admin');
	$provision->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
	$provision->set('cvp_domain', $slug . '.example.com');
	$provision->set('cvp_slug', $slug);
	$provision->set('cvp_hosting_mode', $mode);
	$provision->set('cvp_buyer_email', $slug . '@example.com');
	foreach ($extra as $k => $v) { $provision->set($k, $v); }
	$provision->save();
	$provision->load();
	return $provision;
}

// ---------------------------------------------------------------------------
section('B1: the bootstrap names the buyer and carries their password on stdin');

$node = ht_node('httest-b1-' . $suffix);
$steps = JobCommandBuilder::build_install_node($node, array(
	'mode'        => 'fresh',
	'sitename'    => 'httestb1',
	'domain'      => 'httest-b1.example.com',
	'docker_mode' => 'docker',
	'admin_email' => 'buyer@example.com',
	'admin_password_stdin' => true,
));
$ssh = null;
foreach ($steps as $step) { if (($step['type'] ?? '') === 'ssh') { $ssh = $step; break; } }
check($ssh !== null, 'the bootstrap is one ssh session');
check(strpos($ssh['cmd'], "--admin-email='buyer@example.com'") !== false,
	'the site install line names the buyer\'s address, so the admin account is theirs');
check(strpos($ssh['cmd'], 'IFS= read -r JOINERY_ADMIN_PASSWORD') !== false,
	'the session reads the admin password from its own stdin');
check(strpos($ssh['cmd'], 'export JOINERY_ADMIN_PASSWORD_SHOWN=1') !== false,
	'and marks it as the plane\'s, shown on the buyer\'s page, so the site asks for a new one at first sign-in');
check(strpos($ssh['cmd'], 'test -n "$JOINERY_ADMIN_PASSWORD"') !== false,
	'and refuses to continue without one — a missing password must fail loudly, '
	. 'not fall back to one nobody holds');
check(($ssh['stdin'] ?? null) === array('admin_password'),
	'the step names WHAT it needs on stdin, so the executor looks it up', json_encode($ssh['stdin'] ?? null));

// The whole point: nothing in the stored job is the password itself.
$whole = json_encode($steps);
check(strpos($whole, 'JOINERY_ADMIN_PASSWORD=') === false,
	'no step assigns the password inline — mjb_commands is readable on this plane');

// Without the flag, no read line at all: a bring-your-own install that has no
// password sealed must not ask for one and hang.
$plain = JobCommandBuilder::build_install_node($node, array(
	'mode' => 'fresh', 'sitename' => 'httestb1', 'domain' => 'httest-b1.example.com',
	'docker_mode' => 'docker', 'admin_email' => 'buyer@example.com',
));
check(strpos(json_encode($plain), 'JOINERY_ADMIN_PASSWORD') === false,
	'an install with no sealed password never asks stdin for one');
check(strpos(json_encode($plain), "--admin-email='buyer@example.com'") !== false,
	'but still names the buyer — the address is owed on every path');

$bad = null;
try { JobCommandBuilder::build_install_node($node, array('mode' => 'fresh', 'sitename' => 'httestb1',
	'domain' => 'httest-b1.example.com', 'docker_mode' => 'docker', 'admin_email' => 'not an address')); }
catch (Exception $e) { $bad = $e->getMessage(); }
check($bad !== null && strpos($bad, 'not an address') !== false,
	'a malformed address is refused here, not handed to install.sh', (string)$bad);

// ---------------------------------------------------------------------------
section('The product decides whose account the server is born on');

$provider = new CustomerCloudFulfillment();
$options = $provider->options();
check(count($options) === 3, 'the picker offers exactly three references', implode(' | ', $options));
check(CustomerCloudFulfillment::mode_for_ref(0) === 'customer',
	'reference 0 is the buyer\'s own cloud account — today\'s product, unchanged');
check(CustomerCloudFulfillment::mode_for_ref(1) === 'operator',
	'reference 1 is the operator\'s account');
check(CustomerCloudFulfillment::mode_for_ref(2) === 'operator'
		&& CustomerCloudFulfillment::landing_for_ref(2) === 'container'
		&& CustomerCloudFulfillment::landing_for_ref(1) === 'instance',
	'reference 2 is Starter: the operator\'s, in a container on a shared host');
check(CustomerCloudFulfillment::mode_for_ref(7) === 'customer',
	'an unrecognised reference falls back to the buyer\'s own account, never to ours');

$hosted = ht_provision('httest-mode-' . $suffix, 'operator');
check($hosted->is_operator_hosted(), 'a hosted provision knows it');
$byo = ht_provision('httest-byo-' . $suffix, 'customer');
check(!$byo->is_operator_hosted(), 'and a bring-your-own one knows it is not');

$refused = null;
try { ht_provision('httest-bad-' . $suffix, 'somebody_else'); }
catch (CustomerCloudProvisionException $e) { $refused = $e->getMessage(); }
check($refused !== null, 'an unknown hosting mode is refused rather than stored', (string)$refused);

// ---------------------------------------------------------------------------
section('The first admin password is revealed once, then gone');

$pw = ProvisionCustomerCloud::mint_admin_password();
check(strlen($pw) >= 16 && preg_match('/[A-Z]/', $pw) && preg_match('/[a-z]/', $pw)
	&& preg_match('/[0-9]/', $pw),
	'the minted password would pass an ordinary complexity rule, so the install never argues with one');

$reveal = ht_provision('httest-reveal-' . $suffix, 'operator', array(
	'cvp_admin_pass_sealed' => (new SecretBox())->seal(
		'cvp_customer_cloud_provisions.cvp_admin_pass_sealed', $pw),
));
check($reveal->admin_password_state() === 'sealed', 'before the reveal it is readable');
check(CustomerCloudProvision::holds_admin_password($reveal->get('cvp_mgn_managed_node_id')) === false,
	'holds_admin_password is answered per NODE, and this row names none yet');

// What the page does, in the same request that shows it.
$opened = (new SecretBox())->open((string)$reveal->get('cvp_admin_pass_sealed'));
check($opened['state'] === 'ok' && $opened['value'] === $pw, 'the sealed value reads back');
$reveal->set('cvp_admin_pass_sealed', null);
$reveal->set('cvp_admin_pass_revealed_time', gmdate('Y-m-d H:i:s'));
$reveal->save();
$reveal->load();
check($reveal->admin_password_state() === 'revealed',
	'after the reveal the row remembers that it happened and holds nothing');
check(trim((string)$reveal->get('cvp_admin_pass_sealed')) === '',
	'and there is no copy left to show a second time');

// ---------------------------------------------------------------------------
section('The grace clock moves dates and never touches a machine');

// A real paid line on a site bought here: a signal acts only when the row's
// line is one this site's store sold (hosting_after_purchase B14).
$grace_buyer = make_user('HtGrace' . $suffix);
$grace_order = new Order(NULL);
$grace_order->set('ord_usr_user_id', $grace_buyer->key);
$grace_order->save();
$grace_order->load();
harness_register_row('ord_orders', 'ord_order_id', $grace_order->key);
$grace_item = new OrderItem(NULL);
$grace_item->set('odi_ord_order_id', $grace_order->key);
$grace_item->set('odi_pro_product_id', 999999002);
$grace_item->set('odi_usr_user_id', $grace_buyer->key);
$grace_item->set('odi_price', '12.99');
$grace_item->set('odi_is_subscription', true);
$grace_item->set('odi_subscription_status', 'active');
$grace_item->save();
$grace_item->load();
harness_register_row('odi_order_items', 'odi_order_item_id', $grace_item->key);
$graced = ht_provision('httest-grace-' . $suffix, 'operator', array('cvp_origin' => 'buyer',
	'cvp_external_order_item_id' => $grace_item->key));
$trial = new HostedTrial(NULL);
$trial->set('htr_cvp_customer_cloud_provision_id', (int)$graced->key);
$trial->set('htr_external_order_item_id', $grace_item->key);
$trial->set('htr_state', HostedTrial::STATE_SUBSCRIBED);
$trial->save();
$trial->load();

$order_item_id = (int)$trial->get('htr_external_order_item_id');
HostedTrialSignals::handle_signal('subscription.payment_failed',
	array('order_item_id' => $order_item_id));
$trial->load();
check((string)$trial->get('htr_state') === HostedTrial::STATE_GRACE,
	'a failed payment starts the grace period');
$first_deadline = (string)$trial->get('htr_grace_ends_time');
check($first_deadline !== '' && strtotime($first_deadline . ' UTC') > time(),
	'with a deadline in the future', $first_deadline);
check(trim((string)$trial->get('htr_shelf_ends_time')) !== '',
	'and backup storage date set from the SAME moment, so "kept ninety days" is countable '
	. 'from the day they stopped paying');
check(trim((string)$trial->get('htr_shutdown_time')) === '',
	'nothing was shut down: a webhook moves dates, it does not power off machines');

// A second failure inside the same grace must not buy more time.
sleep(1);
HostedTrialSignals::handle_signal('subscription.payment_failed',
	array('order_item_id' => $order_item_id));
$trial->load();
check((string)$trial->get('htr_grace_ends_time') === $first_deadline,
	'a second failed retry does not extend the deadline — a card that never works '
	. 'would otherwise buy unlimited hosting');

HostedTrialSignals::handle_signal('subscription.payment_recovered',
	array('order_item_id' => $order_item_id));
$trial->load();
check((string)$trial->get('htr_state') === HostedTrial::STATE_SUBSCRIBED,
	'a recovered payment clears the grace');
check(trim((string)$trial->get('htr_grace_ends_time')) === ''
	&& trim((string)$trial->get('htr_shelf_ends_time')) === '',
	'and both deadlines with it — nothing is left pending');

// A signal for somebody else's subscription must find nothing here.
HostedTrialSignals::handle_signal('subscription.payment_failed',
	array('order_item_id' => 0));
$trial->load();
check((string)$trial->get('htr_state') === HostedTrial::STATE_SUBSCRIBED,
	'a subscription that is not hosting passes straight through');

// ---------------------------------------------------------------------------
section('No primitive can NAME a setting — the mail credentials least of all');

// THE assertion of this whole feature. A general settings writer was built and
// removed: a primitive that took a name and a value would let this plane write
// any row in stg_settings on any node it manages, and the mail rows are the
// ones that matter — a site whose outbound mail can be redirected is a site
// whose password-reset email can be redirected.
$mail_built = JobCommandBuilder::build_hosted_mail_settings($node, array(
	'service' => 'smtp', 'host' => 'mail.smtp2go.com', 'port' => 2525,
	'username' => 'site-abc123', 'password' => 'a password with spaces',
	'sender' => 'bounces@example.com', 'helo' => 'example.com',
	'hostname' => 'example.com',
));
check(($mail_built['primitive'] ?? '') === 'hosted_mail_settings', 'the mail primitive builds');
$mail_keys = array_keys($mail_built['params']);
sort($mail_keys);
check($mail_keys === array('helo', 'host', 'hostname', 'password', 'port', 'sender', 'service', 'username'),
	'it carries exactly eight values and no setting name', implode(',', $mail_keys));
foreach (array('setting', 'settings', 'name', 'key', 'smtp_host', 'email_service') as $forbidden) {
	check(!array_key_exists($forbidden, $mail_built['params']),
		"'{$forbidden}' is not a parameter — the names live in the node-side script");
}
check($mail_built['params']['password'] === 'a password with spaces',
	'a minted password survives verbatim — altering a credential produces an unexplainable failure');

$notice_built = JobCommandBuilder::build_hosted_plan_notice($node, array(
	'state' => 'trial', 'until_time' => '2026-11-05 00:00:00', 'notice' => '',
	'allowances' => '[]', 'manage_url' => 'https://example.com/profile/server_manager',
));
check(($notice_built['primitive'] ?? '') === 'hosted_plan_notice', 'the banner primitive builds');
$notice_keys = array_keys($notice_built['params']);
sort($notice_keys);
check($notice_keys === array('allowances', 'manage_url', 'notice', 'state', 'until_time'),
	'and carries exactly five values', implode(',', $notice_keys));
check(array_key_exists('password', $notice_built['params']) === false
	&& array_key_exists('username', $notice_built['params']) === false,
	'the banner cannot carry a credential — the two must not share a doorway');

// Neither builder exists as a general one any more.
check(!method_exists('JobCommandBuilder', 'build_settings_converge'),
	'there is no general settings writer to reach for');

// Bounds, refused at this end so a bad value fails where the row can be seen.
foreach (array(
	array('service' => 'sendmail'),
	array('service' => 'smtp', 'host' => ''),
	array('service' => 'smtp', 'host' => 'not a host'),
	array('service' => 'smtp', 'host' => 'mail.x.com', 'port' => 99999),
	array('service' => 'smtp', 'host' => 'mail.x.com', 'sender' => 'not an address'),
	array('service' => 'smtp', 'host' => 'mail.x.com', 'password' => "two\nlines"),
) as $bad) {
	$err = null;
	try { JobCommandBuilder::build_hosted_mail_settings($node, $bad); }
	catch (Exception $e) { $err = $e->getMessage(); }
	check($err !== null, 'mail settings refused: ' . json_encode($bad), (string)$err);
}
foreach (array(
	array('state' => 'suspended'),
	array('state' => 'trial', 'until_time' => 'soon'),
	array('state' => 'trial', 'manage_url' => 'http://example.com'),
	array('state' => 'trial', 'notice' => "two\nlines"),
) as $bad) {
	$err = null;
	try { JobCommandBuilder::build_hosted_plan_notice($node, $bad); }
	catch (Exception $e) { $err = $e->getMessage(); }
	check($err !== null, 'banner refused: ' . json_encode($bad), (string)$err);
}

// Empty is a real value on both, and is what CLEARS a setting: it is how a site
// is handed back to its owner's own mail account, and how a box returns to
// silence when its hosting ends.
$cleared = JobCommandBuilder::build_hosted_mail_settings($node, array('service' => ''));
check($cleared['params']['service'] === '' && $cleared['params']['host'] === '',
	'an empty mail push is well-formed — that is how a credential is retired');
$silent = JobCommandBuilder::build_hosted_plan_notice($node, array('state' => ''));
check($silent['params']['state'] === '', 'and an empty state is what renders no banner at all');

// ---------------------------------------------------------------------------
section('A test site\'s mail goes to one address of ours');

// The trap is the ninth value, for a site built in a test cloud account. An
// agent refuses a key it does not declare, so it travels only when there is
// one, and only to an agent that takes it: a real site's push is unchanged.
check(!array_key_exists('test_recipient', $mail_built['params']),
	'a push with no trap carries the eight values it always has');
$trap_node = ht_node('httest-trap-' . $suffix);
$trap_node->set('mgn_agent_version', JobCommandBuilder::HOSTED_MAIL_TRAP_AGENT);
$trap_node->save();
$trapped = JobCommandBuilder::build_hosted_mail_settings($trap_node, array(
	'service' => 'smtp', 'host' => 'mail.smtp2go.com', 'port' => 2525,
	'test_recipient' => 'joineryemailtests@dev.getjoinery.com'));
check(($trapped['params']['test_recipient'] ?? '') === 'joineryemailtests@dev.getjoinery.com',
	'an agent that takes it is sent the trap with the credential');
$err = null;
try {
	JobCommandBuilder::build_hosted_mail_settings($node, array(
		'service' => 'smtp', 'host' => 'mail.smtp2go.com', 'test_recipient' => 'joineryemailtests@dev.getjoinery.com'));
} catch (Exception $e) { $err = $e->getMessage(); }
check($err !== null && strpos($err, JobCommandBuilder::HOSTED_MAIL_TRAP_AGENT) !== false,
	'an older agent is refused rather than given a credential that would mail the people a rehearsal names', (string)$err);
$err = null;
try {
	JobCommandBuilder::build_hosted_mail_settings($trap_node, array('service' => 'smtp', 'host' => 'mail.smtp2go.com',
		'test_recipient' => "a@example.com\nBcc: b@example.com"));
} catch (Exception $e) { $err = $e->getMessage(); }
check($err !== null, 'a trap that is not one address is refused', (string)$err);

// B7: the provider's DKIM key name, read from its records, goes to an agent
// that takes it; an older agent's push is the same as before.
$key_prov = new CustomerCloudProvision(NULL);
$key_prov->set('cvp_mail_records', json_encode(array(
	array('name' => 'em1217236.st1.example.com', 'type' => 'CNAME', 'value' => 'return.smtp2go.net'),
	array('name' => 's1217236._domainkey.st1.example.com', 'type' => 'CNAME', 'value' => 'dkim.smtp2go.net'))));
check(ProvisionHostedMail::dkim_key_name($key_prov) === 's1217236', 'the DKIM key name is read from the provider\'s records');
$key_prov->set('cvp_mail_records', json_encode(array()));
check(ProvisionHostedMail::dkim_key_name($key_prov) === '', 'no DKIM record, no key name');
// B4: a hosted site's domain is offered a monitoring DMARC record while it has none.
require_once(PathHelper::getIncludePath('tests/lib/dns_fixtures.php'));
DnsResolver::setBackend(new FakeDnsBackend(array(
	'_dmarc.has.example.com|' . DNS_TXT  => array(array('txt' => 'v=DMARC1; p=reject')),
	'_dmarc.none.example.com|' . DNS_TXT => array(),
	'_dmarc.down.example.com|' . DNS_TXT => false,
	'_dmarc.parent.test|' . DNS_TXT      => array(array('txt' => 'v=DMARC1; p=quarantine')),
)));
$dm = ProvisionHostedMail::dmarc_record('None.Example.com ');
check($dm['type'] === 'TXT' && $dm['name'] === '_dmarc.none.example.com' && $dm['value'] === 'v=DMARC1; p=none'
	&& strpos($dm['note'], 'only if your domain has no DMARC record') !== false, 'the DMARC record offered is monitoring only, with its condition');
check(ProvisionHostedMail::has_dmarc('has.example.com') && !ProvisionHostedMail::has_dmarc('none.example.com')
	&& !ProvisionHostedMail::has_dmarc('down.example.com'), 'a domain with its own DMARC record is left alone; none, or an unanswered lookup, is offered one');
check(ProvisionHostedMail::has_dmarc('shop.parent.test'), 'a subdomain under a parent DMARC record is left alone: a record of its own would replace the parent policy there');
// The zone we hold gets the DMARC record in a plan of its own, which can never fail the mail leg.
require_once(PathHelper::getIncludePath('tests/dns/fixtures/FakeDnsDriver.php'));
$fake = new FakeDnsDriver(array('api_token' => 'unused'));
$fake->seed('none.example.com', array(new DnsRecord('A', 'none.example.com', '192.0.2.9')));
$added = ProvisionHostedMail::publish_dmarc($fake, 'none.example.com');
check($added && count($fake->writes) === 1 && strpos($fake->writes[0], 'create') === 0
		&& strpos($fake->writes[0], '_dmarc.none.example.com') !== false && strpos($fake->writes[0], 'v=DMARC1; p=none') !== false,
	'a zone we hold with no DMARC record gets one', implode(' | ', $fake->writes));
$fake2 = new FakeDnsDriver(array('api_token' => 'unused'));
$fake2->seed('none.example.com', array(new DnsRecord('TXT', '_dmarc.none.example.com', 'v=DMARC1; p=reject')));
ProvisionHostedMail::publish_dmarc($fake2, 'none.example.com');
check($fake2->writes === array(), 'a DMARC record of another value already in the zone is left alone, never doubled', implode(' | ', $fake2->writes));
$fake3 = new FakeDnsDriver(array('api_token' => 'unused'));
$fake3->seed('none.example.com', array(new DnsRecord('A', 'none.example.com', '192.0.2.9')));
$fake3->fail_value = 'v=DMARC1; p=none';
$threw = false;
try { $ok3 = ProvisionHostedMail::publish_dmarc($fake3, 'none.example.com'); } catch (Throwable $e) { $threw = true; }
check(!$threw && $ok3 === false, 'a provider that refuses it is only logged: false, never an exception');
check(ProvisionHostedMail::publish_dmarc(new FakeDnsDriver(array('api_token' => 'unused')), 'has.example.com'), 'a domain whose DMARC record resolves is not touched');
DnsResolver::clearBackend();
$key_node = ht_node('httest-key-' . $suffix);
$key_node->set('mgn_agent_version', JobCommandBuilder::HOSTED_MAIL_KEY_NAME_AGENT);
$key_node->save();
$keyed = JobCommandBuilder::build_hosted_mail_settings($key_node, array(
	'service' => 'smtp', 'host' => 'mail.smtp2go.com', 'port' => 2525, 'dkim_key_name' => 's1217236'));
check(($keyed['params']['dkim_key_name'] ?? '') === 's1217236', 'an agent that takes it is sent the DKIM key name');
$unkeyed = JobCommandBuilder::build_hosted_mail_settings($trap_node, array(
	'service' => 'smtp', 'host' => 'mail.smtp2go.com', 'port' => 2525, 'dkim_key_name' => 's1217236'));
check(!array_key_exists('dkim_key_name', $unkeyed['params']), 'an older agent is not sent it, so its push still goes');
$err = null;
try {
	JobCommandBuilder::build_hosted_mail_settings($key_node, array('service' => 'smtp', 'host' => 'mail.smtp2go.com',
		'dkim_key_name' => 's1._domainkey.example.com'));
} catch (Exception $e) { $err = $e->getMessage(); }
check($err !== null, 'a key name that is not one DNS label is refused', (string)$err);

// The site's mail check tries the key name it was told first.
require_once(PathHelper::getIncludePath('includes/DnsAuthChecker.php'));
harness_set_setting_mem('smtp_dkim_key_name', 's1217236');
$assessed = DnsAuthChecker::assess('b7-' . $suffix . '.invalid', 'smtp');
check(($assessed['service_key_names'][0] ?? '') === 's1217236', 'the mail check looks first under the key name the plane sent',
	json_encode($assessed['service_key_names']));
harness_set_setting_mem('smtp_dkim_key_name', '');

// The site writes it: test mode on, to the trap. An empty one writes nothing,
// because test mode is also a switch an owner may set for themselves.
$was_mode = Globalvars::get_instance()->get_setting('email_test_mode', false, true);
Setting::put('email_test_mode', '');
Globalvars::get_instance()->forget_setting('email_test_mode');
check(HostedMailSettingsMap::testTrap('') === array()
	&& (string)Globalvars::get_instance()->get_setting('email_test_mode', false, true) === '',
	'an empty trap leaves the site\'s test mode alone');
$names = HostedMailSettingsMap::testTrap('joineryemailtests@dev.getjoinery.com');
check($names === array('email_test_recipient', 'email_test_mode')
	&& (string)Globalvars::get_instance()->get_setting('email_test_mode', false, true) === '1'
	&& (string)Globalvars::get_instance()->get_setting('email_test_recipient', false, true) === 'joineryemailtests@dev.getjoinery.com',
	'a trap turns on test mode, to that address', implode(',', $names));
$script_src = file_get_contents(PathHelper::getIncludePath('utils/hosted_mail_settings.php'));
check(strpos($script_src, 'testTrap') !== false
	&& strpos($script_src, 'testTrap') < strpos($script_src, 'HostedMailSettingsMap::apply($values)'),
	'the node-side script writes the trap before the credential');
check(strpos($script_src, 'test_trap=on') !== false, 'and says so, for the plane to read');
Setting::put('email_test_mode', (string)$was_mode);
Globalvars::get_instance()->forget_setting('email_test_mode');

// ---------------------------------------------------------------------------
section('The node-side scripts own the names, and refuse what they do not know');

foreach (array(
	array('utils/hosted_mail_settings.php', array('service' => 'nonsense'), 'HOSTED_MAIL_SETTINGS=error'),
	array('utils/hosted_mail_settings.php', array('service' => 'smtp', 'host' => 'mail.smtp2go.com',
		'dkim_key_name' => 's1._domainkey.example.com'), 'HOSTED_MAIL_SETTINGS=error'),
	array('utils/hosted_plan_notice.php',   array('state' => 'suspended'),  'HOSTED_PLAN_NOTICE=error'),
) as $case) {
	list($rel, $payload, $marker) = $case;
	$script = PathHelper::getIncludePath($rel);
	check(is_file($script), $rel . ' ships in the tree');
	$descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$proc = proc_open('php ' . escapeshellarg($script), $descriptors, $pipes);
	fwrite($pipes[0], json_encode($payload));
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	$code = proc_close($proc);
	check($code === 2, $rel . ' refuses a value it does not recognise', 'exit ' . $code);
	check(strpos($stderr, $marker) !== false, 'and says so on stderr', trim($stderr));
	check(strpos($stdout, '=ok') === false, 'writing nothing');
}

// ---------------------------------------------------------------------------
section('The two writers are two job types, so neither can read the other\'s answer');

// This was a real bug while both rode one general primitive: the mail leg read
// "the newest settings_converge", so a banner landing was taken as "the
// credentials arrived" and the SMTP settings were never sent. Splitting the
// general writer into two compiled-name primitives dissolved it — each caller's
// question is now about its own job type and cannot be answered by the other's.
check(ProvisionHostedMail::JOB_TYPE !== HostedTrialWatch::JOB_TYPE,
	'the mail credentials and the banner travel as different job types');

$shared = ht_node('httest-types-' . $suffix);
$mail_job = ManagementJob::createFromBuild($shared->key, ProvisionHostedMail::JOB_TYPE,
	JobCommandBuilder::build_hosted_mail_settings($shared,
		array('service' => 'smtp', 'host' => 'mail.smtp2go.com', 'port' => 2525)),
	array('provision_id' => 0), null);
$banner_job = ManagementJob::createFromBuild($shared->key, HostedTrialWatch::JOB_TYPE,
	JobCommandBuilder::build_hosted_plan_notice($shared, array('state' => 'trial')),
	array('provision_id' => 0), null);

check((int)ManagementJob::latestForNode($shared->key, ProvisionHostedMail::JOB_TYPE)->key === (int)$mail_job->key,
	'the mail leg finds its own newest job');
check((int)ManagementJob::latestForNode($shared->key, HostedTrialWatch::JOB_TYPE)->key === (int)$banner_job->key,
	'the banner finds its own — filed later, and it does not shadow the first');
check(!method_exists('ManagementJob', 'latestForNodeWithSubject'),
	'and the subject-scoped lookup that patched the shared type is gone with it');

// ---------------------------------------------------------------------------
section('A push the site took ends the run of attempts (B12)');

// The allowance stops a broken site being handed a fresh credential for ever.
// A push that landed is not part of that run: a later re-push (a new key name,
// a new credential) gets its full allowance.
$attempts_of = function ($node, $provision = null) {
	return (new ReflectionMethod('ProvisionHostedMail', 'push_attempts'))->invoke(new ProvisionHostedMail(), $node, $provision);
};
$push_node = ht_node('httest-attempts-' . $suffix);
$push_job = function ($status) use ($push_node) {
	$job = ManagementJob::createFromBuild($push_node->key, ProvisionHostedMail::JOB_TYPE,
		JobCommandBuilder::build_hosted_mail_settings($push_node,
			array('service' => 'smtp', 'host' => 'mail.smtp2go.com', 'port' => 2525)),
		array('provision_id' => 0), null);
	$job->set('mjb_status', $status);
	$job->save();
	return $job;
};
$push_job('failed');
$push_job('failed');
check($attempts_of($push_node) === 2, 'two failed pushes are two attempts');
$push_job('completed');
check($attempts_of($push_node) === 0, 'a push the site took starts the count again');
$push_job('failed');
check($attempts_of($push_node) === 1, 'and a failure after it is the first of a new run');

section('Retry email setup gives the push a fresh allowance (hosting_after_purchase B19)');
$push_job('failed');
$push_job('failed');
check($attempts_of($push_node) === 3, 'three failed pushes since the last one the site took');
$retried = new CustomerCloudProvision(NULL);
$retried->set('cvp_mail_retry_time', gmdate('Y-m-d H:i:s', time() + 1));
check($attempts_of($push_node, $retried) === 0, 'a retry pressed after them starts the count again');
$retried->set('cvp_mail_retry_time', gmdate('Y-m-d H:i:s', time() - 3600));
check($attempts_of($push_node, $retried) === 3, 'a retry pressed before them does not');

// ---------------------------------------------------------------------------
section('The banner is loudest about the thing that is actually urgent');

require_once(PathHelper::getIncludePath('includes/HostedPlanNotice.php'));

// A deployment nobody hosts renders nothing at all. That is what keeps every
// self-hosted install on earth silent, and it is the default.
check(HostedPlanNotice::state() === '' && !HostedPlanNotice::applies(),
	'a deployment with no hosting state renders no banner');
check(HostedPlanNotice::render() === '', 'and render() returns nothing rather than an empty box');

$quiet = array(array('label' => 'Sends', 'percent' => 12));
$near  = array(array('label' => 'Sends', 'percent' => 84));
$over  = array(array('label' => 'Sends', 'percent' => 103));

check(HostedPlanNotice::level('subscribed', 200, $quiet) === 'calm',
	'a paid subscription with room and no deadline is calm');
check(HostedPlanNotice::level('trial', 45, $quiet) === 'calm',
	'a trial six weeks out is calm — a countdown that starts on day one is noise');
check(HostedPlanNotice::level('trial', 5, $quiet) === 'soon',
	'a trial ending this week is not');
check(HostedPlanNotice::level('subscribed', 200, $near) === 'soon',
	'an allowance at 84% warns even when nothing is due');
check(HostedPlanNotice::level('subscribed', 200, $over) === 'urgent',
	'an allowance actually exceeded is urgent');
check(HostedPlanNotice::level('subscribed', 200, array(array('label' => 'Disk', 'percent' => 95))) === 'urgent'
	&& HostedPlanNotice::level('subscribed', 200, array(array('label' => 'Disk', 'percent' => 94))) === 'soon',
	'at 95% it urges: a site at its disk allowance stops taking uploads and mail');
check(HostedPlanNotice::level('grace', 20, $quiet) === 'soon',
	'a failed payment is never calm');
check(HostedPlanNotice::level('grace', 3, $quiet) === 'urgent',
	'and sharpens as the shutdown approaches');
check(HostedPlanNotice::level('shutdown', null, $quiet) === 'urgent',
	'a shutdown is urgent whatever the dates say');
check(HostedPlanNotice::level('subscribed', 200, $quiet, 'Offsite backups of this site are paused.') === 'soon',
	'an operator sentence is never calm — something has actually been done to this site');
check(HostedPlanNotice::level('subscribed', 200, $quiet, '') === 'calm',
	'and its absence leaves the billing state to decide');
check(!in_array('suspended', HostedPlanNotice::STATES, true),
	'sending health is not a billing state — the provider owns it, and the banner has no word for it');

// A hosted site with no proven recovery key has no backups at all: the
// management node's runs seal only to the site's own key. The banner says so
// as loudly as a lapsing payment, and never "there is nothing to do".
check(HostedPlanNotice::level('subscribed', 200, $quiet, '', true) === 'urgent'
	&& HostedPlanNotice::level('trial', 45, $quiet, '', true) === 'urgent',
	'no recovery key makes the banner urgent, whatever the billing state');
$wording = (new ReflectionClass('HostedPlanNotice'))->getMethod('wording');
check(strpos($wording->invoke(null, 'trial', 'on December 1, 2026', false)[1], 'nothing to do') !== false
	&& strpos($wording->invoke(null, 'trial', 'on December 1, 2026', true)[1], 'nothing to do') === false,
	'a trial says there is nothing to do only while the backups it includes are actually running');
check(!in_array('services', HostedPlanNotice::BACKED_UP_STATES, true)
	&& !in_array('shutdown', HostedPlanNotice::BACKED_UP_STATES, true),
	'the key warning is for sites the management node backs up, not a self-hosted services site or a shut-down one');
check(!HostedPlanNotice::backupKeyMissing(), 'a deployment nobody hosts is never told its backups are off');

check(HostedPlanNotice::daysUntil('') === null && HostedPlanNotice::daysUntil('soon') === null,
	'an unreadable date is null, not zero — zero would read as "today"');

// The disk figure comes from the node's own status check, and its absence is
// absence rather than a reassuring nothing.
$disk_node = ht_node('httest-disk-' . $suffix);
check(HostedTrialWatch::disk_percent($disk_node) === null,
	'a node that has never reported has no disk figure');
$disk_node->set('mgn_last_status_data', json_encode(array('disk_usage_percent' => 83.4)));
$disk_node->save();
check(HostedTrialWatch::disk_percent($disk_node) === 83,
	'and once it has, that is the figure the banner shows');

// A site on a shared host shows its own disk, read now against its allowance
// (hosting_paid_launch B6): the node's copy is the host's view and an hour old.
harness_set_setting_mem('hosted_plan_state', 'subscribed');
harness_set_setting_mem('hosted_plan_allowances', json_encode(array(
	array('label' => 'Email sent this month', 'used' => '10', 'allowance' => '1,000', 'percent' => 1),
	array('label' => 'Disk used', 'used' => '40%', 'allowance' => '100%', 'percent' => 40,
		'action_label' => 'Move to your own server', 'action_url' => 'https://example.test/linode'),
)));
$GB = 1073741824;
DiskAllowance::readWith(function () use ($GB) { return array(5 * $GB, (int)(4.8 * $GB), (int)(5.5 * $GB)); });
harness_defer(function () { DiskAllowance::readWith(null); });
$disk_row = null;
foreach (HostedPlanNotice::allowances() as $a) { if ($a['label'] === 'Disk used') { $disk_row = $a; } }
check($disk_row && $disk_row['percent'] === 95 && $disk_row['used'] === '4.8 GB' && $disk_row['allowance'] === '5 GB'
	&& $disk_row['action_url'] === 'https://example.test/linode',
	'the disk row carries the site\'s own figure and keeps the node\'s door', json_encode($disk_row));
check(HostedPlanNotice::level('subscribed', 200, HostedPlanNotice::allowances()) === 'urgent',
	'at 95% of its own allowance the banner urges, though the node said 40%');
harness_set_setting_mem('hosted_plan_allowances', json_encode(array(
	array('label' => 'Email sent this month', 'used' => '10', 'allowance' => '1,000', 'percent' => 1),
)));
$labels = array_column(HostedPlanNotice::allowances(), 'label');
check(in_array('Disk used', $labels, true), 'a site whose node sent no disk row still gets one');
harness_set_setting_mem('hosted_plan_allowances', '');
check(array_column(HostedPlanNotice::allowances(), 'label') === array('Disk used'),
	'a site whose node sent no rows at all still gets its disk row');
harness_set_setting_mem('hosted_plan_allowances', json_encode(array(
	array('label' => 'Email sent this month', 'used' => '10', 'allowance' => '1,000', 'percent' => 1),
)));
DiskAllowance::readWith(function () { return array(null, null, null); });
$labels = array_column(HostedPlanNotice::allowances(), 'label');
check($labels === array('Email sent this month'), 'a site with no allowance adds nothing');
DiskAllowance::readWith(null);
harness_set_setting_mem('hosted_plan_state', '');
harness_set_setting_mem('hosted_plan_allowances', '');

// ---------------------------------------------------------------------------
section('A site opens on trial only where a trial is configured');

// With no trial the subscription has charged at checkout, and a row that opened
// on `trial` would count down to a date nobody agreed to — the first billing
// period's end, dressed up as the end of a free period.
$watch = new HostedTrialWatch();
$open = (new ReflectionClass('HostedTrialWatch'))->getMethod('open_row');

$notrial = ht_provision('httest-notrial-' . $suffix, 'operator');
$open->invoke($watch, $notrial, 0);
$nt = HostedTrial::for_provision($notrial->key);
check($nt !== null && (string)$nt->get('htr_state') === HostedTrial::STATE_SUBSCRIBED,
	'with no trial configured a new site is subscribed from day one');
check($nt !== null && trim((string)$nt->get('htr_trial_ends_time')) === '',
	'and carries no trial date for a banner to count down to');

$withtrial = ht_provision('httest-withtrial-' . $suffix, 'operator');
$open->invoke($watch, $withtrial, 14);
$wt = HostedTrial::for_provision($withtrial->key);
check($wt !== null && (string)$wt->get('htr_state') === HostedTrial::STATE_TRIAL,
	'with a trial configured the row opens on trial');
$ends = $wt ? strtotime((string)$wt->get('htr_trial_ends_time') . ' UTC') : 0;
check($ends > time() + 13 * 86400 && $ends < time() + 15 * 86400,
	'ending after the configured length when the store has no date to offer');

// A cancel that arrived while the site was still being built found no row
// then (B132); the store kept it on the order line, and the new row reads it.
$early_buyer = make_user('HtEarly' . $suffix);
$early_order = new Order(NULL);
$early_order->set('ord_usr_user_id', $early_buyer->key);
$early_order->save();
$early_order->load();
harness_register_row('ord_orders', 'ord_order_id', $early_order->key);
$early_item = new OrderItem(NULL);
$early_item->set('odi_ord_order_id', $early_order->key);
$early_item->set('odi_pro_product_id', 999999002);
$early_item->set('odi_usr_user_id', $early_buyer->key);
$early_item->set('odi_price', '12.99');
$early_item->set('odi_is_subscription', true);
$early_item->set('odi_subscription_status', 'canceled');
$early_item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
$early_item->save();
$early_item->load();
harness_register_row('odi_order_items', 'odi_order_item_id', $early_item->key);
// Bought on this site's setup page (origin buyer): only such a site's line is
// read as this store's (CustomerCloudProvision::local_order_item).
$early = ht_provision('httest-early-' . $suffix, 'operator', array('cvp_external_order_item_id' => $early_item->key,
	'cvp_origin' => 'buyer'));
$open->invoke($watch, $early, 0);
$et = HostedTrial::for_provision($early->key);
check($et !== null && (string)$et->get('htr_state') === HostedTrial::STATE_GRACE,
	'a site whose subscription was cancelled before it was ready opens in its wind-down',
	$et ? (string)$et->get('htr_state') : 'no row');
check($nt !== null && (string)$nt->get('htr_state') === HostedTrial::STATE_SUBSCRIBED,
	'a site with no store line behind it is untouched');

// ---------------------------------------------------------------------------
section('A trial that simply ends becomes a subscription');

// Nothing else says so: payment_recovered fires only for an item that was in
// trouble, and a first successful charge at the end of a trial is not that. Left
// alone, the site would sit at 'trial' for ever with a banner counting down to a
// date in the past.
$conv = ht_provision('httest-conv-' . $suffix, 'operator');
$ct = new HostedTrial(NULL);
$ct->set('htr_cvp_customer_cloud_provision_id', (int)$conv->key);
$ct->set('htr_state', HostedTrial::STATE_TRIAL);
$ct->set('htr_trial_ends_time', gmdate('Y-m-d H:i:s', time() + 86400));
$ct->save();
$ct->load();

$watch = new HostedTrialWatch();
$convert = (new ReflectionClass('HostedTrialWatch'))->getMethod('convert_if_trial_ended');

check($convert->invoke($watch, $ct) === 0, 'a trial still running is left alone');
$ct->load();
check((string)$ct->get('htr_state') === HostedTrial::STATE_TRIAL, 'still on trial');

$ct->set('htr_trial_ends_time', gmdate('Y-m-d H:i:s', time() - 86400));
$ct->save();
check($convert->invoke($watch, $ct) === 1, 'a trial whose date has passed converts');
$ct->load();
check((string)$ct->get('htr_state') === HostedTrial::STATE_SUBSCRIBED, 'to a subscription');

// A row already in grace must NOT be converted out of it by a passed date.
$ct->set('htr_state', HostedTrial::STATE_GRACE);
$ct->save();
check($convert->invoke($watch, $ct) === 0,
	'a failed payment outranks a passed trial date — grace is not converted away');

// And the banner never says "until already" for a date gone by.
$phrase = (new ReflectionClass('HostedPlanNotice'))->getMethod('whenPhrase');
check($phrase->invoke(null, gmdate('Y-m-d H:i:s', time() - 864000)) === '',
	'a past date says nothing rather than "already", which would read as "included until already"');

// ---------------------------------------------------------------------------
section('Reading the provider\'s DNS records in the shape it actually answers');

// The domain endpoints (add / view / verify) all answer in one shape: the
// domain object nested under domains[].domain, with the trackers beside it.
// EVERY RECORD IS A CNAME — dkim_expected is the target of a CNAME at the
// provider's selector, not a DKIM key to publish as TXT (dkim_value is what DNS
// answers today, empty until published) — and the return-path
// host is built from rpath_selector, which is a LABEL and not a hostname.
// Read wrongly, this leg registers a sending domain, publishes records that
// resolve to nothing, never verifies, and leaves the customer's mail unsigned
// while every dashboard reads green.
//
// ONE READER ANSWERS FOR THE WHOLE PLATFORM (Smtp2GoProvider, exercised in
// full by tests/email/provider_dkim_test.php). A hosted customer's sending
// domain and a self-hosted site's are the same thing at the provider, so what
// is asserted here is that this leg reads through it and not around it.
$answer = array('domains' => array(array(
	'domain' => array(
		'fulldomain'     => 'mail.example.com',
		'dkim_selector'  => 's1234567',
		'dkim_verified'  => true,
		'dkim_expected'  => 'dkim.smtp2go.net',
		'dkim_value'     => '',
		'rpath_selector' => 'em1234',
		'rpath_verified' => true,
		'rpath_expected' => 'return.smtp2go.net',
		'rpath_value'    => '',
	),
	'trackers' => array(array(
		'fulldomain'     => 'link.mail.example.com',
		'cname_expected' => 'track.smtp2go.net',
		'cname_value'    => '',
		'enabled'     => true,
	)),
)));

$entry = Smtp2GoProvider::entryFor($answer, 'mail.example.com');
$records = Smtp2GoProvider::recordsOf($entry);
$by_name = array();
foreach ($records as $r) { $by_name[$r['name']] = $r; }

check(count($records) === 3, 'all three records are read out of the answer',
	json_encode(array_keys($by_name)));
check(($by_name['s1234567._domainkey.mail.example.com']['type'] ?? '') === 'CNAME',
	'DKIM is a CNAME at selector._domainkey.<domain>, never a TXT key');
check(($by_name['em1234.mail.example.com']['value'] ?? '') === 'return.smtp2go.net',
	'the return path is built from rpath_selector, without which the domain never verifies');
check(Smtp2GoProvider::stateOf($entry) === 'active',
	'and a domain whose DKIM and return path both resolve reads as verified');

check(Smtp2GoProvider::recordsOf(Smtp2GoProvider::entryFor(array('domains' => array()), 'mail.example.com'))
	=== array(),
	'a domain the account does not hold yields nothing — which this leg treats as a failure, not a pass');

// ---------------------------------------------------------------------------
section('A rehearsal plane mints its SMTP users in the provider\'s sandbox');

// The switch is read by the mail leg and handed to the client, which says
// status=sandbox to the provider only when asked. Pinned at the wire, so a
// plane with the switch on can never mint a user that delivers.
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/Smtp2GoClient.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionHostedMail.php'));
$smtp_history = array();
$smtp_mock = new \GuzzleHttp\Handler\MockHandler(array(
	new \GuzzleHttp\Psr7\Response(200, array(), json_encode(array('data' => array('username' => 'u1')))),
	new \GuzzleHttp\Psr7\Response(200, array(), json_encode(array('data' => array('username' => 'u2')))),
));
$smtp_stack = \GuzzleHttp\HandlerStack::create($smtp_mock);
$smtp_stack->push(\GuzzleHttp\Middleware::history($smtp_history));
$smtp_client = new Smtp2GoClient('test-key', new \GuzzleHttp\Client(array('handler' => $smtp_stack)));
$smtp_client->addSmtpUser('sub1', 'u1', 'pw1');
$smtp_client->addSmtpUser('sub1', 'u2', 'pw2', true);
$sent = array();
foreach ($smtp_history as $entry) {
	$sent[] = json_decode((string)$entry['request']->getBody(), true) ?: array();
}
check(count($sent) === 2 && !isset($sent[0]['status']), 'an ordinary user is minted with no status — the provider\'s default delivers',
	var_export($sent, true));
check(($sent[1]['status'] ?? '') === 'sandbox', 'a sandboxed user says status=sandbox at the wire', var_export($sent, true));
harness_set_setting_mem('server_manager_smtp2go_sandbox_users', '');
check(!ProvisionHostedMail::sandbox_users(), 'the switch is off by default');
harness_set_setting_mem('server_manager_smtp2go_sandbox_users', '1');
check(ProvisionHostedMail::sandbox_users(), 'and on when the hosted card sets it');
harness_set_setting_mem('server_manager_smtp2go_sandbox_users', '0');

section('The master key\'s missing permissions are named before a purchase finds them (B179)');
$perm_client = function (array $body) {
	$mock = new \GuzzleHttp\Handler\MockHandler(array(new \GuzzleHttp\Psr7\Response(200, array(), json_encode($body))));
	return new Smtp2GoClient('test-key', new \GuzzleHttp\Client(array('handler' => \GuzzleHttp\HandlerStack::create($mock))));
};
$all = array_map(function ($p) { return '/' . $p; }, array_keys(Smtp2GoClient::ENDPOINTS_USED));
check($perm_client(array('data' => $all))->missingPermissions() === array(), 'a key with every endpoint lacks nothing');
$narrow = array_values(array_filter($all, function ($p) { return strpos($p, '/subaccount') !== 0; }));
$missing = $perm_client(array('data' => array('permissions' => $narrow)))->missingPermissions();
check(is_array($missing) && array_keys($missing) === array('subaccount/add', 'subaccount/edit', 'subaccount/close', 'subaccount/reopen', 'subaccounts/search')
	&& $missing['subaccount/add'][1] === 'Subaccounts', 'a key without Subaccounts is named, wherever the list sits', var_export($missing, true));
check($perm_client(array('data' => array()))->missingPermissions() === null, 'an empty answer is not checked, never every permission missing');
$fail = new \GuzzleHttp\Handler\MockHandler(array(new \GuzzleHttp\Psr7\Response(401, array(), json_encode(array('data' => array('error' => 'Invalid API key'))))));
$fail_client = new Smtp2GoClient('bad', new \GuzzleHttp\Client(array('handler' => \GuzzleHttp\HandlerStack::create($fail))));
$why = '';
try { $fail_client->missingPermissions(); } catch (Smtp2GoException $e) { $why = $e->getMessage(); }
check(strpos($why, 'Invalid API key') !== false, 'a refused key throws, carrying the provider\'s reason for the tab', $why);

// ---------------------------------------------------------------------------
section('Only a sold site is billed: a copy on our account never gets a trial');

// site_copy.md B39: a copy made with the operator token is an operator-mode,
// admin-origin row. Taken as sold, it got a trial, a banner it refused, and at
// the trial's end a shutdown that after a switch-over hits the live site.
$copy_row = ht_provision('httest-copy-' . $suffix, 'operator', array(
	'cvp_install_mode' => 'copy', 'cvp_source_node_id' => 1, 'cvp_docker_mode' => 'bare-metal',
	'cvp_release' => '0.8.460', 'cvp_status' => 'done'));
$shard_row = ht_provision('httest-shard-' . $suffix, 'operator', array(
	'cvp_install_mode' => 'bare', 'cvp_status' => 'done'));
check(!$copy_row->is_sold() && !$shard_row->is_sold(), 'a site copy and a relay shard are not sold');
$sold_ids = array_map(function ($p) { return (int)$p->key; }, HostedTrialWatch::sold_sites());
check(!in_array((int)$copy_row->key, $sold_ids, true) && !in_array((int)$shard_row->key, $sold_ids, true),
	'so the watch passes over both');
// B11: the per-buyer limit counts what was bought, never an admin's machine.
$limit_user = 980000 + random_int(0, 9999);
$admin_host = ht_provision('httest-lim-host-' . $suffix, 'operator', array(
	'cvp_install_mode' => 'bare', 'cvp_status' => 'done', 'cvp_usr_user_id' => $limit_user));
$admin_site = ht_provision('httest-lim-site-' . $suffix, 'operator', array(
	'cvp_status' => 'done', 'cvp_usr_user_id' => $limit_user));
$bought_site = ht_provision('httest-lim-buy-' . $suffix, 'operator', array(
	'cvp_origin' => 'buyer', 'cvp_status' => 'done', 'cvp_usr_user_id' => $limit_user,
	'cvp_external_order_item_id' => 970000 + random_int(0, 9999)));
check(CustomerCloudProvision::hosted_sites_held($limit_user)['account'] === 1,
	'the per-buyer limit counts the site bought, not a starter host or a site an admin made',
	json_encode(CustomerCloudProvision::hosted_sites_held($limit_user)));
foreach (array($admin_host, $admin_site, $bought_site) as $lim_row) {
	$lim_row->set('cvp_delete_time', gmdate('Y-m-d H:i:s'));
	$lim_row->save();
}
foreach (array('order', 'buyer') as $origin) {
	$bought = new CustomerCloudProvision(NULL);
	$bought->set('cvp_origin', $origin);
	$bought->set('cvp_install_mode', 'fresh');
	check($bought->is_sold(), 'a fresh site from the ' . $origin . ' origin is sold');
}

// ---------------------------------------------------------------------------
section('Cleanup');

check(true, 'fixtures were created inside the transaction');
$db->rollBack();
$left = (int)$db->query("SELECT COUNT(*) FROM cvp_customer_cloud_provisions WHERE cvp_slug LIKE 'httest-%'")->fetchColumn();
check($left === 0, 'every provision this test created is gone', $left . ' left');

harness_finish();
