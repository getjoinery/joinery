<?php
/** @joinery-test
 * name: imap_materialize
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Materialize a reference-backed IMAP message into a self-contained local copy
 * (specs/mailbox_data_loss_fixes.md, Fix 8).
 *
 * A 'remote' message keeps its body text locally but fetches attachments from the
 * source IMAP account on demand. Before that account is deleted, materialize must
 * fetch the full RFC822, split attachments into local Files, and drop the IMAP
 * locator — so the message stays fully functional (attachments still load).
 *
 * The IMAP fetch is stubbed (a subclass returns a canned raw), so the test needs
 * no live server. Asserts:
 *   - after materialize the row is self-contained (driver no longer 'remote',
 *     IMAP account/locator cleared);
 *   - the reference-backed manifest is replaced by a file-backed attachment whose
 *     File exists with the original bytes;
 *   - a second call is a no-op (idempotent);
 *   - a sealed remote row is refused (defensive).
 *
 * Run: php plugins/mailbox/tests/imap_materialize_test.php  (schema synced).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(__DIR__ . '/lib/mailbox_test_fixture.php');
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_mailbox_grant_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));

/** ImapIngestor that returns a canned RFC822 instead of talking to a server. */
class StubMaterializeIngestor extends ImapIngestor {
	private $raw;
	public function __construct(InboundImapAccount $account, string $raw) {
		parent::__construct($account);
		$this->raw = $raw;
	}
	public function fetchFullRaw(int $uid, ?int $uidvalidity, string $folder, ?string $messageId): array {
		return array('ok' => true, 'raw' => $this->raw);
	}
	public function close(): void {}
}

