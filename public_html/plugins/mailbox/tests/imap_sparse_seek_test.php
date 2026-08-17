<?php
/** @joinery-test
 * name: imap_sparse_seek
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The two desert-crossing walkers in ImapIngestor, against a fake IMAP server
 * shaped like a decades-old Gmail folder: UIDs into the hundreds of thousands,
 * almost all deleted, the live mail clustered at the top.
 *
 * That shape is what a real account looks like after years of archiving, and it
 * is what broke the first live connect: the day-boundary seek burned its whole
 * probe budget crawling the empty bottom 64 UIDs at a time (48 probes reached
 * UID 1,536 of 270,948), and the ingest walk then advanced one 50-UID window
 * per poll — eighteen days to reach the mail. Both must cross a desert in
 * logarithmic fetches, and neither may ever advance over a UID range it has
 * not fetched and proven empty.
 *
 * No live server and no writes: the fake client honours UID ranges (the seek
 * is meaningless against one that does not), and the private methods are
 * reached by reflection — they are the algorithm under test, not the plumbing
 * around it.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));

/** Fetch data carrying only what the probes read: INTERNALDATE. */
class SparseSeekFetchData {
	private $date;
	public function __construct(?string $dateUtc) { $this->date = $dateUtc; }
	public function getImapDate() {
		return new Horde_Imap_Client_DateTime($this->date ?? gmdate('Y-m-d H:i:s'));
	}
}

/** uid => data map read the way the walkers read Horde_Imap_Client_Fetch_Results. */
class SparseSeekFetchResults implements IteratorAggregate, ArrayAccess {
	private $rows;
	public function __construct(array $rows) { $this->rows = $rows; }
	public function getIterator(): Iterator { return new ArrayIterator($this->rows); }
	public function ids() { return array_keys($this->rows); }
	public function offsetExists($offset): bool { return isset($this->rows[$offset]); }
	#[\ReturnTypeWillChange]
	public function offsetGet($offset) { return $this->rows[$offset] ?? null; }
	public function offsetSet($offset, $value): void { $this->rows[$offset] = $value; }
	public function offsetUnset($offset): void { unset($this->rows[$offset]); }
}

/**
 * A folder as a uid => date map. fetch() honours the UID range and counts
 * itself, so the tests can assert the round-trip budget, which is the whole
 * point of the algorithms under test.
 */
class SparseSeekImapClient implements ImapClient {
	public $messages = array();   // uid => 'Y-m-d H:i:s' internal date
	public $fetches = 0;

	public function status(string $mailbox, int $flags): array {
		$maxUid = count($this->messages) ? max(array_keys($this->messages)) : 0;
		return array('uidvalidity' => 1, 'uidnext' => $maxUid + 1, 'messages' => count($this->messages));
	}

	public function fetch(string $mailbox, Horde_Imap_Client_Fetch_Query $query, array $options = array()) {
		$this->fetches++;
		$spec = (string)($options['ids'] ?? '');
		$from = 1; $to = PHP_INT_MAX;
		if (strpos($spec, ':') !== false) {
			list($f, $t) = explode(':', $spec, 2);
			$from = intval($f); $to = intval($t);
		} elseif ($spec !== '') {
			$from = $to = intval($spec);
		}
		$rows = array();
		foreach ($this->messages as $uid => $date) {
			if ($uid >= $from && $uid <= $to) { $rows[$uid] = new SparseSeekFetchData($date); }
		}
		ksort($rows, SORT_NUMERIC);
		return new SparseSeekFetchResults($rows);
	}

