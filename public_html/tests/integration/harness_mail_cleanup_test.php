<?php
/** @joinery-test
 * name: harness_mail_cleanup
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The harness cleans up the mail a run causes to be delivered.
 *
 * A run sends real mail. harness_boot() points it at the local relay and
 * redirects every recipient to a test store alias, so nothing reaches a person
 * — but the relay is a real mail server, so those messages are really delivered
 * and stored against an address no alias claims. Unmatched mail sits in no
 * mailbox, so nobody ever trashes it; left alone the suites deposited ~1,550
 * messages a month into dev's Unmatched box.
 *
 * harness_cleanup_delivered_mail() is what stops that, and it is dangerous in
 * exactly one direction: a selection that is too wide deletes mail that is not
 * the run's to delete, silently, on every single test run. So what this guards
 * is not mainly that it removes — it is everything it must REFUSE to remove.
 *
 * Each planted row differs from a removable one in exactly one respect, so a
 * passing check names the clause that did the work rather than a combination:
 *
 *   removed: the test store alias; a fixture address from THIS run
 *   kept:    received before this run booted (another run's, or real mail)
 *            delivered into a real mailbox (a suite's own evidence)
 *            an unrelated recipient (ordinary unmatched mail)
 *            a fixture address carrying ANOTHER run's token
 *
 * That last one matters most: fixture addresses all share a prefix, so a pattern
 * anchored on the prefix alone would have one run deleting a concurrent run's
 * mail out from under its assertions.
 *
 * The boot pass is the mirror image and is covered too. It exists because the
 * teardown pass provably cannot finish the job — delivery is asynchronous, and
 * a measured run left one message that arrived seconds after teardown. Its own
 * safety is an age floor rather than the run token, since collecting somebody
 * else's leftovers is the entire point.
 *
 * Tier db, deliberately: the passes refuse to run below it, because a delete
 * pass in the safe tier would break that tier's no-side-effects promise.
 *
 * Run: php tests/integration/harness_mail_cleanup_test.php
 *
 * @version 1.1 - the run owns its store alias, so a sibling lane cannot delete
 *   a kept fixture out from under the checks; covers the production gate
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

$h = &$GLOBALS['__harness'];
$db = DbConnector::get_instance()->get_db_link();

// Own the store alias for this run. The passes read $h['test_recipient'] when
// they are called, so pointing it at an address only this run uses makes every
// store-alias row planted below invisible to a sibling lane's passes — the db
// and test-db lanes overlap by default, and a row backdated past the settle
// floor is exactly the shape a sibling's boot pass collects. Without this the
// suite carries a rare failure that reads as 'cleanup deleted mail it should
// not have' — a false alarm about the very property it exists to guard.
$h['test_recipient'] = 'harnessmailclean_' . $h['run_token'] . '@' . HARNESS_FIXTURE_DOMAIN;

// ---- fixtures ------------------------------------------------------------
$owner = make_user('MailCleanupOwner', 5);
$owner_id = (int)$owner->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'mailclean-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->set('ied_owner_usr_user_id', $owner_id);
$domain->save();
$domain_id = (int)$domain->key;
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', $domain_id);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
$alias->set('iea_alias', 'real');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$alias_id = (int)$alias->key;
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $alias_id);

/** Plant a stored inbound message exactly as delivery would leave one. */
$plant = function ($recipient, $alias_id = null, $received = null) use ($domain_id) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', $domain_id);
	$m->set('iem_iea_inbound_email_alias_id', $alias_id);
	$m->set('iem_sender', 'sender@example.com');
	$m->set('iem_recipient', $recipient);
	$m->set('iem_subject', 'HarnessTest mail cleanup fixture');
	$m->set('iem_body_plain', 'body');
	$m->set('iem_body_html', '');
	$m->set('iem_message_id_header', 'mailclean-' . bin2hex(random_bytes(8)) . '@example.com');
	$m->set('iem_received_time', $received ?: gmdate('Y-m-d H:i:s'));
	$m->save();
	$id = (int)$m->key;
	harness_register_model('InboundEmailMessage', $id);
	return $id;
};

$exists = function ($id) use ($db) {
	$q = $db->prepare('SELECT 1 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?');
	$q->execute(array($id));
	return (bool)$q->fetchColumn();
};

// ---------------------------------------------------------------------------
section('Where the passes are allowed to run at all');

// These are DELETE passes. The gate is the only thing standing between them and
// a customer's database: deploy-tier tests declare `env: any` and run on
// production nodes via `run.php deploy`, so an env check alone would have let a
// permanent-delete sweep loose there.
check(harness_mail_cleanup_allowed(),
	'allowed here — db tier, debug on, a store alias set');

$saved_tier = $h['meta']['tier'];
foreach (array('safe', 'deploy') as $tier) {
	$h['meta']['tier'] = $tier;
	check(!harness_mail_cleanup_allowed(),
		"refused in the $tier tier — it cannot send mail and must not delete any");
}
$h['meta']['tier'] = $saved_tier;

$saved_recipient = $h['test_recipient'];
$h['test_recipient'] = '';
check(!harness_mail_cleanup_allowed(),
	'refused with no store alias — nothing to recognise, so nothing to delete');
$h['test_recipient'] = $saved_recipient;

