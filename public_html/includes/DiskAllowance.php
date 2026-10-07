<?php
/**
 * DiskAllowance - this site's own share of a shared host's disk, and the
 * refusal when it is used up (specs/multi_tenant_docker_hosts.md WP4, S13).
 *
 * A site on a multi-tenant host has a disk allowance: one XFS project over all
 * of its volumes, whose hard limit is the allowance plus 10%. The host writes
 * the allowance to config/disk_allowance (docker_disk_pool.sh allow). A site
 * with no such file has no allowance, and everything here answers as if the
 * disk were its own: nothing refused, no figure.
 *
 * WHY THE SITE REFUSES BEFORE THE DISK DOES. At the XFS hard limit every write
 * fails. PostgreSQL stops when it cannot write its log; with the database down
 * nobody can sign in to delete anything, and the notice that would say why
 * cannot render. So the site stops taking new uploads and stored mail at the
 * allowance itself, in plain words naming the way out, and the 10% above it is
 * left for the database, logs and system writes.
 *
 * WHAT IS MEASURED. XFS reports a project's limit and use as the size and use
 * of the filesystem to any directory that carries the project, so statfs on the
 * uploads directory gives the whole site's figure: everything its volumes hold
 * but backups and deploy, which are the platform's.
 *
 * @version 1.1 - an allowance whose limit is not in force (the disk seen is far bigger than it) is no
 *                allowance: the disk seen is then the whole pool (reviewer2 B1)
 * @version 1.0
 */

class DiskAllowance {

	/**
	 * The disk a site with its limit in force sees is its allowance plus 10%;
	 * one much bigger than that is the pool, seen because the limit is gone.
	 */
	const IN_FORCE_FACTOR = 1.25;

	/** The file, relative to the site root, the host writes the allowance to. */
	const ALLOWANCE_FILE = 'config/disk_allowance';

	/** Replaces the disk for a test: returns [allowance|null, used|null, disk total|null]. */
	private static $reader = null;

	/** Read through $reader instead of the disk, or the disk again with null. Tests only. */
	public static function readWith(?callable $reader): void {
		self::$reader = $reader;
	}

	/**
	 * The allowance and what is used of it, in bytes, or null when this site
	 * has no allowance. used is null when the disk would not say.
	 *
	 * @return array{allowance:int,used:?int,percent:?int,full:bool}|null
	 */
	public static function state(): ?array {
		if (self::$reader !== null) {
			$read = (self::$reader)();
			$allowance = $read[0] ?? null;
			$used = $read[1] ?? null;
			$total = $read[2] ?? null;
		} else {
			$allowance = self::readAllowance();
			list($total, $used) = ($allowance === null) ? array(null, null) : self::readDisk();
		}
		if ($allowance === null || $allowance <= 0) {
			return null;
		}
		// XFS reports the project's figures only while its limit is in force;
		// without one the disk seen is the whole pool. An allowance with no
		// limit behind it is no allowance, never a pool's worth of other
		// sites' bytes counted as this site's.
		if ($total !== null && $total > $allowance * self::IN_FORCE_FACTOR) {
			return null;
		}
		return array(
			'allowance' => (int)$allowance,
			'used'      => ($used === null) ? null : max(0, (int)$used),
			'percent'   => ($used === null) ? null : (int)floor(max(0, $used) * 100 / $allowance),
			'full'      => ($used !== null && $used >= $allowance),
		);
	}

	/** Is this site at or over its allowance? False with none, or when unknown. */
	public static function isFull(): bool {
		$s = self::state();
		return $s !== null && $s['full'];
	}

	/**
	 * Why N more bytes will not fit in this site's allowance, or '' when they
	 * will (or there is no allowance, or its use is unknown). A refusal names
	 * what is used and the way out.
	 */
	public static function refusal(int $bytes = 0): string {
		$s = self::state();
		if ($s === null || $s['used'] === null) {
			return '';
		}
		if ($s['used'] + max(0, $bytes) <= $s['allowance'] && !$s['full']) {
			return '';
		}
		return 'This site has used ' . DiskSpace::format(min($s['used'], $s['allowance'])) . ' of its '
			. DiskSpace::format($s['allowance']) . ' of disk space'
			. ($s['full'] ? '' : ', and this needs ' . DiskSpace::format(max(0, $bytes)) . ' more')
			. '. Delete files or stored messages you no longer need to make room, or move the site to a server of its own.';
	}

	/** The allowance in bytes from the host's file, or null. */
	private static function readAllowance(): ?int {
		$path = rtrim(PathHelper::getSiteRoot(), '/') . '/' . self::ALLOWANCE_FILE;
		if (!is_file($path)) {
			return null;
		}
		$v = trim((string)@file_get_contents($path, false, null, 0, 32));
		return ctype_digit($v) ? (int)$v : null;
	}

	/** The disk the site's project shows, as [total, used] (statfs of a directory in it), nulls when unknown. */
	private static function readDisk(): array {
		$dir = rtrim(PathHelper::getSiteRoot(), '/') . '/uploads';
		if (!is_dir($dir) || !function_exists('disk_total_space') || !function_exists('disk_free_space')) {
			return array(null, null);
		}
		$total = @disk_total_space($dir);
		$free = @disk_free_space($dir);
		if ($total === false || $free === false) {
			return array(null, null);
		}
		return array((int)$total, max(0, (int)($total - $free)));
	}
}
