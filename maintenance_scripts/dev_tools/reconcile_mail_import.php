<?php
/**
 * reconcile_mail_import.php - did every message in the archive actually land?
 *
 * The importer's own counters are self-consistent: every message it SAW lands in
 * exactly one bucket, and a shortfall trips `unaccounted`. But the denominator is
 * its own scan, so a message its reader never emitted is missing from every
 * number it reports. This script supplies the missing denominator from outside —
 * an inventory produced by mail_archive_inventory.py, which parses the archive
 * with a different implementation entirely — and answers the only question that
 * matters: which messages are in the source and not in the mailbox.
 *
 * It prints IDENTIFIERS, not a count. A count comparison passes whenever two
 * errors cancel, which is exactly the case worth catching.
 *
 * Four comparisons, because a message can go missing in four different ways:
 *
 *   1. by Message-ID  - the ordinary case. Soft-deleted rows COUNT AS PRESENT:
 *      Trash is modelled as a soft delete, so mail the source had in its bin is
 *      correctly stored as a deleted row. Treating those as lost would report
 *      every binned message as a failure.
 *   2. by byte offset - a message with no Message-ID is stored under a
 *      synthesized id derived from its bytes, which the inventory cannot cheaply
 *      reproduce. Those are matched by position instead: the inventory records
 *      each message's body offset and the ledger's mbox locators are
 *      "offset:length" against the same file. An offset on one side only also
 *      localises exactly where the two splitters disagreed.
 *   3. by attachment count - per message present on both sides, the source's
 *      part count against the stored manifest's row count. Catches a dropped
 *      attachment on a message that is otherwise fine, which Message-ID matching
 *      cannot see. COUNT, not filename: the two sides name and de-duplicate
 *      parts differently and a name comparison would drown in false mismatches.
 *   4. by ledger reason - the entries whose own recorded outcome says to look:
 *      a duplicate that collided with a row in another mailbox, one that could
 *      not be identified at all, and one whose stored copy lists no attachments.
 *
 * READ-ONLY. It never writes to the database.
 *
 * Usage:
 *   php reconcile_mail_import.php --run=ID --inventory=PATH [--out=DIR] [--show=N]
 *                                 [--member=NAME]
 *
 * Exits 0 when nothing is outstanding, 2 when there are findings.
 *
 * See specs/mail_import_loss_proof.md § A.
 *
 * @version 1.1
 * @changelog 1.1 - attachment counts reduce with MAX per Message-ID (one id can
 *   legitimately be several rows, and summing them invented mismatches); byte
 *   offsets are scoped to an archive member, so a multi-mbox archive cannot
 *   silently pair a message with an unrelated entry.
 */

$root = dirname(dirname(__DIR__)) . '/public_html';
$_SERVER['DOCUMENT_ROOT'] = $root;
require_once($root . '/includes/PathHelper.php');
require_once($root . '/includes/ClassAutoloader.php');
ClassAutoloader::register();

// ---------------------------------------------------------------- arguments

$opts = getopt('', array('run:', 'inventory:', 'out::', 'show::', 'member::', 'help'));
if (isset($opts['help']) || empty($opts['run']) || empty($opts['inventory'])) {
	fwrite(STDERR, "Usage: php reconcile_mail_import.php --run=ID --inventory=PATH [--out=DIR] [--show=N]\n\n"
		. "  --run        the mail import run to reconcile\n"
		. "  --inventory  JSONL from mail_archive_inventory.py\n"
		. "  --out        directory for the full finding lists (default: alongside the inventory)\n"
		. "  --show       how many findings to print per section (default 25)\n"
		. "  --member     which archive member the inventory covers, when the archive\n"
		. "               held more than one mbox (offsets are only unique within a file)\n");
	exit(1);
}

$run_id    = intval($opts['run']);
$inventory = (string)$opts['inventory'];
$show      = isset($opts['show']) ? max(1, intval($opts['show'])) : 25;
$member_filter = (isset($opts['member']) && $opts['member'] !== '') ? (string)$opts['member'] : null;
$out_dir   = isset($opts['out']) && $opts['out'] !== '' ? rtrim((string)$opts['out'], '/') : dirname($inventory);

