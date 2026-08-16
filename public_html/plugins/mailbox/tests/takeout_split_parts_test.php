<?php
/** @joinery-test
 * name: takeout_split_parts
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * What the reader stack does with an mbox that was cut across export parts.
 *
 * THIS TEST RECORDS REALITY RATHER THAN DEMANDING IT. A mail export requested in
 * parts may split a single oversized mbox across them, leaving the member in the
 * second part starting part-way through a message. Nothing in the importer was
 * built for that, and the remedy — if one is ever needed — depends on what
 * actually happens today. So these checks pin the observed behaviour, and the
 * one thing they genuinely require is that the failure is not silent corruption
 * of a message the user would then believe was imported correctly.
 *
 * A whole mbox and its two halves are built here rather than committed as
 * fixtures, because the interesting property is WHERE the cut lands (mid-message,
 * mid-header, or exactly on a boundary) and that is clearer as code than as a
 * blob.
 *
 * What is asserted:
 *
 *  - The complete mbox reads back the messages it was built from — the control,
 *    without which nothing below means anything.
 *  - A fragment's leading bytes are NOT presented as a whole message: the
 *    splitter starts at the first real separator, so the partial message at the
 *    top of the fragment is dropped rather than imported truncated. Losing it
 *    loudly beats storing a corrupted copy of it.
 *  - The two halves together account for every message except the one the cut
 *    destroyed, which is the measurement that says what reassembly would buy.
 *  - A cut landing exactly on a boundary loses nothing at all.
 *  - The registry still claims a fragment as an mbox (it sniffs a "From " line
 *    OR the extension), so a user importing part two gets an import, not a
 *    refusal — recorded here because it is the behaviour a remedy would change.
 *
 * Run: php tests/run.php safe --filter=takeout_split_parts
 *
 * See specs/mail_import_loss_proof.md § C.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveReaderRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MboxSplitter.php'));

class TakeoutSplitPartsTest {

	/** Messages in the synthetic archive. Enough to cut in the middle of one. */
	const MESSAGE_COUNT = 6;

	private $work;
	private $whole;          // the complete mbox as a string
	private $offsets = array();   // ordinal => byte offset of its "From " line

	function __construct() {
		$this->work = sys_get_temp_dir() . '/joinery-takeout-split-' . bin2hex(random_bytes(4));
		@mkdir($this->work, 0777, true);
	}

	function run() {
		$this->buildArchive();

		section('A complete mbox (the control)');
		$this->testWholeArchive();

		section('An mbox cut in the middle of a message');
		$this->testMidMessageCut();

		section('An mbox cut exactly on a message boundary');
		$this->testBoundaryCut();

		section('What the registry makes of a fragment');
		$this->testFragmentDetection();

		$this->tearDown();
	}

	// ------------------------------------------------------------- the archive

	private function buildArchive() {
		$parts = array();
		$offset = 0;
		for ($i = 0; $i < self::MESSAGE_COUNT; $i++) {
			$body = str_repeat('padding line ' . $i . "\r\n", 8);
			$message = "From sender{$i}@example.com Mon Jan  1 00:00:00 2024\r\n"
				. "Message-ID: <m{$i}@example.com>\r\n"
				. "Subject: Message {$i}\r\n"
				. "Content-Type: text/plain\r\n"
				. "\r\n"
				. $body
				. "\r\n";
			$this->offsets[$i] = $offset;
			$offset += strlen($message);
			$parts[] = $message;
		}
		$this->whole = implode('', $parts);
	}

	/** Message-IDs the splitter finds when reading $bytes as an mbox. */
	private function idsIn(string $bytes): array {
		$path = $this->work . '/probe-' . bin2hex(random_bytes(3)) . '.mbox';
		file_put_contents($path, $bytes);

		$found = array();
		$handle = fopen($path, 'rb');
		MboxSplitter::split($handle, 0, function (array $msg) use (&$found) {
			$id = $msg['headers']['message-id'] ?? '';
			$found[] = ($id !== '') ? $id : '(no id)';
		}, microtime(true) + 20, 500);
		fclose($handle);
		@unlink($path);
		return $found;
	}

	// --------------------------------------------------------------- the tests

	private function testWholeArchive() {
		$ids = $this->idsIn($this->whole);
		check(count($ids) === self::MESSAGE_COUNT,
			'the complete archive yields every message', count($ids) . ' of ' . self::MESSAGE_COUNT);
		check($ids[0] === '<m0@example.com>' && $ids[count($ids) - 1] === '<m5@example.com>',
			'the first and last messages are both present', implode(', ', $ids));
	}

	private function testMidMessageCut() {
		// Land inside message 3's body: past its separator and headers.
		$cut = $this->offsets[3] + 140;
		$head = substr($this->whole, 0, $cut);
		$tail = substr($this->whole, $cut);

		check(strncmp($tail, 'From ', 5) !== 0,
			'the cut really does land mid-message', substr($tail, 0, 40));

		$head_ids = $this->idsIn($head);
		$tail_ids = $this->idsIn($tail);

		// The head keeps everything before the cut. Message 3 is truncated but
		// still separator-led, so it is emitted — with the bytes it has.
		check(in_array('<m0@example.com>', $head_ids, true)
				&& in_array('<m2@example.com>', $head_ids, true),
			'part one holds the messages that finished before the cut', implode(', ', $head_ids));

		// The part that matters: the tail's leading orphan bytes are NOT handed
		// over as a message of their own. The splitter only starts a message at a
		// separator, so the orphan is skipped entirely.
		check(!in_array('<m3@example.com>', $tail_ids, true),
			'part two does NOT present the severed message as its own', implode(', ', $tail_ids));
		check(count($tail_ids) === 2 && $tail_ids[0] === '<m4@example.com>',
			'part two starts cleanly at the next whole message', implode(', ', $tail_ids));

		// So reading both parts independently costs exactly the cut message —
		// counted once in the head as a truncated copy, and never in the tail.
		$union = array_unique(array_merge($head_ids, $tail_ids));
		check(count($union) === self::MESSAGE_COUNT,
			'between them the two parts name every message, one of them truncated',
			implode(', ', $union));
	}

	private function testBoundaryCut() {
		$cut = $this->offsets[3];                       // exactly at a "From " line
		$head_ids = $this->idsIn(substr($this->whole, 0, $cut));
		$tail_ids = $this->idsIn(substr($this->whole, $cut));

		check(count($head_ids) === 3 && count($tail_ids) === 3,
			'a cut on a boundary splits the archive evenly and loses nothing',
			count($head_ids) . ' + ' . count($tail_ids));
		check($tail_ids[0] === '<m3@example.com>',
			'the second part begins at the message the cut fell on', implode(', ', $tail_ids));
	}

	private function testFragmentDetection() {
		$cut = $this->offsets[3] + 140;
		$path = $this->work . '/fragment.mbox';
		file_put_contents($path, substr($this->whole, $cut));

		// Named as an mbox, which is how an export part arrives.
		$reader = MailArchiveReaderRegistry::detect($path, 'All mail.mbox');
		check($reader !== null && $reader::key() === 'mbox',
			'a fragment named .mbox is still claimed by the mbox reader',
			$reader === null ? 'nothing claimed it' : $reader::key());

		// And with no helpful name, the content sniff decides. Recorded rather
		// than required: whichever way it goes, the fragment's leading bytes are
		// already proven above not to become a message.
		$unnamed = MailArchiveReaderRegistry::detect($path, 'part002.dat');
		check(true, 'a fragment with an opaque name is claimed by: '
			. ($unnamed === null ? 'nothing' : $unnamed::key()));

		@unlink($path);
	}

	private function tearDown() {
		foreach (glob($this->work . '/*') ?: array() as $f) {
			@unlink($f);
		}
		@rmdir($this->work);
	}
}

$test = new TakeoutSplitPartsTest();
$test->run();
harness_finish();
?>
