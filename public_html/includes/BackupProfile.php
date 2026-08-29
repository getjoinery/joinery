<?php
/**
 * BackupProfile — whose backup this is.
 *
 * A site can be backed up by more than one party. It backs itself up, and a
 * management node managing it may take its own copies. Those are not two ways of
 * doing the same thing: they are two parties' backups, under two recovery keys,
 * on two schedules, answerable to two people.
 *
 * A profile is the unit that keeps them apart. Everything a run touches that
 * could collide with another run — the working directory, the lock, the tar
 * snapshot, the chain manifest, the envelope scratch, the bucket path, the
 * history rows, the recipient the archive is sealed to — is derived from the
 * profile rather than read from a bare setting.
 *
 * Neither profile owns the site's backups. They are peers: each party runs the
 * copies it initiated, and neither needs the other's absence to make sense.
 *
 *   site     the site's own, configured on its Backups page, sealed to its own
 *            recovery key, dependent on nothing else being alive
 *   manager  a management node's, configured and triggered there, sealed to the
 *            management node's key, which travels with the run and is never
 *            stored here
 *
 * @version 1.0
 */

class BackupProfileException extends Exception {}

class BackupProfile {

	/** The site's own backups. The default for anything that does not say. */
	const SITE = 'site';

	/** A management node's backups of this site. */
	const MANAGER = 'manager';

	/**
	 * Subdirectory of the site's working directory that the manager profile
	 * builds in. Inside `backups/` deliberately: that name is excluded from
	 * archives by every engine, so the profiles cannot archive each other, and
	 * one directory permission decision covers both.
	 */
	const MANAGER_SUBDIR = 'manager';

	/** The machine-wide "one backup at a time" lock, shared by every profile. */
	const MACHINE_LOCK = '.jy_backup_machine.lock';

	public static function names(): array {
		return array(self::SITE, self::MANAGER);
	}

	/**
	 * Accept a profile name, or say so. Unknown names throw rather than falling
	 * back to `site`: a typo that silently ran as the site profile would seal a
	 * management node's backup to the site's key and file it on the site's shelf.
	 */
	public static function normalize($name): string {
		$name = trim((string)$name);
		if ($name === '') {
			return self::SITE;
		}
		if (!in_array($name, self::names(), true)) {
			throw new BackupProfileException(
				'Unknown backup profile "' . $name . '". Known profiles: ' . implode(', ', self::names()) . '.');
		}
		return $name;
	}

	/** How this profile is described to a person. */
	public static function label($name): string {
		return (self::normalize($name) === self::MANAGER)
			? 'management node'
			: 'this site';
	}

	/**
	 * Where this profile builds, locks and sweeps.
	 *
	 * $base is the site's configured working directory. The site profile is that
	 * directory; the manager profile is a subdirectory of it. Separate
	 * directories are what give each profile its own lock, its own tar snapshot,
	 * its own chain manifests and its own local sweep — a shared snapshot alone
	 * would corrupt both chains, since each run advances it and each would then
	 * treat the other's work as already archived.
	 */
	public static function output_dir($name, string $base): string {
		$base = rtrim($base, '/');
		return (self::normalize($name) === self::MANAGER)
			? $base . '/' . self::MANAGER_SUBDIR
			: $base;
	}

	/**
	 * The path segment this profile files under in the bucket, between the slug
	 * and the run's own objects:
	 *
	 *   {path_prefix}/{slug}/{profile}/chain-20260806_031500/files-0000.tar.gz.enc
	 *
	 * Retention is driven by recorded history rather than by a bucket listing,
	 * so objects written before this segment existed keep being aged out by the
	 * same rows that created them. Nothing needs moving.
	 */
	public static function path_segment($name): string {
		return self::normalize($name);
	}

	/**
	 * The machine-wide lock path, which is the same file for every profile.
	 *
	 * Held above each profile's own lock. The per-profile lock is about
	 * correctness — two runs of one profile share a snapshot and a manifest and
	 * would corrupt both. This one is about the machine: two profiles archiving
	 * the same tree at once is twice the I/O for no extra safety, and on a shared
	 * host it is somebody else's I/O too.
	 *
	 * Always resolved from the base directory, never from the profile's own, so
	 * both profiles genuinely contend for one file.
	 */
	public static function machine_lock_path(string $base): string {
		return rtrim($base, '/') . '/' . self::MACHINE_LOCK;
	}
}
