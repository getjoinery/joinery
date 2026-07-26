<?php
/** @joinery-test
 * name: inbound_email_attachment_storage
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Lean-record attachment storage tests (specs/implemented/inbound_email_attachment_storage.md)
 * — the file-backed path, testable without a live IMAP server.
 *
 *  - Owner resolution: a single-grantee alias (individual mailbox) → the attachment
 *    File is owned by that user (so a permission-0 owner sees their own attachments);
 *    a multi-grantee (shared) alias → USER_SYSTEM (admins-only).
 *  - Forward re-attach: attachOriginal() reads file-backed bytes and RE-EMBEDS an
 *    inline (cid:) part via EmailMessage::attachInlineData() with its original
 *    Content-ID, while a regular part attaches normally.
 *
 * Run: php plugins/mailbox/tests/inbound_email_attachment_storage_test.php  (schema synced).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/lib/mailbox_test_fixture.php'); // mailbox_make_user()
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxSender.php'));

/**
 * Router whose attachment/raw persistence always fails — simulates a mid-store
 * abort (process death after the row insert, before attachments are written)
 * to prove the atomic-store transaction (specs/mailbox_data_loss_fixes.md,
 * Fix 4) rolls the whole unit back so no bare, attachment-less row survives.
 */
class AtomicAbortRouter extends InboundEmailRouter {
	protected function persistRawAndManifest(int $message_id, string $raw_email, $alias = null, ?string $dek = null) {
		throw new \RuntimeException('simulated mid-store abort before attachment persistence');
	}
}

