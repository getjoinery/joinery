<?php
/**
 * MlKem768 - ML-KEM-768 (FIPS 203) and SHAKE256, from OpenSSL 3.5 through FFI.
 *
 * Neither PHP's sodium nor its openssl extension exposes a KEM, but the
 * libcrypto PHP already links carries an audited, constant-time ML-KEM from
 * OpenSSL 3.5 on. This class reaches it through PHP's FFI extension over the
 * public, documented 3.x EVP API — nothing here depends on a private struct
 * beyond OSSL_PARAM, which is part of that API.
 *
 * It is the one class in the tree that declares an FFI binding
 * and the one PHP file that touches FFI at all (tests/vault/mlkem768_test.php
 * holds the tree to that). X-Wing, the hybrid KEM of
 * specs/post_quantum_sealing.md R1, is built on top of it.
 *
 *   - keygen($seed64)              d‖z (64 bytes) -> encapsulation key (1184)
 *   - encapsulate($ek[, $m])       -> [ciphertext (1088), shared secret (32)]
 *   - decapsulate($seed64, $ct)    -> shared secret (32)
 *   - shake256($data, $len)        SHAKE256 XOF output
 *
 * $m is the 32-byte encapsulation randomness and exists for known-answer tests
 * only; production callers leave it null and OpenSSL draws its own.
 *
 * The constructor refuses when FFI cannot be used in this process or libcrypto
 * is older than 3.5, with one plain message naming the floor. Every failure
 * after that throws; nothing returns a partial or unverified result.
 *
 * libcrypto's error queue is shared with PHP's openssl extension, so every
 * public call starts and ends with it cleared: a failure here never reports an
 * earlier openssl_*() error, and openssl_error_string() never reports one of ours.
 *
 * @version 1.1 - unavailableCause(); the shared OpenSSL error queue is cleared
 *                around every call and fail() reads the newest entry
 * @version 1.0
 */
class MlKem768 {

	const SEED_BYTES       = 64;
	const PUBLIC_KEY_BYTES = 1184;
	const EXPANDED_SECRET_KEY_BYTES = 2400;
	const CIPHERTEXT_BYTES = 1088;
	const SHARED_BYTES     = 32;
	const ENCAPS_SEED_BYTES = 32;

	const OPENSSL_FLOOR     = 0x30500000;   // 3.5.0
	const FLOOR_MESSAGE     = 'Post-quantum sealing needs PHP\'s FFI extension enabled for this process (ffi.enable=On) and OpenSSL 3.5 or later, which ship with Ubuntu 26.04.';

	const LIBCRYPTO = 'libcrypto.so.3';

	// Why ML-KEM is unavailable, as a code a caller can branch its advice on:
	// the first two are fixed by configuration (the host converger sets
	// ffi.enable = On), the last two only by a newer OS.
	const CAUSE_EXTENSION = 'ffi_extension';
	const CAUSE_SETTING   = 'ffi_setting';
	const CAUSE_LIBCRYPTO = 'libcrypto_missing';
	const CAUSE_OPENSSL   = 'openssl_too_old';

	const EVP_PKEY_KEYPAIR        = 0x87;
	const OSSL_PARAM_OCTET_STRING = 5;

