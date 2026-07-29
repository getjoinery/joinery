<?php
/** @joinery-test
 * name: index_readable_text
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The search index stores what a person can READ, not the document's markup.
 *
 * Bulk mail carries its stylesheet inside the message body, so indexing
 * tag-stripped HTML would make every sender's CSS searchable — a search for
 * "container" or "sans-serif" would match hundreds of unrelated messages.
 * MailboxIndex::rowContent() reduces the HTML body through
 * MailboxHtmlSanitizer::toReadableText() instead.
 *
 * Uses an unsealed owner: MailboxIndex reads content through the same get() hook
 * for sealed and never-sealed rows, so this needs no vault/unlock window
 * (persist() is a no-op without one; the /dev/shm working copy is what search
 * reads). Same shape as drafts_fts_refold.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_mailbox_search_index_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxIndex.php'));

if (!is_dir(MailboxIndex::SHM_DIR)) {
	section('index content');
	check(true, 'skipped: ' . MailboxIndex::SHM_DIR . ' unavailable', 'no shm');
	harness_finish();
	return;
}

$owner = make_user('IdxReadable', 5);
$uid = (int)$owner->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'idxrt-' . bin2hex(random_bytes(4)) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'idxrt');
$alias->set('iea_delivery_mode', 'store');
$alias->set('iea_is_enabled', true);
$alias->prepare();
$alias->save();
$alias_id = (int)$alias->key;
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', $alias_id);

$g = new InboundEmailMailboxGrant(NULL);
$g->set('ieg_iea_inbound_email_alias_id', $alias_id);
$g->set('ieg_usr_user_id', $uid);
$g->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$g->key);

// A newsletter shaped like the real thing: stylesheet in the head, a conditional
// comment, a table-built body. Every token below is nonsense so nothing else in
// the estate can match it.
$html = '<html><head><title>idxrttitleword</title>'
	. '<style type="text/css">a.idxrtcssword{-moz-box-sizing:content-box !important;}'
	. '@media only screen and (min-width:768px){.idxrtmediaword{width:600px !important;}}'
	. '</style></head><body>'
	. '<!--[if mso]><table><tr><td>idxrtmsoword</td></tr></table><![endif]-->'
	. '<script>var idxrtscriptword = 1;</script>'
	. '<table><tr><td>idxrtbodyword</td><td>idxrtsecondcell</td></tr></table>'
	. '<p>Read the <a href="https://idxrthostword.example/idxrtpathword">idxrtlinkword</a>.</p>'
	. '</body></html>';

$msg = new InboundEmailMessage(NULL);
$msg->set('iem_ied_inbound_email_domain_id', (int)$domain->key);
$msg->set('iem_iea_inbound_email_alias_id', $alias_id);
$msg->set('iem_direction', 'inbound');
$msg->set('iem_sender', 'sender@example.com');
$msg->set('iem_recipient', 'idxrt@example.com');
$msg->set('iem_subject', 'idxrtsubjectword');
$msg->set('iem_body_plain', '');
$msg->set('iem_body_html', $html);
$msg->set('iem_message_id_header', 'idxrt-' . bin2hex(random_bytes(8)) . '@example.com');
$msg->set('iem_received_time', gmdate('Y-m-d H:i:s'));
$msg->save();
$mid = (int)$msg->key;
harness_register_row('iem_inbound_email_messages', 'iem_inbound_email_message_id', $mid);

$idx = new MailboxIndex();
$idx->wipe($uid);              // start from a clean working copy
$idx->fold($uid, 'dummy-secret');

$hits = function ($term) use ($idx, $uid) { return $idx->search($uid, $term); };

section('Readable content is indexed');
check($hits('idxrtsubjectword') === array($mid), 'subject is searchable', json_encode($hits('idxrtsubjectword')));
check($hits('idxrtbodyword') === array($mid), 'body text inside a table cell is searchable', json_encode($hits('idxrtbodyword')));
check($hits('idxrtsecondcell') === array($mid), 'a second cell is searchable too');
check($hits('idxrtlinkword') === array($mid), 'link text is searchable');

section('Markup is NOT indexed');
check($hits('idxrtcssword') === array(), 'a CSS selector does not match');
check($hits('idxrtmediaword') === array(), 'a media-query class does not match');
check($hits('idxrtscriptword') === array(), 'a script identifier does not match');
check($hits('idxrtmsoword') === array(), 'text inside an MSO conditional comment does not match');
check($hits('idxrttitleword') === array(), 'the document title does not match');
check($hits('idxrthostword') === array(), 'a link href host does not match');
check($hits('idxrtpathword') === array(), 'a link href path does not match');

$idx->wipe($uid);
harness_finish();
