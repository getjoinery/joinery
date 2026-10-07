<?php
/**
 * ReleaseLogClient - writes a release statement to Sigstore's public Rekor
 * log and hands back the proof that it is there (spec release_transparency,
 * WP1; D4, D5, O2).
 *
 * Runs on the publisher only. A node never talks to the log: it checks the
 * proof this class returns, offline, with TransparencyProof.
 *
 * WHICH LOG. Sigstore rotates Rekor v2 shards, so the shard is read, never
 * hardcoded: four plain HTTPS fetches from Sigstore's TUF CDN, each named by
 * the one before -
 *
 *   timestamp.json -> <n>.snapshot.json -> <n>.targets.json ->
 *   targets/<sha256>.signing_config_rekor_v2.v0.2.json and
 *   targets/<sha256>.trusted_root.json
 *
 * the last two checked against the sha256 and length the targets file names.
 * The TUF signature chain is not walked: the trust anchor is the checkpoint
 * key pinned in the repository (release_keys/log/<origin>.pub), and discovery
 * only says which shard is live. A shard whose key Sigstore's trusted root
 * gives differently from the pin is refused, as is a shard with no pin.
 *
 * TWO KEY SETS, TWO QUESTIONS. "Shipping" is release_keys/log/ in the tree
 * being published: what this release puts on nodes. "Held" is the log_keys of
 * the last release that carried a statement: what nodes have now. The live
 * shard's key must be in both, because nodes check this release against what
 * they already hold. A key pinned in the same commit as the first release
 * logged on its shard would pass a shipping-only check and be refused by every
 * node. When no release has ever carried a statement, held is null: the first
 * logged release is genesis, its keys are trusted on first install (D5), and
 * the result says so.
 *
 * SHARDS AHEAD. Sigstore lists a shard before its validity starts. Every
 * node must hold a shard's checkpoint key before the first release logged on
 * it, so discovery refuses while any listed future shard's key is not in the
 * shipping set - the window in which the old shard still takes writes and the
 * key can be added, committed and shipped by a release logged on the old one.
 *
 * HTTP. Plain curl, not SafeHttpClient: every destination is Sigstore's
 * fixed CDN host or a log URL read from a hash-checked Sigstore document, never
 * one chosen below the trusted-operator line.
 *
 * THE ENTRY. Rekor v2 accepts `hashedrekord` only (D-C): the digest is
 * sha256 of the DSSE envelope's PAE, the signature is the envelope's P-256
 * signature, the verifier is the statement key. log() returns the envelope and
 * the entry, and returns nothing unless the leaf, the inclusion proof and the
 * checkpoint are all present and all pass TransparencyProof::verifyEntry().
 * There is no "log later": a log that cannot be reached is an exception.
 *
 * @version 1.0
 */

class ReleaseLogException extends Exception {}

class ReleaseLogClient {

	const TUF_BASE = 'https://tuf-repo-cdn.sigstore.dev';

	const SIGNING_CONFIG_TARGET = 'signing_config_rekor_v2.v0.2.json';
	const SIGNING_CONFIG_TYPE   = 'application/vnd.dev.sigstore.signingconfig.v0.2+json';
	const TRUSTED_ROOT_TARGET   = 'trusted_root.json';

	/** The Rekor API this client speaks. Shards of any other major version are ignored. */
	const REKOR_API_MAJOR = 2;

	/** The repository's key directory, relative to the site root (the directory holding public_html). */
	const KEYS_DIR = 'maintenance_scripts/install_tools/release_keys';

	/** The publisher's P-256 statement key, PEM, 0600, under the site's config directory. */
	const STATEMENT_KEY_NAME = 'release_statement_key';

	/** @var array origin => Ed25519 DER: release_keys/log/ in the tree being published */
	private $shipping;

	/** @var array|null origin => Ed25519 DER: what nodes hold now; null before any release carried a statement */
	private $held;

	/** @var callable (string $method, string $url, ?string $body): array{status:int, body:string} */
	private $transport;

	/** @var int unix time discovery judges shard validity against */
	private $now;

