<?php
/**
 * Purge the Drive data of retired soak accounts.
 *
 * The rig provisions a fresh account per run and never reclaims the last one,
 * so the served deployment grows by roughly 27MB a run forever. On 2026-08-24
 * that filled the volume, killed postgres, and left the campaign writing green
 * for seven hours into a ledger nobody could tell was lying.
 *
 * Deletion goes through File::permanent_delete so the blob layer releases its
 * reference and the bytes actually leave the disk; deleting rows would strand
 * every blob instead.
 *
 * One pool per soak line, each kept on its own. A line is an account name
 * with its number taken off: soak-rig-* (the ext4 rig, one account per run,
 * about 70 a day), soak-mac-* (the Mac mini's APFS campaign), soak-mac-fat-*
 * (its FAT line), soak-mac-win-* (the Windows VM's line), and any other
 * soak-rig-<word>-* or soak-mac-<word>-* a test line makes. Lines other than
 * the rig's draw from a pool made ahead and use it one run at a time. The
 * newest N accounts STILL HOLDING FILES are kept in each line, so the account
 * a line is using is never purged: a pool made ahead holds nothing until its
 * turn, and counted by id alone it would push the Mac's live account out of
 * the kept set within a day of rig runs. Counted across lines, it is pushed
 * out by the other lines' runs instead: every account in a pool made after
 * the Mac's sorts above the Mac's live one, so N runs of the FAT and Windows
 * lines together would purge it while it was in use.
 *
 * usage: purge_soak.php <keep_newest_accounts> [--apply] [--max-accounts=N]
 */
chdir('/var/www/html/drivetest/public_html');
require_once('includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/file_blobs_class.php'));

$settings = Globalvars::get_instance();
$link = DbConnector::get_instance()->get_db_link();

$keep  = (int)($argv[1] ?? 25);
$apply = in_array('--apply', $argv, true);
$max   = 1000;
foreach ($argv as $a) { if (strpos($a, '--max-accounts=') === 0) { $max = (int)substr($a, 15); } }

// The line an account belongs to: its address up to the number.
function soak_line($email) {
    return preg_match('/^(soak-(?:rig|mac)-(?:[a-z]+-)*)[0-9]+@/', $email, $m) ? $m[1] : null;
}

// Emptying an account does not remove it, so an already-purged one stays on
// the list forever. Left in, each pass would spend its whole budget on the
// oldest accounts -- which have nothing left -- and never reach the ones still
// holding bytes: a purge that runs every run, reports success, and reclaims
// nothing. So only accounts still holding files are counted at all. Holding
// is read as a number: a boolean column can reach PHP as the string 'f',
// which is true, and would count every empty account made ahead as holding.
$q = $link->query("SELECT u.usr_user_id, u.usr_email,
                          CASE WHEN EXISTS (SELECT 1 FROM fil_files f WHERE f.fil_usr_user_id = u.usr_user_id)
                               THEN 1 ELSE 0 END AS holding
                     FROM usr_users u
                    WHERE u.usr_email LIKE 'soak-rig-%' OR u.usr_email LIKE 'soak-mac-%'
                    ORDER BY u.usr_user_id DESC");
$lines = array();
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $line = soak_line($u['usr_email']);
    if ($line === null) { continue; }
    if (!isset($lines[$line])) { $lines[$line] = array('total' => 0, 'holding' => array()); }
    $lines[$line]['total']++;
    if ((int)$u['holding'] === 1) { $lines[$line]['holding'][] = $u; }
}
// The rig's line first: it makes an account every run, and is what fills the
// volume when a pass's budget goes elsewhere.
uksort($lines, function ($a, $b) { return ($b === 'soak-rig-') - ($a === 'soak-rig-') ?: strcmp($a, $b); });
$retire = array();
foreach ($lines as $line => $l) {
    $old = array_slice($l['holding'], $keep);
    echo "{$l['total']} $line accounts, " . count($l['holding']) . " holding files; keeping newest $keep; "
        . count($old) . " to purge\n";
    $retire = array_merge($retire, $old);
}
$retire = array_slice($retire, 0, $max);
echo "this pass: " . count($retire) . ($apply ? " (APPLYING)" : " (dry run)") . "\n";

$files = 0; $bytes = 0; $done = 0; $errors = 0;
foreach ($retire as $u) {
    $uid = (int)$u['usr_user_id'];
    $fq = $link->prepare("SELECT f.fil_file_id, COALESCE(b.fbb_size_bytes,0) sz
                            FROM fil_files f LEFT JOIN fbb_file_blobs b ON b.fbb_file_blob_id = f.fil_fbb_file_blob_id
                           WHERE f.fil_usr_user_id = ?");
    $fq->execute([$uid]);
    $rows = $fq->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $files++; $bytes += (int)$r['sz'];
        if ($apply) {
            // One unhappy row must not abort the pass. This runs unattended
            // between every campaign, and a single file the deleter chokes on
            // would otherwise stop the backlog draining for good, silently.
            try {
                $f = new File((int)$r['fil_file_id'], true);
                if ($f->key) { $f->permanent_delete(); }
            } catch (Throwable $e) {
                $errors++;
                if ($errors <= 3) {
                    echo '  skipped file ' . (int)$r['fil_file_id'] . ': '
                        . substr($e->getMessage(), 0, 90) . "\n";
                }
            }
        }
    }
    $done++;
    if ($apply && $done % 10 === 0) { echo "  ...$done accounts, $files files\n"; }
}
printf("%s %d file(s), %.2f GB of head blobs across %d account(s)%s\n",
    $apply ? 'purged' : 'would purge', $files, $bytes / 1073741824, count($retire),
    $errors ? " -- $errors skipped" : '');
