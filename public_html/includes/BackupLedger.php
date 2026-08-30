<?php
/**
 * BackupLedger — what this machine knows it uploaded, and what those bytes were.
 *
 * One line of defence, against one attacker: the party that chooses where a
 * restore's bytes come from.
 *
 * A restore over the agent channel fetches an archive from a bucket the
 * MANAGEMENT NODE owns, using a URL the management node composed, and loads it
 * as root over a live database. The operator approving that restore approves a
 * NAME and a date; they never see the bytes. So without something on this
 * machine that remembers what it actually wrote, a management node that had been
 * compromised could serve anything at all under an approved name:
 *
 *   - FORGERY. Nothing structurally forces a backup to be sealed
 *     (bkh_encrypted defaults to false), so an unsealed artifact's content is
 *     arbitrary — attacker SQL loaded after DROP SCHEMA public CASCADE, or an
 *     attacker project tree unpacked over the site.
 *   - REPLAY, which sealing does not stop at all. This machine's own genuine
 *     month-old archive, served under a fresh-looking name. Every signature and
 *     every envelope checks out, because it really is this machine's backup —
 *     just not the one the operator asked for.
 *
 * The ledger answers both by being written at UPLOAD time, on the machine that
 * made the archive, before the bytes are anywhere a plane can reach them. A
 * download that does not match refuses. Replay under the archive's OWN name
 * still works, and that is correct: the operator is looking at the real name and
 * the real date. Replay under a different name is what gets refused.
 *
 * WHERE IT LIVES, AND WHY THAT ADDRESS AND NOT ANOTHER. `config/backup-ledger`,
 * beside config/backup_site_key — the other artifact that identifies one machine
 * as the maker of its own backups. Three requirements picked the address, and
 * every other candidate failed one of them:
 *
 *   - IT MUST SURVIVE A CONTAINER REBUILD. On a container node, config/ is a
 *     named volume and the rest of the filesystem is the container's writable
 *     layer. A ledger under /var/lib would be wiped by a rebuild, and since a
 *     ledger only records what has been uploaded SINCE, the recovery path would
 *     stay broken for as long as the chain is old — up to a full interval. A
 *     safety file that disappears on a routine operation is worse than none,
 *     because nobody finds out until they need it.
 *   - IT MUST SURVIVE A PROJECT RESTORE. config/ travels inside a project
 *     archive, so restore_project.sh drops this directory from the staged copy
 *     the same way it drops Globalvars_site.php and backup_site_key: they are
 *     the MACHINE's, not the backup's. Without that, the first restore would
 *     overwrite the record that vouches for the second.
 *   - IT MUST NOT BE WRITABLE BY THE WEB TIER. The directory and its files are
 *     0700/0600, and both this class and the agent REFUSE a ledger that group
 *     or other can write — the check is on the mode, not on the owner, because
 *     backups legitimately run as root on a managed node and as the site user
 *     elsewhere, and demanding root would refuse a ledger written by exactly the
 *     party it is meant to trust. Note the honest limit: config/ itself is writable by the
 *     site user on some installs, so a compromised web tier ON THIS MACHINE
 *     could replace the directory wholesale. That is not what the ledger is for.
 *     Its adversary is the MANAGEMENT NODE — the party that chooses a restore's
 *     bytes and cannot reach this file at all. A machine whose own web tier is
 *     suspect is not a machine to restore in place; it is one to rebuild, and
 *     that is the scope rule the whole restore mechanism is written under.
 *
 * A machine whose backups run as an unprivileged user records nothing here, and
 * that is reported rather than hidden — an unledgered artifact is one this
 * machine will not accept back over the agent channel. On a managed node the
 * backups that matter are taken by the root agent (the backup_run primitive), so
 * they are ledgered as a matter of course.
 *
 * @version 1.2 - untrusted() — a ledger group or other can write is refused here, not only by
 *                the agent, so a download refuses before the bytes move instead of after
 * @version 1.1 - a name that is legitimately rewritten (manifest.json, once per chain run)
 *                keeps its earlier versions, and verify() reports the version that MATCHED
 *                rather than the newest. Keeping only the newest refused an already-approved
 *                chain restore whenever a backup landed during the approval window
 * @version 1.0
 */

class BackupLedger {

	/**
	 * Directory name under the site's config/. NOT configurable, on either side
	 * of the channel: a path read from a setting is a path something else can
	 * move, and moving it somewhere writable is the whole attack. The agent
	 * derives the same address from its own site root.
	 */
	const DIR_NAME = 'backup-ledger';