	/**
	 * @param array         $shipping   origin => Ed25519 DER: self::repoLogKeys() of the tree being published
	 * @param array|null    $held       origin => Ed25519 DER: the log_keys of the last release that carried
	 *                                  a statement; null when none ever has (genesis)
	 * @param callable|null $transport  HTTP, injected by tests; curl by default
	 * @param int|null      $now        unix time, injected by tests
	 */
	public function __construct(array $shipping, $held, $transport = null, $now = null) {
		if ($held !== null && !is_array($held)) {
			throw new InvalidArgumentException('held log keys are an array, or null before any release carried a statement');
		}
		$this->shipping = $shipping;
		$this->held = $held;
		$this->transport = $transport ?: array(__CLASS__, 'curlTransport');
		$this->now = $now ?? time();
	}

	/**
	 * Discover the live shard, sign the statement and write it to the log.
	 *
	 * @return array{envelope:array, entry:array, shard:array}
	 */
	public function log($payload, $statement_key_pem) {
		$shard = $this->discover();
		$envelope = self::signEnvelope($payload, $statement_key_pem);
		$entry = $this->submit($shard, $envelope, self::publicDer($statement_key_pem));
		return array('envelope' => $envelope, 'entry' => $entry, 'shard' => $shard);
	}

	/**
	 * The four fetches of O2, then the shard checks. Returns the live shard:
	 * {url, origin, key (DER), ahead: origin[], genesis: bool}; throws
	 * ReleaseLogException on any refusal.
	 */
	public function discover() {
		$timestamp = $this->fetchJson(self::TUF_BASE . '/timestamp.json');
		$snap_v = $timestamp['signed']['meta']['snapshot.json']['version'] ?? null;
		if (!is_int($snap_v)) {
			throw new ReleaseLogException('Sigstore TUF timestamp.json names no snapshot version');
		}
		$snapshot = $this->fetchJson(self::TUF_BASE . "/{$snap_v}.snapshot.json");
		$targets_v = $snapshot['signed']['meta']['targets.json']['version'] ?? null;
		if (!is_int($targets_v)) {
			throw new ReleaseLogException("Sigstore TUF {$snap_v}.snapshot.json names no targets version");
		}
		$targets = $this->fetchJson(self::TUF_BASE . "/{$targets_v}.targets.json");
		$config = json_decode($this->fetchTarget($targets, self::SIGNING_CONFIG_TARGET), true);
		$root   = json_decode($this->fetchTarget($targets, self::TRUSTED_ROOT_TARGET), true);
		if (!is_array($config) || ($config['mediaType'] ?? null) !== self::SIGNING_CONFIG_TYPE) {
			throw new ReleaseLogException('Sigstore\'s signing config is not the ' . self::SIGNING_CONFIG_TYPE . ' format');
		}
		if (!is_array($root) || !is_array($root['tlogs'] ?? null)) {
			throw new ReleaseLogException('Sigstore\'s trusted root lists no logs');
		}
		return $this->chooseShard($config, $root);
	}

