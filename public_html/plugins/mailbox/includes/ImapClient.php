<?php
/**
 * ImapClient - the narrow seam over the Horde IMAP client.
 *
 * ImapIngestor and ImapSyncer talk IMAP only through this interface — the handful
 * of operations the ingest + sync cycle needs. The production implementation
 * (HordeImapClient) wraps a logged-in Horde_Imap_Client_Socket and delegates; a
 * test fake implements the same interface so the sync engine is exercised without
 * a live server (specs/two_way_imap_sync.md §6.2, §11).
 *
 * Horde value objects (Horde_Imap_Client_Fetch_Query, _Ids, _Search_Query) are
 * pure builders and pass through as method arguments — the seam fakes the socket
 * I/O, not the query builders.
 *
 * @version 1.0
 */

require_once(PathHelper::getComposerAutoloadPath());

interface ImapClient {

	/** Mailbox status flags bitmask (Horde_Imap_Client::STATUS_*). Returns assoc array. */
	public function status(string $mailbox, int $flags): array;

	/** Fetch the query over the given ids/options. Returns Horde_Imap_Client_Fetch_Results. */
	public function fetch(string $mailbox, Horde_Imap_Client_Fetch_Query $query, array $options = array());

	/** Search a mailbox. Returns Horde's results array. */
	public function search(string $mailbox, $query = null, array $options = array()): array;

	/** STORE (add/remove flags). Returns the affected Horde_Imap_Client_Ids. */
	public function store(string $mailbox, array $options = array());

	/** COPY (or MOVE when $options['move']) ids from $source to $dest. */
	public function copy(string $source, string $dest, array $options = array());

	/** EXPUNGE \Deleted-flagged messages (optionally scoped to ids). */
	public function expunge(string $mailbox, array $options = array());

	/** APPEND a message (array of [data, flags]) to a mailbox. Returns new ids. */
	public function append(string $mailbox, array $data, array $options = array());

	/** QRESYNC VANISHED: UIDs that left $mailbox since $modseq. Returns Horde_Imap_Client_Ids. */
	public function vanished(string $mailbox, int $modseq, array $options = array());

	/** LIST mailboxes (with attributes / special-use). Returns Horde's list array. */
	public function listMailboxes($pattern, int $mode = Horde_Imap_Client::MBOX_ALL, array $options = array()): array;

	/** CREATE a mailbox/folder on the server (used to materialize a locally-created label). */
	public function createMailbox(string $mailbox): void;

	/** Whether the server advertised a capability (e.g. 'QRESYNC', 'X-GM-EXT-1'). */
	public function queryCapability(string $capability): bool;

	/** Close the connection. Idempotent. */
	public function logout(): void;
}

/**
 * Production adapter: delegates to a logged-in Horde_Imap_Client_Socket. The
 * socket is the single place the Horde library is touched (here and inside the
 * builders the callers construct); nothing else references Horde types directly
 * for I/O.
 */
class HordeImapClient implements ImapClient {

	/** @var Horde_Imap_Client_Socket */
	private $socket;

	public function __construct(Horde_Imap_Client_Socket $socket) {
		$this->socket = $socket;
	}

	public function status(string $mailbox, int $flags): array {
		return (array)$this->socket->status($mailbox, $flags);
	}

	public function fetch(string $mailbox, Horde_Imap_Client_Fetch_Query $query, array $options = array()) {
		return $this->socket->fetch($mailbox, $query, $options);
	}

	public function search(string $mailbox, $query = null, array $options = array()): array {
		return (array)$this->socket->search($mailbox, $query, $options);
	}

	public function store(string $mailbox, array $options = array()) {
		return $this->socket->store($mailbox, $options);
	}

	public function copy(string $source, string $dest, array $options = array()) {
		return $this->socket->copy($source, $dest, $options);
	}

	public function expunge(string $mailbox, array $options = array()) {
		return $this->socket->expunge($mailbox, $options);
	}

	public function append(string $mailbox, array $data, array $options = array()) {
		return $this->socket->append($mailbox, $data, $options);
	}

	public function vanished(string $mailbox, int $modseq, array $options = array()) {
		return $this->socket->vanished($mailbox, $modseq, $options);
	}

	public function listMailboxes($pattern, int $mode = Horde_Imap_Client::MBOX_ALL, array $options = array()): array {
		return (array)$this->socket->listMailboxes($pattern, $mode, $options);
	}

	public function createMailbox(string $mailbox): void {
		$this->socket->createMailbox($mailbox);
	}

	public function queryCapability(string $capability): bool {
		try {
			return (bool)$this->socket->capability->query($capability);
		} catch (Throwable $e) {
			return false;
		}
	}

	public function logout(): void {
		try { $this->socket->logout(); } catch (Throwable $e) { /* ignore */ }
	}
}
?>
