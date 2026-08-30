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
 * @version 1.0
 */

// Reject non-CLI access
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	echo 'CLI access only.';
	exit(1);
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupFetch.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
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
if (!is_array($config)) {
	stage_chain_refuse('this run needs its configuration as JSON on stdin');
}

$required = array('chain_id', 'profile', 'manifest_url', 'artifact_urls');
$accepted = array_merge($required, array('seq'));
$unknown = array_diff(array_keys($config), $accepted);
if ($unknown) {
	sort($unknown);
	stage_chain_refuse('configuration carries unrecognised key(s): ' . implode(', ', $unknown));
}

$chain_id = trim((string)($config['chain_id'] ?? ''));
// The pattern BackupChain mints, and the one the agent and the management node
// both bind this parameter to. It becomes a DIRECTORY NAME below, so no
// separator and no dot is what keeps the workspace inside the backup base.
if (!preg_match('/^chain-[0-9_]+$/', $chain_id)) {
	stage_chain_refuse('that is not a chain id');
}
if (!BackupFetch::is_signed_url((string)($config['manifest_url'] ?? ''))) {
	stage_chain_refuse("'manifest_url' must be an https URL");
}
if (!is_array($config['artifact_urls'] ?? null) || !$config['artifact_urls']) {
	stage_chain_refuse("'artifact_urls' must be a map of artifact name to signed URL");
}

$profile      = BackupProfile::normalize((string)$config['profile']);
$manifest_url = trim((string)$config['manifest_url']);
$seq          = (isset($config['seq']) && $config['seq'] !== '' && $config['seq'] !== null)
	? (int)$config['seq'] : null;

// Bare names only. A key with a separator in it would be the caller naming a
// path again, through the one map it is allowed to send.
$artifact_urls = array();
foreach ($config['artifact_urls'] as $name => $url) {
	$name = (string)$name;
	if ($name !== basename($name) || $name === '' || $name === '.' || $name === '..') {
		stage_chain_refuse('an artifact link is keyed by a path rather than a name');
	}
	if (!BackupFetch::is_signed_url((string)$url)) {
		stage_chain_refuse('the link for ' . $name . ' is not an https URL');
	}
	$artifact_urls[$name] = (string)$url;
}
unset($config);

if ($seq !== null && ($seq < 0 || $seq > 100000)) {
	stage_chain_refuse('a chain run number must be between 0 and 100000');
}

// ── The workspace ───────────────────────────────────────────────────────────
// restore_<chain_id> under the node's own backup BASE — not under the profile's
// directory. That is where the SSH path put it and where the restore_chain
// primitive looks for it; the two must agree or the restore refuses with
// "no downloaded chain" beside a chain that is downloaded.
$work = rtrim(BackupRunner::output_dir(), '/') . '/restore_' . $chain_id;
if (!is_dir($work) && !@mkdir($work, 0700, true)) {
	stage_chain_refuse('could not make the restore workspace at ' . $work);
}
@chmod($work, 0700);

// ── The manifest ────────────────────────────────────────────────────────────
// First, because it names every artifact with the size and hash each must
// match, and carries the sealed data keys. A directory full of
// files-0003.tar.gz.enc without it is not a backup.
//
// Ledger-checked like everything else, and this one carries the most weight: the
// manifest's own hashes are only as trustworthy as the manifest, so a manifest
// served by a management node that had been compromised could bless whatever
// artifacts it liked. The ledger entry was written by this machine at upload
// time, which the management node has never been able to reach.
$got = BackupFetch::fetch_artifact(
	$profile, $work, $chain_id . '/' . BackupChain::MANIFEST_NAME, BackupChain::MANIFEST_NAME, $manifest_url);
if (!$got['ok']) {
	fwrite(STDERR, 'STAGE_FAIL: could not bring back the chain manifest: ' . $got['error'] . "\n");
	echo "STAGE_RESULT=error\n";
	exit(1);
}

try {
	$manifest = BackupChain::read($work . '/' . BackupChain::MANIFEST_NAME);
	$plan     = BackupChain::restore_plan($manifest, $seq);
} catch (Exception $e) {
	fwrite(STDERR, 'STAGE_FAIL: ' . $e->getMessage() . "\n");
	echo "STAGE_RESULT=error\n";
	exit(1);
}