	/**
	 * The shard rules, on an already-fetched signing config and trusted root.
	 * Live: API v2, validity started and not ended; the newest if several.
	 * Ahead: API v2, validity not yet started. Every live and ahead shard's
	 * checkpoint key must be in Sigstore's trusted root, be Ed25519, and equal
	 * the pin in the shipping set; the live shard's must also be held (unless
	 * genesis). A validity time that does not parse is refused, start or end.
	 */
	public function chooseShard(array $config, array $root) {
		$live = null;
		$ahead = array();
		foreach (is_array($config['rekorTlogUrls'] ?? null) ? $config['rekorTlogUrls'] : array() as $svc) {
			if (($svc['majorApiVersion'] ?? null) !== self::REKOR_API_MAJOR || !is_string($svc['url'] ?? null)) { continue; }
			$start = self::time($svc['validFor']['start'] ?? null);
			$end   = isset($svc['validFor']['end']) ? self::time($svc['validFor']['end']) : null;
			if ($start === null || (isset($svc['validFor']['end']) && $end === null)) {
				throw new ReleaseLogException("Sigstore's signing config gives {$svc['url']} a validFor "
					. ($start === null ? 'start' : 'end') . ' that is not a time');
			}
			if ($start > $this->now) {
				$ahead[] = array('url' => $svc['url'], 'start' => $start);
			} elseif ($end === null || $end > $this->now) {
				if ($live === null || $start > $live['start']) {
					$live = array('url' => $svc['url'], 'start' => $start);
				}
			}
		}
		if ($live === null) {
			throw new ReleaseLogException('Sigstore\'s signing config names no Rekor v' . self::REKOR_API_MAJOR . ' log that is taking entries now');
		}
		foreach ($ahead as $next) {
			$key = $this->rootKey($root, $next['url'], $next['start']);
			if ($key === null) {
				throw new ReleaseLogException("Sigstore lists a future log {$next['url']}, but its trusted root does not yet publish that log's key; "
					. 'every node must hold the key before the log goes live, so publishing waits until Sigstore publishes it');
			}
			$this->assertShipping(self::originOf($next['url']), $key, "the next log, {$next['url']}, takes over later");
		}
		$origin = self::originOf($live['url']);
		$key = $this->rootKey($root, $live['url'], $this->now);
		if ($key === null) {
			throw new ReleaseLogException("Sigstore's trusted root does not list the live log {$live['url']}");
		}
		$this->assertShipping($origin, $key, "{$live['url']} is the live log");
		if ($this->held !== null) {
			$held = $this->held[$origin] ?? null;
			if ($held === null) {
				throw new ReleaseLogException("{$live['url']} is the live log and its key is pinned, but not yet shipped: nodes do not hold it, "
					. 'so every node would refuse a release logged there. Publish once on the old log first, so the key reaches nodes');
			}
			if (!hash_equals($held, $key)) {
				throw new ReleaseLogException("{$live['url']} is the live log, but nodes hold a different checkpoint key for {$origin} "
					. 'than Sigstore\'s trusted root gives. Nothing is logged until the difference is understood');
			}
		}
		return array('url' => rtrim($live['url'], '/'), 'origin' => $origin, 'key' => $key,
			'ahead' => array_map(function ($n) { return self::originOf($n['url']); }, $ahead),
			'genesis' => $this->held === null);
	}

	/**
	 * Write one envelope to the shard and verify what comes back.
	 *
	 * @param array  $shard          from discover()
	 * @param array  $envelope       from signEnvelope()
	 * @param string $statement_der  the statement key's public DER
	 * @return array the entry (see TransparencyProof)
	 */
	public function submit(array $shard, array $envelope, $statement_der) {
		if (!TransparencyProof::isP256($statement_der)) {
			throw new ReleaseLogException('the statement key is not a P-256 key, the only form the log accepts for this entry');
		}
		$payload = base64_decode((string)($envelope['payload'] ?? ''), true);
		$pae = TransparencyProof::pae((string)($envelope['payloadType'] ?? ''), (string)$payload);
		$request = array('hashedRekordRequestV002' => array(
			'digest'    => base64_encode(hash('sha256', $pae, true)),
			'signature' => array(
				'content'  => (string)($envelope['signatures'][0]['sig'] ?? ''),
				'verifier' => array(
					'publicKey'  => array('rawBytes' => base64_encode($statement_der)),
					'keyDetails' => TransparencyProof::STATEMENT_KEY_TYPE,
				),
			),
		));
		$url = $shard['url'] . '/api/v2/log/entries';
		$res = call_user_func($this->transport, 'POST', $url, json_encode($request));
		if ($res['status'] !== 201) {
			$err = json_decode($res['body'], true);
			$why = is_array($err) && isset($err['message']) ? $err['message'] : substr((string)$res['body'], 0, 200);
			throw new ReleaseLogException("the log {$shard['origin']} refused the statement (HTTP {$res['status']}): {$why}");
		}
		return $this->entryFromResponse($shard, $envelope, $statement_der, (string)$res['body']);
	}

