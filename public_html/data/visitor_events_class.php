<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class VisitorEventException extends SystemBaseException {}

class VisitorEvent extends SystemBase {
	public static $prefix = 'vse';

	// REST API: audit/log table — admin-only (permission >= 5) read and write via the API; not user-scoped content.
	function authenticate_read($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
		}
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError('Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	public static $tablename = 'vse_visitor_events';
	public static $pkey_column = 'vse_visitor_event_id';

	/** Event type: Page view */
	const TYPE_PAGE_VIEW = 1;
	/** Event type: Cookie consent record */
	const TYPE_COOKIE_CONSENT = 2;
	/** Event type: Visitor added an item to the shopping cart */
	const TYPE_CART_ADD = 3;
	/** Event type: Visitor reached the checkout/payment form with items in cart */
	const TYPE_CHECKOUT_START = 4;
	/** Event type: Order completed (payment cleared). vse_ref_type='order', vse_ref_id=ord_order_id */
	const TYPE_PURCHASE = 5;
	/** Event type: New user account created. vse_ref_type='user', vse_ref_id=usr_user_id */
	const TYPE_SIGNUP = 6;
	/** Event type: Subscribed to a mailing list. One event per list joined.
	 *  vse_ref_type='mailing_list', vse_ref_id=mlt_mailing_list_id */
	const TYPE_LIST_SIGNUP = 7;
	/** Event type: Arrived with a ?coupon=CODE URL (valid or expired). Diagnostic, not a conversion.
	 *  vse_meta holds the attempted code. Excluded from attribution reports. */
	const TYPE_COUPON_ATTEMPT = 8;

	protected static $foreign_key_actions = [
		'vse_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
	];

	// Retention is roll-up-then-delete, not a plain age delete, so it is the
	// method form of a retention policy: the daily RetentionSweep hands the window
	// to rollupAndPrunePageViews(). 0 in the setting means never — the sweep skips
	// the rule and every page-view row is kept raw forever.
	public static $retention_policy = array(
		'label'          => 'Visitor page-view detail',
		'purge_method'   => 'rollupAndPrunePageViews',
		'window_setting' => 'analytics_retention_days',
	);

	// Only page-view-class rows are rolled up and pruned — the bloat is entirely
	// page views (a table can hold ~a million of them against a handful of
	// conversions). Conversion rows carry per-row data the rollup cannot hold: the
	// order link revenue attribution joins on, and the buyer's event sequence a
	// funnel reconstructs. They are rare and precious, so they stay raw forever.
	const ROLLUP_TYPES = array(self::TYPE_PAGE_VIEW, self::TYPE_COOKIE_CONSENT, self::TYPE_COUPON_ATTEMPT);

	// A single sweep rolls up at most this many backlog days, so the first run on
	// a site with years of history throttles itself across a few nights instead of
	// holding the sweep for one long stretch. Each day is its own transaction and
	// leaves no half-done state, so the next run simply continues.
	const ROLLUP_MAX_DAYS_PER_RUN = 750;

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'vse_visitor_event_id' => array('type'=>'int8', 'serial'=>true),
	    'vse_visitor_id' => array('type'=>'varchar(20)'),
	    'vse_usr_user_id' => array('type'=>'int4', 'index'=>true),
	    'vse_type' => array('type'=>'int2'),
	    'vse_ip' => array('type'=>'varchar(64)'),
	    'vse_page' => array('type'=>'text'),
	    'vse_referrer' => array('type'=>'text'),
	    'vse_source' => array('type'=>'varchar(255)'),
	    'vse_campaign' => array('type'=>'varchar(255)'),
	    'vse_timestamp' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'vse_medium' => array('type'=>'varchar(255)'),
	    'vse_content' => array('type'=>'varchar(255)'),
	    'vse_is_404' => array('type'=>'bool'),
	    // Generic polymorphic reference for conversion rows (order/user/mailing_list/etc.)
	    'vse_ref_type' => array('type'=>'varchar(32)'),
	    'vse_ref_id' => array('type'=>'int8'),
	    // Free-form metadata for diagnostic rows (e.g. attempted coupon code on TYPE_COUPON_ATTEMPT)
	    'vse_meta' => array('type'=>'varchar(255)'),
	);

/**
	 * Record a page visit for tracking purposes
	 * This method creates a visitor event record for JavaScript-based tracking
	 * @param string $page The page URL being visited
	 */
	public static function recordPageVisit($page) {
		try {
			// Don't track admin pages or Ajax requests
			if (strpos($page, '/admin/') === 0 || strpos($page, '/ajax/') === 0) {
				return;
			}

			// Check consent before tracking (analytics tracking requires consent)
			require_once(PathHelper::getIncludePath('includes/ConsentHelper.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
			$consent = ConsentHelper::get_instance();
			if ($consent->isEnabled() && !$consent->allowsAnalytics()) {
				return; // Don't track without consent
			}

			$visitor_event = new VisitorEvent(NULL);

			// Set visitor ID from cookie or generate new one
			$visitor_id = $_COOKIE['visitor_id'] ?? null;
			if (!$visitor_id) {
				$visitor_id = substr(md5(uniqid(mt_rand(), true)), 0, 20);
				setcookie('visitor_id', $visitor_id, time() + (365 * 24 * 60 * 60), '/');
			}

			$visitor_event->set('vse_visitor_id', $visitor_id);

			// Set user ID if logged in
			$session = SessionControl::get_instance();
			if ($session->is_logged_in()) {
				$visitor_event->set('vse_usr_user_id', $_SESSION['user_id']);
			}

			// Set tracking data
			$visitor_event->set('vse_type', self::TYPE_PAGE_VIEW);
			$visitor_event->set('vse_ip', $_SERVER['REMOTE_ADDR'] ?? '');
			$visitor_event->set('vse_page', $page);
			$visitor_event->set('vse_referrer', $_SERVER['HTTP_REFERER'] ?? '');

			// Parse UTM parameters if present
			if (!empty($_GET['utm_source'])) {
				$visitor_event->set('vse_source', $_GET['utm_source']);
			}
			if (!empty($_GET['utm_campaign'])) {
				$visitor_event->set('vse_campaign', $_GET['utm_campaign']);
			}
			if (!empty($_GET['utm_medium'])) {
				$visitor_event->set('vse_medium', $_GET['utm_medium']);
			}
			if (!empty($_GET['utm_content'])) {
				$visitor_event->set('vse_content', $_GET['utm_content']);
			}

			// Check if 404
			if (http_response_code() === 404) {
				$visitor_event->set('vse_is_404', true);
			}

			$visitor_event->save();

		} catch (Exception $e) {
			// Silently fail - don't break page for tracking errors
			error_log("Visitor tracking error: " . $e->getMessage());
		}
	}

	/**
	 * Roll page-view rows older than the window into the daily summary tables and
	 * delete them. The method form of this class's $retention_policy — called by
	 * the daily RetentionSweep with the window in days (never with 0; the sweep
	 * skips a rule whose window is 0).
	 *
	 * Whole days only: current_date - :days is a date, and rolling up everything
	 * strictly before it keeps the most recent :days of raw rows and never splits
	 * a day across the boundary.
	 *
	 * One day per transaction, oldest first. A day enters the batch only while it
	 * still has raw rows, and each transaction rolls that day up into both summary
	 * tables and deletes its raw rows together — so an interrupted run leaves no
	 * day half-summarised (rollback restores the raw rows) and no day double-counted
	 * (a committed day has no raw rows left to re-select). Backfill and steady state
	 * are the same path; the first run just has more days to work through, capped at
	 * ROLLUP_MAX_DAYS_PER_RUN so it self-throttles.
	 *
	 * @param int $days  retention window in days
	 * @return array     removed (raw rows deleted), message
	 */
	public static function rollupAndPrunePageViews($days) {
		$days = (int) $days;
		if ($days <= 0) {
			return array('removed' => 0, 'message' => 'retention disabled');
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$type_list = implode(',', array_map('intval', self::ROLLUP_TYPES));

		// The whole days eligible to roll up, oldest first, capped per run.
		$find = $dblink->prepare(
			"SELECT DISTINCT date(vse_timestamp) AS d
			   FROM vse_visitor_events
			  WHERE vse_type IN ($type_list)
			    AND vse_timestamp < (current_date - (:days * INTERVAL '1 day'))
			  ORDER BY d ASC
			  LIMIT :cap");
		$find->bindValue(':days', $days, PDO::PARAM_INT);
		$find->bindValue(':cap', self::ROLLUP_MAX_DAYS_PER_RUN, PDO::PARAM_INT);
		$find->execute();
		$days_to_process = $find->fetchAll(PDO::FETCH_COLUMN);

		if (!$days_to_process) {
			return array('removed' => 0, 'message' => '');
		}

		// Path only — strip the query string so /product?id=1 and /product?id=2
		// collapse to one /product row. Full URLs survive on the raw rows inside
		// the window; only the rolled-up summary loses the query string.
		$ins_main = $dblink->prepare(
			"INSERT INTO vsr_visitor_stats_rollup
			     (vsr_day, vsr_type, vsr_page, vsr_source, vsr_campaign, vsr_medium, vsr_content, vsr_is_404, vsr_event_count)
			 SELECT date(vse_timestamp), vse_type, split_part(vse_page, '?', 1),
			        vse_source, vse_campaign, vse_medium, vse_content, COALESCE(vse_is_404, FALSE), count(*)
			   FROM vse_visitor_events
			  WHERE vse_type IN ($type_list) AND date(vse_timestamp) = :d
			  GROUP BY date(vse_timestamp), vse_type, split_part(vse_page, '?', 1),
			           vse_source, vse_campaign, vse_medium, vse_content, COALESCE(vse_is_404, FALSE)");

		// The distinct-visitor count, which the summed rollup cannot reproduce, in
		// its own coarse per-day-per-type table.
		$ins_uniq = $dblink->prepare(
			"INSERT INTO vsu_visitor_daily_uniques (vsu_day, vsu_type, vsu_unique_visitors)
			 SELECT date(vse_timestamp), vse_type, count(DISTINCT vse_visitor_id)
			   FROM vse_visitor_events
			  WHERE vse_type IN ($type_list) AND date(vse_timestamp) = :d
			  GROUP BY date(vse_timestamp), vse_type");

		$del = $dblink->prepare(
			"DELETE FROM vse_visitor_events
			  WHERE vse_type IN ($type_list) AND date(vse_timestamp) = :d");

		$removed = 0;
		$rolled_days = 0;
		foreach ($days_to_process as $d) {
			$dblink->beginTransaction();
			try {
				$ins_main->execute(array(':d' => $d));
				$ins_uniq->execute(array(':d' => $d));
				$del->execute(array(':d' => $d));
				$removed += $del->rowCount();
				$dblink->commit();
				$rolled_days++;
			} catch (Throwable $e) {
				// One day's failure rolls back cleanly and is re-tried next run.
				// Re-throw so RetentionSweep records it; other retention rules and
				// the days already committed stand.
				if ($dblink->inTransaction()) {
					$dblink->rollBack();
				}
				throw $e;
			}
		}

		$more = count($days_to_process) >= self::ROLLUP_MAX_DAYS_PER_RUN ? ' (more remain for the next run)' : '';
		return array(
			'removed' => $removed,
			'message' => $rolled_days . ' day(s) rolled up, ' . $removed . ' row(s) pruned' . $more,
		);
	}

}

class MultiVisitorEvent extends SystemMultiBase {
	protected static $model_class = 'VisitorEvent';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        // Note: 'code' filter removed - vse_code field does not exist in model
        
        return $this->_get_resultsv2('vse_visitor_events', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
