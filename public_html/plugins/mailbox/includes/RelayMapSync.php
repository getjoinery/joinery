<?php
/**
 * RelayMapSync - push this tenant's map fragment to the relay.
 *
 * (specs/relay_without_a_shell.md, specs/mailbox_relay_shared_fleet.md § Map
 * sync). Builds the fragment with RelayMapExporter and sends it as one signed
 * PUT /relay/fragment; the relay's root path unit validates it against the
 * tenant's root-owned domain allowlist, derives the Postfix maps, reloads, and
 * the VALIDATION VERDICT comes back in the response. That relay-side step is
 * where the domain-claim boundary is enforced, so a rejected fragment surfaces
 * here as an error (the relay keeps serving the last accepted fragment).
 *
 * On success it records the synced version + push time so the health checks
 * can report map freshness.
 *
 * Called both push-on-change (whenever a domain/alias changes) and on the
 * periodic reconcile (the relay reconcile scheduled task), so freshness beats the
 * reject_unmatched gate.
 *
 * @version 2.3 - the ssh era is over: the API is the only push path
 * @version 2.2 - a relay with an identity pin takes the fragment as a signed
 *                PUT /relay/fragment and answers the merge verdict in the response;
 *                a tunnel relay keeps the rsync + joinery-merge flow
 *                (specs/relay_without_a_shell.md)
 * @version 2.1 - fragment push + merge-verdict flow
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapExporter.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayClient.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

class RelayMapSync {

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
		if (!$relay->usesRelayApi()) {
			return array('status' => 'skipped', 'message' => 'relay has no identity pin (it predates the relay API) and cannot be reached');
		}
		if (trim((string)$relay->get('mrl_public_ip')) === '') {
			return array('status' => 'skipped', 'message' => 'relay has no public address yet');
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

		// One signed PUT; root's path unit on the relay performs the merge and
		// the response IS the verdict. A transport failure returns without
		// touching the bookkeeping, so the next reconcile retries.
		try {
			$answer = $relay->withApi(function (RelayClient $c) use ($fragment_body) { return $c->putFragment($fragment_body); });
		} catch (RelayClientException $e) {
			return array('status' => 'error', 'message' => 'fragment push failed (' . $e->failure_class . '): ' . $e->getMessage());
		} catch (\Throwable $e) {
			return array('status' => 'error', 'message' => $e->getMessage());
		}
		if (($answer['status'] ?? '') === 'timeout') {
			return array('status' => 'error', 'message' => 'the relay did not apply the fragment in time — will retry');
		}
		if (($answer['status'] ?? '') === 'error') {
			return array('status' => 'error', 'message' => 'the relay could not apply the fragment: '
				. (string)($answer['reason'] ?? 'unknown reason'));
		}
		$verdict = isset($answer['merge']) && is_array($answer['merge']) ? $answer['merge'] : array();
		if (($answer['status'] ?? '') === 'rejected' && !isset($verdict['status'])) {
			$verdict = array('status' => 'rejected', 'reason' => (string)($answer['reason'] ?? 'rejected'));
		}
		if (!isset($verdict['status'])) {
			return array('status' => 'error', 'message' => 'the relay answered the fragment push with no merge verdict');
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

}
