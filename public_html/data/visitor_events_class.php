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
	public static $tablename = 'vse_visitor_events';
	public static $pkey_column = 'vse_visitor_event_id';

	/** Event type: Page view */
	const TYPE_PAGE_VIEW = 1;
	/** Event type: Cookie consent record */
	const TYPE_COOKIE_CONSENT = 2;

	protected static $foreign_key_actions = [
		'vse_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
	];

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
	    'vse_usr_user_id' => array('type'=>'int4'),
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
