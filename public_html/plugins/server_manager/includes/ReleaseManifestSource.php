<?php
/**
 * The signed release manifest for a version this plane has published.
 *
 * A node whose own RELEASE_MANIFEST has become unusable — absent, unsigned, or
 * signed by a key its agent does not carry — refuses every script primitive it
 * has, apply_update among them. It cannot repair itself through the agent,
 * because the upgrade that would deliver a good manifest is refused by the same
 * check that is failing. Until SSH is retired that is a manual job on the box;
 * after it, it is a machine nobody can reach. See
 * specs/agent_manifest_trust_recovery.md.
 *
 * This class is the plane's half of the way out: it reads the signed manifest
 * back out of the published archive for a given artifact and version, so the
 * agent can fetch it over the channel it already polls and verify it against
 * the release key compiled into its own binary.
 *
 * WHY THIS IS NOT A NEW TRUST RELATIONSHIP. This plane does not hold the release
 * key and cannot sign a manifest. Everything it serves here is refused by the
 * node unless the signature verifies against the key baked into that node's
 * binary at build time — the same discipline, and the same code path, that lets
 * a plane serve an agent BINARY without being trusted to. What travels is bytes
 * that were signed elsewhere; what decides is a check that never leaves the node.
 *
 * THE NODE NAMES AN ARTIFACT AND A VERSION, NEVER A PATH. Resolution to a file
 * happens entirely here, out of this plane's own directory layout, for the same
 * reason serve_agent_binary() takes an architecture rather than a filename.
 *
 * @version 1.1 - member names are matched by stripping a './' PREFIX, not a character set
 */
class ReleaseManifestSource {

	/** What a manifest pair looks like on disk and inside every archive. */
	const MANIFEST_NAME = 'RELEASE_MANIFEST';
	const SIG_NAME      = 'RELEASE_MANIFEST.sig';

	/**
	 * A manifest larger than this is not one of ours. The real core manifest is
	 * a few hundred kilobytes (0.8.370: 186KB); this leaves room for a much
	 * larger tree without letting a corrupt archive be read into memory whole.
	 */
	const MAX_MANIFEST_BYTES = 8388608;

	/**
	 * Site-root-relative artifact owners this will serve, in the agent's own
	 * spelling. '' is the core release; anything else is an independently
	 * shipped plugin or theme, which carries its own manifest inside its own
	 * archive — core's manifest does not describe those trees and must never be
	 * offered for one (there is no cross-manifest fallback, by design).
	 */
	const OWNER_PATTERN = '#^public_html/(plugins|theme)/[A-Za-z0-9][A-Za-z0-9_-]{0,63}$#';

	/** Versions this will look for. Plugin versions and core versions both. */
	const VERSION_PATTERN = '/^[0-9]{1,5}(\.[0-9]{1,5}){1,3}$/';

	/**
	 * Read the signed manifest pair for one artifact at one version.
	 *
	 * Returns ['manifest' => string, 'signature' => string] or NULL when this
	 * plane has nothing to offer — a version whose archive has been pruned, an
	 * artifact it never published, an archive predating signed manifests. NULL
	 * is an ordinary answer, not an error: a plane that cannot help is the
	 * normal state of a plane that has never published, and the node treats it
	 * the way it treats every other absent artifact.
	 */
	public static function read(string $owner, string $version): ?array {
		if (!self::valid_owner($owner))     { return null; }
		if (!self::valid_version($version)) { return null; }

		$archive = self::archive_path($owner, $version);
		if ($archive === null || !is_file($archive)) { return null; }

		// Inside a core archive the pair sits at the top level; inside a plugin
		// or theme archive it sits under the component's own directory, which is
		// the last path segment of the owner.
		$prefix = '';
		if ($owner !== '') {
			$parts  = explode('/', $owner);
			$prefix = end($parts) . '/';
		}

		$manifest = self::extract($archive, $prefix . self::MANIFEST_NAME);
		if ($manifest === null) { return null; }
		$signature = self::extract($archive, $prefix . self::SIG_NAME);
		if ($signature === null) { return null; }

		return ['manifest' => $manifest, 'signature' => $signature];
	}

	/** Is this an artifact owner this plane will resolve? */
	public static function valid_owner(string $owner): bool {
		return $owner === '' || (bool)preg_match(self::OWNER_PATTERN, $owner);
	}

	/** Is this a version string this plane will look for? */
	public static function valid_version(string $version): bool {
		return (bool)preg_match(self::VERSION_PATTERN, $version);
	}

	/**
	 * Where this plane keeps the archive for one artifact and version.
	 *
	 * Built here from a validated owner and version and nothing else. The
	 * component name is re-checked against a plain-name pattern on the way in,
	 * so even a bug in OWNER_PATTERN could not put a traversal into the path.
	 */
	private static function archive_path(string $owner, string $version): ?string {
		$static = PathHelper::getSiteRoot() . '/static_files';

		if ($owner === '') {
			return $static . '/joinery-core-' . $version . '.tar.gz';
		}

		$parts = explode('/', $owner);
		$name  = end($parts);
		$type  = $parts[1] ?? '';
		if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $name)) { return null; }

		if ($type === 'plugins') { return $static . '/plugins/' . $name . '-' . $version . '.tar.gz'; }
		if ($type === 'theme')   { return $static . '/themes/'  . $name . '-' . $version . '.tar.gz'; }
		return null;
	}

	/**
	 * Pull one named member out of a gzipped tar, streaming.
	 *
	 * PharData would be shorter and is not used: it wants the archive
	 * decompressed to a temporary file first, and these archives are tens of
	 * megabytes each. This reads the tar header chain and stops at the member it
	 * wants, so the common case touches a few kilobytes of a large file.
	 *
	 * Returns NULL when the member is not there, which includes every archive
	 * published before signed manifests existed.
	 */
	private static function extract(string $archive, string $member): ?string {
		$fh = @gzopen($archive, 'rb');
		if (!$fh) { return null; }

		$found = null;
		try {
			while (!gzeof($fh)) {
				$header = gzread($fh, 512);
				if ($header === false || strlen($header) < 512) { break; }
				// The end of a tar is two zero blocks; one is enough to stop on.
				if (trim($header, "\0") === '') { break; }

				$name = trim(substr($header, 0, 100), "\0");
				$size = octdec(trim(substr($header, 124, 12), "\0 ")) ?: 0;
				// Archives are written with and without a leading './'; the
				// caller knows neither spelling and must not have to. A prefix,
				// not a character set — ltrim() here would turn '.htaccess'
				// into 'htaccess'.
				$name = preg_replace('#^\./#', '', $name);

				$blocks = (int)ceil($size / 512) * 512;
				if ($name === preg_replace('#^\./#', '', $member)) {
					if ($size > self::MAX_MANIFEST_BYTES) { break; }
					$body  = $size > 0 ? gzread($fh, $blocks) : '';
					$found = $body === false ? null : substr($body, 0, $size);
					break;
				}
				if ($blocks > 0 && gzseek($fh, $blocks, SEEK_CUR) !== 0) { break; }
			}
		} finally {
			gzclose($fh);
		}

		return $found;
	}
}