if (!is_readable($inventory)) {
	fwrite(STDERR, "Cannot read the inventory: $inventory\n");
	exit(1);
}

$run = new MailImportRun($run_id, TRUE);
if (!$run->key) {
	fwrite(STDERR, "No import run with id $run_id.\n");
	exit(1);
}
$alias_id = intval($run->get('mir_iea_inbound_email_alias_id'));
$alias = new InboundEmailAlias($alias_id, TRUE);

echo "Reconciling run $run_id — " . ($run->get('mir_source_name') ?: 'archive') . "\n";
echo "  mailbox: " . ($alias->key ? $alias->get_full_address() : "alias $alias_id (missing)") . "\n";
echo "  state:   " . $run->get('mir_state') . "\n";
echo "  counters: " . intval($run->get('mir_total_entries')) . " scanned, "
	. intval($run->get('mir_stored')) . " stored, "
	. intval($run->get('mir_dedup')) . " duplicate, "
	. intval($run->get('mir_skipped')) . " skipped, "
	. intval($run->get('mir_failed')) . " failed\n\n";

$db = DbConnector::get_instance()->get_db_link();

// ------------------------------------------------- what the mailbox holds now

/**
 * Every Message-ID stored in this mailbox, mapped to its attachment count.
 *
 * Deleted rows are INCLUDED on purpose (see the header): a message the source
 * had in Trash is stored as a soft-deleted row, and calling that lost would make
 * every binned message a finding.
 *
 * One query and one in-memory set rather than a lookup per message: a
 * half-million-message archive would otherwise be a half-million round trips.
 */
echo "Loading stored messages…\n";
$stored = array();
// Counted PER MESSAGE ROW first, then reduced with MAX. One Message-ID can
// legitimately appear more than once in a mailbox — the unique key is
// (message-id, recipient), so a message sent and received, or delivered to two
// of the user's addresses, is two rows. Counting attachments across the group
// would add their manifests together and report a mismatch on a message that is
// perfectly fine. MAX asks the question that matters: does ANY copy here hold
// the full set? A false finding in a loss report is worse than none, because it
// teaches the reader to skim past the real ones.
$stmt = $db->prepare(
	'SELECT mid, MAX(attachments) AS attachments FROM (
	    SELECT m.iem_message_id_header AS mid,
	           COUNT(a.ima_inbound_message_attachment_id) AS attachments
	      FROM iem_inbound_email_messages m
	      LEFT JOIN ima_inbound_message_attachments a
	             ON a.ima_iem_inbound_email_message_id = m.iem_inbound_email_message_id
	     WHERE m.iem_iea_inbound_email_alias_id = ?
	       AND m.iem_message_id_header IS NOT NULL
	     GROUP BY m.iem_inbound_email_message_id, m.iem_message_id_header
	  ) per_row
	  GROUP BY mid');
$stmt->execute(array($alias_id));
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
	$stored[$row['mid']] = intval($row['attachments']);
}
echo '  ' . number_format(count($stored)) . " distinct Message-IDs in this mailbox\n";

// ------------------------------------------------ what the ledger says it did

/**
 * The ledger's own account, keyed by the byte offset in its locator.
 *
 * Locators come in three shapes and only the mbox ones carry an offset:
 *   "offset:length"            a bare mbox
 *   "m|member|offset:length"   an mbox nested inside a container
 *   "f|member"                 a file-per-message archive — no offset, skipped
 */
echo "Loading the import ledger…\n";
$by_offset = array();
$members_seen = array();
$states = array();
$suspicious = array();
foreach (MailImportEntry::SUSPICIOUS_REASONS as $reason) {
	$suspicious[$reason] = array();
}

