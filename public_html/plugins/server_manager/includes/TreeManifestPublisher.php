<?php
/**
 * TreeManifestPublisher — the signed per-file manifest each shipped artifact
 * carries (specs/agent_on_node_architecture.md component G, §3.2).
 *
 * WHAT THIS IS FOR, in one sentence: the site tree is writable by the web user
 * while the agent runs as root, so before the agent executes a script from that
 * tree it has to know the script is the one the publisher shipped.
 *
 * That is the same discipline the agent's own self-update already applies to its
 * binary — verify against a key compiled into the agent, never a key or a
 * checksum fetched at the moment of use — generalised to every script a
 * primitive may invoke. Without it, a web-layer compromise becomes root the
 * first time a primitive runs a script.
 *
 * WHAT IT IS NOT: a tree attestation. This answers "may this file be exec'd as
 * root", not "is this tree untampered". A real node's tree legitimately holds
 * things no release manifest lists — marketplace-installed plugins, extensions
 * marked receives_upgrades:false and preserved on purpose, generated caches,
 * site-local config. §3.6's attestation needs a notion of legitimately-present-
 * but-unsigned content that this deliberately does not provide.
 *
 * ONE MANIFEST PER SHIPPED ARTIFACT, not one per release. Plugin archives ship
 * and upgrade independently of the core release, so a single manifest signed at
 * core-publish time would describe a tree a node can legitimately diverge from
 * — and a routine plugin update would silently stop that plugin's scripts
 * verifying, with nothing to say why. Each artifact carries its own.
 *
 * WHO MAY SIGN: only a site whose shipped agent bundle was built with its own
 * key. The agent verifies against the key compiled into its binary, and a site
 * that received its agent from upstream carries upstream's key in that binary
 * while holding its own key in config/. A manifest such a site signs is one its
 * own agent refuses, and one every node it serves refuses too. So a site that
 * cannot sign carries forward the manifest it received — for the archive it
 * republishes and for its own live tree, which it leaves alone. See authority().
 *
 * PATHS ARE RELATIVE TO THE SITE ROOT in every manifest, including a plugin's.
 * The scripts the agent most needs to verify span both trees
 * (maintenance_scripts/install_tools/*.sh and public_html/utils/*.php), so a
 * public_html-relative root could not name half of them, and one root across all
 * manifests means the agent resolves a path the same way whichever artifact owns
 * it.
 *
 * @version 1.1 - authority() decides whether this site may sign at all, and publish_artifact()
 *                carries the received manifest forward when it may not
 * @version 1.0
 */

class TreeManifestPublisher {

	/** The manifest and its detached signature, at the root of each artifact. */
	const MANIFEST_NAME  = 'RELEASE_MANIFEST';
	const SIGNATURE_NAME = 'RELEASE_MANIFEST.sig';

	/**
	 * Paths never listed, matched against the site-root-relative path.
	 *
	 * Two kinds of thing are excluded, for two different reasons. Site-local
	 * mutable state (config/, logs/, cache/, uploads/, backups/) is excluded
	 * because it is not shipped and differs on every node — listing it would
	 * make every manifest wrong the moment the site ran. Development and
	 * packaging debris (.git, specs/) is excluded because it is not shipped
	 * either.
	 *
	 * The manifest and its signature exclude themselves, necessarily: a file
	 * cannot contain its own hash.
	 */
	private static $excluded_segments = array('.git', 'cache', 'logs', 'uploads', 'backups', 'specs', '.claude', 'node_modules', 'vendor');
	private static $excluded_basenames = array('.gitignore', self::MANIFEST_NAME, self::SIGNATURE_NAME);
	/** Excluded only at the top level of the site root — a plugin may legitimately ship a config/ directory. */
	private static $excluded_top_level = array('config');

	/**
	 * Whether this site may sign a tree manifest, and with what.
	 *
	 * The answer is the agent bundle's: the key that built the agent this site
	 * ships is the only key that agent, and every node that installs it, will
	 * verify against. A bundle built here records that key in its manifest; a
	 * bundle that predates the record was built here only if the agent source is
	 * on this box (a box without the source can only ever have received one).
	 *
	 * @return array{may_sign:bool, keys:?array, own_public_b64:string, bundle_key_b64:?string, reason:string}
	 */
	public static function authority($full_site_dir) {
		$keys = AgentDistPublisher::ensureKeys($full_site_dir . '/config');
		$bundle_key = AgentDistPublisher::bundleSigningKey($full_site_dir);
		$src = AgentDistPublisher::sourcePath();
		$builds_here = is_dir($src) && file_exists($src . '/main.go');
		$verdict = self::maySign($bundle_key, $keys['public_b64'], $builds_here);
		return array(
			'may_sign'       => $verdict['may_sign'],
			'keys'           => $verdict['may_sign'] ? $keys : null,
			'own_public_b64' => $keys['public_b64'],
			'bundle_key_b64' => $bundle_key,
			'reason'         => $verdict['reason'],
		);
	}