	/**
	 * Turn the log's answer into the stored entry, refusing unless the leaf,
	 * the proof and the checkpoint are all there and all verify.
	 */
	public function entryFromResponse(array $shard, array $envelope, $statement_der, $body) {
		$tle = json_decode($body, true);
		if (!is_array($tle)) {
			throw new ReleaseLogException("the log {$shard['origin']} answered with something that is not JSON");
		}
		$proof = $tle['inclusionProof'] ?? null;
		$missing = array();
		if (!is_string($tle['canonicalizedBody'] ?? null) || $tle['canonicalizedBody'] === '') { $missing[] = 'the entry\'s leaf bytes'; }
		if (!is_array($proof) || !is_array($proof['hashes'] ?? null) || !isset($proof['treeSize'], $proof['rootHash'], $proof['logIndex'])) { $missing[] = 'an inclusion proof'; }
		if (!is_string($proof['checkpoint']['envelope'] ?? null)) { $missing[] = 'a checkpoint'; }
		if ($missing) {
			throw new ReleaseLogException("the log {$shard['origin']} accepted the statement but did not return " . implode(', ', $missing)
				. '; a release with no proof cannot be installed, so nothing is published');
		}
		if (($tle['kindVersion']['kind'] ?? null) !== TransparencyProof::LEAF_KIND || (string)($tle['logIndex'] ?? '') !== (string)$proof['logIndex']) {
			throw new ReleaseLogException("the log {$shard['origin']} answered with an entry that is not the one written");
		}
		$entry = array(
			'log_origin'      => $shard['origin'],
			'log_index'       => self::uint($proof['logIndex']),
			'leaf'            => $tle['canonicalizedBody'],
			'inclusion_proof' => array(
				'tree_size' => self::uint($proof['treeSize']),
				'root_hash' => (string)$proof['rootHash'],
				'hashes'    => array_values(array_map('strval', $proof['hashes'])),
			),
			'checkpoint'      => $proof['checkpoint']['envelope'],
		);
		try {
			TransparencyProof::verifyEntry($envelope, $entry, array($statement_der), array($shard['origin'] => $shard['key']));
		} catch (TransparencyProofException $e) {
			throw new ReleaseLogException("the log {$shard['origin']}'s answer does not prove the statement is in it: " . $e->getMessage());
		}
		return $entry;
	}

	/**
	 * A DSSE envelope over $payload, signed with the P-256 statement key.
	 *
	 * @return array{payloadType:string, payload:string, signatures:array}
	 */
	public static function signEnvelope($payload, $statement_key_pem) {
		$key = openssl_pkey_get_private($statement_key_pem);
		if ($key === false) {
			throw new ReleaseLogException('the statement key is not a readable private key');
		}
		$pae = TransparencyProof::pae(TransparencyProof::PAYLOAD_TYPE, $payload);
		if (!openssl_sign($pae, $sig, $key, OPENSSL_ALGO_SHA256)) {
			throw new ReleaseLogException('signing the statement failed: ' . openssl_error_string());
		}
		return array(
			'payloadType' => TransparencyProof::PAYLOAD_TYPE,
			'payload'     => base64_encode($payload),
			'signatures'  => array(array('keyid' => TransparencyProof::keyId(self::publicDer($statement_key_pem)), 'sig' => base64_encode($sig))),
		);
	}

