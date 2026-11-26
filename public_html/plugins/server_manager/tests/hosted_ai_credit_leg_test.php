<?php
/** @joinery-test
 * name: hosted_ai_credit_leg
 * tier: db
 * env: any
 * needs: []
 */
/**
 * AI credit on the management node (specs/hosted_ai_credit.md §3.3-§3.8, §8
 * "db tier, fake client"), against FakeTrustedRouterClient:
 *
 *  - the leg takes a sold Managed site from pending to done, and the key is
 *    blanked from the job row once the node answers;
 *  - a failed push deletes the key and mints a fresh one, and gives up after
 *    three, deleting the last;
 *  - a purchase applied twice is applied once; a line refunded in two parts
 *    writes two rows, each once however often the watch reads it; a refund on
 *    a removed site writes a row and moves no cap;
 *  - suspension switches the key off and Unsuspend back on;
 *  - the month roll tops free usage up to $1, and gives none to a site whose
 *    plan has lapsed;
 *  - the banner row's figures;
 *  - a site moved to its buyer's own account keeps its credit with its
 *    email and backups.
 *
 * Everything runs inside one transaction that is rolled back.
 *
 * Run: php plugins/server_manager/tests/hosted_ai_credit_leg_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionHostedAi.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/tests/fixtures/FakeTrustedRouterClient.php'));

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
$mails = array();
EmailSender::$capture = function ($template, $to, $vars) use (&$mails) {
	$mails[] = $to;
	return true;
};
harness_set_setting_mem('server_manager_hosted_ai_allowance_usd', '1.00');
harness_set_setting_mem('server_manager_hosted_ai_markup_percent', '10');
harness_set_setting_mem('server_manager_hosted_ai_low_balance_usd', '');
harness_set_setting_mem('server_manager_provisioning_admin_alert_email', 'ops@example.com');

$fake = new FakeTrustedRouterClient();
HostedAiCredit::$client_stand_in = $fake;
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$F = 1000000;

function hai_node(string $slug): ManagedNode {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest AI ' . $slug);
	$node->set('mgn_slug', $slug);
	$node->set('mgn_host', '203.0.113.' . random_int(2, 250));
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_web_root', '/var/www/html/' . $slug . '/public_html');
	$node->set('mgn_uptime_enabled', false);
	$node->set('mgn_agent_public_key', 'hai-agent-' . $slug);
	$node->set('mgn_agent_version', AgentVocabulary::FLOOR);
	$node->set('mgn_agent_primitives', 'hosted_mail_settings,hosted_plan_notice,hosted_ai_settings');
	$node->save();
	$node->load();
	return $node;
}

function hai_site(string $slug, string $trial_state = HostedTrial::STATE_SUBSCRIBED): array {
	$node = hai_node($slug);
	$provision = new CustomerCloudProvision(NULL);
	$provision->set('cvp_origin', 'order');
	$provision->set('cvp_external_order_item_id', random_int(900000000, 999999999));
	$provision->set('cvp_usr_user_id', 990000 + random_int(0, 9999));
	$provision->set('cvp_domain', $slug . '.example.com');
	$provision->set('cvp_slug', $slug);
	$provision->set('cvp_hosting_mode', 'operator');
	$provision->set('cvp_buyer_email', $slug . '@example.com');
	$provision->set('cvp_status', 'done');
	$provision->set('cvp_mgn_managed_node_id', (int)$node->key);
	$provision->save();
	$provision->load();
	$trial = new HostedTrial(NULL);
	$trial->set('htr_cvp_customer_cloud_provision_id', (int)$provision->key);
	$trial->set('htr_state', $trial_state);
	$trial->save();
	return array($node, $provision, $trial);
}

/** The node answers the latest AI push: completed (with the script's line) or failed. */
function hai_answer(ManagedNode $node, string $status): ManagementJob {
	$job = ManagementJob::latestForNode($node->key, ProvisionHostedAi::JOB_TYPE);
	$job->set('mjb_status', $status);
	$job->set('mjb_output', $status === 'completed' ? "HOSTED_AI_SETTINGS=ok\nkey=set\n" : 'php: script not found');
	$job->save();
	JobResultProcessor::process($job);
	$job->load();
	return $job;
}

function hai_leg(): array {
	return (new ProvisionHostedAi())->run(array());
}

/** Let a switched-off key's wait pass, so the next leg reads its final usage and deletes it. */
function hai_age_retiring(HostedAiAccount $account): void {
	$account->load();
	$aged = array();
	foreach ($account->retiring_keys() as $entry) {
		$entry['time'] = time() - 600;
		$aged[] = $entry;
	}
	$account->set_retiring_keys($aged);
	$account->save();
}