	/** The rule behind authority(), pure so it can be asserted directly. */
	public static function maySign($bundle_key_b64, $own_public_b64, $builds_here) {
		if ($bundle_key_b64 !== null && $bundle_key_b64 !== '') {
			if ($bundle_key_b64 === $own_public_b64) {
				return array('may_sign' => true,
					'reason' => 'Tree manifests: signing with this site\'s key, which built the agent bundle it ships');
			}
			return array('may_sign' => false,
				'reason' => 'Tree manifests: this site cannot sign - the agent bundle it ships was built with '
					. 'another site\'s key, so it carries forward the manifests it received');
		}
		if ($builds_here) {
			return array('may_sign' => true,
				'reason' => 'Tree manifests: signing with this site\'s key - the agent source is on this box, so the bundle was built here');
		}
		return array('may_sign' => false,
			'reason' => 'Tree manifests: this site cannot sign - it did not build the agent bundle it ships, '
				. 'so it carries forward the manifests it received');
	}

	/**
	 * Put the right manifest into an artifact: signed here when this site may
	 * sign, otherwise the one received from upstream, carried forward.
	 *
	 * @param string $artifact_dir  The tree that ships (staged core, or a live plugin/theme dir)
	 * @param string $site_root     Root the recorded paths are relative to
	 * @param array  $authority     From authority()
	 * @param string|null $received_dir Where the received manifest lives when it is not
	 *                              $artifact_dir itself (the site root, for the staged core)
	 * @return array ['files' => int, 'manifest' => path, 'carried' => bool]
	 */
	public static function publish_artifact($artifact_dir, $site_root, array $authority, $received_dir = null) {
		if ($authority['may_sign']) {
			$r = self::write($artifact_dir, $site_root, $authority['keys']);
			$r['carried'] = false;
			return $r;
		}
		return self::carry($received_dir ?: $artifact_dir, $artifact_dir, $authority);
	}

	/**
	 * Carry a received manifest forward into $to_dir, checking first that it is
	 * one the shipped agent will accept. Two refusals, both loud: no received
	 * manifest to carry (nothing arrived here), and a manifest this site signed
	 * itself in the past — the exact state that stops every script primitive on
	 * this site, which must not be republished onto anyone else.
	 */
	public static function carry($from_dir, $to_dir, array $authority) {
		$from_dir = rtrim($from_dir, '/');
		$to_dir   = rtrim($to_dir, '/');
		$manifest_path  = $from_dir . '/' . self::MANIFEST_NAME;
		$signature_path = $from_dir . '/' . self::SIGNATURE_NAME;
		if (!is_file($manifest_path) || !is_file($signature_path)) {
			throw new Exception('this site cannot sign a tree manifest (' . $authority['reason'] . ') and '
				. $from_dir . ' holds no received manifest to carry forward');
		}
		$body = file_get_contents($manifest_path);
		$signature = base64_decode(trim((string)file_get_contents($signature_path)), true);
		if ($body === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
			throw new Exception('the received manifest in ' . $from_dir . ' is unreadable');
		}

		$own = base64_decode((string)$authority['own_public_b64'], true);
		if ($own !== false && strlen($own) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
			&& sodium_crypto_sign_verify_detached($signature, $body, $own)) {
			throw new Exception('the manifest in ' . $from_dir . ' was signed with this site\'s own key, which the '
				. 'agent this site ships does not trust. Upgrade from upstream to restore the received manifest, '
				. 'then publish again');
		}
		$bundle = !empty($authority['bundle_key_b64']) ? base64_decode($authority['bundle_key_b64'], true) : false;
		if ($bundle !== false && strlen($bundle) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
			&& !sodium_crypto_sign_verify_detached($signature, $body, $bundle)) {
			throw new Exception('the manifest in ' . $from_dir . ' does not verify against the key in the agent '
				. 'this site ships');
		}

		if ($from_dir !== $to_dir) {
			if (!copy($manifest_path, $to_dir . '/' . self::MANIFEST_NAME)
				|| !copy($signature_path, $to_dir . '/' . self::SIGNATURE_NAME)) {
				throw new Exception('could not carry the received manifest into ' . $to_dir);
			}
		}
		return array('files' => substr_count($body, "\n") - 2, 'manifest' => $to_dir . '/' . self::MANIFEST_NAME, 'carried' => true);
	}

