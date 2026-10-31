<?php
/**
 * XWing - the X-Wing hybrid KEM (X25519 + ML-KEM-768),
 * draft-connolly-cfrg-xwing-kem-11, the one KEM every Joinery seal uses
 * (specs/post_quantum_sealing.md R1).
 *
 *   - publicKey($seed)               32-byte seed -> 1216-byte encapsulation key pk_M ‖ pk_X
 *   - encapsulate($pk[, $eseed])     -> [ciphertext (1120) ct_M ‖ ct_X, shared secret (32)]
 *   - decapsulate($seed, $ct)        -> shared secret (32)
 *
 * The secret is the 32-byte seed; it is expanded with SHAKE256 to 96 bytes,
 * ML-KEM's d‖z then the X25519 scalar. The shared secret is
 * SHA3-256(ss_M ‖ ss_X ‖ ct_X ‖ pk_X ‖ "\.//^\"). ML-KEM comes from MlKem768
 * (OpenSSL 3.5 through FFI), X25519 from libsodium, SHA3-256 from hash().
 *
 * $eseed fixes the 64 bytes of encapsulation randomness (ML-KEM's m, then the
 * X25519 ephemeral) for the draft's known-answer vectors only; production
 * callers leave it null.
 *
 * The constructor throws MlKem768's floor message where ML-KEM is unavailable.
 * Every failure throws RuntimeException; nothing returns a partial result.
 *
 * tests/vault/xwing_vector_test.php holds this to the draft's Appendix C.
 *
 * @version 1.0
 */
class XWing {

	const SEED_BYTES        = 32;
	const PUBLIC_KEY_BYTES  = 1216;   // MlKem768::PUBLIC_KEY_BYTES + 32
	const CIPHERTEXT_BYTES  = 1120;   // MlKem768::CIPHERTEXT_BYTES + 32
	const SHARED_BYTES      = 32;
	const ENCAPS_SEED_BYTES = 64;

	/** The combiner's label, the 6 bytes \.//^\ (5c2e2f2f5e5c). */
	const LABEL = "\x5c\x2e\x2f\x2f\x5e\x5c";

	/** @var MlKem768 */
	private $kem;

	public function __construct() {
		$this->kem = new MlKem768();
	}

	/** The encapsulation key a seed generates (draft § GenerateKeyPairDerand). */
	public function publicKey(string $seed): string {
		[$seed_m, $sk_x, $pk_x] = $this->expand($seed);
		$pk_m = $this->kem->keygen($seed_m);
		sodium_memzero($seed_m);
		sodium_memzero($sk_x);
		return $pk_m . $pk_x;
	}

	/**
	 * Encapsulate to a 1216-byte key: a fresh 1120-byte ciphertext and the
	 * 32-byte shared secret it carries.
	 *
	 * @return array{0:string,1:string} [ciphertext, shared secret]
	 */
	public function encapsulate(string $public_key, ?string $eseed = null): array {
		if (strlen($public_key) !== self::PUBLIC_KEY_BYTES) {
			throw new RuntimeException('XWing: an encapsulation key must be ' . self::PUBLIC_KEY_BYTES . ' bytes.');
		}
		if ($eseed !== null && strlen($eseed) !== self::ENCAPS_SEED_BYTES) {
			throw new RuntimeException('XWing: encapsulation randomness must be ' . self::ENCAPS_SEED_BYTES . ' bytes.');
		}
		$pk_m = substr($public_key, 0, MlKem768::PUBLIC_KEY_BYTES);
		$pk_x = substr($public_key, MlKem768::PUBLIC_KEY_BYTES);
		$ek_x = $eseed === null ? random_bytes(32) : substr($eseed, 32, 32);
		$ct_x = sodium_crypto_scalarmult_base($ek_x);
		$ss_x = self::x25519($ek_x, $pk_x);
		sodium_memzero($ek_x);
		[$ct_m, $ss_m] = $this->kem->encapsulate($pk_m, $eseed === null ? null : substr($eseed, 0, 32));
		$ss = self::combine($ss_m, $ss_x, $ct_x, $pk_x);
		return [$ct_m . $ct_x, $ss];
	}

	/** The shared secret a 1120-byte ciphertext carries for this seed (draft § Decapsulate). */
	public function decapsulate(string $seed, string $ciphertext): string {
		if (strlen($ciphertext) !== self::CIPHERTEXT_BYTES) {
			throw new RuntimeException('XWing: a ciphertext must be ' . self::CIPHERTEXT_BYTES . ' bytes.');
		}
		[$seed_m, $sk_x, $pk_x] = $this->expand($seed);
		$ct_x = substr($ciphertext, MlKem768::CIPHERTEXT_BYTES);
		try {
			$ss_m = $this->kem->decapsulate($seed_m, substr($ciphertext, 0, MlKem768::CIPHERTEXT_BYTES));
			$ss_x = self::x25519($sk_x, $ct_x);
		} finally {
			sodium_memzero($seed_m);
			sodium_memzero($sk_x);
		}
		return self::combine($ss_m, $ss_x, $ct_x, $pk_x);
	}

	/** SHAKE256(seed, 96): ML-KEM's d‖z, the X25519 scalar, and that scalar's public point. */
	private function expand(string $seed): array {
		if (strlen($seed) !== self::SEED_BYTES) {
			throw new RuntimeException('XWing: a secret seed must be ' . self::SEED_BYTES . ' bytes.');
		}
		$expanded = $this->kem->shake256($seed, 96);
		$seed_m = substr($expanded, 0, MlKem768::SEED_BYTES);
		$sk_x = substr($expanded, 64, 32);
		sodium_memzero($expanded);
		return [$seed_m, $sk_x, sodium_crypto_scalarmult_base($sk_x)];
	}

	private static function combine(string $ss_m, string $ss_x, string $ct_x, string $pk_x): string {
		return hash('sha3-256', $ss_m . $ss_x . $ct_x . $pk_x . self::LABEL, true);
	}

	private static function x25519(string $scalar, string $point): string {
		try {
			return sodium_crypto_scalarmult($scalar, $point);
		} catch (SodiumException $e) {
			throw new RuntimeException('XWing: X25519 refused the key (a low-order point).');
		}
	}
}
?>