	private const CDEF = <<<'C'
typedef struct ossl_param_st {
	const char *key;
	unsigned int data_type;
	void *data;
	size_t data_size;
	size_t return_size;
} OSSL_PARAM;
typedef struct evp_pkey_st EVP_PKEY;
typedef struct evp_pkey_ctx_st EVP_PKEY_CTX;
typedef struct evp_md_st EVP_MD;
typedef struct evp_md_ctx_st EVP_MD_CTX;
typedef struct ossl_lib_ctx_st OSSL_LIB_CTX;

unsigned long OpenSSL_version_num(void);
unsigned long ERR_get_error(void);
unsigned long ERR_peek_last_error(void);
void ERR_error_string_n(unsigned long e, char *buf, size_t len);
void ERR_clear_error(void);

EVP_PKEY_CTX *EVP_PKEY_CTX_new_from_name(OSSL_LIB_CTX *libctx, const char *name, const char *propquery);
EVP_PKEY_CTX *EVP_PKEY_CTX_new_from_pkey(OSSL_LIB_CTX *libctx, EVP_PKEY *pkey, const char *propquery);
void EVP_PKEY_CTX_free(EVP_PKEY_CTX *ctx);
int EVP_PKEY_fromdata_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_fromdata(EVP_PKEY_CTX *ctx, EVP_PKEY **ppkey, int selection, OSSL_PARAM params[]);
int EVP_PKEY_get_octet_string_param(const EVP_PKEY *pkey, const char *key_name, unsigned char *buf, size_t max_buf_sz, size_t *out_len);
EVP_PKEY *EVP_PKEY_new_raw_public_key_ex(OSSL_LIB_CTX *libctx, const char *keytype, const char *propq, const unsigned char *pub, size_t len);
void EVP_PKEY_free(EVP_PKEY *pkey);

int EVP_PKEY_encapsulate_init(EVP_PKEY_CTX *ctx, const OSSL_PARAM params[]);
int EVP_PKEY_encapsulate(EVP_PKEY_CTX *ctx, unsigned char *wrappedkey, size_t *wrappedkeylen, unsigned char *genkey, size_t *genkeylen);
int EVP_PKEY_decapsulate_init(EVP_PKEY_CTX *ctx, const OSSL_PARAM params[]);
int EVP_PKEY_decapsulate(EVP_PKEY_CTX *ctx, unsigned char *unwrapped, size_t *unwrappedlen, const unsigned char *wrapped, size_t wrappedlen);

EVP_MD *EVP_MD_fetch(OSSL_LIB_CTX *ctx, const char *algorithm, const char *properties);
void EVP_MD_free(EVP_MD *md);
EVP_MD_CTX *EVP_MD_CTX_new(void);
void EVP_MD_CTX_free(EVP_MD_CTX *ctx);
int EVP_DigestInit_ex(EVP_MD_CTX *ctx, const EVP_MD *type, void *impl);
int EVP_DigestUpdate(EVP_MD_CTX *ctx, const void *d, size_t cnt);
int EVP_DigestFinalXOF(EVP_MD_CTX *ctx, unsigned char *md, size_t len);
C;

	private static $ffi = null;
	private static $unavailable = null;
	private static $cause = null;

	public function __construct() {
		$why = self::unavailableReason();
		if ($why !== null) {
			throw new RuntimeException('MlKem768: ' . self::FLOOR_MESSAGE . ' (' . $why . ')');
		}
	}

	/**
	 * Why this process cannot use ML-KEM, or null when it can. Cached for the
	 * life of the process; never throws.
	 */
	public static function unavailableReason(): ?string {
		if (self::$ffi !== null) {
			return null;
		}
		if (self::$unavailable !== null) {
			return self::$unavailable;
		}
		if (!extension_loaded('ffi')) {
			self::$cause = self::CAUSE_EXTENSION;
			return self::$unavailable = 'the FFI extension is not loaded';
		}
		$enable = strtolower((string)ini_get('ffi.enable'));
		$cli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
		if (!in_array($enable, ['1', 'on', 'true', 'yes'], true) && !($cli && $enable === 'preload')) {
			self::$cause = self::CAUSE_SETTING;
			return self::$unavailable = 'ffi.enable is "' . $enable . '" in the ' . PHP_SAPI . ' SAPI';
		}
		try {
			$ffi = FFI::cdef(self::CDEF, self::LIBCRYPTO);
		} catch (Throwable $e) {
			self::$cause = self::CAUSE_LIBCRYPTO;
			return self::$unavailable = 'libcrypto could not be bound: ' . $e->getMessage();
		}
		$version = (int)$ffi->OpenSSL_version_num();
		if ($version < self::OPENSSL_FLOOR) {
			self::$cause = self::CAUSE_OPENSSL;
			return self::$unavailable = sprintf('libcrypto is 0x%08x, older than 3.5', $version);
		}
		self::$ffi = $ffi;
		return null;
	}

