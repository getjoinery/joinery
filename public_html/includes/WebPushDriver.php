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
 * Both halves are PHP's own OpenSSL and hash functions: the VAPID token is an
 * ES256 JWT (RFC 8292) and the body is one aes128gcm record (RFC 8188) keyed
 * from an ECDH agreement with the browser's key (RFC 8291). Guzzle carries the
 * requests.
 *
 * See docs/push_notifications.md.
 *
 * @version 2.0.0
 */
require_once(PathHelper::getComposerAutoloadPath());

class WebPushDriver implements PushDriver {

	const KEYS_SETTING = 'push_vapid_keys';

	/** Seconds a push service holds a message for a browser that is offline. */
	const TTL = 86400;

	/** Seconds one send may take. */
	const TIMEOUT = 10;

	/** Sends in flight at once. */
	const CONCURRENCY = 20;

	/** The record size written in the aes128gcm header (RFC 8188). */
	const RECORD_SIZE = 4096;

	/**
	 * Largest message a push service must accept (RFC 8291 section 4): a
	 * 4096-byte body less the 86-byte header, the 16-byte tag and the delimiter.
	 */
	const MAX_PAYLOAD = 3993;

	/**
	 * Every message is padded to this many bytes before encryption, so the push
	 * service cannot tell messages apart by length. It is the size every push
	 * service accepts.
	 */
	const PAD_TO = 2820;

	/** Seconds a VAPID token is good for (RFC 8292 allows at most a day). */
	const TOKEN_LIFETIME = 43200;

