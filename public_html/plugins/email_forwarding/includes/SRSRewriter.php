<?php
/**
 * SRSRewriter - Sender Rewriting Scheme for email forwarding.
 *
 * Rewrites envelope sender addresses so forwarded mail passes SPF checks
 * at the destination. Also handles decoding SRS addresses for bounce processing.
 *
 * Format: SRS0=HASH=TIMESTAMP=originaldomain=localpart@forwardingdomain
 *
 * @version 1.0
 */

class SRSRewriter {

	private $secret;
	private $max_age_days = 21;

	function __construct($secret = null) {
		if ($secret === null) {
			$settings = Globalvars::get_instance();
			$this->secret = $settings->get_setting('email_forwarding_srs_secret');
		} else {
			$this->secret = $secret;
		}
	}

	/**
	 * Check if an address is an SRS-rewritten address.
	 */
	static function isSRSAddress($address) {
		$local = explode('@', $address, 2)[0];
		return (strpos($local, 'SRS0=') === 0);
	}

	/**
	 * Rewrite a sender address for forwarding.
	 *
	 * @param string $sender_address   Original sender (e.g., alice@gmail.com)
	 * @param string $forwarding_domain Domain doing the forwarding (e.g., example.com)
	 * @return string SRS-rewritten address
	 */
	function rewrite($sender_address, $forwarding_domain) {
		$parts = explode('@', $sender_address, 2);
		if (count($parts) !== 2) {
			return $sender_address;
		}

		$local = $parts[0];
		$domain = $parts[1];
		$timestamp = $this->encode_timestamp();
		$hash = $this->generate_hash($timestamp, $domain, $local);

		return 'SRS0=' . $hash . '=' . $timestamp . '=' . $domain . '=' . $local . '@' . $forwarding_domain;
	}

	/**
	 * Decode an SRS address back to the original sender.
	 *
	 * @param string $srs_address SRS-encoded address
	 * @return string|false Original sender address, or false if invalid
	 */
	function decode($srs_address) {
		$parts = explode('@', $srs_address, 2);
		if (count($parts) !== 2) {
			return false;
		}

		$local = $parts[0];

		// Parse SRS0=HASH=TIMESTAMP=domain=localpart
		if (!preg_match('/^SRS0=([A-Za-z0-9+\/]+)=([A-Za-z0-9]+)=([^=]+)=(.+)$/', $local, $m)) {
			return false;
		}

		$original_domain = $m[3];
		$original_local = $m[4];

		return $original_local . '@' . $original_domain;
	}

	/**
	 * Validate an SRS address (check hash and timestamp expiry).
	 *
	 * @param string $srs_address SRS-encoded address
	 * @return bool True if valid and not expired
	 */
	function validate($srs_address) {
		$parts = explode('@', $srs_address, 2);
		if (count($parts) !== 2) {
			return false;
		}

		$local = $parts[0];

		if (!preg_match('/^SRS0=([A-Za-z0-9+\/]+)=([A-Za-z0-9]+)=([^=]+)=(.+)$/', $local, $m)) {
			return false;
		}

		$hash = $m[1];
		$timestamp = $m[2];
		$domain = $m[3];
		$original_local = $m[4];

		// Verify hash
		$expected_hash = $this->generate_hash($timestamp, $domain, $original_local);
		if (!hash_equals($expected_hash, $hash)) {
			return false;
		}

		// Check timestamp expiry
		if (!$this->check_timestamp($timestamp)) {
			return false;
		}

		return true;
	}

	/**
	 * Generate HMAC hash for SRS address components.
	 */
	private function generate_hash($timestamp, $domain, $local) {
		$data = $timestamp . '=' . $domain . '=' . $local;
		$full_hash = hash_hmac('sha256', $data, $this->secret);
		// Truncate to 6 characters (base64-safe subset)
		return substr(base64_encode(hex2bin(substr($full_hash, 0, 8))), 0, 6);
	}

	/**
	 * Encode current timestamp as days since epoch (base36 for compactness).
	 */
	private function encode_timestamp() {
		$days = intval(time() / 86400);
		return base_convert($days, 10, 36);
	}

	/**
	 * Check if a timestamp is still valid (not expired).
	 */
	private function check_timestamp($encoded_timestamp) {
		$days = intval(base_convert($encoded_timestamp, 36, 10));
		$current_days = intval(time() / 86400);
		return ($current_days - $days) <= $this->max_age_days;
	}
}
?>
