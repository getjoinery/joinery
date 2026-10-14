<?php
/**
 * WebPushDriver — browser push (the `web` destination kind).
 *
 * Sends straight to the browser maker's push service (Google's for Chrome,
 * Mozilla's, Apple's, Microsoft's) with this site's own key pair (VAPID), no
 * Joinery relay. The message is encrypted to the browser (RFC 8291), so the
 * push service carries it without being able to read it; that is why this
 * driver may carry readable text.
 *
 * The key pair is one sealed setting, `push_vapid_keys` (JSON: public and
 * private, base64url), minted cold by update_database and re-minted by the
 * sealed-secret reconciler when it cannot be opened here (a database copied
 * from another site). Every browser subscription is bound to the public key it
 * was made with, so a re-mint retires every browser destination; each member
 * turns push on again from their notification preferences.
 *
 * A destination's endpoint is a URL the server POSTs to, so only the browser
 * makers' push services are accepted (PUSH_SERVICE_HOSTS), over https on the
 * default port. Anything else is refused at registration.
 *
 * See docs/push_notifications.md.
 *
 * @version 1.0.0
 */
require_once(PathHelper::getComposerAutoloadPath());

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;

class WebPushDriver implements PushDriver {

	const KEYS_SETTING = 'push_vapid_keys';

	/** Seconds a push service holds a message for a browser that is offline. */
	const TTL = 86400;

	/** Seconds one send may take. */
	const TIMEOUT = 10;

	/**
	 * The push services browsers use. An endpoint's host is one of these or a
	 * subdomain of one.
	 */
	const PUSH_SERVICE_HOSTS = array(
		'fcm.googleapis.com',          // Chrome, Brave, Opera, Samsung Internet
		'android.googleapis.com',      // older Chrome subscriptions
		'push.services.mozilla.com',   // Firefox
		'push.apple.com',              // Safari
		'notify.windows.com',          // Edge
	);

	/** @var array|null|false per-request keys (false = read, none usable) */
	private static $keys = null;

	/** @var callable|null a test process's stand-in for the network */
	private static $test_handler = null;

	public function kind(): string {
		return PushDestination::KIND_WEB;
	}

	public function carriesText(): bool {
		return true;
	}

	public function available(): bool {
		return self::keys() !== null;
	}

	/**
	 * A browser's PushSubscription: `endpoint`, `p256dh` and `auth` (as the
	 * browser's subscription.toJSON() gives them), and optionally `user_agent`.
	 */
	public function register(array $input): array {
		$endpoint = trim((string)($input['endpoint'] ?? ''));
		if (!self::is_push_service_url($endpoint)) {
			throw new InvalidArgumentException('That is not a browser push service address.');
		}
		$p256dh = (string)($input['p256dh'] ?? '');
		$auth   = (string)($input['auth'] ?? '');
		$p256dh_raw = self::base64url_decode($p256dh);
		$auth_raw   = self::base64url_decode($auth);
		if ($p256dh_raw === null || strlen($p256dh_raw) !== 65 || $p256dh_raw[0] !== "\x04") {
			throw new InvalidArgumentException('The browser key (p256dh) is not a P-256 public key.');
		}
		if ($auth_raw === null || strlen($auth_raw) !== 16) {
			throw new InvalidArgumentException('The browser secret (auth) is not 16 bytes.');
		}
		$user_agent = mb_substr(trim((string)($input['user_agent'] ?? '')), 0, 255);
		return array(
			'endpoint' => $endpoint,
			'details'  => array('p256dh' => $p256dh, 'auth' => $auth, 'user_agent' => $user_agent),
		);
	}

