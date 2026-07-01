<?php
/**
 * Inbound raw-storage + lean-record ingest + accessor tests (push transport, no live IMAP).
 *
 *  - Lean-record ingest (specs/implemented/inbound_email_attachment_storage.md): storeMessage
 *    now extracts each non-text part into a private File (linked by ima_fil_file_id),
 *    retains NO raw (driver stays 'inline', column empty), and still extracts the text
 *    body. The File carries the original bytes and is owner-or-admin private.
 *  - Extraction-failure fallback: when a File write fails, ingest falls back to today's
 *    raw storage — a stored raw with a section-pointer manifest (ima_fil_file_id null).
 *  - Accessor: getRawMessage() returns the right bytes for inline/local/cloud (cloud
 *    via a mock private driver); getRawMimePart() extracts the correct part; a
 *    'remote' row yields null. These are exercised against directly-built rows (the
 *    fallback/legacy shape), since the happy path no longer stores a raw.
 *  - Deletion: permanent_delete reclaims the message's attachment Files (and any raw).
 *
 * Run: php plugins/inbound_email/tests/inbound_raw_storage_test.php  (schema synced).
 *
 * @version 2.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_message_attachment_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/RawMessageStore.php'));

/** In-memory CloudStorageDriver standing in for the verified-private bucket. */
class RawIngestMockDriver implements CloudStorageDriver {
	public $objects = array();
	public function put(string $local_path, string $remote_key, string $content_type): void {
		$this->objects[$remote_key] = (string)file_get_contents($local_path);
	}
	public function get(string $remote_key, string $local_path): void {
		if (!array_key_exists($remote_key, $this->objects)) {
			throw new RuntimeException('mock: no such object ' . $remote_key);
		}
		file_put_contents($local_path, $this->objects[$remote_key]);
	}
	public function delete(string $remote_key): void { unset($this->objects[$remote_key]); }
	public function url(string $remote_key): string { return ''; }
	public function ping(): array { return array('ok' => true, 'message' => 'mock'); }
}

