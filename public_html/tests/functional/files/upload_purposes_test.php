<?php
/** @joinery-test
 * name: upload_purposes
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Chunked uploads for a non-Drive purpose.
 *
 * The resumable transport is the only way a file larger than one web request gets
 * onto this platform, and it used to serve exactly one consumer. What is tested
 * here is the seam that opened it to others, and the boundaries around that seam:
 *
 *  - a registered purpose opens an upload, receives chunks, and produces a File
 *    carrying that purpose's origin tag
 *  - an unknown purpose is refused at init, before any token exists
 *  - a purpose whose authorize hook refuses is refused with ITS reason, not a
 *    generic one
 *  - the purpose is read from the UPLOAD, so an upload opened for one purpose
 *    cannot be completed as another and borrow its policy
 *  - a file too large for a single request completes anyway, which is the entire
 *    reason this exists
 *  - the resulting File is invisible to Drive and contributes nothing to Drive
 *    usage, even though it belongs to the same user
 *
 * Drive's own upload path is deliberately untouched by all of this and is covered
 * by drive_upload_api; that suite passing unchanged is the other half of the
 * contract.
 *
 * Run: php tests/run.php db --filter=upload_purposes
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/UploadPurposeRegistry.php'));
require_once(PathHelper::getIncludePath('data/file_uploads_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('logic/drive_upload_init_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_upload_complete_logic.php'));

class UploadPurposesTest {

	const PURPOSE = 'test_purpose';
	const REFUSING = 'test_purpose_refused';

	private $db;
	private $user_id;
	private $file_ids = array();
	private $upload_ids = array();
	private $completed = 0;

	function __construct() {
		$this->db = DbConnector::get_instance()->get_db_link();
	}

	function run() {
		section('Chunked upload purposes');
		try {
			$this->setUp();
			$this->testRegistration();
			$this->testUnknownPurposeRefused();
			$this->testAuthorizeRefusalIsItsOwn();
			$this->testRoundTripProducesATaggedFile();
			$this->testLargerThanOneRequest();
			$this->testPurposeCannotBeSwapped();
			$this->testInvisibleToDrive();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	private function setUp() {
		// The upload actions are session-scoped, so give the run a real user of its
		// own rather than borrowing whoever the harness happens to be. Its files are
		// removed in teardown.
		$user = make_user('uploadpurpose');
		$this->user_id = intval($user->key);
		$_SESSION['usr_user_id'] = $this->user_id;
		$_SESSION['loggedin'] = true;

		UploadPurposeRegistry::register(self::PURPOSE, array(
			'source'       => File::SOURCE_MAIL_IMPORT_ARCHIVE,
			'label'        => 'test archive',
			'restrictions' => array('fil_private' => true),
			'authorize'    => function (int $uid, array $input): ?string {
				$this->completed = $this->completed; // closure keeps $this bound
				return null;
			},
			'on_complete'  => function (File $f, $up, int $uid): void {
				$this->completed++;
			},
		));

		UploadPurposeRegistry::register(self::REFUSING, array(
			'source'    => File::SOURCE_MAIL_IMPORT_ARCHIVE,
			'authorize' => function (int $uid, array $input): ?string {
				return 'This purpose says no, for its own reasons.';
			},
		));
	}

	/** Open an upload and return the decoded init payload. */
	private function init(string $purpose, string $name, int $size): array {
		$result = drive_upload_init_logic(array(
			'purpose'    => $purpose,
			'name'       => $name,
			'size_bytes' => $size,
			'mime_type'  => 'application/octet-stream',
		));
		return array(
			'error' => $result->error,
			'data'  => is_array($result->data) ? $result->data : array(),
		);
	}

	/**
	 * Write the bytes straight into the upload's part file and stamp the received
	 * counter — the transport's effect, without an HTTP round trip. The transport
	 * itself is unchanged by this work and is covered by drive_upload_api.
	 */
	private function deliver(string $token, string $bytes): FileUpload {
		$up = FileUpload::load_by_token($token);
		$part = $up->part_path();
		$dir = dirname($part);
		if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
		file_put_contents($part, $bytes);
		$up->set('fup_received_bytes', strlen($bytes));
		$up->save();
		$this->upload_ids[] = intval($up->key);
		return $up;
	}

	private function complete(string $token): array {
		$result = drive_upload_complete_logic(array('upload_token' => $token));
		$data = is_array($result->data) ? $result->data : array();
		if (!empty($data['file']['id'])) {
			$this->file_ids[] = intval($data['file']['id']);
		}
		return array(
			'error' => $result->error,
			'data'  => $data,
		);
	}

	// ------------------------------------------------------------------ tests

	private function testRegistration() {
		check(in_array(self::PURPOSE, UploadPurposeRegistry::names(), true),
			'registry: a registered purpose is listed');
		check(UploadPurposeRegistry::get(self::PURPOSE) !== null,
			'registry: its spec comes back');
		check(UploadPurposeRegistry::get('nothing_registered_this') === null,
			'registry: an unregistered name returns nothing');

		// Drive is not a registered purpose and must not be mistaken for one — it
		// keeps its own path precisely because it does more than this serves.
		check(UploadPurposeRegistry::isDrive('drive'), 'registry: drive is recognised as Drive');
		check(UploadPurposeRegistry::isDrive(''), 'registry: an absent purpose means Drive');
		check(!UploadPurposeRegistry::isDrive(self::PURPOSE), 'registry: another purpose is not Drive');

		$threw = false;
		try {
			UploadPurposeRegistry::register('drive', array('source' => 'x'));
		} catch (\Throwable $e) {
			$threw = true;
		}
		check($threw, 'registry: registering under the name drive is refused');

		$threw = false;
		try {
			UploadPurposeRegistry::register('sourceless', array());
		} catch (\Throwable $e) {
			$threw = true;
		}
		check($threw, 'registry: a purpose with no fil_source is refused at registration');
	}

	private function testUnknownPurposeRefused() {
		$res = $this->init('no_such_purpose_exists', 'x.mbox', 10);
		check($res['error'] !== null, 'unknown purpose: refused', (string)$res['error']);
		check(empty($res['data']['upload_token']),
			'unknown purpose: no token is minted, so nothing can be uploaded against it');
	}

	private function testAuthorizeRefusalIsItsOwn() {
		$res = $this->init(self::REFUSING, 'x.mbox', 10);
		check($res['error'] !== null, 'authorize: a refusing purpose is refused');
		check(strpos((string)$res['error'], 'for its own reasons') !== false,
			'authorize: the purpose\'s OWN reason reaches the caller, not a generic one',
			(string)$res['error']);
	}

	private function testRoundTripProducesATaggedFile() {
		$bytes = str_repeat('From nobody Mon Jan  1 00:00:00 2020' . "\n\n", 20);
		$before = $this->completed;

		$res = $this->init(self::PURPOSE, 'roundtrip.mbox', strlen($bytes));
		check($res['error'] === null, 'round trip: the upload opened', (string)$res['error']);
		$token = (string)($res['data']['upload_token'] ?? '');
		check($token !== '', 'round trip: a token was issued');
		check(intval($res['data']['chunk_bytes'] ?? 0) > 0, 'round trip: a chunk size came back');

		$up = $this->deliver($token, $bytes);
		check((string)$up->get('fup_purpose') === self::PURPOSE,
			'round trip: the upload records its purpose', (string)$up->get('fup_purpose'));

		$done = $this->complete($token);
		check($done['error'] === null, 'round trip: it completed', (string)$done['error']);

		$file_id = intval($done['data']['file']['id'] ?? 0);
		check($file_id > 0, 'round trip: a File came back');
		if ($file_id > 0) {
			$file = new File($file_id, TRUE);
			check((string)$file->get('fil_source') === File::SOURCE_MAIL_IMPORT_ARCHIVE,
				'round trip: the File carries the purpose\'s origin tag',
				(string)$file->get('fil_source'));
			check((bool)$file->get('fil_private'),
				'round trip: the purpose\'s restrictions were applied');
			check($file->size_bytes() === strlen($bytes),
				'round trip: every byte arrived', $file->size_bytes() . ' of ' . strlen($bytes));
		}

		check($this->completed === $before + 1,
			'round trip: the purpose\'s on_complete hook ran exactly once',
			'delta ' . ($this->completed - $before));
	}

	/**
	 * The whole point: a file bigger than a single request can carry still lands.
	 * Sized above post_max_size so it could never have arrived as a form POST.
	 */
	private function testLargerThanOneRequest() {
		$ceiling = self::iniBytes((string)ini_get('post_max_size'));
		$size = $ceiling > 0 ? $ceiling + 1048576 : 9437184;
		$bytes = str_repeat('x', $size);

		$res = $this->init(self::PURPOSE, 'oversize.mbox', $size);
		check($res['error'] === null, 'oversize: an upload larger than post_max_size opens',
			(string)$res['error']);

		$token = (string)($res['data']['upload_token'] ?? '');
		$this->deliver($token, $bytes);
		$done = $this->complete($token);

		check($done['error'] === null, 'oversize: it completes', (string)$done['error']);
		$file_id = intval($done['data']['file']['id'] ?? 0);
		if ($file_id > 0) {
			$file = new File($file_id, TRUE);
			check($file->size_bytes() === $size,
				'oversize: all ' . round($size / 1048576, 1) . ' MB stored, past a limit a form post could not clear',
				$file->size_bytes() . ' of ' . $size);
		}
	}

	private function testPurposeCannotBeSwapped() {
		$bytes = 'swap test';
		$res = $this->init(self::PURPOSE, 'swap.mbox', strlen($bytes));
		$token = (string)($res['data']['upload_token'] ?? '');
		$this->deliver($token, $bytes);

		// Ask to complete it as Drive. The purpose is read from the upload row, so
		// the request's opinion is irrelevant — the file must still come out tagged
		// for the purpose it was opened under.
		$done = drive_upload_complete_logic(array('upload_token' => $token, 'purpose' => 'drive'));
		$data = is_array($done->data) ? $done->data : array();
		$file_id = intval($data['file']['id'] ?? 0);
		if ($file_id > 0) { $this->file_ids[] = $file_id; }

		check($file_id > 0, 'purpose swap: the upload still completed');
		if ($file_id > 0) {
			$file = new File($file_id, TRUE);
			check((string)$file->get('fil_source') === File::SOURCE_MAIL_IMPORT_ARCHIVE,
				'purpose swap: it kept the purpose it was OPENED under, not the one requested',
				(string)$file->get('fil_source'));
		}
	}

	private function testInvisibleToDrive() {
		if (!$this->file_ids) {
			check(false, 'drive isolation: no file to check');
			return;
		}
		$id = $this->file_ids[0];

		$stmt = $this->db->prepare("SELECT COUNT(*) FROM fil_files
			WHERE fil_file_id = ? AND fil_source = 'drive'");
		$stmt->execute(array($id));
		check(intval($stmt->fetchColumn()) === 0,
			'drive isolation: the file is not a Drive item, so no Drive listing shows it');

		// Drive usage sums only drive-tagged files, so a purpose file adds nothing
		// to the member's quota however large it is.
		$stmt = $this->db->prepare("SELECT COALESCE(SUM(b.fbb_size_bytes), 0)
			FROM fil_files f JOIN fbb_file_blobs b ON b.fbb_file_blob_id = f.fil_fbb_file_blob_id
			WHERE f.fil_usr_user_id = ? AND f.fil_source = 'drive' AND f.fil_file_id = ?");
		$stmt->execute(array($this->user_id, $id));
		check(intval($stmt->fetchColumn()) === 0,
			'drive isolation: it contributes nothing to Drive usage');
	}

	private static function iniBytes(string $value): int {
		$value = trim($value);
		if ($value === '') { return 0; }
		$unit = strtolower(substr($value, -1));
		$n = (int)$value;
		if ($unit === 'g') { return $n * 1073741824; }
		if ($unit === 'm') { return $n * 1048576; }
		if ($unit === 'k') { return $n * 1024; }
		return $n;
	}

	private function tearDown() {
		foreach ($this->file_ids as $id) {
			try {
				$f = new File(intval($id), TRUE);
				if ($f->key) { $f->permanent_delete(); }
			} catch (\Throwable $e) {}
		}
		foreach ($this->upload_ids as $id) {
			try {
				$this->db->exec('DELETE FROM fup_file_uploads WHERE fup_file_upload_id = ' . intval($id));
			} catch (\Throwable $e) {}
		}
	}
}

$test = new UploadPurposesTest();
$test->run();
harness_finish();
