<?php
/**
 * imap_window_audit.php - is everything the source server holds actually here?
 *
 * The IMAP side of the same question reconcile_mail_import.php asks of an
 * archive. A poll reconciles the UIDs it walked; it has nothing to say about the
 * ones it never reached. This asks the source server directly and compares.
 *
 * Two independent checks, because a day-windowed feed can fail in two directions:
 *
 * ABOVE THE CURSOR — coverage. Fetch INTERNALDATE and Message-ID for every UID
 * the feed has read past, and report the ones with no stored row. Soft-deleted
 * rows count as present: a message the remote filed in Trash is stored as a
 * deleted row, so counting those as lost would report every binned message.
 *
 * BELOW THE CURSOR — the loss proof. The seed proof written at seek time checks
 * the boundary the bisection chose, but that is not a statement about the whole
 * region beneath it: INTERNALDATE is not guaranteed to rise with UID, because a
 * message copied or imported into an account gets a fresh high UID carrying
 * whatever date it already had. So this fetches the dates of everything below
 * the cursor — one `FETCH 1:cursor (INTERNALDATE)`, dates only, no headers, a
 * single command even on a large folder — and names every message whose date
 * falls inside the window the user asked for. Those are messages the seed
 * skipped that the window claims.
 *
 * Deliberately expensive and run on demand. This is the instrument you reach for
 * to verify a feed, not something a poll does. (No SEARCH-based shortcut exists:
 * Gmail advertises ESEARCH but rejects the form Horde emits, which is why
 * nothing in ImapIngestor searches.)
 *
 * READ-ONLY on both sides — it never writes to the database and never sets a
 * flag on the source server.
 *
 * Usage:
 *   php imap_window_audit.php --account=ID [--folder=NAME] [--show=N]
 *
 * See specs/mail_import_loss_proof.md § B.
 *
 * @version 1.0
 */

$root = dirname(dirname(__DIR__)) . '/public_html';
$_SERVER['DOCUMENT_ROOT'] = $root;
require_once($root . '/includes/PathHelper.php');
require_once($root . '/includes/ClassAutoloader.php');
ClassAutoloader::register();
require_once(PathHelper::getComposerAutoloadPath());

$opts = getopt('', array('account:', 'folder::', 'show::', 'help'));
if (isset($opts['help']) || empty($opts['account'])) {
	fwrite(STDERR, "Usage: php imap_window_audit.php --account=ID [--folder=NAME] [--show=N]\n\n"
		. "  --account  the IMAP feed to audit\n"
		. "  --folder   one folder (default: every tracked folder with a cursor)\n"
		. "  --show     how many findings to print per section (default 25)\n");
	exit(1);
}

$account_id = intval($opts['account']);
$show = isset($opts['show']) ? max(1, intval($opts['show'])) : 25;

$account = new InboundImapAccount($account_id, TRUE);
if (!$account->key) {
	fwrite(STDERR, "No IMAP account with id $account_id.\n");
	exit(1);
}

// The question is "is the message in this MAILBOX?", so presence is judged by
// the alias the feed delivers into — never by which path stored the row. A row
// the archive import stored first carries no IMAP account id, and the poll's
// dedup against it does not add one; matching on the account would report every
// such message as lost, in exactly the poll-plus-import scenario this tool
// exists to verify.
$alias_id = intval($account->get('iia_iea_inbound_email_alias_id'));
if ($alias_id <= 0) {
	fwrite(STDERR, "Account $account_id delivers to no alias — there is no mailbox to audit against.\n");
	exit(1);
}

$cutoff = (string)$account->importCutoffUtc();
echo "Auditing account $account_id (" . ($account->get('iia_label') ?: $account->get('iia_username')) . ")\n";
echo '  scope:  ' . $account->importScope()
	. ($cutoff !== '' ? ' — window opens ' . $cutoff : ' — no date window') . "\n";

$folders = array();
$wanted = isset($opts['folder']) && $opts['folder'] !== '' ? (string)$opts['folder'] : null;
$rows = new MultiInboundImapFolder(array('account_id' => $account_id));
foreach ($rows as $row) {
	$folder = new InboundImapFolder($row->key, TRUE);
	if ($wanted !== null && strcasecmp((string)$folder->get('iif_name'), $wanted) !== 0) {
		continue;
	}
	if ($folder->get('iif_last_seen_uid') === null) {
		continue;                                   // never seeded — nothing to audit
	}
	$folders[] = $folder;
}
if (!count($folders)) {
	fwrite(STDERR, "No tracked folder with a cursor" . ($wanted !== null ? " named $wanted" : '') . ".\n");
	exit(1);
}

$ingestor = new ImapIngestor($account);
$db = DbConnector::get_instance()->get_db_link();
$findings = 0;

