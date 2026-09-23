<?php
/**
 * FleetBackupRetention — pruning backup storage this management node owns.
 *
 * This is the one place the manager profile deliberately keeps work OFF the
 * node. A node is handed a write-only credential: it can add its archives and
 * cannot remove any, so a compromised node cannot erase the fleet's backups —
 * which is the first move of any ransomware worth the name, and the exact thing
 * the manager copy exists to survive. Deletion therefore happens here, with a
 * credential that never leaves this machine.
 *
 * It is driven by a LISTING rather than by recorded history, which is the
 * opposite of what BackupRunner does for a site's own backups — deliberately,
 * and for a reason that only holds here. A site listing a shared bucket cannot
 * know which objects are its own; this management node defined the whole
 * {prefix}/{slug}/manager/ path, knows every slug in it, and is the only party
 * that can delete from it. Listing is also strictly safer for this job: it keeps
 * the newest N sets of objects that ACTUALLY EXIST, so a run that failed
 * part-way can never be counted as a restore point.
 *
 * Chains are kept or deleted whole. Deleting the oldest runs of a chain leaves
 * incrementals whose full is gone, which is not a smaller backup — it is no
 * backup, and it looks like a restore point right up until someone needs it.
 *
 * @version 1.5 - check_shelf() also reads every standalone full's index and requires its stored
 *                objects in backup storage, as it does a chain's newest run's; the two families are
 *                checked by the same rule (compare_index)
 * @version 1.4.1 - an objects index that cannot be read, newest or older, ends the object prune with
 *                  nothing deleted: a transient read failure never costs a retained run its objects
 * @version 1.4 - the object family (specs/implemented/backup_offloaded_files.md § Retention): group() files
 *                nothing under objects/ as a restore point; prune_objects() deletes an object only
 *                when no retained run's index names its backup storage location and it landed before the
 *                newest retained run, and an emptied epoch's envelope with it (never the newest
 *                epoch's); check_shelf() reads each chain's newest index and requires every object
 *                it marks stored to be in backup storage at its recorded size, with its epoch envelope.
 *                index_links() picks the newest index and every envelope for the run request.
 * @version 1.3 - check_shelf() answers in two parts: what is wrong with the backups it read, and
 *                which manifests it could not read this pass — a transport error is reported by the
 *                pass, never stamped as an incomplete backup; the reader is injectable for the test
 * @version 1.2 - the backup storage check: every artifact a chain's manifest names must be in backup storage at the
 *                recorded size, and the manifest must carry its envelope. compare_manifest is the pure
 *                rule; check_shelf reads each manifest off the listing prune() already takes
 * @version 1.1 - the pass also sizes backup storage, from the listing it already takes: the hosted
 *                tier's storage allowance needs no meter of its own
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/S3Signer.php'));
require_once(PathHelper::getIncludePath('includes/BackupProfile.php'));
require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupChain.php'));
require_once(PathHelper::getIncludePath('includes/BackupObjects.php'));
require_once(PathHelper::getIncludePath('includes/BackupNaming.php'));

class FleetBackupRetention {

	/**
	 * Prune one node's manager-profile backup storage to the newest $keep restore points.
	 *
	 * Called immediately BEFORE dispatching that node's next run, which is the
	 * right moment for two reasons: it is once per backup cycle rather than once
	 * per scheduler tick, and everything it counts is already confirmed present
	 * in the bucket.
	 *
	 * The result also carries what the listing SAW — `listed` and
	 * `newest_object_time` — because the listing is the bucket's own testimony
	 * about this node's backup storage, taken with this management node's credential. The
	 * scheduler stamps it on the node, and the health check compares it against
	 * what the node claims: a node that reports success while nothing new lands
	 * in backup storage is the one failure the node's own reporting can never admit
	 * to.
	 *
	 * It also SIZES backup storage, from the same listing. That figure is what the
	 * hosted tier's storage allowance is measured against, and taking it here
	 * is why the allowance needs no meter of its own: the pass already walks the
	 * whole prefix and the provider already returns each object's size, so the
	 * number is free, is taken with the one credential that can see the whole
	 * shelf, and is measured AFTER the prune — which is what the customer is
	 * actually keeping.
	 *
	 * The listing itself comes back too (`objects`, less what was pruned, and
	 * the `base` it was taken under) so the backup storage check can read from the same
	 * testimony without listing again.
	 *
	 * @return array{kept:int, pruned:int, deleted_objects:int, error:string,
	 *               listed:bool, newest_object_time:string, bytes:int,
	 *               objects:array, base:string}
	 */
	public static function prune($node, $target, $keep, $read = null) {
		$keep = max(1, (int)$keep);
		$result = array('kept' => 0, 'pruned' => 0, 'deleted_objects' => 0, 'error' => '',
			'listed' => false, 'newest_object_time' => '', 'bytes' => 0, 'objects' => array(), 'base' => '');

		try {
			$creds  = $target->get_credentials();
			$bucket = trim((string)$target->get('bkt_bucket'));
			$prefix = rtrim(trim((string)$target->get('bkt_path_prefix')) ?: 'joinery-backups', '/');
			$slug   = trim((string)$node->get('mgn_slug'));

			if ($bucket === '' || $slug === '' || empty($creds)) {
				$result['error'] = 'no bucket, slug or credentials';
				return $result;
			}

			$base = $prefix . '/' . $slug . '/' . BackupProfile::path_segment(BackupProfile::MANAGER) . '/';
			$objects = S3Signer::list($creds, $bucket, $base);
			if (!is_array($objects)) {
				$result['error'] = 'backup storage could not be listed';
				return $result;
			}
			$result['listed'] = true;
			$result['newest_object_time'] = self::newest_object_time($objects);

			$groups = self::group($objects, $base);
			$result['kept'] = min(count($groups), $keep);

			$delete = function ($key) use ($creds, $bucket) {
				$resp = S3Signer::delete($creds, $bucket, '/' . ltrim($key, '/'));
				$status = (int)($resp['status'] ?? 0);
				// 404 is the state we were asking for.
				if (($status < 200 || $status >= 300) && $status !== 404) {
					throw new Exception('HTTP ' . $status . ' deleting ' . $key);
				}
			};

			$surplus = array_slice(array_values($groups), $keep);
			$pruned_keys = array();
			foreach ($surplus as $group) {
				foreach ($group['keys'] as $key) {
					$delete($key);
					$result['deleted_objects']++;
					$pruned_keys[$key] = true;
				}
				$result['pruned']++;
			}

			// The third family: offloaded files no retained run names any more.
			// Judged from the runs that are LEFT, so it runs after the groups.
			$kept_groups = array_slice(array_values($groups), 0, $keep);
			if ($read === null) {
				$read = self::shelf_reader($creds, $bucket);
			}
			foreach (self::prune_objects($objects, $base, $kept_groups, $read, $delete) as $key) {
				$result['deleted_objects']++;
				$pruned_keys[$key] = true;
			}
			// Sized from what is LEFT, so the figure is what this node is
			// keeping rather than what it briefly held. Objects the provider
			// reported no size for count as nothing: an under-count trips an
			// allowance late, and an invented number trips it wrongly.
			$result['bytes'] = self::total_bytes($objects, $pruned_keys);
			$result['base']  = $base;
			foreach ($objects as $obj) {
				$key = is_array($obj) ? (string)($obj['key'] ?? $obj['Key'] ?? '') : '';
				if ($key === '' || isset($pruned_keys[$key])) { continue; }
				$result['objects'][] = $obj;
			}
		} catch (Throwable $e) {
			// A shelf that could not be pruned is not a reason to skip the backup
			// that was about to run. Too many restore points is a bill; no backup
			// is an outage.
			$result['error'] = $e->getMessage();
			error_log('FleetBackupRetention: pruning failed for node '
				. $node->get('mgn_slug') . ': ' . $e->getMessage());
		}

		return $result;
	}

	/**
	 * Group a listing into restore points, newest first.
	 *
	 * A chain is one group keyed by its directory, whatever it holds — that is
	 * what makes deletion chain-atomic by construction rather than by a rule
	 * someone has to remember. A standalone archive is one group with its
	 * envelope, because an archive without its envelope is unreadable noise and
	 * an envelope without its archive is a restore point that is not there.
	 *
	 * Groups sort by the timestamp in their name — chain directories are
	 * chain-YYYYMMDD_HHMMSS and standalone archives carry the same stamp. The
	 * stamp rather than the whole name, because the two families have different
	 * prefixes ('chain-' vs the slug): a name sort would order a mixed shelf by
	 * family, and after a mode switch every archive of one family would outrank
	 * every archive of the other regardless of age — old fulls hogging the keep
	 * slots forever while newer chains get pruned. And the stamp rather than the
	 * provider's last-modified, which reflects when an object was WRITTEN — a
	 * chain still being extended would keep jumping to the front of a list that
	 * is meant to be ordered by when it started.
	 */
	public static function group(array $objects, $base) {
		$groups = array();

		// Standalone archives first, by stem, so an objects index (which
		// carries the stem and not the archive suffix) can be filed with its
		// archive and its envelope.
		$archives_by_stem = array();
		foreach ($objects as $obj) {
			$key = is_array($obj) ? (string)($obj['key'] ?? $obj['Key'] ?? '') : (string)$obj;
			if ($key === '' || strpos($key, $base) !== 0) { continue; }
			$rel = substr($key, strlen($base));
			if ($rel === '' || strpos($rel, '/') !== false || !BackupNaming::is_backup($rel)) { continue; }
			$ext = BackupNaming::extension_of($rel);
			$archives_by_stem[substr($rel, 0, -strlen($ext))] = $rel;
		}

		foreach ($objects as $obj) {
			$key = is_array($obj) ? (string)($obj['key'] ?? $obj['Key'] ?? '') : (string)$obj;
			if ($key === '' || strpos($key, $base) !== 0) {
				continue;
			}
			$rel = substr($key, strlen($base));
			if ($rel === '') { continue; }

			$slash = strpos($rel, '/');
			if ($slash !== false) {
				// Anything inside a directory belongs to that directory's group.
				$name = substr($rel, 0, $slash);
				// Except the object store: objects/ is not a restore point, and
				// filed as one it would carry no stamp, sort oldest, and be the
				// first thing pruned. Its own rule is prune_objects().
				if ($name === BackupObjects::DIR) { continue; }
			} else {
				// A standalone archive and its envelope share a group. The sidecar
				// suffix is stripped so the two land together.
				$name = $rel;
				if (BackupEnvelope::is_sidecar_name($name)) {
					$name = substr($name, 0, -strlen(BackupEnvelope::SIDECAR_SUFFIX));
				} elseif (BackupNaming::is_index($name)) {
					$stem = BackupNaming::archive_stem_for_index($name);
					$name = $archives_by_stem[$stem] ?? $name;
				}
			}

			if (!isset($groups[$name])) {
				$groups[$name] = array('name' => $name, 'keys' => array());
			}
			$groups[$name]['keys'][] = $key;
		}

		// Newest first. Name as tiebreak, for a deterministic order between two
		// groups sharing a stamp.
		uksort($groups, function ($a, $b) {
			return strcmp(self::stamp_of($b) . $b, self::stamp_of($a) . $a);
		});
		return $groups;
	}

	/**
	 * The YYYYMMDD_HHMMSS stamp in a group name. A name with no stamp sorts as
	 * the oldest thing in backup storage: it is not a restore point this code ever
	 * wrote, so it must not occupy a keep slot that a real one needs.
	 */
	private static function stamp_of($name) {
		return preg_match('/\d{8}_\d{6}/', (string)$name, $m) ? $m[0] : '00000000_000000';
	}

	/**
	 * The bytes a listing accounts for, ignoring the keys just deleted.
	 */
	public static function total_bytes(array $objects, array $skip_keys = array()) {
		$total = 0;
		foreach ($objects as $obj) {
			if (!is_array($obj)) { continue; }
			$key = (string)($obj['key'] ?? $obj['Key'] ?? '');
			if ($key !== '' && isset($skip_keys[$key])) { continue; }
			$size = $obj['size'] ?? $obj['Size'] ?? null;
			if (is_numeric($size)) { $total += (int)$size; }
		}
		return $total;
	}

	/**
	 * When something last LANDED in this backup storage, from the provider's
	 * last-modified stamps — UTC 'Y-m-d H:i:s', or '' for an empty listing.
	 *
	 * The write time rather than the name stamp on purpose: a chain directory
	 * keeps its start stamp for its whole life, but every run that extends it
	 * writes new objects — so the newest write is when a backup last actually
	 * arrived, which is the fact the node's own reporting cannot fake.
	 */
	public static function newest_object_time(array $objects) {
		$newest = 0;
		foreach ($objects as $obj) {
			$lm = is_array($obj) ? (string)($obj['last_modified'] ?? '') : '';
			if ($lm === '') { continue; }
			$ts = strtotime($lm);
			if ($ts !== false && $ts > $newest) { $newest = $ts; }
		}
		return $newest > 0 ? gmdate('Y-m-d H:i:s', $newest) : '';
	}

	/**
	 * The backup storage check — level 1 of backup verification, and free: is every
	 * backup in this node's backup storage whole?
	 *
	 * For every chain the listing holds a manifest for, the manifest is read
	 * (one small GET each) and compared to the listing: every artifact it names
	 * must be present at the recorded size, and it must carry its envelope, or
	 * there is no key to recover. This catches a partial upload, an object
	 * deleted out from under retention, and a manifest rewritten after its
	 * artifacts were pruned — three ways a backup can look present on the
	 * dashboard and be nothing when it is needed.
	 *
	 * Offloaded files are checked the same way for both families: the newest
	 * run's index of each chain, and the index beside each standalone full,
	 * is read, and every object it marks stored must be in backup storage at its
	 * recorded size under an epoch whose envelope is there.
	 *
	 * Two answers, kept apart because they mean different things:
	 *
	 *   problem  what is wrong with a backup whose manifest WAS read — one line
	 *            naming it, '' when every backup read is whole. A fact about
	 *            backup storage, stamped on the node's card.
	 *   unread   manifests that could not be fetched this pass (a transport
	 *            error, an HTTP status) — one line naming them, '' when all
	 *            were read. A fact about this pass, not backup storage: reported in
	 *            the pass and never stamped, so a network blip is not shown as
	 *            an incomplete backup.
	 *
	 * Nothing here deletes or retries.
	 *
	 * @param array  $objects The listing under $base, as prune() returned it
	 * @param string $base    The manager-profile prefix the listing was taken under
	 * @param callable|null $read fn(string $key): array — the decoded manifest,
	 *                      or throws; defaults to a signed GET from the bucket
	 * @return array{problem:string, unread:string}
	 */
	public static function check_shelf(array $objects, $base, array $creds, $bucket, $read = null) {
		$base = rtrim((string)$base, '/') . '/';
		$present = array();      // chain dir => [name => size]
		$manifests = array();    // chain dir => manifest key
		$standalone = array();   // index key => the archive's name, for a standalone full
		foreach ($objects as $obj) {
			if (!is_array($obj)) { continue; }
			$key = (string)($obj['key'] ?? $obj['Key'] ?? '');
			if ($key === '' || strpos($key, $base) !== 0) { continue; }
			$rel = substr($key, strlen($base));
			$parts = explode('/', $rel);
			if (count($parts) === 1 && BackupNaming::is_index($rel)) {
				$standalone[$key] = BackupNaming::archive_stem_for_index($rel);
				continue;
			}
			if (count($parts) !== 2 || strpos($parts[0], BackupChain::DIR_PREFIX) !== 0) { continue; }
			list($dir, $name) = $parts;
			$size = $obj['size'] ?? $obj['Size'] ?? null;
			$present[$dir][$name] = is_numeric($size) ? (int)$size : null;
			if ($name === BackupChain::MANIFEST_NAME) {
				$manifests[$dir] = $key;
			}
		}
		$store = self::object_store($objects, $base);

		if ($read === null) {
			$read = self::shelf_reader($creds, $bucket);
		}

		$problems = array();
		$unread = array();
		ksort($manifests);
		foreach ($manifests as $dir => $key) {
			try {
				$manifest = $read($key);
				if (!is_array($manifest)) {
					throw new Exception('not a manifest');
				}
			} catch (Throwable $e) {
				$unread[] = 'the manifest of the backup set ' . self::set_words($dir, null)
					. ' could not be read (' . $e->getMessage() . ')';
				continue;
			}
			$problem = self::compare_manifest($manifest, $present[$dir] ?? array());
			if ($problem !== '') {
				$problems[] = $problem;
				continue;
			}
			// The newest run's objects index: every object it marks stored must
			// be in backup storage at its recorded size, under an epoch whose
			// envelope is there. One more small GET per chain; no per-object
			// request, the listing already carries key and size.
			$runs = $manifest['runs'] ?? array();
			$last = $runs ? $runs[count($runs) - 1] : array();
			$index_name = (string)($last['artifacts']['objects']['name'] ?? '');
			if ($index_name === '') { continue; }
			try {
				$index = $read($base . $dir . '/' . $index_name);
				if (!is_array($index)) {
					throw new Exception('not an objects index');
				}
			} catch (Throwable $e) {
				$unread[] = 'the offloaded-files index of the backup set ' . self::set_words($dir, $manifest)
					. ' could not be read (' . $e->getMessage() . ')';
				continue;
			}
			$problem = self::compare_index($index, $store['objects'], $store['envelopes'], self::set_words($dir, $manifest));
			if ($problem !== '') {
				$problems[] = $problem;
			}
		}

		// A standalone full's index, beside its archive: the same rule.
		ksort($standalone);
		foreach ($standalone as $key => $stem) {
			try {
				$index = $read($key);
				if (!is_array($index)) {
					throw new Exception('not an objects index');
				}
			} catch (Throwable $e) {
				$unread[] = 'the offloaded-files index of the backup set ' . self::set_words($stem, null)
					. ' could not be read (' . $e->getMessage() . ')';
				continue;
			}
			$problem = self::compare_index($index, $store['objects'], $store['envelopes'], self::set_words($stem, null));
			if ($problem !== '') {
				$problems[] = $problem;
			}
		}
		return array('problem' => implode('; ', $problems), 'unread' => implode('; ', $unread));
	}

	/** The default reader: a signed GET, decoded as a manifest or as a gzipped objects index by name. */
	private static function shelf_reader(array $creds, $bucket) {
		return function ($key) use ($creds, $bucket) {
			$resp = S3Signer::get($creds, $bucket, '/' . ltrim($key, '/'));
			if ((int)($resp['status'] ?? 0) !== 200) {
				throw new Exception('HTTP ' . (int)($resp['status'] ?? 0));
			}
			$body = (string)($resp['body'] ?? '');
			if (substr($key, -8) === '.json.gz') {
				return BackupObjects::decode_index($body);
			}
			return BackupChain::decode($body);
		};
	}

	/**
	 * The object store as the listing shows it: objects by shelf location
	 * (epoch/name => ['key', 'size', 'last_modified']) and envelopes by epoch.
	 */
	public static function object_store(array $objects, $base) {
		$base = rtrim((string)$base, '/') . '/';
		$out = array('objects' => array(), 'envelopes' => array());
		foreach ($objects as $obj) {
			if (!is_array($obj)) { continue; }
			$key = (string)($obj['key'] ?? $obj['Key'] ?? '');
			if ($key === '' || strpos($key, $base . BackupObjects::DIR . '/') !== 0) { continue; }
			$parts = explode('/', substr($key, strlen($base . BackupObjects::DIR . '/')));
			if (count($parts) !== 2 || strpos($parts[0], BackupObjects::EPOCH_PREFIX) !== 0 || $parts[1] === '') { continue; }
			list($epoch, $file) = $parts;
			$size = $obj['size'] ?? $obj['Size'] ?? null;
			if ($file === BackupObjects::ENVELOPE_NAME) {
				$out['envelopes'][$epoch] = $key;
				continue;
			}
			if (substr($file, -strlen(BackupObjects::OBJECT_SUFFIX)) !== BackupObjects::OBJECT_SUFFIX) { continue; }
			$name = substr($file, 0, -strlen(BackupObjects::OBJECT_SUFFIX));
			$out['objects'][$epoch . '/' . $name] = array(
				'key'           => $key,
				'size'          => is_numeric($size) ? (int)$size : null,
				'last_modified' => (string)($obj['last_modified'] ?? $obj['LastModified'] ?? ''),
			);
		}
		return $out;
	}

	/**
	 * The pure rule behind the object half of the backup storage check: one index
	 * against the store the listing shows. '' when whole.
	 */
	public static function compare_index(array $index, array $store_objects, array $envelopes, $set) {
		$missing = 0; $first_missing = ''; $wrong = '';
		foreach (($index['objects'] ?? array()) as $e) {
			if (empty($e['stored'])) { continue; }
			$loc = (string)($e['epoch'] ?? '') . '/' . (string)($e['name'] ?? '');
			if (!isset($store_objects[$loc])) {
				$missing++;
				if ($first_missing === '') { $first_missing = (string)$e['name']; }
				continue;
			}
			$expected = (int)($e['object_bytes'] ?? 0);
			$actual = $store_objects[$loc]['size'];
			if ($wrong === '' && $expected > 0 && $actual !== null && $actual !== $expected) {
				$wrong = (string)$e['name'] . ' at ' . $actual . ' bytes in backup storage where its index records ' . $expected;
			}
		}
		if ($missing > 0) {
			return 'the backup set ' . $set . ' names ' . $missing . ' offloaded file' . ($missing === 1 ? '' : 's')
				. ' its backup storage does not hold (' . $first_missing . ($missing > 1 ? ', …' : '') . ')';
		}
		if ($wrong !== '') {
			return 'the backup set ' . $set . ' holds the offloaded file ' . $wrong;
		}
		foreach (($index['epochs'] ?? array()) as $epoch) {
			if (!isset($envelopes[(string)$epoch])) {
				return 'the backup set ' . $set . ' names offloaded files in ' . $epoch
					. ' but that epoch\'s envelope is not in backup storage, so no key can be recovered for them';
			}
		}
		return '';
	}

	/**
	 * The object family's retention. An object is deleted when no retained
	 * run's index names its backup storage location AND it landed before the newest
	 * retained run started — an object uploaded after the newest run has had
	 * no run to be indexed by. Retained runs are every run of every kept
	 * chain plus every kept standalone full. The newest index of each kept
	 * chain is read first (and every standalone's); only a location absent
	 * from all of those costs a read of the older indexes. Any index that
	 * cannot be read ends the pass with nothing deleted. An epoch left with
	 * no objects loses its envelope, except the newest epoch, which is where
	 * the node's next store goes.
	 *
	 * When no kept run carries an index at all, nothing is judged and nothing
	 * goes: there is no record to judge by.
	 *
	 * @param array    $objects    the listing under $base
	 * @param array    $kept       the kept groups, as group() returns them
	 * @param callable $read       fn(key): decoded index
	 * @param callable $delete     fn(key): void, throws on failure
	 * @return string[] the keys deleted
	 */
	public static function prune_objects(array $objects, $base, array $kept, $read, $delete) {
		$base = rtrim((string)$base, '/') . '/';
		$store = self::object_store($objects, $base);
		if (!$store['objects'] && !$store['envelopes']) {
			return array();
		}

		// Index keys of the kept runs: each chain's newest first, every
		// standalone's, then the chains' older runs.
		$first = array(); $rest = array();
		foreach ($kept as $group) {
			$chain = array();
			foreach ($group['keys'] as $key) {
				$name = substr($key, strlen($base . $group['name'] . '/'));
				if (strpos($group['name'], BackupChain::DIR_PREFIX) === 0) {
					if (preg_match('/^objects-\d{4}\.json\.gz$/', (string)$name)) { $chain[] = $key; }
				} elseif (BackupNaming::is_index(basename($key))) {
					$first[] = $key;
				}
			}
			if ($chain) {
				rsort($chain);
				$first[] = array_shift($chain);
				foreach ($chain as $k) { $rest[] = $k; }
			}
		}
		if (!$first) {
			return array();
		}

		$candidates = $store['objects'];
		$newest_run = 0;
		$read_any = false;
		foreach (array_merge($first, $rest) as $i => $key) {
			$is_first = $i < count($first);
			if (!$candidates && !$is_first) { break; }
			try {
				$index = $read($key);
			} catch (Throwable $e) {
				// An index that cannot be read — the newest, or an older run's
				// that alone may name what remains — leaves every object
				// unjudged: nothing goes this pass. A transient read failure
				// must never cost a retained restore point its objects.
				error_log('FleetBackupRetention: could not read the objects index ' . $key . ': ' . $e->getMessage()
					. '; no offloaded file is pruned this pass.');
				return array();
			}
			$read_any = true;
			$created = strtotime((string)($index['created'] ?? ''));
			if ($created !== false && $created > $newest_run) { $newest_run = $created; }
			foreach (array_keys(BackupObjects::index_locations($index)) as $loc) {
				unset($candidates[$loc]);
			}
		}
		if (!$read_any || $newest_run === 0) {
			return array();
		}

		$deleted = array();
		$touched = array();
		foreach ($candidates as $loc => $o) {
			$landed = strtotime((string)$o['last_modified']);
			if ($landed === false || $landed >= $newest_run) { continue; }
			$delete($o['key']);
			$deleted[] = $o['key'];
			$touched[substr($loc, 0, strpos($loc, '/'))] = true;
			unset($store['objects'][$loc]);
		}

		// Envelopes of emptied epochs, never the newest epoch's.
		$epochs = array_keys($store['envelopes']);
		sort($epochs);
		$newest_epoch = $epochs ? end($epochs) : '';
		foreach (array_keys($touched) as $epoch) {
			if ($epoch === $newest_epoch || !isset($store['envelopes'][$epoch])) { continue; }
			$left = false;
			foreach ($store['objects'] as $loc => $o) {
				if (strpos($loc, $epoch . '/') === 0) { $left = true; break; }
			}
			if (!$left) {
				$delete($store['envelopes'][$epoch]);
				$deleted[] = $store['envelopes'][$epoch];
			}
		}
		return $deleted;
	}

	/**
	 * What the run request carries about the object store, from the listing
	 * the pass already took: the key of the newest index in backup storage (the
	 * newest group's newest run), or '' when none, and every epoch envelope's
	 * key by epoch id. The caller signs them.
	 *
	 * @return array{index:string, envelopes:array<string,string>}
	 */
	public static function index_links(array $objects, $base) {
		$base = rtrim((string)$base, '/') . '/';
		$index = '';
		foreach (self::group($objects, $base) as $group) {
			$chain = array();
			foreach ($group['keys'] as $key) {
				$name = basename($key);
				if (strpos($group['name'], BackupChain::DIR_PREFIX) === 0) {
					if (preg_match('/^objects-\d{4}\.json\.gz$/', $name)) { $chain[] = $key; }
				} elseif (BackupNaming::is_index($name)) {
					$index = $key;
				}
			}
			if ($chain) { rsort($chain); $index = $chain[0]; }
			if ($index !== '') { break; }
		}
		$store = self::object_store($objects, $base);
		return array('index' => $index, 'envelopes' => $store['envelopes']);
	}

	/**
	 * The pure rule behind the backup storage check: one manifest against what the
	 * listing holds under its directory, as [name => bytes]. Returns '' when
	 * whole, otherwise one line saying what is wrong, in words a person reads
	 * on the node's card.
	 */
	public static function compare_manifest(array $manifest, array $present) {
		$set = self::set_words((string)($manifest['chain_id'] ?? ''), $manifest);
		if (empty($manifest['envelope']) || !is_array($manifest['envelope'])) {
			return 'the backup set ' . $set . ' has no envelope in its manifest, so no key can be recovered for it';
		}
		foreach (($manifest['runs'] ?? array()) as $run) {
			foreach (($run['artifacts'] ?? array()) as $a) {
				$name = (string)($a['name'] ?? '');
				if ($name === '') { continue; }
				if (!array_key_exists($name, $present)) {
					return 'the backup set ' . $set . ' names ' . $name . ' in its manifest but it is not in backup storage';
				}
				$expected = (int)($a['bytes'] ?? 0);
				$actual = $present[$name];
				if ($expected > 0 && $actual !== null && $actual !== $expected) {
					return 'the backup set ' . $set . ' holds ' . $name . ' at ' . $actual
						. ' bytes in backup storage where its manifest records ' . $expected;
				}
			}
		}
		return '';
	}

	/** "begun 2026-09-12 04:45 UTC" for a person, from the manifest or the directory's stamp. */
	private static function set_words($dir, $manifest) {
		$created = is_array($manifest) ? (string)($manifest['created'] ?? '') : '';
		$ts = $created !== '' ? strtotime($created) : false;
		if ($ts === false && preg_match('/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', (string)$dir, $m)) {
			$ts = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
		}
		return $ts ? 'begun ' . gmdate('Y-m-d H:i', $ts) . ' UTC' : 'in backup storage';
	}
}