class InboundRawStorageTest {
	private $pass = 0;
	private $fail = 0;
	private $db;
	private $suffix;
	private $domain_id;
	private $alias_id;
	private $domain;
	private $alias;
	private $router;
	private $pdf_bytes;
	private $created_message_ids = array();
	private $created_file_ids = array();
	private $written_paths = array();

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		if ($c) { $this->pass++; $this->out('  PASS: ' . $l); }
		else    { $this->fail++; $this->out('  FAIL: ' . $l); }
	}
	private function skip($l) { $this->pass++; $this->out('  SKIP: ' . $l); }

	function run() {
		$this->out('=== Inbound raw-storage + lean-record ingest + accessor tests ===');
		try {
			$this->setUp();
			$this->testIngestLeanRecordExtractsFiles();
			$this->testExtractionFailureFallsBackToRaw();
			$this->testAccessorLocalAndPart();
			$this->testAccessorInlineLegacy();
			$this->testAccessorRemoteYieldsNull();
			$this->testAccessorCloud();
			$this->testPermanentDeleteReclaimsFiles();
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
		$this->suffix = substr(md5(uniqid('irs', true)), 0, 8);

		$this->domain = new InboundEmailDomain(NULL);
		$this->domain->set('ied_domain', 'irs-test-' . $this->suffix . '.example');
		$this->domain->set('ied_is_enabled', true);
		$this->domain->save();
		$this->domain_id = intval($this->domain->key);

		$this->alias = new InboundEmailAlias(NULL);
		$this->alias->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$this->alias->set('iea_alias', 'box' . $this->suffix);
		$this->alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$this->alias->set('iea_is_enabled', true);
		$this->alias->prepare(); $this->alias->save();
		$this->alias_id = intval($this->alias->key);

		$this->router = new InboundEmailRouter();
		$this->pdf_bytes = "%PDF-1.4 fake pdf payload " . $this->suffix;
		$this->out('  fixtures ready (suffix ' . $this->suffix . ')');
	}

	private function recipient() { return 'box' . $this->suffix . '@irs-test-' . $this->suffix . '.example'; }

	/** Build a multipart/mixed raw: a text/plain body (section 1) + a pdf attachment (section 2). */
	private function buildRaw($message_id_token) {
		$b = 'BND' . $this->suffix;
		$pdf_b64 = chunk_split(base64_encode($this->pdf_bytes));
		$lines = array(
			'From: Sender <sender@example.com>',
			'To: ' . $this->recipient(),
			'Subject: Raw storage test',
			'Message-ID: <' . $message_id_token . '@example.com>',
			'MIME-Version: 1.0',
			'Content-Type: multipart/mixed; boundary="' . $b . '"',
			'',
			'--' . $b,
			'Content-Type: text/plain; charset=UTF-8',
			'',
			'Hello body text ' . $this->suffix . '.',
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

	private function ingest($message_id_token) {
		$raw = $this->buildRaw($message_id_token);
		$parsed = $this->router->parseEmail($raw);
		$auth = array('dkim' => 'unverified', 'spf' => 'unverified', 'dmarc' => 'unverified', 'source' => 'none');
		$res = $this->router->storeMessage($raw, $parsed, $this->alias, $this->domain, $this->recipient(), $auth);
		if (!empty($res['message'])) {
			$id = intval($res['message']->key);
			$this->created_message_ids[] = $id;
			$this->trackFiles($id);
			return array('id' => $id, 'raw' => $raw);
		}
		return array('id' => 0, 'raw' => $raw);
	}

	/** Remember any file-backed attachment ids for teardown cleanup. */
	private function trackFiles($message_id) {
		$manifest = new MultiInboundMessageAttachment(array('message_id' => $message_id));
		$manifest->load();
		foreach ($manifest as $att) {
			$fil = intval($att->get('ima_fil_file_id'));
			if ($fil > 0) { $this->created_file_ids[] = $fil; }
		}
	}

	private function testIngestLeanRecordExtractsFiles() {
		$r = $this->ingest('ingest-' . $this->suffix);
		$id = $r['id'];
		$this->ok($id > 0, 'storeMessage inserted a row');

		$msg = new InboundEmailMessage($id, TRUE);
		// Lean record: no raw retained.
		$this->ok(($msg->get('iem_raw_storage_driver') ?: 'inline') === 'inline', "no raw retained (driver 'inline')");
		$this->ok((string)$msg->get('iem_raw_storage_key') === '', 'no raw storage key');
		$this->ok((string)$msg->get('iem_raw_message') === '', 'iem_raw_message left empty');
		$this->ok($msg->getRawMessage() === null, 'getRawMessage() is null for a lean record');

		$this->ok(strpos((string)$msg->get('iem_body_plain'), 'Hello body text') !== false,
			'extractBodies still populated the text body');

		$manifest = new MultiInboundMessageAttachment(array('message_id' => $id, 'is_inline' => false));
		$manifest->load();
		$this->ok(count($manifest) === 1, 'one non-inline attachment in the manifest');
		if (count($manifest)) {
			$att = $manifest->get(0);
			$this->ok($att->get('ima_filename') === 'doc.pdf', 'manifest filename is doc.pdf');
			$fil_id = intval($att->get('ima_fil_file_id'));
			$this->ok($fil_id > 0, 'attachment is file-backed (ima_fil_file_id set)');

			$file = new File($fil_id, TRUE);
			$this->ok($file->key && !$file->is_public(), 'the attachment File is private (fil_private)');
			$this->ok(intval($file->get('fil_usr_user_id')) === User::USER_SYSTEM,
				'ownerless alias → File owned by USER_SYSTEM (admins-only)');
			$this->ok($file->read_bytes('original') === $this->pdf_bytes,
				'the File holds the original attachment bytes');
		}
	}

	private function testExtractionFailureFallsBackToRaw() {
		// Force File::createFromBytes() to fail by making upload_dir unwritable, so
		// ingest must fall back to raw storage (a stored raw + section-pointer manifest).
		$upload_dir = Globalvars::get_instance()->get_setting('upload_dir');
		if (!is_dir($upload_dir)) { $this->skip('extraction fallback (no upload_dir)'); return; }

		@chmod($upload_dir, 0555);
		$probe = $upload_dir . '/.probe_' . $this->suffix;
		if (@file_put_contents($probe, 'x') !== false) {
			@unlink($probe); @chmod($upload_dir, 0777);
			$this->skip('extraction fallback (fs not enforcing read-only; likely root)');
			return;
		}

		try {
			$r = $this->ingest('fallback-' . $this->suffix);
			$msg = new InboundEmailMessage($r['id'], TRUE);
			$driver = (string)$msg->get('iem_raw_storage_driver');
			$this->ok($driver === 'local' || $driver === 'inline',
				'extraction failure falls back to raw storage (local or inline)');
			if ($driver === 'local') {
				$this->written_paths[] = RawMessageStore::localPathForKey((string)$msg->get('iem_raw_storage_key'));
			}
			$this->ok($msg->getRawMessage() === $r['raw'], 'the fallback raw round-trips the original message');

			$manifest = new MultiInboundMessageAttachment(array('message_id' => $r['id'], 'is_inline' => false));
			$manifest->load();
			$this->ok(count($manifest) === 1 && intval($manifest->get(0)->get('ima_fil_file_id')) === 0,
				'fallback manifest is a section-pointer (no ima_fil_file_id)');
		} finally {
			@chmod($upload_dir, 0777);
		}
	}

	/** Build a genuine 'local' raw row directly (the fallback/legacy shape). */
	private function makeLocalRow($token) {
		$raw = $this->buildRaw($token);
		$id = $this->insertRow(array('iem_message_id_header' => '<' . $token . '@example.com>'));
		$descriptor = RawMessageStore::write($id, $raw);
		$this->db->prepare("UPDATE iem_inbound_email_messages
			SET iem_raw_storage_driver=?, iem_raw_storage_key=? WHERE iem_inbound_email_message_id=?")
			->execute(array($descriptor['driver'], $descriptor['key'], $id));
		$this->written_paths[] = RawMessageStore::localPathForKey($descriptor['key']);
		return array('id' => $id, 'raw' => $raw, 'key' => $descriptor['key']);
	}

	private function testAccessorLocalAndPart() {
		$r = $this->makeLocalRow('accessor-' . $this->suffix);
		$msg = new InboundEmailMessage($r['id'], TRUE);

		$this->ok($msg->getRawMessage() === $r['raw'], 'getRawMessage() returns the whole raw for a local row');

		$part = $msg->getRawMimePart('2');
		$this->ok($part !== null && $part['content'] === $this->pdf_bytes,
			'getRawMimePart(2) decodes the pdf attachment bytes');
		$this->ok($part !== null && stripos($part['type'], 'application/pdf') !== false,
			'getRawMimePart(2) reports the pdf content-type');
	}

	private function testAccessorInlineLegacy() {
		$raw = $this->buildRaw('inline-' . $this->suffix);
		$id = $this->insertRow(array(
			'iem_raw_message' => $raw,
			'iem_raw_storage_driver' => 'inline',
			'iem_message_id_header' => '<inline-' . $this->suffix . '@example.com>',
		));
		$msg = new InboundEmailMessage($id, TRUE);
		$this->ok($msg->getRawMessage() === $raw, 'getRawMessage() reads a legacy inline row from the column');
		$part = $msg->getRawMimePart('2');
		$this->ok($part !== null && $part['content'] === $this->pdf_bytes, 'getRawMimePart(2) works on an inline row');
	}

	private function testAccessorRemoteYieldsNull() {
		$id = $this->insertRow(array(
			'iem_raw_storage_driver' => 'remote',
			'iem_iia_inbound_imap_account_id' => 999999, // a locator that need not resolve here
			'iem_message_id_header' => '<remote-' . $this->suffix . '@example.com>',
		));
		$msg = new InboundEmailMessage($id, TRUE);
		$this->ok($msg->getRawMessage() === null, 'getRawMessage() is null for a remote row (no platform copy)');
		$this->ok($msg->getRawMimePart('2') === null, 'getRawMimePart() is null for a remote row (caller uses fetchPart)');
	}

	private function testAccessorCloud() {
		$r = $this->makeLocalRow('cloud-' . $this->suffix);
		$msg = new InboundEmailMessage($r['id'], TRUE);
		$key = $r['key'];
		$local_path = RawMessageStore::localPathForKey($key);

		// Move the bytes to the mock private bucket and flip the row to 'cloud'.
		$mock = new RawIngestMockDriver();
		$mock->objects[$key] = (string)file_get_contents($local_path);
		@unlink($local_path); // a cloud row keeps no local copy
		$this->db->prepare("UPDATE iem_inbound_email_messages
			SET iem_raw_storage_driver='cloud' WHERE iem_inbound_email_message_id=?")->execute(array($r['id']));
		$this->injectPrivateDriver($mock);

		$msg2 = new InboundEmailMessage($r['id'], TRUE);
		$this->ok($msg2->getRawMessage() === $r['raw'], 'getRawMessage() pulls a cloud row through the private driver');
		$part = $msg2->getRawMimePart('2');
		$this->ok($part !== null && $part['content'] === $this->pdf_bytes, 'getRawMimePart(2) works on a cloud row');

		CloudStorageDriverFactory::reset();
	}

	private function testPermanentDeleteReclaimsFiles() {
		$r = $this->ingest('delete-' . $this->suffix);
		$id = $r['id'];

		$manifest = new MultiInboundMessageAttachment(array('message_id' => $id, 'file_backed' => true));
		$manifest->load();
		$this->ok(count($manifest) === 1, 'delete fixture has a file-backed attachment');
		$fil_id = count($manifest) ? intval($manifest->get(0)->get('ima_fil_file_id')) : 0;
		$file = $fil_id > 0 ? new File($fil_id, TRUE) : null;
		$path = ($file && $file->key) ? $file->get_filesystem_path('original') : '';
		$this->ok($path !== '' && is_file($path), 'the attachment File exists on disk');

		$msg = new InboundEmailMessage($id, TRUE);
		$msg->permanent_delete();

		$this->ok($path === '' || !is_file($path), 'permanent_delete unlinked the attachment File bytes');
		$file_still = $this->db->prepare("SELECT 1 FROM fil_files WHERE fil_file_id = ?");
		$file_still->execute(array($fil_id));
		$this->ok(!$file_still->fetchColumn(), 'permanent_delete removed the File row');

		$still = $this->db->prepare("SELECT 1 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
		$still->execute(array($id));
		$this->ok(!$still->fetchColumn(), 'permanent_delete removed the message row');
		// No longer ours to clean up.
		$this->created_message_ids = array_values(array_diff($this->created_message_ids, array($id)));
		$this->created_file_ids = array_values(array_diff($this->created_file_ids, array($fil_id)));
	}

	/** Insert a bare message row with overrides; returns its id. */
	private function insertRow(array $overrides) {
		$cols = array(
			'iem_ied_inbound_email_domain_id' => $this->domain_id,
			'iem_iea_inbound_email_alias_id'  => $this->alias_id,
			'iem_sender'    => 'sender@example.com',
			'iem_recipient' => $this->recipient(),
			'iem_subject'   => 'Raw storage test',
			'iem_received_time' => gmdate('Y-m-d H:i:s'),
		);
		$cols = array_merge($cols, $overrides);
		$names = array_keys($cols);
		$ph = implode(',', array_fill(0, count($names), '?'));
		$sql = 'INSERT INTO iem_inbound_email_messages (' . implode(',', $names) . ') VALUES (' . $ph . ')
			RETURNING iem_inbound_email_message_id';
		$stmt = $this->db->prepare($sql);
		$stmt->execute(array_values($cols));
		$id = intval($stmt->fetchColumn());
		$this->created_message_ids[] = $id;
		return $id;
	}

	private function injectPrivateDriver($mock) {
		$ref = new ReflectionProperty('CloudStorageDriverFactory', 'cached_private');
		$ref->setAccessible(true);
		$ref->setValue(null, $mock);
	}

	private function preClean() {
		try {
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'irs-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			if ($dids) {
				$in = implode(',', array_map('intval', $dids));
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
		} catch (\Throwable $e) {}
	}

	private function tearDown() {
		CloudStorageDriverFactory::reset();
		foreach ($this->written_paths as $p) { if (is_file($p)) @unlink($p); }
		try {
			foreach (array_unique($this->created_file_ids) as $fid) {
				try {
					$f = new File(intval($fid), TRUE);
					if ($f->key) { $f->permanent_delete(); }
				} catch (\Throwable $e) {}
			}
			if ($this->created_message_ids) {
				$in = implode(',', array_map('intval', $this->created_message_ids));
				$this->db->exec("DELETE FROM ima_inbound_message_attachments WHERE ima_iem_inbound_email_message_id IN ($in)");
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id IN ($in)");
			}
			if ($this->domain_id) {
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($this->domain_id));
			}
		} catch (\Throwable $e) {}
	}
}

$test = new InboundRawStorageTest();
$ok = $test->run();
exit($ok ? 0 : 1);
?>
