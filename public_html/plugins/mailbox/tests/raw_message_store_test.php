<?php
/** @joinery-test
 * name: raw_message_store
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * RawMessageStore tests — the mail StorageProfile's key scheme + request-time I/O.
 *
 *  - keyFor() lays out mailbox/{yyyy}/{mm}/{id}.eml sharded by received-month.
 *  - LOCAL round-trip: write() lands a file under {site_root}/storage/ and read()
 *    returns identical bytes; itemsForRow()/reverseItemsForRow() enumerate the one
 *    .eml; a missing file makes itemsForRow() null and read() throw cleanly.
 *  - CLOUD round-trip via a mock private driver (injected into the factory cache):
 *    read('cloud') pulls + returns bytes; delete('cloud') removes the object.
 *  - delete() is a no-op for inline / remote (nothing platform-owned).
 *
 * Run: php plugins/mailbox/tests/raw_message_store_test.php  (schema synced).
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriver.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RawMessageStore.php'));
require_once(__DIR__ . '/../../../tests/lib/cloud_fixtures.php'); // InMemoryBlobDriver
require_once(__DIR__ . '/lib/mailbox_test_fixture.php'); // mailbox_purge_domains()

class RawMessageStoreTest {
	private $db;
	private $suffix;
	private $domain_id;
	private $alias_id;
	private $message_id;
	private $written_paths = array();

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	private function out($m) { echo (php_sapi_name() === 'cli' ? '' : '<br>') . $m . "\n"; }
	private function ok($c, $l) { return check((bool)$c, $l); }

	function run() {
		try {
			$this->setUp();
			$this->testKeyLayout();
			$this->testLocalRoundTrip();
			$this->testProfileEnumeration();
			$this->testMissingObject();
			$this->testCloudRoundTrip();
			$this->testDeleteNoOps();
		} catch (\Throwable $e) {
			check(false, 'uncaught exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('rms', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'rms-test-' . $this->suffix . '.example');
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

		// A stored message with a fixed received-month, so the key layout is exact.
		$stmt = $this->db->prepare("INSERT INTO iem_inbound_email_messages
			(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_sender, iem_recipient,
			 iem_subject, iem_message_id_header, iem_received_time)
			VALUES (?, ?, 'from@x', ?, 'subj', ?, '2026-03-15 12:00:00')
			RETURNING iem_inbound_email_message_id");
		$stmt->execute(array($this->domain_id, $this->alias_id, 'box' . $this->suffix . '@x',
			'<rms-' . $this->suffix . '@x>'));
		$this->message_id = intval($stmt->fetchColumn());

		$this->out('  fixtures ready (suffix ' . $this->suffix . ', id ' . $this->message_id . ')');
	}

	private function preClean() {
		mailbox_purge_domains('rms-test-%');
	}

	private function testKeyLayout() {
		section('Key layout');
		$key = RawMessageStore::keyFor($this->message_id);
		$this->ok($key === 'mailbox/2026/03/' . $this->message_id . '.eml',
			'keyFor lays out mailbox/{yyyy}/{mm}/{id}.eml (' . $key . ')');
		$path = RawMessageStore::localPathForKey($key);
		$expected_base = rtrim(PathHelper::getSiteRoot(), '/') . '/storage/';
		$this->ok(strpos($path, $expected_base) === 0, 'local path is rooted at {site_root}/storage/');
	}

	private function testLocalRoundTrip() {
		section('Local round-trip');
		$raw = "From: a@b\r\nSubject: hi\r\n\r\nbody bytes " . $this->suffix;
		$descriptor = RawMessageStore::write($this->message_id, $raw);
		$this->ok($descriptor['driver'] === 'local', 'write() returns driver=local');
		$path = RawMessageStore::localPathForKey($descriptor['key']);
		$this->written_paths[] = $path;
		$this->ok(is_file($path), 'write() created the local .eml file');
		$this->ok(RawMessageStore::read('local', $descriptor['key']) === $raw, 'read(local) returns identical bytes');

		// Persist the descriptor so the profile enumeration reads the real key.
		$this->db->prepare("UPDATE iem_inbound_email_messages
			SET iem_raw_storage_driver='local', iem_raw_storage_key=?
			WHERE iem_inbound_email_message_id=?")->execute(array($descriptor['key'], $this->message_id));
	}

	private function testProfileEnumeration() {
		section('Profile enumeration');
		$profile = new RawMessageStore();
		$this->ok($profile->visibility() === 'private', 'profile visibility is private');
		$this->ok($profile->table() === 'iem_inbound_email_messages', 'profile targets the messages table');
		$this->ok($profile->rowExists($this->message_id), 'rowExists true for the fixture');
		$this->ok($profile->isEligibleRow($this->message_id), 'isEligibleRow true for a local row');

		$fwd = $profile->itemsForRow($this->message_id);
		$this->ok(is_array($fwd) && count($fwd) === 1, 'itemsForRow enumerates exactly one object');
		$this->ok($fwd[0]['content_type'] === 'message/rfc822', 'forward item is message/rfc822');
		$this->ok(substr($fwd[0]['remote_key'], -4) === '.eml', 'forward remote_key is the .eml key');

		$rev = $profile->reverseItemsForRow($this->message_id);
		$this->ok(count($rev) === 1 && $rev[0]['remote_key'] === $fwd[0]['remote_key'],
			'reverseItemsForRow mirrors the same single object');
	}

	private function testMissingObject() {
		section('Missing object');
		$profile = new RawMessageStore();
		// Point the row at a key whose file does not exist.
		$this->db->prepare("UPDATE iem_inbound_email_messages
			SET iem_raw_storage_key=? WHERE iem_inbound_email_message_id=?")
			->execute(array('mailbox/2026/03/does-not-exist.eml', $this->message_id));
		$this->ok($profile->itemsForRow($this->message_id) === null, 'itemsForRow null when the file is missing');

		$threw = false;
		try { RawMessageStore::read('local', 'mailbox/2026/03/missing.eml'); }
		catch (RawMessageStoreException $e) { $threw = true; }
		$this->ok($threw, 'read(local) throws cleanly on a missing file');

		// Restore the real key for later steps.
		$key = RawMessageStore::keyFor($this->message_id);
		$this->db->prepare("UPDATE iem_inbound_email_messages
			SET iem_raw_storage_key=? WHERE iem_inbound_email_message_id=?")
			->execute(array($key, $this->message_id));
	}

	private function testCloudRoundTrip() {
		section('Cloud round-trip');
		$mock = new InMemoryBlobDriver();
		$key = RawMessageStore::keyFor($this->message_id);
		$raw = "From: c@d\r\nSubject: cloud\r\n\r\ncloud bytes " . $this->suffix;
		$mock->objects[$key] = $raw;
		$this->injectPrivateDriver($mock);

		$this->ok(RawMessageStore::read('cloud', $key) === $raw, 'read(cloud) pulls identical bytes via the private driver');

		RawMessageStore::delete('cloud', $key);
		$this->ok(!array_key_exists($key, $mock->objects), 'delete(cloud) removes the private object');

		$this->resetFactory();
	}

	private function testDeleteNoOps() {
		section('Delete no-ops');
		// inline / remote own no platform object — delete must not throw or touch fs.
		$threw = false;
		try {
			RawMessageStore::delete('inline', '');
			RawMessageStore::delete('remote', 'inbound_email/whatever.eml');
		} catch (\Throwable $e) { $threw = true; }
		$this->ok(!$threw, 'delete() is a silent no-op for inline and remote');
	}

	/** Force CloudStorageDriverFactory::forVisibility('private') to return $mock. */
	private function injectPrivateDriver($mock) {
		$ref = new ReflectionProperty('CloudStorageDriverFactory', 'cached_private');
		$ref->setAccessible(true);
		$ref->setValue(null, $mock);
	}

	private function resetFactory() {
		CloudStorageDriverFactory::reset();
	}

	private function tearDown() {
		$this->resetFactory();
		foreach ($this->written_paths as $p) { if (is_file($p)) @unlink($p); }
		try {
			if ($this->domain_id) {
				$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id = " . intval($this->domain_id));
				$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id = " . intval($this->domain_id));
			}
		} catch (\Throwable $e) {}
	}
}

$test = new RawMessageStoreTest();
$test->run();
harness_finish();
