<?php
/**
 * BackupObjectRestoreLauncher — this site bringing its own offloaded files
 * back from its own backup storage.
 *
 * No agent, no management node. When the file store has lost an offloaded
 * file (CloudStoreInventory says which), a site with a backup target of its
 * own has a copy on that shelf: every offloaded file was stored there once,
 * encrypted under an epoch key, and the newest run's index names it. This is
 * the site side of the same restore a management node drives for a node it
 * backs up (FleetObjectRestore), with the one difference that matters: the
 * credential is here, so the loop needs no jobs — the index is read off the
 * shelf, the survey runs in this process, and each page of objects is fetched
 * by links this machine signs for itself and opens with its own key
 * (BackupObjectRestore does the checking, decrypting, placing and recording).
 *
 * Two ways to run it. The Backups page and the cloud-storage page start it in
 * the background the way Verify starts a verify (start() → detach); a shell
 * runs utils/bring_back_objects.php. Either way what it did lands on the
 * inventory record (CloudStoreInventory::note_bring_back) where both pages
 * read it, and the names it brought home leave the missing list at once.
 *
 * `missing` mode (the pages' button) touches only what the file bucket cannot
 * serve; `all` brings every offloaded file home. Nothing already on disk is
 * overwritten and nothing in any bucket is deleted; a run that stops half way
 * is finished by running it again. One at a time: a second start while one is
 * running is refused by the lock.
 *
 * No key and no credential is printed or written anywhere by this class.
 *
 * @version 1.0.1 - in_progress() is the one rule for "one is running" (started, not reported, under
 *                  STALE_SECONDS), shared by start_newest() and the panel, so a run that died without
 *                  reporting stops hiding the button when it stops blocking a start; a run refused by
 *                  the lock leaves the record to the run that holds it
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjectRestore.php'));
require_once(PathHelper::getIncludePath('includes/BackupStaging.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifyLauncher.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStoreInventory.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

class BackupObjectRestoreLauncherException extends Exception {}
/** The lock is held: another run owns the record. */
class BackupObjectRestoreLockedException extends BackupObjectRestoreLauncherException {}

class BackupObjectRestoreLauncher {

	/**
	 * How long a page's signed links live. Links are signed per page, just
	 * before the page is fetched, so this bounds one page's fetch, not the
	 * whole run.
	 */
	const LINK_SECONDS = 3600;

	/** Names per page: the same slice a management node hands a node. */
	const PAGE = BackupObjectRestore::PAGE_MAX;

	/** The lock that makes it one at a time, in the profile's objects directory. */
	const LOCK_NAME = 'bring-back.lock';

	/**
	 * A run that started this long ago and never reported is taken as dead: the
	 * page offers the button again and a start is accepted. A live run holds
	 * the lock, so a second one started by mistake is refused there.
	 */
	const STALE_SECONDS = 21600;

	/** Tests only: the site plan to use instead of BackupRunner::plan(). */
	public static $plan_for_tests = null;

	/**
	 * Is a Bring them back running, by the record's word: started, not yet
	 * reported, and not so long ago that it must have died. Pure.
	 */
	public static function in_progress(?array $bb, $now_ts = null) {
		if (!is_array($bb) || empty($bb['started']) || !empty($bb['finished'])) {
			return false;
		}
		$started = strtotime((string)$bb['started'] . ' UTC');
		return $started !== false && (($now_ts ?? time()) - $started) < self::STALE_SECONDS;
	}

	/** The newest backup of this site's own that a restore can read from. */
	public static function newest_run() {
		return BackupVerifyLauncher::newest_run();
	}

	/**
	 * The request the background run takes: which run, which mode. Pinned to
	 * a run rather than "the newest" so what the page offered is what runs.
	 *
	 * @throws BackupObjectRestoreLauncherException
	 */
	public static function request(BackupHistory $run, $mode) {
		if (!BackupObjectRestore::is_mode($mode)) {
			throw new BackupObjectRestoreLauncherException('Bringing offloaded files home is either "missing" (only what the file store cannot serve) or "all".');
		}
		$chain_id = trim((string)$run->get('bkh_chain_id'));
		if (!preg_match(BackupStaging::CHAIN_ID_PATTERN, $chain_id)) {
			throw new BackupObjectRestoreLauncherException('That backup is not part of a set this site can read offloaded files from.');
		}
		return array(
			'chain_id' => $chain_id,
			'seq'      => (int)$run->get('bkh_chain_seq'),
			'mode'     => (string)$mode,
			'when'     => (string)$run->get('bkh_start_time'),
		);
	}

