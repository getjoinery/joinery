<?php
/** @joinery-test
 * name: imap_sent_direction
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A message the user sent reads as sent mail, whichever folder stored it first.
 *
 * The defect this pins: Gmail's \All coverage folder sorts before Sent Mail in
 * discovery, so on a sync-enabled Gmail feed the All Mail pass always stored a
 * sent message first — as an ordinary inbound row. The Sent pass then hit the
 * §9 Message-ID dedup, adopted the locator, and returned without ever touching
 * iem_direction, so your own sent mail sat in the Inbox as an incoming message
 * from yourself, permanently.
 *
 * What is asserted, driving the real ingestOneStored (no live IMAP — the
 * network half of ingestOne runs before the stored half and is not involved):
 *
 *  - All Mail stores a sent message as inbound; the Sent pass's dedup promotes
 *    that same row to outbound.
 *  - A self-addressed send is NOT promoted — it belongs in the Inbox, exactly
 *    as the source mailbox shows it.
 *  - A message first seen in the Sent folder stores as outbound directly.
 *  - The promotion never demotes: an already-outbound row stays outbound.
 *  - The promotion stands down instead of colliding when a live outbound
 *    sibling already holds the (Message-ID, recipient, direction) dedup key.
 *
 * Run: php tests/run.php db --filter=imap_sent_direction
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folder_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));

/** The slice of a Horde envelope ingestOneStored actually reads. */
class SentDirectionEnvelope {
	public $from;
	public $to = array();
	public $cc = array();
	public $subject = 'A sent message';
	public $message_id;
	public $date = null;
	function __construct(string $from, array $to, string $message_id) {
		$this->from = $from;
		$this->to = $to;
		$this->message_id = $message_id;
	}
}

/** The slice of a Horde fetch result ingestOneStored actually reads. */
class SentDirectionFetchData {
	private $envelope;
	function __construct(SentDirectionEnvelope $envelope) { $this->envelope = $envelope; }
	function getEnvelope() { return $this->envelope; }
	function getSize() { return 2048; }
}

class ImapSentDirectionTest {

	private $db;
	private $suffix;
	private $domain_id;
	private $alias;
	private $account;
	private $folder_all;
	private $folder_sent;
	private $router;
	private $ingestor;
	private $uid = 500;

	const SELF_ADDRESS = 'me@sentdir-source.example';

	function __construct() { $this->db = DbConnector::get_instance()->get_db_link(); }

	function run() {
		section('Sent-folder direction promotion');
		$this->setUp();
		$this->testAllMailFirstThenSentPromotes();
		$this->testSelfSendStaysInbound();
		$this->testFreshSentStoresOutbound();
		$this->testPromotionNeverDemotes();
		$this->testPromotionStandsDownOnLiveOutboundSibling();
		$this->tearDown();
	}

	// ------------------------------------------------------------------ setup

	private function setUp() {
		$this->preClean();
		$this->suffix = substr(md5(uniqid('sdir', true)), 0, 8);

		$domain = new InboundEmailDomain(NULL);
		$domain->set('ied_domain', 'sentdir-test-' . $this->suffix . '.example');
		$domain->set('ied_is_enabled', true);
		$domain->set('ied_is_imap_source', true);
		$domain->save();
		$this->domain_id = intval($domain->key);

		$alias = new InboundEmailAlias(NULL);
		$alias->set('iea_ied_inbound_email_domain_id', $this->domain_id);
		$alias->set('iea_alias', 'in' . $this->suffix);
		$alias->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$alias->set('iea_is_enabled', true);
		$alias->prepare();
		$alias->save();
		$this->alias = $alias;

		$acc = new InboundImapAccount(NULL);
		$acc->set('iia_label', 'SentDir ' . $this->suffix);
		$acc->set('iia_provider_key', 'imap_generic');
		$acc->set('iia_imap_host', 'imap.test');
		$acc->set('iia_iea_inbound_email_alias_id', $this->alias->key);
		$acc->set('iia_username', self::SELF_ADDRESS);
		$acc->set('iia_is_enabled', true);
		$acc->prepare();
		$acc->save();
		$this->account = new InboundImapAccount($acc->key, TRUE);

		$this->folder_all = InboundImapFolder::upsert(intval($this->account->key),
			'[Gmail]/All Mail', InboundImapFolder::ROLE_ALL, true);
		$this->folder_sent = InboundImapFolder::upsert(intval($this->account->key),
			'[Gmail]/Sent Mail', InboundImapFolder::ROLE_SENT, true);

		$this->router = new InboundEmailRouter();
		$this->ingestor = new ImapIngestor($this->account);
	}