// ---------------------------------------------------------------------------
section('A sold Managed site goes from pending to done');

list($node, $provision) = hai_site('haitest-a-' . $suffix);
hai_leg();
$account = HostedAiAccount::for_provision($provision->key);
check($account !== null, 'an account is opened for a sold, subscribed site');
check($account && (int)$account->get('hai_allowance') === $F && (string)$account->get('hai_period_start') === gmdate('Y-m'),
	'its first month has $1 of free usage');
$hash = $account ? (string)$account->get('hai_key_hash') : '';
check($hash !== '' && isset($fake->keys[$hash]) && $fake->keys[$hash]['limit'] === $F,
	'a key is created at TrustedRouter, capped at $1');
check($hash !== '' && strpos($fake->keys[$hash]['name'], 'ai-' . $provision->key) !== false
	&& strpos($fake->keys[$hash]['name'], 'example.com') === false,
	'the key is named by provision id, with no domain sent');
check($account && (string)$account->get('hai_state') === HostedAiAccount::STATE_SETTINGS_PUSHED, 'the key is pushed to the site');
$job = ManagementJob::latestForNode($node->key, ProvisionHostedAi::JOB_TYPE);
$raw = $job ? (string)$job->get('mjb_commands') : '';
check($job && strpos($raw, $fake->keys[$hash]['key'] ?? 'x') !== false, 'the key rides the job to the node');
$grants = new MultiHostedAiLedger(array('account_id' => (int)$account->key, 'kind' => HostedAiLedger::KIND_GRANT));
check(count($grants) === 1, 'the first month\'s free usage is on the ledger');

$job = hai_answer($node, 'completed');
check(strpos((string)$job->get('mjb_commands'), $fake->keys[$hash]['key']) === false
	&& strpos(json_encode($job->get('mjb_parameters')), $fake->keys[$hash]['key']) === false,
	'the key is blanked from the job row once the node answers');
hai_leg();
$account->load();
check((string)$account->get('hai_state') === HostedAiAccount::STATE_DONE, 'the site confirmed it: done');

// ---------------------------------------------------------------------------
section('A failed push deletes the key and mints a fresh one');

list($node_b, $provision_b) = hai_site('haitest-b-' . $suffix);
hai_leg();
$acct_b = HostedAiAccount::for_provision($provision_b->key);
$first = (string)$acct_b->get('hai_key_hash');
hai_answer($node_b, 'failed');
hai_leg();
$acct_b->load();
$second = (string)$acct_b->get('hai_key_hash');
check($second !== '' && $second !== $first && isset($fake->keys[$second]) && $fake->keys[$first]['disabled'] === true
	&& array_column($acct_b->retiring_keys(), 'hash') === array($first),
	'the old key is switched off and parked, and a new one minted and pushed');
$fake->spend($first, 70000, true); // a request in flight when it was switched off lands late
hai_age_retiring($acct_b);
hai_leg();
$acct_b->load();
check(!isset($fake->keys[$first]) && $acct_b->retiring_keys() === array(),
	'on a later pass the parked key is deleted');
check((int)$acct_b->get('hai_allowance') === $F - 70000, 'and its late spend is charged, not lost with it (B2)',
	(string)$acct_b->get('hai_allowance'));
hai_answer($node_b, 'failed');
hai_leg();
$acct_b->load();
hai_answer($node_b, 'failed');
hai_leg();
$acct_b->load();
check((string)$acct_b->get('hai_state') === HostedAiAccount::STATE_FAILED, 'after three failed pushes the leg stops');
$parked = array_column($acct_b->retiring_keys(), 'hash');
$last = (string)end($parked);
check(trim((string)$acct_b->get('hai_key_hash')) === '' && $last !== '' && $fake->keys[$last]['disabled'] === true,
	'and the last key is switched off at once, so nothing spendable is left behind');
hai_age_retiring($acct_b);
hai_leg();
check(!isset($fake->keys[$last]), 'and deleted on the next pass');
check(count($mails) > 0, 'the operator is told');

// ---------------------------------------------------------------------------
section('Buying credit');

$line = random_int(800000000, 899999999);
HostedAiCredit::purchase($account, $line, 20.00);
HostedAiCredit::purchase($account, $line, 20.00);
$account->load();
check((int)$account->get('hai_purchased') === 18181818, 'a purchase applied twice is applied once: $20 buys $18.18');
check(count(new MultiHostedAiLedger(array('purchase_line' => $line))) === 1, 'one purchase row for the line');
check($fake->keys[$hash]['limit'] === $F + 18181818, 'the cap rises at once');

