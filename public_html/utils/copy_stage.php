<?php
/**
 * copy_stage.php — download the backup chain a dormant copy will apply
 * (specs/site_copy.md WP4, G7). The work is CopyStaging's; this is the
 * script the copy_stage agent word runs, as root:
 *
 *   php utils/copy_stage.php --workspace /backups/restore_chain-… --vouched <sha256> [--vouched …] \
 *       [--profile manager] [--history chain-…,<sha256>,<bytes>,<unix time> …] <<'EOF'
 *   {"chain_id":"chain-20260830_010203",
 *    "manifest_url":"https://…signed…",
 *    "artifact_urls":{"files-0000.tar.gz.enc":"https://…"},
 *    "seq":3}
 *   EOF
 *
 * The workspace, the vouched manifest hashes and the source's other chains
 * come from the agent, which derives the first from the chain id and reads
 * the rest from the copy's own state directory, where copy_import wrote them
 * from the source's signed export. The links come from the management node,
 * which has no say in which are used. What the source signed for is recorded
 * in this machine's upload ledger (CopyStaging::record_in_ledger).
 *
 * Exits 0 on success, 1 on a transfer or integrity failure, 2 on a malformed
 * request.
 *
 * @version 1.1 - --profile and --history: the source's backups are recorded in this machine's upload ledger
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/CopyStaging.php'));

try {
	$request = CopyStaging::parse_request(array_slice($argv, 1), json_decode((string)stream_get_contents(STDIN), true));
	$staged = CopyStaging::stage($request, function ($what, $name) {
		echo ($what === 'fetching') ? 'fetching ' . $name . "\n" : $what . ': ' . $name . "\n";
	});
} catch (BackupStagingException $e) {
	fwrite(STDERR, 'STAGE_FAIL: ' . $e->getMessage() . "\n");
	echo "STAGE_RESULT=error\n";
	exit($e->getCode());
}

$fetched = $staged['fetched'];
echo 'Staged ' . $fetched . ' artifact' . ($fetched === 1 ? '' : 's') . ' of ' . $request['chain_id']
	. ' (' . BackupFetch::human($staged['bytes']) . ') in ' . $request['work']
	. ', against the manifest the source vouched for.' . "\n";
echo "STAGE_RESULT=ok\n";
echo 'STAGE_CHAIN=' . $request['chain_id'] . "\n";
echo 'STAGE_SEQ=' . $staged['seq'] . "\n";
echo 'STAGE_ARTIFACTS=' . $fetched . "\n";
echo 'STAGE_BYTES=' . $staged['bytes'] . "\n";
echo 'STAGE_WORKSPACE=' . $request['work'] . "\n";
exit(0);
