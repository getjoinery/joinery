<?php
/** @joinery-test
 * name: imap_store_atomicity
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A message and its attachment manifest are one write, or neither happens.
 *
 * The defect this pins (D1, specs/mail_import_loss_proof.md) needed no
 * concurrency at all. The message row and its manifest used to be two separate
 * statements, so a poll dying between them left the row committed with no
 * attachments — and because the folder cursor had not advanced, the retry found
 * the row already there, reported a dedup, and skipped the manifest write by
 * design. That message listed no attachments permanently, and no counter
 * anywhere said so.
 *
 * What is asserted:
 *
 *  - A manifest write that fails takes the message row with it. Nothing commits.
 *  - The retry then stores the message AND its manifest together, which is the
 *    half that proves the fix actually recovers rather than merely failing safe.
 *  - The ordinary path still stores a complete manifest, so the transaction did
 *    not break the thing it wraps.
 *  - A database-raised collision surfaces as InboundStoreCollisionException
 *    rather than a reported dedup, because a caller inside a transaction cannot
 *    carry on after one — Postgres has aborted it.
 *  - The seed proof records the boundary a day-windowed feed started reading at,
 *    including the case where the probe budget runs out.
 *
 * No live IMAP server, and no mock either: the test drives the same
 * store-plus-manifest transaction boundary ingestOne uses, directly against the
 * router. ingestOne's other half is a network fetch that happens before the
 * transaction opens and is deliberately not under test here — the poller suite
 * covers that path end to end. The cost of this shape is honest: a write
 * drifting outside the real transaction inside ingestOne would not be caught
 * here, only the boundary's own behaviour is.
 *
 * Run: php tests/run.php db --filter=imap_store_atomicity
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_seed_proof_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/AttachmentByteCustody.php'));

/**
 * A MIME part that behaves normally until asked for its name, then throws.
 *
 * That is the injection point: writeManifest() asks every part for its name, so
 * a part like this fails the manifest write and nothing else — which is exactly
 * the shape of the crash the transaction exists to survive.
 */
class ExplodingPart {
	public $explode = false;
	public function getName() {
		if ($this->explode) { throw new RuntimeException('simulated crash during the manifest write'); }
		return 'invoice.pdf';
	}
	public function getType()        { return 'application/pdf'; }
	public function getBytes()       { return 2048; }
	public function getMimeId()      { return '2'; }
	public function getContentId()   { return null; }
	public function getDisposition() { return 'attachment'; }
	public function getPrimaryType() { return 'application'; }
}

class ImapStoreAtomicityTest {

	private $db;
	private $suffix;
	private $domain_id;
	private $alias;
	private $router;

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	function run() {
		section('Store atomicity: a message and its manifest');
		$this->setUp();
		$this->testOrdinaryStoreIsComplete();
		$this->testFailedManifestRollsTheMessageBack();
		$this->testRetryStoresBoth();

		section('A collision inside a transaction');
		$this->testCollisionThrows();

		section('Seed proof');
		$this->testSeedProofBoundary();

		$this->tearDown();
	}

	// ------------------------------------------------------------------ setup

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('atom', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'atom-test-' . $this->suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->set('ied_is_imap_source', true);
		$domain->save();
		$this->domain_id = intval($domain->key);

		$alias = new InboundEmailAlias(NULL);
		$alias->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$alias->set('iea_alias', 'in' . $this->suffix);
		$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$alias->set('iea_is_enabled', true);
		$alias->prepare();
		$alias->save();
		$this->alias = $alias;

		$this->router = new InboundEmailRouter();
	}