section('Refunds');
HostedAiCredit::refund_line($line, 5.50);
HostedAiCredit::refund_line($line, 5.50);
$account->load();
check((int)$account->get('hai_purchased') === 18181818 - 5000000, 'a $5.50 refund takes $5.00 of credit, once');
HostedAiCredit::refund_line($line, 11.00);
HostedAiCredit::refund_line($line, 11.00);
$account->load();
check((int)$account->get('hai_purchased') === 18181818 - 10000000, 'the second part, to $11 refunded, takes another $5.00, once');
check(count(new MultiHostedAiLedger(array('order_item_id' => $line, 'kind' => HostedAiLedger::KIND_REFUND))) === 2,
	'a line refunded in two parts has two refund rows');
check($fake->keys[$hash]['limit'] === $F + 8181818, 'the cap comes down with it');

// ---------------------------------------------------------------------------
section('Suspension');

HostedAiCredit::suspend_provision((int)$provision->key);
$account->load();
check($fake->keys[$hash]['disabled'] === true && (string)$account->get('hai_state') === HostedAiAccount::STATE_DISABLED,
	'suspension switches the key off');
HostedAiCredit::reopen_provision((int)$provision->key);
$account->load();
check($fake->keys[$hash]['disabled'] === false && (string)$account->get('hai_state') === HostedAiAccount::STATE_DONE,
	'Unsuspend switches it back on');

// ---------------------------------------------------------------------------
section('The banner row');

$fake->spend($hash, 1300000);
$account->set('hai_usage', 1300000);
$account->save();
$row = HostedTrialWatch::ai_allowance_of($account);
check($row !== null && $row['label'] === 'AI credit this month' && $row['action_label'] === 'Buy more AI credit',
	'the row names AI credit and offers more');
check($row && $row['used'] === '$1.25' && $row['allowance'] === '$9.18' && $row['percent'] === 10,
	'used rounds down to the quarter dollar, against free usage plus bought credit', json_encode($row));

// ---------------------------------------------------------------------------
section('The month roll');

$account->set('hai_period_start', '2000-01');
$account->set('hai_usage_time', null);
$account->save();
hai_leg();
$account->load();
check((string)$account->get('hai_period_start') === gmdate('Y-m'), 'the account rolls into this month');
check((int)$account->get('hai_period_start_usage') === 1300000 && (int)$account->get('hai_allowance') === $F,
	'free usage is topped up to $1, counted from today\'s usage');
check((int)$account->get('hai_purchased') === 8181818 - 300000, 'bought credit carries over less the 30 cents it paid for');
check($fake->keys[$hash]['limit'] === 1300000 + $F + 7881818, 'the cap is moved to U0 + A + B');

list($node_c, $provision_c, $trial_c) = hai_site('haitest-c-' . $suffix);
hai_leg();
$acct_c = HostedAiAccount::for_provision($provision_c->key);
$trial_c->set('htr_state', HostedTrial::STATE_GRACE);
$trial_c->save();
$acct_c->set('hai_period_start', '2000-01');
$acct_c->save();
hai_leg();
$acct_c->load();
check((int)$acct_c->get('hai_allowance') === 0, 'a site in grace at the roll gets no free usage');

// ---------------------------------------------------------------------------
section('A refund after the site is removed');

$line_d = random_int(700000000, 799999999);
HostedAiCredit::purchase($acct_c, $line_d, 11.00);
$acct_c->load();
$hash_c = (string)$acct_c->get('hai_key_hash');
$done = HostedAiCredit::remove($acct_c, 'test removal');
$acct_c->load();
check($done['ok'] && (string)$acct_c->get('hai_state') === HostedAiAccount::STATE_REMOVED && !isset($fake->keys[$hash_c]),
	'removal deletes the key');
check(count(new MultiHostedAiLedger(array('account_id' => (int)$acct_c->key, 'kind' => HostedAiLedger::KIND_EXPIRE))) === 1,
	'unspent bought credit ends with an expire row');
$calls = count($fake->calls);
$refund = HostedAiCredit::refund_line($line_d, 11.00);
check($refund !== null && (int)$refund->get('hal_amount') === 0 && count($fake->calls) === $calls,
	'a refund after removal writes its row and moves no cap');

