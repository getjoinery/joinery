<?php
/**
 * ReleaseStatementPublisher - the release statement: one signed, publicly
 * logged record of what a release is (spec release_transparency, D4, D5, WP3).
 *
 * WHAT IT SAYS. The statement names the public commits a release was built
 * from, the hash of every artifact's manifest, the raw hash of every binary,
 * the tools that compressed what is compressed, and every key the release
 * puts on a node. It is a DSSE envelope signed with the publisher's P-256
 * statement key and written to Sigstore's Rekor log through ReleaseLogClient,
 * which hands back the entry's leaf, inclusion proof and checkpoint.
 *
 * WHERE IT GOES. RELEASE_STATEMENT, a JSON file holding the envelope, the
 * entry and the key chain, goes into every artifact - public_html/ for the
 * core, the plugin's or theme's own directory, and agent_dist/ for the agent
 * - and is listed in that artifact's manifest like any other file. The
 * statement records each manifest without its RELEASE_STATEMENT line
 * (PackageSignature::statementSubject), which is what breaks the circle.
 *
 * THE KEY. config/release_statement_key, minted on the first logged publish
 * only (genesis). Its public half must be in release_keys/statement/ in the
 * tree being published; a publish writes the file there when it is missing,
 * and the commit check then asks for it to be committed. After genesis the
 * key must also be one the last logged release installed, because a node
 * checks a statement against keys it already holds: a lost key is restored
 * from the site backup, never re-minted.
 *
 * THE CHAIN. key_chain lists every earlier statement that introduced a key,
 * from genesis on, each with its own entry, so a node that skipped the
 * release carrying a key can walk forward to it from keys it holds (D5). It
 * sits beside the envelope, not inside the payload: each link is already
 * signed and logged, and a payload holding it would hold every earlier chain
 * inside every link.
 *
 * Runs on the publisher only, from publish_upgrade.php. A republishing site
 * never writes one: it carries what it received, as a listed file.
 *
 * @version 1.4 - the authority (specs/release_key_rotation.md R1, F1, F2): payload() records the keys allowed
 *                to change the trusted set; it joins the chain's comparison; walkChain() follows the
 *                set and refuses a chain whose set changes are not authorised; verifyDocument() checks it
 * @version 1.3 - binaryArtifacts() records every GoBinaryPublisher::PLUGIN_PUBLISHERS binary, not the relay sealer by name
 * @version 1.2 - verifyDocument() checks the entry against every key the release installs for its log (review B7a)
 * @version 1.1 - held() keeps every log key a release installed, origin => list (WP7 review B7)
 * @version 1.0
 */
class ReleaseStatementPublisher {

	const NAME = PackageSignature::STATEMENT_NAME;

	/** The RELEASE_STATEMENT file's own format version. */
	const FORMAT = 1;

