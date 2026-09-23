<?php
/** @joinery-test
 * name: imap_hardening
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 * timeout: 300
 */
/**
 * The IMAP client hardening fixes (specs/implemented/imap_client_hardening.md), each pinned
 * against a fake server that behaves the way real ones were shown to.
 *
 * The fake sits behind the ImapClient seam and answers with real
 * Horde_Imap_Client_Data_Fetch objects built from raw RFC 822 messages, so the
 * real ingest and sync code — structure walk, envelope, body decode, dedup,
 * locators, pushes — runs exactly as it does against a live server. Each
 * section reproduces one finding's failure scenario and asserts the fixed
 * behavior; before the fixes every one of them failed.
 *
 * Runs in the test database (harness_test_mode): the fixtures are a domain,
 * mailbox and feed per scenario, all torn down through the domain.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_aliases_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_accounts_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_folders_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_ingest_failures_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_label_members_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapSyncer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapFetch.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/PollImapAccounts.php'));

/** Fetch data whose envelope cannot be read — a message the client cannot parse. */
class HardeningBrokenFetch extends Horde_Imap_Client_Data_Fetch {
	public function getEnvelope() { throw new RuntimeException('simulated unparsable message'); }
}

/**
 * In-memory IMAP server over raw messages. Folders: name => ['uidvalidity',
 * 'uidnext' (optional override), 'omit' (status keys left out), 'messages' =>
 * uid => ['raw','flags','date','mode']]. A message 'mode' makes it misbehave:
 * 'broken' (unparsable envelope), 'nobody' (body parts come back empty-handed),
 * 'omit' (listed nowhere in fetches). Writes are applied and recorded in $ops.
 */
class HardeningImapServer implements ImapClient {
	public $folders = array();
	public $caps = array('CONDSTORE' => true, 'QRESYNC' => false, 'X-GM-EXT-1' => false);
	public $ops = array();
	public $vanishedByFolder = array();
	/** uid => true: a structure fetch whose range covers this uid fails to parse. */
	public $batchPoison = array();
	/** folder => true: every write touching it fails (the folder is gone). */
	public $goneFolders = array();
	/** folder => true: a UID-only listing comes back empty (a short answer). */
	public $shortUidList = array();
	/** folder => true: the connection drops on any fetch there. */
	public $disconnectOn = array();
	public $createFails = array();

	function addFolder($name, $uidvalidity = 1) {
		$this->folders[$name] = array('uidvalidity' => $uidvalidity, 'messages' => array(), 'omit' => array());
	}
	function addMessage($folder, $uid, $raw, array $opts = array()) {
		$this->folders[$folder]['messages'][$uid] = array('raw' => $raw,
			'flags' => $opts['flags'] ?? array(), 'date' => $opts['date'] ?? '2026-09-01 10:00:00',
			'mode' => $opts['mode'] ?? null);
	}

	public function status(string $mailbox, int $flags): array {
		$f = $this->folders[$mailbox] ?? array('messages' => array(), 'uidvalidity' => 1, 'omit' => array());
		$max = count($f['messages']) ? max(array_keys($f['messages'])) : 0;
		$out = array('uidvalidity' => $f['uidvalidity'], 'uidnext' => $f['uidnext'] ?? $max + 1,
			'highestmodseq' => $f['highestmodseq'] ?? 10, 'messages' => count($f['messages']));
		foreach ($f['omit'] ?? array() as $k) { unset($out[$k]); }
		return $out;
	}

	private function ranges($ids): ?array {
		if ($ids instanceof Horde_Imap_Client_Ids && $ids->sequence) {
			return array('seq' => array_map('intval', iterator_to_array($ids, false)));
		}
		$s = (string)$ids;
		if ($s === '') { return null; }
		$out = array();
		foreach (explode(',', $s) as $piece) {
			$ab = explode(':', $piece, 2);
			$out[] = array(intval($ab[0]), intval($ab[1] ?? $ab[0]));
		}
		return $out;
	}
	private function covers(int $uid, int $seq, ?array $r): bool {
		if ($r === null) { return true; }
		if (isset($r['seq'])) { return in_array($seq, $r['seq'], true); }
		foreach ($r as $p) { if ($uid >= min($p) && $uid <= max($p)) { return true; } }
		return false;
	}

