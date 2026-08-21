<?php
/** @joinery-test
 * name: imap_seed_scope_guard
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The day-window scope guard and the search-first boundary seek
 * (specs/imap_seed_scope_guard.md): the cursor decides where to look, the
 * scope decides what to keep.
 *
 * The live failure this pins: a 30-day Gmail connect whose boundary seek burned
 * its probe budget in the sparse UID space and left the cursor at UID 1,536 of
 * 270,948 — and the backfill then stored two-thirds out-of-window mail (973
 * messages back to 2005), because the cursor was the only thing enforcing
 * scope. The guard makes the storage decision independent of the seek, so a
 * conservative cursor costs walk time, never out-of-scope mail.
 *
 * No live server, no writes: the decision methods are reached by reflection —
 * they are the algorithm under test — and the MailRunRecord check is pure.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailRunRecord.php'));

/** Fetch data carrying only what the guard reads: INTERNALDATE, raw. */
class ScopeGuardFetchData {
	private $raw;
	public function __construct(?string $raw) { $this->raw = $raw; }
	public function getImapDate() {
		// Horde's own DateTime subclass: an unparsable string falls back to
		// epoch -1 and flags error(), exactly as a live fetch would.
		return new Horde_Imap_Client_DateTime($this->raw ?? gmdate('Y-m-d H:i:s'));
	}
}

/** A client whose search() answer (or refusal) is scripted per test. */
class ScopeGuardImapClient implements ImapClient {
	public $searchResult = array();   // what search() returns
	public $searchThrows = false;     // Gmail rejecting the emitted form
	public $searches = 0;

	public function search(string $mailbox, $query = null, array $options = array()): array {
		$this->searches++;
		if ($this->searchThrows) {
			throw new RuntimeException('BAD Could not parse command');
		}
		return $this->searchResult;
	}

