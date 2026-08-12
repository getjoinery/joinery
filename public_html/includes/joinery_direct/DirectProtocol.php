<?php
/**
 * DirectProtocol - the constants and the canonical byte-forms of the Joinery
 * Direct wire, in one place.
 *
 * Two things get signed on every delivery and both are defined here, because a
 * signature is only worth anything if both ends agree byte-for-byte on what was
 * covered:
 *
 *   - the PREFLIGHT: the envelope plus the manifest of sizes, types and roles.
 *     This authenticates and dates the exchange, before any content exists.
 *   - the CONTENT TRANSFER: the per-part hashes of the SEALED bytes, bound to
 *     the preflight's nonce. This binds the exact delivered bytes, and it cannot
 *     ride the preflight because the sealed bytes do not exist until the
 *     recipient's key arrives in the `accept`.
 *
 * Hashing the ciphertext rather than the plaintext is deliberate: a receiver can
 * then verify without unsealing, so even a locked Fortress box rejects a
 * substituted part at receive instead of discovering it at unlock — which is
 * exactly the case that matters, because the relay forwarding those bytes is the
 * untrusted machine.
 *
 * PROTOCOL_VERSION names the shared layer (envelope shape, manifest fields, hash
 * and signature construction), never the payload. A receiver refuses a version
 * it does not implement at request level, in the same bucket as an invalid
 * signature, so version skew across a federation converges on the caller's
 * fallback and never breaks anything.
 *
 * @version 1.0
 */

class DirectProtocol {

	/** The version of the shared layer this build speaks. */
	const PROTOCOL_VERSION = 1;

	/** Versions this build will accept on an inbound envelope. */
	const SUPPORTED_VERSIONS = array(1);

	/** The path the endpoint is served at, on the SRV-advertised host and port. */
	const ENDPOINT_PATH = '/.well-known/joinery-direct';

	/** DNS names carrying the capability record. */
	const SRV_PREFIX = '_joinery._tcp.';
	const KEY_PREFIX = '_joinery-key.';

	/** The default kind when an envelope names none. */
	const KIND_MAIL = 'mail';

	// Part roles. A kind may use any subset; mail uses all four. The framework
	// treats every role opaquely — it transfers, sizes and hashes a part the same
	// way regardless — so a role is a label the KIND reads, never framework logic.
	const ROLE_BODY_TEXT  = 'body_text';
	const ROLE_BODY_HTML  = 'body_html';
	const ROLE_ATTACHMENT = 'attachment';
	// A kind's own structured header block (mail's subject/From/Message-ID/etc.).
	// It carries a role of its own so a receiver identifies it by that, never by a
	// content type a genuine attachment could also carry — otherwise a real
	// message/rfc822-headers attachment (a forwarded bounce report) would be
	// swallowed as if it were the message's headers.
	const ROLE_HEADERS    = 'headers';

	const ROLES = array(self::ROLE_BODY_TEXT, self::ROLE_BODY_HTML, self::ROLE_ATTACHMENT, self::ROLE_HEADERS);

	// The two wire answers to a preflight. There is never a third: a request-level
	// refusal is an HTTP status, not one of these, so the gate can never become an
	// oracle for the recipient's contact or block list.
	const ANSWER_ACCEPT   = 'accept';
	const ANSWER_DECLINED = 'declined';

	/** Freshness window on the signed timestamp, and the clock-skew margin. */
	const MAX_AGE_SECONDS    = 300;
	const MAX_FUTURE_SECONDS = 60;

	/** The request log feature key. Every refusal and downgrade is counted under it. */
	const LOG_FEATURE = 'joinery_direct';

	/**
	 * The exact bytes an instance signature over a preflight covers.
	 *
	 * Built from a canonical, order-fixed structure rather than from the received
	 * JSON text: two implementations that agree on the fields but not on key
	 * order, whitespace, or unicode escaping would otherwise produce signatures
	 * neither can verify.
	 */
	public static function preflightSigningBytes(array $envelope, array $manifest): string {
		$canonical = array(
			'v'         => (int)($envelope['protocol_version'] ?? self::PROTOCOL_VERSION),
			'kind'      => self::kindOrDefault($envelope['kind'] ?? ''),
			'sender'    => strtolower((string)($envelope['sender'] ?? '')),
			'recipient' => strtolower((string)($envelope['recipient'] ?? '')),
			'key_id'    => (string)($envelope['key_id'] ?? ''),
			'nonce'     => (string)($envelope['nonce'] ?? ''),
			'timestamp' => (string)($envelope['timestamp'] ?? ''),
			'manifest'  => self::canonicalManifest($manifest),
		);
		return 'joinery-direct:preflight:v' . $canonical['v'] . "\n" . self::encode($canonical);
	}

