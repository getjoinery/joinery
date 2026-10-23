<?php
/**
 * RunMailExports - Scheduled Task
 *
 * Builds Standard mailbox exports (MailExportRun), one bounded slice of one run
 * per pass, the way RunMailImports advances imports: an export of years of
 * mail is many passes, and no pass holds the cron runner for long. Each pass
 * also tells the members whose downloads became ready (MailExport::announceReady).
 *
 * A Private export is never built here. Its mail opens only in its owner's
 * window, which a cron process cannot hold, so the vault's deferred work
 * builds it (the mailbox_export consumer in includes/bootstrap.php).
 *
 * Overlap guard: a run is claimed with an atomic conditional UPDATE
 * (MailExport::claim), and a claim gone stale is picked up again. A build
 * records its progress only after each piece is on disk, so a killed pass
 * costs that piece and nothing more.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class RunMailExports implements ScheduledTaskInterface {

	/** Wall-clock seconds one pass builds for. */
	const DEFAULT_SLICE_SECONDS = 120;

	public function run(array $config) {
		$seconds = intval($config['slice_seconds'] ?? 0);
		if ($seconds <= 0) {
			$seconds = self::DEFAULT_SLICE_SECONDS;
		}
		// Whoever's download became ready since the last pass hears of it here,
		// Private ones included: their build ran in the owner's window, in a
		// request that writes nothing long outside a seal.
		$told = MailExport::announceReady();
		$run = MailExport::claim(MailExportRun::LEVEL_STANDARD, null);
		if ($run === null) {
			return array('status' => 'success', 'message' => $told
				? $told . ' member(s) told their download is ready.' : 'No mail exports are waiting.');
		}
		try {
			$r = MailExport::build($run, microtime(true) + $seconds);
			$run->load();
			if ($r['finished']) {
				MailExport::announceReady();
			}
			return array('status' => 'success', 'message' => 'Export run #' . $run->key
				. ($r['finished'] ? ' is ready: ' : ': ') . intval($run->get('mxr_done')) . '/'
				. intval($run->get('mxr_total')) . ' message(s) written, ' . intval($run->get('mxr_left_out'))
				. ' left out.');
		} catch (MailExportCancelled $e) {
			return array('status' => 'success', 'message' => 'Export run #' . $run->key . ' was cancelled by its member.');
		} catch (Throwable $e) {
			$run->moveTo(MailExportRun::STATE_FAILED, $e->getMessage());
			error_log('RunMailExports: run ' . $run->key . ' failed: ' . $e->getMessage());
			return array('status' => 'success', 'message' => 'Export run #' . $run->key . ' failed: ' . $e->getMessage());
		} finally {
			MailExport::release(intval($run->key));
		}
	}
}
?>