	private function preClean() {
		try {
			$dids = $this->db->query("SELECT ied_inbound_email_domain_id FROM ied_inbound_email_domains
				WHERE ied_domain LIKE 'sentdir-test-%'")->fetchAll(PDO::FETCH_COLUMN);
			if (!$dids) { return; }
			$in = implode(',', array_map('intval', $dids));
			$aids = $this->db->query("SELECT iia_inbound_imap_account_id FROM iia_inbound_imap_accounts
				WHERE iia_iea_inbound_email_alias_id IN (SELECT iea_inbound_email_alias_id
					FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id IN ($in))")
				->fetchAll(PDO::FETCH_COLUMN);
			if ($aids) {
				$ain = implode(',', array_map('intval', $aids));
				$this->db->exec("DELETE FROM iif_inbound_imap_folders WHERE iif_iia_inbound_imap_account_id IN ($ain)");
				$this->db->exec("DELETE FROM iia_inbound_imap_accounts WHERE iia_inbound_imap_account_id IN ($ain)");
			}
			$this->db->exec("DELETE FROM iem_inbound_email_messages WHERE iem_ied_inbound_email_domain_id IN ($in)");
			$this->db->exec("DELETE FROM iea_inbound_email_aliases WHERE iea_ied_inbound_email_domain_id IN ($in)");
			$this->db->exec("DELETE FROM ied_inbound_email_domains WHERE ied_inbound_email_domain_id IN ($in)");
		} catch (\Throwable $e) {}
	}

	private function tearDown() { $this->preClean(); }

	private function recipient(): string {
		return strtolower($this->alias->get_full_address());
	}

	/** Run the real stored half of ingestOne for one message through one folder. */
	private function ingest(InboundImapFolder $folder, SentDirectionEnvelope $envelope): array {
		$m = new ReflectionMethod(ImapIngestor::class, 'ingestOneStored');
		$m->setAccessible(true);
		return $m->invoke($this->ingestor, $folder, $this->uid++,
			new SentDirectionFetchData($envelope), $this->router,
			$this->alias, new InboundEmailDomain($this->domain_id, TRUE), $this->recipient(),
			7, false, array(), 'body text', '', array());
	}

	private function direction(int $messageId): string {
		$stmt = $this->db->prepare('SELECT iem_direction FROM iem_inbound_email_messages
			WHERE iem_inbound_email_message_id = ?');
		$stmt->execute(array($messageId));
		return (string)$stmt->fetchColumn();
	}

	private function mid(string $tag): string {
		return '<' . $tag . '-' . $this->suffix . '@sentdir.example>';
	}

	// ------------------------------------------------------------------ cases

	private function testAllMailFirstThenSentPromotes() {
		$env = new SentDirectionEnvelope(self::SELF_ADDRESS, array('friend@example.org'), $this->mid('race'));

		$first = $this->ingest($this->folder_all, $env);
		check(!$first['dedup'] && $first['message_id'] > 0, 'All Mail pass stores the sent message fresh');
		check($this->direction($first['message_id']) === 'inbound',
			'the coverage pass alone stores it as inbound (it knows nothing else)');

		$second = $this->ingest($this->folder_sent, $env);
		check($second['dedup'] && $second['message_id'] === $first['message_id'],
			'the Sent pass dedups to the same row');
		check($this->direction($first['message_id']) === 'outbound',
			'the Sent pass promotes the row to outbound — sent mail no longer reads as incoming');
	}

	private function testSelfSendStaysInbound() {
		$env = new SentDirectionEnvelope(self::SELF_ADDRESS, array(self::SELF_ADDRESS), $this->mid('self'));

		$first = $this->ingest($this->folder_all, $env);
		$this->ingest($this->folder_sent, $env);
		check($this->direction($first['message_id']) === 'inbound',
			'a self-addressed send stays inbound — it belongs in the Inbox');
	}

	private function testFreshSentStoresOutbound() {
		$env = new SentDirectionEnvelope(self::SELF_ADDRESS, array('friend@example.org'), $this->mid('fresh'));

		$res = $this->ingest($this->folder_sent, $env);
		check(!$res['dedup'] && $this->direction($res['message_id']) === 'outbound',
			'a message first seen in Sent stores as outbound directly');
	}

	private function testPromotionNeverDemotes() {
		$env = new SentDirectionEnvelope(self::SELF_ADDRESS, array('friend@example.org'), $this->mid('fresh'));

		$res = $this->ingest($this->folder_sent, $env);
		check($res['dedup'] && $this->direction($res['message_id']) === 'outbound',
			're-seeing an outbound row in Sent leaves it outbound');
	}

	private function testPromotionStandsDownOnLiveOutboundSibling() {
		// A live outbound row already holds the dedup key (the local compose row
		// shape); a coverage-stored inbound copy of the same message must not be
		// promoted into a unique-index collision mid-ingest.
		$env = new SentDirectionEnvelope(self::SELF_ADDRESS, array('friend@example.org'), $this->mid('sibling'));
		$inbound = $this->ingest($this->folder_all, $env);
		check($this->direction($inbound['message_id']) === 'inbound', 'coverage copy stored inbound');

		$stmt = $this->db->prepare("INSERT INTO iem_inbound_email_messages
			(iem_ied_inbound_email_domain_id, iem_iea_inbound_email_alias_id, iem_sender, iem_recipient,
			 iem_subject, iem_message_id_header, iem_direction, iem_received_time, iem_create_time)
			VALUES (?, ?, ?, ?, 'sibling', ?, 'outbound', now(), now())");
		$stmt->execute(array($this->domain_id, intval($this->alias->key), self::SELF_ADDRESS,
			$this->recipient(), $this->mid('sibling')));

		$m = new ReflectionMethod(ImapIngestor::class, 'markDirectionOutbound');
		$m->setAccessible(true);
		$m->invoke($this->ingestor, intval($inbound['message_id']));

		check($this->direction($inbound['message_id']) === 'inbound',
			'the promotion stands down rather than colliding with the live outbound sibling');
	}
}

$test = new ImapSentDirectionTest();
$test->run();
harness_finish();
