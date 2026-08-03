<?php
/**
 * AppStoreHelper — Apple In-App Purchase server-side integration.
 *
 * Two jobs, parallel to StripeHelper:
 *
 * 1. Verify Apple-signed JWS payloads offline. StoreKit 2 transactions the
 *    app submits and App Store Server Notifications V2 both arrive as JWS
 *    strings signed by Apple, carrying their certificate chain in the x5c
 *    header. verifySignedPayload() validates that chain down to the pinned
 *    Apple Root CA - G3 (includes/certs/AppleRootCA-G3.pem) and the ES256
 *    signature before any payload field is trusted.
 *
 * 2. Call the App Store Server API (subscription status refresh) using an
 *    ES256 JWT built from the store_app_store_issuer_id / store_app_store_key_id /
 *    app_store_private_key settings.
 *
 * Settings (store plugin): store_app_store_issuer_id, store_app_store_key_id,
 * store_app_store_private_key (contents of the .p8 key), store_app_store_bundle_ids
 * (comma-separated allowed bundle IDs).
 *
 * @version 1.0.0
 */

class AppStoreHelperException extends Exception {}

class AppStoreHelper {

	const API_HOST_PRODUCTION = 'https://api.storekit.itunes.apple.com';
	const API_HOST_SANDBOX    = 'https://api.storekit-sandbox.itunes.apple.com';

	// Test seams. Tests sign payloads with their own chain and point the
	// verifier at their own root; live code never sets these.
	public static $root_ca_pem_override = null;
	public static $api_response_override = null;

	/**
	 * Bundle IDs allowed to bill through this deployment.
	 */
	public static function allowedBundleIds() {
		$settings = Globalvars::get_instance();
		$raw = (string)$settings->get_setting('store_app_store_bundle_ids');
		$ids = array_filter(array_map('trim', explode(',', $raw)));
		return array_values($ids);
	}

	/**
	 * Whether App Store Server API credentials are configured.
	 */
	public static function isConfiguredForApi() {
		$settings = Globalvars::get_instance();
		return $settings->get_setting('store_app_store_issuer_id')
			&& $settings->get_setting('store_app_store_key_id')
			&& $settings->get_setting('store_app_store_private_key');
	}

	/**
	 * Sandbox payloads are only accepted on deployments with the debug
	 * setting on, so App Store sandbox testing maps to dev tiers without
	 * polluting production billing records.
	 */
	public static function environmentAllowed($environment) {
		if (strcasecmp((string)$environment, 'Production') === 0) {
			return true;
		}
		$settings = Globalvars::get_instance();
		return (bool)$settings->get_setting('debug');
	}

	/**
	 * Verify an Apple JWS (signed transaction, renewal info, or notification
	 * envelope) and return its decoded payload array. Throws on any failure —
	 * bad structure, broken chain, untrusted root, expired certificates, or
	 * bad signature.
	 */
	public static function verifySignedPayload($jws) {
		if (!is_string($jws) || substr_count($jws, '.') !== 2) {
			throw new AppStoreHelperException('Malformed JWS');
		}
		list($header_b64, $payload_b64, $signature_b64) = explode('.', $jws);

		$header = json_decode(self::b64url_decode($header_b64), true);
		if (!is_array($header) || ($header['alg'] ?? '') !== 'ES256' || empty($header['x5c']) || !is_array($header['x5c'])) {
			throw new AppStoreHelperException('Unsupported JWS header');
		}

		$leaf_public_key = self::verifyCertificateChain($header['x5c']);

		$signature = self::b64url_decode($signature_b64);
		if (strlen($signature) !== 64) {
			throw new AppStoreHelperException('Malformed ES256 signature');
		}
		$der_signature = self::es256RawToDer($signature);

		$verified = openssl_verify($header_b64 . '.' . $payload_b64, $der_signature, $leaf_public_key, OPENSSL_ALGO_SHA256);
		if ($verified !== 1) {
			throw new AppStoreHelperException('JWS signature verification failed');
		}

		$payload = json_decode(self::b64url_decode($payload_b64), true);
		if (!is_array($payload)) {
			throw new AppStoreHelperException('JWS payload is not valid JSON');
		}
		return $payload;
	}

