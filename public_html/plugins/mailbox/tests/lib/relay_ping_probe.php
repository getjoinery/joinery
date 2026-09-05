<?php
/**
 * RelayPingProbe - run the REAL relay listener for a test and ask it things.
 *
 * The relay answers its health only through `GET /relay/ping` on its own
 * signed HTTPS API (specs/relay_without_a_shell.md), so a test that wants to
 * know what a relay says has to start the binary, register a tenant key, sign
 * a request the way the plane will, and pin the relay's identity. This helper
 * does exactly that against a temporary relay home on a loopback port. Nothing
 * here touches the machine: no root, no systemd, no Postfix.
 *
 * The binary is the prebuilt one the release ships
 * (provisioning/bin/relay-sealer-<uname -m>) when it is present, otherwise a
 * fresh `go build` of the source into the system temp directory. A box with
 * neither skips: it cannot check this, and saying so beats a green run that
 * verified nothing.
 *
 * @version 1.1 - spool, registry and applier helpers for the plane-side consumer tests
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayProtocol.php'));

class RelayPingProbe {

	/** @var string */
	public $home;
	/** @var string */
	public $spool;
	/** @var string */
	private $postfix;
	/** @var int */
	private $port = 0;
	/** @var resource|null */
	private $proc = null;
	/** @var resource|null the applier loop */
	private $applier = null;
	/** @var string curl pin, sha256//... */
	private $pin = '';
	/** @var array slug => secret key (raw) */
	private $keys = array();
	/** @var string */
	private $binary;

	/** The relay-sealer binary to run, or null when this box cannot provide one. */
	public static function binary(): ?string {
		$machine = trim((string)php_uname('m'));
		$prebuilt = PathHelper::getIncludePath('plugins/mailbox/provisioning/bin/relay-sealer-' . $machine);
		$src = PathHelper::getIncludePath('plugins/mailbox/provisioning/relay-sealer');
		// The shipped binary, but only when it was built from THIS source: a
		// stale bin/ would make the test pass against yesterday's relay.
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySealerPublisher.php'));
		if (is_executable($prebuilt)
				&& RelaySealerPublisher::readStamp(dirname($prebuilt)) === RelaySealerPublisher::sourceHash($src)) {
			return $prebuilt;
		}
		$go = trim((string)shell_exec('command -v go 2>/dev/null'));
		if ($go === '') {
			return null;
		}
		$out = sys_get_temp_dir() . '/joinery-relay-sealer-test-' . getmyuid() . '/relay-sealer';
		// Rebuilt when any source is newer than the binary, so a stale build can
		// never make a test pass against yesterday's relay.
		$stale = !is_file($out);
		if (!$stale) {
			foreach (glob($src . '/*.go') ?: array() as $f) {
				if (filemtime($f) > filemtime($out)) { $stale = true; break; }
			}
		}
		if ($stale) {
			if (!is_dir(dirname($out))) { mkdir(dirname($out), 0700, true); }
			exec(sprintf('cd %s && CGO_ENABLED=0 %s build -buildvcs=false -o %s . 2>&1',
				escapeshellarg($src), escapeshellarg($go), escapeshellarg($out)), $lines, $code);
			if ($code !== 0) {
				return null;
			}
		}
		return is_executable($out) ? $out : null;
	}

	public function __construct(string $binary) {
		$this->binary = $binary;
		$root = sys_get_temp_dir() . '/joinery-relay-probe-' . bin2hex(random_bytes(6));
		$this->home = $root . '/opt';
		$this->spool = $root . '/spool';
		$this->postfix = $root . '/postfix';
		foreach (array($this->home . '/tenants', $this->home . '/requests', $this->home . '/verdicts',
				$this->home . '/status', $this->spool, $this->postfix) as $d) {
			mkdir($d, 0700, true);
		}
		file_put_contents($this->home . '/version', '3.0');
	}

	/** The environment every relay-sealer verb runs under here. */
	private function env(): string {
		return 'JOINERY_RELAY_HOME=' . escapeshellarg($this->home)
			. ' JOINERY_RELAY_SPOOL_ROOT=' . escapeshellarg($this->spool)
			. ' JOINERY_RELAY_POSTFIX_DIR=' . escapeshellarg($this->postfix)
			. ' JOINERY_RELAY_MERGE_NO_RELOAD=1'
			. ' JOINERY_RELAY_REQUEST_UID=' . (int)getmyuid();
	}

	/** Register a tenant with a fresh keypair, exactly as the build registers `main`. */
	public function addTenant(string $slug, string $domains = '*'): bool {
		$pair = sodium_crypto_sign_keypair();
		$this->keys[$slug] = sodium_crypto_sign_secretkey($pair);
		$public = base64_encode(sodium_crypto_sign_publickey($pair));
		exec($this->env() . ' ' . escapeshellarg($this->binary) . ' tenant-add --slug ' . escapeshellarg($slug)
			. ' --public-key ' . escapeshellarg($public) . ' --domains ' . escapeshellarg($domains) . ' 2>&1', $out, $code);
		return $code === 0;
	}

	/** Remove the whole tenant registry, as an unreadable or wiped registry would leave it. */
	public function dropRegistry(): void {
		self::rmTree($this->home . '/tenants');
	}

	/** The loopback port the listener answers on (0 before start()). */
	public function port(): int { return $this->port; }

	/** The base URL a RelayClient override should point at. */
	public function baseUrl(): string { return 'https://127.0.0.1:' . $this->port; }

	/** The identity fingerprint, base64 (what a birth report carries), '' before start(). */
	public function fingerprint(): string {
		return $this->pin === '' ? '' : substr($this->pin, strlen('sha256//'));
	}

	/** The identity's raw public key, standard base64, from the certificate the listener presents. */
	public function identityPublicKey(): string {
		$pem = (string)@file_get_contents($this->home . '/identity/identity.crt');
		$cert = @openssl_x509_read($pem);
		if ($cert === false) { return ''; }
		$key = openssl_pkey_get_public($cert);
		$details = $key ? openssl_pkey_get_details($key) : false;
		// An Ed25519 SPKI is a fixed 12-byte prefix plus the 32-byte key.
		$spki = $details && isset($details['key']) ? (string)$details['key'] : '';
		if ($spki === '') { return ''; }
		$der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $spki), true);
		return ($der !== false && strlen($der) === 44) ? base64_encode(substr($der, 12)) : '';
	}

	/** Register a tenant with a public key the CALLER holds (the plane's own identity). */
	public function addTenantWithKey(string $slug, string $public_key_b64, string $domains = '*'): bool {
		exec($this->env() . ' ' . escapeshellarg($this->binary) . ' tenant-add --slug ' . escapeshellarg($slug)
			. ' --public-key ' . escapeshellarg($public_key_b64) . ' --domains ' . escapeshellarg($domains) . ' 2>&1', $out, $code);
		return $code === 0;
	}

	/** The operator key the shard's tenant routes answer to. */
	public function setOperatorKey(string $public_key_b64): void {
		file_put_contents($this->home . '/operator_public_key', $public_key_b64 . "\n");
	}

	/** Write one spool entry for a tenant, as the sealer pipe would commit it. */
	public function spoolWrite(string $slug, string $id, string $kind, string $content): void {
		$dir = $this->spool . '/' . $slug;
		if (!is_dir($dir)) { mkdir($dir, 0700, true); }
		file_put_contents($dir . '/' . $id . '.' . $kind, $content);
	}

	/** The spool entries (ids) a tenant still holds, by artifact. */
	public function spoolIds(string $slug): array {
		$ids = array();
		foreach (glob($this->spool . '/' . $slug . '/*.{seal,direct}', GLOB_BRACE) ?: array() as $f) {
			$ids[] = preg_replace('/\.(seal|direct)$/', '', basename($f));
		}
		sort($ids);
		return $ids;
	}

	/** The tenant's last ACCEPTED fragment on the relay, decoded, or null. */
	public function acceptedFragment(string $slug): ?array {
		$path = $this->home . '/tenants/' . $slug . '/fragment.accepted.json';
		if (!is_file($path)) { return null; }
		$raw = file_get_contents($path);
		$decoded = $raw === false ? null : json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	/** Does the registry hold a tenant? */
	public function hasTenant(string $slug): bool {
		return is_dir($this->home . '/tenants/' . $slug);
	}

	/**
	 * Run root's applier once, as the path unit would. start() also runs it in
	 * the background; this is for a test that wants a deterministic pass.
	 */
	public function applyOnce(): void {
		exec($this->env() . ' ' . escapeshellarg($this->binary) . ' apply-requests 2>&1');
	}

	/**
	 * A birth report signed by THIS relay's identity key, as the first-boot
	 * script would post it: the binary's own birth-report verb, so the test never
	 * holds the relay's key. Decoded {report, signature}, or null.
	 */
	public function birthReport(string $run_id, string $public_ip): ?array {
		$out = $this->home . '/birth_report.json';
		exec($this->env() . ' ' . escapeshellarg($this->binary) . ' birth-report --home ' . escapeshellarg($this->home)
			. ' --run-id ' . escapeshellarg($run_id) . ' --public-ip ' . escapeshellarg($public_ip)
			. ' --out ' . escapeshellarg($out) . ' 2>&1', $lines, $code);
		if ($code !== 0) { return null; }
		$decoded = json_decode((string)@file_get_contents($out), true);
		return is_array($decoded) ? $decoded : null;
	}

	/** Write a collector status file, as root's timer would. */
	public function writePrivileged(array $status): void {
		file_put_contents($this->home . '/status/privileged.json', json_encode($status));
	}

	/** Start relay-serve on a free loopback port and wait for it to answer. */
	public function start(): bool {
		$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		if (!$sock) { return false; }
		$this->port = (int)substr(stream_socket_get_name($sock, false), strrpos(stream_socket_get_name($sock, false), ':') + 1);
		fclose($sock);

		exec($this->env() . ' ' . escapeshellarg($this->binary) . ' identity-init --home ' . escapeshellarg($this->home) . ' 2>&1', $id_out, $code);
		if ($code !== 0) { return false; }
		foreach ($id_out as $line) {
			if (strpos($line, 'IDENTITY_FINGERPRINT=') === 0) {
				$this->pin = RelayProtocol::curlPin(substr($line, strlen('IDENTITY_FINGERPRINT=')));
			}
		}
		if ($this->pin === '') { return false; }

		$cmd = $this->env() . ' exec ' . escapeshellarg($this->binary) . ' relay-serve --hostname mx.probe.test'
			. ' --home ' . escapeshellarg($this->home) . ' --spool ' . escapeshellarg($this->spool)
			. ' --listen 127.0.0.1:' . $this->port;
		$this->proc = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('file', $this->home . '/serve.log', 'a'), 2 => array('file', $this->home . '/serve.log', 'a')), $pipes);
		if (!is_resource($this->proc)) { return false; }
		fclose($pipes[0]);
		// The path unit, in miniature: react to the drop directory every 100 ms.
		$loop = $this->env() . ' exec bash -c ' . escapeshellarg('while true; do ' . escapeshellarg($this->binary)
			. ' apply-requests >/dev/null 2>&1; sleep 0.1; done');
		$this->applier = proc_open($loop, array(0 => array('pipe', 'r'), 1 => array('file', '/dev/null', 'a'), 2 => array('file', '/dev/null', 'a')), $apipes);
		if (is_resource($this->applier)) { fclose($apipes[0]); }
		for ($i = 0; $i < 50; $i++) {
			// The harness treats a warning as a failed test, and a refused connect
			// while the listener is still binding is exactly one of those - so the
			// probe is silenced for this one call.
			set_error_handler(function () { return true; });
			try {
				$c = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 0.2);
			} finally {
				restore_error_handler();
			}
			if ($c) { fclose($c); return true; }
			usleep(100000);
		}
		return false;
	}

	/**
	 * One signed request. Returns [http_code, body, curl_errno]. A slug with no
	 * key registered here is signed with a throwaway key, so the relay refuses it.
	 */
	public function request(string $method, string $uri, string $body = '', string $slug = 'main', ?string $pin = null): array {
		$env = RelayProtocol::envelope($slug, $method, $uri, $body);
		$secret = $this->keys[$slug] ?? sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
		$sig = base64_encode(sodium_crypto_sign_detached(RelayProtocol::requestSigningBytes($env), $secret));
		$ch = curl_init('https://127.0.0.1:' . $this->port . $uri);
		curl_setopt_array($ch, array(
			CURLOPT_CUSTOMREQUEST   => $method,
			CURLOPT_RETURNTRANSFER  => true,
			// The pin IS the verification: the plane connects by IP with no server name.
			CURLOPT_SSL_VERIFYPEER  => false,
			CURLOPT_SSL_VERIFYHOST  => 0,
			CURLOPT_PINNEDPUBLICKEY => $pin ?? $this->pin,
			CURLOPT_HTTPHEADER      => array(RelayProtocol::AUTH_HEADER . ': ' . RelayProtocol::authHeaderValue($env, $sig)),
			CURLOPT_POSTFIELDS      => $body,
			CURLOPT_TIMEOUT         => 20,
		));
		$out = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$errno = curl_errno($ch);
		curl_close($ch);
		return array($code, (string)$out, $errno);
	}

	/** The raw ping answer for a tenant, and its HTTP code. */
	public function pingRaw(string $slug = 'main'): array {
		list($code, $body) = $this->request('GET', '/relay/ping', '', $slug);
		return array($code, $body);
	}

	/** The decoded ping, or null when the relay refused or answered non-JSON. */
	public function ping(string $slug = 'main'): ?array {
		list($code, $body) = $this->pingRaw($slug);
		if ($code !== 200) { return null; }
		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : null;
	}

	public function stop(): void {
		if (is_resource($this->applier)) {
			// bash -c ... exec'd nothing: kill the loop's process group members.
			$st = proc_get_status($this->applier);
			if (!empty($st['pid'])) { exec('pkill -TERM -P ' . intval($st['pid']) . ' 2>/dev/null'); }
			proc_terminate($this->applier, 9);
			proc_close($this->applier);
			$this->applier = null;
		}
		if (is_resource($this->proc)) {
			proc_terminate($this->proc, 15);
			for ($i = 0; $i < 30; $i++) {
				$st = proc_get_status($this->proc);
				if (!$st['running']) { break; }
				usleep(100000);
			}
			$st = proc_get_status($this->proc);
			if ($st['running']) { proc_terminate($this->proc, 9); }
			proc_close($this->proc);
			$this->proc = null;
		}
		self::rmTree(dirname($this->home));
	}

	public static function rmTree(string $dir): void {
		if (!is_dir($dir)) { return; }
		foreach (array_diff(scandir($dir) ?: array(), array('.', '..')) as $entry) {
			$path = $dir . '/' . $entry;
			is_dir($path) && !is_link($path) ? self::rmTree($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}
?>
