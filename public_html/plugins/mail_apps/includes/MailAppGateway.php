<?php
/**
 * MailAppGateway - the admin side of the mail gateway: the Allow mail apps
 * switch, and the health the admin page shows.
 *
 * The gateway is a separate program (provisioning/mail-gateway, Go) that runs
 * on the host beside the web server: it holds mail programs' long connections
 * on 993 (IMAP) and 465 (SMTP submission), TLS only, and turns each command
 * into a call to this site's mail_apps/mail_* actions. It has no database and
 * no vault access (specs/mail_apps_plugin.md § Architecture).
 *
 * Turning it on is a guarded platform action, never host surgery: a narrow
 * root helper, /usr/local/sbin/joinery-mail-gateway on|off|status SITE,
 * installed with the plugin's host installer (provisioning/
 * install_mail_gateway.sh), run through sudo by the web user — the mailbox
 * listener switch's pattern. A site in a container has no host to change; its
 * host's management node turns it on.
 *
 * @version 1.1.0 - programs connect to the mail host name (servedName()); cannotTurnOn()
 * @version 1.0.0
 */

class MailAppGateway {

	const HELPER = '/usr/local/sbin/joinery-mail-gateway';
	const IMAP_PORT = 993;
	const SUBMISSION_PORT = 465;

	/** The site's name as the host installer knows it (the directory above public_html). */
	public static function siteName(): string {
		return basename(dirname(PathHelper::getIncludePath('')));
	}

	/**
	 * The name programs connect to. While mail apps are on, the name the
	 * gateway was turned on with (recorded by the switch) — the one it really
	 * serves, even if the mail host name setting has changed since. Otherwise
	 * the mail host name, else the web name.
	 */
	public static function servedName(): string {
		$recorded = self::recordedName();
		if ($recorded !== null && Globalvars::get_instance()->get_setting('mail_apps_enabled')) {
			return $recorded;
		}
		return self::mailHostname() ?? self::hostname();
	}

	/** The name the gateway was last turned on with, or null. */
	public static function recordedName(): ?string {
		$name = strtolower(trim((string)Globalvars::get_instance()->get_setting('mail_apps_served_name')));
		return $name !== '' ? $name : null;
	}

	/**
	 * When mail apps are on and the mail host name has changed since the
	 * gateway was turned on, the two names (served, set); null otherwise.
	 *
	 * @return array{served:string, setting:?string}|null
	 */
	public static function nameDrift(): ?array {
		if (!Globalvars::get_instance()->get_setting('mail_apps_enabled')) {
			return null;
		}
		$served = self::recordedName();
		$setting = self::mailHostname();
		if ($served === null || $served === $setting) {
			return null;
		}
		return array('served' => $served, 'setting' => $setting);
	}

	/** The site's public web host name (how the gateway reaches the site over loopback). */
	public static function hostname(): string {
		return (string)parse_url(LibraryFunctions::get_absolute_url('/'), PHP_URL_HOST);
	}

	/**
	 * The name mail programs connect to: the deployment's mail host name
	 * (`mailbox_mail_hostname`, its MX target), which points at this server.
	 * Not the web name, which often sits behind a CDN that carries web ports
	 * only (specs/mail_apps_plugin.md § Where programs connect). Null when no
	 * mail host name is set.
	 */
	public static function mailHostname(): ?string {
		$host = strtolower(trim((string)Globalvars::get_instance()->get_setting('mailbox_mail_hostname')));
		return $host !== '' ? $host : null;
	}

	/**
	 * Why mail apps cannot be turned on here, in words, or null when they can.
	 * A relay-fronted deployment waits for the relay's pass-through, so this
	 * server's address stays hidden.
	 */
	public static function cannotTurnOn(): ?string {
		if (self::inContainer()) {
			return 'This site runs in a container. Its host runs the mail gateway; turn mail apps on for this site '
				. 'from the host\'s management node.';
		}
		if (class_exists('MailboxRelay') && MailboxRelay::active() !== null) {
			return 'Mail arrives here through a relay, which keeps this server\'s address hidden. Mail apps will reach '
				. 'this server through the relay once the relay can pass them through; until then they are not offered.';
		}
		if (self::mailHostname() === null) {
			return 'This site has no mail host name yet. Set it on the Mailbox setup page; mail programs connect to it.';
		}
		return null;
	}