	/**
	 * Directory and file modes: closed to group and other.
	 *
	 * NOT world-readable, which an earlier version made them on the reasoning
	 * that "any surface can show what is recorded". Nothing needs to show it,
	 * and the permissions a deploy-time sweep would otherwise settle on (770 in
	 * production, 777 in dev) meant anything with a shell could rewrite the one
	 * file a restore consults to decide whether bytes are this machine's own.
	 * fix_permissions.sh pins this directory to match, and the agent REFUSES a
	 * ledger that is group- or other-writable — so these two numbers and that
	 * pin have to stay in agreement.
	 */
	const DIR_MODE = 0700;

	/** File mode, same reasoning. */
	const FILE_MODE = 0600;

	/**
	 * Entries kept per profile. The ledger has to outlive the artifact — a
	 * restore may reach for something months old — so it is bounded by count
	 * rather than by age. Oldest recorded entries are evicted first.
	 */
	const MAX_ENTRIES = 5000;

	/**
	 * Earlier versions kept for a name that gets rewritten.
	 *
	 * In practice only manifest.json ever has any, and a chain starts a fresh
	 * full every seven days — so eight covers a chain's whole life with room
	 * over. It is bounded at all because an unbounded list on a long-lived name
	 * is a file that grows without anyone deciding it should.
	 */
	const MAX_PREVIOUS = 8;

	/**
	 * Where the ledger lives. A method rather than a constant so a test can
	 * point a subclass somewhere harmless; nothing in production overrides it,
	 * and there is no setting that could.
	 */
	public static function dir() {
		return rtrim(PathHelper::getSiteRoot(), '/') . '/config/' . static::DIR_NAME;
	}

	/** Ledger file for one profile. */
	public static function path($profile) {
		return static::dir() . '/' . self::profile_key($profile) . '.json';
	}

	/**
	 * Record one uploaded artifact.
	 *
	 * Hashing happens here, from the file on disk, at the moment it went to the
	 * bucket. Returns false when this process cannot write the ledger — which is
	 * a fact the caller must report, not swallow: a backup that ran fine but was
	 * not ledgered cannot be restored over the agent channel.
	 *
	 * @param string $profile   site | manager
	 * @param string $relname   Name relative to the profile's backup directory —
	 *                          'db-2026….sql.gz.enc', or 'chain-…/files-0001.tar.gz.enc'
	 *                          for a chain artifact. This is exactly what a
	 *                          download will ask for.
	 * @param string $path      Absolute path of the file that was uploaded.
	 * @param string $object_key The bucket key it went to, recorded for forensics.
	 */
	public static function record($profile, $relname, $path, $object_key = '') {
		$relname = self::normalize_relname($relname);
		if ($relname === '' || !is_file($path)) {
			return false;
		}
		$sha = @hash_file('sha256', $path);
		if (!is_string($sha) || $sha === '') {
			return false;
		}

		$entries = static::read($profile);

		// A NAME CAN LEGITIMATELY BE REWRITTEN, and when it is, the version it
		// used to hold is still one this machine uploaded.
		//
		// This matters for exactly one file and the rest of the ledger never
		// exercises it. Chain artifacts are named per run (files-0003.tar.gz.enc)
		// and written once, so for them "the recorded version" and "the only
		// version" are the same thing. manifest.json is rewritten by every run of
		// its chain, under a stable name — that is what a growing chain IS.
		//
		// Keeping only the newest turned that into a bug with a nasty shape: a
		// chain staged for restore, then a scheduled backup landing while the
		// operator was reading the approval screen, and the approved restore
		// refused at the last step because the manifest on disk was no longer
		// the newest one recorded. The ledger's question is "did this machine
		// make these bytes", not "are these the newest bytes it made", and the
		// second question was never a designed property — it was an accident of
		// keying a map by name.
		$existing = isset($entries[$relname]) && is_array($entries[$relname]) ? $entries[$relname] : null;
		$previous = array();
		if ($existing !== null && (string)($existing['sha256'] ?? '') !== '' && $existing['sha256'] !== $sha) {
			$previous = array_values(array_filter((array)($existing['previous'] ?? []), 'is_array'));
			array_unshift($previous, array(
				'sha256'        => (string)$existing['sha256'],
				'bytes'         => (int)($existing['bytes'] ?? 0),
				'uploaded_time' => (string)($existing['uploaded_time'] ?? ''),
			));
			$previous = array_slice($previous, 0, self::MAX_PREVIOUS);
		} elseif ($existing !== null) {
			// Re-recorded with the same bytes: carry the history unchanged
			// rather than dropping it.
			$previous = array_values(array_filter((array)($existing['previous'] ?? []), 'is_array'));
		}

		$entries[$relname] = array(
			'sha256'        => $sha,
			'bytes'         => (int)@filesize($path),
			'uploaded_time' => gmdate('Y-m-d H:i:s'),
			'object_key'    => (string)$object_key,
		);
		if ($previous) {
			$entries[$relname]['previous'] = $previous;
		}

		if (count($entries) > self::MAX_ENTRIES) {
			// Oldest recorded first. uasort keeps the association, which is the
			// whole index — a sort that dropped the keys would empty the ledger.
			uasort($entries, function ($a, $b) {
				return strcmp((string)($a['uploaded_time'] ?? ''), (string)($b['uploaded_time'] ?? ''));
			});
			$entries = array_slice($entries, count($entries) - self::MAX_ENTRIES, null, true);
		}

		return static::write($profile, $entries);
	}

