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
 * PATHS ARE RELATIVE TO THE SITE ROOT in every manifest, including a plugin's.
 * The scripts the agent most needs to verify span both trees
 * (maintenance_scripts/install_tools/*.sh and public_html/utils/*.php), so a
 * public_html-relative root could not name half of them, and one root across all
 * manifests means the agent resolves a path the same way whichever artifact owns
 * it.
 *
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
