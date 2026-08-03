<?php
/**
 * GooglePlayHelper — Google Play Billing server-side integration.
 *
 * Three jobs, parallel to StripeHelper/AppStoreHelper:
 *
 * 1. Verify purchases with the Play Developer API (subscriptionsv2), using a
 *    service-account OAuth2 token built from the store_play_service_account_json
 *    setting — no Google SDK dependency, just an RS256-signed JWT grant.
 * 2. Verify the OIDC bearer token on Real-Time Developer Notification
 *    (Pub/Sub push) requests against Google's published signing keys.
 * 3. Acknowledge subscription purchases (unacknowledged purchases are
 *    refunded by Google after three days).
 *
 * Settings (store plugin): store_play_package_names (comma-separated allowed
 * Android package names), store_play_service_account_json (the service account's
 * JSON key), store_play_rtdn_audience (expected `aud` on RTDN push tokens).
 *
 * @version 1.0.0
 */

class GooglePlayHelperException extends Exception {}

class GooglePlayHelper {

	const ANDROID_PUBLISHER = 'https://androidpublisher.googleapis.com/androidpublisher/v3';
	const GOOGLE_JWKS_URL   = 'https://www.googleapis.com/oauth2/v3/certs';

	// Test seams. Tests inject API results and their own JWKS; live code
	// never sets these.
	public static $api_response_override = null;
	public static $jwks_override = null;

	private static $cached_access_token = null;
	private static $cached_access_token_expires = 0;

	/**
	 * Android package names allowed to bill through this deployment.
	 */
	public static function allowedPackageNames() {
		$settings = Globalvars::get_instance();
		$raw = (string)$settings->get_setting('store_play_package_names');
		$names = array_filter(array_map('trim', explode(',', $raw)));
		return array_values($names);
	}

	/**
	 * The decoded service-account key, or throws when unconfigured.
	 */
	public static function serviceAccount() {
		$settings = Globalvars::get_instance();
		$raw = $settings->get_setting('store_play_service_account_json');
		if (!$raw) {
			throw new GooglePlayHelperException('store_play_service_account_json is not configured');
		}
		$account = json_decode($raw, true);
		if (!is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
			throw new GooglePlayHelperException('store_play_service_account_json is not a valid service account key');
		}
		return $account;
	}

	public static function isConfiguredForApi() {
		$settings = Globalvars::get_instance();
		return (bool)$settings->get_setting('store_play_service_account_json');
	}

	/**
	 * Test purchases (license testers) are only accepted on deployments with
	 * the debug setting on, mirroring the App Store sandbox rule.
	 */
	public static function testPurchaseAllowed() {
		$settings = Globalvars::get_instance();
		return (bool)$settings->get_setting('debug');
	}

