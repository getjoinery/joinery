<?php
/**
 * DnsResolverAccess - the keys the DNS servers read this site with.
 *
 * Each DNS server fetches its data from the dns_filtering/resolver_snapshot
 * action with a machine key that can call that action and nothing else:
 * read-only, scoped to the action, and restricted to the server's own
 * addresses: its IPv4 and, when it has one, its IPv6. The site sees the
 * server from whichever family its connection used; both are the machine. This class mints and revokes those keys for the "DNS server
 * access" panel on the plugin's settings page.
 *
 * A key belongs to the admin who issued it; every key carries a user, and the
 * action runs as that user. Nothing about the owner widens the key: its scope
 * confines it to the snapshot action, which needs no permission level, so
 * demoting the owner or changing their password leaves it working. Deleting
 * the owner would stop it, and User refuses that while they own a live
 * scoped key: the key is revoked, or re-issued by another admin, first.
 *
 * @version 1.2 - a server's IPv6 address is recorded beside its IPv4 and the key works from
 *                either; each is checked for its own family; the drift warning compares the
 *                key's addresses with the settings' as addresses
 * @version 1.1 - a key belongs to the admin who issues it; the service account is gone. It
 *                existed to survive its owner's deletion, which User already refuses while
 *                the owner holds a live scoped key, and creating it failed on every
 *                production site (the email field's accepts-mail check refused .invalid)
 * @version 1.0
 */
class DnsResolverAccess {

	const ACTION = 'dns_filtering/resolver_snapshot';

	/**
	 * One slot per DNS server: the settings holding its public addresses, one
	 * per family (which the key's restriction copies), and the setting
	 * recording its key's id.
	 */
	const SLOTS = array(
		'primary' => array(
			'label'        => 'Primary DNS server',
			'ip_setting'   => 'dns_filtering_dns_server_ip',
			'ipv6_setting' => 'dns_filtering_dns_server_ipv6',
			'key_setting'  => 'dns_filtering_resolver_key_primary',
		),
		'secondary' => array(
			'label'        => 'Secondary DNS server',
			'ip_setting'   => 'dns_filtering_dns_secondary_server_ip',
			'ipv6_setting' => 'dns_filtering_dns_secondary_server_ipv6',
			'key_setting'  => 'dns_filtering_resolver_key_secondary',
		),
	);

	/**
	 * Mint a key for one DNS server, owned by $owner (the admin issuing it),
	 * revoking any key that slot already held. Returns ['api_key' => ApiKey,
	 * 'secret_key' => plaintext]; the plaintext exists only in this return
	 * value and is never stored or logged.
	 *
	 * @throws SystemDisplayableError for an unknown slot, an owner who is not a
	 *   live saved user, a server with neither address set yet, or an address
	 *   that is not of its setting's family.
	 */
	public static function issueKey(string $slot, User $owner): array {
		$def = self::slot($slot);
		if (!$owner->key || $owner->get('usr_delete_time')) {
			throw new SystemDisplayableError('A DNS server key needs a live account to belong to.');
		}
		$addresses = self::slotAddresses($slot);
		if (!$addresses) {
			throw new SystemDisplayableError(
				'Set the ' . strtolower($def['label']) . '\'s public address first (IPv4, IPv6 or both): the key only works from those addresses.');
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$own_transaction = !$dblink->inTransaction();
		if ($own_transaction) {
			$dblink->beginTransaction();
		}
		try {
			self::revokeKey($slot);

			$secret = 'secret_' . bin2hex(random_bytes(24));
			$key = new ApiKey(NULL);
			$key->set('apk_usr_user_id', $owner->key);
			$key->set('apk_name', 'DNS server: ' . $slot);
			$key->set('apk_public_key', 'public_' . bin2hex(random_bytes(12)));
			$key->set('apk_secret_key', ApiKey::GenerateKey($secret));
			$key->set('apk_type', ApiKey::TYPE_MACHINE);
			$key->set('apk_permission', 1);
			$key->set('apk_scope', self::ACTION);
			$key->set('apk_ip_restriction', implode(',', $addresses));
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
	 * @return array[] each: slot, label, ip (the addresses the settings hold
	 *   now, ", "-joined), key (ApiKey|null, live only), restricted_to (the
	 *   addresses the key works from), drift (true when the two sets of
	 *   addresses differ, compared as addresses, so the key needs re-issuing).
	 */
	public static function panelState(): array {
		$rows = array();
		foreach (self::SLOTS as $slot => $def) {
			try {
				$addresses = self::slotAddresses($slot);
				$problem = null;
			} catch (SystemDisplayableError $e) {
				$addresses = array();
				$problem = $e->getMessage();
			}
			$key = self::slotKey($slot);
			if ($slot !== 'primary' && !$addresses && $problem === null && $key === null) {
				continue;
			}
			$restricted = $key ? array_values(array_filter(array_map('trim', explode(',', (string)$key->get('apk_ip_restriction'))), 'strlen')) : array();
			$rows[] = array(
				'slot'          => $slot,
				'label'         => $def['label'],
				'ip'            => implode(', ', $addresses),
				'problem'       => $problem,
				'key'           => $key,
				'restricted_to' => implode(', ', $restricted),
				'drift'         => $key !== null && !self::sameAddresses($addresses, $restricted),
			);
		}
		return $rows;
	}

	/**
	 * The slot's public addresses, IPv4 first, leaving out a setting that is
	 * blank. Each must be of its own setting's family, so an IPv6 address
	 * pasted into the IPv4 box cannot become a key that works from neither.
	 *
	 * @return string[]
	 * @throws SystemDisplayableError naming a setting that holds the wrong thing
	 */
	public static function slotAddresses(string $slot): array {
		$def = self::slot($slot);
		$settings = Globalvars::get_instance();
		$addresses = array();
		foreach (array('ip_setting' => FILTER_FLAG_IPV4, 'ipv6_setting' => FILTER_FLAG_IPV6) as $setting => $family) {
			$value = trim((string)$settings->get_setting($def[$setting]));
			if ($value === '') {
				continue;
			}
			// An IPv4 address written IPv6-style (::ffff:a.b.c.d) is IPv4, so it
			// does not belong in the IPv6 setting.
			if (!filter_var($value, FILTER_VALIDATE_IP, $family)
					|| IpAddress::family($value) !== ($family === FILTER_FLAG_IPV4 ? 4 : 6)) {
				throw new SystemDisplayableError('The ' . strtolower($def['label']) . '\'s '
					. ($family === FILTER_FLAG_IPV4 ? 'IPv4' : 'IPv6') . ' address setting holds "' . $value
					. '", which is not an ' . ($family === FILTER_FLAG_IPV4 ? 'IPv4' : 'IPv6') . ' address.');
			}
			$addresses[] = $value;
		}
		return $addresses;
	}

	/** Do two lists name the same set of addresses, in any order and spelling? */
	private static function sameAddresses(array $a, array $b): bool {
		$canon = function (array $list): array {
			$out = array();
			foreach ($list as $ip) {
				$bin = IpAddress::binary((string)$ip);
				$out[] = $bin === null ? 'x:' . $ip : bin2hex($bin);
			}
			$out = array_values(array_unique($out));
			sort($out);
			return $out;
		};
		return $canon($a) === $canon($b);
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
