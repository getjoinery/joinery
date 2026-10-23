<?php
/**
 * WopiProof — check that a WOPI request was signed by this server's editor.
 *
 * The editor signs every request it makes with the private half of its proof
 * key; discovery publishes the public half as an RSA modulus and exponent. The
 * signed bytes are, each prefixed with its length as a 4-byte big-endian
 * integer: the access token, the full request URL in upper case, and the
 * X-WOPI-TimeStamp value as an 8-byte big-endian integer (.NET ticks).
 *
 * A request passes when X-WOPI-Proof verifies under the current key, or
 * X-WOPI-ProofOld under the current key, or X-WOPI-Proof under the old key —
 * the WOPI rotation rule — and its timestamp is under 20 minutes old.
 *
 * @version 0.1.0
 */
class WopiProof {

	/** Ticks between 0001-01-01 and the Unix epoch. */
	const EPOCH_TICKS = 621355968000000000;

	/** How old a signed request may be. */
	const MAX_AGE_SECONDS = 1200;

	/**
	 * @param array  $key     {modulus, exponent[, oldmodulus, oldexponent]} as discovery gives them
	 * @param string $token   the access token as the request carried it
	 * @param string $url     the full URL the editor requested
	 * @param string $ticks   X-WOPI-TimeStamp
	 * @param string $proof   X-WOPI-Proof
	 * @param string $old     X-WOPI-ProofOld
	 */
	public static function verify(array $key, $token, $url, $ticks, $proof, $old, $now = null) {
		if (!ctype_digit((string)$ticks) || (string)$proof === '') {
			return false;
		}
		$now = ($now === null) ? time() : (int)$now;
		$when = intdiv((int)$ticks - self::EPOCH_TICKS, 10000000);
		if (abs($now - $when) > self::MAX_AGE_SECONDS) {
			return false;
		}
		$data = self::signedBytes($token, $url, (int)$ticks);
		$current = self::pem($key['modulus'] ?? '', $key['exponent'] ?? '');
		$previous = (!empty($key['oldmodulus']) && !empty($key['oldexponent']))
			? self::pem($key['oldmodulus'], $key['oldexponent']) : null;

		if ($current !== null && self::check($data, $proof, $current)) {
			return true;
		}
		if ($current !== null && (string)$old !== '' && self::check($data, $old, $current)) {
			return true;
		}
		if ($previous !== null && self::check($data, $proof, $previous)) {
			return true;
		}
		return false;
	}

	/** The bytes the editor signs. */
	public static function signedBytes($token, $url, $ticks) {
		$token = (string)$token;
		$upper = strtoupper((string)$url);
		return pack('N', strlen($token)) . $token
			. pack('N', strlen($upper)) . $upper
			. pack('N', 8) . pack('J', (int)$ticks);
	}

	/** Ticks (100 ns since 0001-01-01) for a Unix time. */
	public static function ticksFor($unix) {
		return self::EPOCH_TICKS + (int)$unix * 10000000;
	}

	private static function check($data, $sig_b64, $pem) {
		$sig = base64_decode((string)$sig_b64, true);
		if ($sig === false || $sig === '') {
			return false;
		}
		return openssl_verify($data, $sig, $pem, OPENSSL_ALGO_SHA256) === 1;
	}

	/** A PEM public key from a base64 big-endian modulus and exponent. */
	public static function pem($modulus_b64, $exponent_b64) {
		$n = base64_decode((string)$modulus_b64, true);
		$e = base64_decode((string)$exponent_b64, true);
		if ($n === false || $e === false || $n === '' || $e === '') {
			return null;
		}
		$rsa = self::der(0x30, self::derInt($n) . self::derInt($e));
		$alg = self::der(0x30, hex2bin('06092a864886f70d0101010500'));
		$spki = self::der(0x30, $alg . self::der(0x03, "\x00" . $rsa));
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	private static function derInt($bytes) {
		$bytes = ltrim($bytes, "\x00");
		if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
			$bytes = "\x00" . $bytes;
		}
		return self::der(0x02, $bytes);
	}

	private static function der($tag, $body) {
		$len = strlen($body);
		if ($len < 0x80) {
			$l = chr($len);
		} else {
			$b = ltrim(pack('N', $len), "\x00");
			$l = chr(0x80 | strlen($b)) . $b;
		}
		return chr($tag) . $l . $body;
	}
}
