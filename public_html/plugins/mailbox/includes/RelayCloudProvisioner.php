<?php
/**
 * RelayCloudProvisioner - turns a just-in-time provider token into a running
 * relay on the customer's own cloud account, born configured
 * (specs/relay_without_a_shell.md; the run model from
 * specs/implemented/mailbox_relay_cloud_provisioning.md).
 *
 * Advances RelayCloudProvision runs. The provider (CloudComputeProvider /
 * LinodeComputeDriver, includes/cloud_compute/) creates or re-images the
 * instance with first-boot user-data rendered by RelayFirstBoot; the relay
 * fetches the run's own copy of the support bundle from this plane, builds
 * itself with provision_relay.sh, and posts a signed birth report that
 * RelayBirthEndpoint hands to completeBirth(). Nothing here logs in to the
 * machine: no SSH key is installed, no root password is recorded, and the plane
 * holds no credential for the relay, ever.
 *
 * Grant-per-act custody: the provider token and the one-time run token live
 * SecretBox-sealed on the run row, with the run's bundle copy beside it, and
 * all three are erased at every terminal state. A failed PROVISION destroys the
 * instance it created within the same grant; a failed UPDATE destroys nothing,
 * because the instance is the customer's existing relay. Failure policy: 4xx
 * terminal (grant-per-act has no re-connect parking), 5xx/network retry next tick.
 *
 * Test seam: $driver_factory.
 *
 * @version 2.1 - BORN CONFIGURED (specs/relay_without_a_shell.md WP3). ready creates the
 *                instance with first-boot user-data (the run's bundle copy, a one-time
 *                run token, this deployment's client public key); booting polls;
 *                provisioning WAITS for the birth report, and a relay that has not
 *                reported by the boot timeout is failed and its instance destroyed.
 *                An update drains over the API, then re-images the same instance with
 *                fresh user-data. The SSH leg is gone: no per-run key, no scp, no
 *                remote script, no root password recorded. When server_manager is
 *                active the born relay is a ManagedNode in the disposable posture
 * @version 2.0 - completeBirth(): a relay born from user-data reports in over HTTPS
 *                (specs/relay_without_a_shell.md); the plane pins its identity only
 *                after the provider's address answers a pinned ping, then writes the
 *                row, pushes the map as the GATE, requests reverse DNS and marks the
 *                run done. registerBornRelay() is the row for such a relay: an
 *                identity pin and a public address, no tunnel, no ssh fields
 * @version 1.9 - the provisioning bundle carries provisioning/bin/, the prebuilt
 *                relay-sealer binaries, and no longer carries the sealer's Go source.
 *                The relay does not install a Go toolchain or compile anything;
 *                provision_relay.sh 2.9 consumes one of these binaries and refuses to
 *                proceed without it, so a bundle that omitted bin/ would fail every
 *                run half an hour in.
 * @version 1.8 - completion clears the map hash and force-pushes the fragment:
 *                a rebuilt relay holds no tenant map, and the hash-skip read the
 *                unchanged fragment as delivered — leaving the relay blank (no
 *                alias routing, no Direct) until a domain change happened to
 *                force a push.
 * @version 1.7 - the instance image is the INSTANCE_IMAGE constant. It was read
 *                from a setting no manifest declared, so no admin could reach
 *                it and the fallback was the only value it ever had.
 * @version 1.6 - the 'upgrade' kind: drain the relay, rebuild the instance in
 *                place (address preserved), rebuild it from the same script. A
 *                failed upgrade never destroys the instance — it is the
 *                customer's working relay, not this run's to throw away.
 *                specs/mailbox_relay_upgrade_without_server_manager.md
 * @version 1.5 - records the relay's authserv-id alongside its MX hostname
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayClient.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));

/** A birth report the plane will not act on. The message is safe to answer with. */
class RelayBirthRefused extends Exception {}

class RelayCloudProvisioner {