class InboundAttachmentStorageTest {
	private $db;
	private $suffix;
	private $domain_id;
	private $router;
	private $created_message_ids = array();
	private $created_file_ids = array();
	private $created_alias_ids = array();
	private $created_user_ids = array();
	private $pdf_bytes;
	private $png_bytes;

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		return check((bool)$c, $l);
	}

	function run() {
		section('Lean-record attachment storage tests');
		try {
			$this->setUp();
			$this->testSingleGranteeOwnsAttachment();
			$this->testSharedMailboxOwnedBySystem();
			$this->testForwardReEmbedsInline();
			$this->testAtomicStoreRollback();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('ias', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'ias-test-' . $this->suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->save();
		$this->domain_id = intval($domain->key);

		$this->router = new InboundEmailRouter();
		$this->pdf_bytes = "%PDF-1.4 fake pdf " . $this->suffix;
		$this->png_bytes = "\x89PNG fake image " . $this->suffix;
		$this->out('  fixtures ready (suffix ' . $this->suffix . ')');
	}

	private function makeAlias($local) {
		$a = new InboundEmailAlias(NULL);
		$a->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$a->set('iea_alias', $local);
		$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$a->set('iea_is_enabled', true);
		$a->prepare(); $a->save();
		$this->created_alias_ids[] = intval($a->key);
		return $a;
	}

	private function makeUser($email, $perm = 5) {
		$id = mailbox_make_user($email, (int)$perm, 'Ias');
		$this->created_user_ids[] = $id;
		return $id;
	}

	private function recipientFor($alias) {
		return $alias->get('iea_alias') . '@ias-test-' . $this->suffix . '.example';
	}

	/** multipart/mixed: text body (1) + pdf attachment (2). */
	private function buildRaw($alias, $token) {
		$b = 'BND' . $this->suffix;
		$pdf_b64 = chunk_split(base64_encode($this->pdf_bytes));
		$lines = array(
			'From: Sender <sender@example.com>',
			'To: ' . $this->recipientFor($alias),
			'Subject: Attachment storage test',
			'Message-ID: <' . $token . '@example.com>',
			'MIME-Version: 1.0',
			'Content-Type: multipart/mixed; boundary="' . $b . '"',
			'',
			'--' . $b,
			'Content-Type: text/plain; charset=UTF-8',
			'',
			'Body ' . $this->suffix . '.',
			'--' . $b,
			'Content-Type: application/pdf; name="doc.pdf"',
			'Content-Transfer-Encoding: base64',
			'Content-Disposition: attachment; filename="doc.pdf"',
			'',
			trim($pdf_b64),
			'--' . $b . '--',
			'',
		);
		return implode("\r\n", $lines);
	}

	private function ingest($alias, $token) {
		$raw = $this->buildRaw($alias, $token);
		$parsed = $this->router->parseEmail($raw);
		$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
		$res = $this->router->storeMessage($raw, $parsed, $alias,
			new InboundEmailDomain($this->domain_id, TRUE), $this->recipientFor($alias), $auth);
		$id = !empty($res['message']) ? intval($res['message']->key) : 0;
		if ($id) {
			$this->created_message_ids[] = $id;
			$manifest = new MultiInboundMessageAttachment(array('message_id' => $id));
			$manifest->load();
			foreach ($manifest as $att) {
				$fil = intval($att->get('ima_fil_file_id'));
				if ($fil > 0) { $this->created_file_ids[] = $fil; }
			}
		}
		return $id;
	}

	private function testSingleGranteeOwnsAttachment() {
		$alias = $this->makeAlias('solo' . $this->suffix);
		$owner = $this->makeUser('ias_solo_' . $this->suffix . '@example.test', 0);
		InboundEmailMailboxGrant::sync_for_alias(intval($alias->key), array($owner));

		$id = $this->ingest($alias, 'solo-' . $this->suffix);
		$this->ok($id > 0, 'single-grantee message stored');

		$manifest = new MultiInboundMessageAttachment(array('message_id' => $id, 'file_backed' => true));
		$manifest->load();
		$fil_id = count($manifest) ? intval($manifest->get(0)->get('ima_fil_file_id')) : 0;
		$file = $fil_id > 0 ? new File($fil_id, TRUE) : null;
		$this->ok($file && $file->key, 'attachment is file-backed');
		$this->ok($file && intval($file->get('fil_usr_user_id')) === $owner,
			'single-grantee (individual mailbox) → File owned by that user');
	}

	private function testSharedMailboxOwnedBySystem() {
		$alias = $this->makeAlias('team' . $this->suffix);
		$u1 = $this->makeUser('ias_team1_' . $this->suffix . '@example.test', 5);
		$u2 = $this->makeUser('ias_team2_' . $this->suffix . '@example.test', 5);
		InboundEmailMailboxGrant::sync_for_alias(intval($alias->key), array($u1, $u2));

		$id = $this->ingest($alias, 'team-' . $this->suffix);
		$this->ok($id > 0, 'shared-mailbox message stored');

		$manifest = new MultiInboundMessageAttachment(array('message_id' => $id, 'file_backed' => true));
		$manifest->load();
		$fil_id = count($manifest) ? intval($manifest->get(0)->get('ima_fil_file_id')) : 0;
		$file = $fil_id > 0 ? new File($fil_id, TRUE) : null;
		$this->ok($file && intval($file->get('fil_usr_user_id')) === User::USER_SYSTEM,
			'shared alias (>1 grantee) → File owned by USER_SYSTEM (admins-only)');
	}

	private function testForwardReEmbedsInline() {
		// A source message with two file-backed parts: a regular pdf and an inline png.
		$alias = $this->makeAlias('fwd' . $this->suffix);
		$stmt = $this->db->prepare("INSERT INTO iem_inbound_email_messages
			(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_sender, iem_recipient,
			 iem_subject, iem_message_id_header, iem_raw_storage_driver, iem_received_time)
			VALUES (?, ?, 'from@x', ?, 'subj', ?, 'inline', NOW()) RETURNING iem_inbound_email_message_id");
		$stmt->execute(array($this->domain_id, intval($alias->key), $this->recipientFor($alias),
			'<fwd-' . $this->suffix . '@x>'));
		$mid = intval($stmt->fetchColumn());
		$this->created_message_ids[] = $mid;

		$pdf = File::createFromBytes($this->pdf_bytes, 'doc.pdf', 'application/pdf', User::USER_SYSTEM, array('fil_private' => true));
		$png = File::createFromBytes($this->png_bytes, 'logo.png', 'image/png', User::USER_SYSTEM, array('fil_private' => true));
		$this->created_file_ids[] = intval($pdf->key);
		$this->created_file_ids[] = intval($png->key);

		InboundMessageAttachment::CreateEntry(array(
			'ima_iem_inbound_email_message_id' => $mid,
			'ima_filename' => 'doc.pdf', 'ima_content_type' => 'application/pdf',
			'ima_size_bytes' => strlen($this->pdf_bytes), 'ima_mime_part' => '2',
			'ima_is_inline' => false, 'ima_fil_file_id' => intval($pdf->key),
		));
		InboundMessageAttachment::CreateEntry(array(
			'ima_iem_inbound_email_message_id' => $mid,
			'ima_filename' => 'logo.png', 'ima_content_type' => 'image/png',
			'ima_size_bytes' => strlen($this->png_bytes), 'ima_mime_part' => '3',
			'ima_content_id' => 'logo123', 'ima_is_inline' => true, 'ima_fil_file_id' => intval($png->key),
		));

		$source = new InboundEmailMessage($mid, TRUE);
		$email = new EmailMessage();

		// attachOriginal is private — invoke via reflection.
		$sender = new MailboxSender(MailboxViewer::forUser(0, 10));
		$ref = new ReflectionMethod('MailboxSender', 'attachOriginal');
		$ref->setAccessible(true);
		$total = $ref->invoke($sender, $email, $source);

		$this->ok($total === strlen($this->pdf_bytes) + strlen($this->png_bytes),
			'attachOriginal re-attached both parts by byte length');

		$atts = $email->getAttachments();
		$inline = null; $regular = null;
		foreach ($atts as $a) {
			if (!empty($a['cid'])) { $inline = $a; }
			else { $regular = $a; }
		}
		$this->ok($regular !== null && $regular['data'] === $this->pdf_bytes && empty($regular['cid']),
			'regular part re-attached as a normal attachment with its bytes');
		$this->ok($inline !== null && ($inline['cid'] ?? null) === 'logo123' && !empty($inline['inline']),
			'inline part re-embedded with its original Content-ID');
		$this->ok($inline !== null && $inline['data'] === $this->png_bytes,
			'inline part carries the original image bytes');
	}

	/**
	 * Fix 4 — the store is atomic. A mid-store abort (attachment persistence
	 * throws after the row is inserted) must leave NO row behind, so the
	 * sender's retry rebuilds from a clean slate and stores fully. Proves the
	 * old crash window — a committed bare row whose retry dedups away the copy
	 * carrying the attachments — is closed.
	 */
	private function testAtomicStoreRollback() {
		$alias = $this->makeAlias('atomic' . $this->suffix);
		$owner = $this->makeUser('ias_atomic_' . $this->suffix . '@example.test', 0);
		InboundEmailMailboxGrant::sync_for_alias(intval($alias->key), array($owner));

		$token = 'atomic-' . $this->suffix;
		$raw = $this->buildRaw($alias, $token);
		$parsed = $this->router->parseEmail($raw);
		$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
		$msgid = '<' . $token . '@example.com>';

		// First delivery aborts inside the store (after insert, before attachments).
		$abort = new AtomicAbortRouter();
		$threw = false;
		try {
			$abort->storeMessage($raw, $parsed, $alias,
				new InboundEmailDomain($this->domain_id, TRUE), $this->recipientFor($alias), $auth);
		} catch (\Throwable $e) {
			$threw = true;
		}
		$this->ok($threw, 'aborted store surfaced the failure (did not swallow it)');
		$this->ok($this->countRowsForMessageId($msgid) === 0,
			'aborted store left NO row behind (whole unit rolled back)');

		// The sender retries: a clean, full store with its attachment.
		$id = $this->ingest($alias, $token);
		$this->ok($id > 0, 're-delivery after abort stored a fresh row');
		$manifest = new MultiInboundMessageAttachment(array('message_id' => $id, 'file_backed' => true));
		$manifest->load();
		$this->ok(count($manifest) === 1 && intval($manifest->get(0)->get('ima_fil_file_id')) > 0,
			're-delivered message is fully stored with its file-backed attachment');
	}

	private function countRowsForMessageId($msgid) {
		$stmt = $this->db->prepare(
			"SELECT COUNT(*) FROM iem_inbound_email_messages WHERE iem_message_id_header = ?");
		$stmt->execute(array($msgid));
		return intval($stmt->fetchColumn());
	}

	private function preClean() {
		mailbox_purge_domains('ias-test-%', 'ias\_%@example.test');
	}

	private function tearDown() {
		try {
			foreach (array_unique($this->created_file_ids) as $fid) {
				try { $f = new File(intval($fid), TRUE); if ($f->key) { $f->permanent_delete(); } }
				catch (\Throwable $e) {}
			}
			if ($this->created_message_ids) {
				$in = implode(',', array_map('intval', $this->created_message_ids));
				$this->db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN ($in)");
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id IN ($in)");
			}
			foreach ($this->created_alias_ids as $aid) {
				$this->db->exec("DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id = " . intval($aid));
			}
			if ($this->domain_id) {
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($this->domain_id));
			}
			$this->db->exec("DELETE FROM usr_users WHERE usr_email LIKE 'ias\\_%@example.test'");
		} catch (\Throwable $e) {}
	}
}

$test = new InboundAttachmentStorageTest();
$test->run();
harness_finish();
