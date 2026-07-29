<?php
/** @joinery-test
 * name: mail_archive_readers
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The archive reader layer, with no database and no mail stored.
 *
 * Everything tested here is a pure function of bytes on disk, which is the whole
 * point of the layer being shaped this way: format handling is where mail import
 * goes wrong, and it is exactly the part that should be provable without a
 * mailbox, a session, or a schema.
 *
 * What is asserted:
 *
 *  - Sniffing picks the right reader, and asks the readers in an order where the
 *    loosest question cannot swallow a file the specific ones would have claimed.
 *  - mbox splitting survives From-escaping, finds the final message with no
 *    trailing separator, and reads each message back byte-for-byte.
 *  - .emlx framing is stripped: the length line goes, and so does the plist.
 *  - Provider conventions are read where present and absent harmlessly where not
 *    (Gmail pseudo-labels, Proton sidecars, maildir flag suffixes).
 *  - A message with no Message-ID synthesizes a STABLE id — the same bytes twice
 *    give the same id, which is what makes re-importing an archive dedup.
 *  - Identity resolution: direction and delivery address from declared addresses.
 *  - Outlook files are refused specifically, with the IMAP redirect.
 *
 * Run: php tests/run.php safe --filter=mail_archive_readers
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReaderRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailImportIdentity.php'));

class MailArchiveReadersTest {

	private $fixtures;
	private $work;

	function __construct() {
		$this->fixtures = __DIR__ . '/fixtures/import';
		$this->work = sys_get_temp_dir() . '/joinery-import-readertest-' . bin2hex(random_bytes(4));
		@mkdir($this->work, 0777, true);
	}

	function run() {
		section('Archive reader layer');
		try {
			$this->testDetection();
			$this->testMboxSplitting();
			$this->testEmlxFraming();
			$this->testGmailLabels();
			$this->testProtonMetadata();
			$this->testMaildirFlags();
			$this->testDirectoryWalk();
			$this->testZipReadInPlace();
			$this->testSynthesizedMessageId();
			$this->testIdentity();
			$this->testOutlookRefusal();
		} catch (\Throwable $e) {
			check(false, 'EXCEPTION', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		} finally {
			$this->tearDown();
		}
	}

	/** Collect every descriptor a reader emits, running the scan to completion. */
	private function scanAll(MailArchiveReader $reader, string $path): array {
		$found = array();
		$state = array();
		$guard = 0;
		do {
			$state = $reader->scan($path, function (array $d) use (&$found) { $found[] = $d; },
				$state, microtime(true) + 20);
			$guard++;
		} while (empty($state['done']) && $guard < 50);
		return $found;
	}

	private function testDetection() {
		$cases = array(
			array('takeout.mbox',  'mbox',    'an mbox by extension'),
			array('apple.emlx',    'eml',     'a single .emlx'),
			array('fake.pst',      'outlook', 'an Outlook store by its magic bytes'),
			array('proton',        'eml_dir', 'a directory of saved messages'),
		);
		foreach ($cases as $c) {
			list($name, $expected, $label) = $c;
			$reader = MailArchiveReaderRegistry::detect($this->fixtures . '/' . $name, $name);
			check($reader !== null && $reader::key() === $expected,
				'detect: ' . $label . ' -> ' . $expected,
				$reader === null ? 'nothing claimed it' : 'got ' . $reader::key());
		}

		// An mbox whose name says nothing is still claimed, by its From_ line — and
		// crucially NOT by the loose eml sniff, which would also see headers.
		$anon = $this->work . '/nameless';
		copy($this->fixtures . '/takeout.mbox', $anon);
		$reader = MailArchiveReaderRegistry::detect($anon, 'nameless');
		check($reader !== null && $reader::key() === 'mbox',
			'detect: an extensionless mbox is claimed by the mbox reader, not the eml one',
			$reader === null ? 'nothing claimed it' : 'got ' . $reader::key());

		check(MailArchiveReaderRegistry::detect($this->work . '/does-not-exist', 'x.txt') === null,
			'detect: a file that is not an archive is claimed by nobody');
	}

	private function testMboxSplitting() {
		$reader = new MboxReader();
		$found = $this->scanAll($reader, $this->fixtures . '/takeout.mbox');

		check(count($found) === 3, 'mbox: three messages found', 'got ' . count($found));

		$first = $reader->read($this->fixtures . '/takeout.mbox', $found[0]['locator']);
		check(strpos($first, 'Message-ID: <takeout-one@example.test>') !== false,
			'mbox: the first message reads back with its headers');
		check(strpos($first, 'Thanks for your order.') !== false,
			'mbox: the first message reads back with its body');

		// The escaped line must come back as the author wrote it, and the separator
		// that follows must NOT have been swallowed into the body.
		check(strpos($first, "\nFrom here on the text is quoted.") !== false,
			'mbox: >From escaping is undone on read');
		check(strpos($first, 'Message-ID: <takeout-two@') === false,
			'mbox: a body line beginning From did not merge two messages');

		$last = $reader->read($this->fixtures . '/takeout.mbox', $found[2]['locator']);
		check(strpos($last, 'Click here immediately.') !== false,
			'mbox: the final message is found without a trailing separator');

		// A file whose last message simply stops — no blank line, no newline.
		$truncated = new MboxReader();
		$cut = $this->scanAll($truncated, $this->fixtures . '/truncated.mbox');
		check(count($cut) === 1, 'mbox: a truncated final message is still reported', 'got ' . count($cut));
		check(strpos($truncated->read($this->fixtures . '/truncated.mbox', $cut[0]['locator']),
			'This message ends abruptly') !== false,
			'mbox: a truncated final message reads back');
	}

	private function testEmlxFraming() {
		$raw = file_get_contents($this->fixtures . '/apple.emlx');
		$stripped = MailArchiveReader::stripEmlx($raw);

		check(strncmp($stripped, 'Message-ID:', 11) === 0,
			'emlx: the leading byte-count line is stripped');
		check(strpos($stripped, '<?xml') === false,
			'emlx: the trailing plist is stripped');
		check(strpos($stripped, 'See attached.') !== false,
			'emlx: the message body survives');

		// A plain .eml has no count line and must be left exactly as it is.
		$plain = "Message-ID: <x@y>\r\n\r\nbody\r\n";
		check(MailArchiveReader::stripEmlx($plain) === $plain,
			'emlx: a file without the framing is returned untouched');
	}

	private function testGmailLabels() {
		$headers = MailArchiveReader::parseHeaders('X-Gmail-Labels: Inbox,Receipts,Unread,Starred');
		$state = MailArchiveReader::gmailLabels($headers);

		check($state['labels'] === array('Receipts'),
			'gmail: real labels are kept and pseudo-labels are not',
			implode(',', $state['labels']));
		check($state['is_read'] === false, 'gmail: an Unread label means unread');
		check($state['is_starred'] === true, 'gmail: a Starred label means starred');
		check($state['folder'] === 'Inbox', 'gmail: Inbox is the folder', (string)$state['folder']);

		$spam = MailArchiveReader::gmailLabels(MailArchiveReader::parseHeaders('X-Gmail-Labels: Spam'));
		check($spam['class'] === 'spam', 'gmail: a Spam label classifies the message as spam');

		// Read state is the ABSENCE of Unread, which is the trap in Takeout's format.
		$read = MailArchiveReader::gmailLabels(MailArchiveReader::parseHeaders('X-Gmail-Labels: Inbox'));
		check($read['is_read'] === true, 'gmail: no Unread label means the message was read');

		// Category tabs are Gmail's own assignment, not a label anybody made.
		$cat = MailArchiveReader::gmailLabels(
			MailArchiveReader::parseHeaders('X-Gmail-Labels: Inbox,Category Promotions'));
		check($cat['labels'] === array(), 'gmail: category tabs are not imported as labels',
			implode(',', $cat['labels']));
	}

	private function testProtonMetadata() {
		$json = file_get_contents($this->fixtures . '/proton/user@example.test/aaa.metadata.json');
		$meta = MailArchiveReader::protonMetadata($json);

		check($meta !== null, 'proton: the sidecar parses');
		check($meta['is_read'] === true, 'proton: Unread 0 means the message was read');
		check($meta['is_starred'] === true, 'proton: Starred carries across');
		// Without the manifest this sidecar's custom folder is an opaque id and
		// nothing more — the resolved case is covered below.
		check($meta['labels'] === array(),
			'proton: a sidecar read alone yields no custom label name',
			implode(',', $meta['labels']));
		check($meta['folder'] === 'Inbox', 'proton: the numeric system label maps to a folder',
			(string)$meta['folder']);

		// A real export's sidecar carries bare NUMERIC label ids — Proton's own
		// system labels and views. Anything not recognised is a view this platform
		// does not model, and must be DROPPED: treating an unknown number as a name
		// is how an import tags two thousand messages with a label called "15".
		$real = json_encode(array('Version' => 1, 'Payload' => array(
			'LabelIDs' => array('2', '5', '6', '15', '24', '26'), 'Unread' => 0)));
		$sys = MailArchiveReader::protonMetadata($real);
		check($sys['labels'] === array(),
			'proton: numeric system ids never become labels',
			json_encode($sys['labels']));

		// This message is ARCHIVED and was sent. Only "6" says where it lives; "2"
		// (All Sent) and "5"/"15" (All Mail) are views laid over the whole mailbox.
		// Reading a view as a folder is what filed an entire account under All mail.
		check($sys['folder'] === 'Archived',
			'proton: the location decides the folder, not a view sitting beside it',
			(string)$sys['folder']);

		// Every message in an export carries "5", so if it ever resolved a folder,
		// every message would land in the same one.
		$viewsOnly = json_encode(array('Payload' => array('LabelIDs' => array('5', '15', '24'))));
		check(MailArchiveReader::protonMetadata($viewsOnly)['folder'] === null,
			'proton: a message with nothing but views resolves no folder at all',
			var_export(MailArchiveReader::protonMetadata($viewsOnly)['folder'], true));

		// All Sent sits on sent mail wherever it now lives, so it cannot be the
		// thing that decides Sent. The real Sent id can.
		$reallySent = json_encode(array('Payload' => array('LabelIDs' => array('2', '5', '7'))));
		check(MailArchiveReader::protonMetadata($reallySent)['folder'] === 'Sent',
			'proton: the real Sent id resolves Sent');

		// Trash and Spam still have to win, because they decide whether the message
		// is offered for import at all.
		$binned = json_encode(array('Payload' => array('LabelIDs' => array('2', '3', '5', '6'))));
		$binnedMeta = MailArchiveReader::protonMetadata($binned);
		check($binnedMeta['folder'] === 'Trash' && $binnedMeta['class'] === 'trash',
			'proton: thrown away beats filed away',
			$binnedMeta['folder'] . '/' . $binnedMeta['class']);

		// A custom folder is referenced by an OPAQUE ID and named nowhere except the
		// export's own labels.json. Resolving it through that manifest is the only
		// way the user's folder survives with the name they gave it — losing it, or
		// naming it after the id, both lose their data.
		$manifest = MailArchiveReader::protonLabelMap(
			file_get_contents($this->fixtures . '/proton/user@example.test/labels.json'));
		check(($manifest['kFn6eqxVdWEkL6aSalke=='] ?? '') === 'Meditation',
			'proton: labels.json resolves an opaque folder id to its real name');
		check(($manifest['0'] ?? '') === 'Inbox',
			'proton: the manifest names the system labels too');

		$opaque = json_encode(array('Payload' => array(
			'LabelIDs' => array('0', '5', 'kFn6eqxVdWEkL6aSalke=='))));
		$resolved = MailArchiveReader::protonMetadata($opaque, $manifest);
		check($resolved['labels'] === array('Meditation'),
			'proton: a custom folder imports under its own name',
			json_encode($resolved['labels']));
		check($resolved['folder'] === 'Inbox',
			'proton: system ids still resolve the folder, and never become labels',
			(string)$resolved['folder']);

		// The manifest also says which custom entries are FOLDERS. A message in one
		// of those has no system location at all — the folder IS its location, and
		// without this it would arrive filed nowhere.
		$kinds = MailArchiveReader::protonLabelKinds(
			file_get_contents($this->fixtures . '/proton/user@example.test/labels.json'));
		check(($kinds['kFn6eqxVdWEkL6aSalke=='] ?? '') === 'folder',
			'proton: the manifest Type marks a custom folder as a folder');
		check(($kinds['5'] ?? '') === 'label',
			'proton: a built-in view is not marked a folder');

		$filed = json_encode(array('Payload' => array(
			'LabelIDs' => array('5', '15', 'kFn6eqxVdWEkL6aSalke=='))));
		check(MailArchiveReader::protonMetadata($filed, $manifest, $kinds)['folder'] === 'Meditation',
			'proton: a message in a custom folder lands in that folder',
			(string)MailArchiveReader::protonMetadata($filed, $manifest, $kinds)['folder']);

		// Without the manifest the name simply is not available anywhere, so the id
		// is dropped rather than used as one.
		check(MailArchiveReader::protonMetadata($opaque)['labels'] === array(),
			'proton: with no manifest, an opaque id is dropped rather than used as a name');

		// A sidecar that inlines the name needs no manifest.
		$withName = json_encode(array('Payload' => array(
			'LabelIDs' => array(array('ID' => 'abc123def', 'Name' => 'Receipts')))));
		check(MailArchiveReader::protonMetadata($withName)['labels'] === array('Receipts'),
			'proton: a name inlined in the sidecar is used directly');

		check(MailArchiveReader::protonMetadata(null) === null,
			'proton: no sidecar is not an error');
		check(MailArchiveReader::protonMetadata('not json at all') === null,
			'proton: unreadable metadata is ignored rather than fatal');
	}

	private function testMaildirFlags() {
		$flags = MailArchiveReader::maildirFlags('1465484000.M1P2.host:2,SF');
		check($flags['read'] === true && $flags['starred'] === true && $flags['trashed'] === false,
			'maildir: S and F are read from the filename suffix');

		$none = MailArchiveReader::maildirFlags('1465484000.M1P2.host');
		check($none['read'] === false, 'maildir: a file with no flag suffix is unread');

		$trashed = MailArchiveReader::maildirFlags('x:2,ST');
		check($trashed['trashed'] === true, 'maildir: T means trashed');
	}

	private function testDirectoryWalk() {
		$reader = new EmlDirectoryReader();
		$reader->prepare($this->fixtures . '/proton', $this->work);
		$found = $this->scanAll($reader, $this->fixtures . '/proton');

		check(count($found) === 3, 'directory: three messages found, sidecars excluded',
			'got ' . count($found));

		$byLocator = array();
		foreach ($found as $d) { $byLocator[$d['locator']] = $d; }

		$aaa = $byLocator['f|user@example.test/aaa.eml'] ?? null;
		check($aaa !== null && $aaa['is_starred'] === true,
			'directory: the sidecar beside a message is applied to it');
		// End to end: the walk finds labels.json itself and resolves the sidecar's
		// opaque id through it, without the caller doing anything.
		check($aaa !== null && in_array('Meditation', $aaa['labels'], true),
			'directory: a custom folder is resolved through the export label manifest',
			$aaa ? json_encode($aaa['labels']) : 'no descriptor');

		check(isset($byLocator['f|user@example.test/bbb.eml']),
			'directory: a message with no sidecar is still found');

		$raw = $reader->read($this->fixtures . '/proton', 'f|user@example.test/aaa.eml');
		check(strpos($raw, 'Your statement is ready.') !== false,
			'directory: a message reads back by its locator');

		// A maildir is the same walk, and its flags live in the filename.
		$maildir = new EmlDirectoryReader();
		$maildir->prepare($this->fixtures . '/maildir', $this->work);
		$mail = $this->scanAll($maildir, $this->fixtures . '/maildir');
		check(count($mail) === 1, 'maildir: the member is found', 'got ' . count($mail));
		check(!empty($mail[0]['is_read']) && !empty($mail[0]['is_starred']),
			'maildir: the filename flags become the message state');
	}

	private function testZipReadInPlace() {
		if (!class_exists('ZipArchive')) {
			check(true, 'zip: skipped, the zip extension is not present');
			return;
		}
		$path = $this->work . '/proton.zip';
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		foreach (array('aaa.eml', 'aaa.metadata.json', 'bbb.eml', 'ccc.eml') as $name) {
			$zip->addFile($this->fixtures . '/proton/user@example.test/' . $name,
				'user@example.test/' . $name);
		}
		$zip->close();

		$reader = MailArchiveReaderRegistry::detect($path, 'proton.zip');
		check($reader !== null && $reader::key() === 'zip', 'zip: claimed by the zip reader',
			$reader === null ? 'nothing claimed it' : $reader::key());

		$reader->prepare($path, $this->work);
		$found = $this->scanAll($reader, $path);
		check(count($found) === 3, 'zip: three messages found inside, sidecar excluded',
			'got ' . count($found));

		$raw = $reader->read($path, 'f|user@example.test/aaa.eml');
		check(strpos($raw, 'Your statement is ready.') !== false,
			'zip: a member reads back straight out of the archive');

		// Reading in place is the point: nothing should have been expanded.
		check(!is_dir($this->work . '/tree'),
			'zip: nothing was extracted to read .eml members');
	}

	private function testSynthesizedMessageId() {
		$raw = file_get_contents($this->fixtures . '/proton/user@example.test/ccc.eml');
		$headers = MailArchiveReader::parseHeaders(MailArchiveReader::headerBlock($raw));

		check(MailArchiveReader::header($headers, 'message-id') === '',
			'message-id: the fixture genuinely has none');

		$first = MailArchiveReader::messageId($headers, $raw);
		$second = MailArchiveReader::messageId($headers, $raw);
		check($first === $second, 'message-id: the same bytes synthesize the same id — re-import dedups');
		check(substr($first, -strlen('@import.invalid>')) === '@import.invalid>',
			'message-id: synthesized ids use a domain that can never be real', $first);

		$other = MailArchiveReader::messageId($headers, $raw . 'x');
		check($first !== $other, 'message-id: different bytes synthesize different ids');

		// A real header is used as-is, angle brackets and all.
		$withId = MailArchiveReader::parseHeaders('Message-ID: <real@example.test>');
		check(MailArchiveReader::messageId($withId, 'anything') === '<real@example.test>',
			'message-id: an existing header is never replaced');
	}

	private function testIdentity() {
		$own = array('me@example.test', 'old@example.test');

		$sent = MailArchiveReader::parseHeaders("From: me@example.test\nTo: friend@elsewhere.test");
		check(MailImportIdentity::direction($sent, $own, 'All mail') === 'outbound',
			'identity: mail from one of your addresses is mail you sent');

		$received = MailArchiveReader::parseHeaders("From: friend@elsewhere.test\nTo: me@example.test");
		check(MailImportIdentity::direction($received, $own, 'Inbox') === 'inbound',
			'identity: mail from anyone else is mail you received');

		// The folder outranks the headers: a message filed in Sent was sent, even
		// when its From is an address the user forgot to declare.
		$forgotten = MailArchiveReader::parseHeaders("From: ancient@example.test\nTo: x@y.test");
		check(MailImportIdentity::direction($forgotten, $own, 'Sent') === 'outbound',
			'identity: the Sent folder settles direction even when From does not match');

		$delivered = MailArchiveReader::parseHeaders(
			"Delivered-To: old@example.test\nTo: list@lists.test\nCc: me@example.test");
		check(MailImportIdentity::deliveryAddress($delivered, $own, 'box@site.test') === 'old@example.test',
			'identity: the envelope header wins over To and Cc');

		$viaCc = MailArchiveReader::parseHeaders("To: list@lists.test\nCc: me@example.test");
		check(MailImportIdentity::deliveryAddress($viaCc, $own, 'box@site.test') === 'me@example.test',
			'identity: Cc counts when nothing recorded the envelope');

		$bcc = MailArchiveReader::parseHeaders("To: someone@elsewhere.test");
		check(MailImportIdentity::deliveryAddress($bcc, $own, 'box@site.test') === 'box@site.test',
			'identity: a bcc falls back to the mailbox address rather than guessing');

		$out = MailArchiveReader::parseHeaders("From: me@example.test\nTo: First <first@x.test>, second@y.test");
		check(MailImportIdentity::deliveryAddress($out, $own, 'box@site.test', 'outbound') === 'first@x.test',
			'identity: sent mail records its first recipient');

		check(MailImportIdentity::addressesIn('Name <A@B.test>, plain@c.test')
			=== array('a@b.test', 'plain@c.test'),
			'identity: display names and bare addresses both parse, lowercased');

		$dated = MailArchiveReader::parseHeaders('Date: Mon, 5 Jan 2015 09:14:22 +0000');
		check(MailImportIdentity::receivedTime($dated) === '2015-01-05 09:14:22',
			'identity: the Date header becomes the received time, in UTC',
			(string)MailImportIdentity::receivedTime($dated));

		// A Date header whose day name disagrees with the date is common in real
		// archives, and PHP reads the mismatch as "move to the next such day" —
		// silently shifting the message by up to six days. 3 Feb 2016 was a
		// Wednesday, and the header says Tuesday.
		$wrongDay = MailArchiveReader::parseHeaders('Date: Tue, 3 Feb 2016 11:02:00 +0000');
		check(MailImportIdentity::receivedTime($wrongDay) === '2016-02-03 11:02:00',
			'identity: a wrong day name in Date does not shift the message',
			(string)MailImportIdentity::receivedTime($wrongDay));

		$fullDay = MailArchiveReader::parseHeaders('Date: Wednesday, 3 Feb 2016 11:02:00 +0000');
		check(MailImportIdentity::receivedTime($fullDay) === '2016-02-03 11:02:00',
			'identity: a spelled-out day name is handled too',
			(string)MailImportIdentity::receivedTime($fullDay));

		$noDay = MailArchiveReader::parseHeaders('Date: 3 Feb 2016 11:02:00 +0000');
		check(MailImportIdentity::receivedTime($noDay) === '2016-02-03 11:02:00',
			'identity: a Date with no day name at all still parses',
			(string)MailImportIdentity::receivedTime($noDay));

		check(MailImportIdentity::receivedTime(MailArchiveReader::parseHeaders('Date: not a date')) === null,
			'identity: an unparseable date is refused rather than guessed');
		check(MailImportIdentity::receivedTime(MailArchiveReader::parseHeaders(
			'Date: Mon, 5 Jan 2195 09:14:22 +0000')) === null,
			'identity: a date far in the future is refused, so it cannot pin itself to the top');
	}

	private function testOutlookRefusal() {
		$reader = MailArchiveReaderRegistry::detect($this->fixtures . '/fake.pst', 'archive.pst');
		check($reader !== null && $reader::key() === 'outlook', 'outlook: the file is recognised');

		$refusal = $reader->refusal();
		check($refusal !== null && stripos($refusal, 'IMAP') !== false,
			'outlook: the refusal names IMAP as the way that does work', (string)$refusal);

		// Recognised by magic bytes too, so a renamed .pst is still refused specifically.
		$renamed = MailArchiveReaderRegistry::detect($this->fixtures . '/fake.pst', 'backup.dat');
		check($renamed !== null && $renamed::key() === 'outlook',
			'outlook: a renamed Outlook file is still recognised by its contents');
	}

	private function tearDown() {
		if (!is_dir($this->work)) {
			return;
		}
		foreach (glob($this->work . '/*') ?: array() as $f) {
			is_dir($f) ? @rmdir($f) : @unlink($f);
		}
		@rmdir($this->work);
	}
}

$test = new MailArchiveReadersTest();
$test->run();
harness_finish();
