<?php
/** @joinery-test
 * name: direct_store_logged
 * tier: db
 * env: dev-only
 * needs: []
 */

/**
 * A Joinery Direct delivery shows on the Logs tab, like every other delivery.
 *
 * A Direct message never crosses SMTP, so nothing on the transaction log recorded
 * it — an operator watching the Logs tab saw mail arrive with no trace of how.
 * storeDirectMessage now writes a `stored` transaction row exactly as the SMTP
 * store does. (The stored message ROW is what the per-domain volume cap counts,
 * so a Direct delivery already counts toward that cap.)
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tests/lib/mailbox_test_fixture.php'));

$suffix = substr(md5(uniqid('dsl', true)), 0, 8);
$domain_name = 'dsl-test-' . $suffix . '.example';

mailbox_purge_domains('dsl-test-%');
harness_defer(function () { mailbox_purge_domains('dsl-test-%'); });

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', $domain_name);
$domain->set('ied_is_enabled', true);
$domain->save();
$domain_id = intval($domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', $domain_id);
$alias->set('iea_alias', 'inbox');
$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();

$recipient = 'inbox@' . $domain_name;
$db = DbConnector::get_instance()->get_db_link();

// ---------------------------------------------------------------------------
section('Storing a Direct delivery writes a stored transaction row');
// ---------------------------------------------------------------------------

$router = new InboundEmailRouter();
$meta = array('sender' => 'someone@elsewhere.test', 'subject' => 'A Direct hello',
	'message_id' => '<dsl-' . $suffix . '@elsewhere.test>', 'references' => '', 'in_reply_to' => '',
	'received_time' => gmdate('Y-m-d H:i:s'));
$parts = array('body_plain' => 'plain body', 'body_html' => '', 'attachments' => array());

$result = $router->storeDirectMessage($meta, $parts, $alias, $domain, $recipient, true);
check(!empty($result['message']), 'the message stored');

$stmt = $db->prepare("SELECT COUNT(*) FROM iel_inbound_email_logs
	WHERE iel_ied_inbound_email_domain_id = ? AND iel_status = 'stored'
	  AND LOWER(iel_to_address) = ?");
$stmt->execute(array($domain_id, strtolower($recipient)));
check((int)$stmt->fetchColumn() === 1,
	'a single stored transaction row is on the Logs tab for the Direct delivery');

// ---------------------------------------------------------------------------
section('A duplicate Direct delivery is logged as stored, not lost silently');
// ---------------------------------------------------------------------------

$dup = $router->storeDirectMessage($meta, $parts, $alias, $domain, $recipient, true);
check(!empty($dup['dedup']), 'the same Message-ID dedups rather than storing twice');

$stmt->execute(array($domain_id, strtolower($recipient)));
check((int)$stmt->fetchColumn() === 2,
	'and the dedup still leaves a Logs-tab row, so the retry is visible not invisible');

harness_finish();
