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
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/hosted_trial_class.php'));
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
	$node->set('mgn_agent_version', '1.20.0');
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
check(strpos($ssh['cmd'], 'test -n "$JOINERY_ADMIN_PASSWORD"') !== false,
	'and refuses to continue without one — a missing password must fail loudly, '
	. 'not fall back to one nobody holds');
check(($ssh['stdin'] ?? '') === 'admin_password',
	'the step names WHAT it needs on stdin, so the executor looks it up');

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
check(count($options) === 2, 'the picker offers exactly two references', implode(' | ', $options));
check(CustomerCloudFulfillment::mode_for_ref(0) === 'customer',
	'reference 0 is the buyer\'s own cloud account — today\'s product, unchanged');
check(CustomerCloudFulfillment::mode_for_ref(1) === 'operator',
	'reference 1 is the operator\'s account');
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
check(CustomerCloudProvision::holds_admin_password($reveal->get('cvp_mgn_node_id')) === false,
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

$graced = ht_provision('httest-grace-' . $suffix, 'operator');
$trial = new HostedTrial(NULL);
$trial->set('htr_cvp_provision_id', (int)$graced->key);
$trial->set('htr_external_order_item_id', 880000 + random_int(0, 9999));
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
	'and the shelf date set from the SAME moment, so "kept ninety days" is countable '
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
	'service' => 'smtp', 'host' => 'mail.smtp2go.com', 'port' => 587,
	'username' => 'site-abc123', 'password' => 'a password with spaces',
	'sender' => 'bounces@mail.example.com', 'helo' => 'mail.example.com',
	'hostname' => 'mail.example.com',
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
section('The node-side scripts own the names, and refuse what they do not know');

foreach (array(
	array('utils/hosted_mail_settings.php', array('service' => 'nonsense'), 'HOSTED_MAIL_SETTINGS=error'),
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
section('A backup target that can mint keys says so, and only where it can');

require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
$b2 = new BackupTarget(NULL);
$b2->set('bkt_name', 'HarnessTest hosted ' . $suffix);
$b2->set('bkt_provider', 'b2');
$b2->set('bkt_bucket', 'ht-bucket');
$b2->set('bkt_credentials', json_encode(array('access_key' => 'k', 'secret_key' => 's',
	'region' => 'us-west-004', 'endpoint' => 'https://s3.us-west-004.backblazeb2.com')));
$b2->save();
$b2->load();
check(!$b2->can_mint_run_keys(),
	'a new target does NOT mint until somebody turns it on — minting needs a master key the '
	. 'provider will let create keys, and switching it on by default would fail every run of a '
	. 'fleet that was working');

$b2->set('bkt_mint_run_keys', true);
$b2->save();
$b2->load();
check($b2->can_mint_run_keys(), 'and does once an operator has');

$b2->set('bkt_mint_run_keys', true);
$b2->set('bkt_provider', 's3');
$b2->save();
check(!$b2->can_mint_run_keys(),
	'a provider that cannot pin a key to a prefix cannot mint, whatever the flag says — '
	. 'the fleet keeps the shared write-only credential it always had');

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
		array('service' => 'smtp', 'host' => 'mail.smtp2go.com', 'port' => 587)),
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
section('The banner is loudest about the thing that is actually urgent');// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
section('A site opens on trial only where a trial is configured');

// With no trial the subscription has charged at checkout, and a row that opened
// on `trial` would count down to a date nobody agreed to — the first billing
// period's end, dressed up as the end of a free period.
$watch = new HostedTrialWatch();
$open = (new ReflectionClass('HostedTrialWatch'))->getMethod('open_row');
$open->setAccessible(true);

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

// ---------------------------------------------------------------------------
section('A trial that simply ends becomes a subscription');

// Nothing else says so: payment_recovered fires only for an item that was in
// trouble, and a first successful charge at the end of a trial is not that. Left
// alone, the site would sit at 'trial' for ever with a banner counting down to a
// date in the past.
$conv = ht_provision('httest-conv-' . $suffix, 'operator');
$ct = new HostedTrial(NULL);
$ct->set('htr_cvp_provision_id', (int)$conv->key);
$ct->set('htr_state', HostedTrial::STATE_TRIAL);
$ct->set('htr_trial_ends_time', gmdate('Y-m-d H:i:s', time() + 86400));
$ct->save();
$ct->load();

$watch = new HostedTrialWatch();
$convert = (new ReflectionClass('HostedTrialWatch'))->getMethod('convert_if_trial_ended');
$convert->setAccessible(true);

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
$phrase->setAccessible(true);
check($phrase->invoke(null, gmdate('Y-m-d H:i:s', time() - 864000)) === '',
	'a past date says nothing rather than "already", which would read as "included until already"');

// ---------------------------------------------------------------------------
section('Reading the provider\'s DNS records in the shape it actually answers');

// The domain endpoints (add / view / verify) all answer in one shape: the
// domain object nested under domains[].domain, with the trackers beside it.
// EVERY RECORD IS A CNAME — dkim_value is the target of a CNAME at the
// provider's selector, not a DKIM key to publish as TXT — and the return-path
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
		'dkim_value'     => 'dkim.smtp2go.net',
		'rpath_selector' => 'em1234',
		'rpath_verified' => true,
		'rpath_value'    => 'return.smtp2go.net',
	),
	'trackers' => array(array(
		'fulldomain'  => 'link.mail.example.com',
		'cname_value' => 'track.smtp2go.net',
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
section('Cleanup');

check(true, 'fixtures were created inside the transaction');
$db->rollBack();
$left = (int)$db->query("SELECT COUNT(*) FROM cvp_customer_cloud_provisions WHERE cvp_slug LIKE 'httest-%'")->fetchColumn();
check($left === 0, 'every provision this test created is gone', $left . ' left');

harness_finish();