	/**
	 * Start a Bring them back in the background, from the newest run. The
	 * request crosses on stdin to a detached process (BackupVerifyLauncher::
	 * detach), and the inventory record says it is running until it reports.
	 *
	 * @return string the sentence the page shows
	 * @throws BackupObjectRestoreLauncherException
	 */
	public static function start_newest($mode = BackupObjectRestore::MODE_MISSING) {
		$run = self::newest_run();
		if ($run === null) {
			throw new BackupObjectRestoreLauncherException('This site has no backup of its own to bring offloaded files back from.');
		}
		$request = self::request($run, $mode);
		$current = CloudStoreInventory::read()['bring_back'] ?? null;
		if (self::in_progress($current)) {
			throw new BackupObjectRestoreLauncherException('Offloaded files are already being brought back (started '
				. BackupVerifier::when_words((string)$current['started']) . '). Wait for it to finish.');
		}
		CloudStoreInventory::note_bring_back(array(
			'started' => gmdate('Y-m-d H:i:s'), 'finished' => null, 'mode' => $request['mode'],
			'run' => $request['chain_id'] . '/' . $request['seq'], 'result' => null, 'reason' => '',
			'restored' => 0, 'kept' => 0, 'skipped' => 0, 'bytes' => 0, 'wanted' => 0, 'by' => 'this site',
		));
		try {
			BackupVerifyLauncher::detach(PathHelper::getIncludePath('utils/bring_back_objects.php'), json_encode($request));
		} catch (BackupVerifyLauncherException $e) {
			CloudStoreInventory::note_bring_back(array('finished' => gmdate('Y-m-d H:i:s'), 'result' => BackupObjectRestore::RESULT_FAIL,
				'reason' => 'could not start the background process'));
			throw new BackupObjectRestoreLauncherException('Could not start bringing the files back.');
		}
		return 'Bringing the missing offloaded files back from the backup of ' . BackupVerifier::when_words($request['when'])
			. ' in the background. This page says what came home when it is done.';
	}

