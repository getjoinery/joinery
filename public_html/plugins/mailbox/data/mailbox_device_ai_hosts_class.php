<?php
/**
 * MailboxDeviceAiHost — one row per user: where their own AI model answers,
 * as an origin (scheme://host[:port]), for AI on end-to-end encrypted mail
 * (specs/fortress_mail_device_ai.md § R2).
 *
 * The person's browser opens their Fortress mail and sends it to a model they
 * name. This row is the only part of that setup the server keeps: the model's
 * origin, in the clear, so the mailbox page can name exactly that host in its
 * CSP connect-src and nowhere else. The key, the model name and the rest of
 * the address stay in the person's browser. The origin is not a secret —
 * holding it gives the operator nothing — but it decides where mail may go, so
 * it is set or changed only under a fresh second-factor confirmation (the
 * mailbox/device_ai_host action), and a page script can never point the mail
 * somewhere the person did not confirm.
 *
 * normalizeOrigin() is the one rule for what may be stored: an origin, never
 * a path; https, or http only to this computer or a private or tailnet
 * address.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailboxDeviceAiHostException extends SystemBaseException {}

class MailboxDeviceAiHost extends SystemBase {
	public static $prefix = 'mdh';
	public static $tablename = 'mdh_mailbox_device_ai_hosts';
	public static $pkey_column = 'mdh_mailbox_device_ai_host_id';

	public static $api_readable = false;
	public static $api_writable = false;

	protected static $foreign_key_actions = array(
		'mdh_usr_user_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'mdh_mailbox_device_ai_host_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'mdh_usr_user_id'  => array('type'=>'int8', 'is_nullable'=>false, 'unique'=>true),
		'mdh_host'         => array('type'=>'varchar(255)', 'is_nullable'=>false),
		'mdh_create_time'  => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mdh_update_time'  => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	function authenticate_write($data) {
		// Written only by the mailbox/device_ai_host action, on the caller's
		// own row, after its second-factor gate.
	}

	/**
	 * $input as the origin to store, or a MailboxDeviceAiHostException naming
	 * what is wrong with it. Accepts scheme://host[:port] with nothing after it
	 * (a trailing slash is tolerated and dropped); refuses a path, a query, a
	 * fragment or credentials in the address, and plain http unless the host is
	 * this computer (localhost, 127.0.0.0/8, ::1) or a private or tailnet IP
	 * address (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7) — a public
	 * host carries the mail over the internet, so it must be https.
	 */
	public static function normalizeOrigin(string $input): string {
		$input = trim($input);
		if ($input === '') {
			throw new MailboxDeviceAiHostException('Enter the address your model answers at.');
		}
		$parts = parse_url($input);
		if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
			throw new MailboxDeviceAiHostException('That is not a web address. It starts with https://');
		}
		$scheme = strtolower($parts['scheme']);
		if ($scheme !== 'https' && $scheme !== 'http') {
			throw new MailboxDeviceAiHostException('The address must start with https://');
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new MailboxDeviceAiHostException('Leave any name or password out of the address; your key goes in the AI panel.');
		}
		$path = $parts['path'] ?? '';
		if (($path !== '' && $path !== '/') || isset($parts['query']) || isset($parts['fragment'])) {
			throw new MailboxDeviceAiHostException('Enter only the start of the address, up to the host name '
				. '(for example https://api.fireworks.ai). The rest goes in the AI panel.');
		}
		$host = strtolower($parts['host']);
		if ($scheme === 'http' && !self::hostIsPrivate($host)) {
			throw new MailboxDeviceAiHostException('Use https:// for a model on the internet. '
				. 'Plain http:// is allowed only for this computer or your own network.');
		}
		$origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . intval($parts['port']) : '');
		if (PublicPageBase::normalize_connect_origin($origin) === null) {
			throw new MailboxDeviceAiHostException('That address cannot be used.');
		}
		return $origin;
	}

	/** True for this computer, or an IP address on a private or tailnet range. */
	public static function hostIsPrivate(string $host): bool {
		$host = strtolower(trim($host, '[]'));
		if ($host === 'localhost') {
			return true;
		}
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$ip = ip2long($host);
			foreach (array('127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10') as $cidr) {
				list($net, $bits) = explode('/', $cidr);
				$mask = -1 << (32 - (int)$bits);
				if (($ip & $mask) === (ip2long($net) & $mask)) {
					return true;
				}
			}
			return false;
		}
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			if ($host === '::1') {
				return true;
			}
			$first = hexdec(substr(bin2hex(inet_pton($host)), 0, 2));
			return ($first & 0xfe) === 0xfc; // fc00::/7, unique local
		}
		return false;
	}

	/** The origin $user_id registered, or null. */
	public static function originForUser(int $user_id): ?string {
		$row = self::loadForUser($user_id);
		return $row ? (string)$row->get('mdh_host') : null;
	}

	public static function loadForUser(int $user_id): ?MailboxDeviceAiHost {
		if ($user_id <= 0) {
			return null;
		}
		$multi = new MultiMailboxDeviceAiHost(array('user_id' => $user_id));
		return $multi->count() > 0 ? $multi->get(0) : null;
	}

	/**
	 * Store $origin as $user_id's model host (normalizeOrigin() first), or
	 * remove the row when $origin is ''. Returns the stored origin, or null
	 * after a removal. The CALLER holds the second-factor gate.
	 */
	public static function setForUser(int $user_id, string $origin): ?string {
		$row = self::loadForUser($user_id);
		if (trim($origin) === '') {
			if ($row) {
				$row->permanent_delete();
			}
			return null;
		}
		$origin = self::normalizeOrigin($origin);
		if (!$row) {
			$row = new MailboxDeviceAiHost(NULL);
			$row->set('mdh_usr_user_id', $user_id);
		}
		$row->set('mdh_host', $origin);
		$row->set('mdh_update_time', gmdate('Y-m-d H:i:s'));
		$row->save();
		return $origin;
	}
}

class MultiMailboxDeviceAiHost extends SystemMultiBase {
	protected static $model_class = 'MailboxDeviceAiHost';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['user_id'])) {
			$filters['mdh_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('mdh_mailbox_device_ai_hosts', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
