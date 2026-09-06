<?php
/**
 * HostedTrial — where one hosted site stands with the operator that runs it.
 *
 * A hosted provision has a compute half (the instance, on the provision row)
 * and a commercial half: whether the subscription is paying, whether a trial
 * is still running where one was configured, and what happens next if a
 * payment fails. This is the commercial half, one row per provision.
 *
 * WHAT IS NOT HERE IS THE POINT (specs/hosted_trial_provisioning.md §8). There
 * are no meter columns. The shelf figure lives on the node row, where the
 * retention pass already measures it; sends are counted by the mail provider,
 * which is also what enforces them; disk is the figure the node's own status
 * check already reports; and outbound transfer is an account-wide pool with no
 * per-customer number behind it. A column here for any of those would be a
 * second copy that drifts from the one that decides.
 *
 * The states:
 *
 *   trial       inside a configured free period. A card is on file; nothing
 *               is charged. A deployment with no trial never opens a row here.
 *   subscribed  the subscription is paying.
 *   grace       a charge failed. Everything keeps running to htr_grace_ends_time.
 *   shutdown    the grace ran out. The instance is powered off and a deletion
 *               task has been raised for a person; the backups are kept until
 *               htr_shelf_ends_time.
 *
 * SENDING HEALTH IS NOT TRACKED HERE AT ALL. The mail provider enforces the
 * monthly send limit on the customer's own subaccount and applies its own
 * bounce and complaint controls; the one figure kept here, htr_sent_count, is
 * for the banner's allowance line and decides nothing.
 *
 * The platform never deletes a cloud instance and never deletes a customer's
 * site. Shutdown is the strongest automatic action there is, and the deletion
 * that stops the bill is a person at the provider.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class HostedTrialException extends SystemBaseException {}

class HostedTrial extends SystemBase {
	public static $prefix = 'htr';
	public static $tablename = 'htr_hosted_trials';
	public static $pkey_column = 'htr_id';

	// A trial row is meaningless without its provision: it describes the
	// commercial half of one specific site. It follows the provision rather
	// than outliving it as a row nothing can be read against.
	protected static $foreign_key_actions = array(
		'htr_cvp_provision_id' => array('action' => 'cascade'),
	);

	public static $test_fixture = array(
		'update_field' => 'htr_state',
	);

	const STATE_TRIAL      = 'trial';
	const STATE_SUBSCRIBED = 'subscribed';
	const STATE_GRACE      = 'grace';
	const STATE_SHUTDOWN   = 'shutdown';

	/** The states in which the site is still running and still ours to watch. */
	const LIVE_STATES = array(self::STATE_TRIAL, self::STATE_SUBSCRIBED, self::STATE_GRACE);

	public static $field_specifications = array(
		'htr_id'                  => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'htr_cvp_provision_id'    => array('type'=>'int8', 'required'=>true, 'is_nullable'=>false, 'unique'=>true),
		'htr_state'               => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'trial',
			'allowed_values'=>array('trial', 'subscribed', 'grace', 'shutdown')),
		// The subscription line this hosting is billed on. It is an ORDER ITEM
		// id, not a provider subscription id: the store's signals carry the
		// order item, and matching on anything else would mean holding a second
		// mapping that can disagree with the store's.
		'htr_external_order_item_id' => array('type'=>'int8'),
		'htr_trial_ends_time'     => array('type'=>'timestamp(6)'),
		// When the charge failed, and the two deadlines counted from it. Stored
		// rather than derived so that changing the grace setting does not
		// silently move the deadline of a customer already inside one.
		'htr_payment_failed_time' => array('type'=>'timestamp(6)'),
		'htr_grace_ends_time'     => array('type'=>'timestamp(6)'),
		'htr_shelf_ends_time'     => array('type'=>'timestamp(6)'),
		'htr_shutdown_time'       => array('type'=>'timestamp(6)'),
		// Sends this month, from the provider's webhook events. It moves the
		// banner's allowance line and nothing else: a webhook can be spoofed or
		// dropped, so the cap is the provider's own count of the same thing.
		'htr_sent_count'          => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
		'htr_counts_reset_time'   => array('type'=>'timestamp(6)'),
		// What the site's own banner currently says, as last pushed. Held so a
		// converge is dispatched when the message CHANGES rather than every
		// tick — a job per site per quarter-hour, forever, is not a banner.
		'htr_pushed_digest'       => array('type'=>'varchar(64)'),
		'htr_pushed_time'         => array('type'=>'timestamp(6)'),
		'htr_note'                => array('type'=>'text'),
		'htr_create_time'         => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'htr_update_time'         => array('type'=>'timestamp(6)'),
		'htr_delete_time'         => array('type'=>'timestamp(6)'),
	);

	function prepare() {
		$this->set('htr_update_time', gmdate('Y-m-d H:i:s'));
	}

	function save($debug = false) {
		if (!$this->get('htr_cvp_provision_id')) {
			throw new HostedTrialException('A hosted trial row belongs to a provision.');
		}
		$state = $this->get('htr_state') ?: self::STATE_TRIAL;
		if (!in_array($state, array_merge(self::LIVE_STATES, array(self::STATE_SHUTDOWN)), true)) {
			throw new HostedTrialException("Unknown hosted state '{$state}'.");
		}
		$this->set('htr_update_time', gmdate('Y-m-d H:i:s'));
		return parent::save($debug);
	}

	/** The row for one provision, or null. */
	public static function for_provision($provision_id) {
		$provision_id = (int)$provision_id;
		if (!$provision_id) { return null; }
		$rows = new MultiHostedTrial(array('provision_id' => $provision_id, 'deleted' => false));
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/** The row for a subscription's order item, or null. */
	public static function for_order_item($order_item_id) {
		$order_item_id = (int)$order_item_id;
		if (!$order_item_id) { return null; }
		$rows = new MultiHostedTrial(array('order_item_id' => $order_item_id, 'deleted' => false));
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}
}

class MultiHostedTrial extends SystemMultiBase {
	protected static $model_class = 'HostedTrial';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['provision_id'])) {
			$filters['htr_cvp_provision_id'] = array((int)$this->options['provision_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['order_item_id'])) {
			$filters['htr_external_order_item_id'] = array((int)$this->options['order_item_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['state'])) {
			$filters['htr_state'] = array((string)$this->options['state'], PDO::PARAM_STR);
		}
		if (isset($this->options['states']) && is_array($this->options['states']) && count($this->options['states'])) {
			$quoted = array_map(function ($s) {
				return "'" . preg_replace('/[^a-z_]/', '', $s) . "'";
			}, $this->options['states']);
			$filters['htr_state'] = 'IN (' . implode(',', $quoted) . ')';
		}

		return $this->_get_resultsv2('htr_hosted_trials', $filters, $this->order_by, $only_count, $debug);
	}
}
