<?php
/**
 * ServicesConnect — binding a self-hosted site to a getjoinery account
 * (specs/services_phase2_platform.md §4, E6, D2).
 *
 * Nobody types a key. The site sends its owner's browser to the authorise
 * page here carrying its hostname, its return address and a single-use
 * state; the owner signs in (or up) and approves *link this site*; this
 * mints an API key against their account and the key goes back in the
 * redirect, once. From then on the key IS the site: every service row the
 * site holds is keyed by it.
 *
 * One active key per site per account. A re-connect (the same host under the
 * same account) mints a new key, moves the site's rows to it and deactivates
 * the earlier one — a lost or rotated key is replaced this way, and two live
 * keys for one host under one account never coexist. The rows are created
 * at `unpaid` on connect: that is the site's first contact, and it is what
 * lets the account holder see the site on their Connected sites page and the
 * operator grant it a date before the site has enrolled anything.
 *
 * Disconnect is the account holder's cut-off: the key is deactivated first —
 * from that moment the site can do nothing over it — then every service the
 * site holds is released through the same path the reconcile uses. A
 * provider refusal on one release is surfaced after the others have run; it
 * never leaves the key live. A site someone was tricked into approving keeps
 * a key only until its owner finds this page.
 *
 * @version 1.1 - disconnect cuts the key before it releases the services
 */
class ServicesConnectException extends Exception {}

class ServicesConnect {

	/** apk_name is varchar(32): the key cannot be named for the host. */
	const KEY_NAME = 'Joinery services';

	/** Read + write, no delete: the seeding's own permission. */
	const KEY_PERMISSION = 3;

	/**
	 * Mint the site's key against the account, retiring the earlier one for
	 * the same host. Returns the public key and the secret plaintext — the one
	 * time the secret exists outside the site.
	 *
	 * @return array{public_key:string, secret_key:string, key_id:int}
	 */
	public static function mintKey(int $user_id, string $host): array {
		$host = JoineryServices::cleanHost($host);
		if ($host === '') {
			throw new ServicesConnectException('That is not a site hostname.');
		}
		if ($user_id <= 0) {
			throw new ServicesConnectException('A key belongs to an account.');
		}

		$public_key = 'public_' . LibraryFunctions::random_string(16);
		$secret_plaintext = 'secret_' . LibraryFunctions::random_string(16);

		$key = new ApiKey(NULL);
		$key->set('apk_usr_user_id', $user_id);
		$key->set('apk_name', self::KEY_NAME);
		$key->set('apk_public_key', $public_key);
		$key->set('apk_secret_key', ApiKey::GenerateKey($secret_plaintext));
		$key->set('apk_type', ApiKey::TYPE_MACHINE);
		$key->set('apk_permission', self::KEY_PERMISSION);
		$key->set('apk_is_active', TRUE);
		$key->save();
		$key_id = (int)$key->key;

		// The site's rows move to the new key; the keys they held go inactive.
		$retired = array();
		$have = array();
		foreach (ServiceTenant::forHost($user_id, $host) as $row) {
			$old = (int)$row->get('svt_apk_api_key_id');
			if ($old > 0 && $old !== $key_id && !isset($retired[$old])) {
				self::deactivateKey($old, $user_id);
				$retired[$old] = true;
			}
			$row->set('svt_apk_api_key_id', $key_id);
			$row->save();
			$have[(string)$row->get('svt_service')] = true;
		}
		// First contact: one row per service, unpaid, so the site is on the
		// account's page and the operator's list from now.
		foreach (ServiceTenant::SERVICES as $service) {
			if (!isset($have[$service])) {
				JoineryServices::tenant($user_id, $key_id, $service, $host);
			}
		}

		return array('public_key' => $public_key, 'secret_key' => $secret_plaintext, 'key_id' => $key_id);
	}

