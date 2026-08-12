<?php
/**
 * DirectSettings - every tunable of the Joinery Direct channel, read in one
 * place with the same default the settings manifest declares.
 *
 * Each of these is a declared setting rather than a constant because they are
 * the knobs an operator turns when a deployment's shape differs from the
 * default: a box with small disks lowers the spool caps, a busy federation peer
 * raises the per-instance rate. No new rate-limiting engine and no new cap
 * mechanism — the values live here, the enforcement reuses the platform's
 * existing limiters and byte counters.
 *
 * @version 1.0
 */

class DirectSettings {

	/** Is Direct on at all? Off means: publish nothing, send nothing, refuse everything. */
	public static function enabled(): bool {
		return (string)self::raw('joinery_direct_enabled', '0') === '1';
	}

	// ── Manifest bounds, checked at preflight, identically for every recipient,
	//    every kind and every tier (instance configuration, so refusing on them
	//    discloses nothing about the recipient).

	public static function maxParts(): int {
		return max(1, (int)self::raw('joinery_direct_max_parts', '64'));
	}

	public static function maxBytesPerPart(): int {
		return max(1024, (int)self::raw('joinery_direct_max_part_bytes', (string)(100 * 1024 * 1024)));
	}

	public static function maxTotalBytes(): int {
		return max(1024, (int)self::raw('joinery_direct_max_total_bytes', (string)(250 * 1024 * 1024)));
	}

	// ── Spool byte caps at the sealed tiers. Absolute recipient-side bounds, so
	//    no number of cheap sending domains raises the ceiling the way Sybil
	//    multiplies a per-instance rate limit.

	public static function spoolDomainCapBytes(): int {
		return max(0, (int)self::raw('joinery_direct_spool_domain_cap_bytes', (string)(10 * 1024 * 1024 * 1024)));
	}

	public static function spoolAddressCapBytes(): int {
		return max(0, (int)self::raw('joinery_direct_spool_address_cap_bytes', (string)(1024 * 1024 * 1024)));
	}

	public static function spoolRetentionDays(): int {
		return max(0, (int)self::raw('joinery_direct_spool_retention_days', '30'));
	}

	// ── Rate limiting. The per-instance check counts recent preflights per
	//    verified sending domain in Direct's own request log, exactly as the
	//    mailbox forwarding limiters count the inbound email log.

	public static function preflightLimit(): int {
		return max(1, (int)self::raw('joinery_direct_preflight_limit', '120'));
	}

	public static function preflightWindowSeconds(): int {
		return max(10, (int)self::raw('joinery_direct_preflight_window', '120'));
	}

	/** How long an unredeemed delivery session lives before its parts are discarded. */
	public static function sessionTtlSeconds(): int {
		return max(60, (int)self::raw('joinery_direct_session_ttl', '900'));
	}

	/** Connect and total timeouts for an outbound Direct request. */
	public static function connectTimeout(): int {
		return max(1, (int)self::raw('joinery_direct_connect_timeout', '5'));
	}

	public static function requestTimeout(): int {
		return max(5, (int)self::raw('joinery_direct_request_timeout', '30'));
	}

	/**
	 * The domain secret behind decoy key derivation.
	 *
	 * A decoy must be DETERMINISTIC — a key that changed between probes of the
	 * same address would itself be the tell — so the secret is minted once and
	 * kept. It lives on the box at Private and travels in the relay map at
	 * Fortress, where the relay answers preflights.
	 */
	public static function decoySecret(): string {
		require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
		$stored = (string)self::raw('joinery_direct_decoy_secret', '');
		if ($stored !== '') {
			try {
				return (new SecretBox())->decrypt($stored);
			} catch (\Throwable $e) {
				// A stored secret that will not decrypt means the SecretBox key
				// changed or the row is corrupt. Minting a fresh one here would
				// silently rotate EVERY decoy — and a decoy that changes between two
				// probes of one address is precisely the existence tell decoys exist
				// to prevent. Fail closed: refuse rather than re-mint. The receiver
				// turns this into a uniform sealed-tier refusal, so no address is
				// distinguishable by the fault.
				throw new RuntimeException('Joinery Direct: the decoy secret is present but unreadable; '
					. 'refusing to re-mint it, which would rotate every decoy key. Restore the SecretBox key, '
					. 'or clear joinery_direct_decoy_secret deliberately to rotate.', 0, $e);
			}
		}
		$secret = base64_encode(random_bytes(32));
		self::persist('joinery_direct_decoy_secret', (new SecretBox())->encrypt($secret));
		return $secret;
	}

	private static function raw(string $name, string $default): string {
		$settings = Globalvars::get_instance();
		$value = $settings->get_setting($name);
		if ($value === null || $value === '' || $value === false) {
			return $default;
		}
		return (string)$value;
	}

	/**
	 * Write a managed setting the platform mints for itself.
	 *
	 * There is no Globalvars::set_setting by design, so this upserts the row
	 * directly. get_setting() re-reads a blank value from the database rather
	 * than trusting its in-request copy, so the freshly written value is
	 * readable on the next call with nothing to invalidate.
	 */
	private static function persist(string $name, string $value): void {
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$stmt = $db->prepare('INSERT INTO stg_settings (stg_name, stg_value, stg_create_time, stg_update_time)
				VALUES (?, ?, now(), now())
				ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value, stg_update_time = now()');
			$stmt->execute(array($name, $value));
		} catch (\Throwable $e) {
			error_log('DirectSettings: could not persist ' . $name . ': ' . $e->getMessage());
		}
	}
}