	public function fetch(string $mailbox, Horde_Imap_Client_Fetch_Query $query, array $options = array()) {
		$ranges = $this->ranges($options['ids'] ?? '');
		$this->ops[] = array('op' => 'fetch', 'mailbox' => $mailbox, 'ids' => (string)($options['ids'] ?? ''));
		if (!empty($this->disconnectOn[$mailbox])) {
			throw new Horde_Imap_Client_Exception('Mail server closed the connection unexpectedly.',
				Horde_Imap_Client_Exception::DISCONNECT);
		}
		$msgs = $this->folders[$mailbox]['messages'] ?? array();
		ksort($msgs);
		if ($query->contains(Horde_Imap_Client::FETCH_STRUCTURE)) {
			$seq = 0;
			foreach ($msgs as $uid => $m) {
				$seq++;
				if (isset($this->batchPoison[$uid]) && $this->covers($uid, $seq, $ranges)) {
					throw new Horde_Imap_Client_Exception('Server response could not be parsed');
				}
			}
		}
		$uidOnly = !$query->contains(Horde_Imap_Client::FETCH_STRUCTURE)
			&& !$query->contains(Horde_Imap_Client::FETCH_BODYPART)
			&& !$query->contains(Horde_Imap_Client::FETCH_FLAGS)
			&& !$query->contains(Horde_Imap_Client::FETCH_IMAPDATE);
		if ($uidOnly && !empty($this->shortUidList[$mailbox])) {
			return new Horde_Imap_Client_Fetch_Results();
		}
		$res = new Horde_Imap_Client_Fetch_Results();
		$seq = 0;
		foreach ($msgs as $uid => $m) {
			$seq++;
			if (!$this->covers($uid, $seq, $ranges) || $m['mode'] === 'omit') { continue; }
			$d = ($m['mode'] === 'broken') ? new HardeningBrokenFetch() : new Horde_Imap_Client_Data_Fetch();
			$d->setUid($uid);
			$part = Horde_Mime_Part::parseMessage($m['raw']);
			$hdr = preg_split("/\r?\n\r?\n/", $m['raw'], 2)[0];
			if ($query->contains(Horde_Imap_Client::FETCH_STRUCTURE)) { $d->setStructure($part); }
			if ($query->contains(Horde_Imap_Client::FETCH_ENVELOPE)) {
				$h = Horde_Mime_Headers::parseHeaders($hdr);
				$env = array();
				foreach (array('subject' => 'subject', 'from' => 'from', 'to' => 'to', 'cc' => 'cc',
						'message-id' => 'message_id', 'date' => 'date') as $hk => $ek) {
					$val = $h->getValue($hk);
					if ($val !== null && $val !== '') { $env[$ek] = $val; }
				}
				if (preg_match('/^Subject: (.*)$/mi', $hdr, $sm)) { $env['subject'] = rtrim($sm[1], "\r"); }
				$d->setEnvelope($env);
			}
			if ($query->contains(Horde_Imap_Client::FETCH_HEADERTEXT)) { $d->setHeaderText(0, $hdr . "\r\n\r\n"); }
			if ($query->contains(Horde_Imap_Client::FETCH_SIZE)) { $d->setSize(strlen($m['raw'])); }
			if ($query->contains(Horde_Imap_Client::FETCH_IMAPDATE)) { $d->setImapDate($m['date'] . ' +0000'); }
			if ($query->contains(Horde_Imap_Client::FETCH_FLAGS)) { $d->setFlags($m['flags']); }
			if ($query->contains(Horde_Imap_Client::FETCH_BODYPART) && $m['mode'] !== 'nobody') {
				foreach ($query[Horde_Imap_Client::FETCH_BODYPART] as $pid => $opts) {
					$p = $part->getPart($pid);
					if ($p === null) { continue; }
					$body = (string)$p->getContents();
					if (isset($opts['length'])) {
						$body = substr($body, intval($opts['start'] ?? 0), intval($opts['length']));
					}
					$d->setBodyPart($pid, $body, '8bit');
				}
			}
			$res[$uid] = $d;
		}
		return $res;
	}

	/** Header searches (Message-ID) answered from the raw headers; anything else matches nothing. */
	public function search(string $mailbox, $query = null, array $options = array()): array {
		$want = null;
		if ($query instanceof Horde_Imap_Client_Search_Query) {
			$prop = new ReflectionProperty('Horde_Imap_Client_Search_Query', '_search');
			$prop->setAccessible(true);
			foreach ((array)($prop->getValue($query)['header'] ?? array()) as $h) {
				if (strtoupper($h['header']) === 'MESSAGE-ID') { $want = $h['text']; }
			}
		}
		$match = array();
		if ($want !== null) {
			foreach (($this->folders[$mailbox]['messages'] ?? array()) as $uid => $m) {
				if (stripos($m['raw'], 'Message-ID: ' . $want) !== false) { $match[] = $uid; }
			}
		}
		return array('match' => new Horde_Imap_Client_Ids($match), 'count' => count($match));
	}

	public function store(string $mailbox, array $options = array()) {
		if (!empty($this->goneFolders[$mailbox])) {
			throw new Horde_Imap_Client_Exception('Mailbox does not exist', Horde_Imap_Client_Exception::NONEXISTENT);
		}
		$uids = array_map('intval', iterator_to_array($options['ids'], false));
		$this->ops[] = array('op' => 'store', 'mailbox' => $mailbox, 'ids' => $uids);
		return $options['ids'];
	}
	public function copy(string $source, string $dest, array $options = array()) {
		if (!empty($this->goneFolders[$source])) {
			throw new Horde_Imap_Client_Exception('Mailbox does not exist', Horde_Imap_Client_Exception::NONEXISTENT);
		}
		$uids = array_map('intval', iterator_to_array($options['ids'], false));
		$this->ops[] = array('op' => !empty($options['move']) ? 'move' : 'copy', 'mailbox' => $source,
			'dest' => $dest, 'ids' => $uids);
		$map = array();
		foreach ($uids as $u) {
			$m = $this->folders[$source]['messages'][$u] ?? null;
			if ($m === null) { continue; }
			$n = (count($this->folders[$dest]['messages']) ? max(array_keys($this->folders[$dest]['messages'])) : 0) + 1;
			$this->folders[$dest]['messages'][$n] = $m;
			$map[$u] = $n;
			if (!empty($options['move'])) { unset($this->folders[$source]['messages'][$u]); }
		}
		return $map; // Horde's COPYUID map
	}
	public function expunge(string $mailbox, array $options = array()) {
		$uids = array_map('intval', iterator_to_array($options['ids'], false));
		$this->ops[] = array('op' => 'expunge', 'mailbox' => $mailbox, 'ids' => $uids);
		foreach ($uids as $u) { unset($this->folders[$mailbox]['messages'][$u]); }
		return $options['ids'];
	}
	public function append(string $mailbox, array $data, array $options = array()) { return new Horde_Imap_Client_Ids(array()); }
	public function vanished(string $mailbox, int $modseq, array $options = array()) {
		return new Horde_Imap_Client_Ids($this->vanishedByFolder[$mailbox] ?? array());
	}
	/** LIST with real pattern semantics: '*' and '%' are wildcards. */
	public function listMailboxes($pattern, int $mode = 0, array $options = array()): array {
		$re = '/^' . str_replace(array('\*', '%'), array('.*', '[^\/]*'), preg_quote((string)$pattern, '/')) . '$/';
		$out = array();
		foreach ($this->folders as $name => $f) {
			if (preg_match($re, $name)) { $out[$name] = array('mailbox' => $name, 'attributes' => array()); }
		}
		return $out;
	}
	public function createMailbox(string $mailbox): void {
		$this->ops[] = array('op' => 'create', 'mailbox' => $mailbox);
		if (!empty($this->createFails[$mailbox])) {
			throw new Horde_Imap_Client_Exception('Invalid mailbox name');
		}
		$this->addFolder($mailbox);
	}
	public function queryCapability(string $capability): bool { return !empty($this->caps[$capability]); }
	public function logout(): void {}