	const BOOT_TIMEOUT_SECONDS = 1800; // instance create -> running + IP
	// running + IP -> the birth report. The build installs packages on a fresh
	// image and posts once sshd is gone; a relay silent for this long after its
	// boot is not coming.
	const BIRTH_TIMEOUT_SECONDS = 1800;
	// The run token outlives both windows, so a report that arrives late but
	// inside the birth window never finds its token already expired.
	const RUN_TOKEN_TTL_SECONDS = self::BOOT_TIMEOUT_SECONDS + self::BIRTH_TIMEOUT_SECONDS + 600;
	// The private StackScript the plane keeps on the account for regions whose
	// Metadata service cannot carry user-data.
	const STACKSCRIPT_LABEL = 'joinery-relay-first-boot';
	const LABEL_MAX_LENGTH = 64;       // provider cap on an instance label (Linode)
	// Drain passes allowed before an upgrade gives up. Each is a network round
	// trip, so this is a bound on one cron tick, not a patience setting: a spool
	// still filling after this many passes is not draining, and the run stops
	// rather than wiping mail it never collected.
	const DRAIN_MAX_PASSES = 12;
	// The OS image every relay is built from. Deliberately a constant and not a
	// setting: which release the relay runs is a property of what
	// provisioning/provision_relay.sh is known to produce, not a per-deployment
	// preference. When a newer image is qualified, changing it here moves every
	// deployment's next relay at once — a setting would let each one drift, and
	// a relay built from an unqualified image fails half an hour later as
	// "provision_relay.sh did not complete" with no hint that the image was why.
	const INSTANCE_IMAGE = 'linode/ubuntu26.04';

	/** @var callable|null fn(RelayCloudProvision): CloudComputeProvider — test seam. */
	public static $driver_factory = null;


	/** Advance one run a single step. Returns a short human status line. */
	public function advance(RelayCloudProvision $run): string {
		switch ((string)$run->get('rcp_status')) {
			case 'ready':
				// An upgrade has an instance already; what it needs first is for
				// the relay to be empty, because the wipe takes the spool with it.
				return $run->isUpgrade() ? $this->handleDraining($run) : $this->handleReady($run);
			case 'draining':
				return $this->handleDraining($run);
			case 'rebuilding':
				return $this->handleRebuilding($run);
			case 'booting':
				return $this->handleBooting($run);
			case 'provisioning':
				// The relay is building itself and will report in over HTTPS
				// (RelayBirthEndpoint -> completeBirth). Nothing to do but wait,
				// and give up when it has been too long.
				return $this->awaitBirth($run);
			default:
				return 'nothing to do';
		}
	}

