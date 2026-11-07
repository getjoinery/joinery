<?php
/** @joinery-test
 * name: mail_read_audit
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Reading mail you hold no grant on is written down (mail_access_hardening WP1,
 * MailboxReadAudit).
 *
 *  - A level-10 viewer with no grant opens a thread: exactly one
 *    mail_foreign_read row, naming the mailbox, the surface and the thread's ids.
 *  - The grant holder opens the same thread: no row. A member with no grant
 *    gets nothing back and no row. The message's sealed owner is a holder too.
 *  - A list page over a mailbox the viewer holds no grant on: one row per
 *    mailbox, with the previewed ids. A download started: one row, the count.
 *  - The row still lands when the process is hot (sealed content was opened)
 *    and the id list is longer than the egress guard's threshold.
 *  - parseNote() reads every note back; forMailbox() finds the mailbox's rows
 *    by the note's first word.
 *
 * Run: php tests/run.php db --filter=mail_read_audit
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

if (!PluginHelper::isPluginActive('mailbox')) { harness_skip('mailbox plugin inactive'); harness_finish(); }

$db = DbConnector::get_instance()->get_db_link();
$router = new InboundEmailRouter();
$suffix = bin2hex(random_bytes(4));

/** The mail_foreign_read rows $viewer_id has written since this test began, newest last; each is registered for cleanup. */
$rows_for = function (int $viewer_id) use ($db): array {
	$q = $db->prepare("SELECT evl_event_log_id, evl_note FROM evl_event_logs WHERE evl_usr_user_id = ? AND evl_event = ?
		AND evl_create_time > now() - interval '10 minutes' ORDER BY evl_event_log_id ASC");
	$q->execute(array($viewer_id, MailboxReadAudit::EVENT));
	$out = array();
	foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
		harness_register_row('evl_event_logs', 'evl_event_log_id', (int)$r['evl_event_log_id']);
		$out[] = (string)$r['evl_note'];
	}
	return $out;
};

// ---- Fixtures --------------------------------------------------------------
$holder = make_user('RdAuH' . $suffix, 0);
$admin  = make_user('RdAuA' . $suffix, 10);
$member = make_user('RdAuM' . $suffix, 0);
$hid = (int)$holder->key; $aid = (int)$admin->key; $mid = (int)$member->key;

$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'rdau-' . $suffix . '.example');
$domain->set('ied_owner_usr_user_id', $hid);
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_row('ied_inbound_email_domains', 'ied_inbound_email_domain_id', (int)$domain->key);

$alias = new InboundEmailAlias(NULL);
$alias->set('iea_ied_inbound_email_domain_id', (int)$domain->key);
$alias->set('iea_alias', 'inbox');
$alias->set('iea_delivery_mode', 'store');
$alias->set('iea_is_enabled', true);
$alias->save();
harness_register_row('iea_inbound_email_aliases', 'iea_inbound_email_alias_id', (int)$alias->key);
$alias_id = (int)$alias->key;

$grant = new InboundEmailMailboxGrant(NULL);
$grant->set('ieg_iea_inbound_email_alias_id', $alias_id);
$grant->set('ieg_usr_user_id', $hid);
$grant->save();
harness_register_row('ieg_inbound_email_mailbox_grants', 'ieg_inbound_email_mailbox_grant_id', (int)$grant->key);

$address = 'inbox@' . $domain->get('ied_domain');
$store = function (string $token) use ($router, $alias, $domain, $address): InboundEmailMessage {
	$raw = implode("\r\n", array(
		'From: Sender <sender@example.com>',
		'To: ' . $address,
		'Subject: Audit ' . $token,
		'Message-ID: <' . $token . '@example.com>',
		'Date: Mon, 25 Aug 2026 12:00:00 +0000',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'',
		'Body of ' . $token . '.',
		'',
	));
	$res = $router->storeMessage($raw, $router->parseEmail($raw), $alias, $domain, $address);
	harness_register_model('InboundEmailMessage', (int)$res['message']->key);
	return $res['message'];
};
$m1 = $store('rdau-one-' . $suffix);
$m2 = $store('rdau-two-' . $suffix);
$id1 = (int)$m1->key; $id2 = (int)$m2->key;
$thread1 = (string)(new InboundEmailMessage($id1, TRUE))->get('iem_thread_key');

// ---------------------------------------------------------------------------
section('an all-access viewer opening a thread they hold no grant on is written down');

$admin_svc = new MailboxService(MailboxViewer::confirmedForTest($aid, 10));
$thread = $admin_svc->getThread($alias_id, $thread1);
check(count($thread) === 1 && (int)$thread[0]['id'] === $id1, 'the level-10 viewer reads the thread (precondition)');
$rows = $rows_for($aid);
check(count($rows) === 1, 'exactly one mail_foreign_read row', implode(' | ', $rows));
$f = MailboxReadAudit::parseNote($rows[0] ?? '');
check(($f['alias'] ?? null) === $alias_id && ($f['domain'] ?? null) === (int)$domain->key,
	'it names the mailbox and its domain', $rows[0] ?? '');
check(($f['surface'] ?? null) === MailboxReadAudit::SURFACE_THREAD && ($f['count'] ?? null) === 1 && ($f['ids'] ?? null) === array($id1),
	'with the surface, the count and the thread\'s ids', $rows[0] ?? '');
check(strpos($rows[0] ?? '', 'Audit') === false && strpos($rows[0] ?? '', 'sender@') === false,
	'and never a subject or an address');

// ---------------------------------------------------------------------------
section('a grant holder is never written down, a member reaches nothing');

