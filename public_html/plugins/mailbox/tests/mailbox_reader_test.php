<?php
/**
 * Tests for the Mailbox Reader service + viewer scope + thread-key computation.
 *
 * Covers:
 *  - MailboxViewer.accessibleAliasIds / scopeAliasIds (grant vs all-access)
 *  - MailboxService.listMailboxes / listThreads grouping, unread, any-starred, search
 *  - Unmatched (NULL-alias) visibility (hidden from grantees, shown to superadmin)
 *  - Mutations (markRead/setStarred/softDelete), shared state, out-of-scope rejection
 *  - InboundEmailRouter.computeThreadKey precedence
 *
 * Run: php plugins/mailbox/tests/mailbox_reader_test.php
 * (requires schema synced — iem threading/state columns + ieg table).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class MailboxReaderTest {
	private $pass = 0;
	private $fail = 0;
	private $db;

	private $suffix;
	private $msg_counter = 0;
	private $domain_id;
	private $beth_alias;
	private $legal_alias;
	private $other_alias;
	private $beth_user;
	private $bob_user;
	private $msg_ids = array(); // label => id

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		if ($c) { $this->pass++; $this->out('  PASS: ' . $l); }
		else { $this->fail++; $this->out('  FAIL: ' . $l); }
	}

	function run() {
		$this->out('=== Mailbox Reader tests ===');
		try {
			$this->setUp();
			$this->testThreadKey();
			$this->testScope();
			$this->testListThreads();
			$this->testUnmatched();
			$this->testSearch();
			$this->testMutations();
		} catch (\Throwable $e) {
			$this->fail++;
			$this->out('  EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
		$this->out("=== {$this->pass} passed, {$this->fail} failed ===");
		return $this->fail === 0;
	}

	private function setUp() {
		$this->preClean();
		$suffix = substr(md5(uniqid('rdr', true)), 0, 8);
		$this->suffix = $suffix;

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'reader-test-' . $suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->save();
		$this->domain_id = intval($domain->key);

		$this->beth_alias  = $this->makeAlias('beth' . $suffix);
		$this->legal_alias = $this->makeAlias('legal' . $suffix);
		$this->other_alias = $this->makeAlias('other' . $suffix);

		$this->beth_user = $this->makeUser('rdr_beth_' . $suffix . '@example.test');
		$this->bob_user  = $this->makeUser('rdr_bob_' . $suffix . '@example.test');

		// Beth: beth@ + legal@. Bob: legal@ only.
		InboundEmailMailboxGrant::sync_for_alias($this->beth_alias, [$this->beth_user]);
		InboundEmailMailboxGrant::sync_for_alias($this->legal_alias, [$this->beth_user, $this->bob_user]);
		// other_alias: granted to nobody (only superadmin sees it).

		// Messages:
		// T1 in beth@ — two-message thread, both unread.
		$this->msg_ids['t1a'] = $this->insertMsg($this->beth_alias, '<t1@x>', 'Hello there', false, false, 30);
		$this->msg_ids['t1b'] = $this->insertMsg($this->beth_alias, '<t1@x>', 'Re: Hello there', false, false, 20);
		// T2 in legal@ — single, unread, starred, searchable subject.
		$this->msg_ids['t2']  = $this->insertMsg($this->legal_alias, '<t2@x>', 'Invoice please', false, true, 25);
		// Other alias thread (un-granted to beth/bob).
		$this->msg_ids['o1']  = $this->insertMsg($this->other_alias, '<o1@x>', 'Secret', false, false, 15);
		// Unmatched (NULL alias).
		$this->msg_ids['u1']  = $this->insertMsg(null, '<u1@x>', 'Nowhere', false, false, 10);

		$this->out("  fixtures ready (suffix $suffix)");
	}

	/**
	 * Remove any leftover test rows from prior runs so the suite is self-healing
	 * (raw alias delete does not cascade grants, so debris can accumulate).
	 */
	private function preClean() {
		try {
			// Orphaned grants (their alias no longer exists).
			$this->db->exec("DELETE FROM ieg_inbound_email_mailbox_grants
				WHERE ieg_iea_inbound_email_alias_id NOT IN
				(SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases)");

			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'reader-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			if ($dids) {
				$in = implode(',', array_map('intval', $dids));
				$aids = $this->db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
					WHERE iea_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
				if ($aids) {
					$ain = implode(',', array_map('intval', $aids));
					$this->db->exec("DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id IN ($ain)");
				}
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id IN ($in)");
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id IN ($in)");
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id IN ($in)");
			}
			$this->db->exec("DELETE FROM usr_users WHERE usr_email LIKE 'rdr\\_%@example.test'");
		} catch (\Throwable $e) {}
	}

	private function makeAlias($local) {
		$a = new InboundEmailAlias(NULL);
		$a->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$a->set('iea_alias', $local);
		$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$a->set('iea_is_enabled', true);
		$a->prepare();
		$a->save();
		return intval($a->key);
	}

	private function makeUser($email) {
		// Raw insert to bypass the User model's email-deliverability validation.
		$stmt = $this->db->prepare("INSERT INTO usr_users
			(usr_first_name, usr_email, usr_timezone, usr_permission)
			VALUES ('Reader', ?, 'UTC', 5) RETURNING usr_user_id");
		$stmt->execute([$email]);
		return intval($stmt->fetchColumn());
	}

	private function insertMsg($alias_id, $thread_key, $subject, $is_read, $is_starred, $minutes_ago) {
		// thread_key is shared within a conversation (that is the whole point);
		// Message-ID must be unique per row or the dedup UNIQUE constraint trips.
		$this->msg_counter++;
		$message_id = '<m' . $this->msg_counter . '_' . $this->suffix . '@x>';
		$sql = "INSERT INTO iem_inbound_email_messages
			(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_sender,
			 iem_recipient, iem_subject, iem_body_plain, iem_body_html, iem_message_id_header,
			 iem_thread_key, iem_is_read, iem_is_starred, iem_received_time)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now() - (? || ' minutes')::interval)
			RETURNING iem_inbound_email_message_id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			$this->domain_id,
			$alias_id,
			'sender_' . $this->suffix . '@out.test',
			'rcpt_' . $this->suffix . '@in.test',
			$subject,
			'plain body ' . $subject,
			'',
			$message_id,
			$thread_key,
			$is_read ? 't' : 'f',
			$is_starred ? 't' : 'f',
			$minutes_ago,
		]);
		return intval($stmt->fetchColumn());
	}

	private function bethViewer() { return MailboxViewer::forUser($this->beth_user, 5); }
	private function bobViewer()  { return MailboxViewer::forUser($this->bob_user, 5); }
	private function superViewer(){ return MailboxViewer::forUser(0, 10); }

	private function threadKeys($result) {
		$keys = array();
		foreach ($result['threads'] as $t) { $keys[] = $t['thread_key']; }
		return $keys;
	}

	// ---- thread key precedence ----
	private function testThreadKey() {
		$this->out('-- computeThreadKey --');
		$r = new InboundEmailRouter();

		$refs = ['headers' => ['references' => '<root@a> <mid@b>']];
		$this->ok($r->computeThreadKey($refs, '<own@c>') === '<root@a>', 'References → first token');

		$irt = ['headers' => ['in-reply-to' => '<parent@a>']];
		$this->ok($r->computeThreadKey($irt, '<own@c>') === '<parent@a>', 'In-Reply-To → that id');

		$own = ['headers' => []];
		$this->ok($r->computeThreadKey($own, '<own@c>') === '<own@c>', 'own Message-ID → singleton');

		$none = ['headers' => []];
		$this->ok($r->computeThreadKey($none, null) === null, 'no Message-ID → null');
	}

	// ---- viewer scope ----
	private function testScope() {
		$this->out('-- viewer scope --');
		$beth = $this->bethViewer();
		$bob = $this->bobViewer();
		$super = $this->superViewer();

		$ba = $beth->accessibleAliasIds();
		$this->ok(in_array($this->beth_alias, $ba, true) && in_array($this->legal_alias, $ba, true)
			&& !in_array($this->other_alias, $ba, true), 'beth accessible = {beth, legal}');

		$boa = $bob->accessibleAliasIds();
		$this->ok(in_array($this->legal_alias, $boa, true) && !in_array($this->beth_alias, $boa, true),
			'bob accessible = {legal}');

		$sa = $super->accessibleAliasIds();
		$this->ok(in_array($this->beth_alias, $sa, true) && in_array($this->legal_alias, $sa, true)
			&& in_array($this->other_alias, $sa, true), 'superadmin all-access includes every alias');
		$this->ok($super->isAllAccess() && !$beth->isAllAccess(), 'isAllAccess only for superadmin');

		$this->ok($beth->scopeAliasIds($this->beth_alias) === [$this->beth_alias], 'scope(own) = [own]');
		$this->ok($beth->scopeAliasIds($this->other_alias) === [], 'scope(foreign) = [] (nothing)');
		$union = $beth->scopeAliasIds(null);
		$this->ok(count($union) === 2 && in_array($this->legal_alias, $union, true), 'scope(null) = accessible union');

		// getThread on an un-granted thread is empty.
		$svc = new MailboxService($beth);
		$this->ok(count($svc->getThread(null, '<o1@x>')) === 0, 'getThread on un-granted thread is empty');
		// A grant means full access — read and send-as — so a viewer with any
		// accessible mailbox may compose.
		$this->ok($beth->canCompose() === true, 'canCompose true with accessible mailboxes');
		$this->ok(MailboxViewer::forUser(-1, 1)->canCompose() === false, 'canCompose false with none');
	}

	// ---- list / grouping ----
	private function testListThreads() {
		$this->out('-- listThreads grouping --');
		$svc = new MailboxService($this->bethViewer());

		$all = $svc->listThreads(null, array(), 1, 50);
		$keys = $this->threadKeys($all);
		$this->ok(in_array('<t1@x>', $keys, true) && in_array('<t2@x>', $keys, true), 'beth sees T1 and T2');
		$this->ok(!in_array('<o1@x>', $keys, true) && !in_array('<u1@x>', $keys, true),
			'beth does NOT see other-alias or unmatched');

		// T1 is a 2-message thread, both unread.
		$t1 = null; $t2 = null;
		foreach ($all['threads'] as $t) {
			if ($t['thread_key'] === '<t1@x>') $t1 = $t;
			if ($t['thread_key'] === '<t2@x>') $t2 = $t;
		}
		$this->ok($t1 && $t1['msg_count'] === 2 && $t1['unread_count'] === 2, 'T1 grouped: 2 msgs, 2 unread');
		$this->ok($t2 && $t2['any_starred'] === true, 'T2 any_starred true');

		// Scoped to a single mailbox.
		$bethbox = $svc->listThreads($this->beth_alias, array(), 1, 50);
		$this->ok($this->threadKeys($bethbox) === ['<t1@x>'], 'beth@ mailbox shows only T1');

		// Bob (legal only) sees T2, not T1.
		$bobsvc = new MailboxService($this->bobViewer());
		$bobkeys = $this->threadKeys($bobsvc->listThreads(null, array(), 1, 50));
		$this->ok(in_array('<t2@x>', $bobkeys, true) && !in_array('<t1@x>', $bobkeys, true),
			'bob sees legal@ T2 but not beth@ T1');

		// listMailboxes unread counts.
		$mb = $svc->listMailboxes();
		$beth_box = null; $legal_box = null;
		foreach ($mb['mailboxes'] as $m) {
			if ($m['alias_id'] === $this->beth_alias) $beth_box = $m;
			if ($m['alias_id'] === $this->legal_alias) $legal_box = $m;
		}
		$this->ok($beth_box && $beth_box['unread'] === 2 && $beth_box['total'] === 2, 'beth@ unread/total = 2/2');
		$this->ok($legal_box && $legal_box['unread'] === 1, 'legal@ unread = 1');
	}

	// ---- unmatched ----
	private function testUnmatched() {
		$this->out('-- unmatched mail --');
		$super = new MailboxService($this->superViewer());
		$keys = $this->threadKeys($super->listThreads(null, array(), 1, 50));
		$this->ok(in_array('<u1@x>', $keys, true), 'superadmin All mail includes unmatched');

		$mb = $super->listMailboxes();
		$this->ok(isset($mb['unmatched']) && $mb['unmatched']['total'] >= 1, 'listMailboxes reports unmatched count');
		$this->ok($mb['all_access'] === true && isset($mb['all_mail']), 'all_access flag + all_mail present');
	}

	// ---- search ----
	private function testSearch() {
		$this->out('-- search --');
		$svc = new MailboxService($this->bethViewer());
		$res = $svc->listThreads(null, array('q' => 'invoice'), 1, 50);
		$keys = $this->threadKeys($res);
		$this->ok($keys === ['<t2@x>'], 'full-text search "invoice" returns only T2');

		$res2 = $svc->listThreads(null, array('starred_only' => true), 1, 50);
		$this->ok($this->threadKeys($res2) === ['<t2@x>'], 'starred_only returns only T2');

		$res3 = $svc->listThreads(null, array('unread_only' => true), 1, 50);
		$this->ok(in_array('<t1@x>', $this->threadKeys($res3), true), 'unread_only includes T1');
	}

	// ---- mutations ----
	private function testMutations() {
		$this->out('-- mutations + shared state + scope --');
		$beth = new MailboxService($this->bethViewer());
		$bob = new MailboxService($this->bobViewer());

		// Out-of-scope: bob marking beth@ T1 affects nothing.
		$n = $bob->markRead([$this->msg_ids['t1a'], $this->msg_ids['t1b']], true);
		$this->ok($n === 0, 'bob cannot mark beth@ messages (0 affected)');
		$this->ok($this->isRead($this->msg_ids['t1a']) === false, 'T1 still unread after out-of-scope attempt');

		// Beth marks T1 read via thread expansion.
		$ids = $beth->messageIdsInThread(null, '<t1@x>');
		$this->ok(count($ids) === 2, 'messageIdsInThread expands T1 to 2 ids');
		$beth->markRead($ids, true);
		$this->ok($this->isRead($this->msg_ids['t1a']) && $this->isRead($this->msg_ids['t1b']), 'T1 marked read');
		$this->ok($this->readTime($this->msg_ids['t1a']) !== null, 'read_time set on first read');

		// Shared state: beth marks legal@ T2 read; bob (also on legal@) sees it read.
		$beth->markRead([$this->msg_ids['t2']], true);
		$bobview = $bob->listThreads($this->legal_alias, array(), 1, 50);
		$t2 = null;
		foreach ($bobview['threads'] as $t) { if ($t['thread_key'] === '<t2@x>') $t2 = $t; }
		$this->ok($t2 && $t2['unread_count'] === 0, 'shared read state: bob sees T2 read after beth read it');

		// Star / unstar.
		$beth->setStarred([$this->msg_ids['t1a']], true);
		$this->ok($this->isStarred($this->msg_ids['t1a']) === true, 'setStarred true');
		$beth->setStarred([$this->msg_ids['t1a']], false);
		$this->ok($this->isStarred($this->msg_ids['t1a']) === false, 'setStarred false');

		// Soft delete hides from the list.
		$beth->softDelete([$this->msg_ids['t2']]);
		$keys = $this->threadKeys($beth->listThreads($this->legal_alias, array(), 1, 50));
		$this->ok(!in_array('<t2@x>', $keys, true), 'soft-deleted thread hidden from list');
	}

	private function scalar($id, $col) {
		$stmt = $this->db->prepare("SELECT $col FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
		$stmt->execute([$id]);
		return $stmt->fetchColumn();
	}
	private function isRead($id)    { $v = $this->scalar($id, 'iem_is_read'); return ($v === true || $v === 't'); }
	private function isStarred($id) { $v = $this->scalar($id, 'iem_is_starred'); return ($v === true || $v === 't'); }
	private function readTime($id)  { $v = $this->scalar($id, 'iem_read_time'); return ($v === false || $v === null) ? null : $v; }

	private function tearDown() {
		try {
			$ids = array_filter(array_map('intval', array_values($this->msg_ids)));
			if (count($ids)) {
				$this->db->exec("DELETE FROM iem_inbound_email_messages
					WHERE iem_inbound_email_message_id IN (" . implode(',', $ids) . ")");
			}
		} catch (\Throwable $e) {}
		// Grants don't cascade on raw alias delete (no DB FK), so clean them first.
		$aids = array_filter(array_map('intval', [$this->beth_alias, $this->legal_alias, $this->other_alias]));
		$uids = array_filter(array_map('intval', [$this->beth_user, $this->bob_user]));
		if ($aids || $uids) {
			$conds = array();
			if ($aids) { $conds[] = 'ieg_iea_inbound_email_alias_id IN (' . implode(',', $aids) . ')'; }
			if ($uids) { $conds[] = 'ieg_usr_user_id IN (' . implode(',', $uids) . ')'; }
			try { $this->db->exec("DELETE FROM ieg_inbound_email_mailbox_grants WHERE " . implode(' OR ', $conds)); } catch (\Throwable $e) {}
		}
		foreach ([$this->beth_alias, $this->legal_alias, $this->other_alias] as $aid) {
			if ($aid) { try { $this->db->prepare("DELETE FROM iea_inbound_email_aliases WHERE iea_inbound_email_alias_id = ?")->execute([$aid]); } catch (\Throwable $e) {} }
		}
		foreach ([$this->beth_user, $this->bob_user] as $uid) {
			if ($uid) { try { $this->db->prepare("DELETE FROM usr_users WHERE usr_user_id = ?")->execute([$uid]); } catch (\Throwable $e) {} }
		}
		if ($this->domain_id) {
			try { $this->db->prepare("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = ?")->execute([$this->domain_id]); } catch (\Throwable $e) {}
		}
	}
}

$tester = new MailboxReaderTest();
$ok = $tester->run();
if (php_sapi_name() === 'cli') {
	exit($ok ? 0 : 1);
}