	/**
	 * Write a signed manifest covering $dir into $dir.
	 *
	 * @param string $dir        Directory to walk (the artifact's own tree)
	 * @param string $site_root  Root the recorded paths are relative to
	 * @param array  $keys       ['secret' => ..., 'public' => ...] from AgentDistPublisher::ensureKeys()
	 * @return array ['files' => int, 'manifest' => path]
	 * @throws Exception when the manifest cannot be written or does not verify
	 */
	public static function write($dir, $site_root, array $keys) {
		$body = self::build($dir, $site_root);

		$manifest_path  = rtrim($dir, '/') . '/' . self::MANIFEST_NAME;
		$signature_path = rtrim($dir, '/') . '/' . self::SIGNATURE_NAME;

		$signature = sodium_crypto_sign_detached($body, $keys['secret']);

		// Verify what we just signed, with the public half, before it ships.
		// A signature nobody checked at build time is a signature discovered to
		// be wrong by a fleet of agents refusing to run anything.
		if (!sodium_crypto_sign_verify_detached($signature, $body, $keys['public'])) {
			throw new Exception('the tree manifest signature does not verify against its own public key');
		}

		if (file_put_contents($manifest_path, $body) === false) {
			throw new Exception('could not write ' . $manifest_path);
		}
		if (file_put_contents($signature_path, base64_encode($signature) . "\n") === false) {
			throw new Exception('could not write ' . $signature_path);
		}

		return array('files' => substr_count($body, "\n"), 'manifest' => $manifest_path);
	}

	/**
	 * The manifest body: "<sha256>  <site-root-relative path>" lines, sorted.
	 *
	 * The format is fixed by the agent's parser (primitives/manifest.go) — two
	 * fields, a 64-character lowercase hex hash first. Sorted so the same tree
	 * always produces byte-identical output, which is what lets a publish that
	 * changed nothing leave the artifact alone.
	 */
	public static function build($dir, $site_root) {
		$site_root = rtrim($site_root, '/');
		$entries = array();

		// Excluded directories are pruned BEFORE descent, not filtered after:
		// the live site root contains directories the publishing user cannot
		// read (root-owned backup chains), and an iterator that descends first
		// throws on them — filtering the entries afterwards never runs. Pruning
		// also keeps the walk from hashing its way through gigabytes of
		// excluded backup and upload content.
		$inner = new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			function ($file) use ($site_root) {
				$rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($site_root))), '/');
				if (self::excluded($rel)) {
					return false;
				}
				// Unreadable entries are pruned rather than fatal: on the live
				// tree a root-owned stray must not abort a publish, and a file
				// absent from the manifest is honestly UNAVAILABLE to the
				// agent, never silently trusted.
				return $file->isReadable();
			}
		);
		$rii = new RecursiveIteratorIterator($inner, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
		foreach ($rii as $file) {
			if ($file->isDir()) continue;

			$abs = $file->getPathname();
			$rel = ltrim(str_replace('\\', '/', substr($abs, strlen($site_root))), '/');
			if (self::excluded($rel)) continue;

			$hash = hash_file('sha256', $abs);
			if ($hash === false) {
				throw new Exception('could not hash ' . $rel);
			}
			$entries[$rel] = $hash;
		}

		ksort($entries);

		$body = "# Joinery release manifest — sha256 of every shipped file, paths relative to the site root.\n"
		      . "# Signed with the release key; the agent verifies against the key compiled into its binary.\n";
		foreach ($entries as $rel => $hash) {
			$body .= $hash . '  ' . $rel . "\n";
		}
		return $body;
	}

	/** Whether a site-root-relative path is left out of the manifest. */
	public static function excluded($rel) {
		$segments = explode('/', $rel);

		if (in_array(basename($rel), self::$excluded_basenames, true)) {
			return true;
		}
		if (in_array($segments[0], self::$excluded_top_level, true)) {
			return true;
		}
		foreach ($segments as $segment) {
			if (in_array($segment, self::$excluded_segments, true)) {
				return true;
			}
		}
		return false;
	}
}
