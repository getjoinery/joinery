<?php
/**
 * Inbound raw-storage ingest + accessor tests (push transport, no live IMAP).
 *
 *  - Ingest: storeMessage lands a LOCAL .eml, writes the ima_ manifest from a MIME
 *    parse, stamps driver='local'+key, leaves iem_raw_message empty, and still
 *    extracts the text body.
 *  - Accessor: getRawMessage() returns the right bytes for inline/local/cloud (cloud
 *    via a mock private driver); getRawMimePart() extracts the correct part; a
 *    'remote' row yields null (the caller routes remote to IMAP fetchPart).
 *  - Attachment parity: a pushed message exposes the per-attachment manifest and a
 *    single-part download (getRawMimePart) over the same section the manifest records.
 *  - Inline fallback: a local-write failure stores the raw inline with the descriptor.
 *  - Deletion: permanent_delete reclaims the stored object.
 *
 * Run: php plugins/inbound_email/tests/inbound_raw_storage_test.php  (schema synced).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
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
	private $written_paths = array();

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) {
		if ($c) { $this->pass++; $this->out('  PASS: ' . $l); }
		else    { $this->fail++; $this->out('  FAIL: ' . $l); }
	}
	private function skip($l) { $this->pass++; $this->out('  SKIP: ' . $l); }

	function run() {
		$this->out('=== Inbound raw-storage ingest + accessor tests ===');
		try {
			$this->setUp();
			$this->testIngestWritesLocalFileManifestAndEmptyColumn();
			$this->testAccessorLocalAndPart();
			$this->testAccessorInlineLegacy();
			$this->testAccessorRemoteYieldsNull();
			$this->testAccessorCloud();
			$this->testInlineFallback();
			$this->testPermanentDeleteReclaims();
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
			return array('id' => $id, 'raw' => $raw);
		}
		return array('id' => 0, 'raw' => $raw);
	}

	private function testIngestWritesLocalFileManifestAndEmptyColumn() {
		$r = $this->ingest('ingest-' . $this->suffix);
		$id = $r['id'];
		$this->ok($id > 0, 'storeMessage inserted a row');

		$msg = new InboundEmailMessage($id, TRUE);
		$this->ok($msg->get('iem_raw_storage_driver') === 'local', "driver stamped 'local'");
		$key = (string)$msg->get('iem_raw_storage_key');
		$this->ok($key !== '', 'iem_raw_storage_key set');
		$this->ok((string)$msg->get('iem_raw_message') === '', 'iem_raw_message left empty');

		$path = RawMessageStore::localPathForKey($key);
		$this->written_paths[] = $path;
		$this->ok(is_file($path), 'a local .eml file was written');
		$this->ok((string)file_get_contents($path) === $r['raw'], 'the stored file holds the original raw');

		$this->ok(strpos((string)$msg->get('iem_body_plain'), 'Hello body text') !== false,
			'extractBodies still populated the text body');

		$manifest = new MultiInboundMessageAttachment(array('message_id' => $id, 'is_inline' => false));
		$manifest->load();
		$this->ok(count($manifest) === 1, 'one non-inline attachment in the manifest');
		if (count($manifest)) {
			$this->ok($manifest->get(0)->get('ima_filename') === 'doc.pdf', 'manifest filename is doc.pdf');
			$this->ok((string)$manifest->get(0)->get('ima_mime_part') === '2', 'manifest records MIME section 2');
		}
	}

	private function testAccessorLocalAndPart() {
		$r = $this->ingest('accessor-' . $this->suffix);
		$msg = new InboundEmailMessage($r['id'], TRUE);
		$this->written_paths[] = RawMessageStore::localPathForKey((string)$msg->get('iem_raw_storage_key'));

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
		$r = $this->ingest('cloud-' . $this->suffix);
		$msg = new InboundEmailMessage($r['id'], TRUE);
		$key = (string)$msg->get('iem_raw_storage_key');
		$local_path = RawMessageStore::localPathForKey($key);
		$this->written_paths[] = $local_path;

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

	private function testInlineFallback() {
		// Force RawMessageStore::write() to fail by making this month's storage
		// directory read-only, then confirm storeMessage falls back to inline.
		$month_dir = RawMessageStore::localBase() . 'inbound_email/' . gmdate('Y') . '/' . gmdate('m');
		@mkdir($month_dir, 0777, true);
		if (!is_dir($month_dir)) { $this->skip('inline fallback (could not stage the storage dir)'); return; }

		@chmod($month_dir, 0555);
		// As root, 0555 is still writable — detect and skip rather than false-fail.
		$probe = $month_dir . '/.probe_' . $this->suffix;
		$writable = @file_put_contents($probe, 'x');
		if ($writable !== false) { @unlink($probe); @chmod($month_dir, 0777); $this->skip('inline fallback (fs not enforcing read-only; likely root)'); return; }

		try {
			$r = $this->ingest('fallback-' . $this->suffix);
			$msg = new InboundEmailMessage($r['id'], TRUE);
			$this->ok($msg->get('iem_raw_storage_driver') === 'inline', "local-write failure falls back to driver='inline'");
			$this->ok((string)$msg->get('iem_raw_message') === $r['raw'], 'fallback stores the raw inline');
		} finally {
			@chmod($month_dir, 0777);
		}
	}

	private function testPermanentDeleteReclaims() {
		$r = $this->ingest('delete-' . $this->suffix);
		$id = $r['id'];
		$msg = new InboundEmailMessage($id, TRUE);
		$path = RawMessageStore::localPathForKey((string)$msg->get('iem_raw_storage_key'));
		$this->ok(is_file($path), 'delete fixture has a stored file');

		$msg->permanent_delete();
		$this->ok(!is_file($path), 'permanent_delete unlinked the stored object');

		$still = $this->db->prepare("SELECT 1 FROM iem_inbound_email_messages WHERE iem_inbound_email_message_id = ?");
		$still->execute(array($id));
		$this->ok(!$still->fetchColumn(), 'permanent_delete removed the row');
		// It is no longer ours to clean up.
		$this->created_message_ids = array_values(array_diff($this->created_message_ids, array($id)));
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