	/**
	 * One of the CAUSE_* codes when unavailableReason() is not null, else null.
	 */
	public static function unavailableCause(): ?string {
		return self::unavailableReason() === null ? null : self::$cause;
	}

	/**
	 * Deterministic key generation from the 64-byte seed d‖z (FIPS 203
	 * ML-KEM.KeyGen_internal). Returns the 1184-byte encapsulation key.
	 */
	public function keygen(string $seed): string {
		self::$ffi->ERR_clear_error();
		$pkey = $this->pkeyFromSeed($seed);
		try {
			return $this->publicKeyOf($pkey);
		} finally {
			self::$ffi->EVP_PKEY_free($pkey);
			self::$ffi->ERR_clear_error();
		}
	}

	/**
	 * Encapsulate to a 1184-byte encapsulation key.
	 *
	 * @param string|null $m 32 bytes of encapsulation randomness, for
	 *                       known-answer tests only; null draws fresh entropy
	 * @return array{0:string,1:string} [ciphertext (1088), shared secret (32)]
	 */
	public function encapsulate(string $public_key, ?string $m = null): array {
		if (strlen($public_key) !== self::PUBLIC_KEY_BYTES) {
			throw new RuntimeException('MlKem768: an encapsulation key is ' . self::PUBLIC_KEY_BYTES . ' bytes.');
		}
		if ($m !== null && strlen($m) !== self::ENCAPS_SEED_BYTES) {
			throw new RuntimeException('MlKem768: encapsulation randomness is ' . self::ENCAPS_SEED_BYTES . ' bytes.');
		}
		$f = self::$ffi;
		$f->ERR_clear_error();
		$pub = self::buf($public_key);
		$pkey = $f->EVP_PKEY_new_raw_public_key_ex(null, 'ML-KEM-768', null, $pub, strlen($public_key));
		if ($pkey === null) {
			self::fail('the encapsulation key was refused');
		}
		$ctx = null;
		try {
			$ctx = $f->EVP_PKEY_CTX_new_from_pkey(null, $pkey, null);
			if ($ctx === null) {
				self::fail('no encapsulation context');
			}
			$params = null;
			$ikme = null;
			if ($m !== null) {
				$ikme = self::buf($m);
				$params = self::octetParams('ikme', $ikme, strlen($m), $hold_name);
			}
			if ($f->EVP_PKEY_encapsulate_init($ctx, $params) !== 1) {
				self::fail('encapsulate init failed');
			}
			$ct = $f->new('unsigned char[' . self::CIPHERTEXT_BYTES . ']');
			$ss = $f->new('unsigned char[' . self::SHARED_BYTES . ']');
			$ct_len = $f->new('size_t');
			$ss_len = $f->new('size_t');
			$ct_len->cdata = self::CIPHERTEXT_BYTES;
			$ss_len->cdata = self::SHARED_BYTES;
			if ($f->EVP_PKEY_encapsulate($ctx, $ct, FFI::addr($ct_len), $ss, FFI::addr($ss_len)) !== 1
				|| $ct_len->cdata !== self::CIPHERTEXT_BYTES || $ss_len->cdata !== self::SHARED_BYTES) {
				self::fail('encapsulate failed');
			}
			$out = [FFI::string($ct, self::CIPHERTEXT_BYTES), FFI::string($ss, self::SHARED_BYTES)];
			FFI::memset($ss, 0, self::SHARED_BYTES);
			return $out;
		} finally {
			if ($ctx !== null) {
				$f->EVP_PKEY_CTX_free($ctx);
			}
			$f->EVP_PKEY_free($pkey);
			$f->ERR_clear_error();
		}
	}