	public function search(string $mailbox, $query = null, array $options = array()): array { return array(); }
	public function store(string $mailbox, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function copy(string $source, string $dest, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function expunge(string $mailbox, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function append(string $mailbox, array $data, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function vanished(string $mailbox, int $modseq, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function listMailboxes($pattern, int $mode = Horde_Imap_Client::MBOX_ALL, array $options = array()): array { return array(); }
	public function createMailbox(string $mailbox): void {}
	public function queryCapability(string $capability): bool { return false; }
	public function logout(): void {}
}

class ImapSparseSeekTest {

	const HIGH_UID = 270948;
	const CUTOFF   = '2026-07-18 00:00:00';
	const IN_WINDOW = '2026-08-10 12:00:00';
	const ANCIENT   = '2019-01-01 12:00:00';

	private $ingestor;

	function run() {
		// The ingestor's account is a prop: the methods under test read only
		// their arguments. Never saved.
		$account = new InboundImapAccount(NULL);
		$this->ingestor = new ImapIngestor($account);

		$this->testDesertSeekConverges();
		$this->testAllPreCutoffSeeksToTop();
		$this->testDenseBoundaryStillExact();
		$this->testWalkJumpsDesert();
		$this->testWalkJumpIsTrimmedToMaxPerRun();
	}

	private function seek(SparseSeekImapClient $client, string $cutoff): array {
		$m = new ReflectionMethod(ImapIngestor::class, 'seekCursorInner');
		$m->setAccessible(true);
		return $m->invoke($this->ingestor, $client, 'INBOX', $cutoff, self::HIGH_UID);
	}

	private function window(SparseSeekImapClient $client, int $lastSeen, int $maxPerRun): array {
		$m = new ReflectionMethod(ImapIngestor::class, 'nextOccupiedWindow');
		$m->setAccessible(true);
		return $m->invoke($this->ingestor, $client, 'INBOX', $lastSeen, self::HIGH_UID, $maxPerRun);
	}

	/** The live-Gmail shape: a top cluster above a quarter-million deleted UIDs. */
	private function desertClient(int $clusterFrom, int $clusterTo, string $date): SparseSeekImapClient {
		$client = new SparseSeekImapClient();
		for ($uid = $clusterFrom; $uid <= $clusterTo; $uid++) {
			$client->messages[$uid] = $date;
		}
		return $client;
	}

	private function testDesertSeekConverges() {
		section('Day-boundary seek across a 270k-UID desert');
		$client = $this->desertClient(270900, 270947, self::IN_WINDOW);
		$found = $this->seek($client, self::CUTOFF);
		check($found['converged'] === true, 'seek converges within the probe budget',
			'probes=' . $found['probes']);
		check($found['cursor'] === 270899, 'cursor lands one below the oldest live message',
			'cursor=' . $found['cursor']);
		check($found['probes'] <= ImapIngestor::SEEK_MAX_PROBES, 'probe budget respected',
			'probes=' . $found['probes']);
	}

	private function testAllPreCutoffSeeksToTop() {
		section('A mailbox whose every message predates the cutoff');
		$client = $this->desertClient(270900, 270947, self::ANCIENT);
		$found = $this->seek($client, self::CUTOFF);
		check($found['cursor'] === self::HIGH_UID, 'nothing to backfill seeds at the top',
			'cursor=' . $found['cursor']);
		check($found['converged'] === true, 'and does so within budget',
			'probes=' . $found['probes']);
	}

	private function testDenseBoundaryStillExact() {
		section('A dense mailbox keeps the exact boundary');
		$client = new SparseSeekImapClient();
		for ($uid = 1; $uid <= 2000; $uid++) {
			$client->messages[$uid] = ($uid <= 1000) ? self::ANCIENT : self::IN_WINDOW;
		}
		$m = new ReflectionMethod(ImapIngestor::class, 'seekCursorInner');
		$m->setAccessible(true);
		$found = $m->invoke($this->ingestor, $client, 'INBOX', self::CUTOFF, 2000);
		check($found['cursor'] === 1000, 'cursor sits exactly one below the oldest in-window message',
			'cursor=' . $found['cursor']);
		check($found['converged'] === true, 'dense bisection converges', 'probes=' . $found['probes']);
	}

	private function testWalkJumpsDesert() {
		section('Ingest walk crosses the desert in one poll');
		$client = $this->desertClient(270900, 270947, self::IN_WINDOW);
		list($floor, $windowEnd, $uids, $fetch) = $this->window($client, 1536, 50);
		check(count($uids) === 48, 'all 48 live messages found in one call', 'got=' . count($uids));
		check(intval($uids[0]) === 270900 && intval($uids[47]) === 270947,
			'the uids are the live cluster', $uids[0] . '..' . $uids[47]);
		check($floor >= 1536 && $floor < 270900, 'the floor advanced only over fetched-empty ranges',
			'floor=' . $floor);
		check($client->fetches <= 16, 'the desert cost logarithmic fetches, not weeks of polls',
			'fetches=' . $client->fetches);
	}

	private function testWalkJumpIsTrimmedToMaxPerRun() {
		section('A jump landing in dense mail is trimmed to one window');
		$client = $this->desertClient(270700, 270947, self::IN_WINDOW); // 248 live
		list($floor, $windowEnd, $uids, $fetch) = $this->window($client, 1536, 50);
		check(count($uids) === 50, 'kept exactly maxPerRun messages', 'got=' . count($uids));
		check($windowEnd === 270749, 'window end pulled back to the last uid kept',
			'end=' . $windowEnd);
		check(intval($uids[0]) === 270700, 'and they are the oldest first', 'first=' . $uids[0]);
	}
}

(new ImapSparseSeekTest())->run();
harness_finish();