	/** The public SubjectPublicKeyInfo DER of a private key PEM. */
	public static function publicDer($statement_key_pem) {
		$key = openssl_pkey_get_private($statement_key_pem);
		$details = $key !== false ? openssl_pkey_get_details($key) : false;
		if (!$details || !isset($details['key'])) {
			throw new ReleaseLogException('the statement key is not a readable private key');
		}
		return base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $details['key']));
	}

	/**
	 * Load the P-256 statement key, generating it on first use:
	 * {config_dir}/release_statement_key, PEM, 0600. The public half is what
	 * release_keys/statement/ must list (assertStatementKeyListed).
	 *
	 * @return string the private key PEM
	 */
	public static function ensureStatementKey($config_dir) {
		$path = rtrim($config_dir, '/') . '/' . self::STATEMENT_KEY_NAME;
		if (!file_exists($path)) {
			if (!is_dir($config_dir) || !is_writable($config_dir)) {
				throw new ReleaseLogException("config dir {$config_dir} is not writable; cannot create the release statement key");
			}
			$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
			if ($key === false || !openssl_pkey_export($key, $pem)) {
				throw new ReleaseLogException('generating the release statement key failed: ' . openssl_error_string());
			}
			// 0600 before the key material goes in, so it is never briefly readable.
			if (file_put_contents($path, '') === false || !chmod($path, 0600)) {
				@unlink($path);
				throw new ReleaseLogException("cannot create {$path} with mode 0600");
			}
			if (file_put_contents($path, $pem) === false) {
				throw new ReleaseLogException("cannot write {$path}");
			}
		}
		$pem = @file_get_contents($path);
		if ($pem === false || !TransparencyProof::isP256(self::publicDer($pem))) {
			throw new ReleaseLogException("the release statement key at {$path} is unreadable or not a P-256 key");
		}
		return $pem;
	}

	/**
	 * Refuse to log under a statement key the repository does not publish: a
	 * node would refuse the statement, and nobody reading the commit would
	 * see the key.
	 */
	public static function assertStatementKeyListed($full_site_dir, $statement_der) {
		if (!in_array($statement_der, self::repoStatementKeys($full_site_dir), true)) {
			throw new ReleaseLogException('this site\'s release statement key is not listed in ' . self::KEYS_DIR . '/statement/; '
				. 'add its public half there as one base64 line in a .pub file (' . base64_encode($statement_der) . ') and commit it');
		}
	}

	/** The P-256 statement keys under release_keys/statement/, as DER. */
	public static function repoStatementKeys($full_site_dir) {
		$keys = array();
		foreach (glob(rtrim($full_site_dir, '/') . '/' . self::KEYS_DIR . '/statement/*.pub') ?: array() as $file) {
			$der = base64_decode(trim((string)file_get_contents($file)), true);
			if ($der !== false && TransparencyProof::isP256($der)) {
				$keys[] = $der;
			}
		}
		return $keys;
	}

	/** The checkpoint keys under release_keys/log/, origin => DER; malformed files are skipped. */
	public static function repoLogKeys($full_site_dir) {
		$keys = array();
		foreach (glob(rtrim($full_site_dir, '/') . '/' . self::KEYS_DIR . '/log/*.pub') ?: array() as $file) {
			$der = base64_decode(trim((string)file_get_contents($file)), true);
			if ($der === false) { continue; }
			try {
				TransparencyProof::ed25519Raw($der);
			} catch (TransparencyProofException $e) {
				continue;
			}
			$keys[basename($file, '.pub')] = $der;
		}
		ksort($keys);
		return $keys;
	}

	/** A log's checkpoint origin: the host of its URL. */
	public static function originOf($url) {
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			throw new ReleaseLogException("a log URL has no host: {$url}");
		}
		return strtolower($host);
	}

	/** Default transport: curl, TLS verified. */
	public static function curlTransport($method, $url, $body = null) {
		$ch = curl_init($url);
		$opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER     => array('Accept: application/json'),
		);
		if ($method === 'POST') {
			$opts[CURLOPT_POST] = true;
			$opts[CURLOPT_POSTFIELDS] = $body;
			$opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
		}
		curl_setopt_array($ch, $opts);
		$out = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($out === false) {
			throw new ReleaseLogException("cannot reach {$url}: {$err}; a release is not published without its log entry");
		}
		return array('status' => $status, 'body' => (string)$out);
	}

	// ------------------------------------------------------------------

	private function fetch($url) {
		$res = call_user_func($this->transport, 'GET', $url, null);
		if ($res['status'] !== 200) {
			throw new ReleaseLogException("fetching {$url} answered HTTP {$res['status']}");
		}
		return (string)$res['body'];
	}

	private function fetchJson($url) {
		$doc = json_decode($this->fetch($url), true);
		if (!is_array($doc)) {
			throw new ReleaseLogException("{$url} is not JSON");
		}
		return $doc;
	}

	/** A TUF target by name, fetched at its hash-prefixed path and checked against the hash and length. */
	private function fetchTarget(array $targets, $name) {
		$meta = $targets['signed']['targets'][$name] ?? null;
		$sha = $meta['hashes']['sha256'] ?? null;
		if (!is_string($sha) || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
			throw new ReleaseLogException("Sigstore's TUF targets do not name {$name}");
		}
		$bytes = $this->fetch(self::TUF_BASE . "/targets/{$sha}.{$name}");
		if (!hash_equals($sha, hash('sha256', $bytes)) || (isset($meta['length']) && strlen($bytes) !== $meta['length'])) {
			throw new ReleaseLogException("Sigstore's {$name} does not match the hash its TUF targets file names");
		}
		return $bytes;
	}

	/**
	 * The Ed25519 checkpoint key Sigstore's trusted root gives for a log URL at
	 * time $at, as DER; null when the root lists none for that URL. A log may
	 * appear more than once when its key rotates in place; the entry whose
	 * validity covers $at is the one, and several at once is refused.
	 */
	private function rootKey(array $root, $url, $at) {
		$matched = 0;
		$valid = array();
		foreach ($root['tlogs'] as $tlog) {
			if (rtrim((string)($tlog['baseUrl'] ?? ''), '/') !== rtrim($url, '/')) { continue; }
			$matched++;
			$from  = self::time($tlog['publicKey']['validFor']['start'] ?? null);
			$until = isset($tlog['publicKey']['validFor']['end']) ? self::time($tlog['publicKey']['validFor']['end']) : null;
			if ($from === null || $from > $at || (isset($tlog['publicKey']['validFor']['end']) && ($until === null || $until <= $at))) { continue; }
			$valid[] = $tlog;
		}
		if ($matched === 0) { return null; }
		$when = gmdate('Y-m-d\TH:i:s\Z', $at);
		if (count($valid) !== 1) {
			throw new ReleaseLogException("Sigstore's trusted root lists {$matched} key(s) for {$url}, " . count($valid) . " of them valid at {$when}; exactly one must be");
		}
		if (($valid[0]['publicKey']['keyDetails'] ?? null) !== 'PKIX_ED25519') {
			throw new ReleaseLogException("Sigstore's checkpoint key for {$url} is not Ed25519, the only kind a node verifies");
		}
		$der = base64_decode((string)($valid[0]['publicKey']['rawBytes'] ?? ''), true);
		if ($der === false || $der === '') {
			throw new ReleaseLogException("Sigstore's checkpoint key for {$url} is not base64");
		}
		return $der;
	}

	/** Refuse unless the tree being published pins exactly this checkpoint key for this origin. */
	private function assertShipping($origin, $key, $why) {
		$pinned = $this->shipping[$origin] ?? null;
		$file = self::KEYS_DIR . "/log/{$origin}.pub";
		if ($pinned === null) {
			throw new ReleaseLogException("{$why}, and its checkpoint key is not pinned. Add {$file} containing "
				. base64_encode($key) . ' (Sigstore\'s trusted root), commit it, and publish; a release logged on a log '
				. 'whose key nodes lack would be refused by every node');
		}
		if (!hash_equals($pinned, $key)) {
			throw new ReleaseLogException("{$why}, but Sigstore's trusted root gives a different checkpoint key for {$origin} "
				. "than {$file} pins. Nothing is logged until the difference is understood");
		}
	}

	private static function time($rfc3339) {
		if (!is_string($rfc3339)) { return null; }
		$t = strtotime($rfc3339);
		return $t === false ? null : $t;
	}

	private static function uint($v) {
		if (is_int($v) && $v >= 0) { return $v; }
		if (is_string($v) && preg_match('/^(0|[1-9][0-9]{0,18})$/', $v)) { return (int)$v; }
		throw new ReleaseLogException('the log answered with an index or tree size that is not a number');
	}
}