	/**
	 * Decapsulate with the key generated from the 64-byte seed. A malformed
	 * ciphertext of the right length does not throw: ML-KEM's implicit
	 * rejection returns a pseudorandom secret, so the seal's AEAD tag is what
	 * refuses it.
	 */
	public function decapsulate(string $seed, string $ciphertext): string {
		self::$ffi->ERR_clear_error();
		$pkey = $this->pkeyFromSeed($seed);
		try {
			return $this->decapsulateWith($pkey, $ciphertext);
		} finally {
			self::$ffi->EVP_PKEY_free($pkey);
			self::$ffi->ERR_clear_error();
		}
	}

	/**
	 * Decapsulate with a FIPS 203 expanded decapsulation key (2400 bytes).
	 * The platform only ever stores seeds; this exists so the NIST
	 * known-answer decapsulation vectors, which carry expanded keys, can be
	 * run against the same OpenSSL code path.
	 */
	public function decapsulateExpanded(string $expanded_key, string $ciphertext): string {
		if (strlen($expanded_key) !== self::EXPANDED_SECRET_KEY_BYTES) {
			throw new RuntimeException('MlKem768: an expanded decapsulation key is ' . self::EXPANDED_SECRET_KEY_BYTES . ' bytes.');
		}
		self::$ffi->ERR_clear_error();
		$pkey = $this->pkeyFromParam('priv', $expanded_key);
		try {
			return $this->decapsulateWith($pkey, $ciphertext);
		} finally {
			self::$ffi->EVP_PKEY_free($pkey);
			self::$ffi->ERR_clear_error();
		}
	}

	/**
	 * SHAKE256 with $length bytes of output (PHP's hash() has SHA3 but no XOF).
	 */
	public function shake256(string $data, int $length): string {
		if ($length < 1) {
			throw new RuntimeException('MlKem768: SHAKE256 output length must be positive.');
		}
		$f = self::$ffi;
		$f->ERR_clear_error();
		$md = $f->EVP_MD_fetch(null, 'SHAKE256', null);
		if ($md === null) {
			self::fail('SHAKE256 is not available');
		}
		$ctx = $f->EVP_MD_CTX_new();
		try {
			if ($ctx === null || $f->EVP_DigestInit_ex($ctx, $md, null) !== 1) {
				self::fail('SHAKE256 init failed');
			}
			if ($data !== '') {
				$in = self::buf($data);
				if ($f->EVP_DigestUpdate($ctx, $in, strlen($data)) !== 1) {
					self::fail('SHAKE256 update failed');
				}
			}
			$out = $f->new('unsigned char[' . $length . ']');
			if ($f->EVP_DigestFinalXOF($ctx, $out, $length) !== 1) {
				self::fail('SHAKE256 finalize failed');
			}
			return FFI::string($out, $length);
		} finally {
			if ($ctx !== null) {
				$f->EVP_MD_CTX_free($ctx);
			}
			$f->EVP_MD_free($md);
			$f->ERR_clear_error();
		}
	}

	private function pkeyFromSeed(string $seed) {
		if (strlen($seed) !== self::SEED_BYTES) {
			throw new RuntimeException('MlKem768: a key seed is ' . self::SEED_BYTES . ' bytes.');
		}
		return $this->pkeyFromParam('seed', $seed);
	}

	private function pkeyFromParam(string $name, string $bytes) {
		$f = self::$ffi;
		$ctx = $f->EVP_PKEY_CTX_new_from_name(null, 'ML-KEM-768', null);
		if ($ctx === null) {
			self::fail('ML-KEM-768 is not available in this libcrypto');
		}
		$buf = self::buf($bytes);
		try {
			$params = self::octetParams($name, $buf, strlen($bytes), $hold_name);
			$pp = $f->new('EVP_PKEY*');
			if ($f->EVP_PKEY_fromdata_init($ctx) !== 1
				|| $f->EVP_PKEY_fromdata($ctx, FFI::addr($pp), self::EVP_PKEY_KEYPAIR, $params) !== 1
				|| FFI::isNull($pp)) {
				self::fail('the key could not be loaded');
			}
			return $pp;
		} finally {
			FFI::memset($buf, 0, strlen($bytes));
			$f->EVP_PKEY_CTX_free($ctx);
		}
	}

