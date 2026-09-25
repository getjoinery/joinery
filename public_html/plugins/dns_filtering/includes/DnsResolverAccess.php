<?php
/**
 * DnsResolverAccess - the keys the DNS servers read this site with.
 *
 * Each DNS server fetches its data from the dns_filtering/resolver_snapshot
 * action with a machine key that can call that action and nothing else:
 * read-only, scoped to the action, and restricted to the server's own IPv4
 * address. This class mints and revokes those keys for the "DNS server
 * access" panel on the plugin's settings page.
 *
 * The keys belong to a service account the plugin creates, not to the admin
 * who issued them: every key carries a user, a deleted user's keys stop
 * authenticating, and the action runs as that user. The account has
 * permission 0, no password, password recovery disabled, and an address on
 * the reserved .invalid domain, so it can neither sign in nor receive mail.
 * (server_manager's provisioning service user, ProvisioningSetup, is the
 * same kind of account.) User refuses to delete it while
 * it owns a live scoped key.
 *
 * @version 1.0
 */
class DnsResolverAccess {

	const ACTION = 'dns_filtering/resolver_snapshot';

	const ACCOUNT_SETTING = 'dns_filtering_resolver_user_id';

	/**
	 * One slot per DNS server: the setting holding its public address (which the
	 * key's restriction copies) and the setting recording its key's id.
	 */
	const SLOTS = array(
		'primary' => array(
			'label'       => 'Primary DNS server',
			'ip_setting'  => 'dns_filtering_dns_server_ip',
			'key_setting' => 'dns_filtering_resolver_key_primary',
		),
		'secondary' => array(
			'label'       => 'Secondary DNS server',
			'ip_setting'  => 'dns_filtering_dns_secondary_server_ip',
			'key_setting' => 'dns_filtering_resolver_key_secondary',
		),
	);

	/**
	 * The service account, created on first use when $create is true.
	 * Returns null when there is none and $create is false.
	 */
	public static function serviceAccount(bool $create = false): ?User {
		$id = (int)Globalvars::get_instance()->get_setting(self::ACCOUNT_SETTING);
		if ($id > 0) {
			$user = new User($id, TRUE);
			if ($user->key && !$user->get('usr_delete_time')) {
				return $user;
			}
		}
		if (!$create) {
			return null;
		}

		// The random part keeps the address from being claimable in advance
		// by an ordinary signup; the account is found by its id, never by it.
		$user = new User(NULL);
		$user->set('usr_first_name', 'DNS servers');
		$user->set('usr_last_name', '(service account)');
		$user->set('usr_email', 'dns-servers-' . bin2hex(random_bytes(6)) . '@service.invalid');
		$user->set('usr_password', NULL);
		// A machine account: no password to recover, and no mailbox to recover into.
		$user->set('usr_password_recovery_disabled', TRUE);
		$user->set('usr_permission', 0);
		$user->set('usr_timezone', 'UTC');
		$user->save();
		$user->load();
		Setting::put(self::ACCOUNT_SETTING, $user->key);
		return $user;
	}

	/**
	 * Mint a key for one DNS server, revoking any key that slot already held.
	 * Returns ['api_key' => ApiKey, 'secret_key' => plaintext]; the plaintext
	 * exists only in this return value and is never stored or logged.
	 *
	 * @throws SystemDisplayableError for an unknown slot, or a server with no
	 *   IPv4 address set yet.
	 */
	public static function issueKey(string $slot): array {
		$def = self::slot($slot);
		$ip = trim((string)Globalvars::get_instance()->get_setting($def['ip_setting']));
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			throw new SystemDisplayableError(
				'Set the ' . strtolower($def['label']) . '\'s public IPv4 address first: the key only works from that address.');
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$own_transaction = !$dblink->inTransaction();
		if ($own_transaction) {
			$dblink->beginTransaction();
		}
		try {
			self::revokeKey($slot);
			$account = self::serviceAccount(true);

			$secret = 'secret_' . bin2hex(random_bytes(24));
			$key = new ApiKey(NULL);
			$key->set('apk_usr_user_id', $account->key);
			$key->set('apk_name', 'DNS server: ' . $slot);
			$key->set('apk_public_key', 'public_' . bin2hex(random_bytes(12)));
			$key->set('apk_secret_key', ApiKey::GenerateKey($secret));
			$key->set('apk_type', ApiKey::TYPE_MACHINE);
			$key->set('apk_permission', 1);
			$key->set('apk_scope', self::ACTION);
			$key->set('apk_ip_restriction', $ip);
			$key->set('apk_is_active', TRUE);
			$key->save();
			$key->load();
			Setting::put($def['key_setting'], $key->key);

			if ($own_transaction) {
				$dblink->commit();
			}
		} catch (\Throwable $e) {
			if ($own_transaction && $dblink->inTransaction()) {
				$dblink->rollBack();
			}
			throw $e;
		}

		return array('api_key' => $key, 'secret_key' => $secret);
	}

	/**
	 * End a slot's key, if it has one. The DNS server using it stops getting
	 * updates and keeps filtering from its cached copy.
	 */
	public static function revokeKey(string $slot): void {
		$def = self::slot($slot);
		$key = self::slotKey($slot);
		if ($key !== null) {
			$key->soft_delete();
		}
		Setting::put($def['key_setting'], '');
	}

	/**
	 * What the panel shows, one entry per server the settings configure. The
	 * primary always appears; the secondary when it has an address or a key.
	 *
	 * @return array[] each: slot, label, ip (the address the setting holds now),
	 *   key (ApiKey|null, live only), restricted_to (the address the key works
	 *   from), drift (true when the two addresses differ, so the key needs
	 *   re-issuing).
	 */
	public static function panelState(): array {
		$settings = Globalvars::get_instance();
		$rows = array();
		foreach (self::SLOTS as $slot => $def) {
			$ip = trim((string)$settings->get_setting($def['ip_setting']));
			$key = self::slotKey($slot);
			if ($slot !== 'primary' && $ip === '' && $key === null) {
				continue;
			}
			$restricted_to = $key ? trim((string)$key->get('apk_ip_restriction')) : '';
			$rows[] = array(
				'slot'          => $slot,
				'label'         => $def['label'],
				'ip'            => $ip,
				'key'           => $key,
				'restricted_to' => $restricted_to,
				'drift'         => $key !== null && $restricted_to !== $ip,
			);
		}
		return $rows;
	}

	/** The slot's live key, or null when it has none (never issued, revoked or deleted). */
	public static function slotKey(string $slot): ?ApiKey {
		$def = self::slot($slot);
		$id = (int)Globalvars::get_instance()->get_setting($def['key_setting']);
		if ($id <= 0) {
			return null;
		}
		$key = new ApiKey($id, TRUE);
		if (!$key->key || $key->get('apk_delete_time')) {
			return null;
		}
		return $key;
	}

	private static function slot(string $slot): array {
		if (!isset(self::SLOTS[$slot])) {
			throw new SystemDisplayableError('Unknown DNS server: ' . $slot);
		}
		return self::SLOTS[$slot];
	}
}