$holder_svc = new MailboxService(MailboxViewer::forUser($hid, 0));
$thread = $holder_svc->getThread($alias_id, $thread1);
check(count($thread) === 1, 'the holder reads their own thread (precondition)');
check($rows_for($hid) === array(), 'and writes no row');

$member_svc = new MailboxService(MailboxViewer::forUser($mid, 0));
check($member_svc->getThread($alias_id, $thread1) === array(), 'a member with no grant gets nothing back');
check($rows_for($mid) === array(), 'and writes no row');

// The message's sealed owner holds it whatever their grants: not foreign.
$db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_owner_user_id = ? WHERE iem_inbound_email_message_id = ?')
	->execute(array($aid, $id1));
$admin_svc->getThread($alias_id, $thread1);
check(count($rows_for($aid)) === 1, 'a level-10 viewer who is the message\'s sealed owner is a holder: no new row');
$db->prepare('UPDATE iem_inbound_email_messages SET iem_sealed_owner_user_id = NULL WHERE iem_inbound_email_message_id = ?')
	->execute(array($id1));

// ---------------------------------------------------------------------------
section('a list page writes one row per mailbox with the previewed ids; a download its count');

$page = $admin_svc->listThreads($alias_id, array(), 1, 50);
check(count($page['threads']) === 2, 'the level-10 viewer lists both threads (precondition)');
$rows = $rows_for($aid);
check(count($rows) === 2, 'one more row: the list page', implode(' | ', $rows));
$f = MailboxReadAudit::parseNote($rows[1] ?? '');
check(($f['surface'] ?? null) === MailboxReadAudit::SURFACE_LIST && ($f['count'] ?? null) === 2 && ($f['ids'] ?? null) === array($id1, $id2),
	'surface list, both previewed ids, sorted', $rows[1] ?? '');

$holder_svc->listThreads($alias_id, array(), 1, 50);
check($rows_for($hid) === array(), 'the holder\'s list page writes none');

MailboxReadAudit::mailbox(MailboxViewer::confirmedForTest($aid, 10), MailboxReadAudit::SURFACE_EXPORT, $alias_id, 2);
$rows = $rows_for($aid);
$f = MailboxReadAudit::parseNote($rows[2] ?? '');
check(count($rows) === 3 && ($f['surface'] ?? null) === MailboxReadAudit::SURFACE_EXPORT
		&& ($f['count'] ?? null) === 2 && ($f['ids'] ?? null) === array(),
	'a download started: surface export, the count, no ids', $rows[2] ?? '');
MailboxReadAudit::mailbox(MailboxViewer::confirmedForTest($hid, 10), MailboxReadAudit::SURFACE_EXPORT, $alias_id, 2);
check($rows_for($hid) === array(), 'a level-10 holder downloading their own mailbox writes none');

// ---------------------------------------------------------------------------
section('the row lands on a hot request with a long id list');

SealedEgressGuard::reset();
SealedEgressGuard::markHot('mail:' . $id1 . ':body');
$many = range(100000, 100039);
MailboxReadAudit::foreign($aid, $alias_id, (int)$domain->key, MailboxReadAudit::SURFACE_AI_QUERY, $many);
SealedEgressGuard::reset();
$rows = $rows_for($aid);
$f = MailboxReadAudit::parseNote($rows[3] ?? '');
check(count($rows) === 4 && ($f['ids'] ?? null) === $many && ($f['count'] ?? null) === 40,
	'forty ids (longer than the guard\'s threshold) written while hot', $rows[3] ?? '');

// ---------------------------------------------------------------------------
section('parseNote reads every note back; a value off its list is other');

$f = MailboxReadAudit::parseNote('alias=0 domain=0 surface=forensic count=1 ids=7');
check($f === array('alias' => 0, 'domain' => 0, 'surface' => 'forensic', 'count' => 1, 'ids' => array(7)),
	'unmatched mail: alias 0, domain 0', json_encode($f));
$f = MailboxReadAudit::parseNote('alias=x domain=5 surface=telepathy count=-1 ids=1,,2 extra=1');
check($f['alias'] === 'other' && $f['surface'] === 'other' && $f['count'] === 'other' && $f['ids'] === 'other'
		&& $f['domain'] === 5 && !array_key_exists('extra', $f),
	'bad words, numbers and lists read as other; an unknown field is dropped', json_encode($f));
check(MailboxReadAudit::parseNote('alias=3 surface=list') === array('alias' => 3, 'surface' => 'list'),
	'a field the note lacks is absent, never guessed');
$threw = false;
try { MailboxReadAudit::foreign($aid, $alias_id, null, 'telepathy', array(1)); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'writing an unknown surface is a programming error, not a row');

// ---------------------------------------------------------------------------
section('the holder finds every read of their mailbox');

$reads = MailboxReadAudit::forMailbox($alias_id);
check(count($reads) === 4, 'four reads of this mailbox', json_encode($reads));
$surfaces = array_column($reads, 'surface');
sort($surfaces);
check($surfaces === array('ai_query', 'export', 'list', 'thread'), 'one of each surface written above', implode(',', $surfaces));
check(count(array_unique(array_column($reads, 'viewer_id'))) === 1 && $reads[0]['viewer_id'] === $aid,
	'all by the level-10 viewer');
check(MailboxReadAudit::forMailbox($alias_id, 0) === array() || count(MailboxReadAudit::forMailbox($alias_id, 1)) === 4,
	'the window is in days (today\'s reads are within one day)');

harness_finish();