	/**
	 * The whole run, in this process: read the run's index from backup storage,
	 * survey, then page by page sign links, fetch, check, decrypt, place and
	 * record. The outcome is written to the inventory record whatever happens
	 * and returned in the shape BackupObjectRestore::format_contract() prints.
	 *
	 * @param array         $request  request()
	 * @param callable|null $progress fn(string $what, string $name, string $detail)
	 * @return array
	 */
	public static function run(array $request, ?callable $progress = null) {
		$t0 = microtime(true);
		$mode = (string)($request['mode'] ?? BackupObjectRestore::MODE_MISSING);
		$chain_id = trim((string)($request['chain_id'] ?? ''));
		$seq = (int)($request['seq'] ?? 0);
		$base_result = array('mode' => $mode, 'run' => $chain_id . '/' . $seq);
		$done = array();
		$lock = null;
		$work = '';
		$mine = true;   // false once the lock says another run owns the record
		// The record says one is running from here on — unless the page's
		// start() already said so a moment ago, whose start time stands. A
		// mark left by a run that died is not that.
		$current = CloudStoreInventory::read()['bring_back'] ?? null;
		if (!self::in_progress($current)) {
			CloudStoreInventory::note_bring_back(array('started' => gmdate('Y-m-d H:i:s'), 'finished' => null, 'mode' => $mode,
				'run' => $base_result['run'], 'result' => null, 'reason' => '', 'restored' => 0, 'kept' => 0, 'skipped' => 0,
				'bytes' => 0, 'wanted' => 0, 'by' => 'this site'));
		}
		try {
			if (!BackupObjectRestore::is_mode($mode)) {
				throw new BackupObjectRestoreLauncherException('mode must be missing or all');
			}
			if (!preg_match(BackupStaging::CHAIN_ID_PATTERN, $chain_id) || $seq < 0 || $seq > BackupStaging::MAX_SEQ) {
				throw new BackupObjectRestoreLauncherException('the request names no run this site can read');
			}
			$plan = self::$plan_for_tests ?? BackupRunner::plan(array('profile' => BackupProfile::SITE));
			list($creds, $bucket, $base) = BackupObjects::destination($plan);

			$lock = self::take_lock($plan);
			// Holding the lock, any bring-back-* left in tmp/ belongs to a run
			// that died; the working directory is private to a run.
			foreach (glob(rtrim(BackupObjects::tmp_dir($plan), '/') . '/bring-back-*', GLOB_ONLYDIR) ?: array() as $stale) {
				BackupVerifier::remove_tree($stale);
			}

			$index_name = BackupChain::artifact_name('objects', $seq);
			$index = BackupObjects::fetch_index_key($plan, $base . $chain_id . '/' . $index_name);
			if ($index === null) {
				throw new BackupObjectRestoreLauncherException('run ' . $seq . ' of ' . $chain_id
					. ' has no offloaded-files index in backup storage, so nothing can be brought back from it');
			}
			$base_result['run'] = (string)($index['run'] ?? $base_result['run']);
			$stored = count(BackupObjects::index_entries($index));
			$base_result['indexed'] = $stored;
			$base_result['not_stored'] = count($index['objects'] ?? array()) - $stored;

			// The whole picture, uncapped: this loop has no job body to fit.
			$survey = BackupObjectRestore::survey($index, $mode, PHP_INT_MAX, PHP_INT_MAX);
			$base_result['wanted'] = (int)$survey['wanted'];
			$base_result['epochs'] = $survey['epochs'];
			$settled = (int)$survey['served'] + (int)$survey['local'] + (int)$survey['no_row'];
			CloudStoreInventory::note_bring_back(array('wanted' => (int)$survey['wanted']));

			$counts = array('restored' => 0, 'bytes' => 0, 'kept' => 0, 'skipped' => 0);
			if ($survey['want']) {
				$work = rtrim(BackupObjects::tmp_dir($plan), '/') . '/bring-back-' . getmypid();
				BackupStaging::prepare_workspace($work);
				$sign = function ($relname) use ($creds, $bucket, $base) {
					return S3Signer::presign_get($creds, $bucket, '/' . ltrim($base . $relname, '/'), self::LINK_SECONDS);
				};
				$entries = BackupObjects::index_entries($index);
				$keys = array();
				$note = function ($what, $name, $detail = '') use (&$done, $progress) {
					if ($what === 'restored' || $what === 'kept') { $done[] = $name; }
					if ($progress) { $progress($what, $name, $detail); }
				};
				foreach (array_chunk($survey['want'], self::PAGE) as $names) {
					$page = BackupObjectRestore::subset($index, $names);
					// Envelopes once per epoch across the run, not once per page.
					$need = array();
					foreach ($page['objects'] as $e) {
						$epoch = (string)$e['epoch'];
						if (!isset($keys[$epoch])) { $need[$epoch] = $sign(BackupObjects::envelope_relname($epoch)); }
					}
					if ($need) {
						$envelopes = BackupStaging::fetch_envelopes($work, $page, $need, $note);
						$keys += BackupObjectRestore::epoch_keys($page, function ($epoch) use ($envelopes) {
							return (string)($envelopes['envelopes'][$epoch] ?? '');
						});
					}
					$links = array();
					foreach ($names as $name) {
						$links[$name] = $sign(BackupObjects::object_relname((string)$entries[$name]['epoch'], $name));
					}
					$r = BackupObjectRestore::restore($index, $names, $mode, $keys,
						BackupObjectRestore::link_source($work, $links, $note), $note);
					foreach ($counts as $k => $v) { $counts[$k] += (int)$r[$k]; }
					CloudStoreInventory::note_bring_back(array('restored' => $counts['restored'], 'kept' => $counts['kept'],
						'bytes' => $counts['bytes']));
				}
			}
			$result = array_merge($base_result, $counts, array(
				'result'   => BackupObjectRestore::RESULT_OK,
				'skipped'  => $counts['skipped'] + $settled,
				'duration' => (int)round(microtime(true) - $t0),
			));
		} catch (BackupObjectRestoreLockedException $e) {
			// Another run holds the lock and is writing the record; this one
			// reports its refusal to its caller only.
			$mine = false;
			$result = array_merge($base_result, array(
				'result'   => BackupObjectRestore::RESULT_FAIL,
				'reason'   => $e->getMessage(),
				'duration' => (int)round(microtime(true) - $t0),
			));
		} catch (\Throwable $e) {
			$result = array_merge($base_result, array(
				'result'   => BackupObjectRestore::RESULT_FAIL,
				'reason'   => $e->getMessage(),
				'duration' => (int)round(microtime(true) - $t0),
			));
		} finally {
			if ($work !== '') { BackupVerifier::remove_tree($work); }
			if ($lock !== null) { self::release_lock($lock); }
		}

		if (!$mine) {
			return $result;
		}
		CloudStoreInventory::forget_missing($done);
		CloudStoreInventory::note_bring_back(array(
			'finished' => gmdate('Y-m-d H:i:s'),
			'result'   => $result['result'],
			'reason'   => (string)($result['reason'] ?? ''),
			'restored' => (int)($result['restored'] ?? 0),
			'kept'     => (int)($result['kept'] ?? 0),
			'skipped'  => (int)($result['skipped'] ?? 0),
			'bytes'    => (int)($result['bytes'] ?? 0),
			'wanted'   => (int)($result['wanted'] ?? 0),
			'run'      => (string)$result['run'],
			'mode'     => $mode,
		));
		return $result;
	}

