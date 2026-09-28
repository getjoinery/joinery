<?php
/**
 * SiteCensus — a count of what a site holds, and a check of two counts.
 *
 * What it is for: a copy of a site on another machine is only as good as the
 * evidence that nothing was left behind. The census counts, on one machine:
 *
 *   - the rows in every table;
 *   - the files, directories and bytes under each top-level directory of the
 *     site, with a digest of every path and size;
 *   - whether the key canary opens, and how many registered sealed secrets
 *     are stored and how many of them are dead;
 *   - how many files are offloaded to the file bucket, and whether a sample of
 *     them answers from it (a metadata request each, never a download).
 *
 * Run on the source and on the copy, compare() says what differs
 * (specs/site_copy.md, step 5). The same check verifies any restore: a site
 * brought back from its backups onto a new machine has the census of the one
 * it came from.
 *
 * It reads and never writes: no row, no file, no cached verdict. The sealed
 * secrets are counted by SecretReconciler::census(), the reconciler's read-only
 * pass. Run it as root: a quiet site's firewall refuses the web user's
 * requests to the bucket, and a directory it cannot read fails the census.
 *
 * WHAT IT LEAVES OUT (the one exclusion list). A copy carries what the backup
 * chain carries, so the census counts exactly that, and leaves out what each
 * machine keeps as its own:
 *
 *   - the names the files engine never archives, at any depth
 *     (backup_files.sh NAMED_EXCLUDES; site_census_test pins the two lists
 *     equal), and the site's own backup_exclude names;
 *   - public_html_* beside the code, and uploads/upgrades (the upgrade's
 *     trees);
 *   - offloaded files' local copies: they live in the bucket, and the archive
 *     leaves them out (BackupObjects::exclude_lines);
 *   - each machine's own config/Globalvars_site.php, config/backup_site_key and
 *     config/backup-ledger;
 *   - the rows a backup run writes on the source after its dump
 *     (bkh_backup_history).
 *
 * @version 1.0
 */
class SiteCensus {

	/** The census format. compare() refuses two censuses of different formats. */
	const VERSION = 1;

	/**
	 * Names no archive carries, at any depth: backup_files.sh's NAMED_EXCLUDES.
	 * The census counts what a copy receives, so the two lists are one list.
	 */
	const NAMED_EXCLUDES = array('backups', 'vendor', 'node_modules', 'target', '.git', 'logs', 'cache', 'tmp', 'sessions');

	/** Paths, relative to the site root, each machine keeps as its own. */
	const OWN_PATHS = array('config/Globalvars_site.php', 'config/backup_site_key', 'config/backup-ledger', 'uploads/upgrades');

	/** Tables a backup run writes on the source after its dump. */
	const OWN_TABLES = array('bkh_backup_history');

	/** How many offloaded files the census asks the bucket about. */
	const OFFLOAD_SAMPLE = 20;

	/** The line site_census.php prints, which parse_output() reads back. */
	const OUTPUT_PREFIX = 'CENSUS=';

	/**
	 * The census of this site.
	 *
	 * @return array{version:int, tables:array<string,int>, files:array, secrets:array}
	 */
	public static function take(): array {
		$root = rtrim((string)PathHelper::getSiteRoot(), '/');
		$names = BackupRunner::extra_excludes();
		$objects = BackupObjects::cloud_objects();
		$paths = BackupObjects::exclude_lines($objects, $root);
		return array(
			'version'   => self::VERSION,
			'tables'    => self::tables(DbConnector::get_instance()->get_db_link()),
			'files'     => self::files($root, $names, $paths),
			'secrets'   => SecretReconciler::census(),
			'offloaded' => self::offloaded($objects, $objects ? CloudStorageDriverFactory::driverUnlatched() : null),
		);
	}