	private function publicKeyOf($pkey): string {
		$f = self::$ffi;
		$out = $f->new('unsigned char[' . self::PUBLIC_KEY_BYTES . ']');
		$len = $f->new('size_t');
		if ($f->EVP_PKEY_get_octet_string_param($pkey, 'pub', $out, self::PUBLIC_KEY_BYTES, FFI::addr($len)) !== 1
			|| $len->cdata !== self::PUBLIC_KEY_BYTES) {
			self::fail('the encapsulation key could not be read');
		}
		return FFI::string($out, self::PUBLIC_KEY_BYTES);
	}

	private function decapsulateWith($pkey, string $ciphertext): string {
		if (strlen($ciphertext) !== self::CIPHERTEXT_BYTES) {
			throw new RuntimeException('MlKem768: a ciphertext is ' . self::CIPHERTEXT_BYTES . ' bytes.');
		}
		$f = self::$ffi;
		$ctx = $f->EVP_PKEY_CTX_new_from_pkey(null, $pkey, null);
		if ($ctx === null) {
			self::fail('no decapsulation context');
		}
		try {
			if ($f->EVP_PKEY_decapsulate_init($ctx, null) !== 1) {
				self::fail('decapsulate init failed');
			}
			$ct = self::buf($ciphertext);
			$ss = $f->new('unsigned char[' . self::SHARED_BYTES . ']');
			$ss_len = $f->new('size_t');
			$ss_len->cdata = self::SHARED_BYTES;
			if ($f->EVP_PKEY_decapsulate($ctx, $ss, FFI::addr($ss_len), $ct, self::CIPHERTEXT_BYTES) !== 1
				|| $ss_len->cdata !== self::SHARED_BYTES) {
				self::fail('decapsulate failed');
			}
			$out = FFI::string($ss, self::SHARED_BYTES);
			FFI::memset($ss, 0, self::SHARED_BYTES);
			return $out;
		} finally {
			$f->EVP_PKEY_CTX_free($ctx);
		}
	}

	/** A two-entry OSSL_PARAM array: one octet string, then the end marker. */
	private static function octetParams(string $name, $buf, int $len, &$hold) {
		$f = self::$ffi;
		$params = $f->new('OSSL_PARAM[2]');
		// The name string must outlive the call: the caller holds it in $hold.
		$key = $f->new('char[' . (strlen($name) + 1) . ']');
		FFI::memcpy($key, $name . "\0", strlen($name) + 1);
		// An array CData does not decay when cast: take its first element's address.
		$params[0]->key = $f->cast('const char *', FFI::addr($key[0]));
		$params[0]->data_type = self::OSSL_PARAM_OCTET_STRING;
		$params[0]->data = $f->cast('void *', FFI::addr($buf[0]));
		$params[0]->data_size = $len;
		$params[0]->return_size = 0;
		$params[1]->key = null;
		$params[1]->data_type = 0;
		$params[1]->data = null;
		$params[1]->data_size = 0;
		$params[1]->return_size = 0;
		$hold = $key;
		return $params;
	}

	private static function buf(string $bytes) {
		$n = max(1, strlen($bytes));
		$b = self::$ffi->new('unsigned char[' . $n . ']');
		if ($bytes !== '') {
			FFI::memcpy($b, $bytes, strlen($bytes));
		}
		return $b;
	}

	private static function fail(string $what): void {
		$f = self::$ffi;
		$detail = '';
		$code = (int)$f->ERR_peek_last_error();
		if ($code !== 0) {
			$s = $f->new('char[256]');
			$f->ERR_error_string_n($code, $s, 256);
			$detail = ' (' . FFI::string($s) . ')';
		}
		$f->ERR_clear_error();
		throw new RuntimeException('MlKem768: ' . $what . $detail . '.');
	}
}