	function opsOf(string $type, ?string $mailbox = null): array {
		return array_values(array_filter($this->ops, function ($o) use ($type, $mailbox) {
			return $o['op'] === $type && ($mailbox === null || $o['mailbox'] === $mailbox);
		}));
	}
	function subjectAt(string $folder, int $uid): ?string {
		$raw = $this->folders[$folder]['messages'][$uid]['raw'] ?? null;
		return ($raw !== null && preg_match('/^Subject: (.*)$/m', $raw, $x)) ? trim($x[1]) : null;
	}
}

class ImapHardeningTest {

	private function db() { return DbConnector::get_instance()->get_db_link(); }

	private function raw(string $subject, ?string $mid, string $body = 'hello', array $extra = array()): string {
		$h = "From: Sender <sender@x.test>\r\nTo: harnesstest_me@hardening.test\r\nSubject: $subject\r\n";
		if ($mid !== null) { $h .= "Message-ID: $mid\r\n"; }
		if (!array_key_exists('Date', $extra)) { $h .= "Date: Tue, 01 Sep 2026 10:00:00 +0000\r\n"; }
		foreach ($extra as $k => $v) { if ($v !== null) { $h .= "$k: $v\r\n"; } }
		return $h . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . $body;
	}

	/** Domain + mailbox + feed; registered so teardown takes all of it. */
	private function fixture(array $acct = array()): array {
		$sfx = substr(md5(uniqid('hard', true)), 0, 8);
		$d = new InboundEmailDomain(NULL);
		$d->set('ied_domain', 'harnesstest-' . $sfx . '.example');
		$d->set('ied_is_enabled', true);
		$d->set('ied_is_imap_source', true);
		$d->save();
		harness_register_model('InboundEmailDomain', intval($d->key));
		$a = new InboundEmailAlias(NULL);
		$a->set('iea_ied_inbound_email_domain_id', $d->key);
		$a->set('iea_alias', 'harnesstest_in' . $sfx);
		$a->set('iea_delivery_mode', InboundEmailAlias::MODE_STORE);
		$a->set('iea_is_enabled', true);
		$a->prepare(); $a->save();
		$acc = new InboundImapAccount(NULL);
		$acc->set('iia_label', 'Hardening ' . $sfx);
		$acc->set('iia_provider_key', 'imap_generic');
		$acc->set('iia_imap_host', 'imap.test');
		$acc->set('iia_iea_inbound_email_alias_id', $a->key);
		$acc->set('iia_username', 'harnesstest_me@hardening.test');
		$acc->set('iia_is_enabled', true);
		foreach ($acct as $k => $v) { $acc->set($k, $v); }
		$acc->prepare(); $acc->save();
		return array('alias' => intval($a->key), 'account' => new InboundImapAccount($acc->key, TRUE));
	}

	private function syncFixture(array $extra = array()): array {
		return $this->fixture($extra + array('iia_import_scope' => 'full', 'iia_sync_mode' => 'both',
			'iia_supports_condstore' => true, 'iia_folders_exclusive' => false, 'iia_sync_deletes' => true));
	}

	private function poll($acc, $srv, int $max = 50): ?array {
		$ing = new ImapIngestor(new InboundImapAccount($acc->key, TRUE), $srv);
		try { return $ing->poll($max); } catch (Throwable $e) { return array('error' => $e->getMessage()); } finally { $ing->close(); }
	}

	private function cycle($fx, $srv, int $n = 1, int $max = 50): void {
		for ($i = 0; $i < $n; $i++) {
			try { ImapFetch::run(new InboundImapAccount($fx['account']->key, TRUE), $max, null, $srv); }
			catch (Throwable $e) { /* the assertions say what matters */ }
		}
	}

	private function rows(int $alias): array {
		$s = $this->db()->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ? ORDER BY iem_inbound_email_message_id');
		$s->execute(array($alias));
		return $s->fetchAll(PDO::FETCH_ASSOC);
	}

	private function row(int $alias, string $mid): ?array {
		$s = $this->db()->prepare('SELECT * FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ? AND iem_message_id_header = ?');
		$s->execute(array($alias, $mid));
		$r = $s->fetch(PDO::FETCH_ASSOC);
		return $r ?: null;
	}

	private function folder($acc, string $name): ?InboundImapFolder {
		$s = $this->db()->prepare('SELECT iif_inbound_imap_folder_id FROM iif_inbound_imap_folders WHERE iif_iia_inbound_imap_account_id = ? AND iif_name = ?');
		$s->execute(array(intval($acc->key), $name));
		$id = $s->fetchColumn();
		return $id ? new InboundImapFolder(intval($id), TRUE) : null;
	}

	private function track($acc, string $name, ?string $role = null) {
		InboundImapFolder::upsert(intval($acc->key), $name, $role, true);
	}