	/**
	 * The provider-side instance label.
	 *
	 * Cosmetic to the platform — every lookup, destroy and reverse-DNS call keys
	 * on the numeric instance id, never on this — but it is the ONLY thing
	 * naming a box in the provider's dashboard, so it carries the relay's mail
	 * hostname instead of a bare counter that says nothing about what the
	 * machine is or whether it is the live one.
	 *
	 * The run id stays on the end because a rebuild creates the replacement
	 * while the predecessor is still running (retiring the old box is a
	 * deliberate manual act), and provider labels must be unique within an
	 * account: a bare hostname would collide and fail the create on every
	 * rotation. Hostname for humans, suffix for the provider.
	 */
	public static function instanceLabel(string $mail_hostname, int $run_id): string {
		$suffix = '-' . $run_id;
		$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($mail_hostname)));
		$slug = trim((string)$slug, '-');
		if ($slug === '') {
			return 'joinery-relay' . $suffix;
		}
		// Providers cap label length (Linode: 64). The suffix is what guarantees
		// uniqueness, so it is kept whole and the readable half gives way.
		$max = self::LABEL_MAX_LENGTH - strlen($suffix);
		if (strlen($slug) > $max) {
			$slug = rtrim(substr($slug, 0, $max), '-');
		}
		return $slug . $suffix;
	}

	// ------------------------------------------------------------ transitions

	/**
	 * ready -> booting: create the instance on the customer's account, born
	 * configured. The user-data carries the plane's URL, this run's id and
	 * one-time token, the sha256 of the run's own bundle copy, the mail
	 * hostname and this deployment's relay client public key - public keys and
	 * a token that dies with the boot, nothing else. No SSH key is installed and
	 * no root password is recorded: the platform holds no credential for the
	 * machine, and never will.
	 */
	private function handleReady(RelayCloudProvision $run): string {
		try {
			$first_boot = $this->prepareFirstBoot($run);
		} catch (\Throwable $e) {
			// Nothing was created at the provider; the run's copy, if any, goes.
			$run->eraseBundle();
			$run->fail('Could not prepare the relay\'s first boot: ' . $e->getMessage());
			return 'first boot not prepared - failed';
		}

		$driver = $this->driverFor($run);
		try {
			$opts = array(
				'label'  => self::instanceLabel((string)$run->get('rcp_mail_hostname'), intval($run->key)),
				'region' => (string)$run->get('rcp_region'),
				'type'   => (string)$run->get('rcp_instance_type'),
				'image'  => self::INSTANCE_IMAGE,
			);
			$instance = $driver->createInstance($opts + $this->firstBootOptions($driver, (string)$run->get('rcp_region'), $first_boot));
		} catch (CloudComputeException $e) {
			return $this->handleComputeFailure($run, $e, 'create');
		}

		$run->set('rcp_instance_id', (string)$instance['id']);
		$run->set('rcp_instance_ip', (string)$instance['ip']);
		$run->set('rcp_status', 'booting');
		$run->set('rcp_error', null);
		$run->save();
		return 'instance created with first-boot user-data, booting';
	}

	/**
	 * Everything a birth needs from this side, staged on the run: the bundle
	 * copy and its hash, a fresh one-time token, and the rendered first-boot
	 * script. Returns ['user_data' => rendered script, 'fields' => the values]
	 * so the caller can hand either form to the provider.
	 */
	private function prepareFirstBoot(RelayCloudProvision $run): array {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayFirstBoot.php'));
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_client_identity_class.php'));
		$plane = rtrim((string)LibraryFunctions::get_absolute_url(), '/');
		if ($plane === '' || stripos($plane, 'https://') !== 0) {
			throw new RuntimeException('this deployment has no https URL for the relay to fetch its bundle from and report to');
		}
		$sha = $run->copyBundle();
		$token = $run->issueRunToken(self::RUN_TOKEN_TTL_SECONDS);
		$run->save();
		$mail_hostname = strtolower(trim((string)$run->get('rcp_mail_hostname')));
		$fields = array(
			'plane'             => $plane,
			'run_id'            => (string)$run->key,
			'run_token'         => $token,
			'bundle_sha256'     => $sha,
			'mail_hostname'     => $mail_hostname,
			'authserv_id'       => $mail_hostname,
			'client_public_key' => RelayClientIdentity::publicKey(RelayClientIdentity::KIND_CLIENT),
			'skeleton_only'     => '0',
		);
		return array('user_data' => RelayFirstBoot::render($fields), 'fields' => $fields);
	}

	/**
	 * The provider options that carry the first boot: user_data where the
	 * region's Metadata service takes it, the plane's private StackScript with
	 * the same fields as UDF values where it does not. Neither is a provider
	 * process of its own; both are fields of the create or rebuild call.
	 */
	private function firstBootOptions(CloudComputeProvider $driver, string $region, array $first_boot): array {
		$metadata = true;
		if ($driver instanceof LinodeComputeDriver) {
			try {
				$metadata = $driver->regionSupportsMetadata($region);
			} catch (\Throwable $e) {
				// Unknown: try the Metadata service, which most regions have; a
				// region that lacks it ignores user_data and the birth times out
				// with the provider's console log saying why.
				$metadata = true;
			}
		}
		if ($metadata) {
			return array('user_data' => $first_boot['user_data']);
		}
		if (!($driver instanceof LinodeComputeDriver)) {
			return array('user_data' => $first_boot['user_data']);
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayFirstBoot.php'));
		$id = $driver->ensureStackScript(self::STACKSCRIPT_LABEL, RelayFirstBoot::stackScript(), array(self::INSTANCE_IMAGE));
		$data = array();
		foreach ($first_boot['fields'] as $name => $value) {
			$data[strtoupper($name)] = (string)$value;
		}
		return array('stackscript_id' => $id, 'stackscript_data' => $data);
	}

	/**
	 * UPGRADE ONLY. ready|draining -> rebuilding: empty the relay's spool first.
	 *
	 * The wipe destroys every byte on the machine, and sealed blobs the platform
	 * has not pulled yet are mail nobody else has a copy of. So the drain is a
	 * GATE, not a courtesy: this method refuses to advance unless the spool is
	 * genuinely empty.
	 *
	 * Two refusals, both deliberate:
	 *
	 *   held > 0     A held blob is one whose owner is not yet resolvable, left
	 *                un-acked on purpose so a later pull can store it. Those are
	 *                exactly the blobs a wipe would destroy silently, and they are
	 *                held because something about them is unresolved. The customer
	 *                fixes the cause or waits for the grace-window age-out.
	 *   no progress  Successive passes that move nothing but leave entries behind
	 *                mean the drain cannot finish. An upgrade is elective; losing
	 *                mail to an elective act is not a trade to make on the
	 *                customer's behalf.
	 */
	private function handleDraining(RelayCloudProvision $run): string {
		$relay = $run->relay();
		if ($relay === null) {
			$run->fail('The relay this upgrade targets no longer exists.');
			return 'relay gone — failed';
		}

		// THE WIPE GUARD, checked live rather than from a cached answer, because a
		// tenant can have been added since the relay last spoke. A rebuild destroys
		// every other tenant's account, allowlist, WireGuard peer and un-pulled
		// mail — and the drain above empties only THIS tenant's spool, so nothing
		// else is preserved even in passing. The deployment asking can see only its
		// own tenancy, so the relay is asked, and only an explicit TRUE proceeds.
		$health = $relay->pollHealth();
		if (($health['sole'] ?? null) === false) {
			$run->fail('This relay serves other deployments as well as this one. Rebuilding it would '
				. 'destroy their mail and their configuration, so it has been stopped. Nothing has been '
				. 'changed on the relay.');
			return 'refused: relay is shared';
		}

		if ((string)$run->get('rcp_status') !== 'draining') {
			$run->set('rcp_status', 'draining');
			$run->set('rcp_error', null);
			$run->save();
		}

		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));
		$consumer = new RelaySpoolConsumer($relay);

		// Bounded: each pass is a network round trip, and an unbounded loop inside
		// one cron tick would hold the pass open indefinitely. Anything still left
		// after this many passes is not draining, it is stuck.
		$passes = 0;
		$last_remaining = null;
		while ($passes < self::DRAIN_MAX_PASSES) {
			$passes++;
			$result = $consumer->pull();
			$status = (string)($result['status'] ?? 'error');

			if ($status === 'error') {
				$run->set('rcp_error', mb_substr('Drain failed: ' . (string)($result['message'] ?? ''), 0, 4000));
				$run->save();
				return 'drain error — will retry next pass';
			}
			if ($status === 'skipped') {
				$run->set('rcp_error', 'The relay has no tunnel address, so its spool cannot be drained before the wipe.');
				$run->save();
				return 'drain skipped — no tunnel';
			}

			$held      = intval($result['held'] ?? 0);
			$seals     = intval($result['seals'] ?? 0);
			$remaining = $held + intval($result['errors'] ?? 0);

			if ($held > 0) {
				$run->fail($held . ' message(s) on the relay cannot be stored here yet, and a rebuild would '
					. 'destroy them. They are held because their domain is disabled or unconfigured, or their '
					. 'owner cannot be resolved. Fix that — or wait for the grace window to expire them — and '
					. 'start the upgrade again.');
				return 'drain blocked: ' . $held . ' held';
			}
			if ($seals === 0) {
				break; // The relay had nothing left. Safe to wipe.
			}
			if ($last_remaining !== null && $remaining >= $last_remaining && $remaining > 0) {
				$run->fail('The relay\'s spool is not emptying (' . $remaining . ' entr(y/ies) stuck across '
					. 'successive passes), and a rebuild would destroy what is left. Nothing has been changed '
					. 'on the relay.');
				return 'drain stalled — failed';
			}
			$last_remaining = $remaining;
		}

		if ($passes >= self::DRAIN_MAX_PASSES) {
			$run->fail('The relay still had mail after ' . self::DRAIN_MAX_PASSES . ' drain passes. Nothing '
				. 'has been changed on the relay; try the upgrade again when it is quieter.');
			return 'drain did not finish — failed';
		}

		$run->set('rcp_status', 'rebuilding');
		$run->set('rcp_error', null);
		$run->save();
		return 'relay drained in ' . $passes . ' pass(es) — rebuilding';
	}

	/**
	 * UPDATE ONLY. rebuilding -> booting: re-image the same instance with fresh
	 * user-data and a fresh run token. The instance and its public IPv4 survive,
	 * which is the whole point: the address is what an MX record points at, so
	 * an update is minutes of downtime while a destroy-and-create would be a
	 * DNS change. The relay is born again from the current bundle and reports
	 * in with a new identity; the birth writes the new pin on the same row.
	 */
	private function handleRebuilding(RelayCloudProvision $run): string {
		$instance_id = (string)$run->get('rcp_instance_id');
		if ($instance_id === '') {
			$run->fail('This update has no provider instance to re-image.');
			return 'no instance - failed';
		}
		try {
			$first_boot = $this->prepareFirstBoot($run);
		} catch (\Throwable $e) {
			$run->eraseBundle();
			$run->fail('Could not prepare the relay\'s first boot: ' . $e->getMessage());
			return 'first boot not prepared - failed';
		}

		$driver = $this->driverFor($run);
		try {
			$instance = $driver->rebuildInstance($instance_id,
				array('image' => self::INSTANCE_IMAGE)
				+ $this->firstBootOptions($driver, (string)$run->get('rcp_region'), $first_boot));
		} catch (CloudComputeException $e) {
			return $this->handleComputeFailure($run, $e, 'rebuild');
		}

		if (!empty($instance['ip'])) {
			$run->set('rcp_instance_ip', (string)$instance['ip']);
		}
		$run->set('rcp_status', 'booting');
		$run->set('rcp_error', null);
		$run->save();
		return 'instance re-imaging with fresh user-data, booting';
	}

	/** booting -> provisioning: the instance runs; now its first boot builds it. */	/** booting -> provisioning (and straight into the SSH build). */
	private function handleBooting(RelayCloudProvision $run): string {
		$driver = $this->driverFor($run);
		try {
			$instance = $driver->getInstance((string)$run->get('rcp_instance_id'));
		} catch (CloudComputeException $e) {
			return $this->handleComputeFailure($run, $e, 'boot-poll');
		}

		if ($instance['status'] !== 'running' || $instance['ip'] === '') {
			$since = $run->get('rcp_update_time') ?: $run->get('rcp_create_time');
			if ($since && (time() - strtotime($since . ' UTC')) > self::BOOT_TIMEOUT_SECONDS) {
				$this->destroyInstanceQuietly($run);
				$run->fail('Instance did not reach running with a public IP within '
					. intval(self::BOOT_TIMEOUT_SECONDS / 60) . ' minutes (status: ' . $instance['status'] . ').');
				return 'boot timeout — failed';
			}
			return 'still booting';
		}

		$run->set('rcp_instance_ip', (string)$instance['ip']);
		$run->set('rcp_status', 'provisioning');
		$run->save();
		return 'boot complete - waiting for the relay to build itself and report in';
	}

	/**
	 * Advance only the CHEAP transitions (create the instance, poll the boot)
	 * - safe inside a page load, so the Setup page moves the run along while
	 * the admin watches. The birth wait stays with the scheduled task.
	 */
	public function advanceCheap(RelayCloudProvision $run): string {
		switch ((string)$run->get('rcp_status')) {
			case 'ready':
				// PROVISION ONLY. handleReady() CREATES an instance, which for an
				// update would leave the customer paying for a second machine
				// while their relay carried on untouched. An update's first step
				// is the drain, which is a loop of network round trips and has no
				// business inside a page load.
				return $run->isUpgrade() ? 'nothing cheap to do' : $this->handleReady($run);
			case 'rebuilding':
				// One API call, same cost as creating an instance.
				return $this->handleRebuilding($run);
			case 'booting':
				return $this->handleBooting($run);
			default:
				return 'nothing cheap to do';
		}
	}

	/**
	 * provisioning: the relay is building itself from the bundle it fetched and
	 * will post its birth report to RelayBirthEndpoint, which completes the run
	 * through completeBirth(). This side only keeps time: a relay that has not
	 * reported by the birth timeout is failed and its instance destroyed within
	 * the grant, as a boot timeout is. Its console log at the provider says why.
	 */
	private function awaitBirth(RelayCloudProvision $run): string {
		$since = $run->get('rcp_update_time') ?: $run->get('rcp_create_time');
		if ($since && (time() - strtotime($since . ' UTC')) > self::BIRTH_TIMEOUT_SECONDS) {
			$this->destroyInstanceQuietly($run);
			$run->eraseBundle();
			$run->fail('The relay did not report in within ' . intval(self::BIRTH_TIMEOUT_SECONDS / 60)
				. ' minutes of booting. Its first-boot log at the provider says what stopped it.');
			return 'birth timeout - failed';
		}
		return 'waiting for the birth report';
	}

	// ------------------------------------------------------------------- birth

	/**
	 * A relay born from user-data has reported in. RelayBirthEndpoint has
	 * already checked the token, the address and the report's own signature;
	 * this is steps 3 and 4 of the spec's birth sequence, and it is where the
	 * plane first TRUSTS the identity:
	 *
	 *   3. a pinned GET /relay/ping to the provider's address with the reported
	 *      fingerprint - only when that answers is the pin written, the row
	 *      updated, the map hash cleared and the fragment pushed. The push is
	 *      the gate: a run whose fragment did not land is not done.
	 *   4. reverse DNS is requested, the run is done, its credentials erased.
	 *
	 * Throws RelayBirthRefused with a plain reason when the relay cannot be
	 * believed or the map did not land; the run stays where it was, so the
	 * relay's retries (the first-boot script posts six times) get another go.
	 *
	 * @param array $report the verified report: run_id, public_ip, identity_public_key,
	 *                      identity_fingerprint, relay_version, postfix, listener_443
	 */
	public function completeBirth(RelayCloudProvision $run, array $report): MailboxRelay {
		$public_ip = trim((string)$report['public_ip']);
		$fingerprint = trim((string)$report['identity_fingerprint']);
		$mail_hostname = strtolower(trim((string)$run->get('rcp_mail_hostname')));

		// 3a. Does the machine at the provider's address hold the key the report
		//     carried? Signed as tenant main with this deployment's client identity,
		//     which the user-data put in the relay's registry.
		$probe = new RelayClient($public_ip, $fingerprint, 'main', RelayClientIdentity::KIND_CLIENT);
		try {
			$ping = $probe->ping();
		} catch (RelayClientException $e) {
			throw new RelayBirthRefused('The relay at ' . $public_ip . ' did not answer a pinned ping ('
				. $e->failure_class . '): ' . $e->getMessage());
		}
		$reported = (string)($ping['identity']['identity_fingerprint'] ?? '');
		if ($reported !== '' && $reported !== $fingerprint) {
			throw new RelayBirthRefused('The relay answered with a different identity than its report carried.');
		}

		// 3b. The row: the pin and the address. Nothing about a tunnel.
		$had_row = $this->existingRowFor($run) !== null;
		$relay = $this->registerBornRelay($run, $public_ip, $fingerprint, (string)$report['identity_public_key'], $mail_hostname);

		// 3c. The map push is the gate. A relay with no fragment routes nothing,
		//     and the hash-skip would read an unchanged fragment as delivered.
		$relay->set('mrl_map_content_hash', null);
		$relay->save();
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapSync.php'));
		$push = RelayMapSync::push($relay, true);
		if (($push['status'] ?? '') !== 'success') {
			// A refused birth leaves no relay behind: a row minted for this
			// report is removed, so the deployment does not gain an enabled relay
			// nothing has routed to, and the relay's next report starts clean. An
			// upgrade's existing row keeps the new pin - the machine did answer.
			if (!$had_row) {
				$relay->permanent_delete();
			}
			throw new RelayBirthRefused('The relay answered, but the address list did not land on it: '
				. (string)($push['message'] ?? 'unknown reason'));
		}

		// The relay's health, from the ping already in hand: stored so the Setup
		// tab reads the born relay's state the moment the run finishes.
		try {
			$relay->pollHealth();
		} catch (\Throwable $e) {
			error_log('RelayCloudProvisioner: post-birth health poll failed: ' . $e->getMessage());
		}

		// 4. Reverse DNS through the provider API. Providers require the forward
		//    A record first, which is usually unpublished at this moment - a
		//    refusal is expected and the Setup tab's PTR check carries the
		//    instruction from here.
		if ((string)$run->get('rcp_instance_id') !== '') {
			try {
				$this->driverFor($run)->setReverseDns((string)$run->get('rcp_instance_id'), $public_ip, $mail_hostname);
			} catch (\Throwable $e) {
				error_log('RelayCloudProvisioner: setReverseDns deferred (' . $e->getMessage()
					. ') - expected until the mail hostname A record resolves.');
			}
		}

		// Server Manager shows the relay when it is active: a ManagedNode in the
		// DISPOSABLE posture - no agent, no key path, no SSH fields - monitored
		// by the plane-side tcp/25 probe, with Update and Delete as its only acts.
		$this->attachManagedNode($relay);

		$run->spendRunToken();
		$run->set('rcp_mrl_mailbox_relay_id', intval($relay->key));
		$run->set('rcp_status', 'done');
		$run->set('rcp_error', null);
		$run->eraseCredentials();
		$run->save();
		return $relay;
	}

	/**
	 * The relay as a ManagedNode, when server_manager is active. Disposable:
	 * mgn_is_relay, no agent, no key path, tcp/25 uptime probe, app health
	 * checks skipped. Reuses the node the row already names; otherwise a fresh
	 * one under the mail hostname. Never a reason to refuse a birth: a failure
	 * here is logged and the relay is complete without its dashboard card.
	 */
	public function attachManagedNode(MailboxRelay $relay): void {
		if (!PluginHelper::isPluginActive('server_manager') || !class_exists('ManagedNode')) {
			return;
		}
		try {
			$node = null;
			$node_id = intval($relay->get('mrl_mgn_managed_node_id'));
			if ($node_id > 0) {
				$node = new ManagedNode($node_id, TRUE);
				if (!$node->key || (string)$node->get('mgn_delete_time') !== '') {
					$node = null;
				}
			}
			$hostname = (string)$relay->get('mrl_mx_hostname') ?: (string)$relay->get('mrl_name');
			if ($node === null) {
				$node = new ManagedNode(NULL);
				$node->set('mgn_name', substr('Relay ' . $hostname, 0, 100));
				$node->set('mgn_slug', class_exists('AgentChannelEndpoint')
					? AgentChannelEndpoint::freeSlug('relay-' . $hostname)
					: substr('relay-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($hostname)) . '-' . intval($relay->key), 0, 50));
			}
			$node->set('mgn_host', substr((string)$relay->get('mrl_public_ip'), 0, 255));
			$node->set('mgn_is_relay', true);
			$node->set('mgn_skip_joinery_checks', true);
			$node->set('mgn_enabled', true);
			$node->set('mgn_ssh_key_path', null);
			$node->set('mgn_uptime_enabled', true);
			$node->set('mgn_uptime_check_type', 'tcp_port');
			$node->set('mgn_uptime_tcp_port', 25);
			$node->set('mgn_notes', 'Disposable relay (specs/relay_without_a_shell.md): no shell, no agent. '
				. 'Its only acts are Update and Delete on the mailbox Setup tab.');
			$node->save();
			$relay->set('mrl_mgn_managed_node_id', intval($node->key));
			$relay->save();
		} catch (\Throwable $e) {
			error_log('RelayCloudProvisioner: could not attach a ManagedNode to relay ' . intval($relay->key)
				. ': ' . $e->getMessage());
		}
	}

	/**
	 * The MailboxRelay row for a relay without a shell. Reuses the row an
	 * upgrade names, or the row whose instance id this is (never a second row
	 * for one machine); otherwise a fresh one, born enabled. Carries the
	 * identity pin and the public address; none of the tunnel or ssh fields,
	 * which stay empty and so keep every RelaySsh path off this row.
	 */
	public function registerBornRelay(RelayCloudProvision $run, string $public_ip, string $fingerprint,
			string $identity_public_key, string $mail_hostname): MailboxRelay {
		$relay = $this->existingRowFor($run);
		if ($relay === null) {
			$relay = new MailboxRelay(NULL);
			// Born enabled: it starts pulling and receives its address list
			// immediately, so it is ready before any MX points at it.
			$relay->set('mrl_is_enabled', true);
		}
		$relay->set('mrl_name', $mail_hostname);
		$relay->set('mrl_public_ip', substr($public_ip, 0, 64));
		$relay->set('mrl_identity_fingerprint', substr($fingerprint, 0, 64));
		$relay->set('mrl_identity_public_key', substr($identity_public_key, 0, 64));
		$relay->set('mrl_tenant_slug', 'main');
		$relay->set('mrl_mx_hostname', substr($mail_hostname, 0, 255));
		$relay->set('mrl_authserv_id', substr($mail_hostname, 0, 255));
		$relay->set('mrl_spool_path', '/var/spool/joinery-relay/main');
		// An updated relay is a new machine with a new identity: the pin above
		// replaced the old one, and any tunnel-era fields a predecessor row
		// carried are cleared so nothing reads this row as a tunnel relay.
		foreach (array('mrl_host', 'mrl_ssh_user', 'mrl_ssh_key_path', 'mrl_wg_public_key', 'mrl_wg_endpoint', 'mrl_wg_ip') as $field) {
			$relay->set($field, null);
		}
		$relay->set('mrl_last_health_failure', null);
		$relay->set('mrl_cloud_provider', (string)$run->get('rcp_provider'));
		$relay->set('mrl_cloud_instance_id', (string)$run->get('rcp_instance_id'));
		$relay->save();
		$relay->ensureTransportKeypair();
		return $relay;
	}

	/**
	 * The relay row a run already belongs to: the one an upgrade names, or the
	 * one carrying this run's instance id. Null for a first birth.
	 */
	private function existingRowFor(RelayCloudProvision $run): ?MailboxRelay {
		$relay = $run->isUpgrade() ? $run->relay() : null;
		if ($relay === null && (string)$run->get('rcp_instance_id') !== '') {
			$existing = new MultiMailboxRelay(array('deleted' => false));
			foreach ($existing as $row) {
				if ((string)$row->get('mrl_cloud_instance_id') === (string)$run->get('rcp_instance_id')) {
					return $row;
				}
			}
		}
		return $relay;
	}

	// --------------------------------------------------------------- plumbing

	/**
	 * Compute-API failure policy (grant-per-act variant): 401 and other 4xx
	 * are terminal (a fresh retry brings a fresh grant); 5xx/network stay put
	 * and retry next tick. A terminal failure cleans up any created instance.
	 */
	private function handleComputeFailure(RelayCloudProvision $run, CloudComputeException $e, string $phase): string {
		$code = (int)$e->getCode();
		if ($code >= 400 && $code < 500 && $code !== 429) {
			$this->failWithCleanup($run, 'Provider API error during ' . $phase . ': ' . $e->getMessage());
			return $phase . ' failed';
		}
		$run->set('rcp_error', mb_substr('Transient (' . $phase . '): ' . $e->getMessage(), 0, 4000));
		$run->save();
		return $phase . ' transient error — will retry';
	}

	/** Terminal failure: destroy any created instance within the grant, then fail. */
	private function failWithCleanup(RelayCloudProvision $run, string $message): void {
		$this->destroyInstanceQuietly($run);
		$run->fail($message);
	}

	private function destroyInstanceQuietly(RelayCloudProvision $run): void {
		// An UPGRADE's instance is the customer's existing, working relay — this
		// run did not create it and has no business deleting it. Every cleanup
		// path funnels through here, so the refusal lives here rather than being
		// repeated (and eventually missed) at each call site.
		if ($run->isUpgrade()) {
			return;
		}
		$instance_id = (string)$run->get('rcp_instance_id');
		if ($instance_id === '') {
			return;
		}
		try {
			$this->driverFor($run)->deleteInstance($instance_id);
		} catch (\Throwable $e) {
			error_log('RelayCloudProvisioner: cleanup deleteInstance failed for run '
				. intval($run->key) . ': ' . $e->getMessage()
				. ' — the instance may still exist on the customer account.');
		}
	}

	private function driverFor(RelayCloudProvision $run): CloudComputeProvider {
		if (self::$driver_factory !== null) {
			return call_user_func(self::$driver_factory, $run);
		}
		$provider = (string)$run->get('rcp_provider');
		if ($provider !== 'linode') {
			throw new RelayCloudProvisionException('Unknown cloud provider "' . $provider . '".');
		}
		$token = $run->unsealToken();
		if ($token === '') {
			throw new RelayCloudProvisionException('The run holds no provider token — restart it to re-grant.');
		}
		return new LinodeComputeDriver($token);
	}

}
?>