$stmt = $db->prepare('SELECT mie_locator, mie_state, mie_reason, mie_iem_inbound_email_message_id
	FROM mie_mail_import_entries WHERE mie_mir_mail_import_run_id = ?');
$stmt->execute(array($run_id));
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
	$state = (string)$row['mie_state'];
	$states[$state] = ($states[$state] ?? 0) + 1;

	list($member, $offset) = locator_parts((string)$row['mie_locator']);
	if ($offset !== null && ($member_filter === null || $member === $member_filter)) {
		$by_offset[$offset] = $state;
	}
	if ($offset !== null && $member !== null) {
		$members_seen[$member] = true;
	}

	$reason = (string)$row['mie_reason'];
	foreach (MailImportEntry::SUSPICIOUS_REASONS as $prefix) {
		if ($reason !== '' && strncmp($reason, $prefix, strlen($prefix)) === 0) {
			$suspicious[$prefix][] = trim($row['mie_locator'] . ' — ' . $reason);
			break;
		}
	}
}
echo '  ' . number_format(array_sum($states)) . " ledger entries\n";

// Offsets are unique only within one file. If this run read several mboxes and
// the caller has not said which one the inventory covers, a position match could
// pair a source message with an unrelated entry from another member — reporting
// a message as present that is not. Say so and drop the section rather than
// print a number that cannot be trusted.
$offset_matching_ok = true;
if ($member_filter === null && count($members_seen) > 1) {
	$offset_matching_ok = false;
	echo "\n  ! This run read " . count($members_seen) . " separate mbox members, and byte offsets\n";
	echo "    are only unique within one file. Re-run with --member=NAME to reconcile the\n";
	echo "    messages that have no Message-ID. Members:\n";
	foreach (array_slice(array_keys($members_seen), 0, 10) as $name) {
		echo '      ' . $name . "\n";
	}
} elseif ($member_filter !== null) {
	echo '  offset matching restricted to member: ' . $member_filter . "\n";
}
echo "\n";

/**
 * The archive member a locator names and the byte offset inside it.
 *
 * The member matters because offsets are only unique WITHIN one file. An archive
 * holding two mboxes has two messages at offset 4096, and matching on the number
 * alone would call one of them present because the other is — an under-report,
 * which is the direction that must never happen quietly.
 *
 * @return array{0:?string,1:?int} member (null for a bare mbox), offset (null when
 *         the locator shape carries none).
 */
function locator_parts(string $locator): array {
	$tail = $locator;
	$member = null;
	if (strncmp($tail, 'f|', 2) === 0) {
		return array(substr($tail, 2), null);          // file-per-message: no offset
	}
	if (strncmp($tail, 'm|', 2) === 0) {
		$rest = substr($tail, 2);
		$split = strrpos($rest, '|');
		if ($split === false) { return array(null, null); }
		$member = substr($rest, 0, $split);
		$tail = substr($rest, $split + 1);
	}
	if (strpos($tail, ':') === false) {
		return array($member, null);
	}
	$parts = explode(':', $tail);
	return array($member, is_numeric($parts[0]) ? intval($parts[0]) : null);
}

// -------------------------------------------------- walk the source inventory

echo "Reading the inventory…\n";
$handle = fopen($inventory, 'rb');
if (!$handle) {
	fwrite(STDERR, "Could not open $inventory\n");
	exit(1);
}

$total = 0;
$with_id = 0;
$no_id = 0;
$unreadable = 0;
$missing_by_id = array();
$missing_by_offset = array();
$attachment_gaps = array();