// The domain anchor. harness_fixture_email() mints exactly one domain, and both
// patterns are anchored to it, so mail at a customer's own domain to an address
// that merely starts harnesstest_ can never match.
check(substr(harness_fixture_email('x'), -strlen('@' . HARNESS_FIXTURE_DOMAIN))
		=== '@' . HARNESS_FIXTURE_DOMAIN,
	'a fixture address is always at the one anchored domain', harness_fixture_email('x'));

// ---------------------------------------------------------------------------
section('The naming rule the cleanup depends on');

$addr = harness_fixture_email('CleanupProbe');
check(strpos($addr, 'harnesstest_cleanupprobe_') === 0,
	'harness_fixture_email() lowercases the label under the shared prefix', $addr);
check(strpos($addr, '_' . $h['run_token'] . '@') !== false,
	'the address carries THIS run token, which is what scopes the cleanup', $addr);
check($addr === harness_fixture_email('CleanupProbe'),
	'the same label yields the same address within a run');

// A user fixture must use the same rule, or its mail is unrecognisable.
check(strpos((string)$owner->get('usr_email'), '_' . $h['run_token'] . '@') !== false,
	'make_user() addresses come from the same rule', (string)$owner->get('usr_email'));

// ---------------------------------------------------------------------------
section('What the run caused is removed');

$store_alias = (string)$h['test_recipient'];
check($store_alias !== '', 'the run recorded its test store alias', $store_alias);

$mine_store   = $plant($store_alias);
$mine_fixture = $plant($addr);

harness_cleanup_delivered_mail();

check(!$exists($mine_store),
	'mail redirected to the test store alias is removed');
check(!$exists($mine_fixture),
	'mail delivered to this run\'s fixture address is removed');

// ---------------------------------------------------------------------------
section('What is not the run\'s to delete survives');

// Each of these differs from a removable row in ONE respect.
$before_run = $plant($store_alias, null, gmdate('Y-m-d H:i:s', strtotime($h['started_utc']) - 3600));
$in_mailbox = $plant($store_alias, $alias_id);
$unrelated  = $plant('someone@' . $domain->get('ied_domain'));
$other_run  = $plant('harnesstest_probe_' . bin2hex(random_bytes(4)) . '@dev.getjoinery.com');

harness_cleanup_delivered_mail();

check($exists($before_run),
	'mail that arrived before this run booted is left alone — it is not this run\'s');
check($exists($in_mailbox),
	'mail delivered into a real mailbox is left alone — a suite may be asserting on it');
check($exists($unrelated),
	'ordinary unmatched mail at an unrelated address is left alone');
check($exists($other_run),
	'a fixture address carrying ANOTHER run token is left alone — prefix alone is not enough');

// The production case, planted rather than inferred: a customer's own mail to an
// address that merely starts harnesstest_ at THEIR domain. Both patterns are
// anchored to HARNESS_FIXTURE_DOMAIN precisely so this can never match.
$foreign_now  = $plant('harnesstest_realperson@' . $domain->get('ied_domain'));
$foreign_old  = $plant('harnesstest_realperson2@' . $domain->get('ied_domain'),
	null, gmdate('Y-m-d H:i:s', time() - 3600));
harness_cleanup_delivered_mail();
harness_cleanup_stale_delivered_mail();
check($exists($foreign_now) && $exists($foreign_old),
	'harnesstest_ mail at somebody else\'s domain is never touched, at any age');

// ---------------------------------------------------------------------------
section('The boot pass collects what earlier runs left behind');

// The teardown pass cannot catch mail delivered after it runs, and a real run
// was measured leaving exactly that. The boot pass is the answer: by the time
// the next run starts, the previous run's mail has certainly landed. Its safety
// is an age floor, since it matches ANY fixture address rather than this run's.
$stale_store   = $plant($store_alias, null, gmdate('Y-m-d H:i:s', time() - 3600));
$stale_fixture = $plant('harnesstest_earlier_' . bin2hex(random_bytes(4)) . '@dev.getjoinery.com',
	null, gmdate('Y-m-d H:i:s', time() - 3600));

// Younger than the floor: may belong to a lane still running beside this one.
$fresh_other   = $plant('harnesstest_concurrent_' . bin2hex(random_bytes(4)) . '@dev.getjoinery.com');
$stale_mailbox = $plant($store_alias, $alias_id, gmdate('Y-m-d H:i:s', time() - 3600));
$stale_unrelated = $plant('nobody@' . $domain->get('ied_domain'), null, gmdate('Y-m-d H:i:s', time() - 3600));

harness_cleanup_stale_delivered_mail();

check(!$exists($stale_store),
	'an earlier run\'s mail at the test store alias is collected');
check(!$exists($stale_fixture),
	'an earlier run\'s fixture mail is collected even though the token is not ours');
check($exists($fresh_other),
	'recent fixture mail is left alone — a lane running beside this one may own it');
check($exists($stale_mailbox),
	'old mail in a real mailbox is still left alone');
check($exists($stale_unrelated),
	'old unmatched mail at an unrelated address is still left alone');

// ---------------------------------------------------------------------------
section('Running it twice is harmless');

// Teardown can fire twice (a clean finish, then the shutdown reporter), and a
// crash can leave the second call facing rows the first already removed.
$again = $plant($store_alias);
harness_cleanup_delivered_mail();
harness_cleanup_delivered_mail();
check(!$exists($again), 'a second pass over an already-cleaned box is a no-op, not an error');

harness_finish();
