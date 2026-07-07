<?php
/**
 * VaultHealth - host-hardening checks for the Sealed Vault's unlock window.
 *
 * The unwrapped secret key lives only in APCu for the duration of a window
 * (includes/VaultUnlock.php). These checks verify the three host facts that
 * keep it off any disk path even so: APCu backed by anonymous shared memory
 * (never a file an attacker with disk access could read), the PHP worker's
 * core dumps disabled (a crash must not write the key to a core file), and
 * swap off or encrypted (an idle worker's pages must not land on disk in the
 * clear). Best-effort and advisory — a check that cannot be verified from
 * within PHP reports 'unknown', never a false pass.
 *
 * Unlike a plugin's provisioners (declared in plugin.json, surfaced on
 * /admin/admin_plugins - docs/plugin_developer_guide.md § Declaring Host
 * Provisioners), this is core and has no such registry to plug into yet, so
 * it is surfaced directly: informationally at vault setup
 * (logic/vault_setup_verify_logic.php) and via the CLI equivalent,
 * maintenance_scripts/dev_tools/check_vault_health.php.
 *
 * @version 1.0
 */
class VaultHealth {

	/**
	 * Run every check and return a flat result list.
	 * @return array<array{key:string,label:string,state:string,reason:string}>
	 *         state is one of 'verified' | 'unmet' | 'unknown'.
	 */
	public static function runAll(): array {
		return [
			self::checkApcuAnonymous(),
			self::checkCoredumpsDisabled(),
			self::checkSwapSafe(),
		];
	}

	/**
	 * apc.mmap_file_mask must be empty - APCu's shared memory segment is then
	 * anonymous (POSIX shm), never a file-backed mmap an attacker with disk
	 * access could read the unwrapped key out of.
	 */
	public static function checkApcuAnonymous(): array {
		$key = 'apcu_anonymous_shm';
		$label = 'APCu uses anonymous shared memory (apc.mmap_file_mask unset)';
		if (!extension_loaded('apcu')) {
			return ['key' => $key, 'label' => $label, 'state' => 'unknown', 'reason' => 'ext-apcu is not loaded.'];
		}
		$mask = ini_get('apc.mmap_file_mask');
		if ($mask === '' || $mask === false) {
			return ['key' => $key, 'label' => $label, 'state' => 'verified', 'reason' => ''];
		}
		return ['key' => $key, 'label' => $label, 'state' => 'unmet',
			'reason' => 'apc.mmap_file_mask is set to "' . $mask . '" - APCu is backed by a file on disk, not anonymous memory.'];
	}

	/**
	 * The PHP worker's core-dump size limit must be 0 - a crash while the
	 * vault window is open must not write the unwrapped key to a core file.
	 * PHP has no getrlimit() without a dedicated extension, so this shells
	 * out to `ulimit -c`, which a forked child inherits from the running
	 * worker's actual limit.
	 */
	public static function checkCoredumpsDisabled(): array {
		$key = 'coredumps_disabled';
		$label = 'PHP worker core dumps are disabled (rlimit_core = 0)';
		if (!function_exists('exec')) {
			return ['key' => $key, 'label' => $label, 'state' => 'unknown', 'reason' => 'exec() is disabled - cannot check the worker rlimit.'];
		}
		$output = [];
		$status = null;
		@exec('ulimit -c 2>&1', $output, $status);
		$value = trim(implode('', $output));
		if ($value === '0') {
			return ['key' => $key, 'label' => $label, 'state' => 'verified', 'reason' => ''];
		}
		if ($value === '') {
			return ['key' => $key, 'label' => $label, 'state' => 'unknown', 'reason' => 'Could not read the worker core-dump limit.'];
		}
		return ['key' => $key, 'label' => $label, 'state' => 'unmet',
			'reason' => 'Core dump size limit is "' . $value . '", not 0 - set rlimit_core = 0 in the FPM pool.'];
	}

	/**
	 * Swap must be off, or every active swap device must be an encrypted
	 * (dm-crypt/LUKS) mapping - an idle worker's pages holding the unwrapped
	 * key must never land on an unencrypted disk.
	 */
	public static function checkSwapSafe(): array {
		$key = 'swap_off_or_encrypted';
		$label = 'Swap is off, or every active swap device is encrypted';
		$proc_swaps = '/proc/swaps';
		if (!is_readable($proc_swaps)) {
			return ['key' => $key, 'label' => $label, 'state' => 'unknown', 'reason' => '/proc/swaps is not readable on this host.'];
		}
		$lines = @file($proc_swaps, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			return ['key' => $key, 'label' => $label, 'state' => 'unknown', 'reason' => 'Could not read /proc/swaps.'];
		}
		$devices = array_slice($lines, 1); // first line is the column header
		if (empty($devices)) {
			return ['key' => $key, 'label' => $label, 'state' => 'verified', 'reason' => ''];
		}
		foreach ($devices as $line) {
			$path = trim(explode(' ', $line)[0] ?? '');
			// Heuristic: an encrypted swap device is mapped through dm-crypt,
			// which always shows up under /dev/mapper/.
			if (strpos($path, '/dev/mapper/') !== 0) {
				return ['key' => $key, 'label' => $label, 'state' => 'unmet',
					'reason' => 'Active swap device "' . $path . '" is not a dm-crypt mapping - verify it is encrypted.'];
			}
		}
		return ['key' => $key, 'label' => $label, 'state' => 'unknown',
			'reason' => 'Swap is active on an encrypted-looking (dm-crypt) device - encryption could not be independently confirmed from PHP.'];
	}
}
?>