	private function exec(string $sql, array $p) { $this->db()->prepare($sql)->execute($p); }

	function run() {
		$this->testCertificateVerification();
		$this->testNoPlaintextMode();
		$this->testRenumberedFolderNeverHitsStrangers();
		$this->testOneBadMessageDoesNotBlockTheFolder();
		$this->testUnparsableMessageIsIsolatedFromItsBatch();
		$this->testConnectionDropIsNotCountedAgainstAMessage();
		$this->testMissingBodyIsNotStoredEmpty();
		$this->testByteCutsStayValidUtf8();
		$this->testNoMessageIdIsStoredOnce();
		$this->testMissingDateFallsBackToInternalDate();
		$this->testMissingStatusValues();
		$this->testUidvalidityChurnPausesTheFolder();
		$this->testShortUidAnswerDropsNoLabels();
		$this->testStuckPushesDoNotStarveNewOnes();
		$this->testMoveCarriesDestinationGeneration();
		$this->testBodyFetchIsBounded();
		$this->testFailingFeedBacksOff();
		$this->testMovedMessagesAreFollowedOrMarkedGone();
		$this->testNamespacedRoleNames();
		$this->testInternalHostsRefused();
		$this->testWildcardFolderNameIsNotMistakenForAnother();
	}

	// ── F1 / Q1 ─────────────────────────────────────────────────────────────

	private function testCertificateVerification() {
		section('F1: the server certificate is verified against the host name');
		$m = new ReflectionMethod('ImapIngestor', 'connectionParams');
		$m->setAccessible(true);
		$p = $m->invoke(null, array('host' => 'imap.example.com', 'connect' => '203.0.113.9'), 'me@example.com', 'ssl', 993);
		$ssl = $p['context']['ssl'] ?? array();
		check(($ssl['verify_peer'] ?? null) === true, 'the peer certificate is verified');
		check(($ssl['verify_peer_name'] ?? null) === true, 'and its name is checked');
		check(($ssl['peer_name'] ?? null) === 'imap.example.com', 'against the host the operator entered, not the pinned address');
		check(($ssl['allow_self_signed'] ?? null) === false, 'a self-signed certificate is refused');
		check($p['hostspec'] === '203.0.113.9', 'the connection goes to the checked address');
		$p = $m->invoke(null, array('host' => 'h', 'connect' => '203.0.113.9'), 'u', 'none', 143);
		check($p['secure'] === 'ssl', 'an old "none" setting still connects encrypted');
		$p = $m->invoke(null, array('host' => 'h', 'connect' => '203.0.113.9'), 'u', 'tls', 143);
		check($p['secure'] === 'tls', 'STARTTLS stays STARTTLS');
	}

	private function testNoPlaintextMode() {
		section('Q1: there is no unencrypted connection mode');
		$acc = new InboundImapAccount(NULL);
		$acc->set('iia_provider_key', 'imap_generic');
		$acc->set('iia_imap_encryption', 'none');
		$refused = false;
		try { $acc->prepare(); } catch (InboundImapAccountException $e) { $refused = true; }
		check($refused, 'a feed cannot be saved with encryption "none"');
		check(!defined('InboundImapAccount::ENC_NONE'), 'and the mode no longer exists');
	}

	// ── F2 ──────────────────────────────────────────────────────────────────

	private function testRenumberedFolderNeverHitsStrangers() {
		section('F2: after a folder is renumbered, sync never writes to the message that now holds an old number');
		$fx = $this->syncFixture(); $al = $fx['alias'];
		$srv = new HardeningImapServer();
		$srv->addFolder('INBOX', 100); $srv->addFolder('Trash', 50); $srv->addFolder('Work', 300);
		$srv->addMessage('INBOX', 1, $this->raw('A', '<a@x.test>'));
		$srv->addMessage('INBOX', 2, $this->raw('B', '<b@x.test>'));
		$srv->addMessage('Work', 1, $this->raw('A', '<a@x.test>'));
		$this->track($fx['account'], 'INBOX', 'inbox'); $this->track($fx['account'], 'Trash', 'trash'); $this->track($fx['account'], 'Work');
		$this->cycle($fx, $srv, 2);
		$A = $this->row($al, '<a@x.test>'); $B = $this->row($al, '<b@x.test>');
		check($A && $B, 'A and B imported');

		// The server renumbers INBOX and Work: UIDs 1 and 2 now belong to strangers.
		$this->exec("UPDATE iia_inbound_imap_accounts SET iia_import_scope = 'future' WHERE iia_inbound_imap_account_id = ?", array($fx['account']->key));
		$srv->folders['INBOX'] = array('uidvalidity' => 200, 'omit' => array(), 'messages' => array());
		$srv->addMessage('INBOX', 1, $this->raw('C stranger', '<c@x.test>'));
		$srv->addMessage('INBOX', 2, $this->raw('D stranger', '<d@x.test>'));
		$srv->addMessage('INBOX', 7, $this->raw('A', '<a@x.test>'));
		$srv->addMessage('INBOX', 8, $this->raw('B', '<b@x.test>'));
		$srv->folders['Work'] = array('uidvalidity' => 301, 'omit' => array(), 'messages' => array());
		$srv->addMessage('Work', 9, $this->raw('A', '<a@x.test>'));
		// The member reads A and deletes B here.
		$this->exec("UPDATE iem_inbound_email_messages SET iem_is_read = true, iem_local_state_modified = now() + interval '1 second' WHERE iem_inbound_email_message_id = ?", array($A['iem_inbound_email_message_id']));
		$this->exec("UPDATE iem_inbound_email_messages SET iem_delete_time = now() WHERE iem_inbound_email_message_id = ?", array($B['iem_inbound_email_message_id']));
		$srv->ops = array();
		$this->cycle($fx, $srv, 3);

		$strangers = function ($o) { return in_array(1, $o['ids'], true) || in_array(2, $o['ids'], true); };
		check(!count(array_filter($srv->opsOf('store', 'INBOX'), $strangers)), 'no flag write reached stranger UIDs 1 or 2');
		check(!count(array_filter(array_merge($srv->opsOf('copy', 'INBOX'), $srv->opsOf('move', 'INBOX')), $strangers)),
			'no trash move reached stranger UIDs 1 or 2');
		check(count(array_filter($srv->opsOf('store', 'INBOX'), function ($o) { return in_array(7, $o['ids'], true); })) > 0,
			'the read flag went to A at its new UID 7');
		$trashSubjects = array();
		foreach (array_keys($srv->folders['Trash']['messages']) as $u) { $trashSubjects[] = $srv->subjectAt('Trash', $u); }
		check(in_array('B', $trashSubjects, true) && !in_array('D stranger', $trashSubjects, true),
			'Trash holds B, not the stranger', json_encode($trashSubjects));
		$s = $this->db()->prepare('SELECT ilm_imap_uid, ilm_imap_uidvalidity FROM ilm_inbound_label_members WHERE ilm_iem_inbound_email_message_id = ?');
		$s->execute(array($A['iem_inbound_email_message_id']));
		$m = $s->fetch(PDO::FETCH_ASSOC);
		check($m !== false, "A keeps its Work label through the renumbering");
		check($m && intval($m['ilm_imap_uid']) === 9 && intval($m['ilm_imap_uidvalidity']) === 301,
			'and the label is re-found at its new UID in the new generation', json_encode($m));
	}

