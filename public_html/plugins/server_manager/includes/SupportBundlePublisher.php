<?php
/**
 * SupportBundlePublisher — the signed script tree a machine with no Joinery
 * site runs its primitives from (specs/agent_machine_posture_and_relay_converge.md §4).
 *
 * WHAT PROBLEM THIS SOLVES, in one sentence: a machine the plane manages but
 * that hosts no site — a mail relay, a Docker host — has no release tree, so it
 * has nothing to verify a script against, so it cannot run one at all.
 *
 * The agent will not execute a script as root that it cannot prove the
 * publisher shipped. On a node that proof is the site's own signed
 * RELEASE_MANIFEST. A siteless machine has no site, no manifest and therefore
 * no script primitives whatsoever — its entire vocabulary is embedded Go. That
 * is the constraint that made "rewrite the relay provisioning in Go" look
 * necessary, and it is a constraint about verification rather than about
 * language: what a relay needs is not Go instead of a script, it is a script it
 * can prove.
 *
 * So this ships one: a small tarball carrying exactly the scripts those
 * machines invoke, plus its own RELEASE_MANIFEST and .sig signed with the same
 * release key everything else is. The agent fetches it over the channel,
 * verifies the signature against the key compiled into its binary, unpacks it
 * root-owned, and resolves script primitives against it.
 *
 * THE PLANE IS NOT A TRUST ROOT HERE AND THIS FILE IS WHY THAT IS TRUE. The
 * signing happens at publish time with the release key; the plane that later
 * SERVES these bytes over the agent channel signs nothing and vouches for
 * nothing. A compromised plane can withhold a bundle or serve an old one. It
 * cannot serve one the fleet will run.
 *
 * PATHS ARE SITE-ROOT-RELATIVE inside the bundle, exactly as they are in a
 * release manifest. That is what lets one primitive declare one ScriptPath and
 * have it resolve correctly whether the machine running it has a site tree or a
 * bundle — the posture stops being something a primitive has to know about.
 *
 * THE VERSION IS A CONTENT HASH, not the release number. A publish that changes
 * no bundled script must produce a byte-identical bundle, the same way an
 * unchanged agent leaves agent_dist alone — otherwise every publish hands every
 * siteless machine a download whose only difference is a version string. The
 * hash of the manifest body answers "has the content changed" directly, with
 * nothing to keep in step.
 *
 * @version 1.0
 */

class SupportBundlePublisher {

	/**
	 * Whether any machine in the fleet consumes a support bundle.
	 *
	 * SHELVED (owner, 2026-08-28). The bundle exists for machines that run the
	 * agent and host no Joinery site, and there are none: the relay is not
	 * managed by design, and both hardening targets move as full-site nodes.
	 * A mechanism that runs on every publish and is consumed by nobody is a
	 * mechanism nobody watches, so the pipeline does not call it — this class
	 * and its tests stand, ready, and are exercised directly.
	 *
	 * FLIP THIS TO TRUE when a siteless machine actually needs a verified
	 * script tree. The Docker host is the candidate: it would run
	 * provision_certificate from a bundle-supplied setup_ssl.sh, which is the
	 * last executor missing before the shared provisioning key can be
	 * destroyed.
	 */
	public static function hasConsumer() {
		return false;
	}

	/** Where the bundle ships. Beside the agent artifact, served by the same endpoint. */
	const BUNDLE_NAME = 'support_bundle.tar.gz';
	const INFO_NAME   = 'support_bundle.json';

	/**
	 * What a siteless machine's primitives invoke. Site-root-relative paths,
	 * and a DELIBERATE LIST rather than a directory sweep.
	 *
	 * A sweep would be shorter and would quietly widen the set of things a
	 * relay can be asked to execute every time somebody adds a file to
	 * sysadmin_tools. Every entry here is a script some primitive actually
	 * names; adding one is a decision, and a reviewer sees it.
	 *
	 * Ordering note for whoever adds the next one: a script that SOURCES
	 * another (setup_ssl.sh sources install.sh from a sibling directory) needs
	 * that sibling here too, at the same relative path. The layout inside the
	 * bundle is the layout the scripts expect to find themselves in.
	 *
	 * No relay content belongs here. The relay is not a managed machine (owner,
	 * 2026-08-28): its whole management surface is a plane-side port probe and
	 * complete reprovisioning, so it runs no agent and invokes no primitive. The
	 * prebuilt relay-sealer reaches it through the provisioning tarball instead.
	 *
	 * The consumer this list is waiting for is the Docker host, which needs
	 * setup_ssl.sh to issue certificates for the containers it fronts.
	 */
	private static $contents = array(
		'maintenance_scripts/sysadmin_tools/setup_ssl.sh',
		// setup_ssl.sh sources this for provision_origin_cert() and its
		// helpers; without it the bundle ships a script that cannot run.
		'maintenance_scripts/install_tools/install.sh',
	);

