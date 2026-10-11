<?php
/** @joinery-test
 * name: mail_apps_scale
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 1200
 */
/**
 * A 100,000-message folder (specs/mail_apps_plugin.md § How Thunderbird
 * learns what changed, "Cost"): times what a mail program costs the site, and
 * fails if the check every idle connection repeats is too slow to repeat.
 *
 * Seeds the messages with one bulk INSERT (the arrival pipeline would take
 * hours and measures nothing here), then times:
 *   - the first reconcile, which numbers every message;
 *   - a reconcile with nothing changed — what each IDLE poll pays, every 30 s
 *     per idling folder;
 *   - a reconcile after 100 flag changes made through mailbox;
 *   - the full flag listing every SELECT asks for (Thunderbird does not use
 *     CONDSTORE: F1), and its JSON size.
 * Every row it made is deleted at the end.
 *
 * Budgets (fail above): idle reconcile 1.5 s, changed reconcile 3 s, full
 * listing 6 s. Measured numbers are printed either way.
 *
 * Run: php -d apc.enable_cli=1 plugins/mail_apps/tests/mail_apps_scale_test.php [count]
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!class_exists('MailAppContext')) {
	harness_skip('the mail_apps plugin is not active here');
	harness_finish();
}
require_once(__DIR__ . '/lib/mail_apps_fixtures.php');
harness_set_setting_mem('mail_apps_enabled', '1');

$count = 100000;
foreach ($argv ?? array() as $arg) {
	if (ctype_digit((string)$arg) && (int)$arg > 0) { $count = (int)$arg; }
}
$db = DbConnector::get_instance()->get_db_link();
$user = make_user('MailAppsScale', 0);
$uid = (int)$user->key;
$domain = mafx_domain('scale');
$alias = mafx_alias($domain, 'big', $uid);
$address = strtolower($alias->get_full_address());
$alias_id = (int)$alias->key;
$domain_id = (int)$domain->key;
// Registered LAST, so it runs FIRST at teardown (the harness unwinds in reverse):
// the bulk rows must go before the mailbox and domain fixtures do. Matched by
// domain as well as mailbox, so rows a mailbox delete already unlinked still go.
harness_defer(function () use ($db, $alias_id, $domain_id) {
	$db->prepare('DELETE FROM iem_inbound_email_messages
		WHERE iem_iea_inbound_email_alias_id = ? OR iem_ied_inbound_email_domain_id = ?')->execute(array($alias_id, $domain_id));
	SessionControl::get_instance()->clear_api_user();
});

$timed = function (callable $fn) { $t = microtime(true); $r = $fn(); return array($r, microtime(true) - $t); };

section("Seeding $count messages");
list(, $seed) = $timed(function () use ($db, $alias_id, $domain, $count, $address) {
	$db->prepare("INSERT INTO iem_inbound_email_messages
		(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_sender, iem_recipient, iem_subject,
		 iem_body_plain, iem_body_html, iem_raw_message, iem_message_id_header, iem_direction, iem_is_read,
		 iem_is_archived, iem_received_time, iem_size_bytes)
		SELECT ?, ?, 'sender' || g || '@example.org', ?, 'Message ' || g, 'body ' || g, '', '',
		       '<scale-' || g || '-' || ? || '@example.org>', 'inbound', (g % 3 = 0), false,
		       now() - (g || ' minutes')::interval, 1000
		FROM generate_series(1, ?) g")
		->execute(array((int)$domain->key, $alias_id, $address, bin2hex(random_bytes(4)), $count));
});
printf("  seeded in %.1f s\n", $seed);

$ctx = mafx_context($uid, $address);
$inbox = MailAppFolders::byKey($ctx, 'inbox');

section('First reconcile (numbering every message)');
list($state, $first) = $timed(function () use ($ctx, $inbox) { return MailAppFolders::reconcile($ctx, $inbox); });
printf("  %.2f s, %d numbered\n", $first, $state['numbered']);
check($state['exists'] === $count, "All $count are numbered", json_encode($state));

section('Reconcile with nothing changed (each IDLE poll)');
$idle = array();
for ($i = 0; $i < 5; $i++) {
	list($s, $t) = $timed(function () use ($ctx, $inbox) { return MailAppFolders::reconcile($ctx, $inbox); });
	$idle[] = $t;
}
sort($idle);
printf("  median %.3f s (min %.3f, max %.3f)\n", $idle[2], $idle[0], $idle[4]);
check($idle[2] < 1.5, 'An idle check stays under 1.5 s', sprintf('%.3f s', $idle[2]));

section('Reconcile after 100 flag changes');
$ids = array_map('intval', $db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
	WHERE iem_iea_inbound_email_alias_id = $alias_id ORDER BY random() LIMIT 100")->fetchAll(PDO::FETCH_COLUMN));
$ctx->service->setStarred($ids, true);
list($s, $changed) = $timed(function () use ($ctx, $inbox) { return MailAppFolders::reconcile($ctx, $inbox); });
printf("  %.3f s\n", $changed);
$since = MailAppFolders::rows($ctx, $inbox, $s['highest_modseq'] - 1)['messages'];
check(count($since) === 100, 'The 100 changes are found', count($since) . ' found');
check($changed < 3.0, 'A check with changes stays under 3 s', sprintf('%.3f s', $changed));

section('The full flag listing (every SELECT)');
list($rows, $listing) = $timed(function () use ($ctx, $inbox) { return MailAppFolders::rows($ctx, $inbox); });
$json = strlen(json_encode($rows));
printf("  %.2f s, %.1f MB of JSON\n", $listing, $json / 1048576);
check(count($rows['messages']) === $count, 'Every message is listed');
check($listing < 6.0, 'The full listing stays under 6 s', sprintf('%.2f s', $listing));

harness_finish();