	/** What this machine recorded for one artifact, or null. */
	public static function lookup($profile, $relname) {
		$entries = static::read($profile);
		$relname = self::normalize_relname($relname);
		return isset($entries[$relname]) && is_array($entries[$relname]) ? $entries[$relname] : null;
	}

	/**
	 * Does the file at $path match what this machine recorded under $relname?
	 *
	 * Returns ['ok' => bool, 'reason' => string, 'entry' => array|null]. The
	 * reason is written for an operator reading a job transcript during a
	 * restore, so it says which of the three things went wrong: nothing was
	 * recorded, the bytes differ, or the ledger itself is not there.
	 */
	public static function verify($profile, $relname, $path) {
		$untrusted = static::untrusted($profile);
		if ($untrusted !== '') {
			return array('ok' => false, 'reason' => $untrusted, 'entry' => null);
		}

		$relname = self::normalize_relname($relname);
		$entry = static::lookup($profile, $relname);

		if ($entry === null) {
			// Two very different situations produce this, and the benign one is
			// far more common in the days after an upgrade: the ledger only
			// records uploads made since it existed, so every archive older
			// than it is unrecognised. Naming that first stops a routine
			// "this predates the ledger" reading as an attack — and the answer
			// to it is the same either way, which is why refusing is still
			// right.
			$reason = static::exists($profile)
				? 'this machine has no record of uploading ' . $relname
					. '. Either it was uploaded before this machine started keeping an upload ledger, '
					. 'or it is not the archive it is being offered as — and this machine cannot tell '
					. 'those apart, so it will not load it'
				: 'this machine has no upload ledger for the ' . self::profile_key($profile)
					. ' profile yet, so it cannot confirm any archive is one it made. It starts one on '
					. 'its next backup run';
			return array('ok' => false, 'reason' => $reason, 'entry' => null);
		}

		if (!is_file($path)) {
			return array('ok' => false, 'reason' => 'the downloaded file is not there', 'entry' => $entry);
		}

		$sha = @hash_file('sha256', $path);
		if (!is_string($sha) || $sha === '') {
			return array('ok' => false, 'reason' => 'the downloaded file could not be read', 'entry' => $entry);
		}

		// Current version first, then the earlier ones this name has held. The
		// MATCHED entry is what comes back, not the newest — a caller reporting
		// an archive's age to a person must report the age of the bytes in front
		// of them, and on the approval screen that date is the whole point.
		$matched = self::match_version($entry, $sha);
		if ($matched === null) {
			return array(
				'ok'     => false,
				'reason' => 'the bytes offered as ' . $relname . ' are not bytes this machine uploaded '
					. 'under that name (recorded ' . substr((string)$entry['sha256'], 0, 16)
					. '…, received ' . substr((string)$sha, 0, 16) . '…)',
				'entry'  => $entry,
			);
		}

		return array('ok' => true, 'reason' => '', 'entry' => $matched);
	}

	/**
	 * Which recorded version of a name these bytes are, or null for none.
	 *
	 * Constant-time on each comparison. The list is short and every element is a
	 * hash this machine wrote, so there is nothing here an attacker can lengthen.
	 */
	private static function match_version(array $entry, $sha) {
		if (hash_equals((string)($entry['sha256'] ?? ''), $sha)) {
			return $entry;
		}
		foreach ((array)($entry['previous'] ?? []) as $old) {
			if (is_array($old) && hash_equals((string)($old['sha256'] ?? ''), $sha)) {
				return $old;
			}
		}
		return null;
	}