	public function status(string $mailbox, int $flags): array { return array(); }
	public function fetch(string $mailbox, Horde_Imap_Client_Fetch_Query $query, array $options = array()) { return array(); }
	public function store(string $mailbox, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function copy(string $source, string $dest, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function expunge(string $mailbox, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function append(string $mailbox, array $data, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function vanished(string $mailbox, int $modseq, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function listMailboxes($pattern, int $mode = Horde_Imap_Client::MBOX_ALL, array $options = array()): array { return array(); }
	public function createMailbox(string $mailbox): void {}
	public function queryCapability(string $capability): bool { return true; }
	public function logout(): void {}
}

class ImapSeedScopeGuardTest {

	const CUTOFF    = '2026-07-18 00:00:00';
	const SEED_HIGH = 270948;

	private $ingestor;

	function run() {
		// The ingestor's account is a prop: the methods under test read only
		// their arguments. Never saved.
		$account = new InboundImapAccount(NULL);
		$this->ingestor = new ImapIngestor($account);

		$this->testGuardDecision();
		$this->testGuardTimezoneHandling();
		$this->testSearchSeekCooperativeServer();
		$this->testSearchSeekRefusalsFallBack();
		$this->testRunRecordReconciles();
	}

	private function guard(int $uid, ?string $rawDate, string $cutoff = self::CUTOFF,
			int $seedHigh = self::SEED_HIGH): bool {
		$m = new ReflectionMethod(ImapIngestor::class, 'outOfScopeForBackfill');
		$m->setAccessible(true);
		return $m->invoke($this->ingestor, $uid, new ScopeGuardFetchData($rawDate), $cutoff, $seedHigh);
	}

	private function searchSeek(ScopeGuardImapClient $client, int $highUid): ?array {
		$m = new ReflectionMethod(ImapIngestor::class, 'seekCursorBySearch');
		$m->setAccessible(true);
		return $m->invoke($this->ingestor, $client, 'INBOX', self::CUTOFF, $highUid);
	}

	private function testGuardDecision() {
		section('The guard keeps only what the window asked for');
		check($this->guard(1536, '2019-01-01 12:00:00') === true,
			'a backfill message predating the window is out of scope');
		check($this->guard(1536, '2026-08-10 12:00:00') === false,
			'a backfill message inside the window is kept');
		check($this->guard(self::SEED_HIGH + 1, '2019-01-01 12:00:00') === false,
			'above the seed-time high the guard is off — a message moved in later is kept whatever its age');
		check($this->guard(1536, 'not-a-date') === false,
			'an unreadable INTERNALDATE counts as in-window (fail toward keeping)');
		check($this->guard(1536, '2019-01-01 12:00:00', '') === false,
			'no day window, no guard');
		check($this->guard(1536, '2019-01-01 12:00:00', self::CUTOFF, 0) === false,
			'a folder with no recorded seed high (pre-guard seed) is never filtered');
		check($this->guard(1536, self::CUTOFF) === false,
			'a message exactly at the cutoff is inside the window');
	}

	private function testGuardTimezoneHandling() {
		section('INTERNALDATE offsets are compared in UTC');
		// 03:00 on the 21st at +14:00 is 13:00 on the 20th UTC — before a
		// 2026-07-21 cutoff. Formatting without converting would keep it.
		check($this->guard(1536, '21 Jul 2026 03:00:00 +1400', '2026-07-21 00:00:00') === true,
			'an offset date that is pre-cutoff in UTC is out of scope');
		check($this->guard(1536, '20 Jul 2026 23:00:00 -0500', '2026-07-21 00:00:00') === false,
			'an offset date that is in-window in UTC is kept');
	}

	private function testSearchSeekCooperativeServer() {
		section('A cooperative server answers the boundary in one search');
		$client = new ScopeGuardImapClient();
		$client->searchResult = array('match' => new Horde_Imap_Client_Ids(array(270900, 270910, 270947)));
		$found = $this->searchSeek($client, self::SEED_HIGH);
		check($found !== null, 'the search answer is used');
		check($found['cursor'] === 270899, 'cursor lands one below the oldest in-window UID',
			'cursor=' . ($found['cursor'] ?? '-'));
		check(($found['method'] ?? '') === 'search' && $found['converged'] === true && $found['probes'] === 0,
			'recorded as a converged zero-probe search');

		$client = new ScopeGuardImapClient();
		$client->searchResult = array('match' => new Horde_Imap_Client_Ids(array()));
		$found = $this->searchSeek($client, self::SEED_HIGH);
		check($found !== null && $found['cursor'] === self::SEED_HIGH,
			'an empty match is a real answer: the whole mailbox predates the window, seed at the top');
	}

	private function testSearchSeekRefusalsFallBack() {
		section('Any refusal or unusable answer defers to the bisection');
		$client = new ScopeGuardImapClient();
		$client->searchThrows = true; // the live-Gmail behavior
		check($this->searchSeek($client, self::SEED_HIGH) === null,
			'a rejected SEARCH returns null (bisection decides)');

		$client = new ScopeGuardImapClient();
		$client->searchResult = array(); // no match key at all
		check($this->searchSeek($client, self::SEED_HIGH) === null,
			'an answer without a match set returns null');

		$client = new ScopeGuardImapClient();
		$client->searchResult = array('match' => new Horde_Imap_Client_Ids(array(0)));
		check($this->searchSeek($client, self::SEED_HIGH) === null,
			'a nonsense UID discredits the whole answer');
	}

	private function testRunRecordReconciles() {
		section('out_of_scope is a first-class bucket in the run record');
		$summary = MailRunRecord::summarize(array(
			'seen' => 10, 'stored' => 4, 'dedup' => 2, 'out_of_scope' => 4, 'failed' => 0,
		), 'INBOX');
		check($summary['unaccounted'] === 0, 'skipped messages still balance the books',
			'unaccounted=' . $summary['unaccounted']);
		check($summary['success'] === true, 'a run with only in-scope skips is a success');
		check(strpos($summary['note'], 'out of scope 4') !== false,
			'and the note names the count', $summary['note']);
	}
}

(new ImapSeedScopeGuardTest())->run();
harness_finish();