// ---------------------------------------------------------------------------
section('A site moved to its buyer\'s own account keeps its credit with its email and backups');

$tenant = new ServiceTenant(NULL);
$tenant->set('svt_usr_user_id', (int)$provision->get('cvp_usr_user_id'));
$tenant->set('svt_service', ServiceTenant::SERVICE_MAIL);
$tenant->set('svt_state', 'active');
$tenant->set('svt_mgn_managed_node_id', (int)$node->key);
$tenant->set('svt_paid_until', gmdate('Y-m-d H:i:s', time() + 30 * 86400));
$tenant->save();
HostedAiCredit::carry_or_end($provision, $node);
$account->load();
check((int)$account->get('hai_svt_service_tenant_id') === (int)$tenant->key && isset($fake->keys[$hash]),
	'the account follows the Services rows and keeps its key');
check(ProvisionHostedAi::entitled($account), 'and its entitlement is the bundle\'s now');

// ---------------------------------------------------------------------------
section('TrustedRouter unreachable mid-step (B1, B5, B6)');

list($node_e, $provision_e) = hai_site('haitest-e-' . $suffix);
hai_leg();
$acct_e = HostedAiAccount::for_provision($provision_e->key);
hai_answer($node_e, 'completed');
hai_leg();
$acct_e->load();
$hash_e = (string)$acct_e->get('hai_key_hash');
$fake->spend($hash_e, 300000);
$fake->fail_on = array('disable');
$threw = false;
try { HostedAiCredit::retire_key($acct_e, $fake, 300000); } catch (TrustedRouterException $e) { $threw = true; }
$acct_e->load();
check($threw && (string)$acct_e->get('hai_key_hash') === $hash_e && (int)$acct_e->get('hai_period_start_usage') === 0
	&& (int)$acct_e->get('hai_allowance') === $F,
	'a key that cannot be switched off is left exactly as it was: nothing settled, nothing lost (B1)');
$fake->fail_on = array();
HostedAiCredit::retire_key($acct_e, $fake, 300000);
$fake->fail_on = array('delete');
hai_age_retiring($acct_e);
hai_leg();
$acct_e->load();
check(array_column($acct_e->retiring_keys(), 'hash') === array($hash_e) && (int)$acct_e->get('hai_allowance') === $F - 300000,
	'a delete that fails keeps the key parked and charges it once, not twice (B1)', (string)$acct_e->get('hai_allowance'));
$fake->fail_on = array();
hai_leg();
$acct_e->load();
check($acct_e->retiring_keys() === array() && (int)$acct_e->get('hai_allowance') === $F - 300000,
	'and the next pass deletes it, still charged once');

// A live key again, then a removal while deletes fail.
$acct_e->set('hai_state', HostedAiAccount::STATE_PENDING);
$acct_e->save();
hai_leg();
$acct_e->load();
$fake->fail_on = array('delete');
$done = HostedAiCredit::remove_provision((int)$provision_e->key, 'test removal while TrustedRouter is down');
$acct_e->load();
check(!$done['ok'] && (string)$acct_e->get('hai_state') === HostedAiAccount::STATE_REMOVING,
	'a removal that cannot delete the key is kept as removing (B5)');
$fake->fail_on = array();
hai_leg();
$acct_e->load();
check((string)$acct_e->get('hai_state') === HostedAiAccount::STATE_REMOVED, 'and the leg finishes it on the next pass');

list($node_f, $provision_f) = hai_site('haitest-f-' . $suffix);
hai_leg();
$acct_f = HostedAiAccount::for_provision($provision_f->key);
$hash_f = (string)$acct_f->get('hai_key_hash');
$acct_f->set('hai_state', HostedAiAccount::STATE_PENDING);
$acct_f->save();
hai_leg();
$acct_f->load();
check((string)$acct_f->get('hai_key_hash') !== $hash_f && $fake->keys[$hash_f]['disabled'] === true,
	'a mint over a key still on record retires that key first, never orphans it (B6)');

$provision_f->set('cvp_delete_time', gmdate('Y-m-d H:i:s'));
$provision_f->save();
hai_leg();
$acct_f->load();
check(in_array((string)$acct_f->get('hai_state'), array(HostedAiAccount::STATE_REMOVED), true),
	'a Managed site whose provision is gone is ended by the leg (B5)');

// ---------------------------------------------------------------------------
section('A push the node never collects (B10)');