	/**
	 * The exact bytes an instance signature over a content transfer covers: the
	 * ordered per-part hashes of the SEALED bytes, bound to the preflight nonce
	 * so content cannot be spliced onto a different preflight.
	 *
	 * @param array $hashes ordered list of lowercase hex BLAKE2b-256 digests
	 */
	public static function transferSigningBytes(string $nonce, array $hashes): string {
		$canonical = array(
			'nonce'  => $nonce,
			'hashes' => array_values(array_map('strval', $hashes)),
		);
		return 'joinery-direct:transfer:v' . self::PROTOCOL_VERSION . "\n" . self::encode($canonical);
	}

	/**
	 * The manifest as it is signed: one ordered entry per part, carrying only
	 * what the receiver decides on before any content crosses the wire.
	 *
	 * Per-part integrity hashes are deliberately NOT here — they cover the sealed
	 * bytes, which do not exist yet.
	 */
	public static function canonicalManifest(array $manifest): array {
		$out = array();
		foreach (array_values($manifest) as $part) {
			$out[] = array(
				'role'         => (string)($part['role'] ?? ''),
				'content_type' => (string)($part['content_type'] ?? 'application/octet-stream'),
				'filename'     => (string)($part['filename'] ?? ''),
				'content_id'   => (string)($part['content_id'] ?? ''),
				'is_inline'    => !empty($part['is_inline']),
				'size'         => (int)($part['size'] ?? 0),
			);
		}
		return $out;
	}

	/** BLAKE2b-256 of some bytes, lowercase hex. The hash the transfer signs. */
	public static function hashBytes(string $bytes): string {
		return sodium_bin2hex(sodium_crypto_generichash($bytes, '', 32));
	}

	/**
	 * BLAKE2b-256 of a file's contents, streamed.
	 *
	 * A large attachment must never have to sit in memory as a string just to be
	 * hashed — peak memory is meant to scale with the largest single part, and
	 * even that only where sealing (a one-shot primitive) genuinely requires it.
	 */
	public static function hashFile(string $path): string {
		$state = sodium_crypto_generichash_init('', 32);
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			throw new RuntimeException('Cannot read part file: ' . $path);
		}
		try {
			while (!feof($fh)) {
				$chunk = fread($fh, 1048576);
				if ($chunk === false) {
					throw new RuntimeException('Read failed on part file: ' . $path);
				}
				if ($chunk !== '') {
					sodium_crypto_generichash_update($state, $chunk);
				}
			}
		} finally {
			fclose($fh);
		}
		return sodium_bin2hex(sodium_crypto_generichash_final($state, 32));
	}

	/** Deterministic JSON: fixed key order (as built), no escaping surprises. */
	private static function encode(array $value): string {
		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			// Invalid UTF-8 (e.g. a filename read off a filesystem as raw Latin-1)
			// makes json_encode return false, and 'prefix' . false concatenates to
			// just the prefix — so EVERY malformed envelope would sign one identical
			// byte string and the signature would stop binding anything. Fail loudly:
			// the send path catches this and reports a failure (for mail, SMTP).
			throw new RuntimeException('Joinery Direct: cannot build signing bytes: ' . json_last_error_msg());
		}
		return $json;
	}

	/**
	 * The largest a part of $plaintext_bytes can be once it is sealed.
	 *
	 * The manifest declares PLAINTEXT sizes, because it is written before the
	 * recipient's key exists and because the caps are about payload size. The
	 * bytes that then arrive are sealed and therefore bigger, so a receiver
	 * enforcing the manifest has to know by how much or it would abort every
	 * honest sealed delivery.
	 *
	 * The growth is fixed and knowable: a Direct part is sealed raw with
	 * `crypto_box_seal` (SealedBox::sealBinary), which adds only an ephemeral
	 * public key and a MAC — no base64, no prefix, because a bulk part carries no
	 * DEK text wrapping. Nothing here depends on the content.
	 */
	public static function sealedSizeCeiling(int $plaintext_bytes): int {
		return max(0, $plaintext_bytes) + SODIUM_CRYPTO_BOX_SEALBYTES;
	}

	/** A fresh 128-bit delivery nonce, hex. */
	public static function newNonce(): string {
		return bin2hex(random_bytes(16));
	}

	/**
	 * A kind, or KIND_MAIL when the envelope names none. "Names none" is a blank
	 * value as much as an absent one — an envelope carrying `kind: ""` and one
	 * carrying no kind at all mean the same thing and must sign and dispatch the
	 * same, or the relay would serve as mail what the box refuses (or vice versa).
	 */
	public static function kindOrDefault($kind): string {
		$kind = (string)$kind;
		return $kind === '' ? self::KIND_MAIL : $kind;
	}

	/** The lowercased domain part of an address, or '' when there is none. */
	public static function domainOf(string $address): string {
		$at = strrpos($address, '@');
		return $at === false ? '' : strtolower(trim(substr($address, $at + 1)));
	}

	/** The lowercased local part of an address, or '' when there is none. */
	public static function localPartOf(string $address): string {
		$at = strrpos($address, '@');
		return $at === false ? '' : strtolower(trim(substr($address, 0, $at)));
	}
}