	/** A site container has no host of its own to change. */
	public static function inContainer(): bool {
		return Globalvars::get_instance()->get_setting('deployment_environment', true, true) === 'docker';
	}

	public static function helperInstalled(): bool {
		return is_file(self::HELPER);
	}

	/** Whether something accepts connections on $port on this machine. */
	public static function listening(int $port): bool {
		$sock = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2);
		if ($sock) {
			@fclose($sock);
			return true;
		}
		return false;
	}

	/**
	 * The certificate the gateway presents for this site's name on $port:
	 * whether it covers the name, and when it expires.
	 *
	 * @return array{ok:bool, covers:bool, expires:?int, error:?string}
	 */
	public static function certificate(int $port): array {
		$host = self::servedName();
		// Verified as a mail program verifies it: the chain to a public root
		// and the name. A self-signed or broken chain fails here, as it would
		// in Thunderbird; the unverified look below only says why.
		$verified = @stream_socket_client('ssl://127.0.0.1:' . $port, $verrno, $verrstr, 5, STREAM_CLIENT_CONNECT,
			stream_context_create(array('ssl' => array(
				'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host))));
		if ($verified) {
			@fclose($verified);
		}
		$ctx = stream_context_create(array('ssl' => array(
			'capture_peer_cert' => true,
			'verify_peer'       => false,
			'verify_peer_name'  => false,
			'SNI_enabled'       => true,
			'peer_name'         => $host,
		)));
		$sock = @stream_socket_client('ssl://127.0.0.1:' . $port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
		if (!$sock) {
			return array('ok' => false, 'covers' => false, 'expires' => null, 'error' => $errstr ?: 'no TLS answer');
		}
		$params = stream_context_get_params($sock);
		@fclose($sock);
		$cert = $params['options']['ssl']['peer_certificate'] ?? null;
		$info = $cert ? openssl_x509_parse($cert) : null;
		if (!$info) {
			return array('ok' => false, 'covers' => false, 'expires' => null, 'error' => 'no certificate');
		}
		$names = array(strtolower((string)($info['subject']['CN'] ?? '')));
		foreach (explode(',', (string)($info['extensions']['subjectAltName'] ?? '')) as $alt) {
			$alt = trim($alt);
			if (stripos($alt, 'DNS:') === 0) {
				$names[] = strtolower(substr($alt, 4));
			}
		}
		$covers = false;
		foreach ($names as $name) {
			if ($name === strtolower($host)
				|| (strpos($name, '*.') === 0 && substr(strtolower($host), strpos(strtolower($host), '.')) === substr($name, 1))) {
				$covers = true;
			}
		}
		$expires = isset($info['validTo_time_t']) ? intval($info['validTo_time_t']) : null;
		return array('ok' => (bool)$verified && $covers && $expires !== null && $expires > time(), 'covers' => $covers,
			'expires' => $expires,
			'error' => $verified ? null : 'Mail programs will refuse this certificate (it does not verify to a public root).');
	}

	/**
	 * Run one helper verb as root via the sudoers rule and demand its success
	 * marker. Never throws.
	 *
	 * @param string $verb on | off | status
	 * @return array{ok:bool, message:string}
	 */
	public static function run(string $verb): array {
		if (!in_array($verb, array('on', 'off', 'status'), true)) {
			return array('ok' => false, 'message' => 'Unknown verb.');
		}
		if (!self::helperInstalled()) {
			return array('ok' => false, 'message' => 'The mail gateway is not installed on this server yet. The host '
				. 'installer puts it in place within a few minutes of the plugin being switched on; or run once as '
				. 'root: sudo bash plugins/mail_apps/provisioning/install_mail_gateway.sh');
		}
		$marker = array('on' => 'GATEWAY_ON', 'off' => 'GATEWAY_OFF', 'status' => 'GATEWAY_STATUS')[$verb];
		$output = array();
		$code = 1;
		$args = ' ' . escapeshellarg($verb) . ' ' . escapeshellarg(self::siteName());
		if ($verb === 'on' && self::mailHostname() !== null) {
			$args .= ' ' . escapeshellarg(self::mailHostname());
		}
		exec('sudo -n ' . escapeshellarg(self::HELPER) . $args . ' 2>&1', $output, $code);
		$text = trim(implode("\n", $output));
		if ($code !== 0 || strpos($text, $marker) === false) {
			return array('ok' => false, 'message' => 'The mail gateway helper did not finish (exit ' . intval($code) . '): '
				. ($text !== '' ? $text : 'no output'));
		}
		return array('ok' => true, 'message' => $text);
	}

	/**
	 * The round trip: sign in over IMAP with a throwaway app password, SELECT
	 * INBOX, sign out, revoke the password. Proves the whole path — TLS, the
	 * gateway, the API, app access, the folder — with the admin's own mailbox.
	 *
	 * @return array{ok:bool, steps:string[], error:?string}
	 */
	public static function roundTrip(int $user_id): array {
		$steps = array();
		$aliases = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
		$address = null;
		foreach ($aliases as $alias_id) {
			$alias = new InboundEmailAlias(intval($alias_id), TRUE);
			if ($alias->key && !$alias->get('iea_delete_time') && !$alias->is_fortress()) {
				$address = strtolower($alias->get_full_address());
				break;
			}
		}
		if ($address === null) {
			return array('ok' => false, 'steps' => $steps,
				'error' => 'You hold no Standard or Private mailbox to sign in to; the round trip needs one.');
		}
		try {
			$minted = ApiKey::CreateAppPassword($user_id, 'mail', 'Health check');
		} catch (\Throwable $e) {
			return array('ok' => false, 'steps' => $steps, 'error' => 'Could not make a throwaway app password: ' . $e->getMessage());
		}
		$steps[] = 'Made a throwaway app password';
		$error = null;
		try {
			$ctx = stream_context_create(array('ssl' => array(
				// Verified, as a program would: a round trip that passes on a
				// certificate Thunderbird refuses proves nothing.
				'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => self::servedName(),
			)));
			$sock = @stream_socket_client('ssl://127.0.0.1:' . self::IMAP_PORT, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
			if (!$sock) {
				throw new RuntimeException('No TLS connection to port ' . self::IMAP_PORT . ': ' . $errstr);
			}
			stream_set_timeout($sock, 20);
			$greeting = (string)fgets($sock);
			if (strpos($greeting, '* OK') !== 0) {
				throw new RuntimeException('Unexpected greeting: ' . trim($greeting));
			}
			$steps[] = 'Connected over TLS';
			$quote = function (string $s): string { return '"' . addcslashes($s, "\"\\") . '"'; };
			$command = function (string $tag, string $line) use ($sock): string {
				fwrite($sock, $tag . ' ' . $line . "\r\n");
				while (($reply = fgets($sock)) !== false) {
					if (strpos($reply, $tag . ' ') === 0) {
						return trim($reply);
					}
				}
				throw new RuntimeException('The connection closed during ' . strtok($line, ' '));
			};
			$reply = $command('a1', 'LOGIN ' . $quote($address) . ' ' . $quote($minted['password']));
			if (strpos($reply, 'a1 OK') !== 0) {
				throw new RuntimeException('Sign-in refused: ' . $reply);
			}
			$steps[] = 'Signed in as ' . $address;
			$reply = $command('a2', 'SELECT INBOX');
			if (strpos($reply, 'a2 OK') !== 0) {
				throw new RuntimeException('Opening INBOX refused: ' . $reply);
			}
			$steps[] = 'Opened INBOX';
			$command('a3', 'LOGOUT');
			$steps[] = 'Signed out';
			fclose($sock);
		} catch (\Throwable $e) {
			$error = $e->getMessage();
		} finally {
			$minted['api_key']->soft_delete();
			$steps[] = 'Revoked the throwaway app password';
		}
		return array('ok' => $error === null, 'steps' => $steps, 'error' => $error);
	}
}
?>
