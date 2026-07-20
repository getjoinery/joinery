<?php
/**
 * RelayMapSync - push this tenant's map fragment to the relay over the tunnel.
 *
 * (specs/mailbox_relay_shared_fleet.md § Map sync: fragment push and shard-side
 * merge). Builds the fragment with RelayMapExporter, rsyncs it into the
 * tenant's own fragment drop area over WireGuard (the restricted tenant
 * account — never root, never /etc/postfix), then triggers the relay's merge
 * unit and reads the VALIDATION VERDICT in-band. The merge validates the
 * fragment against the tenant's root-owned domain allowlist, derives the
 * Postfix maps, and reloads — that shard-side step is where the domain-claim
 * boundary is enforced, so a rejected fragment surfaces here as an error (the
 * relay keeps serving the last accepted fragment).
 *
 * On success it records the synced version + push time so the health checks
 * can report map freshness.
 *
 * Called both push-on-change (whenever a domain/alias changes) and on the
 * periodic reconcile (SyncRelayMap scheduled task), so freshness beats the
 * reject_unmatched gate.
 *
 * @version 2.1 - fragment push + merge-verdict flow (replaces the root-login
 *                full-file replace into /etc/postfix)
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapExporter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

class RelayMapSync {

	const FRAGMENT_NAME = 'fragment.json';

	/**
	 * Best-effort immediate push after a routing change (a new alias must not
	 * bounce during the reconcile gap). No-op when there is no relay; swallows
	 * failures (the periodic reconcile retries).
	 */
	public static function onChange(): void {
		$relay = MailboxRelay::active();
		if ($relay === null) {
			return;
		}
		try {
			self::push($relay);
		} catch (\Throwable $e) {
			error_log('RelayMapSync::onChange push failed: ' . $e->getMessage());
		}
	}

	/**
	 * The ONE hash formula for a built artifact set. push() records it and
	 * checkRelayMapFresh() compares against it — both must call this, never an
	 * inline hash, or freshness becomes a permanent false alarm. Hashes the
	 * DETERMINISTIC fragment (version 0, before push() stamps the real one).
	 */
	public static function contentHash(array $artifacts): string {
		return hash('sha256', (string)($artifacts['fragment'] ?? ''));
	}

	/**
	 * Build and push the fragment to the given relay. The push is skipped (no
	 * SSH) when the freshly-built fragment is byte-identical to the last one
	 * pushed, so this is cheap to call every reconcile pass and on every routing
	 * change. Pass $force=true to re-push regardless (e.g. after a rebuild wipes
	 * the relay). Returns ['status'=>'success'|'error'|'skipped', 'message'=>...,
	 * 'version'=>int]. Never throws — a sync failure is reported, not fatal (the
	 * relay keeps running the last accepted fragment).
	 */
	public static function push(MailboxRelay $relay, bool $force = false): array {
		$host = trim((string)$relay->get('mrl_host'));
		if ($host === '') {
			return array('status' => 'skipped', 'message' => 'relay has no tunnel host yet');
		}

		try {
			$exporter = new RelayMapExporter($relay);
			$artifacts = $exporter->build();
		} catch (\Throwable $e) {
			return array('status' => 'error', 'message' => 'map build failed: ' . $e->getMessage());
		}

		$hash = self::contentHash($artifacts);
		if (!$force && $hash === (string)$relay->get('mrl_map_content_hash')) {
			return array('status' => 'skipped', 'message' => 'map unchanged',
				'version' => intval($relay->get('mrl_map_version')));
		}

		// Stamp the real version into the pushed copy (the built fragment is
		// deterministic at version 0 so the hash-skip works). The merge verdict
		// echoes fragment_version, which proves the merge saw THIS push and not
		// a stale drop.
		$version = intval($relay->get('mrl_map_version')) + 1;
		$fragment = json_decode($artifacts['fragment'], true);
		if (!is_array($fragment)) {
			return array('status' => 'error', 'message' => 'built fragment is not valid JSON');
		}
		$fragment['version'] = $version;
		$fragment_body = self::encodeFragmentBody($fragment);

		$stage = self::stage($fragment_body);
		if ($stage === null) {
			return array('status' => 'error', 'message' => 'could not stage the fragment');
		}

		try {
			// 1. Drop the fragment into the tenant's own drop area. The tenant
			// shell pins the rsync destination to exactly this directory.
			$cmd = RelaySsh::rsyncCommand($relay, $stage . '/' . self::FRAGMENT_NAME,
				$relay->fragmentDir() . '/', false);
			list($rc, $rout) = RelaySsh::run($cmd);
			if ($rc !== 0) {
				// Return WITHOUT touching mrl_map_content_hash / version / push_time,
				// so the change-skip check stays false and the next reconcile retries.
				return array('status' => 'error', 'message' => 'fragment push failed: ' . $rout);
			}

			// 2. Trigger the shard-side merge; the verdict comes back in-band.
			list($code, $out) = RelaySsh::run(RelaySsh::sshCommand($relay, 'joinery-merge'));
			$verdict = json_decode(trim($out), true);
			if (!is_array($verdict)) {
				return array('status' => 'error',
					'message' => 'merge returned no verdict (exit ' . $code . '): ' . substr(trim($out), 0, 300));
			}
			if (($verdict['status'] ?? '') !== 'ok') {
				return array('status' => 'error', 'message' => 'merge rejected the fragment: '
					. (string)($verdict['reason'] ?? 'unknown reason'));
			}
			if (empty($verdict['installed'])) {
				return array('status' => 'error', 'message' => 'merge validated the fragment but could not install the maps');
			}
			if (intval($verdict['fragment_version'] ?? -1) !== $version) {
				return array('status' => 'error', 'message' => 'merge served fragment v'
					. intval($verdict['fragment_version'] ?? -1) . ', expected v' . $version . ' — will retry');
			}
		} catch (\Throwable $e) {
			return array('status' => 'error', 'message' => $e->getMessage());
		} finally {
			self::cleanup($stage);
		}

		$relay->set('mrl_map_version', $version);
		$relay->set('mrl_map_content_hash', $hash);
		$relay->set('mrl_last_push_time', gmdate('Y-m-d H:i:s'));
		$relay->save();

		return array('status' => 'success', 'message' => 'map v' . $version . ' pushed', 'version' => $version);
	}

	/**
	 * Encode the fragment for the shard-side merge. The merge's typed
	 * unmarshal requires 'recipients' and 'domains' to be JSON OBJECTS even
	 * when empty — PHP's empty array would encode as [] and reject the whole
	 * fragment (a domainless deployment's first push hits exactly this).
	 */
	public static function encodeFragmentBody(array $fragment): string {
		foreach (array('recipients', 'domains') as $map_field) {
			if (array_key_exists($map_field, $fragment) && $fragment[$map_field] === array()) {
				$fragment[$map_field] = (object)array();
			}
		}
		return json_encode($fragment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	/** Write the fragment to a private staging dir; returns its path or null. */
	private static function stage(string $fragment_body): ?string {
		$dir = sys_get_temp_dir() . '/joinery-relay-map-' . bin2hex(random_bytes(6));
		if (!@mkdir($dir, 0700, true)) {
			return null;
		}
		if (file_put_contents($dir . '/' . self::FRAGMENT_NAME, $fragment_body) === false) {
			self::cleanup($dir);
			return null;
		}
		return $dir;
	}

	private static function cleanup(?string $dir): void {
		if ($dir === null || !is_dir($dir)) {
			return;
		}
		foreach (glob($dir . '/*') ?: array() as $f) {
			@unlink($f);
		}
		@rmdir($dir);
	}

}