	/** One at a time. Returns the open lock handle. */
	private static function take_lock(array $plan) {
		$path = rtrim(BackupObjects::dir($plan), '/') . '/' . self::LOCK_NAME;
		$fh = @fopen($path, 'c');
		if (!$fh) {
			throw new BackupObjectRestoreLauncherException('could not open the lock at ' . $path);
		}
		if (!flock($fh, LOCK_EX | LOCK_NB)) {
			fclose($fh);
			throw new BackupObjectRestoreLockedException('offloaded files are already being brought back by another process');
		}
		@chmod($path, 0664);
		return $fh;
	}

	private static function release_lock($fh) {
		@flock($fh, LOCK_UN);
		@fclose($fh);
	}

	/**
	 * The last Bring them back, in words, for a page. '' when there has never
	 * been one. Pure over the record's bring_back slot.
	 */
	public static function describe_last(?array $bb) {
		if (!is_array($bb) || empty($bb['started'])) {
			return '';
		}
		if (empty($bb['finished']) && !self::in_progress($bb)) {
			return 'A Bring them back started ' . BackupVerifier::when_words((string)$bb['started'])
				. ' never reported back' . ((int)($bb['restored'] ?? 0) > 0 ? ' (' . number_format((int)$bb['restored']) . ' home by then)' : '')
				. '; run it again to finish what it left.';
		}
		if (empty($bb['finished'])) {
			$n = (int)($bb['restored'] ?? 0);
			return 'Bringing offloaded files back now (started ' . BackupVerifier::when_words((string)$bb['started']) . ')'
				. ($n > 0 ? ': ' . number_format($n) . ' home so far.' : '.');
		}
		$r = array(
			'result' => $bb['result'] ?? BackupObjectRestore::RESULT_FAIL, 'reason' => $bb['reason'] ?? '',
			'restored' => (int)($bb['restored'] ?? 0), 'bytes' => (int)($bb['bytes'] ?? 0), 'kept' => (int)($bb['kept'] ?? 0),
			'skipped' => (int)($bb['skipped'] ?? 0),
		);
		return 'Last brought back ' . BackupVerifier::when_words((string)$bb['finished'])
			. (!empty($bb['by']) && $bb['by'] !== 'this site' ? ' by ' . $bb['by'] : '')
			. ': ' . BackupObjectRestore::describe($r);
	}
}