	/**
	 * OAuth2 access token for the Play Developer API (JWT bearer grant).
	 */
	public static function getAccessToken() {
		if (self::$cached_access_token && time() < self::$cached_access_token_expires - 60) {
			return self::$cached_access_token;
		}

		$account = self::serviceAccount();
		$token_uri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
		$now = time();

		$header = array('alg' => 'RS256', 'typ' => 'JWT');
		$claims = array(
			'iss'   => $account['client_email'],
			'scope' => 'https://www.googleapis.com/auth/androidpublisher',
			'aud'   => $token_uri,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);
		$signing_input = self::b64url_encode(json_encode($header)) . '.' . self::b64url_encode(json_encode($claims));

		$pkey = openssl_pkey_get_private($account['private_key']);
		if ($pkey === false) {
			throw new GooglePlayHelperException('Service account private key is unreadable');
		}
		if (!openssl_sign($signing_input, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
			throw new GooglePlayHelperException('Failed to sign service-account JWT');
		}
		$assertion = $signing_input . '.' . self::b64url_encode($signature);

		$response = self::httpRequest('POST', $token_uri, http_build_query(array(
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion'  => $assertion,
		)), array('Content-Type: application/x-www-form-urlencoded'));

		if (empty($response['access_token'])) {
			throw new GooglePlayHelperException('OAuth token exchange failed');
		}
		self::$cached_access_token = $response['access_token'];
		self::$cached_access_token_expires = time() + (int)($response['expires_in'] ?? 3600);
		return self::$cached_access_token;
	}

	/**
	 * The subscriptionsv2 purchase resource for a purchase token.
	 */
	public static function getSubscriptionPurchase($package_name, $purchase_token) {
		if (self::$api_response_override !== null) {
			return self::$api_response_override;
		}
		$url = self::ANDROID_PUBLISHER . '/applications/' . rawurlencode($package_name)
			. '/purchases/subscriptionsv2/tokens/' . rawurlencode($purchase_token);
		return self::httpRequest('GET', $url, null, array(
			'Authorization: Bearer ' . self::getAccessToken(),
			'Accept: application/json',
		));
	}

	/**
	 * Acknowledge a subscription purchase so Google keeps it.
	 */
	public static function acknowledgeSubscription($package_name, $subscription_id, $purchase_token) {
		if (self::$api_response_override !== null) {
			return true;
		}
		$url = self::ANDROID_PUBLISHER . '/applications/' . rawurlencode($package_name)
			. '/purchases/subscriptions/' . rawurlencode($subscription_id)
			. '/tokens/' . rawurlencode($purchase_token) . ':acknowledge';
		self::httpRequest('POST', $url, '{}', array(
			'Authorization: Bearer ' . self::getAccessToken(),
			'Content-Type: application/json',
		));
		return true;
	}

	/**
	 * Map a subscriptionsv2 subscriptionState to the odi_subscription_status
	 * vocabulary shared with Stripe ('active', 'grace_period', 'past_due',
	 * 'paused', 'expired').
	 */
	public static function mapSubscriptionState($state) {
		$map = array(
			'SUBSCRIPTION_STATE_ACTIVE'          => 'active',
			'SUBSCRIPTION_STATE_CANCELED'        => 'active', // auto-renew off, still entitled until expiry
			'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => 'grace_period',
			'SUBSCRIPTION_STATE_ON_HOLD'         => 'past_due',
			'SUBSCRIPTION_STATE_PAUSED'          => 'paused',
			'SUBSCRIPTION_STATE_EXPIRED'         => 'expired',
		);
		return $map[$state] ?? 'expired';
	}

	/**
	 * Latest expiry time across the purchase's line items, as a UTC
	 * 'Y-m-d H:i:s' string (or null).
	 */
	public static function purchaseExpiryTime(array $purchase) {
		$latest = null;
		foreach (($purchase['lineItems'] ?? array()) as $line) {
			if (!empty($line['expiryTime'])) {
				$ts = strtotime($line['expiryTime']);
				if ($ts !== false && ($latest === null || $ts > $latest)) {
					$latest = $ts;
				}
			}
		}
		return $latest === null ? null : gmdate('Y-m-d H:i:s', $latest);
	}

	/**
	 * Refresh a store-billed order item's subscription fields from the Play
	 * Developer API. Same role as
	 * StripeHelper::update_subscription_in_order_item.
	 */
	public static function update_subscription_in_order_item($order_item) {
		$purchase_token = $order_item->get('odi_play_purchase_token');
		if (!$purchase_token) {
			throw new GooglePlayHelperException('Order item has no Play purchase token');
		}

		$order = $order_item->get_order();
		$package_name = self::packageNameForOrderItem($order_item);
		$purchase = self::getSubscriptionPurchase($package_name, $purchase_token);

		$status = self::mapSubscriptionState($purchase['subscriptionState'] ?? '');
		$order_item->set('odi_subscription_status', $status);

		$expiry = self::purchaseExpiryTime($purchase);
		if ($expiry !== null) {
			$order_item->set('odi_subscription_period_end', $expiry);
		}

		if (($purchase['subscriptionState'] ?? '') === 'SUBSCRIPTION_STATE_CANCELED') {
			$order_item->set('odi_subscription_cancel_at_period_end', true);
		}

		if ($status === 'expired' && !$order_item->get('odi_subscription_cancelled_time')) {
			$order_item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
		}

		$order_item->save();
		return $order_item;
	}

	/**
	 * The package name a store-billed order item belongs to. Stored in the
	 * order item's product_info blob at claim time; falls back to the first
	 * configured package.
	 */
	public static function packageNameForOrderItem($order_item) {
		$info = $order_item->get('odi_product_info');
		if ($info) {
			$data = @unserialize(base64_decode($info));
			if (is_array($data) && !empty($data['play_package_name'])) {
				return $data['play_package_name'];
			}
		}
		$packages = self::allowedPackageNames();
		if (empty($packages)) {
			throw new GooglePlayHelperException('store_play_package_names is not configured');
		}
		return $packages[0];
	}

	/**
	 * Verify the OIDC bearer token on an RTDN Pub/Sub push request. Returns
	 * the verified claims; throws on any failure. The expected audience comes
	 * from the store_play_rtdn_audience setting — verification refuses to run when
	 * it is unconfigured.
	 */
	public static function verifyRtdnBearer($jwt) {
		$settings = Globalvars::get_instance();
		$expected_audience = $settings->get_setting('store_play_rtdn_audience');
		if (!$expected_audience) {
			throw new GooglePlayHelperException('store_play_rtdn_audience is not configured');
		}

		if (!is_string($jwt) || substr_count($jwt, '.') !== 2) {
			throw new GooglePlayHelperException('Malformed bearer token');
		}
		list($header_b64, $payload_b64, $signature_b64) = explode('.', $jwt);

		$header = json_decode(self::b64url_decode($header_b64), true);
		if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
			throw new GooglePlayHelperException('Unsupported bearer token header');
		}

		$jwks = self::$jwks_override !== null ? self::$jwks_override : self::httpRequest('GET', self::GOOGLE_JWKS_URL, null, array('Accept: application/json'));
		$public_key = null;
		foreach (($jwks['keys'] ?? array()) as $key) {
			if (($key['kid'] ?? '') === $header['kid'] && ($key['kty'] ?? '') === 'RSA') {
				$public_key = self::rsaPublicKeyFromJwk($key);
				break;
			}
		}
		if ($public_key === null) {
			throw new GooglePlayHelperException('No matching Google signing key for token');
		}

		$signature = self::b64url_decode($signature_b64);
		if (openssl_verify($header_b64 . '.' . $payload_b64, $signature, $public_key, OPENSSL_ALGO_SHA256) !== 1) {
			throw new GooglePlayHelperException('Bearer token signature verification failed');
		}

		$claims = json_decode(self::b64url_decode($payload_b64), true);
		if (!is_array($claims)) {
			throw new GooglePlayHelperException('Bearer token payload is not valid JSON');
		}
		if (!in_array($claims['iss'] ?? '', array('accounts.google.com', 'https://accounts.google.com'))) {
			throw new GooglePlayHelperException('Bearer token issuer is not Google');
		}
		if (($claims['aud'] ?? '') !== $expected_audience) {
			throw new GooglePlayHelperException('Bearer token audience mismatch');
		}
		if ((int)($claims['exp'] ?? 0) < time()) {
			throw new GooglePlayHelperException('Bearer token is expired');
		}
		return $claims;
	}

