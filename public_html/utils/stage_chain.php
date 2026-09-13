<?php
/**
 * stage_chain.php — put an incremental backup chain back on this node, ready
 * for restore_chain.sh to replay it.
 *
 * Every node in this fleet backs up in chain mode, so this is the staging that
 * the common restore actually needs. It replaces six steps a management node
 * used to compose over SSH — make a workspace, download the manifest through a
 * heredoc'd uploader program, open the chain envelope, run a Python program
 * built on the management node to work out which artifacts the manifest names,
 * download each of them, take a pre-restore dump — with one script that lives
 * on this machine and is verified against the signed release manifest before it
 * starts.
 *
 * The difference that matters is not the step count. It is that the chain's
 * layout stops being something two implementations both compute. The manifest is
 * read HERE, by the machine that wrote it, using the same BackupChain code that
 * produced it; the caller supplies signed links keyed by bare artifact name and
 * has no say in which of them are used.
 *
 * WHAT THIS SCRIPT WILL NOT DO:
 *
 *   - It will not accept a decryption key, and none is offered. The chain data
 *     key is recovered here, from this machine's own config/backup_site_key,
 *     against the envelope inside the manifest. A key on the wire is a key in
 *     every stored job record. A chain that does not open with this machine's
 *     own key belongs to a different machine, and that is a refusal, not a
 *     prompt for a better key.
 *   - It will not accept a bucket credential. Signed URLs, one object each,
 *     expiring — see BackupFetch.
 *   - It will not stage an artifact this machine has no record of uploading.
 *     Every fetch is checked against the node-side upload ledger, so a
 *     management node cannot substitute a forged artifact, or replay a genuine
 *     one from another run under the name this run's manifest expects.
 *   - It will not restore anything. Staging is not destructive and takes no
 *     approval; the restore that follows is destructive and takes one.
 *
 * Configuration arrives as JSON on stdin, and only on stdin:
 *
 *   php utils/stage_chain.php <<'EOF'
 *   {"chain_id":"chain-20260830_010203","profile":"manager",
 *    "manifest_url":"https://…signed…",
 *    "artifact_urls":{"files-0000.tar.gz.enc":"https://…","db-0000.sql.gz.enc":"https://…"},
 *    "seq":3}
 *   EOF
 *
 * Exits 0 on success, 1 on a transfer, envelope or integrity failure, 2 on a
 * malformed request.
 *
 * @version 1.1 - the fetch, ledger check, envelope open and key file live in BackupStaging,
 *                shared with utils/verify_backup.php so a verify can never fetch something a
 *                Prepare would refuse; the stdout/stderr/exit contract is unchanged
 * @version 1.0
 */

// Reject non-CLI access
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

function stage_chain_refuse($message, $code = 2) {
	fwrite(STDERR, 'STAGE_FAIL: ' . $message . "\n");
	echo "STAGE_RESULT=error\n";
	exit($code);
}

// ── Configuration ───────────────────────────────────────────────────────────

$raw = stream_get_contents(STDIN);
$config = json_decode((string)$raw, true);
unset($raw);

try {
	$request = BackupStaging::parse_request($config);
} catch (BackupStagingException $e) {
	stage_chain_refuse($e->getMessage(), $e->getCode());
}
unset($config);

$chain_id      = $request['chain_id'];
$profile       = $request['profile'];
$manifest_url  = $request['manifest_url'];
$artifact_urls = $request['artifact_urls'];
$seq           = $request['seq'];
unset($request);

// ── The workspace ───────────────────────────────────────────────────────────
// restore_<chain_id> under the node's own backup BASE — not under the profile's
// directory. That is where the SSH path put it and where the restore_chain
// primitive looks for it; the two must agree or the restore refuses with
// "no downloaded chain" beside a chain that is downloaded.
$work = rtrim(BackupRunner::output_dir(), '/') . '/' . BackupRunner::STAGED_RESTORE_PREFIX . $chain_id;

try {
	BackupStaging::prepare_workspace($work);

	// The manifest first; the chain data key recovered from this machine's own
	// site key; then exactly the artifacts the manifest says the run needs, in
	// the order a restore applies them. Every decision is BackupStaging's.
	$manifest = BackupStaging::fetch_manifest($profile, $work, $chain_id, $manifest_url);
	$plan     = BackupStaging::plan($manifest, $seq);
	BackupStaging::write_chain_key($manifest, $work);

	$staged = BackupStaging::fetch_artifacts($profile, $work, $chain_id, BackupStaging::wanted($plan),
		$artifact_urls, $plan['seq'], function ($what, $name) {
			echo ($what === 'fetching') ? 'fetching ' . $name . "\n" : $what . ': ' . $name . "\n";
		});
} catch (BackupStagingException $e) {
	stage_chain_refuse($e->getMessage(), $e->getCode());
}

$fetched = $staged['fetched'];
$bytes   = $staged['bytes'];

echo 'Staged ' . $fetched . ' artifact' . ($fetched === 1 ? '' : 's') . ' of ' . $chain_id
	. ' (' . BackupFetch::human($bytes) . ') in ' . $work . ', with the chain key recovered from this machine\'s own key.' . "\n";
echo "STAGE_RESULT=ok\n";
echo 'STAGE_CHAIN=' . $chain_id . "\n";
echo 'STAGE_SEQ=' . (int)$plan['seq'] . "\n";
echo 'STAGE_ARTIFACTS=' . $fetched . "\n";
echo 'STAGE_BYTES=' . $bytes . "\n";
echo 'STAGE_WORKSPACE=' . $work . "\n";
exit(0);