	/**
	 * Validate the x5c chain: each certificate signed by the next, every
	 * certificate within its validity window, and the chain anchored at the
	 * pinned Apple root. Returns the leaf certificate's public key.
	 */
	private static function verifyCertificateChain(array $x5c) {
		$certs = array();
		foreach ($x5c as $cert_b64) {
			$pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($cert_b64, 64, "\n") . "-----END CERTIFICATE-----\n";
			$cert = openssl_x509_read($pem);
			if ($cert === false) {
				throw new AppStoreHelperException('Unreadable certificate in x5c chain');
			}
			$certs[] = $cert;
		}
		if (count($certs) < 2) {
			throw new AppStoreHelperException('Certificate chain too short');
		}

		$now = time();
		foreach ($certs as $cert) {
			$info = openssl_x509_parse($cert);
			if (!$info || $now < $info['validFrom_time_t'] || $now > $info['validTo_time_t']) {
				throw new AppStoreHelperException('Certificate in chain is outside its validity window');
			}
		}

		// Each certificate must be signed by the next one up.
		for ($i = 0; $i < count($certs) - 1; $i++) {
			$issuer_key = openssl_pkey_get_public($certs[$i + 1]);
			if ($issuer_key === false || openssl_x509_verify($certs[$i], $issuer_key) !== 1) {
				throw new AppStoreHelperException('Certificate chain link verification failed');
			}
		}

		// The last certificate must be the pinned root.
		$root_pem = self::$root_ca_pem_override;
		if ($root_pem === null) {
			$root_pem = file_get_contents(PathHelper::getIncludePath('plugins/store/includes/certs/AppleRootCA-G3.pem'));
		}
		$pinned = openssl_x509_read($root_pem);
		if ($pinned === false) {
			throw new AppStoreHelperException('Pinned root certificate unreadable');
		}
		$chain_root_fp = openssl_x509_fingerprint($certs[count($certs) - 1], 'sha256');
		$pinned_fp = openssl_x509_fingerprint($pinned, 'sha256');
		if (!$chain_root_fp || !hash_equals($pinned_fp, $chain_root_fp)) {
			throw new AppStoreHelperException('Certificate chain does not anchor at the trusted root');
		}

		$leaf_key = openssl_pkey_get_public($certs[0]);
		if ($leaf_key === false) {
			throw new AppStoreHelperException('Cannot extract leaf public key');
		}
		return $leaf_key;
	}