	/**
	 * Build an RSA public key from JWK modulus/exponent (DER-encoded
	 * SubjectPublicKeyInfo wrapped as PEM).
	 */
	public static function rsaPublicKeyFromJwk(array $jwk) {
		$n = self::b64url_decode($jwk['n']);
		$e = self::b64url_decode($jwk['e']);

		$der_int = function ($bytes) {
			if ($bytes === '' ) { $bytes = "\x00"; }
			if (ord($bytes[0]) > 0x7f) { $bytes = "\x00" . $bytes; }
			return "\x02" . self::derLength(strlen($bytes)) . $bytes;
		};

		$rsa_key = "\x30" . self::derLength(strlen($der_int($n) . $der_int($e))) . $der_int($n) . $der_int($e);
		$bit_string = "\x03" . self::derLength(strlen($rsa_key) + 1) . "\x00" . $rsa_key;
		// rsaEncryption OID 1.2.840.113549.1.1.1 + NULL params
		$algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
		$spki = "\x30" . self::derLength(strlen($algorithm . $bit_string)) . $algorithm . $bit_string;

		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
		$key = openssl_pkey_get_public($pem);
		if ($key === false) {
			throw new GooglePlayHelperException('Failed to build RSA public key from JWK');
		}
		return $key;
	}

	private static function derLength($length) {
		if ($length < 0x80) {
			return chr($length);
		}
		$bytes = '';
		while ($length > 0) {
			$bytes = chr($length & 0xff) . $bytes;
			$length >>= 8;
		}
		return chr(0x80 | strlen($bytes)) . $bytes;
	}

	// ---- encoding + transport helpers ----

	public static function b64url_decode($data) {
		$decoded = base64_decode(strtr($data, '-_', '+/'));
		if ($decoded === false) {
			throw new GooglePlayHelperException('Invalid base64url data');
		}
		return $decoded;
	}

	public static function b64url_encode($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	private static function httpRequest($method, $url, $body, array $headers) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
		));
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$response = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);

		if ($response === false) {
			throw new GooglePlayHelperException('Google API request failed: ' . $curl_error);
		}
		if ($status < 200 || $status >= 300) {
			throw new GooglePlayHelperException('Google API returned HTTP ' . $status . ' for ' . strtok($url, '?'));
		}
		$decoded = json_decode($response, true);
		if ($response !== '' && !is_array($decoded)) {
			throw new GooglePlayHelperException('Google API returned invalid JSON');
		}
		return is_array($decoded) ? $decoded : array();
	}
}

?>