	/** DER prefix of a P-256 SubjectPublicKeyInfo; the 65-byte point follows. */
	const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

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
		if ($p256dh_raw === null || self::public_key_from_point($p256dh_raw) === null) {
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

		$private_key = $keys['signer'];

		// No redirects: the endpoint allowlist is what keeps the server posting
		// only to push services, and a 3xx would walk past it (it lands in failed).
		$client_options = array('timeout' => self::TIMEOUT, 'http_errors' => false, 'allow_redirects' => false);
		if (self::$test_handler !== null) {
			$client_options['handler'] = \GuzzleHttp\HandlerStack::create(self::$test_handler);
		}
		$client = new \GuzzleHttp\Client($client_options);

		// One token per push service per send: the audience is the service's origin.
		$tokens = array();
		$requests = array();
		foreach ($deliveries as $d) {
			$destination = $d['destination'];
			$id = (int)$destination->key;
			$endpoint = (string)$destination->get('psh_endpoint');
			$details = $destination->details();
			try {
				$ua_public = self::base64url_decode((string)($details['p256dh'] ?? ''));
				$auth = self::base64url_decode((string)($details['auth'] ?? ''));
				if ($ua_public === null || $auth === null) {
					throw new InvalidArgumentException('the browser\'s keys are unreadable');
				}
				$payload = json_encode($d['message'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				if ($payload === false || strlen($payload) > self::MAX_PAYLOAD) {
					throw new InvalidArgumentException('the message is larger than one push can carry');
				}
				$audience = strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) . '://' . strtolower((string)parse_url($endpoint, PHP_URL_HOST));
				if (!isset($tokens[$audience])) {
					$tokens[$audience] = self::vapid_token($audience, self::subject(), $private_key);
				}
				$body = self::encrypt($payload, $ua_public, $auth, self::PAD_TO);
				$requests[$id] = new \GuzzleHttp\Psr7\Request('POST', $endpoint, array(
					'Content-Type'     => 'application/octet-stream',
					'Content-Encoding' => 'aes128gcm',
					'Content-Length'   => (string)strlen($body),
					'TTL'              => (string)self::TTL,
					'Urgency'          => 'normal',
					'Authorization'    => 'vapid t=' . $tokens[$audience] . ', k=' . $keys['public'],
				), $body);
			} catch (Throwable $e) {
				$out[$id] = array('result' => self::RESULT_FAILED, 'reason' => $e->getMessage());
			}
		}

		$ids = array_keys($requests);
		$pool = new \GuzzleHttp\Pool($client, array_values($requests), array(
			'concurrency' => self::CONCURRENCY,
			'fulfilled' => function ($response, $index) use (&$out, $ids) {
				$status = (int)$response->getStatusCode();
				if ($status >= 200 && $status < 300) {
					$out[$ids[$index]] = array('result' => self::RESULT_SENT, 'reason' => (string)$status);
				} elseif ($status === 404 || $status === 410) {
					// The browser unsubscribed or the subscription expired.
					$out[$ids[$index]] = array('result' => self::RESULT_GONE, 'reason' => 'the push service answered ' . $status);
				} else {
					$out[$ids[$index]] = array('result' => self::RESULT_FAILED, 'reason' => 'the push service answered ' . $status);
				}
			},
			'rejected' => function ($reason, $index) use (&$out, $ids) {
				$out[$ids[$index]] = array('result' => self::RESULT_FAILED,
					'reason' => $reason instanceof Throwable ? $reason->getMessage() : 'the request failed');
			},
		));
		$pool->promise()->wait();
		return $out;
	}

	// ── the two RFCs ────────────────────────────────────────────────────────

	/**
	 * The VAPID token (RFC 8292): an ES256 JWT naming the push service
	 * ($audience, its origin), who to contact ($subject) and an expiry.
	 */
	public static function vapid_token(string $audience, string $subject, $private_key): string {
		$header = self::base64url_encode(json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
		$claims = self::base64url_encode(json_encode(array(
			'aud' => $audience,
			'exp' => time() + self::TOKEN_LIFETIME,
			'sub' => $subject,
		), JSON_UNESCAPED_SLASHES));
		$signing_input = $header . '.' . $claims;
		$der = '';
		if (!openssl_sign($signing_input, $der, $private_key, OPENSSL_ALGO_SHA256)) {
			throw new RuntimeException('the sending key could not sign');
		}
		return $signing_input . '.' . self::base64url_encode(self::ecdsa_der_to_raw($der));
	}

	/**
	 * Encrypt $payload to one browser (RFC 8291), as one aes128gcm record (RFC
	 * 8188) padded to $pad_to bytes. $ua_public is the browser's 65-byte P-256
	 * point and $auth its 16-byte secret.
	 *
	 * $as_private_raw and $salt are drawn fresh when null; they are arguments
	 * only so a test can reproduce the RFC 8291 worked example byte for byte,
	 * and are refused outside the CLI (a fixed key and salt is nonce reuse).
	 */
	public static function encrypt(string $payload, string $ua_public, string $auth, int $pad_to, ?string $as_private_raw = null, ?string $salt = null): string {
		if (($as_private_raw !== null || $salt !== null) && php_sapi_name() !== 'cli') {
			throw new LogicException('A fixed sender key or salt is for test processes only.');
		}
		if ($salt !== null && strlen($salt) !== 16) {
			throw new InvalidArgumentException('the salt must be 16 bytes');
		}
		if (strlen($ua_public) !== 65 || $ua_public[0] !== "\x04" || strlen($auth) !== 16) {
			throw new InvalidArgumentException('the browser\'s keys are not a P-256 key and a 16-byte secret');
		}
		if (strlen($payload) > self::MAX_PAYLOAD) {
			throw new InvalidArgumentException('the message is larger than one push can carry');
		}

		// This message's own key pair: its public half travels in the header.
		if ($as_private_raw === null) {
			$as_key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
			if ($as_key === false) {
				throw new RuntimeException('no key pair could be made for this message');
			}
			$as_public = self::public_point($as_key);
		} else {
			$as_public = self::public_point_from_private_raw($as_private_raw);
			$as_key = self::private_key_from_raw($as_private_raw, $as_public);
		}
		$ua_key = self::public_key_from_point($ua_public);
		if ($as_key === null || $as_public === null || $ua_key === null) {
			throw new InvalidArgumentException('the browser\'s key is not a point on P-256');
		}
		$shared = openssl_pkey_derive($ua_key, $as_key, 32);
		if ($shared === false || strlen($shared) !== 32) {
			throw new RuntimeException('no shared secret could be agreed with the browser');
		}
		$salt = $salt ?? random_bytes(16);

		$ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua_public . $as_public, $auth);
		$cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
		$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

		// The last (only) record ends with 0x02, then zero padding.
		$record = str_pad($payload . "\x02", max($pad_to, strlen($payload) + 1), "\0", STR_PAD_RIGHT);
		$tag = '';
		$ciphertext = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
		if ($ciphertext === false) {
			throw new RuntimeException('the message could not be encrypted');
		}
		return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $as_public . $ciphertext . $tag;
	}

	// ── P-256 key shapes ────────────────────────────────────────────────────

	/** An ECDSA signature as OpenSSL writes it (DER) as JWS wants it (r || s, 32 bytes each). */
	private static function ecdsa_der_to_raw(string $der): string {
		$pos = 0;
		$byte = function () use ($der, &$pos): int {
			if ($pos >= strlen($der)) {
				throw new RuntimeException('the signature ends early');
			}
			return ord($der[$pos++]);
		};
		if ($byte() !== 0x30) {
			throw new RuntimeException('the signature is not a DER sequence');
		}
		$len = $byte();
		if ($len & 0x80) {
			$pos += $len & 0x7f;
		}
		$out = '';
		for ($i = 0; $i < 2; $i++) {
			if ($byte() !== 0x02) {
				throw new RuntimeException('the signature is not two DER integers');
			}
			$n = $byte();
			$raw = substr($der, $pos, $n);
			if (strlen($raw) !== $n) {
				throw new RuntimeException('the signature ends early');
			}
			$pos += $n;
			$int = ltrim($raw, "\0");
			if (strlen($int) > 32) {
				throw new RuntimeException('the signature integer is too long');
			}
			$out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
		}
		if ($pos !== strlen($der)) {
			throw new RuntimeException('the signature has trailing bytes');
		}
		return $out;
	}

	/** The 65-byte uncompressed point of an OpenSSL EC key. */
	private static function public_point($key): ?string {
		$d = openssl_pkey_get_details($key);
		if (!is_array($d) || ($d['ec']['curve_name'] ?? '') !== 'prime256v1') {
			return null;
		}
		return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
	}

	/** A P-256 public key OpenSSL can use, from its 65-byte point; null if the point is not on the curve. */
	private static function public_key_from_point(string $point) {
		if (strlen($point) !== 65 || $point[0] !== "\x04") {
			return null;
		}
		$der = hex2bin(self::P256_SPKI_PREFIX) . $point;
		$key = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
		return $key === false ? null : $key;
	}

	/**
	 * A P-256 private key OpenSSL can use, from its 32-byte scalar and 65-byte
	 * point (an RFC 5915 ECPrivateKey); null if they do not make a key.
	 */
	private static function private_key_from_raw(string $d, string $point) {
		if (strlen($d) !== 32 || strlen($point) !== 65 || $point[0] !== "\x04") {
			return null;
		}
		// OpenSSL loads a point that does not belong to the scalar and reads it
		// back as given, so the match is checked against the point the scalar makes.
		if (self::public_point_from_private_raw($d) !== $point) {
			return null;
		}
		$der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
		$key = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
		return $key === false ? null : $key;
	}

	/** The public point of a P-256 private scalar (OpenSSL computes it from the scalar alone). */
	private static function public_point_from_private_raw(string $d): ?string {
		if (strlen($d) !== 32) {
			return null;
		}
		// An ECPrivateKey may omit the public key; OpenSSL then derives it.
		$der = hex2bin('30310201010420') . $d . hex2bin('a00a06082a8648ce3d030107');
		$key = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
		return $key === false ? null : self::public_point($key);
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
			$generated = self::mint_key_pair();
			$box = new SecretBox();
			$blob = $box->seal(self::KEYS_SETTING, json_encode($generated));
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

	/**
	 * A fresh P-256 pair: the 65-byte public point and the 32-byte private
	 * scalar, each base64url without padding (the shape browsers take as
	 * applicationServerKey).
	 *
	 * @return array ['public' => ..., 'private' => ...]
	 */
	private static function mint_key_pair(): array {
		$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
		$d = $key === false ? false : openssl_pkey_get_details($key);
		if (!is_array($d) || empty($d['ec']['d'])) {
			throw new RuntimeException('OpenSSL could not make a P-256 key pair');
		}
		return array(
			'public'  => self::base64url_encode(self::public_point($key)),
			'private' => self::base64url_encode(str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT)),
		);
	}

	/** @return array|null ['public' => ..., 'private' => ..., 'signer' => the OpenSSL key] */
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
		// The two halves must make one key. A pair that does not would be
		// refused by every push service, and each refusal would count against
		// the member's browser; unusable keys instead turn push off here.
		$signer = self::private_key_from_raw(self::base64url_decode((string)$pair['private']) ?? '',
			self::base64url_decode((string)$pair['public']) ?? '');
		if ($signer === null) {
			error_log('[WebPushDriver] ' . self::KEYS_SETTING . ' holds a key pair whose halves do not match; push is off until it is re-minted');
			return null;
		}
		self::$keys = array('public' => (string)$pair['public'], 'private' => (string)$pair['private'], 'signer' => $signer);
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

	private static function base64url_encode(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
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
