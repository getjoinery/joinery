<?php
/**
 * TransparencyProof - the offline checks that a release statement is in the
 * public log (spec release_transparency, D4 and D5).
 *
 * A release statement is a DSSE envelope signed with a P-256 statement key.
 * The publisher writes it to Sigstore's Rekor v2 log as a `hashedrekord`
 * entry whose digest is sha256 of the envelope's pre-authentication encoding
 * (PAE) and whose signature is the envelope's own signature. The log answers
 * with the entry's leaf bytes, an inclusion proof and a signed checkpoint.
 * This class checks that answer, with nothing but bytes and keys handed to it:
 *
 *  1. the envelope's signature verifies against a statement key we hold;
 *  2. the leaf is bound to THIS envelope: its digest is sha256(PAE), its
 *     signature is the envelope's signature, its key is the key that verified
 *     it. Without this, a logged statement's proof would vouch for an unlogged
 *     one;
 *  3. the checkpoint is a C2SP signed note from a log origin whose Ed25519
 *     checkpoint key we hold;
 *  4. the RFC 6962 inclusion proof walks the leaf to the checkpoint's root.
 *
 * It reads no file and makes no network call: the publisher (ReleaseLogClient)
 * runs it on what the log just returned, and a node runs it on what an
 * archive carries, against the keys in its own config/. Every failure throws
 * TransparencyProofException with a sentence that says which check failed.
 *
 * Keys are passed as raw PKIX DER (SubjectPublicKeyInfo) bytes - the form
 * Sigstore's trusted root publishes and release_keys/ stores in base64.
 *
 * An entry, as the publisher stores it beside the envelope:
 *
 *   log_origin        the checkpoint origin, e.g. log2025-1.rekor.sigstore.dev
 *   log_index         the entry's index in the log
 *   leaf              base64 of the leaf bytes (Rekor's canonical JSON body)
 *   inclusion_proof   {tree_size, root_hash (base64), hashes (base64[])}
 *   checkpoint        the signed note, verbatim
 *
 * @version 1.0
 */

class TransparencyProofException extends Exception {}

class TransparencyProof {

	/** The DSSE payload type of a release statement. */
	const PAYLOAD_TYPE = 'application/vnd.joinery.release-statement+json';

	/** The only log entry form Rekor v2 accepts, and the key form it carries. */
	const LEAF_KIND          = 'hashedrekord';
	const LEAF_API_VERSION   = '0.0.2';
	const LEAF_DIGEST_ALG    = 'SHA2_256';
	const STATEMENT_KEY_TYPE = 'PKIX_ECDSA_P256_SHA_256';

	/** SubjectPublicKeyInfo prefix of an Ed25519 key: the 32 raw bytes follow. */
	const ED25519_SPKI_PREFIX = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";

	/**
	 * DSSE pre-authentication encoding: what the statement key signs, and what
	 * the log entry's digest is taken over. The payload type is bound into it,
	 * so a signature over one kind of document never reads as another.
	 */
	public static function pae($payload_type, $payload) {
		return 'DSSEv1 ' . strlen($payload_type) . ' ' . $payload_type . ' ' . strlen($payload) . ' ' . $payload;
	}

