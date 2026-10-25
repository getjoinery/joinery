<?php
/**
 * copy_live_source.php - what a source site's owner does on its own pages
 * during a copy, done as root on a TEST box the live test made
 * (site_copy_live_test.php, SiteCopyLiveRun). TEST SITES ONLY.
 *
 *   php copy_live_source.php /var/www/html/SITE recovery-key PUBLIC_B64
 *       sets the site's backup recovery public key; prints CHALLENGE=<the possession challenge>
 *   php copy_live_source.php /var/www/html/SITE prove PROOF_B64
 *       records what the challenge opened to; prints STATE=<the key's setup state>
 *   php copy_live_source.php /var/www/html/SITE export-pending
 *       prints PENDING=<the copy export waiting for approval, as JSON, or null>
 *   php copy_live_source.php /var/www/html/SITE export-answer JOB ANSWER_B64
 *       hands the agent what the export's challenge opened to; prints ANSWERED=JOB
 *   php copy_live_source.php /var/www/html/SITE marker-path FILE_ID
 *       prints PATH=<the public path of a seed's marker file>
 *
 * The recovery key's private half never comes here: the test opens each
 * challenge on its management node and sends back only what it opened to,
 * as the owner's browser does on the Backups page.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit(1);
}
$site = rtrim((string)($argv[1] ?? ''), '/');
$cmd = (string)($argv[2] ?? '');
if (!preg_match('#^/var/www/html/[A-Za-z0-9][A-Za-z0-9_-]*$#', $site) || !is_file($site . '/public_html/includes/PathHelper.php')) {
	fwrite(STDERR, "usage: php copy_live_source.php /var/www/html/SITE recovery-key|prove|export-pending|export-answer|marker-path ...\n");
	exit(2);
}
require_once($site . '/public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

$arg = function (int $i): string {
	global $argv;
	$v = (string)($argv[$i] ?? '');
	if ($v === '') {
		fwrite(STDERR, "copy_live_source: argument {$i} is missing\n");
		exit(2);
	}
	return $v;
};

try {
	switch ($cmd) {
		case 'recovery-key':
			BackupRecoveryKey::set_public_key($arg(3));
			echo 'CHALLENGE=' . BackupRecoveryKey::possession_challenge() . "\n";
			break;
		case 'prove':
			BackupRecoveryKey::record_possession_proof((string)base64_decode($arg(3), true));
			echo 'STATE=' . BackupRecoveryKey::setup_state()['state'] . "\n";
			break;
		case 'export-pending':
			echo 'PENDING=' . json_encode(ApprovalChallenge::pending(ApprovalChallenge::EXPORT), JSON_UNESCAPED_SLASHES) . "\n";
			break;
		case 'export-answer':
			ApprovalChallenge::answer(ApprovalChallenge::EXPORT, (int)$arg(3), (string)base64_decode($arg(4), true));
			echo 'ANSWERED=' . (int)$arg(3) . "\n";
			break;
		case 'marker-path':
			$file = new File((int)$arg(3), TRUE);
			if (!$file->key) {
				throw new Exception('no file ' . (int)$arg(3));
			}
			echo 'PATH=/uploads/' . $file->get('fil_name') . "\n";
			break;
		default:
			fwrite(STDERR, "copy_live_source: unknown command {$cmd}\n");
			exit(2);
	}
} catch (Throwable $e) {
	fwrite(STDERR, 'copy_live_source: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
	exit(1);
}
