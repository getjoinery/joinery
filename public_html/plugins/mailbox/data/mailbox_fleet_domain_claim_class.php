<?php
/**
 * MailboxFleetDomainClaim - a tenant's ownership claim on a mail domain.
 *
 * (specs/mailbox_relay_shared_fleet.md § Enrollment step 2). Domain-ownership
 * verification is a SECURITY BOUNDARY, not bookkeeping: without it, tenant A
 * claims tenant B's domain and the fleet delivers B's mail into A's sealed
 * spool. The claim is a DNS TXT challenge (_joinery-fleet-challenge.<domain>
 * = the token) with FLEET-WIDE uniqueness; on success the fleet service
 * writes the domain into the tenant's shard-side allowlist (a set-domains
 * job), which the map merge then enforces on every subsequent sync — the
 * claim is checked continuously, not only at enrollment.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailboxFleetDomainClaimException extends SystemBaseException {}

class MailboxFleetDomainClaim extends SystemBase {
	public static $prefix = 'mfd';
	public static $tablename = 'mfd_mailbox_fleet_domain_claims';
	public static $pkey_column = 'mfd_mailbox_fleet_domain_claim_id';

	const STATUS_PENDING  = 'pending';
	const STATUS_VERIFIED = 'verified';
	const STATUS_REVOKED  = 'revoked';

	/** The TXT record's host label, prepended to the claimed domain. */
	const CHALLENGE_LABEL = '_joinery-fleet-challenge';

	protected static $foreign_key_actions = [
		'mfd_mft_slot_id' => ['action' => 'cascade'],
	];

	public static $field_specifications = array(
		'mfd_mailbox_fleet_domain_claim_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mfd_mft_slot_id'  => array('type'=>'int8', 'is_nullable'=>false),
		'mfd_domain'       => array('type'=>'varchar(255)', 'is_nullable'=>false),
		'mfd_txt_token'    => array('type'=>'varchar(80)'),
		'mfd_status'       => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>'pending'),
		'mfd_verify_time'  => array('type'=>'timestamp(6)'),
		'mfd_create_time'  => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mfd_update_time'  => array('type'=>'timestamp(6)'),
		'mfd_delete_time'  => array('type'=>'timestamp(6)'),
	);

	// Row scope: no owner column, so the SystemBase default is staff-only —
	// correct for the REST surface. The fleet_* actions mediate customer access
	// (a claim is reachable only through the customer's own slot).

	function prepare() {
		$this->set('mfd_update_time', gmdate('Y-m-d H:i:s'));
	}

	/** The full TXT record host the tenant must create. */
	public function challengeHost(): string {
		return self::CHALLENGE_LABEL . '.' . strtolower(trim((string)$this->get('mfd_domain')));
	}

	/**
	 * Fleet-wide uniqueness: the live claim (pending or verified) held on a
	 * domain by any OTHER slot, or null. Both creation and verification check
	 * this — a domain can only ever be claimed into one tenant's spool.
	 */
	public static function liveClaimByOtherSlot(string $domain, int $slot_id): ?MailboxFleetDomainClaim {
		$multi = new MultiMailboxFleetDomainClaim(array('domain' => $domain, 'live' => true, 'deleted' => false));
		$multi->load();
		foreach ($multi as $claim) {
			if (intval($claim->get('mfd_mft_slot_id')) !== $slot_id) {
				return $claim;
			}
		}
		return null;
	}
}

class MultiMailboxFleetDomainClaim extends SystemMultiBase {
	protected static $model_class = 'MailboxFleetDomainClaim';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['slot_id'])) {
			$filters['mfd_mft_slot_id'] = [intval($this->options['slot_id']), PDO::PARAM_INT];
		}

		if (isset($this->options['domain'])) {
			$filters['mfd_domain'] = [strtolower(trim((string)$this->options['domain'])), PDO::PARAM_STR];
		}

		if (isset($this->options['status'])) {
			$filters['mfd_status'] = [(string)$this->options['status'], PDO::PARAM_STR];
		}

		if (!empty($this->options['live'])) {
			$filters['mfd_status'] = "IN ('pending', 'verified')";
		}


		return $this->_get_resultsv2('mfd_mailbox_fleet_domain_claims', $filters, $this->order_by, $only_count, $debug);
	}
}