	// ── F3 ──────────────────────────────────────────────────────────────────

	private function testOneBadMessageDoesNotBlockTheFolder() {
		section('F3: one message that always fails is skipped after three tries; the folder carries on');
		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 7);
		for ($i = 1; $i <= 300; $i++) {
			$srv->addMessage('INBOX', $i, $this->raw("M$i", "<f3-$i@x.test>"), $i === 5 ? array('mode' => 'broken') : array());
		}
		for ($p = 0; $p < 9; $p++) { $this->poll($fx['account'], $srv, 50); }
		check(count($this->rows($fx['alias'])) === 299, 'all 299 good messages imported', count($this->rows($fx['alias'])));
		$folder = $this->folder($fx['account'], 'INBOX');
		check(intval($folder->get('iif_last_seen_uid')) === 300, 'the cursor reached the end of the folder');
		check(InboundImapIngestFailure::skippedCountForAccount(intval($fx['account']->key)) === 1, 'the bad message is recorded as skipped');

		// The operator's Retry once the message is readable again.
		$srv->folders['INBOX']['messages'][5]['mode'] = null;
		$ing = new ImapIngestor(new InboundImapAccount($fx['account']->key, TRUE), $srv);
		$res = $ing->retrySkipped();
		$ing->close();
		check($res['imported'] === 1, 'Retry imports it');
		check(count($this->rows($fx['alias'])) === 300, 'all 300 are here');
		check(InboundImapIngestFailure::skippedCountForAccount(intval($fx['account']->key)) === 0, 'and nothing is left skipped');
	}

	private function testUnparsableMessageIsIsolatedFromItsBatch() {
		section('F3: a message the client cannot parse fails alone, not with its whole batch');
		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 7);
		for ($i = 1; $i <= 20; $i++) { $srv->addMessage('INBOX', $i, $this->raw("B$i", "<f3b-$i@x.test>")); }
		$srv->batchPoison[5] = true;
		$this->poll($fx['account'], $srv);
		check(count($this->rows($fx['alias'])) === 19, 'the other 19 import on the first poll', count($this->rows($fx['alias'])));
		for ($p = 0; $p < 3; $p++) { $this->poll($fx['account'], $srv); }
		check(intval($this->folder($fx['account'], 'INBOX')->get('iif_last_seen_uid')) === 20,
			'after three tries the walk moves past it');
	}

	private function testConnectionDropIsNotCountedAgainstAMessage() {
		section('F3: a dropped connection is not the message\'s fault');
		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 7);
		for ($i = 1; $i <= 3; $i++) { $srv->addMessage('INBOX', $i, $this->raw("D$i", "<f3c-$i@x.test>")); }
		$srv->disconnectOn['INBOX'] = true;
		for ($p = 0; $p < 4; $p++) { $this->poll($fx['account'], $srv); }
		$s = $this->db()->prepare('SELECT count(*) FROM ifl_inbound_imap_ingest_failures f JOIN iif_inbound_imap_folders d ON d.iif_inbound_imap_folder_id = f.ifl_iif_inbound_imap_folder_id WHERE d.iif_iia_inbound_imap_account_id = ?');
		$s->execute(array($fx['account']->key));
		check(intval($s->fetchColumn()) === 0, 'four failed polls count nothing against any message');
		unset($srv->disconnectOn['INBOX']);
		$this->poll($fx['account'], $srv);
		check(count($this->rows($fx['alias'])) === 3, 'and all three import once the connection holds');
	}

	// ── F4 ──────────────────────────────────────────────────────────────────

	private function testMissingBodyIsNotStoredEmpty() {
		section('F4: a body the server did not return is retried, never stored empty');
		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 7);
		$srv->addMessage('INBOX', 1, $this->raw('Has body', '<f4@x.test>', 'the real body text'), array('mode' => 'nobody'));
		$this->poll($fx['account'], $srv);
		check(count($this->rows($fx['alias'])) === 0, 'nothing is stored while the body is missing');
		$srv->folders['INBOX']['messages'][1]['mode'] = null;
		$this->poll($fx['account'], $srv);
		$rows = $this->rows($fx['alias']);
		check(count($rows) === 1 && trim((string)$rows[0]['iem_body_plain']) === 'the real body text',
			'the next poll stores it with its body');
	}

	// ── F5 ──────────────────────────────────────────────────────────────────

	private function testByteCutsStayValidUtf8() {
		section('F5: cutting a body or subject never splits a character');
		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 7);
		$srv->addMessage('INBOX', 1, $this->raw('Big', '<f5b@x.test>', str_repeat('a', 2097150) . "\u{1F600}" . str_repeat('b', 100)));
		$srv->addMessage('INBOX', 2, $this->raw('=?UTF-8?B?' . base64_encode('a' . str_repeat('é', 600)) . '?=', '<f5c@x.test>'));
		$srv->addMessage('INBOX', 3, $this->raw("Caf\xE9 au lait", '<f5a@x.test>'));
		$this->poll($fx['account'], $srv);
		$rows = $this->rows($fx['alias']);
		check(count($rows) === 3, 'all three store', count($rows));
		$ok = true;
		foreach ($rows as $r) {
			$ok = $ok && mb_check_encoding((string)$r['iem_subject'], 'UTF-8') && mb_check_encoding((string)$r['iem_body_plain'], 'UTF-8');
		}
		check($ok, 'every stored subject and body is valid UTF-8');
		check(DocumentText::clip('a' . str_repeat('é', 600), 1000) === 'a' . str_repeat('é', 499),
			'clip() cuts at the last whole character');
	}

	// ── F6 ──────────────────────────────────────────────────────────────────

	private function testNoMessageIdIsStoredOnce() {
		section('F6: a message with no Message-ID is stored once, however many folders hold it');
		$fx = $this->fixture(array('iia_import_scope' => 'full', 'iia_sync_mode' => 'pull', 'iia_supports_condstore' => true));
		$srv = new HardeningImapServer();
		foreach (array('INBOX' => 7, 'Archive' => 8) as $f => $v) {
			$srv->addFolder($f, $v);
			$srv->addMessage($f, 1, $this->raw('No id here', null));
		}
		$this->track($fx['account'], 'INBOX', 'inbox'); $this->track($fx['account'], 'Archive');
		$this->poll($fx['account'], $srv);
		check(count($this->rows($fx['alias'])) === 1, 'one row for the message in two folders', count($this->rows($fx['alias'])));
		InboundImapFolder::rewindCursors(intval($fx['account']->key));
		$this->poll($fx['account'], $srv);
		check(count($this->rows($fx['alias'])) === 1, 'and still one after a full rescan');
	}

	// ── F7 ──────────────────────────────────────────────────────────────────

	private function testMissingDateFallsBackToInternalDate() {
		section('F7: a message with no Date header is dated by the server\'s arrival time');
		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 7);
		$srv->addMessage('INBOX', 1, $this->raw('Old one', '<f7@x.test>', 'x', array('Date' => null)), array('date' => '2019-03-01 12:00:00'));
		$srv->addMessage('INBOX', 2, $this->raw('Future spam', '<f7b@x.test>', 'x', array('Date' => 'Mon, 01 Jan 2035 10:00:00 +0000')), array('date' => '2026-09-02 08:00:00'));
		$this->poll($fx['account'], $srv);
		check(substr((string)($this->row($fx['alias'], '<f7@x.test>')['iem_received_time'] ?? ''), 0, 10) === '2019-03-01',
			'no Date header: INTERNALDATE');
		check(substr((string)($this->row($fx['alias'], '<f7b@x.test>')['iem_received_time'] ?? ''), 0, 10) === '2026-09-02',
			'a Date years in the future: INTERNALDATE');
	}

	// ── F8 ──────────────────────────────────────────────────────────────────

	private function testMissingStatusValues() {
		section('F8: a server that leaves UIDNEXT or UIDVALIDITY out of STATUS');
		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 7); $srv->folders['INBOX']['omit'] = array('uidnext');
		for ($i = 1; $i <= 3; $i++) { $srv->addMessage('INBOX', $i, $this->raw("U$i", "<f8a-$i@x.test>")); }
		$this->poll($fx['account'], $srv);
		check(count($this->rows($fx['alias'])) === 3, 'no UIDNEXT: the highest UID is asked directly and mail still imports');

		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 7); $srv->folders['INBOX']['omit'] = array('uidvalidity');
		$srv->addMessage('INBOX', 1, $this->raw('x', '<f8b@x.test>'));
		$res = $this->poll($fx['account'], $srv);
		check(isset($res['error']) && stripos($res['error'], 'UIDVALIDITY') !== false, 'no UIDVALIDITY: the folder is refused, saying why');
		check($this->folder($fx['account'], 'INBOX')->get('iif_uidvalidity') === null, 'and no UID is trusted blind');
	}

	// ── F9 ──────────────────────────────────────────────────────────────────

	private function testUidvalidityChurnPausesTheFolder() {
		section('F9: a folder whose UIDVALIDITY keeps changing is paused, not re-walked every poll');
		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 100);
		for ($i = 1; $i <= 40; $i++) { $srv->addMessage('INBOX', $i, $this->raw("h$i", "<f9-$i@x.test>")); }
		$this->poll($fx['account'], $srv);
		for ($p = 1; $p <= 5; $p++) { $srv->folders['INBOX']['uidvalidity'] = 100 + $p; $this->poll($fx['account'], $srv); }
		$folder = $this->folder($fx['account'], 'INBOX');
		check($folder->isPaused(), 'the third change in a day paused the folder');
		$srv->ops = array();
		$this->poll($fx['account'], $srv);
		check(count($srv->opsOf('fetch')) === 0, 'a paused folder is not fetched at all');
		$folder->resume();
		$folder = $this->folder($fx['account'], 'INBOX');
		check(!$folder->isPaused() && intval($folder->get('iif_uidvalidity_changes')) === 0, 'Resume clears the pause and the count');
	}

	// ── F10 ─────────────────────────────────────────────────────────────────

	private function testShortUidAnswerDropsNoLabels() {
		section('F10: a UID list shorter than the folder\'s message count removes nothing');
		$fx = $this->syncFixture();
		$srv = new HardeningImapServer(); $srv->caps['QRESYNC'] = false;
		$srv->addFolder('INBOX', 100); $srv->addFolder('Work', 300);
		for ($i = 1; $i <= 3; $i++) {
			$srv->addMessage('INBOX', $i, $this->raw("W$i", "<w$i@x.test>"));
			$srv->addMessage('Work', $i, $this->raw("W$i", "<w$i@x.test>"));
		}
		$this->exec('UPDATE iia_inbound_imap_accounts SET iia_supports_qresync = false WHERE iia_inbound_imap_account_id = ?', array($fx['account']->key));
		$this->track($fx['account'], 'INBOX', 'inbox'); $this->track($fx['account'], 'Work');
		$this->cycle($fx, $srv, 2);
		$count = function () use ($fx) {
			$s = $this->db()->prepare('SELECT count(*) FROM ilm_inbound_label_members m JOIN iif_inbound_imap_folders f ON f.iif_inbound_imap_folder_id = m.ilm_iif_inbound_imap_folder_id WHERE f.iif_iia_inbound_imap_account_id = ? AND f.iif_name = ?');
			$s->execute(array($fx['account']->key, 'Work'));
			return intval($s->fetchColumn());
		};
		$before = $count();
		check($before === 3, 'three Work labels to start');
		$srv->shortUidList['Work'] = true;
		$this->cycle($fx, $srv, 1);
		check($count() === 3, 'a short answer drops none of them', $count());
		unset($srv->shortUidList['Work']);
		unset($srv->folders['Work']['messages'][2]);
		$this->cycle($fx, $srv, 1);
		check($count() === 2, 'a real removal still drops that one', $count());
	}

	// ── F11 ─────────────────────────────────────────────────────────────────

	private function testStuckPushesDoNotStarveNewOnes() {
		section('F11: pushes that keep failing back off instead of blocking the queue');
		$fx = $this->syncFixture(); $al = $fx['alias'];
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 100); $srv->addFolder('Old', 400);
		for ($i = 1; $i <= 12; $i++) { $srv->addMessage('Old', $i, $this->raw("O$i", "<o$i@x.test>")); }
		$srv->addMessage('INBOX', 1, $this->raw('Fresh', '<fresh@x.test>'));
		$this->track($fx['account'], 'INBOX', 'inbox'); $this->track($fx['account'], 'Old');
		$this->cycle($fx, $srv, 2);
		$this->exec("UPDATE iem_inbound_email_messages SET iem_is_starred = true, iem_local_state_modified = now() WHERE iem_iea_inbound_email_alias_id = ? AND iem_imap_folder = 'Old'", array($al));
		$this->exec("UPDATE iem_inbound_email_messages SET iem_is_starred = true, iem_local_state_modified = now() + interval '1 minute' WHERE iem_iea_inbound_email_alias_id = ? AND iem_message_id_header = '<fresh@x.test>'", array($al));
		$srv->goneFolders['Old'] = true;
		$this->exec("UPDATE iif_inbound_imap_folders SET iif_is_tracked = false WHERE iif_iia_inbound_imap_account_id = ? AND iif_name = 'Old'", array($fx['account']->key));
		$srv->ops = array();
		$this->cycle($fx, $srv, 3, 10);
		check(count($srv->opsOf('store', 'INBOX')) > 0, 'the fresh star reached the server despite 12 stuck rows ahead of it');
		$s = $this->db()->prepare("SELECT count(*) FROM iem_inbound_email_messages WHERE iem_iea_inbound_email_alias_id = ? AND iem_imap_folder = 'Old' AND iem_push_retry_after > now()");
		$s->execute(array($al));
		check(intval($s->fetchColumn()) > 0, 'the stuck rows are waiting out a back-off');
	}

	// ── F12 ─────────────────────────────────────────────────────────────────

	private function testMoveCarriesDestinationGeneration() {
		section('F12: a moved message\'s locator carries the destination folder\'s UIDVALIDITY');
		$fx = $this->syncFixture(array('iia_folders_exclusive' => true)); $al = $fx['alias'];
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 100); $srv->addFolder('Work', 900);
		$srv->addMessage('INBOX', 1, $this->raw('Mv', '<mv@x.test>'));
		$this->track($fx['account'], 'INBOX', 'inbox'); $this->track($fx['account'], 'Work');
		$this->cycle($fx, $srv, 2);
		$M = $this->row($al, '<mv@x.test>');
		$labelId = $this->folder($fx['account'], 'Work')->ensureLabel();
		InboundLabelMember::apply(intval($M['iem_inbound_email_message_id']), intval($labelId));
		$this->cycle($fx, $srv, 1);
		$M = $this->row($al, '<mv@x.test>');
		check($M['iem_imap_folder'] === 'Work' && intval($M['iem_imap_uidvalidity']) === 900,
			'locator is Work with UIDVALIDITY 900', $M['iem_imap_folder'] . '/' . $M['iem_imap_uidvalidity']);
	}

	// ── F13 ─────────────────────────────────────────────────────────────────

	private function testBodyFetchIsBounded() {
		section('F13: only the first part of a huge text body is fetched');
		$fx = $this->fixture(array('iia_import_scope' => 'full'));
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 7);
		$srv->addMessage('INBOX', 1, $this->raw('Huge', '<f13@x.test>', str_repeat("line of text\n", 2500000)));
		$this->poll($fx['account'], $srv);
		$body = (string)($this->row($fx['alias'], '<f13@x.test>')['iem_body_plain'] ?? '');
		check(strlen($body) > 2000000 && strlen($body) < 2200000, 'the stored body is about 2 MB', strlen($body));
		check(strpos($body, 'Message body truncated') !== false, 'and says it was truncated');
		$q = new ReflectionClassConstant('ImapIngestor', 'TEXT_FETCH_BYTES');
		check($q->getValue() < 4 * 1048576, 'the request asks for under 4 MB of a 32 MB part');
	}

	// ── F14 ─────────────────────────────────────────────────────────────────

	private function testFailingFeedBacksOff() {
		section('F14: a feed that keeps failing is polled less often');
		$fx = $this->fixture(array('iia_poll_interval_seconds' => 300));
		$claim = new ReflectionMethod('PollImapAccounts', 'claim');
		$claim->setAccessible(true);
		$task = new PollImapAccounts();
		$at = function (int $failures, int $ago) use ($fx, $claim, $task) {
			$this->exec("UPDATE iia_inbound_imap_accounts SET iia_consecutive_failures = ?, iia_last_poll_time = now() - (? * interval '1 second') WHERE iia_inbound_imap_account_id = ?",
				array($failures, $ago, $fx['account']->key));
			return (bool)$claim->invoke($task, intval($fx['account']->key));
		};
		check($at(0, 301) === true, 'a healthy feed is due after its interval');
		check($at(6, 301) === false, 'six failures in a row: not due after one interval');
		check($at(6, 19201) === true, 'due after 64 intervals');
		check($at(20, 21601) === true, 'never more than six hours');
	}

	// ── F15 ─────────────────────────────────────────────────────────────────

	private function testMovedMessagesAreFollowedOrMarkedGone() {
		section('F15: a message moved at the source is followed; one gone from every tracked folder is kept and marked');
		$fx = $this->syncFixture(array('iia_folders_exclusive' => true, 'iia_supports_qresync' => true)); $al = $fx['alias'];
		$srv = new HardeningImapServer(); $srv->caps['QRESYNC'] = true;
		$srv->addFolder('INBOX', 100); $srv->addFolder('Kept', 600); $srv->addFolder('Archive', 700);
		$srv->addMessage('INBOX', 1, $this->raw('Followed', '<follow@x.test>'));
		$srv->addMessage('INBOX', 2, $this->raw('Gone', '<gone@x.test>'));
		$this->track($fx['account'], 'INBOX', 'inbox'); $this->track($fx['account'], 'Kept');
		$this->cycle($fx, $srv, 2);
		$srv->folders['Kept']['messages'][5] = $srv->folders['INBOX']['messages'][1];
		$srv->folders['Archive']['messages'][3] = $srv->folders['INBOX']['messages'][2];
		unset($srv->folders['INBOX']['messages'][1], $srv->folders['INBOX']['messages'][2]);
		$srv->vanishedByFolder['INBOX'] = array(1, 2);
		$this->cycle($fx, $srv, 1);
		$F = $this->row($al, '<follow@x.test>');
		check($F['iem_imap_folder'] === 'Kept' && intval($F['iem_imap_uid']) === 5 && $F['iem_source_gone_time'] === null,
			'moved to a tracked folder: the locator follows it', $F['iem_imap_folder'] . '/' . $F['iem_imap_uid']);
		$G = $this->row($al, '<gone@x.test>');
		check($G !== null && $G['iem_delete_time'] === null, 'moved out of reach: the message is kept');
		check($G !== null && $G['iem_source_gone_time'] !== null, 'and marked gone from the source');
	}

	// ── F16 / F17 / F18 ─────────────────────────────────────────────────────

	private function testNamespacedRoleNames() {
		section('F16: role names under INBOX. and [Google Mail]/');
		$cases = array('INBOX.Sent' => 'sent', 'INBOX/Trash' => 'trash', 'INBOX.Junk' => 'junk',
			'INBOX.Drafts' => 'drafts', '[Google Mail]/Sent Mail' => 'sent', 'Projects/Sent' => null);
		foreach ($cases as $name => $want) {
			check(InboundImapFolder::roleFor(array(), $name) === $want, $name . ' → ' . var_export($want, true));
		}
	}

	private function testInternalHostsRefused() {
		section('F17: a host inside this server\'s own network is refused');
		foreach (array('127.0.0.1', '169.254.169.254', '10.0.0.5', '192.168.1.10', '::1') as $host) {
			check(InboundImapAccount::imapHostProblem($host, 993) !== null, $host . ' is refused');
		}
		check(InboundImapAccount::imapHostProblem('1.1.1.1', 993) === null, 'a public address is accepted');
	}

	private function testWildcardFolderNameIsNotMistakenForAnother() {
		section('F18: a failed CREATE of "Q*A" is not mistaken for an existing "QxA"');
		$fx = $this->syncFixture();
		$srv = new HardeningImapServer(); $srv->addFolder('INBOX', 100); $srv->addFolder('QxA', 5);
		$this->track($fx['account'], 'INBOX', 'inbox');
		$f = InboundImapFolder::upsert(intval($fx['account']->key), 'Q*A', null, true);
		$f->set('iif_pending_remote_create', true); $f->prepare(); $f->save();
		$srv->createFails['Q*A'] = true;
		$this->cycle($fx, $srv, 1);
		$f = new InboundImapFolder($f->key, TRUE);
		check((bool)$f->get('iif_pending_remote_create') === true, 'the folder is still pending creation');
	}
}

(new ImapHardeningTest())->run();
harness_finish();
?>
