<?php
/**
 * Tests for the member mailbox mount (specs: inbound_email_profile_mailbox).
 *
 * Covers, at member permission (1, not staff):
 *  - Scope proofs: a granted member lists only their granted mailboxes;
 *    threads/messages/actions never leak another alias
 *  - Grantless signed-in user: empty mailbox list, empty threads, no-op actions
 *  - canCompose: granted true, grantless false, superadmin true
 *  - Send-as scope: a non-granted member is rejected by the scope check; a
 *    granted member passes it (send not completed — no real SMTP in tests)
 *  - Attachment access: the member-endpoint authorization rule (viewer can
 *    access the message's alias; NULL-alias is superadmin-only) and shared
 *    retrieval of file-backed bytes
 *  - Anonymous HTTP requests to all five /ajax/mailbox_* endpoints are 403
 *
 * Run: php plugins/mailbox/tests/profile_mailbox_test.php [base_url] [origin_ip]
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxService.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

class ProfileMailboxTest {
	private $pass = 0;
	private $fail = 0;
	private $db;

	private $base_url;
	private $origin_ip;

	private $suffix;
	private $msg_counter = 0;
	private $domain_id;
	private $mine_alias;    // granted to member
	private $other_alias;   // granted to nobody
	private $member_user;   // permission 1, granted mine_alias
	private $lonely_user;   // permission 1, no grants
	private $msg_mine;
	private $msg_other;
	private $msg_unmatched;
	private $att_file;      // File backing the attachment
	private $att_id;        // manifest row id
	private $att_bytes;

	function __construct($base_url, $origin_ip) {
		$this->db = DbConnector::get_instance()->get_db_link();
		$this->base_url = $base_url;
		$this->origin_ip = $origin_ip;
	}

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		if ($c) { $this->pass++; $this->out('  PASS: ' . $l); }
		else { $this->fail++; $this->out('  FAIL: ' . $l); }
	}

	function run() {
		$this->out('=== Member mailbox tests ===');
		try {
			$this->setUp();
			$this->testMemberScope();
			$this->testGrantless();
			$this->testCanCompose();
			$this->testSendScope();
			$this->testAttachmentAccess();
			$this->testAnonymousHttp();
		} finally {
			$this->tearDown();
		}
		$this->out("=== {$this->pass} passed, {$this->fail} failed ===");
		return $this->fail === 0;
	}

	private function setUp() {
		$this->preClean();
		$suffix = substr(md5(uniqid('pmx', true)), 0, 8);
		$this->suffix = $suffix;

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'member-test-' . $suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->save();
		$this->domain_id = intval($domain->key);

		$this->mine_alias  = $this->makeAlias('mine' . $suffix);
		$this->other_alias = $this->makeAlias('other' . $suffix);

		// Regular members: permission 1 — the whole point of the member mount.
		$this->member_user = $this->makeUser('pmx_member_' . $suffix . '@example.test');
		$this->lonely_user = $this->makeUser('pmx_lonely_' . $suffix . '@example.test');

		InboundEmailMailboxGrant::sync_for_alias($this->mine_alias, [$this->member_user]);

		$this->msg_mine      = $this->insertMsg($this->mine_alias, '<pm1@x>', 'Mine hello');
		$this->msg_other     = $this->insertMsg($this->other_alias, '<pm2@x>', 'Other secret');
		$this->msg_unmatched = $this->insertMsg(null, '<pm3@x>', 'Unmatched');

		// File-backed attachment on the member's message.
		$this->att_bytes = 'member-attachment-' . $suffix;
		$this->att_file = File::createFromBytes(
			$this->att_bytes, 'pmx_att_' . $suffix . '.txt', 'text/plain', 1,
			array('fil_private' => true)
		);
		$att = InboundMessageAttachment::CreateEntry(array(
			'ima_iem_inbound_email_message_id' => $this->msg_mine,
			'ima_filename'     => 'pmx_att_' . $suffix . '.txt',
			'ima_content_type' => 'text/plain',
			'ima_size_bytes'   => strlen($this->att_bytes),
			'ima_is_inline'    => false,
			'ima_fil_file_id'  => intval($this->att_file->key),
		));
		$this->att_id = intval($att->key);

		$this->out("  fixtures ready (suffix $suffix)");
	}

	private function preClean() {
		try {
			$this->db->exec("DELETE FROM ieg_inbound_email_mailbox_grants
				WHERE ieg_iea_inbound_email_alias_id NOT IN
				(SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases)");
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'member-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			if ($dids) {
				$in = implode(',', array_map('intval', $dids));
				$aids = $this->db->query("SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases
					WHERE iea_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
				if ($aids) {
					$ain = implode(',', array_map('intval', $aids));
					$this->db->exec("DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id IN ($ain)");
				}
				$mids = $this->db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE iem_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
				if ($mids) {
					$min = implode(',', array_map('intval', $mids));
					$this->db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN ($min)");
				}
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id IN ($in)");
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id IN ($in)");
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id IN ($in)");
			}
			$this->db->exec("DELETE FROM usr_users WHERE usr_email LIKE 'pmx\\_%@example.test'");
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
			VALUES ('Member', ?, 'UTC', 1) RETURNING usr_user_id");
		$stmt->execute([$email]);
		return intval($stmt->fetchColumn());
	}

	private function insertMsg($alias_id, $thread_key, $subject) {
		$this->msg_counter++;
		$message_id = '<pm' . $this->msg_counter . '_' . $this->suffix . '@x>';
		$sql = "INSERT INTO iem_inbound_email_messages
			(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_sender,
			 iem_recipient, iem_subject, iem_body_plain, iem_body_html, iem_message_id_header,
			 iem_thread_key, iem_is_read, iem_is_starred, iem_received_time)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'f', 'f', now())
			RETURNING iem_inbound_email_message_id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			$this->domain_id, $alias_id,
			'sender_' . $this->suffix . '@out.test',
			'rcpt_' . $this->suffix . '@in.test',
			$subject, 'plain body ' . $subject, '', $message_id, $thread_key,
		]);
		return intval($stmt->fetchColumn());
	}

	private function memberViewer() { return MailboxViewer::forUser($this->member_user, 1); }
	private function lonelyViewer() { return MailboxViewer::forUser($this->lonely_user, 1); }
	private function superViewer()  { return MailboxViewer::forUser(0, 10); }

	// ── sections ────────────────────────────────────────────────────────────

	private function testMemberScope() {
		$this->out('-- member scope (permission 1)');
		$svc = new MailboxService($this->memberViewer());

		$result = $svc->listMailboxes();
		$boxes = $result['mailboxes'] ?? array();
		$ids = array_map(function ($b) { return intval($b['alias_id'] ?? 0); }, $boxes);
		$this->ok(in_array($this->mine_alias, $ids, true), 'granted mailbox is listed');
		$this->ok(!in_array($this->other_alias, $ids, true), 'un-granted mailbox is NOT listed');
		$this->ok(count($boxes) === 1 && empty($result['all_access'])
			&& !isset($result['all_mail']) && !isset($result['unmatched']),
			'exactly the granted mailbox, no all-access/All mail/Unmatched');

		$threads = $svc->listThreads(null, array(), 1, 50);
		$subjects = array_map(function ($t) { return $t['subject'] ?? ''; }, $threads['threads'] ?? $threads);
		$flat = json_encode($subjects);
		$this->ok(strpos($flat, 'Mine hello') !== false, 'own thread visible');
		$this->ok(strpos($flat, 'Other secret') === false, 'other-alias thread NOT visible');
		$this->ok(strpos($flat, 'Unmatched') === false, 'unmatched (NULL-alias) thread NOT visible');

		$msgs = $svc->getThread(null, '<pm2@x>');
		$this->ok(count($msgs) === 0, 'thread fetch outside scope returns empty');

		$count = $svc->markRead(array($this->msg_other), true);
		$this->ok($count === 0, 'mutation on out-of-scope message affects 0 rows');
		$is_read = $this->db->query("SELECT iem_is_read FROM iem_inbound_email_messages
			WHERE iem_inbound_email_message_id = {$this->msg_other}")->fetchColumn();
		$this->ok($is_read === false || $is_read === 'f', 'out-of-scope message state unchanged in DB');
	}

	private function testGrantless() {
		$this->out('-- grantless signed-in user');
		$svc = new MailboxService($this->lonelyViewer());
		$result = $svc->listMailboxes();
		$this->ok(count($result['mailboxes'] ?? array()) === 0 && empty($result['all_access']),
			'mailbox list is empty');
		$threads = $svc->listThreads(null, array(), 1, 50);
		$list = $threads['threads'] ?? $threads;
		$this->ok(count($list) === 0, 'thread list is empty');
		$this->ok($svc->markRead(array($this->msg_mine), true) === 0, 'actions are no-ops');
	}

	private function testCanCompose() {
		$this->out('-- canCompose');
		$this->ok($this->memberViewer()->canCompose() === true, 'granted member can compose');
		$this->ok($this->lonelyViewer()->canCompose() === false, 'grantless member cannot compose');
		$this->ok($this->superViewer()->canCompose() === true, 'superadmin can compose');
	}

	private function testSendScope() {
		$this->out('-- send-as scope (MailboxSender)');
		$denied = 'You do not have access to this mailbox.';

		// Non-granted member replying to a message in an alias they lack: the
		// scope check itself rejects.
		try {
			$sender = new MailboxSender($this->lonelyViewer());
			$sender->send(array('mode' => 'reply', 'source_id' => $this->msg_mine, 'to' => 'x@example.test'));
			$this->ok(false, 'non-granted send should have thrown');
		} catch (MailboxSenderException $e) {
			$this->ok($e->getMessage() === $denied, 'non-granted send rejected by scope check');
		}

		// Granted member: passes the scope check (fails later on transport or
		// validation — anything but the scope denial — so no real send occurs).
		try {
			$sender = new MailboxSender($this->memberViewer());
			$sender->send(array('mode' => 'reply', 'source_id' => $this->msg_mine, 'to' => ''));
			$this->ok(false, 'granted send with no recipient should still throw (but past scope)');
		} catch (MailboxSenderException $e) {
			$this->ok($e->getMessage() !== $denied, 'granted member passes the scope check (failure is post-scope: ' . $e->getMessage() . ')');
		}
	}

	private function testAttachmentAccess() {
		$this->out('-- attachment access (member endpoint rule + shared retrieval)');
		$att = new InboundMessageAttachment($this->att_id, TRUE);
		$message = new InboundEmailMessage($this->msg_mine, TRUE);

		// The member endpoint's authorization rule, exactly as profile_attachment_logic applies it.
		$rule = function (MailboxViewer $viewer, InboundEmailMessage $msg) {
			$alias_id = intval($msg->get('iem_iea_inbound_email_alias_id'));
			return $alias_id > 0 ? $viewer->canAccess($alias_id) : $viewer->isAllAccess();
		};

		$this->ok($rule($this->memberViewer(), $message) === true, 'granted member may download');
		$this->ok($rule($this->lonelyViewer(), $message) === false, 'non-granted member may not');
		$unmatched = new InboundEmailMessage($this->msg_unmatched, TRUE);
		$this->ok($rule($this->memberViewer(), $unmatched) === false, 'NULL-alias message denied to members');
		$this->ok($rule($this->superViewer(), $unmatched) === true, 'NULL-alias message allowed to superadmin');

		$result = mailbox_retrieve_attachment_bytes($att, $message);
		$this->ok(!empty($result['ok']), 'file-backed retrieval succeeds');
		$this->ok(($result['content'] ?? '') === $this->att_bytes, 'retrieved bytes match');
	}

	private function testAnonymousHttp() {
		$this->out('-- anonymous HTTP: all five endpoints reject');
		$endpoints = array(
			'/ajax/mailbox_mailboxes' => 'GET',
			'/ajax/mailbox_list'      => 'GET',
			'/ajax/mailbox_thread'    => 'GET',
			'/ajax/mailbox_action'    => 'POST',
			'/ajax/mailbox_send'      => 'POST',
		);
		$host = parse_url($this->base_url, PHP_URL_HOST);
		foreach ($endpoints as $path => $method) {
			$ch = curl_init($this->base_url . $path);
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_RESOLVE        => array($host . ':443:' . $this->origin_ip),
			));
			if ($method === 'POST') {
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, array());
			}
			curl_exec($ch);
			$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);
			$this->ok($status == 403, "$method $path anonymous -> 403 (got $status)");
		}
	}

	private function tearDown() {
		try {
			if ($this->att_file && $this->att_file->key) {
				$this->att_file->permanent_delete();
			}
			$this->preClean();
			$this->out('  cleanup done');
		} catch (\Throwable $e) {
			$this->out('  cleanup error: ' . $e->getMessage());
		}
	}
}

$base_url  = isset($argv[1]) ? rtrim($argv[1], '/') : 'https://dev.getjoinery.com';
$origin_ip = isset($argv[2]) ? $argv[2] : '69.164.209.253';

$t = new ProfileMailboxTest($base_url, $origin_ip);
exit($t->run() ? 0 : 1);