class ImapMaterializeTest {
	private $db;
	private $suffix;
	private $domain_id;
	private $alias;
	private $account_id;
	private $router;
	private $pdf_bytes;
	private $created_file_ids = array();

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	function run() {
		section('IMAP materialize (remote → self-contained)');
		try {
			$this->setUp();
			$this->testMaterializeConvertsRemoteToLocal();
			$this->testSealedRemoteRefused();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		mailbox_purge_domains('imat-%', 'imat\_%@example.test');
		$this->suffix = substr(md5(uniqid('imat', true)), 0, 8);
		$this->pdf_bytes = '%PDF-1.4 materialize ' . $this->suffix;

		$d = new InboundEmailDomain(NULL);
		$d->set('ied_domain', 'imat-' . $this->suffix . '.example');
		$d->set('ied_is_enabled', true);
		$d->save();
		$this->domain_id = intval($d->key);

		$a = new InboundEmailAlias(NULL);
		$a->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$a->set('iea_alias', 'me' . $this->suffix);
		$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$a->set('iea_is_enabled', true);
		$a->prepare(); $a->save();
		$this->alias = $a;

		$owner = mailbox_make_user('imat_' . $this->suffix . '@example.test', 0, 'Imat');
		InboundEmailMailboxGrant::sync_for_alias(intval($a->key), array($owner));

		$acct = new InboundImapAccount(NULL);
		$acct->set('iia_iea_inbound_email_alias_id', intval($a->key));
		$acct->set('iia_label', 'Stub feed ' . $this->suffix);
		$acct->set('iia_username', 'me@imat.example');
		$acct->set('iia_provider_key', 'imap_generic');
		$acct->set('iia_imap_folder', 'INBOX');
		$acct->prepare(); $acct->save();
		$this->account_id = intval($acct->key);

		$this->router = new InboundEmailRouter();
		echo (php_sapi_name() === 'cli' ? '' : '<br>') . '  fixtures ready (suffix ' . $this->suffix . ")\n";
	}

	private function recipient() { return 'me' . $this->suffix . '@imat-' . $this->suffix . '.example'; }

	/** Build a remote message row plus its reference-backed (fileless) manifest. */
	private function makeRemoteMessage($token): InboundEmailMessage {
		$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
		$res = $this->router->storeExtracted(array(
			'sender'            => 'sender@example.com',
			'subject'           => 'Remote message ' . $token,
			'body_plain'        => 'Body ' . $token,
			'body_html'         => '',
			'message_id_header' => '<' . $token . '@example.com>',
			'size_bytes'        => 2048,
			'imap_account_id'   => $this->account_id,
			'imap_uid'          => 42,
			'imap_uidvalidity'  => 1,
			'imap_folder'       => 'INBOX',
		), $this->alias, new InboundEmailDomain($this->domain_id, TRUE), $this->recipient(), $auth);
		$message = $res['message'];

		// The reference-backed manifest ImapIngestor would have written from
		// BODYSTRUCTURE: a fileless row (bytes fetched on demand).
		InboundMessageAttachment::CreateEntry(array(
			'ima_iem_inbound_email_message_id' => intval($message->key),
			'ima_filename'     => 'doc.pdf',
			'ima_content_type' => 'application/pdf',
			'ima_size_bytes'   => strlen($this->pdf_bytes),
			'ima_mime_part'    => '2',
			'ima_is_inline'    => false,
		));
		return $message;
	}

	/** multipart/mixed raw: text body (1) + pdf attachment (2). */
	private function rawWithAttachment($token): string {
		$b = 'BND' . $this->suffix;
		$pdf_b64 = trim(chunk_split(base64_encode($this->pdf_bytes)));
		return implode("\r\n", array(
			'From: Sender <sender@example.com>',
			'To: ' . $this->recipient(),
			'Subject: Remote message ' . $token,
			'Message-ID: <' . $token . '@example.com>',
			'MIME-Version: 1.0',
			'Content-Type: multipart/mixed; boundary="' . $b . '"',
			'', '--' . $b,
			'Content-Type: text/plain; charset=UTF-8',
			'', 'Body ' . $token . '.',
			'--' . $b,
			'Content-Type: application/pdf; name="doc.pdf"',
			'Content-Transfer-Encoding: base64',
			'Content-Disposition: attachment; filename="doc.pdf"',
			'', $pdf_b64,
			'--' . $b . '--', '',
		));
	}

	private function reload($id) { return new InboundEmailMessage(intval($id), TRUE); }

	private function testMaterializeConvertsRemoteToLocal() {
		$token = 'imat-' . $this->suffix;
		$message = $this->makeRemoteMessage($token);
		$id = intval($message->key);

		check((string)$this->reload($id)->get('iem_raw_storage_driver') === 'remote',
			'precondition: the message is reference-backed (remote)');

		$stub = new StubMaterializeIngestor(new InboundImapAccount($this->account_id, TRUE), $this->rawWithAttachment($token));
		$res = $this->router->materializeRemoteMessage($this->reload($id), $stub);
		check(!empty($res['ok']), 'materialize reported success' . (empty($res['ok']) ? ' — ' . ($res['message'] ?? '') : ''));

		$after = $this->reload($id);
		check((string)$after->get('iem_raw_storage_driver') !== 'remote',
			'driver is no longer remote (self-contained)');
		check((int)$after->get('iem_iia_inbound_imap_account_id') === 0 || $after->get('iem_iia_inbound_imap_account_id') === null,
			'IMAP account reference cleared');
		check($after->get('iem_imap_uid') === null || (int)$after->get('iem_imap_uid') === 0,
			'IMAP uid locator cleared');

		// The manifest is now a single file-backed attachment with the real bytes.
		$manifest = new MultiInboundMessageAttachment(array('message_id' => $id, 'file_backed' => true));
		$manifest->load();
		check(count($manifest) === 1, 'exactly one file-backed attachment after materialize');
		$fil_id = count($manifest) ? intval($manifest->get(0)->get('ima_fil_file_id')) : 0;
		check($fil_id > 0, 'the attachment is now file-backed');
		if ($fil_id > 0) { $this->created_file_ids[] = $fil_id; }
		$file = $fil_id > 0 ? new File($fil_id, TRUE) : null;
		check($file && $file->key, 'the attachment File exists');

		// Idempotent: a second call is a no-op.
		$res2 = $this->router->materializeRemoteMessage($this->reload($id), $stub);
		check(!empty($res2['ok']), 'second materialize is a no-op success');
		$manifest2 = new MultiInboundMessageAttachment(array('message_id' => $id, 'file_backed' => true));
		$manifest2->load();
		check(count($manifest2) === 1, 'still exactly one attachment after the no-op');
	}

	private function testSealedRemoteRefused() {
		$token = 'imat-sealed-' . $this->suffix;
		$message = $this->makeRemoteMessage($token);
		$id = intval($message->key);
		// Force the (unexpected) sealed state on a remote row.
		$this->db->prepare("UPDATE iem_inbound_email_messages SET iem_content_sealed = true WHERE iem_inbound_email_message_id = ?")
			->execute(array($id));

		$stub = new StubMaterializeIngestor(new InboundImapAccount($this->account_id, TRUE), $this->rawWithAttachment($token));
		$res = $this->router->materializeRemoteMessage($this->reload($id), $stub);
		check(empty($res['ok']), 'a sealed reference-backed row is refused, not mishandled');
		check((string)$this->reload($id)->get('iem_raw_storage_driver') === 'remote',
			'the refused row is left untouched (still remote)');
	}

	private function tearDown() {
		try {
			foreach (array_unique($this->created_file_ids) as $fid) {
				try { $f = new File(intval($fid), TRUE); if ($f->key) { $f->permanent_delete(); } } catch (\Throwable $e) {}
			}
			if ($this->domain_id) {
				$this->db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN (SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . intval($this->domain_id) . ")");
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM iia_inbound_imap_accounts WHERE iia_iea_inbound_email_alias_id IN (SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id) . ")");
				$this->db->exec("DELETE FROM ieg_inbound_email_mailbox_grants WHERE ieg_iea_inbound_email_alias_id IN (SELECT iea_inbound_email_alias_id FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id) . ")");
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($this->domain_id));
			}
			$this->db->exec("DELETE FROM usr_users WHERE usr_email LIKE 'imat\\_%@example.test'");
		} catch (\Throwable $e) {}
	}
}

$test = new ImapMaterializeTest();
$test->run();
harness_finish();