	/**
	 * Cut a site off: deactivate its key, then release every service it
	 * holds. Every row is tried; the first provider refusal is thrown once
	 * the rest have run, with the key already off.
	 */
	public static function disconnect(int $user_id, string $host, ?Smtp2GoClient $client = null): int {
		$host = JoineryServices::cleanHost($host);
		$rows = ServiceTenant::forHost($user_id, $host);
		if (!$rows) {
			throw new ServicesConnectException('No site by that name is connected to this account.');
		}
		$keys = array();
		foreach ($rows as $row) {
			$key_id = (int)$row->get('svt_apk_api_key_id');
			if ($key_id > 0) {
				$keys[$key_id] = true;
			}
		}
		foreach (array_keys($keys) as $key_id) {
			self::deactivateKey($key_id, $user_id);
		}
		$failed = null;
		foreach ($rows as $row) {
			try {
				JoineryServices::releaseRow($row, $client);
			} catch (\Throwable $e) {
				$failed = $failed ?? $e;
			}
		}
		if ($failed !== null) {
			throw $failed;
		}
		return count($rows);
	}

	/**
	 * The account's connected sites: host => {host, connected_time, key_id,
	 * active (the key still works), services: service => C2 status}.
	 */
	public static function sitesFor(int $user_id): array {
		$sites = array();
		$rows = new MultiServiceTenant(array('user_id' => $user_id, 'deleted' => false),
			array('svt_host' => 'ASC', 'svt_service_tenant_id' => 'ASC'));
		foreach ($rows as $row) {
			$host = (string)$row->get('svt_host');
			$key_id = (int)$row->get('svt_apk_api_key_id');
			if (!isset($sites[$host])) {
				$active = false;
				$connected = (string)$row->get('svt_create_time');
				if ($key_id > 0) {
					try {
						$key = new ApiKey($key_id, TRUE);
						if ($key->key) {
							$active = (bool)$key->get('apk_is_active') && !$key->get('apk_delete_time');
							$connected = (string)$key->get('apk_create_time') ?: $connected;
						}
					} catch (\Throwable $e) {
						// a key row that is gone: the site is simply not active
					}
				}
				$sites[$host] = array(
					'host'           => $host,
					'key_id'         => $key_id,
					'active'         => $active,
					'connected_time' => $connected,
					'services'       => array(),
				);
			}
			$sites[$host]['services'][(string)$row->get('svt_service')] = JoineryServices::statusOf($row);
		}
		return array_values($sites);
	}

	/** A return address the plane will send a key to: same host as the site, over https. */
	public static function cleanReturn(string $return, string $host): string {
		$return = trim($return);
		$parsed = parse_url($return);
		if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host']) || !empty($parsed['fragment'])) {
			return '';
		}
		$scheme = strtolower((string)$parsed['scheme']);
		if ($scheme !== 'https' && !($scheme === 'http' && self::localHost((string)$parsed['host']))) {
			return '';
		}
		if (strtolower((string)$parsed['host']) !== $host) {
			return '';
		}
		if (!empty($parsed['user']) || !empty($parsed['pass'])) {
			return '';
		}
		return $return;
	}

	/** A hostname only a developer's own box answers on. */
	private static function localHost(string $host): bool {
		$host = strtolower($host);
		return $host === 'localhost' || substr($host, -6) === '.local' || substr($host, -5) === '.test'
			|| preg_match('/^(127\.|10\.|192\.168\.)/', $host) === 1;
	}

	/** The redirect that hands the site its key, once. */
	public static function returnUrl(string $return, string $public_key, string $secret_key, string $state): string {
		$glue = strpos($return, '?') === false ? '?' : '&';
		return $return . $glue . http_build_query(array(
			'public_key' => $public_key, 'secret_key' => $secret_key, 'state' => $state,
		));
	}

	private static function deactivateKey(int $key_id, int $user_id): void {
		try {
			$key = new ApiKey($key_id, TRUE);
		} catch (\Throwable $e) {
			return;
		}
		if (!$key->key || (int)$key->get('apk_usr_user_id') !== $user_id) {
			return;
		}
		if ((bool)$key->get('apk_is_active')) {
			$key->set('apk_is_active', FALSE);
			$key->save();
		}
	}
}