	public function send(array $deliveries): array {
		$out = array();
		$keys = self::keys();
		if ($keys === null) {
			foreach ($deliveries as $d) {
				$out[(int)$d['destination']->key] = array('result' => self::RESULT_FAILED, 'reason' => 'no sending key on this site');
			}
			return $out;
		}

		$client_options = array();
		if (self::$test_handler !== null) {
			$client_options['handler'] = \GuzzleHttp\HandlerStack::create(self::$test_handler);
		}
		$webpush = new WebPush(
			array('VAPID' => array('subject' => self::subject(), 'publicKey' => $keys['public'], 'privateKey' => $keys['private'])),
			array('TTL' => self::TTL, 'urgency' => 'normal'),
			self::TIMEOUT,
			$client_options
		);

		$by_endpoint = array();
		foreach ($deliveries as $d) {
			$destination = $d['destination'];
			$id = (int)$destination->key;
			$details = $destination->details();
			try {
				$webpush->queueNotification(
					Subscription::create(array(
						'endpoint'        => $destination->get('psh_endpoint'),
						'publicKey'       => (string)($details['p256dh'] ?? ''),
						'authToken'       => (string)($details['auth'] ?? ''),
						'contentEncoding' => 'aes128gcm',
					)),
					json_encode($d['message'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
				);
				$by_endpoint[$destination->get('psh_endpoint')] = $id;
			} catch (Throwable $e) {
				$out[$id] = array('result' => self::RESULT_FAILED, 'reason' => $e->getMessage());
			}
		}

		foreach ($webpush->flush() as $report) {
			$id = $by_endpoint[$report->getEndpoint()] ?? null;
			if ($id === null) {
				continue;
			}
			$response = $report->getResponse();
			$status = $response ? $response->getStatusCode() : 0;
			if ($report->isSuccess()) {
				$out[$id] = array('result' => self::RESULT_SENT, 'reason' => (string)$status);
			} elseif ($report->isSubscriptionExpired()) {
				$out[$id] = array('result' => self::RESULT_GONE, 'reason' => 'the push service answered ' . $status);
			} else {
				$out[$id] = array('result' => self::RESULT_FAILED, 'reason' => $status ? 'the push service answered ' . $status : $report->getReason());
			}
		}
		return $out;
	}

	public function retired(PushDestination $destination, string $reason): void {
		// Nothing to tell a browser: its subscription simply stops receiving.
	}

	// ── the site's key pair ──────────────────────────────────────────────────

	/** The public key browsers subscribe with (base64url), or null when push cannot send here. */
	public static function public_key(): ?string {
		$keys = self::keys();
		return $keys === null ? null : $keys['public'];
	}

	/**
	 * Mint the key pair if the site has none, so no request ever has to. Called
	 * from update_database (cold) and as the reconciler's reprovision recipe,
	 * which clears a dead value first. A fresh pair retires every browser
	 * destination: each was bound to the pair it replaced.
	 *
	 * @return bool true once usable keys exist
	 */
	public static function provisionKeys(): bool {
		$dblink = DbConnector::get_instance()->get_db_link();
		if (self::read_blob() === null) {
			$generated = VAPID::createVapidKeys();
			$box = new SecretBox();
			$blob = $box->seal(self::KEYS_SETTING, json_encode(array(
				'public'  => $generated['publicKey'],
				'private' => $generated['privateKey'],
			)));
			// Fill the seeded empty row, or insert one; never overwrite a pair
			// another process minted first (same guard as File's signing key).
			$q = $dblink->prepare(
				"INSERT INTO stg_settings
					(stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
				 VALUES (?, ?, 1, NOW(), NOW(), 'notifications')
				 ON CONFLICT (stg_name) DO UPDATE
					SET stg_value = EXCLUDED.stg_value, stg_update_time = NOW()
					WHERE stg_settings.stg_value IS NULL OR stg_settings.stg_value = ''"
			);
			$q->execute(array(self::KEYS_SETTING, $blob));
			self::$keys = null;
			if (self::read_blob() === $blob) {
				PushSender::retire_kind(PushDestination::KIND_WEB, 'the site minted a new sending key');
			}
		}
		self::$keys = null;
		return self::keys() !== null;
	}

	/** @return array|null ['public' => ..., 'private' => ...] */
	private static function keys(): ?array {
		if (is_array(self::$keys)) {
			return self::$keys;
		}
		if (self::$keys === false) {
			return null;
		}
		self::$keys = false;
		$blob = self::read_blob();
		if ($blob === null) {
			return null;
		}
		$opened = (new SecretBox())->open($blob);
		if ($opened['value'] === null) {
			// Dead here (another site's key): push is off until the reconciler
			// re-mints on its next cold pass.
			return null;
		}
		$pair = json_decode((string)$opened['value'], true);
		if (!is_array($pair) || empty($pair['public']) || empty($pair['private'])) {
			return null;
		}
		self::$keys = array('public' => (string)$pair['public'], 'private' => (string)$pair['private']);
		return self::$keys;
	}

	private static function read_blob(): ?string {
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
		$q->execute(array(self::KEYS_SETTING));
		$blob = $q->fetchColumn();
		return ($blob === false || $blob === null || $blob === '') ? null : (string)$blob;
	}

	/** Who the push services contact about this sender: the site's address. */
	private static function subject(): string {
		$email = (string)Globalvars::get_instance()->get_setting('defaultemail');
		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return 'mailto:' . $email;
		}
		return LibraryFunctions::get_absolute_url('/');
	}

	// ── addresses ───────────────────────────────────────────────────────────

	/** Is this an https URL on a browser push service, on the default port? */
	public static function is_push_service_url(string $url): bool {
		if ($url === '' || strlen($url) > 1024) {
			return false;
		}
		$parts = parse_url($url);
		if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
				|| isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}
		$host = strtolower(rtrim($parts['host'], '.'));
		foreach (self::PUSH_SERVICE_HOSTS as $service) {
			if ($host === $service || substr($host, -strlen('.' . $service)) === '.' . $service) {
				return true;
			}
		}
		return false;
	}

	private static function base64url_decode(string $s): ?string {
		if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $s)) {
			return null;
		}
		$raw = base64_decode(strtr(rtrim($s, '='), '-_', '+/'), true);
		return $raw === false ? null : $raw;
	}

	// ── test seams ──────────────────────────────────────────────────────────

	/**
	 * Answer sends with $handler (a Guzzle handler, e.g. a MockHandler) instead
	 * of the network, for the rest of this process; null restores the network.
	 *
	 * @throws LogicException outside the CLI
	 */
	public static function useHttpHandlerForTest(?callable $handler): void {
		if (php_sapi_name() !== 'cli') {
			throw new LogicException('WebPushDriver::useHttpHandlerForTest is for test processes only.');
		}
		self::$test_handler = $handler;
	}

	/** Forget the keys read this request (a test changed the setting). */
	public static function resetForTests(): void {
		self::$keys = null;
	}
}