	/**
	 * How many files are offloaded to the file bucket, and whether a sample of
	 * them answers from it: the largest few and the rest drawn at random, one
	 * metadata request each.
	 *
	 * @param array                   $objects BackupObjects::cloud_objects()
	 * @param CloudStorageDriver|null $driver  the file store's driver; null when it has none
	 * @return array{total:int, sampled:int, answered:int, missing:string[], error:string}
	 */
	public static function offloaded(array $objects, ?CloudStorageDriver $driver, int $sample = self::OFFLOAD_SAMPLE): array {
		$out = array('total' => count($objects), 'sampled' => 0, 'answered' => 0, 'missing' => array(), 'error' => '');
		if (!$objects) { return $out; }
		if ($driver === null) {
			$out['error'] = 'files are offloaded, and this site has no file store configured to reach them';
			return $out;
		}
		$picked = $objects;
		if (count($objects) > $sample) {
			shuffle($picked);
			$picked = array_slice($picked, 0, $sample);
		}
		foreach ($picked as $obj) {
			$out['sampled']++;
			if ($driver->head((string)$obj['remote_key']) !== null) {
				$out['answered']++;
			} elseif (count($out['missing']) < 10) {
				$out['missing'][] = (string)$obj['name'];
			}
		}
		return $out;
	}

	/**
	 * Rows per table, every schema but the system's. A table outside public is
	 * named schema.table.
	 *
	 * Counted in one read-only snapshot, so on a live site every count is of the
	 * same moment. A caller already in a transaction counts in its own.
	 *
	 * @return array<string,int>
	 */
	public static function tables(PDO $db): array {
		$own = !$db->inTransaction();
		if ($own) {
			$db->beginTransaction();
			$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
		}
		try {
			$q = $db->query("SELECT schemaname, tablename FROM pg_tables
				WHERE schemaname NOT IN ('pg_catalog', 'information_schema') ORDER BY schemaname, tablename");
			$out = array();
			foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
				$name = $t['schemaname'] === 'public' ? $t['tablename'] : $t['schemaname'] . '.' . $t['tablename'];
				if (in_array($name, self::OWN_TABLES, true)) { continue; }
				$ident = '"' . str_replace('"', '""', $t['schemaname']) . '"."' . str_replace('"', '""', $t['tablename']) . '"';
				$out[$name] = (int)$db->query('SELECT count(*) FROM ' . $ident)->fetchColumn();
			}
		} finally {
			if ($own) { $db->rollBack(); }
		}
		return $out;
	}