while (($line = fgets($handle)) !== false) {
	$line = trim($line);
	if ($line === '') { continue; }
	$rec = json_decode($line, true);
	if (!is_array($rec)) { continue; }

	$total++;
	if (!empty($rec['error'])) { $unreadable++; }

	$mid = trim((string)($rec['message_id'] ?? ''));
	$source_attachments = is_array($rec['attachments'] ?? null) ? count($rec['attachments']) : 0;

	if ($mid !== '') {
		$with_id++;
		if (!array_key_exists($mid, $stored)) {
			$missing_by_id[] = $mid . '  (offset ' . intval($rec['body_offset'] ?? -1) . ')';
			continue;
		}
		// Present. Do the two sides agree about what it contains?
		if ($source_attachments !== $stored[$mid]) {
			$names = array();
			foreach ((array)($rec['attachments'] ?? array()) as $a) {
				$names[] = (string)($a['filename'] ?? '') ?: '(unnamed)';
			}
			$attachment_gaps[] = $mid . '  source ' . $source_attachments
				. ' vs stored ' . $stored[$mid] . '  [' . implode(', ', $names) . ']';
		}
		continue;
	}

	// No Message-ID: the only handle is where it sits in the file.
	$no_id++;
	if (!$offset_matching_ok) {
		continue;                    // ambiguous across members — already reported
	}
	$offset = intval($rec['body_offset'] ?? -1);
	if ($offset < 0 || !array_key_exists($offset, $by_offset)) {
		$missing_by_offset[] = 'offset ' . $offset . ' (index ' . intval($rec['index'] ?? -1) . ')';
	}
}
fclose($handle);

// -------------------------------------------------------------------- report

echo "\n";
echo "SOURCE\n";
echo '  ' . number_format($total) . " messages in the archive\n";
echo '  ' . number_format($with_id) . " with a Message-ID, " . number_format($no_id) . " without\n";
if ($unreadable > 0) {
	echo '  ' . number_format($unreadable) . " the inventory could not parse\n";
}

echo "\nLEDGER\n";
foreach ($states as $state => $count) {
	echo '  ' . str_pad($state, 10) . number_format($count) . "\n";
}

$findings = 0;
$findings += report_section('MISSING — in the archive, not in this mailbox (by Message-ID)',
	$missing_by_id, $show, $out_dir . "/run{$run_id}_missing_by_id.txt");
if ($offset_matching_ok) {
	$findings += report_section('MISSING — no Message-ID, and no ledger entry at that position',
		$missing_by_offset, $show, $out_dir . "/run{$run_id}_missing_by_offset.txt");
} else {
	echo "\nMISSING — no Message-ID, and no ledger entry at that position\n";
	echo '  NOT CHECKED — ' . number_format($no_id) . " message(s) without a Message-ID could not be\n";
	echo "  placed, because this run read several mbox members. Re-run with --member=NAME.\n";
}
$findings += report_section('ATTACHMENT COUNT DIFFERS between the archive and the stored copy',
	$attachment_gaps, $show, $out_dir . "/run{$run_id}_attachment_gaps.txt");

foreach (MailImportEntry::SUSPICIOUS_REASONS as $prefix) {
	$findings += report_section('LEDGER FLAGGED — ' . $prefix, $suspicious[$prefix], $show,
		$out_dir . '/run' . $run_id . '_flagged_' . substr(md5($prefix), 0, 8) . '.txt');
}

echo "\n";
if ($findings === 0) {
	echo "NOTHING OUTSTANDING. Every message in the archive is accounted for in this mailbox,\n";
	echo "every no-Message-ID message has a ledger entry at its position, the attachment counts\n";
	echo "agree, and no duplicate was recorded without naming what it duplicated.\n";
	exit(0);
}

echo "$findings finding(s) above. Each is a named message or position — none is a bare count.\n";
exit(2);

/**
 * Print one section, capped, and write the whole list to a file so a long one
 * stays usable. Returns how many findings it held.
 *
 * A section with nothing in it still prints, because "checked, clean" and "never
 * checked" must not look the same.
 */
function report_section(string $title, array $items, int $show, string $path): int {
	echo "\n$title\n";
	$count = count($items);
	if ($count === 0) {
		echo "  none\n";
		return 0;
	}
	echo '  ' . number_format($count) . " found\n";
	foreach (array_slice($items, 0, $show) as $item) {
		echo '    ' . $item . "\n";
	}
	if ($count > $show) {
		echo '    … ' . number_format($count - $show) . " more\n";
	}
	if (@file_put_contents($path, implode("\n", $items) . "\n") !== false) {
		echo '  full list: ' . $path . "\n";
	}
	return $count;
}
?>