try {
	$client = $ingestor->getClient();

	foreach ($folders as $folder) {
		$name = (string)$folder->get('iif_name');
		$cursor = intval($folder->get('iif_last_seen_uid'));
		echo "\n=== $name (cursor $cursor) ===\n";

		// ---- above the cursor: everything read should be stored -------------
		$above_missing = array();
		$above_seen = 0;
		$query = new Horde_Imap_Client_Fetch_Query();
		$query->imapDate();
		$query->envelope();
		$res = $client->fetch($name, $query, array(
			'ids' => new Horde_Imap_Client_Ids(($cursor + 1) . ':*'),
		));
		foreach ($res->ids() as $uid) {
			$uid = intval($uid);
			if ($uid <= $cursor) { continue; }
			$data = $res[$uid] ?? null;
			if ($data === null) { continue; }
			$above_seen++;
			$mid = trim((string)$data->getEnvelope()->message_id);
			if ($mid === '') {
				$above_missing[] = "uid $uid — no Message-ID on the server, cannot be matched";
				continue;
			}
			if (!stored_here($db, $alias_id, $mid)) {
				$above_missing[] = "uid $uid — $mid";
			}
		}
		echo '  above the cursor: ' . number_format($above_seen) . " message(s) on the server\n";
		$findings += audit_section('  NOT STORED (read past, but no row here)', $above_missing, $show);

		// ---- below the cursor: the seed should have skipped only old mail ----
		if ($cutoff === '' || $cursor < 1) {
			echo "  below the cursor: nothing to prove (no date window, or the feed starts at zero)\n";
			continue;
		}
		$in_window_below = array();
		$below_seen = 0;
		$dates = new Horde_Imap_Client_Fetch_Query();
		$dates->imapDate();
		$res = $client->fetch($name, $dates, array(
			'ids' => new Horde_Imap_Client_Ids('1:' . $cursor),
		));
		foreach ($res->ids() as $uid) {
			$uid = intval($uid);
			if ($uid > $cursor) { continue; }
			$data = $res[$uid] ?? null;
			if ($data === null) { continue; }
			$below_seen++;
			$date = $data->getImapDate();
			if (!$date || $date->error()) {
				// Unknown is never evidence either way — but it is not silence.
				$in_window_below[] = "uid $uid — unreadable INTERNALDATE, cannot be judged";
				continue;
			}
			$date = clone $date;
			$date->setTimezone(new DateTimeZone('UTC'));
			$stamp = $date->format('Y-m-d H:i:s');
			if ($stamp >= $cutoff) {
				$in_window_below[] = "uid $uid — $stamp is inside the window but below the cursor";
			}
		}
		echo '  below the cursor: ' . number_format($below_seen) . " message(s) on the server\n";
		$findings += audit_section('  SKIPPED (inside the window, never read)', $in_window_below, $show);
	}
} catch (Throwable $e) {
	fwrite(STDERR, 'Audit failed: ' . $e->getMessage() . "\n");
	$ingestor->close();
	exit(1);
}

$ingestor->close();

// ------------------------------------------------------- the seed proof trail

echo "\n=== seed proofs recorded for this account ===\n";
$proofs = new MultiInboundImapSeedProof(array('account_id' => $account_id),
	array('isp_create_time' => 'DESC'), 10);
$any = false;
foreach ($proofs as $row) {
	$proof = new InboundImapSeedProof($row->key, TRUE);
	echo '  ' . $proof->get('isp_create_time') . '  ' . $proof->describe() . "\n";
	if ($proof->boundaryHolds() === false) { $findings++; }
	$any = true;
}
if (!$any) {
	echo "  none — this feed has not seeded a day-windowed cursor since seed proofs were recorded\n";
}

echo "\n";
if ($findings === 0) {
	echo "NOTHING OUTSTANDING. Everything the feed has read past is stored, and nothing inside\n";
	echo "the window sits below where it started reading.\n";
	exit(0);
}
echo "$findings finding(s) above.\n";
exit(2);

/**
 * Is this Message-ID stored in the mailbox this feed delivers into?
 *
 * Scoped to the ALIAS, not the IMAP account, so a row stored by any path — the
 * poll, the archive import, a local delivery — counts. Deleted rows count as
 * present — Trash arrivals are stored as soft-deleted rows, so excluding them
 * would report every binned message as lost.
 */
function stored_here(PDO $db, int $alias_id, string $mid): bool {
	static $stmt = null;
	if ($stmt === null) {
		$stmt = $db->prepare('SELECT 1 FROM iem_inbound_email_messages
			WHERE iem_message_id_header = ? AND iem_iea_inbound_email_alias_id = ? LIMIT 1');
	}
	$stmt->execute(array(substr($mid, 0, 255), $alias_id));
	return $stmt->fetchColumn() !== false;
}

/** One capped section. An empty one still prints — "clean" must not look like "unchecked". */
function audit_section(string $title, array $items, int $show): int {
	$count = count($items);
	if ($count === 0) {
		echo "$title: none\n";
		return 0;
	}
	echo "$title: " . number_format($count) . "\n";
	foreach (array_slice($items, 0, $show) as $item) {
		echo '      ' . $item . "\n";
	}
	if ($count > $show) {
		echo '      … ' . number_format($count - $show) . " more\n";
	}
	return $count;
}
?>