	/**
	 * Files, directories and bytes under each top-level entry of the site root.
	 * Loose files at the root are counted under '.'.
	 *
	 * The digest is a sha256 over every counted path with its type and size (a
	 * link with its target), sorted, so a file renamed, removed, added or changed
	 * in size changes it, while nothing is read but the directory entries.
	 *
	 * @param string   $root  the site root
	 * @param string[] $names further names to leave out at any depth (backup_exclude)
	 * @param string[] $paths further paths, relative to the root, to leave out (offloaded files)
	 * @return array<string, array{files:int, dirs:int, bytes:int, digest:string}>
	 */
	public static function files(string $root, array $names = array(), array $paths = array()): array {
		$root = rtrim($root, '/');
		$skip_names = array_fill_keys(self::NAMED_EXCLUDES, true);
		// backup_exclude names may be tar patterns (joinery-core-*.tar.gz). An
		// unanchored tar pattern matches any tail of the member's path that
		// starts at a name, and its * crosses '/': x/joinery-core-1/y.tar.gz is
		// left out too. A plain name matches one whole name, as tar's does.
		$patterns = array_values(array_filter($names, function ($n) { return strpbrk($n, '*?[') !== false; }));
		foreach ($names as $n) { if (strpbrk($n, '*?[') === false) { $skip_names[$n] = true; } }
		$skip_paths = array_fill_keys(array_merge(self::OWN_PATHS, $paths), true);

		$groups = array();
		$lines = array();
		$count = function ($group, $rel, $line, $kind, $bytes) use (&$groups, &$lines) {
			if (!isset($groups[$group])) {
				$groups[$group] = array('files' => 0, 'dirs' => 0, 'bytes' => 0);
				$lines[$group] = array();
			}
			$groups[$group][$kind] += 1;
			$groups[$group]['bytes'] += $bytes;
			$lines[$group][] = $line;
		};

		// Depth-first by hand: the iterators either follow links into directories
		// or stop at an unreadable one, and the census must do neither.
		$walk = function ($rel) use (&$walk, $root, $skip_names, $patterns, $skip_paths, $count) {
			$dir = $rel === '' ? $root : $root . '/' . $rel;
			$entries = @scandir($dir);
			if ($entries === false) {
				throw new RuntimeException('SiteCensus: cannot read ' . $dir);
			}
			foreach ($entries as $name) {
				if ($name === '.' || $name === '..' || isset($skip_names[$name])) { continue; }
				if ($patterns && self::tar_excludes($patterns, $rel === '' ? $name : $rel . '/' . $name)) { continue; }
				$path = $rel === '' ? $name : $rel . '/' . $name;
				if (isset($skip_paths[$path])) { continue; }
				if ($rel === '' && strpos($name, 'public_html_') === 0) { continue; }
				$group = $rel === '' ? (is_dir($root . '/' . $name) && !is_link($root . '/' . $name) ? $name : '.') : explode('/', $rel, 2)[0];
				$full = $root . '/' . $path;
				if (is_link($full)) {
					$count($group, $path, 'l ' . $path . ' -> ' . (string)@readlink($full), 'files', 0);
				} elseif (is_dir($full)) {
					$count($group, $path, 'd ' . $path, 'dirs', 0);
					$walk($path);
				} elseif (is_file($full)) {
					$size = (int)@filesize($full);
					$count($group, $path, 'f ' . $path . ' ' . $size, 'files', $size);
				}
				// A socket or a fifo is not in any archive: not counted.
			}
		};
		$walk('');

		ksort($groups);
		foreach ($groups as $group => &$g) {
			sort($lines[$group], SORT_STRING);
			$g['digest'] = hash('sha256', implode("\n", $lines[$group]));
		}
		unset($g);
		return $groups;
	}

	/**
	 * Whether an unanchored tar --exclude pattern leaves out this path: any
	 * tail of it that starts at a name matches, with * free to cross '/'.
	 */
	private static function tar_excludes(array $patterns, string $path): bool {
		$tail = $path;
		while (true) {
			foreach ($patterns as $p) {
				if (fnmatch($p, $tail)) { return true; }
			}
			$slash = strpos($tail, '/');
			if ($slash === false) { return false; }
			$tail = substr($tail, $slash + 1);
		}
	}

	/** The one line site_census.php prints. */
	public static function format_output(array $census): string {
		return self::OUTPUT_PREFIX . json_encode($census, JSON_UNESCAPED_SLASHES) . "\n";
	}

	/**
	 * The census in a site_census job's output, or null when it holds none
	 * (an error, a node too old to carry the tool). Warnings may surround it.
	 */
	public static function parse_output(string $output): ?array {
		if (!preg_match('/^' . preg_quote(self::OUTPUT_PREFIX, '/') . '(\{.*\})\s*$/m', $output, $m)) {
			return null;
		}
		$census = json_decode($m[1], true);
		if (!is_array($census) || !isset($census['version'], $census['tables'], $census['files'], $census['secrets'], $census['offloaded'])) {
			return null;
		}
		return $census;
	}

