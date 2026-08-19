<?php

class OwnershipException extends SystemBaseException {}

/**
 * A record that one user owns one thing, once.
 *
 * A product carries an ownership tag (pro_ownership_tag) naming what buying it
 * confers. When such a product is paid for, the store writes a row here. From
 * then on the buy button reads "you already own this" and checkout refuses to
 * charge for it again. Products sharing a tag count as the same thing.
 *
 * The tag '*' means "every tag in this store" — an all-access bundle.
 *
 * own_license_key is fulfillment's business, not ownership's: an operator
 * whose product mails out a key string stamps it here. Core never reads it.
 *
 * Version: 1.0.0
 */
class Ownership extends SystemBase {
	public static $prefix = 'own';
	public static $tablename = 'own_ownerships';
	public static $pkey_column = 'own_ownership_id';

	/** The tag that covers every tag. */
	const TAG_ALL = '*';

	// Not exposed over REST (no $api_readable/$api_writable) and not AI-readable
	// — a buyer sees their own ownerships on their profile, nowhere else.
	public static $ai_readable = false;

	protected static $foreign_key_actions = [
		'own_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		'own_ord_order_id' => ['action' => 'null'],
		'own_odi_order_item_id' => ['action' => 'null']
	];

	public static $field_specifications = array(
	    'own_ownership_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'own_usr_user_id' => array('type'=>'int4', 'required'=>true),
	    'own_tag' => array('type'=>'varchar(64)', 'required'=>true),
	    'own_ord_order_id' => array('type'=>'int4'),
	    'own_odi_order_item_id' => array('type'=>'int4'),
	    'own_license_key' => array('type'=>'varchar(64)'),
	    'own_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'own_revoked_time' => array('type'=>'timestamp(6)'),
	    'own_delete_time' => array('type'=>'timestamp(6)'),
	);

	/**
	 * Does this user own this tag?
	 *
	 * True when they hold a live, un-revoked row for the tag itself or for the
	 * all-access tag. This is the single authority — every guard calls it.
	 */
	public static function user_owns($user_id, $tag) {
		$user_id = (int)$user_id;
		$tag = trim((string)$tag);
		if (!$user_id || $tag === '') {
			return false;
		}
		$owned = new MultiOwnership(array(
			'user_id' => $user_id,
			'covers_tag' => $tag,
			'revoked' => FALSE,
		));
		return $owned->count_all() > 0;
	}

	/**
	 * The tag a product with "Own once" (not shared with other products) gets.
	 * Derived, never typed by the operator.
	 */
	public static function tag_for_product($product_id) {
		return 'product-' . (int)$product_id;
	}

	/**
	 * Record that a paid order item's owner now owns the product's tag.
	 *
	 * The store does this itself the moment a tagged product is paid for —
	 * tagging the product is the entire setup, no purchase script required.
	 * Idempotent per order item, so a webhook replay or a re-run of the
	 * post-charge work never writes a second row.
	 *
	 * Returns the ownership row, or NULL when there is nothing to record.
	 *
	 * @param Product        $product
	 * @param ProductVersion $product_version  the version sold (may be NULL)
	 * @param User           $user             who fulfillment is for
	 * @param OrderItem      $order_item
	 * @param Order          $order            may be NULL
	 */
	public static function record_purchase($product, $product_version, $user, $order_item, $order = NULL) {
		$tag = trim((string)$product->get('pro_ownership_tag'));
		if ($tag === '') {
			return NULL;
		}

		// Boundary backstop: ownership stays out of tiers, billing and
		// renewals. The admin product edit refuses to save a tag on a
		// subscription; if one ever reaches here anyway, say so and skip.
		$is_subscription = ($product_version && $product_version->is_subscription())
			|| $order_item->get('odi_is_subscription');
		if ($is_subscription) {
			error_log('Ownership::record_purchase: ownership tag "' . $tag . '" on subscription product #'
				. $product->key . ' (order_item ' . $order_item->key . ') — skipped; ownership applies to '
				. 'one-time purchases only.');
			return NULL;
		}

		$existing = new MultiOwnership(array('order_item_id' => $order_item->key));
		foreach ($existing as $row) {
			return $row;
		}

		$ownership = new Ownership(NULL);
		$ownership->set('own_usr_user_id', $user->key);
		$ownership->set('own_tag', $tag);
		$ownership->set('own_ord_order_id', $order ? $order->key : NULL);
		$ownership->set('own_odi_order_item_id', $order_item->key);
		$ownership->save();
		$ownership->load();
		return $ownership;
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
}

class MultiOwnership extends SystemMultiBase {
	protected static $model_class = 'Ownership';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['own_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['order_id'])) {
			$filters['own_ord_order_id'] = [$this->options['order_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['order_item_id'])) {
			$filters['own_odi_order_item_id'] = [$this->options['order_item_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['tag'])) {
			$filters['own_tag'] = [$this->options['tag'], PDO::PARAM_STR];
		}

		// The ownership question: a row for this tag, or an all-access row.
		if (isset($this->options['covers_tag'])) {
			$dblink = DbConnector::get_instance()->get_db_link();
			$filters['own_tag'] = 'IN (' . $dblink->quote($this->options['covers_tag'])
				. ', ' . $dblink->quote(Ownership::TAG_ALL) . ')';
		}

		if (isset($this->options['revoked'])) {
			$filters['own_revoked_time'] = $this->options['revoked'] ? 'IS NOT NULL' : 'IS NULL';
		}

		if (empty($this->options['include_deleted'])) {
			$filters['own_delete_time'] = "IS NULL";
		}

		return $this->_get_resultsv2('own_ownerships', $filters, $this->order_by, $only_count, $debug);
	}

	/**
	 * The shared tags already in use on this store's products, for the admin
	 * suggestion list. Derived per-product tags and the all-access tag are not
	 * groups anyone joins by typing, so they are left out.
	 */
	public static function tags_in_use() {
		$dblink = DbConnector::get_instance()->get_db_link();
		$sql = "SELECT DISTINCT pro_ownership_tag FROM pro_products
			WHERE pro_ownership_tag IS NOT NULL AND pro_ownership_tag <> ''
			AND pro_ownership_tag <> " . $dblink->quote(Ownership::TAG_ALL) . "
			AND pro_ownership_tag NOT LIKE 'product-%'
			AND pro_delete_time IS NULL ORDER BY pro_ownership_tag";
		$tags = array();
		foreach ($dblink->query($sql, PDO::FETCH_ASSOC) as $row) {
			$tags[] = $row['pro_ownership_tag'];
		}
		return $tags;
	}
}

?>
