<?php
/** @joinery-test
 * name: imap_draft_ingest
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A draft on the source is never ingested (specs/bugfix_imap_draft_ingest.md).
 *
 * The live failure this pins: composing one message in Gmail put four incoming
 * emails from himself in the owner's mailbox. Gmail's [Gmail]/All Mail carries
 * drafts beside real mail, and every autosave replaces the draft with a fresh
 * UID, so the coverage pass met each half-written revision as brand-new mail
 * above its cursor and stored it — one per poll for as long as the compose
 * window stayed open, each with its own Message-ID, so no dedup could ever
 * collapse them. The four rows grew 1137 → 3695 → 5505 → 7352 bytes, which is
 * the shape of the message being typed.
 *
 * No live server, no writes: the decision method is reached by reflection (it
 * is the algorithm under test), fetchWindow is driven through a capturing fake
 * client, and the MailRunRecord check is pure.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailRunRecord.php'));

/** Fetch data carrying only what the draft predicate reads. */
class DraftFetchData {
	private $flags; private $throws;
	public function __construct(array $flags, bool $throws = false) {
		$this->flags = $flags; $this->throws = $throws;
	}
	public function getFlags() {
		if ($this->throws) { throw new RuntimeException('no FLAGS in this response'); }
		return $this->flags;
	}
}

/** A client that records the fetch query it was handed. */
class DraftCapturingImapClient implements ImapClient {
	public $lastQuery = null;

	public function fetch(string $mailbox, Horde_Imap_Client_Fetch_Query $query, array $options = array()) {
		$this->lastQuery = $query;
		return array();
	}

	public function search(string $mailbox, $query = null, array $options = array()): array { return array(); }
	public function status(string $mailbox, int $flags): array { return array(); }
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

class ImapDraftIngestTest {

	private $ingestor;

	function run() {
		// The ingestor's account is a prop: the methods under test read only
		// their arguments. Never saved.
		$account = new InboundImapAccount(NULL);
		$this->ingestor = new ImapIngestor($account);

		$this->testFlagRecognition();
		$this->testFailsTowardIngesting();
		$this->testFetchAsksForFlags();
		$this->testRunRecordReconciles();
	}

	private function isDraft($data): bool {
		$m = new ReflectionMethod('ImapIngestor', 'isSourceDraft');
		$m->setAccessible(true);
		return $m->invoke($this->ingestor, $data);
	}

	private function testFlagRecognition() {
		section('the \Draft flag is what decides, whatever the folder');

		check($this->isDraft(new DraftFetchData(array('\\draft'))) === true,
			'Horde hands flags back lowercased with the backslash on');
		check($this->isDraft(new DraftFetchData(array('\\Draft'))) === true,
			'original-case \Draft is the same answer');
		check($this->isDraft(new DraftFetchData(array('draft'))) === true,
			'and so is a bare draft with no backslash');
		check($this->isDraft(new DraftFetchData(array('\\seen', '\\draft'))) === true,
			'a draft that has also been read is still a draft');

		check($this->isDraft(new DraftFetchData(array('\\seen'))) === false,
			'ordinary read mail is not a draft');
		check($this->isDraft(new DraftFetchData(array())) === false,
			'and neither is mail carrying no flags at all');
		check($this->isDraft(new DraftFetchData(array('\\drafted', 'draftbox'))) === false,
			'a flag that merely starts with "draft" is not the \Draft flag');
	}

	private function testFailsTowardIngesting() {
		section('an unreadable answer keeps the message');
		check($this->isDraft(new DraftFetchData(array(), true)) === false,
			'a server that will not answer FLAGS leaves the message ordinary mail');
	}

	private function testFetchAsksForFlags() {
		section('the poll fetch asks for FLAGS, so no second round trip is needed');
		$client = new DraftCapturingImapClient();
		$m = new ReflectionMethod('ImapIngestor', 'fetchWindow');
		$m->setAccessible(true);
		$m->invoke($this->ingestor, $client, 'INBOX', 1, 50);

		check($client->lastQuery instanceof Horde_Imap_Client_Fetch_Query,
			'fetchWindow built a fetch query');
		check($client->lastQuery !== null
			&& $client->lastQuery->contains(Horde_Imap_Client::FETCH_FLAGS),
			'and it asks for FLAGS');
		check($client->lastQuery !== null
			&& $client->lastQuery->contains(Horde_Imap_Client::FETCH_ENVELOPE),
			'without dropping what it already fetched');
	}

	private function testRunRecordReconciles() {
		section('source_draft is a first-class bucket in the run record');
		$summary = MailRunRecord::summarize(array(
			'seen' => 10, 'stored' => 4, 'dedup' => 2, 'out_of_scope' => 0,
			'source_draft' => 4, 'failed' => 0,
		), 'INBOX');
		check($summary['unaccounted'] === 0, 'skipped drafts still balance the books',
			'unaccounted=' . $summary['unaccounted']);
		check($summary['success'] === true, 'a run that only skipped drafts is a success');
		check(strpos($summary['note'], 'source drafts 4') !== false,
			'and the note names the count', $summary['note']);
	}
}

(new ImapDraftIngestTest())->run();
harness_finish();
