<?php
/**
 * RelayProtocol - the relay API's wire vocabulary, PHP side.
 *
 * THIS FILE IS AN INTEROP CONTRACT. Every construction here has an exact
 * counterpart in plugins/mailbox/provisioning/relay-sealer/relay_protocol.go,
 * and the two are pinned against each other by the relay-sealer wire gate
 * (direct_wire_gate.sh, run by plugins/mailbox/tests/direct_wire_gate.sh):
 * PHP emits the signing bytes for a fixed fixture, Go emits them, and the two
 * are diffed. A drift here would not throw anywhere - every pull from this
 * deployment would simply fail signature verification at the relay and read as
 * "the relay is down" - which is why the bytes are pinned rather than trusted.
 *
 * Two things are signed:
 *
 *   - a REQUEST ENVELOPE, by a tenant key (or the operator key), over the
 *     method, the request URI with its query string, a hash of the body, a
 *     nonce and a timestamp. Every /relay/ route on the relay carries one in
 *     the X-Joinery-Relay-Auth header.
 *   - the BIRTH REPORT, by the relay's own identity key, which the plane
 *     verifies before it pins the identity.
 *
 * Nothing here holds a key or makes a request. The signing identity and the
 * pinned curl handle are the plane-side client's; this class only says what
 * bytes they sign and what header they send.
 *
 * @version 1.0
 */

class RelayProtocol {

	/** The relay envelope version this deployment speaks. */
	const PROTOCOL_VERSION = 1;

	/** The header a signed request rides in: base64 of {"envelope":{...},"signature":"..."}. */
	const AUTH_HEADER = 'X-Joinery-Relay-Auth';

	/** The header the one-time run token rides in, on the bundle fetch and the birth report. */
	const RUN_TOKEN_HEADER = 'X-Joinery-Relay-Run-Token';

	/** The reserved tenant name the operator key answers to. */
	const OPERATOR_TENANT = 'operator';

	/** Domain separators, so a signature over one shape is never valid as another. */
	const REQUEST_SIGNING_PREFIX = "joinery-relay:request:v1\n";
	const BORN_SIGNING_PREFIX    = "joinery-relay:born:v1\n";

	/** Freshness window on the signed timestamp, and the clock-skew margin - Direct's numbers. */
	const MAX_AGE_SECONDS    = 300;
	const MAX_FUTURE_SECONDS = 60;

	/**
	 * The exact bytes a request signature covers.
	 *
	 * Field order is the contract: the Go side emits its struct in declaration
	 * order and this array is built in the same order. The method is
	 * upper-cased and the body hash lower-cased on both sides, so a client that
	 * wrote either loosely still signs what the relay checks.
	 *
	 * @param array $envelope protocol_version, tenant, method, request_uri, body_sha256, nonce, timestamp
	 */
	public static function requestSigningBytes(array $envelope): string {
		$canonical = array(
			'protocol_version' => (int)($envelope['protocol_version'] ?? self::PROTOCOL_VERSION),
			'tenant'           => (string)($envelope['tenant'] ?? ''),
			'method'           => strtoupper(trim((string)($envelope['method'] ?? ''))),
			'request_uri'      => (string)($envelope['request_uri'] ?? ''),
			'body_sha256'      => strtolower(trim((string)($envelope['body_sha256'] ?? ''))),
			'nonce'            => (string)($envelope['nonce'] ?? ''),
			'timestamp'        => (string)($envelope['timestamp'] ?? ''),
		);
		return self::REQUEST_SIGNING_PREFIX . self::encode($canonical);
	}

	/**
	 * The exact bytes a birth report's signature covers.
	 *
	 * @param array $report run_id, public_ip, identity_public_key, identity_fingerprint,
	 *                      relay_version, postfix, listener_443
	 */
	public static function bornSigningBytes(array $report): string {
		$canonical = array(
			'run_id'               => (string)($report['run_id'] ?? ''),
			'public_ip'            => (string)($report['public_ip'] ?? ''),
			'identity_public_key'  => (string)($report['identity_public_key'] ?? ''),
			'identity_fingerprint' => (string)($report['identity_fingerprint'] ?? ''),
			'relay_version'        => (string)($report['relay_version'] ?? ''),
			'postfix'              => (string)($report['postfix'] ?? ''),
			'listener_443'         => (string)($report['listener_443'] ?? ''),
		);
		return self::BORN_SIGNING_PREFIX . self::encode($canonical);
	}

	/**
	 * A complete envelope for one request, ready to sign. The timestamp is the
	 * relay's format (UTC, YYYY-MM-DD HH:MM:SS) and the nonce 16 random bytes.
	 *
	 * @param string $request_uri the path AND query string exactly as it will be sent
	 */
	public static function envelope(string $tenant, string $method, string $request_uri, string $body = ''): array {
		return array(
			'protocol_version' => self::PROTOCOL_VERSION,
			'tenant'           => $tenant,
			'method'           => strtoupper($method),
			'request_uri'      => $request_uri,
			'body_sha256'      => self::bodyHash($body),
			'nonce'            => self::newNonce(),
			'timestamp'        => gmdate('Y-m-d H:i:s'),
		);
	}

	/** Lowercase hex SHA-256 of a request body ("" for a bodyless request). */
	public static function bodyHash(string $body): string {
		return hash('sha256', $body);
	}

	/** 16 random bytes, standard base64 - the nonce shape the relay validates. */
	public static function newNonce(): string {
		return base64_encode(random_bytes(16));
	}

	/**
	 * The X-Joinery-Relay-Auth header value for a signed envelope.
	 *
	 * @param string $signature_b64 the detached Ed25519 signature, base64
	 */
	public static function authHeaderValue(array $envelope, string $signature_b64): string {
		return base64_encode(self::encode(array('envelope' => $envelope, 'signature' => $signature_b64)));
	}

	/**
	 * The CURLOPT_PINNEDPUBLICKEY string for a relay's reported identity
	 * fingerprint. The relay reports base64(SHA-256(SPKI DER)), which is
	 * exactly what curl compares after its "sha256//" prefix - no conversion.
	 */
	public static function curlPin(string $identity_fingerprint): string {
		return 'sha256//' . trim($identity_fingerprint);
	}

	/** Deterministic JSON: fixed key order (as built), slashes and unicode unescaped. */
	private static function encode(array $value): string {
		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			// Invalid UTF-8 would make this return false, and 'prefix' . false is
			// just the prefix - every malformed envelope would sign one identical
			// byte string. Fail loudly instead.
			throw new RuntimeException('Relay protocol: cannot build signing bytes: ' . json_last_error_msg());
		}
		return $json;
	}
}
?>
