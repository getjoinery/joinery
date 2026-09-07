<?php
/**
 * DnsInstallCredential — the DNS credential an installer hands in, kept for
 * the setup wizard's one DNS publish and deleted the first time it is used.
 *
 * A first-boot installer (the Linode StackScript path) already holds a DNS
 * token: it used it to create the zone and the A record. The setup wizard
 * needs the same token a few minutes later to add the mail records, and a
 * reader who has to paste it twice is a reader who may not have kept it.
 * So the installer seals it here and the wizard consumes it.
 *
 * Consume on use is the whole contract: the first publish that reads it
 * deletes it, whatever the publish's outcome — a token that failed once is
 * not worth keeping, and a token that worked has done its job. Nothing else
 * reads it, and a stale one is discarded by the reconciler (kind ephemeral),
 * never healed or flagged.
 *
 * Stored as one sealed JSON blob in the declared setting: the driver key and
 * that driver's credential fields, so the shape follows DnsProvider and no
 * host is named here.
 *
 * @version 1.0
 */
class DnsInstallCredential {

	const SETTING = 'dns_install_credential';

	/**
	 * Seal and store an installer's credential. The driver must be a
	 * registered API-credential driver; the fields are whatever that driver
	 * declares, and anything else is dropped.
	 *
	 * @throws InvalidArgumentException When the driver is unknown or not API-driven.
	 */
	public static function store(string $driver_key, array $credential): void {
		$driver_class = DnsDriverRegistry::get($driver_key);
		if ($driver_class === null || $driver_class::credentialMode() !== DnsProvider::CREDENTIAL_API) {
			throw new InvalidArgumentException("DnsInstallCredential: '$driver_key' is not a DNS driver that takes a pasted credential.");
		}
		$kept = array();
		foreach ($driver_class::credentialFields() as $field => $spec) {
			$value = trim((string)($credential[$field] ?? ''));
			if ($value !== '') {
				$kept[$field] = $value;
			}
		}
		if (!$kept) {
			throw new InvalidArgumentException('DnsInstallCredential: no credential fields were supplied.');
		}
		$blob = (new SecretBox())->seal(self::SETTING, json_encode(array(
			'driver' => $driver_key,
			'credential' => $kept,
			'time' => gmdate('Y-m-d H:i:s'),
		)));
		Setting::put(self::SETTING, $blob);
	}

	/**
	 * The stored credential, or null when there is none, it cannot be opened,
	 * or its driver no longer resolves.
	 *
	 * @return array{driver:string, credential:array<string,string>}|null
	 */
	public static function stored(): ?array {
		$raw = self::readRaw();
		$opened = (new SecretBox())->open($raw);
		if ($opened['state'] !== SecretBox::OPEN_OK) {
			return null;
		}
		$data = json_decode((string)$opened['value'], true);
		$driver_key = is_array($data) ? trim((string)($data['driver'] ?? '')) : '';
		$credential = is_array($data) && is_array($data['credential'] ?? null) ? $data['credential'] : array();
		if ($driver_key === '' || !$credential || DnsDriverRegistry::get($driver_key) === null) {
			return null;
		}
		return array('driver' => $driver_key, 'credential' => $credential);
	}

	/** Delete the stored credential. Idempotent. */
	public static function consume(): void {
		if (self::readRaw() !== '') {
			Setting::put(self::SETTING, '');
		}
	}

	/** Read straight from the table: the settings singleton may hold a stale copy. */
	private static function readRaw(): string {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
		$q->execute(array(self::SETTING));
		$v = $q->fetchColumn();
		return ($v === false || $v === null) ? '' : (string)$v;
	}
}