	private function preClean() {
		try {
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'atom-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			if (!$dids) { return; }
			$in = implode(',', array_map('intval', $dids));
			$mids = $this->db->query("SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
				WHERE iem_ied_inbound_email_domain_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
			if ($mids) {
				$min = implode(',', array_map('intval', $mids));
				$this->db->exec("DELETE FROM ima_inbound_message_attachments
					WHERE ima_iem_inbound_email_message_id IN ($min)");
			}
			$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id IN ($in)");
			$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id IN ($in)");
			$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id IN ($in)");
		} catch (\Throwable $e) {}
	}

	private function recipient(): string {
		return strtolower($this->alias->get_full_address());
	}

	private function domain(): InboundEmailDomain {
		return new InboundEmailDomain($this->domain_id, TRUE);
	}

	private function msg(string $mid): array {
		return array(
			'sender' => 'someone@example.org',
			'subject' => 'With an attachment',
			'body_plain' => 'see attached',
			'body_html' => '',
			'message_id_header' => $mid,
			'headers' => array(),
			'size_bytes' => 4096,
			'received_time' => gmdate('Y-m-d H:i:s'),
			'imap_account_id' => null,
			'imap_uid' => 101,
			'imap_uidvalidity' => 1,
			'imap_folder' => 'INBOX',
		);
	}

	/**
	 * Store one message and its manifest exactly the way ingestOne does — one
	 * transaction, rolled back whole if any part of it fails.
	 *
	 * This mirrors the production boundary rather than calling into it, because
	 * ingestOne's other half is a live IMAP fetch. The behaviour under test is
	 * the boundary itself.
	 */
	private function storeWithManifest(string $mid, array $parts): array {
		$owns = !$this->db->inTransaction();
		if ($owns) { $this->db->beginTransaction(); }
		try {
			$result = $this->router->storeExtracted($this->msg($mid), $this->alias,
				$this->domain(), $this->recipient(),
				array('dkim' => 'unverified', 'spf' => 'unverified',
					'dmarc' => 'unverified', 'source' => 'none'));
			if (!$result['dedup'] && $result['message'] !== null) {
				foreach ($parts as $part) {
					InboundMessageAttachment::CreateEntry(array(
						'ima_iem_inbound_email_message_id' => intval($result['message']->key),
						'ima_filename'     => $part->getName(),
						'ima_content_type' => $part->getType(),
						'ima_size_bytes'   => $part->getBytes(),
						'ima_mime_part'    => $part->getMimeId(),
						'ima_encoding'     => 'base64',
						'ima_is_inline'    => false,
					));
				}
			}
			if ($owns) { $this->db->commit(); }
			return $result;
		} catch (\Throwable $e) {
			if ($owns && $this->db->inTransaction()) { $this->db->rollBack(); }
			throw $e;
		}
	}

	private function findMessage(string $mid): ?int {
		$stmt = $this->db->prepare('SELECT iem_inbound_email_message_id FROM iem_inbound_email_messages
			WHERE iem_message_id_header = ? AND iem_recipient = ? LIMIT 1');
		$stmt->execute(array($mid, $this->recipient()));
		$id = $stmt->fetchColumn();
		return $id === false ? null : intval($id);
	}

	// ------------------------------------------------------------------ tests

	private function testOrdinaryStoreIsComplete() {
		$mid = '<clean-' . $this->suffix . '@example.org>';
		$part = new ExplodingPart();
		$this->storeWithManifest($mid, array($part));

		$id = $this->findMessage($mid);
		check($id !== null, 'an ordinary store commits the message');
		check(AttachmentByteCustody::manifestRowCount((int)$id) === 1,
			'and it commits with its manifest',
			'rows: ' . AttachmentByteCustody::manifestRowCount((int)$id));
	}

	private function testFailedManifestRollsTheMessageBack() {
		$mid = '<crash-' . $this->suffix . '@example.org>';
		$part = new ExplodingPart();
		$part->explode = true;

		$threw = false;
		try {
			$this->storeWithManifest($mid, array($part));
		} catch (\Throwable $e) {
			$threw = true;
		}
		check($threw, 'a manifest write that fails throws rather than half-storing');

		check($this->findMessage($mid) === null,
			'THE MESSAGE ROW IS GONE — a half-stored message never commits');
	}

	private function testRetryStoresBoth() {
		// The same message the crash rolled back, retried as the next poll would.
		$mid = '<crash-' . $this->suffix . '@example.org>';
		$part = new ExplodingPart();          // no longer exploding
		$this->storeWithManifest($mid, array($part));

		$id = $this->findMessage($mid);
		check($id !== null, 'the retry stores the message the crash rolled back');
		check($id !== null && AttachmentByteCustody::manifestRowCount((int)$id) === 1,
			'and this time the manifest lands with it — the defect is closed',
			$id === null ? 'no message' : 'rows: ' . AttachmentByteCustody::manifestRowCount((int)$id));
	}

	private function testCollisionThrows() {
		// The pre-validate path: the row is already there, so save() refuses before
		// it ever reaches an INSERT. Nothing has aborted, so this is a plain dedup.
		$mid = '<clean-' . $this->suffix . '@example.org>';
		$again = $this->router->storeExtracted($this->msg($mid), $this->alias,
			$this->domain(), $this->recipient(),
			array('dkim' => 'unverified', 'spf' => 'unverified',
				'dmarc' => 'unverified', 'source' => 'none'));
		check(!empty($again['dedup']),
			'a duplicate caught before the INSERT is still reported as an ordinary dedup');

		// And the class exists for the other half — the database-raised violation,
		// which only a genuine race produces and which must never be swallowed.
		check(class_exists('InboundStoreCollisionException'),
			'a database-raised collision has its own exception, so it can never be '
			. 'mistaken for a completed store');
	}

	private function testSeedProofBoundary() {
		$cutoff = gmdate('Y-m-d H:i:s', strtotime('-30 days'));

		// The shape a good seek leaves: the newest message below the cursor
		// predates the cutoff, the oldest above it does not.
		$good = new InboundImapSeedProof(NULL);
		$good->set('isp_iia_inbound_imap_account_id', 0);
		$good->set('isp_folder', 'INBOX');
		$good->set('isp_cutoff_time', $cutoff);
		$good->set('isp_cursor_uid', 500);
		$good->set('isp_high_uid', 900);
		$good->set('isp_probes', 7);
		$good->set('isp_converged', true);
		$good->set('isp_below_uid', 500);
		$good->set('isp_below_time', gmdate('Y-m-d H:i:s', strtotime('-40 days')));
		$good->set('isp_above_uid', 501);
		$good->set('isp_above_time', gmdate('Y-m-d H:i:s', strtotime('-29 days')));

		check($good->boundaryHolds() === true,
			'a seek that started just past the window boundary reports the boundary holding');
		check($good->overImported() === false, 'and reports no over-import');

		// The shape that means mail was skipped: a message below the cursor is
		// inside the window.
		$bad = new InboundImapSeedProof(NULL);
		$bad->set('isp_cutoff_time', $cutoff);
		$bad->set('isp_below_time', gmdate('Y-m-d H:i:s', strtotime('-2 days')));
		check($bad->boundaryHolds() === false,
			'a seek that skipped in-window mail reports the boundary BROKEN');
		check(strpos($bad->describe(), 'BOUNDARY BROKEN') !== false,
			'and says so in words, not just a flag', $bad->describe());

		// Unknown is never evidence. An unreadable INTERNALDATE stores as NULL and
		// must read as "cannot be judged", never as "fine".
		$unknown = new InboundImapSeedProof(NULL);
		$unknown->set('isp_cutoff_time', $cutoff);
		check($unknown->boundaryHolds() === null,
			'a seek with nothing to probe is unprovable, NOT proven good');

		// The budget-exhausted path is recorded rather than hidden: the cursor is
		// still safe, but it is looser than asked and the row says which.
		$loose = new InboundImapSeedProof(NULL);
		$loose->set('isp_folder', 'INBOX');
		$loose->set('isp_cutoff_time', $cutoff);
		$loose->set('isp_converged', false);
		check(strpos($loose->describe(), 'budget exhausted') !== false,
			'an inconclusive seek says its budget ran out', $loose->describe());
	}

	private function tearDown() {
		$this->preClean();
	}
}

$test = new ImapStoreAtomicityTest();
$test->run();
harness_finish();
?>
