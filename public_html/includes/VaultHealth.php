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
 * Each check reads its host facts through a parameter with a live default,
 * so tests/vault/vault_health_test.php can hand it a fixture and cover every
 * branch on any box.
 *
 * @version 1.1 - specs/vault_exposure_quick_fixes.md Q2-Q4: the core-dump check
 *                reads kernel.core_pattern (apport ignores the rlimit); the swap
 *                check confirms dm-crypt from sysfs and accepts zram; a fourth
 *                check keeps exception arguments out of the log
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
			self::checkExceptionArgs(),
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
	 * A crash while the vault window is open must not write the unwrapped key
	 * anywhere. Two facts decide it. The worker's core-dump size limit
	 * (`ulimit -c`, which a forked child inherits from the running worker) is
	 * the whole story only when kernel.core_pattern names a file. When it is a
	 * pipe the kernel hands the core to the named handler regardless of the
	 * rlimit: Ubuntu's apport reads the whole core from stdin into its
	 * /var/crash report, so a box with apport enabled keeps cores whatever the
	 * limit says; systemd-coredump honours the limit, so there the rlimit
	 * check stands; any other handler is unknown territory.
	 *
	 * @param array|null $facts Injected facts for tests; see coredumpFacts().
	 */
	public static function checkCoredumpsDisabled(?array $facts = null): array {
		$key = 'coredumps_disabled';
		$label = 'PHP worker core dumps are disabled';
		$facts = $facts ?? self::coredumpFacts();
		$rlimit = $facts['rlimit'];
		$pattern = trim((string)($facts['core_pattern'] ?? ''));

		if ($pattern !== '' && $pattern[0] === '|') {
			$handler = trim(substr($pattern, 1));
			if (strpos($handler, 'apport') !== false) {
				if ($facts['apport_enabled'] === null) {
					return ['key' => $key, 'label' => $label, 'state' => 'unknown',
						'reason' => 'Cores are piped to apport and its state could not be read. Check /etc/default/apport and the apport unit on the host.'];
				}
				if ($facts['apport_enabled']) {
					return ['key' => $key, 'label' => $label, 'state' => 'unmet',
						'reason' => 'Cores are piped to apport, which keeps them in /var/crash whatever the rlimit says. On the host: sudo systemctl disable --now apport, and set enabled=0 in /etc/default/apport.'];
				}
				// apport is off: the pipe target discards, and the rlimit
				// governs the plain core file apport would otherwise also write.
			} elseif (strpos($handler, 'systemd-coredump') === false) {
				return ['key' => $key, 'label' => $label, 'state' => 'unknown',
					'reason' => 'Cores are piped to "' . $handler . '", which is not a handler this check knows. Confirm on the host that it discards them.'];
			}
		}

		if ($rlimit === null) {
			return ['key' => $key, 'label' => $label, 'state' => 'unknown', 'reason' => 'Could not read the worker core-dump limit (exec() is disabled or ulimit gave no answer).'];
		}
		if ($rlimit === '0') {
			return ['key' => $key, 'label' => $label, 'state' => 'verified', 'reason' => ''];
		}
		return ['key' => $key, 'label' => $label, 'state' => 'unmet',
			'reason' => 'Core dump size limit is "' . $rlimit . '", not 0 - set rlimit_core = 0 in the FPM pool.'];
	}

	/**
	 * The live facts checkCoredumpsDisabled() decides on.
	 *
	 * rlimit: the worker's `ulimit -c` as a string, null when unreadable.
	 * core_pattern: /proc/sys/kernel/core_pattern (not namespaced, so inside a
	 *   container this is the host's - which is the right thing to read).
	 * apport_enabled: true when /etc/default/apport says enabled=1 AND the
	 *   apport unit is active, false when either says off, null when neither
	 *   could be read. Only consulted when the pattern pipes to apport.
	 */
	public static function coredumpFacts(): array {
		$rlimit = null;
		if (function_exists('exec')) {
			$output = [];
			$status = null;
			@exec('ulimit -c 2>&1', $output, $status);
			$value = trim(implode('', $output));
			if ($value !== '') {
				$rlimit = $value;
			}
		}

		$pattern = @file_get_contents('/proc/sys/kernel/core_pattern');
		$pattern = is_string($pattern) ? trim($pattern) : '';

		$apport_enabled = null;
		if (strpos($pattern, 'apport') !== false) {
			$defaults = @file_get_contents('/etc/default/apport');
			if (is_string($defaults)) {
				$apport_enabled = (bool)preg_match('/^\s*enabled\s*=\s*1\s*$/m', $defaults);
			}
			if ($apport_enabled !== false && function_exists('exec')) {
				$output = [];
				$status = null;
				@exec('systemctl is-active apport 2>/dev/null', $output, $status);
				$state = trim(implode('', $output));
				if ($state !== '') {
					$apport_enabled = ($state === 'active') && ($apport_enabled ?? true);
				}
			}
		}

		return ['rlimit' => $rlimit, 'core_pattern' => $pattern, 'apport_enabled' => $apport_enabled];
	}

	/**
	 * An uncaught exception's trace must not carry its arguments into the
	 * error log: the secret key is a string argument on the VaultCrypto open
	 * methods, and with zend.exception_ignore_args off the log would hold its
	 * leading bytes. The ini is PHP_INI_ALL, so a test can flip it.
	 */
	public static function checkExceptionArgs(): array {
		$key = 'exception_args_ignored';
		$label = 'Exception traces omit their arguments (zend.exception_ignore_args = On)';
		$value = ini_get('zend.exception_ignore_args');
		if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
			return ['key' => $key, 'label' => $label, 'state' => 'verified', 'reason' => ''];
		}
		return ['key' => $key, 'label' => $label, 'state' => 'unmet',
			'reason' => 'zend.exception_ignore_args is off - a logged exception carries the key bytes it was called with. Set zend.exception_ignore_args = On in php.ini.'];
	}

	/**
	 * Swap must be off, or every active swap device must be one whose pages
	 * never reach disk in the clear - an idle worker's pages holding the
	 * unwrapped key must not land on an unencrypted disk. Every device-mapper
	 * device states its type in /sys/block/dm-N/dm/uuid: CRYPT- is dm-crypt
	 * (verified), LVM- is a plain logical volume (unmet). zram is compressed
	 * RAM and only reaches disk with its writeback feature, which no distro
	 * enables by default (verified).
	 *
	 * @param string $proc_swaps Path of /proc/swaps, or a fixture.
	 * @param string $sys_block  Path of /sys/block, or a fixture tree.
	 */
	public static function checkSwapSafe(string $proc_swaps = '/proc/swaps', string $sys_block = '/sys/block'): array {
		$key = 'swap_off_or_encrypted';
		$label = 'Swap is off, or every active swap device is encrypted';
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
			$real = @realpath($path);
			$name = basename($real !== false ? $real : $path);

			if (strpos($name, 'zram') === 0) {
				continue;
			}
			if (strpos($name, 'dm-') === 0) {
				$uuid = @file_get_contents($sys_block . '/' . $name . '/dm/uuid');
				if (!is_string($uuid) || trim($uuid) === '') {
					return ['key' => $key, 'label' => $label, 'state' => 'unknown',
						'reason' => 'Active swap device "' . $path . '" is a device-mapper device whose type could not be read from sysfs.'];
				}
				$uuid = trim($uuid);
				if (strpos($uuid, 'CRYPT-') === 0) {
					continue;
				}
				$type = explode('-', $uuid)[0];
				return ['key' => $key, 'label' => $label, 'state' => 'unmet',
					'reason' => 'Active swap device "' . $path . '" is a ' . $type . ' mapping, not dm-crypt - its pages reach disk in the clear.'];
			}
			return ['key' => $key, 'label' => $label, 'state' => 'unmet',
				'reason' => 'Active swap device "' . $path . '" is a plain device - its pages reach disk in the clear. Encrypt it (the installer\'s housekeeping does this) or turn swap off.'];
		}
		return ['key' => $key, 'label' => $label, 'state' => 'verified', 'reason' => ''];
	}
}
?>
