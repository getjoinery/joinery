<?php
/**
 * RelayMapSync - push the compiled routing map to the relay over the tunnel.
 *
 * (specs/inbound_email_hardened_ingest_relay_executor.md § Phase 3). Builds the
 * artifacts with RelayMapExporter, rsyncs them to the relay over WireGuard
 * (key-only SSH — the relay's whole network surface is Postfix + WireGuard +
 * SSH), rebuilds the Postfix lookup tables, reloads Postfix, and drops the
 * sealer's routing.json in place (rsync's temp-then-rename keeps the sealer from
 * ever reading a half file). On success it records the synced version + push
 * time so the health checks can report map freshness.
 *
 * Called both push-on-change (whenever a domain/alias changes) and on the
 * periodic reconcile (SyncRelayMap scheduled task), so freshness beats the
 * reject_unmatched gate.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapExporter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

class RelayMapSync {

	const REMOTE_POSTFIX_DIR = '/etc/postfix';
	const REMOTE_ROUTING_PATH = '/opt/joinery-relay/routing.json';

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
	 * inline hash, or freshness becomes a permanent false alarm.
	 */
	public static function contentHash(array $artifacts): string {
		return hash('sha256', $artifacts['relay_domains'] . "\0" . $artifacts['recipients']
			. "\0" . $artifacts['transport'] . "\0" . $artifacts['srs_access'] . "\0" . $artifacts['routing_json']);
	}

	/**
	 * Build and push the map to the given relay. The push is skipped (no SSH) when
	 * the freshly-built map is byte-identical to the last one pushed, so this is
	 * cheap to call every reconcile pass and on every routing change. Pass
	 * $force=true to re-push regardless (e.g. after a rebuild wipes the relay).
	 * Returns ['status'=>'success'|'error'|'skipped', 'message'=>..., 'version'=>int].
	 * Never throws — a sync failure is reported, not fatal (the relay keeps
	 * running the last good map).
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

		$stage = self::stage($artifacts);
		if ($stage === null) {
			return array('status' => 'error', 'message' => 'could not stage map files');
		}

		try {
			// Postfix lookup tables → /etc/postfix. Each upload's exit code is
			// checked — a discarded failure would let postmap/reload run on the OLD
			// files, exit 0, and record success for a stale map (a new alias then
			// bounces forever with the health dashboard green).
			foreach (array('joinery-relay-domains', 'joinery-recipients', 'joinery-transport', 'joinery-srs', 'routing.json') as $name) {
				$remote = ($name === 'routing.json')
					? self::REMOTE_ROUTING_PATH
					: self::REMOTE_POSTFIX_DIR . '/' . $name;
				list($rc, $rout) = RelaySsh::run(RelaySsh::rsyncCommand($relay, $stage . '/' . $name, $remote, false));
				if ($rc !== 0) {
					// Return WITHOUT touching mrl_map_content_hash / version / push_time,
					// so the change-skip check stays false and the next reconcile retries.
					return array('status' => 'error', 'message' => 'rsync of ' . $name . ' failed: ' . $rout);
				}
			}

			// Rebuild the hashes and reload Postfix in one round trip.
			// routing.json carries the SRS secret + public keys; keep it readable by
			// the unprivileged sealer user (the Postfix pipe runs as joinery-relay)
			// but nobody else.
			$remote_cmd = 'set -e; '
				. 'postmap ' . self::REMOTE_POSTFIX_DIR . '/joinery-relay-domains; '
				. 'postmap ' . self::REMOTE_POSTFIX_DIR . '/joinery-recipients; '
				. 'postmap ' . self::REMOTE_POSTFIX_DIR . '/joinery-transport; '
				. 'chown root:joinery-relay ' . self::REMOTE_ROUTING_PATH . ' 2>/dev/null || true; '
				. 'chmod 640 ' . self::REMOTE_ROUTING_PATH . '; '
				. 'postfix reload';
			list($code, $out) = RelaySsh::run(RelaySsh::sshCommand($relay, $remote_cmd));
			if ($code !== 0) {
				return array('status' => 'error', 'message' => 'remote postmap/reload failed: ' . $out);
			}
		} catch (\Throwable $e) {
			return array('status' => 'error', 'message' => $e->getMessage());
		} finally {
			self::cleanup($stage);
		}

		$version = intval($relay->get('mrl_map_version')) + 1;
		$relay->set('mrl_map_version', $version);
		$relay->set('mrl_map_content_hash', $hash);
		$relay->set('mrl_last_push_time', gmdate('Y-m-d H:i:s'));
		$relay->save();

		return array('status' => 'success', 'message' => 'map v' . $version . ' pushed', 'version' => $version);
	}

	/** Write the artifacts to a private staging dir; returns its path or null. */
	private static function stage(array $artifacts): ?string {
		$dir = sys_get_temp_dir() . '/joinery-relay-map-' . bin2hex(random_bytes(6));
		if (!@mkdir($dir, 0700, true)) {
			return null;
		}
		$writes = array(
			'joinery-relay-domains' => $artifacts['relay_domains'],
			'joinery-recipients'    => $artifacts['recipients'],
			'joinery-transport'     => $artifacts['transport'],
			'joinery-srs'           => $artifacts['srs_access'],
			'routing.json'          => $artifacts['routing_json'],
		);
		foreach ($writes as $name => $body) {
			if (file_put_contents($dir . '/' . $name, $body) === false) {
				self::cleanup($dir);
				return null;
			}
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
