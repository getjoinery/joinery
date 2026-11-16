<?php
/**
 * FleetProvisionSeeding - order-time fleet enrollment seeding for
 * customer-cloud provisions (specs/mailbox_relay_shared_fleet.md § Follow-up).
 *
 * Runs on the OPERATOR deployment once a customer-cloud provision is done and
 * the new site's agent has paired. When the site's own plan grants it a slot
 * (a Starter site), or the buyer's tier carries the fleet-slot feature, the
 * tenant box gets the three fleet-service settings
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
 * @version 2.5 - seededElsewhere() skips a site by the key it holds (its own recorded key), not by what its plan
 *                would grant, so a Managed site seeded with the tier key still holds the tier slot (B11)
 * @version 2.4 - a hosted site whose plan grants it a slot (FleetService::site_plan_grants_slot) gets a key of
 *               its own, named for its provision and recorded on its provision; re-seeding it retires only that key, and
 *               no other site of the buyer's is refused or touched (hosting_launch S5). applies() and
 *               seedNode() take the provision
 * @version 2.3 - an agent that cannot be seeded is refused with the standard "needs a newer agent" sentence
 *               (AgentVocabulary), not an agent version number
 * @version 2.2 - a decommissioned site (its node soft-deleted) no longer holds the buyer's slot
 * @version 2.1 - one seeded site per buyer: seeding a second site is refused, naming the site that
 *                holds the slot, instead of silently revoking that site's credential
 * @version 2.0 - seeding is the fleet_enroll primitive over the agent channel; the SSH path is gone
 * @version 1.1 - keyless nodes: seed over the provision's sealed root password
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));

class FleetProvisionSeeding {

	/** apk_name of the buyer's tier-slot credential (one active per buyer); a site's own is siteKeyName(). */
	const KEY_NAME = 'Fleet enrollment';

	/** read + write, no delete — same axis the provisioning pipeline key uses. */
	const KEY_PERMISSION = 3;

	/** The job type the seeding travels as. */
	const JOB_TYPE = 'fleet_enroll';

	/**
	 * Whether a completed provision should be seeded: the fleet service is
	 * on, this deployment is its own store (a remote store's buyer ids are
	 * not local users, so entitlement cannot be read), and either the site's
	 * own plan grants it a slot or the buyer's tier carries the slot feature.
	 */
	public static function applies($provision): bool {
		$buyer_user_id = $provision ? (int)$provision->get('cvp_usr_user_id') : 0;
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
		return FleetService::site_plan_grants_slot($provision) || FleetService::entitled($buyer_user_id);
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
	public static function seedNode($node, $provision): array {
		try {
			if (!self::nodeReady($node)) {
				return array('ok' => false, 'job_id' => null, 'message' =>
					'No route to the node for fleet_enroll. ' . AgentVocabulary::needs_newer_agent_text($node, array('fleet_enroll'))
					. ' There is no SSH route for this.');
			}
			$buyer_user_id = (int)$provision->get('cvp_usr_user_id');

			if (FleetService::site_plan_grants_slot($provision)) {
				// The site's own slot: a key of its own, so no other site of
				// this buyer is touched.
				$key = self::mintSiteKey($provision);
			} else {
				// One tier slot per subscription, so one seeded site per buyer.
				// Minting for a second site would deactivate the key the first
				// site enrolled with — a silent takeover nobody asked for.
				// Refuse and say which site holds the slot; re-seeding THAT
				// site (a key rotation) is fine.
				$holder = self::seededElsewhere($node, $buyer_user_id);
				if ($holder !== null) {
					return array('ok' => false, 'job_id' => null, 'message' =>
						'This account\'s hosted relay slot is already seeded on ' . $holder
						. '. A subscription carries one slot, and seeding a second site would revoke the '
						. 'first site\'s credential. Not seeded.');
				}
				$key = self::mintTenantKey($buyer_user_id);
			}
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
	 * The domain of another provision of this buyer whose seeding of the
	 * buyer's tier slot is done on a node other than $node, or null when that
	 * slot is unclaimed (or claimed by this very node, which is a rotation,
	 * not a takeover). A site whose own plan grants its slot is not counted.
	 */
	public static function seededElsewhere($node, int $buyer_user_id): ?string {
		if (!class_exists('CustomerCloudProvision')) {
			return null;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT cvp_customer_cloud_provision_id FROM cvp_customer_cloud_provisions " .
			"WHERE cvp_usr_user_id = ? AND cvp_fleet_seed_state = 'done' AND cvp_delete_time IS NULL " .
			"AND cvp_mgn_managed_node_id IS NOT NULL AND cvp_mgn_managed_node_id <> ? " .
			// A site that has been decommissioned holds nothing: its node row is
			// soft-deleted and the credential it enrolled with has no site behind
			// it, so the slot is free for the account's next site.
			"AND EXISTS (SELECT 1 FROM mgn_managed_nodes WHERE mgn_managed_node_id = cvp_mgn_managed_node_id AND mgn_delete_time IS NULL) " .
			"ORDER BY cvp_customer_cloud_provision_id"
		);
		$q->execute(array($buyer_user_id, (int)$node->key));
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $provision_id) {
			// A site seeded with a key of its own holds a slot of its own and
			// no part of the tier's. Asked of what the site holds, not of
			// what its plan would grant: a site seeded with the buyer's tier
			// key before its plan granted one still holds the tier slot.
			if (intval((new CustomerCloudProvision((int)$provision_id, TRUE))->get('cvp_fleet_apk_api_key_id')) > 0) {
				continue;
			}
			return (string)(new CustomerCloudProvision((int)$provision_id, TRUE))->get('cvp_domain');
		}
		return null;
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
		return self::mintKey($buyer_user_id, self::KEY_NAME, null);
	}

	/**
	 * Mint (or re-mint) a hosted site's own fleet credential: retire the key
	 * recorded on its provision, if any, create a fresh machine key named for
	 * the site (siteKeyName), and record it on the provision. The buyer's other keys,
	 * another site's among them, are never touched.
	 */
	public static function mintSiteKey($provision): array {
		$name = self::siteKeyName($provision);
		$minted = self::mintKey((int)$provision->get('cvp_usr_user_id'), $name, (int)$provision->get('cvp_fleet_apk_api_key_id') ?: 0);
		$provision->set('cvp_fleet_apk_api_key_id', $minted['api_key_id']);
		$provision->save();
		return $minted;
	}

	/** A site's own key's name (apk_name holds 32): 'Fleet enrollment #<provision id>'. */
	public static function siteKeyName($provision): string {
		return FleetService::SITE_KEY_NAME_PREFIX . (int)$provision->key;
	}

	/**
	 * Create a machine key for the buyer. With $retire_id null, the buyer's
	 * earlier active keys of the same name are retired; otherwise only the
	 * key with that id (0: none).
	 */
	private static function mintKey(int $buyer_user_id, string $name, ?int $retire_id): array {
		require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

		$old_keys = new MultiApiKey(array('user_id' => $buyer_user_id));
		foreach ($old_keys as $old) {
			$retire = $retire_id === null
				? $old->get('apk_name') === $name
				: (int)$old->key === $retire_id;
			if ($retire && $old->get('apk_is_active')) {
				$old->set('apk_is_active', FALSE);
				$old->save();
			}
		}

		$public_key = 'public_' . LibraryFunctions::random_string(16);
		$secret_plaintext = 'secret_' . LibraryFunctions::random_string(16);

		$api_key = new ApiKey(NULL);
		$api_key->set('apk_usr_user_id', $buyer_user_id);
		$api_key->set('apk_name', $name);
		$api_key->set('apk_public_key', $public_key);
		$api_key->set('apk_secret_key', ApiKey::GenerateKey($secret_plaintext));
		$api_key->set('apk_type', ApiKey::TYPE_MACHINE);
		$api_key->set('apk_permission', self::KEY_PERMISSION);
		$api_key->set('apk_is_active', TRUE);
		$api_key->save();

		return array('public_key' => $public_key, 'secret_key' => $secret_plaintext, 'api_key_id' => (int)$api_key->key);
	}
}
