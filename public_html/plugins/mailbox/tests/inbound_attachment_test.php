<?php
/** @joinery-test
 * name: inbound_attachment
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Tests for the attachment manifest + the per-attachment download endpoint's
 * access + filename rules (the parts testable without a live IMAP server).
 *
 *  - InboundMessageAttachment manifest CRUD; the Multi is_inline filter excludes
 *    inline (cid:) parts from the reader's attachment list.
 *  - The download endpoint's filename sanitization strips CR/LF + path separators
 *    (header-injection guard).
 *  - Grant parity: the endpoint's access check matches the reader — a user without
 *    a grant to the message's mailbox is refused; a granted user is allowed.
 *
 * The live single-part FETCH + decode is wrapped behind Horde and covered by the
 * manual end-to-end checklist (see the plugin docs).
 *
 * Run: php plugins/mailbox/tests/inbound_attachment_test.php  (schema synced).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_attachment_logic.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/attachment_retrieval.php'));

class InboundAttachmentTest {
	private $db;
	private $suffix;
	private $domain_id;
	private $alias_id;
	private $message_id;
	private $granted_user;
	private $other_user;

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		return check((bool)$c, $l);
	}

	function run() {
		section('Inbound attachment tests');
		try {
			$this->setUp();
			$this->testManifestAndInlineFilter();
			$this->testFilenameSanitization();
			$this->testGrantParity();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('att', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'att-test-' . $this->suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->save();
		$this->domain_id = intval($domain->key);

		$a = new InboundEmailAlias(NULL);
		$a->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$a->set('iea_alias', 'box' . $this->suffix);
		$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$a->set('iea_is_enabled', true);
		$a->prepare(); $a->save();
		$this->alias_id = intval($a->key);

		// A stored message under that alias.
		$stmt = $this->db->prepare("INSERT INTO iem_inbound_email_messages
			(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_sender, iem_recipient,
			 iem_subject, iem_message_id_header, iem_received_time)
			VALUES (?, ?, 'from@x', ?, 'subj', ?, NOW()) RETURNING iem_inbound_email_message_id");
		$stmt->execute(array($this->domain_id, $this->alias_id, 'box' . $this->suffix . '@x',
			'<att-' . $this->suffix . '@x>'));
		$this->message_id = intval($stmt->fetchColumn());

		$this->granted_user = $this->makeUser('att_grant_' . $this->suffix . '@example.test');
		$this->other_user   = $this->makeUser('att_other_' . $this->suffix . '@example.test');
		InboundEmailMailboxGrant::sync_for_alias($this->alias_id, array($this->granted_user));

		$this->out('  fixtures ready (suffix ' . $this->suffix . ')');
	}

	private function preClean() {
		try {
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'att-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			if ($dids) {
				$in = implode(',', array_map('intval', $dids));
				$mids = $this->db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
					WHERE iem_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
				if ($mids) {
					$min = implode(',', array_map('intval', $mids));
					$this->db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN ($min)");
				}
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
			$this->db->exec("DELETE FROM usr_users WHERE usr_email LIKE 'att\\_%@example.test'");
		} catch (\Throwable $e) {}
	}

	private function makeUser($email) {
		$stmt = $this->db->prepare("INSERT INTO usr_users
			(usr_first_name, usr_email, usr_timezone, usr_permission)
			VALUES ('Att', ?, 'UTC', 5) RETURNING usr_user_id");
		$stmt->execute(array($email));
		return intval($stmt->fetchColumn());
	}

	private function testManifestAndInlineFilter() {
		// A real attachment + an inline cid: image.
		InboundMessageAttachment::CreateEntry(array(
			'ima_iem_inbound_email_message_id' => $this->message_id,
			'ima_filename' => 'invoice.pdf',
			'ima_content_type' => 'application/pdf',
			'ima_size_bytes' => 4096,
			'ima_mime_part' => '2',
			'ima_encoding' => 'base64',
			'ima_is_inline' => false,
		));
		InboundMessageAttachment::CreateEntry(array(
			'ima_iem_inbound_email_message_id' => $this->message_id,
			'ima_filename' => 'logo.png',
			'ima_content_type' => 'image/png',
			'ima_size_bytes' => 2048,
			'ima_mime_part' => '3',
			'ima_encoding' => 'base64',
			'ima_content_id' => 'logo123',
			'ima_is_inline' => true,
		));

		$all = new MultiInboundMessageAttachment(array('message_id' => $this->message_id));
		$this->ok($all->count_all() === 2, 'both manifest rows written');

		// The reader list excludes inline parts.
		$visible = new MultiInboundMessageAttachment(array('message_id' => $this->message_id, 'is_inline' => false));
		$visible->load();
		$this->ok(count($visible) === 1, 'inline cid: part excluded from the attachment list');
		$this->ok($visible->get(0)->get('ima_filename') === 'invoice.pdf', 'the visible attachment is the real one');
	}

	private function testFilenameSanitization() {
		$this->ok(mailbox_attachment_safe_filename("a\r\nb.pdf") === 'ab.pdf', 'CR/LF stripped from filename');
		$this->ok(mailbox_attachment_safe_filename('../../etc/passwd') === '....etcpasswd', 'path separators stripped');
		$this->ok(mailbox_attachment_safe_filename('') === 'attachment', 'empty filename falls back to "attachment"');
		$this->ok(strpos(mailbox_attachment_safe_filename('quote"name.txt'), '"') === false, 'double-quote stripped');
	}

	private function testGrantParity() {
		// The endpoint's access decision == MailboxViewer (the reader's seam).
		$grantedViewer = MailboxViewer::forUser($this->granted_user, 5);
		$otherViewer   = MailboxViewer::forUser($this->other_user, 5);
		$superViewer   = MailboxViewer::forUser(0, 10);

		$this->ok($grantedViewer->canAccess($this->alias_id), 'granted user can access the mailbox');
		$this->ok(!$otherViewer->canAccess($this->alias_id), 'ungranted user is refused');
		$this->ok($superViewer->isAllAccess(), 'superadmin is all-access');
	}

	private function tearDown() {
		try {
			$this->db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id = " . intval($this->message_id));
			if ($this->alias_id) {
				$this->db->exec("DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id = " . intval($this->alias_id));
			}
			if ($this->domain_id) {
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($this->domain_id));
			}
			$this->db->exec("DELETE FROM usr_users WHERE usr_email LIKE 'att\\_%@example.test'");
		} catch (\Throwable $e) {}
	}
}

$test = new InboundAttachmentTest();
$test->run();
harness_finish();