list($node_g, $provision_g) = hai_site('haitest-g-' . $suffix);
hai_leg();
$acct_g = HostedAiAccount::for_provision($provision_g->key);
$old_job = ManagementJob::latestForNode($node_g->key, ProvisionHostedAi::JOB_TYPE);
$old_job->set('mjb_status', 'queued');
$old_job->set('mjb_create_time', gmdate('Y-m-d H:i:s', time() - 25 * 3600));
$old_job->save();
$hash_g = (string)$acct_g->get('hai_key_hash');
hai_leg();
$old_job->load();
$acct_g->load();
check((string)$old_job->get('mjb_status') === 'cancelled' && strpos((string)$old_job->get('mjb_commands'), $fake->keys[$hash_g]['key']) === false,
	'a push queued for a day is cancelled and its key blanked');
check((string)$acct_g->get('hai_key_hash') !== $hash_g && $fake->keys[$hash_g]['disabled'] === true,
	'and its key replaced');

// ---------------------------------------------------------------------------
section('A purchase during the roll is not overwritten (B7)');

list($node_h, $provision_h) = hai_site('haitest-h-' . $suffix);
hai_leg();
$acct_h = HostedAiAccount::for_provision($provision_h->key);
$acct_h->set('hai_period_start', '2000-01');
$acct_h->save();
$stale = new HostedAiAccount($acct_h->key, TRUE);
HostedAiCredit::purchase(new HostedAiAccount($acct_h->key, TRUE), random_int(600000000, 699999999), 11.00);
HostedAiCredit::roll($stale, 0, true);
$acct_h->load();
check((int)$acct_h->get('hai_purchased') === 10000000, 'a roll from an older copy keeps the purchase made since', (string)$acct_h->get('hai_purchased'));

// ---------------------------------------------------------------------------
section('A bundle site connected by key collects its key (B2, B3) and comes back after it leaves (B4)');

$buyer = 991000 + random_int(0, 999);
$tenant_k = new ServiceTenant(NULL);
$tenant_k->set('svt_usr_user_id', $buyer);
$tenant_k->set('svt_apk_api_key_id', 880000000 + random_int(0, 99999));
$tenant_k->set('svt_service', ServiceTenant::SERVICE_MAIL);
$tenant_k->set('svt_state', 'active');
$tenant_k->set('svt_host', 'haitest-k-' . $suffix . '.example.com');
$tenant_k->set('svt_paid_until', gmdate('Y-m-d H:i:s', time() + 30 * 86400));
$tenant_k->save();
hai_leg();
$acct_k = HostedAiAccount::for_tenant($tenant_k->key);
check($acct_k !== null && (string)$acct_k->get('hai_state') === HostedAiAccount::STATE_PENDING
	&& JoineryServices::aiStatus($buyer, (int)$tenant_k->get('svt_apk_api_key_id'))['needs_key'] === true,
	'a paid bundle site with no agent has an account waiting for it to collect its key');
$key_id = (int)$tenant_k->get('svt_apk_api_key_id');
$fake->fail_on = array('create');
$threw = false;
try { JoineryServices::enrolAi($buyer, $key_id); } catch (Throwable $e) { $threw = true; }
$acct_k->load();
check($threw && (string)$acct_k->get('hai_state') === HostedAiAccount::STATE_PENDING
	&& JoineryServices::aiStatus($buyer, $key_id)['needs_key'] === true,
	'a mint that fails leaves it pending, so the site asks again (B3)');
$fake->fail_on = array();
$threw = false;
try { JoineryServices::enrolAi($buyer, $key_id); } catch (JoineryServicesException $e) { $threw = true; }
check($threw, 'right after a failed mint the claim still holds, so a second caller cannot mint at once');
$acct_k->set('hai_key_time', gmdate('Y-m-d H:i:s', time() - 600));
$acct_k->save();
$answer = JoineryServices::enrolAi($buyer, $key_id);
$acct_k->load();
check(!empty($answer['api_key']) && (string)$acct_k->get('hai_state') === HostedAiAccount::STATE_DONE,
	'the site collects its key in the enrol answer');
$threw = false;
try { JoineryServices::enrolAi($buyer, $key_id); } catch (JoineryServicesException $e) { $threw = true; }
check($threw, 'a second collection the same day is refused, so a fresh cap cannot be had on demand (B2)');

