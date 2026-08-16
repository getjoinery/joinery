<?php
/**
 * DiskSpace - will this fit, and what do we tell someone when it will not?
 *
 * A job that writes a lot of bytes should find out it has nowhere to put them
 * BEFORE it starts, not on the write that fails. Running a filesystem to zero is
 * worse than refusing the job: the failure lands wherever the job happened to be,
 * other subsystems sharing the disk start failing for reasons that look unrelated,
 * and the recovery is somebody guessing which of the half-written things is safe
 * to delete.
 *
 * Three ideas, and they are deliberately separate:
 *
 *   - How much room is there? — freeBytes(), which answers NULL when it genuinely
 *     cannot tell. Unknown is not zero and is not infinity, and a caller that
 *     treats it as either will be wrong on some deployment. See roomFor().
 *
 *   - Is there room for N bytes, keeping a floor in reserve? — roomFor(). The
 *     floor exists because "exactly enough" is not enough: a disk with nothing
 *     spare breaks logging, sessions, and Postgres itself, so a job that would
 *     land the machine there is refused while it is still only a refusal.
 *
 *   - What do we say? — shortfallMessage(), in bytes a person reads, naming what
 *     was needed and what is there. Never a bare "insufficient disk space", which
 *     tells the reader nothing about whether deleting one file would fix it.
 *
 * HOW MUCH A JOB WILL WRITE IS THE CALLER'S KNOWLEDGE, NOT THIS CLASS'S. An
 * archive import writes some multiple of the archive it reads; a backup writes
 * some fraction of the database. Those ratios belong with the subsystems that
 * know them. This class only ever answers "does N fit".
 *
 * @version 1.0
 */

class DiskSpace {

	/**
	 * Bytes left untouched even by a job that would otherwise fit.
	 *
	 * 1 GiB, chosen as the smallest amount that keeps a Linux host WORKING rather
	 * than merely booted: Postgres needs room for WAL, the error log needs room to
	 * record what went wrong, and a full disk is the failure mode that makes every
	 * other failure harder to read.
	 */
	const DEFAULT_FLOOR_BYTES = 1073741824;

	/**
	 * Free bytes on the filesystem holding $path, or NULL when it cannot be known.
	 *
	 * Walks up to the nearest ancestor that exists, because the interesting
	 * question is nearly always about a directory a job is ABOUT to write into —
	 * asking about a path that is not there yet would answer false and read as
	 * "no space" when it means "no such directory".
	 *
	 * NULL is returned when disk_free_space is disabled by the host or fails. A
	 * caller must decide what to do with not-knowing; this will not decide by
	 * inventing a number.
	 */
	public static function freeBytes(string $path): ?int {
		if (!function_exists('disk_free_space')) {
			return null;
		}
		$candidate = $path === '' ? '/' : $path;
		// Bounded: a path cannot have more ancestors than it has separators, and
		// dirname('/') is '/', which would otherwise spin.
		for ($i = 0; $i < 64; $i++) {
			if (is_dir($candidate)) {
				break;
			}
			$parent = dirname($candidate);
			if ($parent === $candidate) {
				break;
			}
			$candidate = $parent;
		}
		if (!is_dir($candidate)) {
			return null;
		}
		$free = @disk_free_space($candidate);
		if ($free === false || !is_numeric($free)) {
			return null;
		}
		return (int)$free;
	}

	/**
	 * The least free space across several paths, ignoring the ones it cannot read.
	 *
	 * Paths given together may sit on different filesystems — a job writing to two
	 * of them is constrained by the tighter one, so that is what is returned. NULL
	 * only when NONE of the paths could be measured.
	 */
	public static function leastFreeBytes(array $paths): ?int {
		$least = null;
		foreach ($paths as $path) {
			$free = self::freeBytes((string)$path);
			if ($free === null) {
				continue;
			}
			if ($least === null || $free < $least) {
				$least = $free;
			}
		}
		return $least;
	}

	/**
	 * Is there room to write $bytes to every one of $paths and still leave $floor?
	 *
	 * UNKNOWABLE FREE SPACE ANSWERS TRUE. A host that hides disk_free_space would
	 * otherwise have every guarded feature permanently switched off by a check that
	 * is meant to be a safety net, not a gate. The guard's job is to catch the
	 * disk it CAN see filling up; it is not an entitlement check.
	 */
	public static function roomFor(int $bytes, array $paths, int $floor = self::DEFAULT_FLOOR_BYTES): bool {
		$free = self::leastFreeBytes($paths);
		if ($free === null) {
			return true;
		}
		return $free >= (max(0, $bytes) + max(0, $floor));
	}

	/**
	 * Why it does not fit, in a sentence someone can act on — or '' when it does.
	 *
	 * Says all three numbers, because only their relationship tells the reader
	 * whether this is "delete one thing" or "get a bigger disk".
	 */
	public static function shortfallMessage(int $bytes, array $paths, int $floor = self::DEFAULT_FLOOR_BYTES): string {
		if (self::roomFor($bytes, $paths, $floor)) {
			return '';
		}
		$free = self::leastFreeBytes($paths);
		return 'Not enough disk space: this needs about ' . self::format($bytes)
			. ' and keeps ' . self::format($floor) . ' in reserve, but only '
			. self::format((int)$free) . ' is free. Free up '
			. self::format(max(0, $bytes + $floor - (int)$free)) . ' and try again.';
	}

	/** Bytes as something a person reads without counting digits. */
	public static function format(int $bytes): string {
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$i = 0;
		$n = max(0, $bytes);
		while ($n >= 1024 && $i < count($units) - 1) {
			$n /= 1024;
			$i++;
		}
		return ($i === 0 ? (int)$n : round($n, 1)) . ' ' . $units[$i];
	}
}
?>