	/**
	 * The newest logged release's RELEASE_STATEMENT, decoded; null when no
	 * release has ever been logged (genesis).
	 */
	public static function previous(): ?array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT upg_release_statement FROM upg_upgrades
			WHERE upg_release_statement IS NOT NULL AND upg_release_statement <> ''
			ORDER BY upg_upgrade_id DESC LIMIT 1");
		$q->execute();
		$raw = $q->fetchColumn();
		if ($raw === false) {
			return null;
		}
		$doc = json_decode((string)$raw, true);
		if (!is_array($doc) || !is_array($doc['envelope'] ?? null)) {
			throw new Exception('the newest logged release\'s statement in upg_upgrades is not readable, so what nodes hold cannot be known');
		}
		return $doc;
	}

	/**
	 * Release rows a logging publish must not build on: every row newer than
	 * the newest one holding a statement that holds none itself. Once any
	 * release is logged every later one is, so such a row is a publish that
	 * stopped between logging and recording its statement, possibly with
	 * archives nodes can install. Before genesis there is nothing to check.
	 *
	 * @return string[] the versions, oldest first
	 */
	public static function unfinished(): array {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT upg_major_version || '.' || upg_minor_version || '.' || upg_patch_version FROM upg_upgrades
			WHERE (upg_release_statement IS NULL OR upg_release_statement = '')
			  AND upg_upgrade_id > (SELECT MAX(upg_upgrade_id) FROM upg_upgrades WHERE upg_release_statement <> '')
			ORDER BY upg_upgrade_id");
		$q->execute();
		return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
	}

	/** A statement document's payload, decoded. Not verified: the caller already holds it as its own record. */
	public static function payloadOf(array $doc): array {
		$payload = json_decode((string)base64_decode((string)($doc['envelope']['payload'] ?? ''), true), true);
		if (!is_array($payload) || !is_array($payload['keys_installed'] ?? null)) {
			throw new Exception('a release statement\'s payload is not readable');
		}
		return $payload;
	}

	/**
	 * What nodes hold now, from the newest logged release: its log keys
	 * (origin => list of DER: a log rotating its key in place is held by both)
	 * and statement keys (DER). Both null at genesis.
	 *
	 * @return array{log:?array, statement:?array}
	 */
	public static function held(?array $previous): array {
		if ($previous === null) {
			return array('log' => null, 'statement' => null);
		}
		$keys = self::payloadOf($previous)['keys_installed'];
		$log = array();
		foreach ($keys['log_keys'] ?? array() as $pair) {
			$log[(string)($pair['origin'] ?? '')][] = (string)base64_decode((string)($pair['key'] ?? ''), true);
		}
		$statement = array();
		foreach ($keys['statement_keys'] ?? array() as $b64) {
			$statement[] = (string)base64_decode((string)$b64, true);
		}
		return array('log' => ReleaseLogClient::keySets($log), 'statement' => $statement);
	}

	/**
	 * The statement key this publish signs with: minted at genesis, read
	 * otherwise, and refused when nodes do not hold it. When the repository
	 * does not list its public half yet, the file is written for the owner to
	 * commit and 'listed' names it.
	 *
	 * @return array{pem:string, der:string, listed:?string}
	 */
	public static function statementKey(string $full_site_dir, ?array $previous): array {
		$config = rtrim($full_site_dir, '/') . '/config';
		$key_path = $config . '/' . ReleaseLogClient::STATEMENT_KEY_NAME;
		$held = self::held($previous)['statement'];
		if ($held !== null && !file_exists($key_path)) {
			throw new Exception("{$key_path} is missing, and nodes already hold a statement key ("
				. implode(', ', array_map(function ($der) { return substr(TransparencyProof::keyId($der), 0, 12); }, $held))
				. '). A new key would be refused by every node; restore the file from this site\'s backup');
		}
		$pem = ReleaseLogClient::ensureStatementKey($config);
		$der = ReleaseLogClient::publicDer($pem);
		if ($held !== null && !in_array($der, $held, true)) {
			throw new Exception('config/' . ReleaseLogClient::STATEMENT_KEY_NAME . ' (key ' . substr(TransparencyProof::keyId($der), 0, 12)
				. ') is not a statement key the last logged release installed, so every node would refuse what it signs. '
				. 'Restore the key nodes hold; to change keys, list the new one under release_keys/statement/ and publish '
				. 'once with the old key before switching');
		}
		$listed = null;
		if (!in_array($der, ReleaseLogClient::repoStatementKeys($full_site_dir), true)) {
			$listed = rtrim($full_site_dir, '/') . '/' . ReleaseLogClient::KEYS_DIR . '/statement/joinery-'
				. substr(TransparencyProof::keyId($der), 0, 8) . '.pub';
			if (!is_dir(dirname($listed)) && !mkdir(dirname($listed), 0755, true)) {
				throw new Exception('cannot create ' . dirname($listed));
			}
			if (file_put_contents($listed, base64_encode($der) . "\n") === false) {
				throw new Exception("cannot write {$listed}");
			}
			ReleaseLogClient::assertStatementKeyListed($full_site_dir, $der);
		}
		return array('pem' => $pem, 'der' => $der, 'listed' => $listed);
	}

	/**
	 * The hashes the statement records beside the manifests: each agent
	 * binary's raw sha256 as the bundle manifest records it, the support
	 * bundle, and each binary of a Go program a plugin ships prebuilt
	 * (GoBinaryPublisher::PLUGIN_PUBLISHERS: the relay sealer, the mail gateway).
	 *
	 * @return array<string,string> artifact name => sha256 hex
	 */
	public static function binaryArtifacts(string $full_site_dir): array {
		$site = rtrim($full_site_dir, '/');
		$out = array();
		$agent = AgentDistPublisher::readManifest($site . '/public_html/agent_dist');
		foreach (is_array($agent['binaries'] ?? null) ? $agent['binaries'] : array() as $platform => $bin) {
			if (preg_match('/^[0-9a-f]{64}$/', (string)($bin['sha256'] ?? ''))) {
				$out['agent/' . $platform] = $bin['sha256'];
			}
		}
		$bundle = $site . '/public_html/agent_dist/support_bundle.tar.gz';
		if (is_file($bundle)) {
			$out['support_bundle'] = hash_file('sha256', $bundle);
		}
		foreach (GoBinaryPublisher::pluginPublishers() as $publisher) {
			foreach (array_keys($publisher::ARCHES) as $machine) {
				$file = $site . '/' . $publisher::BIN_SUBDIR . '/' . $publisher::BINARY . '-' . $machine;
				if (is_file($file)) {
					$out[$publisher::artifactName($machine)] = hash_file('sha256', $file);
				}
			}
		}
		ksort($out);
		return $out;
	}

	/** The compressors whose output the release ships, by version (D2: a verifier compares content where bytes differ). */
	public static function compressors(): array {
		$first = function ($cmd) {
			$out = array();
			exec($cmd . ' 2>/dev/null', $out);
			return trim((string)($out[0] ?? '')) ?: 'unknown';
		};
		return array(
			'gzencode' => 'zlib ' . (defined('ZLIB_VERSION') ? ZLIB_VERSION : 'unknown'),
			'gzip'     => $first('gzip --version'),
			'tar'      => $first('tar --version'),
		);
	}

	/**
	 * The statement payload, as bytes. Not canonicalised: the envelope carries
	 * these exact bytes and a verifier never re-encodes them (D4).
	 *
	 * @param array $facts version, core_commit, agent_commit, go_toolchain, compressors,
	 *                     artifacts (name => sha256), keys_installed (AgentDistPublisher::repoKeyLists),
	 *                     and optionally authority (AgentDistPublisher::repoAuthority): the keys
	 *                     allowed to sign the next change to the set (specs/release_key_rotation.md R1).
	 *                     Every release from M on carries it; a statement without it has the whole
	 *                     set as its authority.
	 */
	public static function payload(array $facts, ?int $now = null): string {
		foreach (array('version', 'core_commit', 'agent_commit', 'go_toolchain', 'compressors', 'artifacts', 'keys_installed') as $field) {
			if (!array_key_exists($field, $facts) || $facts[$field] === null || $facts[$field] === '') {
				throw new Exception("a release statement needs {$field}");
			}
		}
		if (!preg_match('/^[0-9a-f]{40}$/', (string)$facts['core_commit']) || !preg_match('/^[0-9a-f]{40}$/', (string)$facts['agent_commit'])) {
			throw new Exception('a release statement names its commits by full hash');
		}
		$artifacts = $facts['artifacts'];
		ksort($artifacts);
		foreach ($artifacts as $name => $sha) {
			if (!preg_match('/^[0-9a-f]{64}$/', (string)$sha)) {
				throw new Exception("the statement's hash for {$name} is not a sha256");
			}
		}
		if (!isset($artifacts['core'])) {
			throw new Exception('a release statement must record the core manifest');
		}
		$keys = $facts['keys_installed'];
		$authority = null;
		if (isset($facts['authority'])) {
			foreach (array('release_keys', 'statement_keys') as $list) {
				$named = array_values((array)($facts['authority'][$list] ?? array()));
				if ($named === array() || array_diff($named, (array)($keys[$list] ?? array())) !== array()) {
					throw new Exception("a release statement's authority must name {$list} the release installs, at least one");
				}
				$authority[$list] = $named;
			}
		}
		$doc = array(
			'version'        => (string)$facts['version'],
			'core_commit'    => (string)$facts['core_commit'],
			'agent_commit'   => (string)$facts['agent_commit'],
			'go_toolchain'   => (string)$facts['go_toolchain'],
			'compressors'    => $facts['compressors'],
			'artifacts'      => $artifacts,
			'keys_installed' => array(
				'release_keys'   => array_values($keys['release_keys'] ?? array()),
				'statement_keys' => array_values($keys['statement_keys'] ?? array()),
				'log_keys'       => array_values($keys['log_keys'] ?? array()),
			),
		);
		if ($authority !== null) {
			$doc['authority'] = $authority;
		}
		$doc['published_at'] = gmdate('Y-m-d\TH:i:s\Z', $now ?? time());
		return json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	}

	/**
	 * The key chain the next statement carries: the previous release's chain,
	 * plus the previous release itself when it introduced a key the chain did
	 * not already hold. Genesis introduces every key it lists, so it heads the
	 * chain.
	 */
	public static function keyChain(?array $previous): array {
		if ($previous === null) {
			return array();
		}
		$chain = is_array($previous['key_chain'] ?? null) ? array_values($previous['key_chain']) : array();
		$before = $chain === array() ? array() : self::keySet(self::payloadOf(end($chain)));
		$now = self::keySet(self::payloadOf($previous));
		// Any difference: a key added, one dropped (a retirement), or the
		// authority changed. A node walks each such link (R1).
		if (array_diff($now, $before) !== array() || array_diff($before, $now) !== array()) {
			$chain[] = array('envelope' => $previous['envelope'], 'entry' => $previous['entry']);
		}
		return $chain;
	}

	/** Every key a payload installs, and its authority, one string each, for set comparison. */
	private static function keySet(array $payload): array {
		$keys = $payload['keys_installed'];
		$set = array();
		foreach (is_array($payload['authority'] ?? null) ? $payload['authority'] : array() as $list => $named) {
			foreach ((array)$named as $k) { $set[] = 'authority ' . $list . ' ' . $k; }
		}
		foreach ($keys['release_keys'] ?? array() as $k) { $set[] = 'release ' . $k; }
		foreach ($keys['statement_keys'] ?? array() as $k) { $set[] = 'statement ' . $k; }
		foreach ($keys['log_keys'] ?? array() as $p) { $set[] = 'log ' . ($p['origin'] ?? '') . ' ' . ($p['key'] ?? ''); }
		return $set;
	}

	/**
	 * Sign the payload and write it to the log, returning the
	 * RELEASE_STATEMENT bytes. ReleaseLogClient::submit() has already checked
	 * the log's answer against the shard's key; nothing here returns without
	 * the leaf, the proof and the checkpoint.
	 */
	public static function log(ReleaseLogClient $client, array $shard, string $payload, string $pem, ?array $previous): string {
		$envelope = ReleaseLogClient::signEnvelope($payload, $pem);
		$entry = $client->submitEntry($shard, $envelope, ReleaseLogClient::publicDer($pem));
		return json_encode(array(
			'format'    => self::FORMAT,
			'envelope'  => $envelope,
			'entry'     => $entry,
			'key_chain' => self::keyChain($previous),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	}

	/**
	 * The trusted set a payload installs (specs/release_key_rotation.md R1):
	 * {release: base64[], statement: DER[], authority: {release: base64[],
	 * statement: DER[]}}, the authority being the whole set when the payload
	 * names none (a release from before M).
	 */
	public static function trustOf(array $payload): array {
		$keys = $payload['keys_installed'];
		$release = array_values($keys['release_keys'] ?? array());
		$statement = array_map(function ($b64) { return (string)base64_decode((string)$b64, true); }, array_values($keys['statement_keys'] ?? array()));
		$authority = array('release' => $release, 'statement' => $statement);
		if (is_array($payload['authority'] ?? null)) {
			$authority = array(
				'release'   => array_values($payload['authority']['release_keys'] ?? array()),
				'statement' => array_map(function ($b64) { return (string)base64_decode((string)$b64, true); }, array_values($payload['authority']['statement_keys'] ?? array())),
			);
		}
		return array('release' => $release, 'statement' => $statement, 'authority' => $authority);
	}

	/** Whether two trustOf() sets hold the same keys and the same authority. */
	public static function sameTrust(array $a, array $b): bool {
		foreach (array(array($a['release'], $b['release']), array($a['statement'], $b['statement']),
				array($a['authority']['release'], $b['authority']['release']), array($a['authority']['statement'], $b['authority']['statement'])) as $pair) {
			sort($pair[0]);
			sort($pair[1]);
			if ($pair[0] !== $pair[1]) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Refuse, before anything is logged, a release that changes the trusted
	 * set without the authority to (R1): the set the newest logged release
	 * installed may be changed only by a release whose manifest is signed by
	 * one of its authority's release keys ($release_key, base64) and whose
	 * statement by one of its statement keys ($statement_der). Every node that
	 * holds that set would refuse anything else (`unauthorized_keys`). At
	 * genesis there is no set yet.
	 */
	public static function assertMayChangeSet(?array $previous, array $keys_installed, ?array $authority, string $release_key, string $statement_der): void {
		if ($previous === null) {
			return;
		}
		$was = self::trustOf(self::payloadOf($previous));
		$next = self::trustOf(array('keys_installed' => $keys_installed, 'authority' => $authority));
		if (self::sameTrust($was, $next)) {
			return;
		}
		if (!in_array($release_key, $was['authority']['release'], true) || !in_array($statement_der, $was['authority']['statement'], true)) {
			throw new Exception('this release changes the keys nodes trust (release_keys/ or release_keys/authority), but it would be signed by '
				. 'keys the last logged release does not allow to change them; nodes would refuse it. Sign it with the authority\'s keys '
				. '(specs/release_key_rotation.md R5), or put release_keys/ back as the last release had it');
		}
	}

	/**
	 * Walk a key chain as a node would (R1, R2): the first link (genesis)
	 * against its own keys, each later link against the set the links before
	 * it left, and a link that changes the set only with a statement signed by
	 * that set's authority, which here is refused rather than skipped: a chain
	 * this publisher ships must walk whole. Returns the set a node holding
	 * only the genesis keys ends with: {statement: DER[], log: origin => DER[],
	 * release: base64[], authority: {release, statement}}.
	 */
	public static function walkChain(array $chain): array {
		$held = null;
		$log = array();
		foreach (array_values($chain) as $i => $link) {
			$envelope = (array)($link['envelope'] ?? array());
			$entry = (array)($link['entry'] ?? array());
			$installed_log = self::held(array('envelope' => $envelope))['log'];
			$installed = self::trustOf(self::payloadOf(array('envelope' => $envelope)));
			$check_statement = $held === null ? $installed['statement'] : $held['statement'];
			$check_log = $held === null ? $installed_log : $log;
			try {
				TransparencyProof::verifyEntry($envelope, $entry, $check_statement, $check_log);
			} catch (TransparencyProofException $e) {
				throw new Exception('link ' . ($i + 1) . ' of the key chain does not verify against the keys before it: ' . $e->getMessage());
			}
			if ($held !== null && !self::sameTrust($held, $installed)) {
				try {
					TransparencyProof::verifyEntry($envelope, $entry, $held['authority']['statement'], $check_log);
				} catch (TransparencyProofException $e) {
					throw new Exception('link ' . ($i + 1) . ' of the key chain changes the trusted set, but is not signed by a statement key '
						. 'of the authority before it, so a node would not follow it');
				}
			}
			$held = $installed;
			// A log's key may rotate in place: a later link's key for an
			// origin is the one later statements are checked against.
			$log = $installed_log + $log;
		}
		$held = $held ?? array('release' => array(), 'statement' => array(), 'authority' => array('release' => array(), 'statement' => array()));
		return array('statement' => $held['statement'], 'log' => $log, 'release' => $held['release'], 'authority' => $held['authority']);
	}

	/**
	 * The node's check, run here before anything ships: the document's
	 * envelope and entry verify against the keys this release installs, and
	 * the payload records $subjects (artifact name => manifest subject) and
	 * $keys_installed exactly. When it carries a key chain, the chain walks
	 * from genesis and the statement verifies against the keys the walk ends
	 * with, as on a node that skipped every release since genesis. Returns
	 * the payload.
	 */
	public static function verifyDocument(string $bytes, array $keys_installed, array $subjects, ?array $authority = null): array {
		$doc = json_decode($bytes, true);
		if (!is_array($doc) || ($doc['format'] ?? null) !== self::FORMAT || !is_array($doc['envelope'] ?? null) || !is_array($doc['entry'] ?? null)) {
			throw new Exception('the release statement is not the format this publisher writes');
		}
		$statement_keys = array_map(function ($b64) { return (string)base64_decode($b64, true); }, $keys_installed['statement_keys']);
		$log_keys = array();
		foreach ($keys_installed['log_keys'] as $pair) {
			// Every key the release installs for a log: while a log rotates its
			// key in place, the entry is under the current one of the two.
			$log_keys[$pair['origin']][] = (string)base64_decode($pair['key'], true);
		}
		try {
			TransparencyProof::verifyEntry($doc['envelope'], $doc['entry'], $statement_keys, $log_keys);
		} catch (TransparencyProofException $e) {
			throw new Exception('the release statement does not verify against the keys this release installs: ' . $e->getMessage());
		}
		$payload = self::payloadOf($doc);
		if (!empty($doc['key_chain'])) {
			$walked = self::walkChain($doc['key_chain']);
			try {
				TransparencyProof::verifyEntry($doc['envelope'], $doc['entry'], $walked['statement'], $walked['log']);
			} catch (TransparencyProofException $e) {
				throw new Exception('the release statement does not verify against the keys its key chain hands a node: ' . $e->getMessage());
			}
			if (!self::sameTrust($walked, self::trustOf($payload))) {
				try {
					TransparencyProof::verifyEntry($doc['envelope'], $doc['entry'], $walked['authority']['statement'], $walked['log']);
				} catch (TransparencyProofException $e) {
					throw new Exception('the release statement changes the trusted set, but is not signed by a statement key of the authority its key chain hands a node');
				}
			}
		}
		if ($authority !== null && ($payload['authority'] ?? null) !== array('release_keys' => array_values($authority['release_keys']),
				'statement_keys' => array_values($authority['statement_keys']))) {
			throw new Exception('the release statement names a different authority than release_keys/authority');
		}
		if ($payload['keys_installed'] !== array('release_keys' => array_values($keys_installed['release_keys']),
				'statement_keys' => array_values($keys_installed['statement_keys']), 'log_keys' => array_values($keys_installed['log_keys']))) {
			throw new Exception('the release statement lists different keys than this release installs');
		}
		foreach ($subjects as $name => $sha) {
			if (($payload['artifacts'][$name] ?? null) !== $sha) {
				throw new Exception("the release statement does not record {$name} as it ships");
			}
		}
		return $payload;
	}
}