	/**
	 * Is this ledger one worth believing? Returns '' when it is, or the reason
	 * it is not.
	 *
	 * ANYTHING THAT CAN WRITE THE LEDGER CAN AUTHORISE A RESTORE. The record is
	 * the only thing standing between an approved NAME and arbitrary bytes, so a
	 * ledger the group or the world can rewrite does not weaken the check, it
	 * inverts it: whoever rewrote it decides what this machine believes it
	 * uploaded, and the check then reports success. Refusing is the only honest
	 * answer, and it is the same answer the agent gives before it will run a
	 * restore — the two have to agree, or a download succeeds and the restore it
	 * was for refuses.
	 *
	 * The mode is the test, not the owner. See the header.
	 */
	public static function untrusted($profile) {
		$dir  = static::dir();
		$path = static::path($profile);

		foreach (array($dir, $path) as $target) {
			if (!file_exists($target)) {
				continue;   // absence is a different answer, given by exists()
			}
			$perms = @fileperms($target);
			if ($perms === false) {
				continue;
			}
			if (($perms & 0022) !== 0) {
				return 'this machine\'s upload ledger (' . $target . ') can be written by users other '
					. 'than its owner, so what it records is not evidence of anything. Restore its '
					. 'permissions (' . decoct(self::DIR_MODE) . ' on the directory, '
					. decoct(self::FILE_MODE) . ' on the files) before restoring from a backup';
			}
		}
		return '';
	}

	/** Is there a ledger for this profile at all? */
	public static function exists($profile) {
		return is_file(static::path($profile));
	}

	/**
	 * Can this process record? Answered by trying to make the directory, so the
	 * first root-run backup on a machine creates it and every later caller gets
	 * a truthful yes or no rather than a guess about which user it is.
	 */
	public static function is_recordable() {
		$dir = static::dir();
		if (!is_dir($dir)) {
			@mkdir($dir, self::DIR_MODE, true);
			@chmod($dir, self::DIR_MODE);
		}
		return is_dir($dir) && is_writable($dir);
	}

	/** Every recorded artifact for a profile, newest first. For display. */
	public static function entries($profile) {
		$entries = static::read($profile);
		uasort($entries, function ($a, $b) {
			return strcmp((string)($b['uploaded_time'] ?? ''), (string)($a['uploaded_time'] ?? ''));
		});
		return $entries;
	}

	// ── internals ──

	private static function read($profile) {
		$path = static::path($profile);
		if (!is_file($path)) {
			return array();
		}
		$decoded = json_decode((string)@file_get_contents($path), true);
		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Atomic replace: write beside, then rename. A ledger truncated by a crash
	 * mid-write would refuse every artifact recorded before it, which turns a
	 * power cut into an unrestorable machine.
	 */
	private static function write($profile, array $entries) {
		if (!static::is_recordable()) {
			return false;
		}
		$path = static::path($profile);
		$tmp  = $path . '.' . getmypid() . '.tmp';
		$body = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($body === false || @file_put_contents($tmp, $body) === false) {
			@unlink($tmp);
			return false;
		}
		@chmod($tmp, self::FILE_MODE);
		if (!@rename($tmp, $path)) {
			@unlink($tmp);
			return false;
		}
		return true;
	}

	/** site | manager, and nothing else can name a file here. */
	private static function profile_key($profile) {
		return ((string)$profile === 'manager') ? 'manager' : 'site';
	}

	/**
	 * A name relative to the backup directory, and never anything else. A
	 * traversal here would let a ledger entry be looked up for a path outside
	 * the backup tree, so the separator is allowed only between plain segments.
	 */
	private static function normalize_relname($relname) {
		// A leading slash is REFUSED, not trimmed. Trimming it would quietly
		// turn "/etc/passwd" into a lookup for "etc/passwd" and record it as
		// though that were what the caller asked for — and the agent, which
		// reads these keys, would then have to make the identical guess to
		// agree. Both sides refuse instead, so a key means one thing.
		$relname = (string)$relname;
		if ($relname === '' || strlen($relname) > 512) {
			return '';
		}
		if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$#', $relname)) {
			return '';
		}
		if (strpos($relname, '..') !== false) {
			return '';
		}
		return $relname;
	}
}