if ((string)($manifest['chain_id'] ?? '') !== $chain_id) {
	fwrite(STDERR, 'STAGE_FAIL: the manifest at ' . $work . ' is for chain '
		. (string)($manifest['chain_id'] ?? '(unnamed)') . ', not ' . $chain_id . "\n");
	echo "STAGE_RESULT=error\n";
	exit(1);
}

// ── The chain data key ──────────────────────────────────────────────────────
// Recovered here, from this machine's own site key. Every chain seals to the
// node as well as to the management node's recovery key precisely so that a node
// can open its own backups without anybody's private key travelling.
$key_path = $work . '/' . 'chain.key';
try {
	if (empty($manifest['envelope']) || !is_array($manifest['envelope'])) {
		throw new BackupEnvelopeException('This chain manifest carries no envelope, so there is no data key to recover.');
	}
	$data_key = BackupEnvelope::open_as_site($manifest['envelope']);
} catch (Exception $e) {
	fwrite(STDERR, 'STAGE_FAIL: the chain envelope did not open with this machine\'s own backup key — '
		. 'this chain was taken by a different machine. ' . $e->getMessage() . "\n");
	fwrite(STDERR, "Restore it from a shell with the recovery key: backup_envelope.php open "
		. "--sidecar manifest.json --private <recovery key>\n");
	echo "STAGE_RESULT=error\n";
	exit(1);
}

// Written the way BackupRunner writes a run key: restricted before content, so
// there is no window in which a usable decryption key is readable by anything
// else on the machine.
$old_umask = umask(0077);
$wrote = @file_put_contents($key_path, $data_key);
umask($old_umask);
sodium_memzero($data_key);
if ($wrote === false) {
	fwrite(STDERR, "STAGE_FAIL: could not write the recovered chain key\n");
	echo "STAGE_RESULT=error\n";
	exit(1);
}
@chmod($key_path, 0600);

// ── The artifacts the manifest names ────────────────────────────────────────
// Exactly these, in this order: the full and every incremental up to the chosen
// run, then that run's database dump and metadata. The list comes from
// BackupChain::restore_plan, so the caller has no say in it.
$wanted = array();
foreach ($plan['files'] as $a) {
	$wanted[] = (string)$a['name'];
}
foreach (array('db', 'meta') as $kind) {
	if (!empty($plan[$kind]['name'])) {
		$wanted[] = (string)$plan[$kind]['name'];
	}
}

$fetched = 0;
$bytes   = 0;
foreach ($wanted as $name) {
	if (!isset($artifact_urls[$name])) {
		fwrite(STDERR, 'STAGE_FAIL: no download link was supplied for ' . $name
			. ', which this chain\'s manifest says run ' . $plan['seq'] . " needs\n");
		echo "STAGE_RESULT=error\n";
		exit(1);
	}
	// Already here and already correct: a resumed staging should not re-pull
	// gigabytes it has. Checked against the ledger, not merely present.
	$local = $work . '/' . $name;
	if (is_file($local)) {
		$check = BackupLedger::verify($profile, $chain_id . '/' . $name, $local);
		if ($check['ok']) {
			echo 'already staged: ' . $name . "\n";
			$fetched++;
			$bytes += (int)@filesize($local);
			continue;
		}
		@unlink($local);
	}

	echo 'fetching ' . $name . "\n";
	$got = BackupFetch::fetch_artifact($profile, $work, $chain_id . '/' . $name, $name, $artifact_urls[$name]);
	if (!$got['ok']) {
		fwrite(STDERR, 'STAGE_FAIL: ' . $got['error'] . "\n");
		echo "STAGE_RESULT=error\n";
		exit(1);
	}
	$fetched++;
	$bytes += (int)$got['bytes'];
}

echo 'Staged ' . $fetched . ' artifact' . ($fetched === 1 ? '' : 's') . ' of ' . $chain_id
	. ' (' . BackupFetch::human($bytes) . ') in ' . $work . ', with the chain key recovered from this machine\'s own key.' . "\n";
echo "STAGE_RESULT=ok\n";
echo 'STAGE_CHAIN=' . $chain_id . "\n";
echo 'STAGE_SEQ=' . (int)$plan['seq'] . "\n";
echo 'STAGE_ARTIFACTS=' . $fetched . "\n";
echo 'STAGE_BYTES=' . $bytes . "\n";
echo 'STAGE_WORKSPACE=' . $work . "\n";
exit(0);
