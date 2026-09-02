<?php
/**
 * FleetProvisionSeeding - order-time fleet enrollment seeding for
 * customer-cloud provisions (specs/mailbox_relay_shared_fleet.md § Follow-up).
 *
 * Runs on the OPERATOR deployment once a customer-cloud provision is done and
 * the new site's agent has paired. When the buyer's tier carries the
 * fleet-slot feature, the tenant box gets the three fleet-service settings
 * seeded (mailbox_fleet_service_url + the API key pair), so its owner lands
 * on a one-click Enroll in the mailbox Setup tab instead of pasting
 * credentials. The DNS TXT ownership challenges and the MX edit stay manual by
 * nature — the customer proving domain control at their own DNS provider.
 *
 * The API key is minted here for the buyer's own account (the fleet service
 * authenticates /api/v1 calls as that customer and gates on their tier). The
 * three values travel to the site as ONE JOB over the agent channel — the
 * fleet_enroll primitive, whose node-side script utils/fleet_enroll.php holds
 * the three setting names; the plane cannot say where the values land. The
 * secret rides the job row until the node answers, redacted on display, and
 * JobResultProcessor::process_fleet_enroll blanks it then. Nothing opens a
 * shell (specs/ssh_single_bootstrap.md WP4).
 *
 * The plane already mints this key, holds its hash, and is the API it
 * authenticates TO, so the job row is not a new holder of anything.
 *
 * @version 2.0 - seeding is the fleet_enroll primitive over the agent channel; the SSH path is gone
 * @version 1.1 - keyless nodes: seed over the provision's sealed root password
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

class FleetProvisionSeeding {

	/** apk_name of the minted tenant credential (one active per buyer). */
	const KEY_NAME = 'Fleet enrollment';

	/** read + write, no delete — same axis the provisioning pipeline key uses. */
	const KEY_PERMISSION = 3;

	/** The job type the seeding travels as. */
	const JOB_TYPE = 'fleet_enroll';

	/**
	 * Whether a completed provision for this buyer should be seeded: the
	 * fleet service is on, this deployment is its own store (a remote store's
	 * buyer ids are not local users, so entitlement cannot be read), and the
	 * buyer's tier carries the fleet-slot feature.
	 */
	public static function applies(int $buyer_user_id): bool {
		if ($buyer_user_id <= 0) {
			return false;
		}
		$settings = Globalvars::get_instance();
		if ((string)$settings->get_setting('mailbox_fleet_service_enabled') !== '1') {
			return false;
		}
		$store_url = rtrim(trim((string)$settings->get_setting('server_manager_getjoinery_api_url')), '/');
		$self_url = rtrim(LibraryFunctions::get_absolute_url('/'), '/');
		if ($store_url !== '' && $store_url !== $self_url) {
			return false;
		}
		return FleetService::entitled($buyer_user_id);
	}

	/**
	 * Can this node be seeded right now? True once its agent has paired and
	 * reports the fleet_enroll primitive. Checked BEFORE a key is minted, so a
	 * node still waiting on approval does not churn a fresh key every tick.
	 */
	public static function nodeReady($node): bool {
		return class_exists('JobCommandBuilder') && JobCommandBuilder::has_primitive($node, 'fleet_enroll');
	}

	/**
	 * Seed the fleet-service settings on a provisioned node: mint the buyer's
	 * API key and dispatch ONE fleet_enroll job carrying the three values.
	 * Returns ['ok' => bool, 'message' => string, 'job_id' => int|null];
	 * never throws.
	 */
	public static function seedNode($node, int $buyer_user_id): array {
		try {
			if (!self::nodeReady($node)) {
				return array('ok' => false, 'job_id' => null, 'message' =>
					'No route to the node: its agent has not paired with this plane, or does not offer '
					. 'the fleet_enroll primitive (agent 1.17.0 or later). There is no SSH route for this.');
			}

			$key = self::mintTenantKey($buyer_user_id);
			$service_url = rtrim(LibraryFunctions::get_absolute_url('/'), '/');

			$built = JobCommandBuilder::build_fleet_enroll($node, array(
				'service_url' => $service_url,
				'public_key'  => $key['public_key'],
				'secret_key'  => $key['secret_key'],
			));
			$job = ManagementJob::createFromBuild($node->key, self::JOB_TYPE, $built, array(), null);
			return array('ok' => true, 'job_id' => (int)$job->key,
				'message' => 'Fleet seeding dispatched to the node\'s agent (job #' . $job->key . ').');
		} catch (\Throwable $e) {
			return array('ok' => false, 'job_id' => null, 'message' => 'Fleet seeding failed: ' . $e->getMessage());
		}
	}

	/**
	 * What the latest seeding job for this node did.
	 * @return array{state:string,message:string} state is pending|seeded|failed|none
	 */
	public static function outcome($node): array {
		$job = ManagementJob::latestForNode($node->key, self::JOB_TYPE);
		if (!$job) {
			return array('state' => 'none', 'message' => 'No fleet seeding job exists for this node.');
		}
		$status = (string)$job->get('mjb_status');
		if ($status !== 'completed' && $status !== 'failed') {
			return array('state' => 'pending', 'message' => 'Fleet seeding job #' . $job->key . ' is ' . $status . '.');
		}
		if (!$job->get('mjb_result')) {
			JobResultProcessor::process($job);
			$job->load();
		}
		$result = json_decode((string)$job->get('mjb_result'), true);
		if (is_array($result) && !empty($result['seeded'])) {
			return array('state' => 'seeded', 'message' => 'Fleet settings seeded; the owner\'s Setup tab offers one-click Enroll.');
		}
		return array('state' => 'failed', 'message' => 'Fleet seeding job #' . $job->key . ' finished ' . $status
			. ' without seeding: ' . trim((string)($job->get('mjb_error_message') ?: 'see the job output')));
	}

	/**
	 * Mint (or re-mint) the buyer's fleet credential: deactivate this user's
	 * previous keys of the same name, create a fresh machine key. Returns
	 * ['public_key' => string, 'secret_key' => plaintext] — the plaintext
	 * exists only in this request and the job row that carries it.
	 */
	public static function mintTenantKey(int $buyer_user_id): array {
		require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

		$old_keys = new MultiApiKey(array('user_id' => $buyer_user_id));
		$old_keys->load();
		foreach ($old_keys as $old) {
			if ($old->get('apk_name') === self::KEY_NAME && $old->get('apk_is_active')) {
				$old->set('apk_is_active', FALSE);
				$old->save();
			}
		}

		$public_key = 'public_' . LibraryFunctions::random_string(16);
		$secret_plaintext = 'secret_' . LibraryFunctions::random_string(16);

		$api_key = new ApiKey(NULL);
		$api_key->set('apk_usr_user_id', $buyer_user_id);
		$api_key->set('apk_name', self::KEY_NAME);
		$api_key->set('apk_public_key', $public_key);
		$api_key->set('apk_secret_key', ApiKey::GenerateKey($secret_plaintext));
		$api_key->set('apk_type', ApiKey::TYPE_MACHINE);
		$api_key->set('apk_permission', self::KEY_PERMISSION);
		$api_key->set('apk_is_active', TRUE);
		$api_key->save();

		return array('public_key' => $public_key, 'secret_key' => $secret_plaintext);
	}
}