	/** PEM for a DER SubjectPublicKeyInfo, which is what openssl_verify() takes. */
	public static function spkiPem($der) {
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	/** The DSSE key id we give a statement key: hex sha256 of its DER. */
	public static function keyId($spki_der) {
		return hash('sha256', $spki_der);
	}

	/** The 32 raw bytes of an Ed25519 SubjectPublicKeyInfo, or an exception. */
	public static function ed25519Raw($spki_der) {
		$prefix = self::ED25519_SPKI_PREFIX;
		if (strlen($spki_der) !== strlen($prefix) + 32 || strncmp($spki_der, $prefix, strlen($prefix)) !== 0) {
			throw new TransparencyProofException('the checkpoint key is not an Ed25519 public key');
		}
		return substr($spki_der, strlen($prefix));
	}

	/** True when the DER is a P-256 public key, the only statement key form the log accepts. */
	public static function isP256($spki_der) {
		$key = @openssl_pkey_get_public(self::spkiPem($spki_der));
		if ($key === false) { return false; }
		$details = openssl_pkey_get_details($key);
		return ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') === 'prime256v1';
	}

	/**
	 * Check 1: the envelope carries exactly one signature and it verifies,
	 * over the PAE, against one of $statement_keys. Returns that key's DER.
	 *
	 * @param array    $envelope        {payloadType, payload (base64), signatures: [{keyid, sig (base64)}]}
	 * @param string[] $statement_keys  P-256 SubjectPublicKeyInfo DER
	 */
	public static function verifyEnvelope(array $envelope, array $statement_keys) {
		$type    = $envelope['payloadType'] ?? null;
		$payload = isset($envelope['payload']) ? base64_decode((string)$envelope['payload'], true) : false;
		$sigs    = $envelope['signatures'] ?? null;
		if (!is_string($type) || $payload === false || !is_array($sigs)) {
			throw new TransparencyProofException('the statement envelope is not the DSSE form we write');
		}
		if ($type !== self::PAYLOAD_TYPE) {
			throw new TransparencyProofException("the statement envelope's payload type is {$type}, not a release statement");
		}
		if (count($sigs) !== 1 || !isset($sigs[0]['sig'])) {
			throw new TransparencyProofException('the statement envelope must carry exactly one signature');
		}
		$sig = base64_decode((string)$sigs[0]['sig'], true);
		if ($sig === false || $sig === '') {
			throw new TransparencyProofException('the statement signature is not base64');
		}
		$pae = self::pae($type, $payload);
		foreach ($statement_keys as $der) {
			$key = @openssl_pkey_get_public(self::spkiPem($der));
			if ($key !== false && openssl_verify($pae, $sig, $key, OPENSSL_ALGO_SHA256) === 1) {
				return $der;
			}
		}
		throw new TransparencyProofException('the statement is not signed by a statement key this machine trusts');
	}

	/**
	 * Check 2: the leaf bytes are a hashedrekord entry for exactly this
	 * envelope, signed by $signer_der. The bytes must also be the canonical
	 * form of what they decode to, so the fields read here are the bytes that
	 * get hashed into the tree and nothing a parser could read two ways.
	 */
	public static function verifyLeafBinding($leaf_bytes, array $envelope, $signer_der) {
		$leaf = json_decode($leaf_bytes, true);
		if (!is_array($leaf) || self::canonicalJson($leaf) !== $leaf_bytes) {
			throw new TransparencyProofException('the log entry is not canonical JSON');
		}
		$hr = $leaf['spec']['hashedRekordV002'] ?? null;
		if (($leaf['kind'] ?? null) !== self::LEAF_KIND || ($leaf['apiVersion'] ?? null) !== self::LEAF_API_VERSION || !is_array($hr)) {
			throw new TransparencyProofException('the log entry is not a ' . self::LEAF_KIND . ' ' . self::LEAF_API_VERSION . ' entry');
		}
		$payload = base64_decode((string)($envelope['payload'] ?? ''), true);
		$pae = self::pae((string)($envelope['payloadType'] ?? ''), (string)$payload);
		if (($hr['data']['algorithm'] ?? null) !== self::LEAF_DIGEST_ALG
			|| base64_decode((string)($hr['data']['digest'] ?? ''), true) !== hash('sha256', $pae, true)) {
			throw new TransparencyProofException('the log entry records a different statement than this one');
		}
		if (base64_decode((string)($hr['signature']['content'] ?? ''), true) !== base64_decode((string)($envelope['signatures'][0]['sig'] ?? ''), true)) {
			throw new TransparencyProofException('the log entry carries a different signature than this statement');
		}
		$verifier = $hr['signature']['verifier'] ?? array();
		if (($verifier['keyDetails'] ?? null) !== self::STATEMENT_KEY_TYPE
			|| base64_decode((string)($verifier['publicKey']['rawBytes'] ?? ''), true) !== $signer_der) {
			throw new TransparencyProofException('the log entry was written under a different key than the one that signed this statement');
		}
	}

	/**
	 * Check 3: a C2SP signed note from a log whose checkpoint key we hold.
	 * Returns {origin, tree_size, root_hash (raw)}. Other signers on the note
	 * (witnesses) are allowed and ignored.
	 *
	 * @param array $log_keys  origin => Ed25519 SubjectPublicKeyInfo DER
	 */
	public static function verifyCheckpoint($note, array $log_keys) {
		$split = is_string($note) ? strpos($note, "\n\n") : false;
		if ($split === false || substr($note, -1) !== "\n") {
			throw new TransparencyProofException('the checkpoint is not a signed note');
		}
		$body  = substr($note, 0, $split + 1);
		$lines = explode("\n", substr($body, 0, -1));
		if (count($lines) < 3 || !preg_match('/^(0|[1-9][0-9]{0,18})$/', $lines[1])) {
			throw new TransparencyProofException('the checkpoint body is not origin, size, root');
		}
		$origin = $lines[0];
		$root = base64_decode($lines[2], true);
		if ($root === false || strlen($root) !== 32) {
			throw new TransparencyProofException('the checkpoint root hash is malformed');
		}
		if (!isset($log_keys[$origin])) {
			throw new TransparencyProofException("the checkpoint is from {$origin}, a log this machine holds no key for");
		}
		$pk = self::ed25519Raw($log_keys[$origin]);
		$key_hash = substr(hash('sha256', $origin . "\n\x01" . $pk, true), 0, 4);
		foreach (explode("\n", rtrim(substr($note, $split + 2), "\n")) as $line) {
			if (!preg_match('/^\x{2014} (\S+) (\S+)$/u', $line, $m) || $m[1] !== $origin) { continue; }
			$raw = base64_decode($m[2], true);
			if ($raw === false || strlen($raw) !== 68 || substr($raw, 0, 4) !== $key_hash) { continue; }
			if (sodium_crypto_sign_verify_detached(substr($raw, 4), $body, $pk)) {
				return array('origin' => $origin, 'tree_size' => (int)$lines[1], 'root_hash' => $root);
			}
		}
		throw new TransparencyProofException("the checkpoint is not signed by {$origin}'s key");
	}

	/** RFC 6962 leaf hash. */
	public static function leafHash($leaf_bytes) {
		return hash('sha256', "\x00" . $leaf_bytes, true);
	}

	/** RFC 6962 interior node hash. */
	public static function nodeHash($left, $right) {
		return hash('sha256', "\x01" . $left . $right, true);
	}

	/**
	 * Check 4: RFC 9162 section 2.1.3.2 - the audit path from leaf $index in a
	 * tree of $tree_size leaves arrives at $root. All hashes raw.
	 */
	public static function inclusionHolds($leaf_hash, $index, $tree_size, array $path, $root) {
		if ($index < 0 || $index >= $tree_size) { return false; }
		$fn = $index;
		$sn = $tree_size - 1;
		$r  = $leaf_hash;
		foreach ($path as $p) {
			if ($sn === 0 || strlen($p) !== 32) { return false; }
			if (($fn & 1) || $fn === $sn) {
				$r = self::nodeHash($p, $r);
				while (!($fn & 1) && $fn !== 0) { $fn >>= 1; $sn >>= 1; }
			} else {
				$r = self::nodeHash($r, $p);
			}
			$fn >>= 1;
			$sn >>= 1;
		}
		return $sn === 0 && hash_equals($root, $r);
	}

	/**
	 * All four checks over one stored entry. Returns {origin, log_index,
	 * tree_size, statement_key} on success; throws on the first failure.
	 *
	 * @param array    $envelope        the DSSE envelope
	 * @param array    $entry           the stored entry (see the class comment)
	 * @param string[] $statement_keys  P-256 SubjectPublicKeyInfo DER
	 * @param array    $log_keys        origin => Ed25519 SubjectPublicKeyInfo DER
	 */
	public static function verifyEntry(array $envelope, array $entry, array $statement_keys, array $log_keys) {
		foreach (array('log_origin', 'log_index', 'leaf', 'inclusion_proof', 'checkpoint') as $field) {
			if (!isset($entry[$field])) {
				throw new TransparencyProofException("the log entry has no {$field}");
			}
		}
		$signer = self::verifyEnvelope($envelope, $statement_keys);

		$leaf = base64_decode((string)$entry['leaf'], true);
		if ($leaf === false || $leaf === '') {
			throw new TransparencyProofException('the log entry has no leaf bytes');
		}
		self::verifyLeafBinding($leaf, $envelope, $signer);

		$cp = self::verifyCheckpoint($entry['checkpoint'], $log_keys);
		if ($cp['origin'] !== $entry['log_origin']) {
			throw new TransparencyProofException("the checkpoint is from {$cp['origin']}, the entry says {$entry['log_origin']}");
		}

		$proof = $entry['inclusion_proof'];
		$path = array();
		foreach (is_array($proof['hashes'] ?? null) ? $proof['hashes'] : array() as $h) {
			$path[] = (string)base64_decode((string)$h, true);
		}
		$index = filter_var($entry['log_index'], FILTER_VALIDATE_INT);
		$size  = filter_var($proof['tree_size'] ?? null, FILTER_VALIDATE_INT);
		if ($index === false || $size === false || $size !== $cp['tree_size']
			|| base64_decode((string)($proof['root_hash'] ?? ''), true) !== $cp['root_hash']) {
			throw new TransparencyProofException('the inclusion proof is for a different tree than the checkpoint');
		}
		if (!self::inclusionHolds(self::leafHash($leaf), $index, $size, $path, $cp['root_hash'])) {
			throw new TransparencyProofException("the inclusion proof does not place this entry at index {$index} of {$cp['origin']}");
		}
		return array('origin' => $cp['origin'], 'log_index' => $index, 'tree_size' => $size, 'statement_key' => $signer);
	}

	/**
	 * RFC 8785 canonical JSON for the shapes a log entry takes: objects with
	 * sorted keys, arrays, ASCII strings, integers. Anything else is refused by
	 * returning null, which never equals a byte string.
	 *
	 * For the log leaf only. A decoded PHP array cannot say whether it was `[]`
	 * or `{}`, and this renders an empty one as `{}`; a hashedrekord leaf has no
	 * empty containers, a release statement may. The statement needs no
	 * canonical form anyway: the envelope carries its exact payload bytes.
	 *
	 * Strict on purpose, and safe to be: the publisher runs this check on the
	 * log's answer before anything ships, so a change in Rekor's encoding stops
	 * a publish, never a node.
	 */
	public static function canonicalJson($value) {
		if (is_array($value)) {
			if ($value !== array() && array_keys($value) === range(0, count($value) - 1)) {
				$parts = array();
				foreach ($value as $v) {
					$c = self::canonicalJson($v);
					if ($c === null) { return null; }
					$parts[] = $c;
				}
				return '[' . implode(',', $parts) . ']';
			}
			ksort($value, SORT_STRING);
			$parts = array();
			foreach ($value as $k => $v) {
				$c = self::canonicalJson($v);
				if ($c === null) { return null; }
				$parts[] = json_encode((string)$k, JSON_UNESCAPED_SLASHES) . ':' . $c;
			}
			return '{' . implode(',', $parts) . '}';
		}
		if (is_string($value)) {
			return preg_match('/^[\x20-\x7e]*$/', $value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : null;
		}
		if (is_int($value)) {
			return (string)$value;
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		return null;
	}
}