// Two collections at the same moment (B12): the claim is taken under the row
// lock before anything is minted, so the second is refused and no second
// live key exists.
$acct_k->load();
$acct_k->set('hai_key_time', gmdate('Y-m-d H:i:s', time() - 2 * 86400));
$acct_k->save();
$first_copy = new HostedAiAccount($acct_k->key, TRUE);
$second_copy = new HostedAiAccount($acct_k->key, TRUE);
HostedAiCredit::claim_collection($first_copy, JoineryServices::AI_COLLECT_EVERY_SECONDS);
$threw = false;
try { HostedAiCredit::claim_collection($second_copy, JoineryServices::AI_COLLECT_EVERY_SECONDS); } catch (HostedAiAccountException $e) { $threw = true; }
check($threw, 'of two collections at the same moment, the second is refused before it mints (B12)');
$keys_before = count($fake->keys);
$threw = false;
try { JoineryServices::enrolAi($buyer, $key_id); } catch (JoineryServicesException $e) { $threw = true; }
check($threw && count($fake->keys) === $keys_before, 'and a collection arriving while one is claimed mints nothing');

$tenant_k->set('svt_state', ServiceTenant::STATE_SUSPENDED);
$tenant_k->set('svt_paid_until', gmdate('Y-m-d H:i:s', time() - 86400));
$tenant_k->save();
hai_leg();
$acct_k->load();
$hash_k = (string)$acct_k->get('hai_key_hash');
check((string)$acct_k->get('hai_state') === HostedAiAccount::STATE_DISABLED && $fake->keys[$hash_k]['disabled'] === true,
	'a suspended bundle switches the key off and keeps it (B4)');
$acct_k->set('hai_state', HostedAiAccount::STATE_PENDING);
$acct_k->set('hai_key_time', null);
$acct_k->save();
$answer = JoineryServices::enrolAi($buyer, $key_id);
check(empty($answer['api_key']) && empty($answer['entitled']), 'a suspended bundle is not handed a working key (B16)');
$acct_k->set('hai_state', HostedAiAccount::STATE_DISABLED);
$acct_k->save();
$tenant_k->set('svt_state', 'active');
$tenant_k->set('svt_paid_until', gmdate('Y-m-d H:i:s', time() + 30 * 86400));
$tenant_k->save();
hai_leg();
$acct_k->load();
check((string)$acct_k->get('hai_state') === HostedAiAccount::STATE_DONE && $fake->keys[$hash_k]['disabled'] === false,
	'paying again switches it back on');
$tenant_k->set('svt_state', ServiceTenant::STATE_RELEASED);
$tenant_k->save();
hai_leg();
$acct_k->load();
check((string)$acct_k->get('hai_state') === HostedAiAccount::STATE_REMOVED, 'a released bundle ends its account');
$tenant_k->set('svt_state', 'active');
$tenant_k->save();
hai_leg();
$acct_k->load();
check((string)$acct_k->get('hai_state') === HostedAiAccount::STATE_PENDING && (int)$acct_k->get('hai_allowance') === 0,
	'a site back in the month it left is reopened with no second free usage (B4, B13)');
$tenant_k->set('svt_state', ServiceTenant::STATE_RELEASED);
$tenant_k->save();
hai_leg();
$acct_k->load();
$acct_k->set('hai_period_start', '2000-01');
$acct_k->save();
$tenant_k->set('svt_state', 'active');
$tenant_k->save();
hai_leg();
$acct_k->load();
check((string)$acct_k->get('hai_state') === HostedAiAccount::STATE_PENDING && (int)$acct_k->get('hai_allowance') === $F,
	'one back in a later month gets that month\'s free usage');

// A removal while TrustedRouter is down, whose provision row is then deleted
// for good (B15): it can still be saved, and the phase still runs.
list($node_r, $provision_r) = hai_site('haitest-r-' . $suffix);
hai_leg();
$acct_r = HostedAiAccount::for_provision($provision_r->key);
$fake->fail_on = array('delete');
HostedAiCredit::remove_provision((int)$provision_r->key, 'test');
$acct_r->load();
$acct_r->set('hai_cvp_customer_cloud_provision_id', null);
$saved = true;
try { $acct_r->save(); } catch (Throwable $e) { $saved = false; }
$result = hai_leg();
$fake->fail_on = array();
check($saved && (string)$acct_r->get('hai_state') === HostedAiAccount::STATE_REMOVING,
	'a removing account whose site row is gone still saves (B15)');
check(isset($result['status']), 'and the Hosted AI phase still runs past it', json_encode($result));
hai_leg();
$acct_r->load();
check((string)$acct_r->get('hai_state') === HostedAiAccount::STATE_REMOVED, 'and is removed once TrustedRouter answers');

HostedAiCredit::$client_stand_in = null;
EmailSender::$capture = null;
$db->rollBack();
harness_finish();