	/**
	 * Subscription statuses for an original transaction ID from the App Store
	 * Server API. $environment: 'Production' or 'Sandbox'.
	 */
	public static function getSubscriptionStatuses($original_transaction_id, $environment) {
		if (self::$api_response_override !== null) {
			return self::$api_response_override;
		}
		if (!self::isConfiguredForApi()) {
			throw new AppStoreHelperException('App Store Server API credentials are not configured');
		}

		$host = (strcasecmp((string)$environment, 'Sandbox') === 0) ? self::API_HOST_SANDBOX : self::API_HOST_PRODUCTION;
		$url = $host . '/inApps/v1/subscriptions/' . rawurlencode($original_transaction_id);

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => array(
				'Authorization: Bearer ' . self::makeApiJwt(),
				'Accept: application/json',
			),
		));
		$body = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);

		if ($body === false) {
			throw new AppStoreHelperException('App Store Server API request failed: ' . $curl_error);
		}
		if ($status < 200 || $status >= 300) {
			throw new AppStoreHelperException('App Store Server API returned HTTP ' . $status);
		}
		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			throw new AppStoreHelperException('App Store Server API returned invalid JSON');
		}
		return $decoded;
	}

	/**
	 * Refresh a store-billed order item's subscription fields from the App
	 * Store Server API. Same role as
	 * StripeHelper::update_subscription_in_order_item.
	 */
	public static function update_subscription_in_order_item($order_item) {
		$original_transaction_id = $order_item->get('odi_app_store_original_transaction_id');
		if (!$original_transaction_id) {
			throw new AppStoreHelperException('Order item has no App Store transaction ID');
		}

		$environment = (strcasecmp((string)$order_item->get('odi_store_environment'), 'sandbox') === 0) ? 'Sandbox' : 'Production';
		$statuses = self::getSubscriptionStatuses($original_transaction_id, $environment);

		// Response: data[] of subscription groups, each with lastTransactions[]
		// of {originalTransactionId, status, signedTransactionInfo}.
		$entry = null;
		foreach (($statuses['data'] ?? array()) as $group) {
			foreach (($group['lastTransactions'] ?? array()) as $last) {
				if (($last['originalTransactionId'] ?? '') === $original_transaction_id) {
					$entry = $last;
					break 2;
				}
			}
		}
		if ($entry === null) {
			throw new AppStoreHelperException('No status entry for transaction ' . $original_transaction_id);
		}

		// 1 active, 2 expired, 3 billing retry, 4 grace period, 5 revoked
		$status_map = array(1 => 'active', 2 => 'expired', 3 => 'past_due', 4 => 'grace_period', 5 => 'canceled');
		$status = $status_map[(int)($entry['status'] ?? 0)] ?? 'expired';
		$order_item->set('odi_subscription_status', $status);

		if (!empty($entry['signedTransactionInfo'])) {
			$transaction = self::verifySignedPayload($entry['signedTransactionInfo']);
			if (!empty($transaction['expiresDate'])) {
				$order_item->set('odi_subscription_period_end', gmdate('Y-m-d H:i:s', (int)($transaction['expiresDate'] / 1000)));
			}
		}

		if (in_array($status, array('expired', 'canceled')) && !$order_item->get('odi_subscription_cancelled_time')) {
			$order_item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
		}

		$order_item->save();
		return $order_item;
	}

	/**
	 * ES256 JWT for the App Store Server API.
	 */
	private static function makeApiJwt() {
		$settings = Globalvars::get_instance();
		$issuer_id = $settings->get_setting('store_app_store_issuer_id');
		$key_id = $settings->get_setting('store_app_store_key_id');
		$private_key_pem = $settings->get_setting('store_app_store_private_key');

		$bundle_ids = self::allowedBundleIds();
		$now = time();
		$header = array('alg' => 'ES256', 'kid' => $key_id, 'typ' => 'JWT');
		$claims = array(
			'iss' => $issuer_id,
			'iat' => $now,
			'exp' => $now + 1200,
			'aud' => 'appstoreconnect-v1',
		);
		if (!empty($bundle_ids)) {
			$claims['bid'] = $bundle_ids[0];
		}

		$signing_input = self::b64url_encode(json_encode($header)) . '.' . self::b64url_encode(json_encode($claims));

		$pkey = openssl_pkey_get_private($private_key_pem);
		if ($pkey === false) {
			throw new AppStoreHelperException('store_app_store_private_key is not a readable private key');
		}
		if (!openssl_sign($signing_input, $der_signature, $pkey, OPENSSL_ALGO_SHA256)) {
			throw new AppStoreHelperException('Failed to sign App Store API JWT');
		}
		return $signing_input . '.' . self::b64url_encode(self::es256DerToRaw($der_signature));
	}

	// ---- encoding helpers ----

	public static function b64url_decode($data) {
		$decoded = base64_decode(strtr($data, '-_', '+/'));
		if ($decoded === false) {
			throw new AppStoreHelperException('Invalid base64url data');
		}
		return $decoded;
	}

	public static function b64url_encode($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Raw 64-byte ES256 signature (r||s) → DER SEQUENCE(INTEGER r, INTEGER s).
	 */
	public static function es256RawToDer($raw) {
		$r = ltrim(substr($raw, 0, 32), "\x00");
		$s = ltrim(substr($raw, 32, 32), "\x00");
		if ($r === '') { $r = "\x00"; }
		if ($s === '') { $s = "\x00"; }
		if (ord($r[0]) > 0x7f) { $r = "\x00" . $r; }
		if (ord($s[0]) > 0x7f) { $s = "\x00" . $s; }
		$body = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
		return "\x30" . chr(strlen($body)) . $body;
	}

	/**
	 * DER ECDSA signature → raw 64-byte r||s.
	 */
	public static function es256DerToRaw($der) {
		$offset = 2; // 0x30 len
		if (ord($der[1]) & 0x80) {
			$offset += ord($der[1]) & 0x7f;
		}
		$parts = array();
		for ($i = 0; $i < 2; $i++) {
			if (ord($der[$offset]) !== 0x02) {
				throw new AppStoreHelperException('Malformed DER signature');
			}
			$len = ord($der[$offset + 1]);
			$int = substr($der, $offset + 2, $len);
			$offset += 2 + $len;
			$int = ltrim($int, "\x00");
			$parts[] = str_pad($int, 32, "\x00", STR_PAD_LEFT);
		}
		return $parts[0] . $parts[1];
	}
}

?>
