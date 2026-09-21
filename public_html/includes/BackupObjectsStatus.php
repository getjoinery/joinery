<?php
/**
 * BackupObjectsStatus — the figures the Backups page, the cloud-storage page
 * and the admin notice show about offloaded files and their place on the
 * backup shelf (specs/backup_offloaded_files.md § Admin surfaces).
 *
 * A file the site moved to its cloud file store is in no archive; each is
 * copied to the backup shelf once, and its local bytes stay on this server
 * until every backup that stores offloaded files holds it. From that, four
 * figures a person asks for:
 *
 *   on the shelf   what each enabled backup holds — N objects, X GB — and
 *                  the run that last indexed them (held.json, history)
 *   waiting        offloaded files whose local copy is still here because
 *                  some enabled backup lacks them: "M files (X GB) waiting
 *                  for the management node's backup before their local copy
 *                  is released" — one stat per cloud row on each page load;
 *                  no column, no cache, milliseconds at ten thousand rows
 *   still to copy  offloaded files with no local bytes that an enabled backup
 *                  lacks: they come back down from the file store, one at a
 *                  time inside the run's budget, until this reaches zero
 *   same account   the file store and this site's backup target share an
 *                  access key, so losing that account loses both
 *
 * Everything is read from this machine: the file-blob table, the two upload
 * directories, held.json per profile, this site's history and two settings.
 * Nothing here opens a network connection, so a page and the notice on every
 * admin page can afford it. Pure over what it reads: the same facts give the
 * same figures, and the test hooks hand the facts in.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));
require_once(PathHelper::getIncludePath('includes/BackupVerifier.php'));
require_once(PathHelper::getIncludePath('data/backup_history_class.php'));

class BackupObjectsStatus {

	/**
	 * Tests only. Any of:
	 *   rows     callable() → [['name', 'size', 'paths' => [local paths the original may sit at]]]
	 *   enabled  the enabled profiles, as BackupProfile::enabled() would answer
	 *   held     profile => (name => entry) | null, as BackupObjects::held_sets() would answer
	 *   runs     profile => ['indexed' => ['time' => utc, 'label' => string] | null, 'last_success' => utc | null]
	 *   keys     ['store' => access key, 'target' => access key | null]
	 */
	public static $test_hooks = array();

	/** The whole picture. Never throws: a fact that cannot be read counts as absent. */
	public static function compute() {
		$enabled = isset(self::$test_hooks['enabled']) ? (array)self::$test_hooks['enabled'] : self::enabled();
		$held    = isset(self::$test_hooks['held']) ? (array)self::$test_hooks['held'] : self::held($enabled);
		$runs    = isset(self::$test_hooks['runs']) ? (array)self::$test_hooks['runs'] : self::runs($enabled);
		$keys    = isset(self::$test_hooks['keys']) ? (array)self::$test_hooks['keys'] : self::keys();
		$rows    = isset(self::$test_hooks['rows']) ? (array)call_user_func(self::$test_hooks['rows']) : self::rows();
		return self::figures($rows, $enabled, $held, $runs, $keys);
	}

	/**
	 * The figures from the facts. Pure but for one stat per row: a cloud row
	 * whose original is still on disk is a local copy waiting to be released.
	 */
	public static function figures(array $rows, array $enabled, array $held, array $runs, array $keys) {
		$zero = array('count' => 0, 'bytes' => 0);
		$out = array(
			'total'        => $zero,
			'enabled'      => array_values($enabled),
			'shelf'        => array(),
			'waiting'      => $zero + array('for' => array()),
			'releasable'   => $zero,
			'catchup'      => $zero,
			'same_account' => self::same_account((string)($keys['store'] ?? ''), $keys['target'] ?? null),
			'last_success' => array(),
		);
		foreach ($enabled as $profile) {
			$set = $held[$profile] ?? null;
			$bytes = 0;
			foreach ((array)$set as $e) { $bytes += (int)($e['object_bytes'] ?? 0); }
			$out['shelf'][$profile] = array(
				'count'   => is_array($set) ? count($set) : 0,
				'bytes'   => $bytes,
				'known'   => is_array($set),
				'indexed' => $runs[$profile]['indexed'] ?? null,
			);
			$out['waiting']['for'][$profile] = $zero;
			$out['last_success'][$profile] = $runs[$profile]['last_success'] ?? null;
		}

		foreach ($rows as $row) {
			$name = (string)($row['name'] ?? '');
			if ($name === '') { continue; }
			$size = (int)($row['size'] ?? 0);
			$out['total']['count']++;
			$out['total']['bytes'] += $size;

			$on_disk = null;
			foreach ((array)($row['paths'] ?? array()) as $p) {
				if (is_file($p)) { $on_disk = (int)@filesize($p); break; }
			}

			$lacking = array();
			foreach ($enabled as $profile) {
				$set = $held[$profile] ?? null;
				if (!is_array($set) || !isset($set[$name])) { $lacking[] = $profile; }
			}

			if ($on_disk !== null) {
				if ($lacking) {
					$out['waiting']['count']++;
					$out['waiting']['bytes'] += $on_disk;
					foreach ($lacking as $profile) {
						$out['waiting']['for'][$profile]['count']++;
						$out['waiting']['for'][$profile]['bytes'] += $on_disk;
					}
				} else {
					$out['releasable']['count']++;
					$out['releasable']['bytes'] += $on_disk;
				}
			} elseif ($lacking) {
				$out['catchup']['count']++;
				$out['catchup']['bytes'] += $size;
			}
		}
		return $out;
	}

	/**
	 * The same-key test is the whole rule: accounts are not detectable, keys
	 * are. A blank on either side is not a match. Pure.
	 */
	public static function same_account($store_key, $target_key) {
		$store_key = trim((string)$store_key);
		$target_key = trim((string)$target_key);
		return $store_key !== '' && $target_key !== '' && hash_equals($store_key, $target_key);
	}

	// ------------------------------------------------------------ sentences

	const SAME_ACCOUNT_LINE = 'Your backup shelf and your file store are on the same account. Losing that account loses both. '
		. 'A copy taken by a management node is the one that survives it.';

	/** The same-account warning, or '' when the keys differ. */
	public static function same_account_line(array $status) {
		return !empty($status['same_account']) ? self::SAME_ACCOUNT_LINE : '';
	}

	/** "this site's backup", "the management node's backup", or both joined. */
	public static function backup_words(array $profiles) {
		$words = array();
		foreach ($profiles as $p) {
			$words[] = ($p === BackupProfile::MANAGER) ? 'the management node\'s backup' : 'this site\'s backup';
		}
		return implode(' and ', $words);
	}

	/**
	 * "M files (X GB) waiting for the management node's backup before their
	 * local copy is released." — or '' when nothing waits.
	 */
	public static function waiting_sentence(array $status) {
		$w = $status['waiting'];
		if ((int)$w['count'] === 0) {
			return '';
		}
		$for = array();
		foreach (($w['for'] ?? array()) as $profile => $f) {
			if ((int)$f['count'] > 0) { $for[] = $profile; }
		}
		return self::files_words((int)$w['count'], (int)$w['bytes']) . ' waiting for ' . self::backup_words($for)
			. ' before ' . ((int)$w['count'] === 1 ? 'its' : 'their') . ' local copy is released.';
	}

	/** "N files (X GB) still to copy from the file store." — or '' when caught up. */
	public static function catchup_sentence(array $status) {
		$c = $status['catchup'];
		if ((int)$c['count'] === 0) {
			return '';
		}
		return self::files_words((int)$c['count'], (int)$c['bytes']) . ' still to copy from the file store.';
	}

	/**
	 * One line per enabled backup: "Offloaded files on the shelf (this site's
	 * backup): N objects, X GB; last indexed at the run of <when>."
	 */
	public static function shelf_sentences(array $status) {
		$out = array();
		foreach (($status['shelf'] ?? array()) as $profile => $s) {
			$line = 'Offloaded files on the shelf (' . self::backup_words(array($profile)) . '): ';
			if (empty($s['known'])) {
				$line .= 'none yet; the first run that stores them writes the record.';
			} else {
				$line .= number_format((int)$s['count']) . ' object' . ((int)$s['count'] === 1 ? '' : 's') . ', '
					. BackupRunner::human((int)$s['bytes']) . '; ';
				$line .= is_array($s['indexed'] ?? null)
					? 'last indexed at the run of ' . BackupVerifier::when_words((string)$s['indexed']['time']) . '.'
					: 'not indexed yet.';
			}
			$out[$profile] = $line;
		}
		return $out;
	}

	/** "N files (X GB)" */
	public static function files_words($count, $bytes) {
		return number_format((int)$count) . ' file' . ((int)$count === 1 ? '' : 's') . ' (' . BackupRunner::human((int)$bytes) . ')';
	}

	/** Is there anything for a page to show? Nothing offloaded means no box. */
	public static function has_content(array $status) {
		return (int)$status['total']['count'] > 0;
	}

	// ------------------------------------------------------------ the facts

	private static function enabled() {
		try {
			return BackupProfile::enabled();
		} catch (\Throwable $e) {
			return array();
		}
	}

	private static function held(array $enabled) {
		if (!$enabled) {
			return array();
		}
		try {
			return BackupObjects::held_sets($enabled, BackupRunner::output_dir());
		} catch (\Throwable $e) {
			return array();
		}
	}

	/**
	 * Per enabled profile: the newest successful offsite run that carries an
	 * objects index, and the newest successful offsite run at all.
	 */
	private static function runs(array $enabled) {
		$out = array();
		foreach ($enabled as $profile) {
			$out[$profile] = array('indexed' => null, 'last_success' => null);
			try {
				$rows = new MultiBackupHistory(
					array('outcome' => 'success', 'offsite' => true, 'deleted' => false, 'profile' => $profile),
					array('bkh_start_time' => 'DESC'), 50, 0);
				foreach ($rows as $r) {
					$when = (string)($r->get('bkh_finish_time') ?: $r->get('bkh_start_time'));
					if ($out[$profile]['last_success'] === null) {
						$out[$profile]['last_success'] = $when;
					}
					foreach ($r->artifacts() as $a) {
						if (($a['kind'] ?? '') === 'objects') {
							$out[$profile]['indexed'] = array('time' => $when, 'label' => (string)($a['name'] ?? ''));
							break 2;
						}
					}
				}
			} catch (\Throwable $e) {
				// No history to read: nothing indexed, nothing succeeded.
			}
		}
		return $out;
	}

	private static function keys() {
		$keys = array('store' => '', 'target' => null);
		try {
			$keys['store'] = (string)Globalvars::get_instance()->get_setting('cloud_storage_access_key');
			$target = BackupRunner::site_target();
			if ($target !== null) {
				$creds = $target->get_credentials();
				$keys['target'] = is_array($creds) ? (string)($creds['access_key'] ?? '') : null;
			}
		} catch (\Throwable $e) {
			// A target that cannot be read shares no key with anything.
		}
		return $keys;
	}

	/**
	 * Every cloud row with the local paths its original may occupy — its own
	 * visibility's directory first, the other one because a blob's bytes may
	 * sit in either during a visibility flip. One prepared statement; no model
	 * per row, because this runs on every admin page load.
	 */
	private static function rows() {
		$out = array();
		try {
			$settings       = Globalvars::get_instance();
			$restricted_dir = rtrim((string)$settings->get_setting('upload_dir'), '/');
			$fast_dir       = dirname($restricted_dir) . '/static_files/uploads';
			$db = DbConnector::get_instance()->get_db_link();
			$st = $db->prepare('SELECT fbb_stored_name, fbb_size_bytes, fbb_is_private FROM fbb_file_blobs WHERE fbb_storage_driver = ?');
			$st->execute(array('cloud'));
			while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
				$name = (string)$row['fbb_stored_name'];
				$private = in_array($row['fbb_is_private'], array(true, 't', 'true', 1, '1'), true);
				$own = $private ? $restricted_dir : $fast_dir;
				$other = $private ? $fast_dir : $restricted_dir;
				$out[] = array(
					'name'  => $name,
					'size'  => (int)$row['fbb_size_bytes'],
					'paths' => array($own . '/' . $name, $other . '/' . $name),
				);
			}
		} catch (\Throwable $e) {
			error_log('BackupObjectsStatus: could not list offloaded files: ' . $e->getMessage());
		}
		return $out;
	}
}
