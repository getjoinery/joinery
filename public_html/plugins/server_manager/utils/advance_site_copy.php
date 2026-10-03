<?php
/**
 * advance_site_copy.php — follow one site copy's local steps.
 *
 * A short-lived worker the Copy tab's presses start (SiteCopyRunner::
 * start_worker_for): while the copy's current step is one this management node
 * does itself (powering a server off or on, swapping addresses, asking the
 * copy's look path at the site's name), it advances the copy every few
 * seconds. It exits when the copy waits on an agent job (a node's posted result
 * moves it on), stops, or finishes, and after half an hour whatever happens: the
 * Advance Site Copies task carries anything left.
 *
 * Two workers for one copy are harmless: each advance takes the runner's lock,
 * and a second simply finds nothing to do.
 *
 * CLI only: a machine's reboot takes minutes, longer than a web request should.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit("This worker runs from the command line only.\n");
}

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));

$copy_id = (int)($argv[1] ?? 0);
if ($copy_id <= 0) {
	fwrite(STDERR, "usage: advance_site_copy.php <site copy id>\n");
	exit(2);
}

$say = function ($line) use ($copy_id) { echo gmdate('Y-m-d H:i:s'), " copy #{$copy_id}: ", $line, "\n"; flush(); };
$deadline = time() + 1800;
$say('worker started (pid ' . getmypid() . ')');
try {
	while (time() < $deadline) {
		$copy = new SiteCopy($copy_id, TRUE);
		if (!$copy->key || $copy->get('scp_delete_time')) {
			$say('the copy is gone');
			break;
		}
		SiteCopyRunner::advance($copy);
		$copy->load();
		if (!SiteCopyRunner::on_local_step($copy)) {
			$say('now ' . $copy->status() . '; nothing local to follow');
			break;
		}
		sleep(5);
	}
} catch (Throwable $e) {
	error_log('advance_site_copy: copy #' . $copy_id . ': ' . $e->getMessage());
	$say('error: ' . $e->getMessage());
	exit(1);
}
exit(0);