	/**
	 * Build (or leave alone) the support bundle in the agent_dist directory.
	 *
	 * Never throws, for the same reason AgentDistPublisher does not: a bundle
	 * problem must not abort an unrelated platform publish. It returns a status
	 * and leaves any previous bundle in place.
	 *
	 * @param string $full_site_dir e.g. /var/www/html/joinerytest
	 * @param callable|null $out    line-output callback (publish_output)
	 * @return array{status:string, message:string, version:?string, files:int}
	 */
	public static function publish($full_site_dir, $out = null) {
		$say = function ($msg) use ($out) { if ($out) { call_user_func($out, $msg); } };

		$dist_dir = $full_site_dir . '/public_html/agent_dist';
		$staging  = $dist_dir . '/.support_bundle_staging';

		try {
			$missing = array();
			foreach (self::$contents as $rel) {
				if (!file_exists($full_site_dir . '/' . $rel)) {
					$missing[] = $rel;
				}
			}
			if (!empty($missing)) {
				// A bundle missing a script it declares is not a smaller
				// bundle, it is a bundle that fails on the machine instead of
				// here. Carry the previous one forward and say why.
				$msg = 'Support bundle: WARNING - not rebuilt, these declared files are absent: '
					. implode(', ', $missing);
				$say($msg);
				return self::result('carried', $msg, self::installedVersion($dist_dir), 0);
			}

			if (!is_dir($dist_dir) && !mkdir($dist_dir, 0755, true)) {
				throw new Exception("cannot create {$dist_dir}");
			}

			self::rrmdir($staging);
			if (!mkdir($staging, 0755, true)) {
				throw new Exception("cannot create staging dir {$staging}");
			}

			foreach (self::$contents as $rel) {
				$target = $staging . '/' . $rel;
				if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
					throw new Exception("cannot create the directory for {$rel}");
				}
				if (!copy($full_site_dir . '/' . $rel, $target)) {
					throw new Exception("cannot copy {$rel} into the bundle");
				}
				// Scripts ship executable; nothing here ships setuid. The agent
				// masks the mode on unpack regardless, so this is what the
				// bundle MEANS rather than what enforces it.
				chmod($target, 0755);
			}

			$keys = AgentDistPublisher::ensureKeys($full_site_dir . '/config');
			// Signed with the staging directory as its own root, so the paths
			// recorded are exactly the site-root-relative ones the primitives
			// declare — the bundle root stands in for a site root.
			$manifest = TreeManifestPublisher::write($staging, $staging, $keys);

			$version = substr(hash_file('sha256', $manifest['manifest']), 0, 16);
			$current = self::installedVersion($dist_dir);
			if ($current === $version && file_exists($dist_dir . '/' . self::BUNDLE_NAME)) {
				self::rrmdir($staging);
				$msg = "Support bundle: {$version} already bundled - unchanged";
				$say($msg);
				return self::result('skipped', $msg, $version, count(self::$contents));
			}

			$tarball = $dist_dir . '/' . self::BUNDLE_NAME;
			// Built into a temporary name and moved, so a failed tar never
			// replaces a working bundle with a truncated one.
			$temp_tar = $tarball . '.new';
			@unlink($temp_tar);
			$cmd = sprintf('tar -czf %s -C %s . 2>&1', escapeshellarg($temp_tar), escapeshellarg($staging));
			exec($cmd, $tar_out, $exit_code);
			if ($exit_code !== 0 || !file_exists($temp_tar)) {
				throw new Exception('tar failed: ' . implode(' | ', array_slice($tar_out, -3)));
			}
			if (!rename($temp_tar, $tarball)) {
				@unlink($temp_tar);
				throw new Exception('cannot move the new bundle into place');
			}
			chmod($tarball, 0644);

			// What the artifact endpoint hands a machine that asks what is on
			// offer. The sha256 here lets an agent skip a download it already
			// has; it is NOT what makes the bundle trustworthy — the signature
			// inside it is, verified against a key this plane does not hold.
			$info = array(
				'version' => $version,
				'file'    => self::BUNDLE_NAME,
				'sha256'  => hash_file('sha256', $tarball),
				'bytes'   => filesize($tarball),
			);
			if (file_put_contents($dist_dir . '/' . self::INFO_NAME,
					json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
				throw new Exception('cannot write ' . self::INFO_NAME);
			}
			chmod($dist_dir . '/' . self::INFO_NAME, 0644);

			self::rrmdir($staging);

			$msg = "Support bundle: built {$version} (" . count(self::$contents) . ' files, '
				. round($info['bytes'] / 1024) . ' KB)';
			$say($msg);
			return self::result('built', $msg, $version, count(self::$contents));
		} catch (\Throwable $e) {
			self::rrmdir($staging);
			$msg = 'Support bundle: WARNING - ' . $e->getMessage() . '; previous bundle (if any) carried forward';
			$say($msg);
			return self::result('carried', $msg, self::installedVersion($dist_dir), 0);
		}
	}

	/** The version of the bundle already in agent_dist, or null. */
	public static function installedVersion($dist_dir) {
		$info = self::info($dist_dir);
		return $info ? ($info['version'] ?? null) : null;
	}

	/**
	 * Read the bundle's info record. The artifact endpoint's source of truth
	 * for what this plane has to offer.
	 */
	public static function info($dist_dir) {
		$raw = @file_get_contents(rtrim($dist_dir, '/') . '/' . self::INFO_NAME);
		if ($raw === false) { return null; }
		$info = json_decode($raw, true);
		return is_array($info) ? $info : null;
	}

	/** The declared contents, for tests and for anyone auditing what a relay can be asked to run. */
	public static function declaredContents() {
		return self::$contents;
	}

	private static function result($status, $message, $version, $files) {
		return array('status' => $status, 'message' => $message, 'version' => $version, 'files' => $files);
	}

	private static function rrmdir($dir) {
		if (!is_dir($dir)) { return; }
		foreach (scandir($dir) ?: array() as $f) {
			if ($f === '.' || $f === '..') { continue; }
			$path = $dir . '/' . $f;
			is_dir($path) ? self::rrmdir($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}
