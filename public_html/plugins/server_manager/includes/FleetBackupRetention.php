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
 * @version 1.13 - object lock (specs/storage_targets.md F8): a surplus point any of whose objects the lock still holds
 *                 is kept whole, for a pass after its date; an object or envelope still held is left, not counted
 * @version 1.12 - a deleted object's ledger row is kept, marked pruned by retention; a chain's newest manifest is
 *                 read whatever its version, and every envelope of an emptied epoch goes with it
 *                 (specs/storage_targets.md WP5)
 * @version 1.11 - prune() deletes only what BackupSafety allows (specs/storage_targets.md F1, F3): the node's newest
 *                 verified chain and everything newer stay, the newest stays, and a point goes only once a pass
 *                 CONFIRM_HOURS earlier found it surplus too (recorded on its space, sps_surplus); the verified
 *                 floor is read from the verify jobs, so a space that cannot be listed never lowers it
 * @version 1.10 - object_store() reads object keys through BackupObjects::location_of(), so offloaded mail is kept and pruned like files
 * @version 1.9 - prune() orders every space's points newest first before the window is applied; a draining space
 *                is released only by a verify sent after the active space was (re)opened
 * @version 1.8 - prune() works across every storage space of the node, each point deleted from its own space's
 *                target; a draining space is kept whole until the active space holds a verified chain, then ages
 *                out and is retired once empty; newest_landed() reads one space (specs/storage_targets.md WP4)
 * @version 1.7 - prune() refuses a node in an install state: a copy's row names its source's storage
 *                (site_copy.md WP5)
 * @version 1.6 - prune() keeps $keep_days days of restore points by BackupRunner::surplus(), the rule a
 *                site's own retention uses, instead of a count; newest_landed() is the backup storage
 *                listing taken after a node reports success
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
	 * Prune one node's manager-profile backups to $keep_days days of restore
	 * points, across every storage space that holds them — the rule is
	 * BackupRunner::surplus(), the one a site's own retention uses: every
	 * point started inside the window, plus the newest one started before it.
	 * Each point is deleted from its own space's target.
	 *
	 * What the window finds surplus goes only as BackupSafety allows: the
	 * newest chain a verify passed (verified_chains()) and everything newer
	 * stay, the newest point stays, and a point is deleted only once a pass
	 * CONFIRM_HOURS earlier found it surplus too. Each space records what was
	 * found surplus in it (StorageSpace::record_surplus()).
	 *
	 * A space the node was moved away from (draining) is kept whole until the
	 * node's active space holds a chain a verify has passed, so there is never
	 * a night with no restorable copy anywhere (specs/storage_targets.md §5).
	 * After that its points age out by the same rule; once none is left, the
	 * rest of it (offloaded files no kept run names) goes too, and an empty
	 * draining space is retired.
	 *
	 * Called immediately BEFORE dispatching that node's next run, which is the
	 * right moment for two reasons: it is once per backup cycle rather than once
	 * per scheduler tick, and everything it counts is already confirmed present
	 * in the bucket.
	 *
	 * The result also carries what the listing of the ACTIVE space SAW —
	 * `listed` and `newest_object_time` — because the listing is the bucket's
	 * own testimony about where this node's backups land, taken with this
	 * management node's credential. The scheduler stamps it on the node, and
	 * the health check compares it against what the node claims: a node that
	 * reports success while nothing new lands in backup storage is the one
	 * failure the node's own reporting can never admit to.
	 *
	 * It also SIZES backup storage, from the same listings: `bytes` is what is
	 * left in every space after the prune, what the customer is keeping, and
	 * what the hosted tier's storage allowance is measured against.
	 *
	 * The active space's listing comes back too (`objects`, less what was
	 * pruned, the `base` it was taken under and the `space`) so the backup
	 * storage check and the run request read from the same testimony without
	 * listing again.
	 *
	 * @return array{kept:int, pruned:int, deleted_objects:int, error:string,
	 *               listed:bool, newest_object_time:string, bytes:int,
	 *               objects:array, base:string, space:?StorageSpace, retired:int}
	 */
	public static function prune($node, $keep_days, $read = null, $now = null) {
		$now = ($now === null) ? time() : (int)$now;
		$result = array('kept' => 0, 'pruned' => 0, 'deleted_objects' => 0, 'error' => '',
			'listed' => false, 'newest_object_time' => '', 'bytes' => 0, 'objects' => array(), 'base' => '',
			'space' => null, 'retired' => 0);

		// A node in an install state is never pruned from here. A dormant
		// copy's backups are its source's, and pruning them would delete the
		// source's restore points under the copy's schedule.
		if (!ManagedNode::is_operational_from($node)) {
			$result['error'] = 'not a working node (' . $node->get('mgn_install_state') . '), so its storage is not pruned';
			return $result;
		}

		$errors = array();
		$listed = array();     // space id => point name => first found surplus, for this pass to record
		$decided = false;
		$segment = BackupProfile::path_segment(BackupProfile::MANAGER) . '/';
		$spaces = array();     // space id => [space, creds, bucket, base, objects, groups]
		$active = null;
		foreach (StorageSpace::of_owner(StorageSpace::OWNER_NODE, (int)$node->key) as $space) {
			try {
				list($target, $creds, $bucket) = $space->reach();
				$base = $space->base() . $segment;
				$objects = S3Signer::list($creds, $bucket, $base);
				if (!is_array($objects)) {
					throw new Exception('backup storage could not be listed');
				}
				$spaces[(int)$space->key] = array('space' => $space, 'target' => $target, 'creds' => $creds, 'bucket' => $bucket,
					'base' => $base, 'objects' => $objects, 'groups' => self::group($objects, $base));
				if ($space->is_active()) { $active = (int)$space->key; }
			} catch (Throwable $e) {
				// A space that cannot be listed is left alone this pass: its
				// points are unknown, so nothing is judged surplus against it.
				$errors[] = $space->describe() . ': ' . $e->getMessage();
			}
		}
		if ($active !== null) {
			$result['listed'] = true;
			$result['space'] = $spaces[$active]['space'];
			$result['base'] = $spaces[$active]['base'];
			$result['newest_object_time'] = self::newest_object_time($spaces[$active]['objects']);
		}
		$hold_draining = !self::active_verified($node, $active !== null ? $spaces[$active]['space'] : null);

		try {
			// One window across every space: a point's age is its name's stamp,
			// wherever it lives.
			$points = array();
			foreach ($spaces as $id => $sp) {
				foreach ($sp['groups'] as $name => $group) {
					$points[] = array('item' => $id . '|' . $name, 'time' => self::start_time_of($name));
				}
			}
			// surplus() reads its points newest first: the first one before the
			// window is the one kept. Across spaces that order is not given.
			usort($points, function ($x, $y) { return $y['time'] <=> $x['time'] ?: strcmp($y['item'], $x['item']); });
			$verified = self::verified_chains($node);
			$listed_before = array();
			foreach ($spaces as $id => $sp) {
				foreach ($sp['space']->surplus_listed() as $name => $first) {
					$listed_before[$id . '|' . $name] = $first;
				}
			}
			foreach ($points as &$p) {
				list($sid, $name) = explode('|', $p['item'], 2);
				$p['verified'] = isset($verified[$sid . '|' . $name]) || isset($verified['|' . $name]);
			}
			unset($p);
			// The floor comes from the verify jobs alone (a chain's start is in its
			// name): a space that could not be listed this pass must not hide the
			// newest verified chain and let what is newer than it go.
			$floor = null;
			foreach (array_keys($verified) as $key) {
				$t = self::start_time_of(substr($key, strpos($key, '|') + 1));
				if ($t > 0 && ($floor === null || $t > $floor)) { $floor = $t; }
			}
			$decision = BackupSafety::confirm($points, BackupRunner::surplus($points, $keep_days, $now), $listed_before, $now, $floor);
			$surplus_items = array_flip($decision['delete']);
			$decided = true;
			$result['kept'] = count($points) - count($surplus_items);
			foreach ($decision['listed'] as $item => $first) {
				list($sid, $name) = explode('|', $item, 2);
				$listed[(int)$sid][$name] = $first;
			}

			// Deletes one key; false when object lock still holds it, which a
			// pass after its date deletes (F8).
			$deleter = function (array $sp) {
				return function ($key) use ($sp) {
					$resp = S3Signer::delete($sp['creds'], $sp['bucket'], '/' . ltrim($key, '/'));
					$status = (int)($resp['status'] ?? 0);
					if ($status === S3Signer::LOCKED) {
						return false;
					}
					// 404 is the state we were asking for.
					if (($status < 200 || $status >= 300) && $status !== 404) {
						throw new Exception('HTTP ' . $status . ' deleting ' . $key);
					}
					// The broker's ledger keeps the row, marked with when and why.
					$row = ShelfObject::forKey((int)$sp['space']->key, ltrim((string)$key, '/'));
					if ($row !== null) {
						$row->markPruned(ShelfObject::PRUNED_RETENTION);
					}
				};
			};

			foreach ($spaces as $id => &$sp) {
				$held = $sp['space']->is_draining() && $hold_draining;
				$delete = $deleter($sp);
				$sp['pruned_keys'] = array();
				$kept_groups = array();
				$written = self::written_times($sp['objects']);
				foreach ($sp['groups'] as $name => $group) {
					if (!isset($surplus_items[$id . '|' . $name]) || $held) {
						$kept_groups[] = $group;
						continue;
					}
					// A point is deleted whole or not at all: while object lock
					// holds any of it, it stays, surplus, for a pass after that
					// date — and is kept for what the object store keeps (F8).
					$newest = 0;
					foreach ($group['keys'] as $key) {
						$newest = max($newest, $written[$key] ?? 0);
					}
					if ($sp['target']->held_until($newest ?: $now) > $now) {
						$kept_groups[] = $group;
						continue;
					}
					$stopped = false;
					foreach ($group['keys'] as $key) {
						if ($delete($key) === false) {
							// The clocks disagree by more than the margin: the
							// rest of the point waits, still surplus, for a later
							// pass; what was deleted answers 404 then.
							$stopped = true;
							break;
						}
						$result['deleted_objects']++;
						$sp['pruned_keys'][$key] = true;
					}
					if ($stopped) {
						$kept_groups[] = $group;
						continue;
					}
					unset($listed[$id][$name]);
					$result['pruned']++;
				}
				if ($held) {
					continue;
				}
				if ($sp['space']->is_draining() && !$kept_groups) {
					// No run of this space is kept, so nothing left in it is
					// needed: the offloaded files and envelopes go with it.
					foreach ($sp['objects'] as $obj) {
						$key = is_array($obj) ? (string)($obj['key'] ?? $obj['Key'] ?? '') : '';
						if ($key === '' || isset($sp['pruned_keys'][$key]) || strpos($key, $sp['base']) !== 0) { continue; }
						if ($delete($key) === false) { continue; }
						$result['deleted_objects']++;
						$sp['pruned_keys'][$key] = true;
					}
				} else {
					// The third family: offloaded files no retained run names any
					// more. Judged from the runs that are LEFT, so it runs after
					// the groups.
					$reader = $read ?? self::shelf_reader($sp['creds'], $sp['bucket']);
					foreach (self::prune_objects($sp['objects'], $sp['base'], $kept_groups, $reader, $delete) as $key) {
						$result['deleted_objects']++;
						$sp['pruned_keys'][$key] = true;
					}
				}
			}
			unset($sp);
		} catch (Throwable $e) {
			// A shelf that could not be pruned is not a reason to skip the backup
			// that was about to run. Too many restore points is a bill; no backup
			// is an outage.
			$errors[] = $e->getMessage();
			error_log('FleetBackupRetention: pruning failed for node '
				. $node->get('mgn_slug') . ': ' . $e->getMessage());
		}

		// What each listed space found surplus, for the next pass to confirm.
		// A space that could not be listed keeps what it had, and so does every
		// space when the pass failed before it decided anything.
		foreach ($decided ? $spaces : array() as $id => $sp) {
			try {
				$sp['space']->record_surplus($listed[$id] ?? array());
			} catch (Throwable $e) {
				$errors[] = $sp['space']->describe() . ': could not record what is surplus: ' . $e->getMessage();
			}
		}

		// Sized from what is LEFT in every space, so the figure is what this
		// node is keeping rather than what it briefly held. Objects the
		// provider reported no size for count as nothing: an under-count trips
		// an allowance late, and an invented number trips it wrongly.
		foreach ($spaces as $id => $sp) {
			$pruned_keys = $sp['pruned_keys'] ?? array();
			$result['bytes'] += self::total_bytes($sp['objects'], $pruned_keys);
			$left = 0;
			foreach ($sp['objects'] as $obj) {
				$key = is_array($obj) ? (string)($obj['key'] ?? $obj['Key'] ?? '') : '';
				if ($key === '' || isset($pruned_keys[$key])) { continue; }
				$left++;
				if ($id === $active) { $result['objects'][] = $obj; }
			}
			// Retired only once the whole folder is empty: a site's own backups
			// beside ours keep it claimed.
			if ($left === 0 && $sp['space']->is_draining() && !$hold_draining && $sp['space']->is_empty()) {
				$sp['space']->retire();
				$result['retired']++;
			}
		}
		$result['error'] = implode('; ', $errors);
		return $result;
	}

	/**
	 * The chains of this node a verify has passed, as 'space id|chain id' and,
	 * for a verify sent before verifies named their space, '|chain id'. Read
	 * from the completed verify_backup jobs, newest first.
	 */
	public static function verified_chains($node): array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_parameters, mjb_result FROM mjb_management_jobs
			WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'verify_backup' AND mjb_status = 'completed'
			  AND mjb_delete_time IS NULL
			ORDER BY mjb_management_job_id DESC LIMIT 200");
		$q->execute(array((int)$node->key));
		$out = array();
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$params = json_decode((string)$row['mjb_parameters'], true);
			$res = json_decode((string)$row['mjb_result'], true);
			if (!is_array($params) || !is_array($res) || ($res['verify_status'] ?? '') !== 'pass') {
				continue;
			}
			$chain = (string)($params['chain_id'] ?? '');
			if ($chain === '') { continue; }
			$space = (int)($params['space_id'] ?? 0);
			$out[($space > 0 ? $space : '') . '|' . $chain] = true;
		}
		return $out;
	}

	/**
	 * Does the node's active space hold a chain a verify has passed since it
	 * became active? The verify job names the space it read (space_id); a
	 * pass for a chain there, sent after the space was (re)opened, means the
	 * space holds a complete restore point of its own. A pass from an earlier
	 * time the node backed up there says nothing about now.
	 */
	public static function active_verified($node, ?StorageSpace $active) {
		if ($active === null) {
			return false;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_parameters, mjb_result FROM mjb_management_jobs
			WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'verify_backup' AND mjb_status = 'completed'
			  AND mjb_delete_time IS NULL AND mjb_create_time >= ?
			ORDER BY mjb_management_job_id DESC LIMIT 50");
		$q->execute(array((int)$node->key, (string)$active->get('sps_opened_time')));
		foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$params = json_decode((string)$row['mjb_parameters'], true);
			$res = json_decode((string)$row['mjb_result'], true);
			if (is_array($params) && (int)($params['space_id'] ?? 0) === (int)$active->key
					&& is_array($res) && ($res['verify_status'] ?? '') === 'pass') {
				return true;
			}
		}
		return false;
	}

	/**
	 * When something last landed in one space's manager-profile backups,
	 * listed with this management node's credential — UTC 'Y-m-d H:i:s', or
	 * '' for an empty prefix. Throws when the space cannot be listed.
	 *
	 * prune()'s listing runs before a run is dispatched, so it cannot see that
	 * run's upload; this is the same testimony taken after the node reports.
	 */
	public static function newest_landed(StorageSpace $space) {
		list($target, $creds, $bucket) = $space->reach();
		$base = $space->base() . BackupProfile::path_segment(BackupProfile::MANAGER) . '/';
		$objects = S3Signer::list($creds, $bucket, $base);
		if (!is_array($objects)) {
			throw new Exception('backup storage could not be listed');
		}
		return self::newest_object_time($objects);
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

	/** When a group started, as a unix time from its name's stamp; 0 for no stamp. */
	public static function start_time_of($name) {
		if (!preg_match('/(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', (string)$name, $m)) { return 0; }
		return (int)gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
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
	public static function check_shelf(array $objects, $base, array $creds, $bucket, $read = null, $space_id = 0) {
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
		}
		// Each chain's newest manifest: a version-3 chain writes one per run.
		foreach ($present as $dir => $names) {
			$newest = ShelfObject::preferredManifestName((int)$space_id, $base . $dir . '/', array_keys($names));
			if ($newest !== '') {
				$manifests[$dir] = $base . $dir . '/' . $newest;
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
	 * (epoch/name => ['key', 'size', 'last_modified']); 'envelopes', the key of
	 * each epoch's newest envelope (the one a re-seal wrote last, else its
	 * envelope.json); and 'envelope_keys', every envelope key of each epoch.
	 */
	public static function object_store(array $objects, $base) {
		$base = rtrim((string)$base, '/') . '/';
		$out = array('objects' => array(), 'envelopes' => array(), 'envelope_keys' => array());
		$written = array();
		foreach ($objects as $obj) {
			if (!is_array($obj)) { continue; }
			$key = (string)($obj['key'] ?? $obj['Key'] ?? '');
			if ($key === '' || strpos($key, $base . BackupObjects::DIR . '/') !== 0) { continue; }
			$at = BackupObjects::location_of(substr($key, strlen($base . BackupObjects::DIR . '/')));
			if ($at === null) { continue; }
			$epoch = $at['epoch'];
			$size = $obj['size'] ?? $obj['Size'] ?? null;
			if ($at['envelope']) {
				$out['envelope_keys'][$epoch][] = $key;
				// Newest written wins; on a tie a re-sealed name over envelope.json.
				$when = array((string)($obj['last_modified'] ?? $obj['LastModified'] ?? ''), $at['file'] !== BackupObjects::ENVELOPE_NAME ? 1 : 0);
				if (!isset($written[$epoch]) || $when > $written[$epoch]) {
					$written[$epoch] = $when;
					$out['envelopes'][$epoch] = $key;
				}
				continue;
			}
			$name = $at['name'];
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
	/** key => when the provider says it was written (unix time), for the keys a listing dated. */
	private static function written_times(array $objects): array {
		$out = array();
		foreach ($objects as $obj) {
			if (!is_array($obj)) { continue; }
			$key = (string)($obj['key'] ?? $obj['Key'] ?? '');
			$t = strtotime((string)($obj['last_modified'] ?? $obj['LastModified'] ?? ''));
			if ($key !== '' && $t) { $out[$key] = (int)$t; }
		}
		return $out;
	}

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
			if ($delete($o['key']) === false) { continue; }   // object lock still holds it
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
				foreach (($store['envelope_keys'][$epoch] ?? array($store['envelopes'][$epoch])) as $key) {
					if ($delete($key) !== false) { $deleted[] = $key; }
				}
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