	/**
	 * What differs between the source's census and the copy's.
	 *
	 * Two kinds of difference:
	 *   - one the copy's own state explains, and no amount of the source
	 *     changing does: the canary not opening on the copy while it opens on
	 *     the source, more dead secrets on the copy, or an offloaded file the
	 *     copy cannot reach in the bucket. Always blocking.
	 *   - a row, file or byte count, or a count of stored secrets. The source
	 *     changes these while it is live, so they block only when $exact: at
	 *     the final copy, with the source frozen.
	 *
	 * @param bool $exact true with the source frozen: every difference blocks
	 * @return array{match:bool, blocking:int, differences:array<int, array{what:string, source:mixed, copy:mixed, blocking:bool}>}
	 */
	public static function compare(array $source, array $copy, bool $exact): array {
		$diffs = array();
		$add = function ($what, $s, $c, $blocking) use (&$diffs) {
			$diffs[] = array('what' => $what, 'source' => $s, 'copy' => $c, 'blocking' => (bool)$blocking);
		};

		if (($source['version'] ?? null) !== self::VERSION || ($copy['version'] ?? null) !== self::VERSION) {
			$add('census format', $source['version'] ?? null, $copy['version'] ?? null, true);
			return self::verdict($diffs);
		}

		$st = (array)$source['tables']; $ct = (array)$copy['tables'];
		foreach (array_unique(array_merge(array_keys($st), array_keys($ct))) as $t) {
			$s = array_key_exists($t, $st) ? (int)$st[$t] : null;
			$c = array_key_exists($t, $ct) ? (int)$ct[$t] : null;
			if ($s !== $c) { $add('rows in ' . $t, $s, $c, $exact); }
		}

		$sf = (array)$source['files']; $cf = (array)$copy['files'];
		foreach (array_unique(array_merge(array_keys($sf), array_keys($cf))) as $g) {
			$s = $sf[$g] ?? null; $c = $cf[$g] ?? null;
			if ($s === null || $c === null) {
				$add('files in ' . $g . '/', $s === null ? null : 'present', $c === null ? null : 'present', $exact);
				continue;
			}
			$differs = false;
			foreach (array('files', 'dirs', 'bytes') as $k) {
				if ((int)($s[$k] ?? -1) !== (int)($c[$k] ?? -1)) {
					$add($k . ' in ' . $g . '/', (int)($s[$k] ?? 0), (int)($c[$k] ?? 0), $exact);
					$differs = true;
				}
			}
			// Same counts, different paths or sizes: a file renamed, or two sizes
			// that changed by amounts that cancel.
			if (!$differs && ($s['digest'] ?? '') !== ($c['digest'] ?? '')) {
				$add('paths and sizes in ' . $g . '/', 'digest ' . substr((string)($s['digest'] ?? ''), 0, 12),
					'digest ' . substr((string)($c['digest'] ?? ''), 0, 12), $exact);
			}
		}

		$ss = (array)$source['secrets']; $cs = (array)$copy['secrets'];
		$s_canary = (string)($ss['canary'] ?? ''); $c_canary = (string)($cs['canary'] ?? '');
		if ($s_canary !== $c_canary) {
			$add('key canary', $s_canary, $c_canary, $s_canary === SecretBox::OPEN_OK);
		}
		if ((int)($ss['dead'] ?? 0) !== (int)($cs['dead'] ?? 0)) {
			$add('dead sealed secrets', (int)($ss['dead'] ?? 0), (int)($cs['dead'] ?? 0),
				$exact || (int)($cs['dead'] ?? 0) > (int)($ss['dead'] ?? 0));
		}
		if ((int)($ss['present'] ?? 0) !== (int)($cs['present'] ?? 0)) {
			$add('stored sealed secrets', (int)($ss['present'] ?? 0), (int)($cs['present'] ?? 0), $exact);
		}

		$so = (array)($source['offloaded'] ?? array()); $co = (array)($copy['offloaded'] ?? array());
		if ((int)($so['total'] ?? 0) !== (int)($co['total'] ?? 0)) {
			$add('offloaded files', (int)($so['total'] ?? 0), (int)($co['total'] ?? 0), $exact);
		}
		if ((string)($co['error'] ?? '') !== '' || (int)($co['answered'] ?? 0) < (int)($co['sampled'] ?? 0)) {
			$add('offloaded files the copy reaches in the bucket', null,
				(string)($co['error'] ?? '') !== '' ? (string)$co['error']
					: ((int)$co['answered'] . ' of ' . (int)$co['sampled'] . ' sampled; not answering: ' . implode(', ', (array)($co['missing'] ?? array()))),
				true);
		}

		return self::verdict($diffs);
	}

	private static function verdict(array $diffs): array {
		$blocking = count(array_filter($diffs, function ($d) { return $d['blocking']; }));
		return array('match' => !$diffs, 'blocking' => $blocking, 'differences' => $diffs);
	}
}
