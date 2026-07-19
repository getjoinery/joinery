<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class BookingException extends SystemBaseException {}

/**
 * Booking — one confirmed (or held) appointment against a booking type.
 *
 * `bkn_usr_user_id_booked` is the host (whose schedule the slot came from);
 * `bkn_usr_user_id_client` is the invitee. Invitee self-service (cancel /
 * reschedule) is authorized by the random `bkn_action_token`, not a login.
 * External-provider bookings carry their provider id in `bkn_external_uri`.
 */
class Booking extends SystemBase {

	public static $prefix = 'bkn';
	public static $tablename = 'bkn_bookings';
	public static $pkey_column = 'bkn_booking_id';

	public static $ai_readable        = true;
	public static $ai_owner_field     = ['bkn_usr_user_id_booked', 'bkn_usr_user_id_client']; // a member sees bookings where they are host or client
	public static $ai_description     = 'Time-slot bookings: appointments booked against a booking type.';
	public static $ai_excluded_fields = ['bkn_action_token'];
	public static $ai_untrusted_fields = ['bkn_notes', 'bkn_cancel_reason'];

	const BOOKING_STATUS_CREATED = 0;          // pending hold (paid flow) — slot reserved, not confirmed
	const BOOKING_STATUS_BOOKED = 1;
	const BOOKING_STATUS_COMPLETED = 2;
	const BOOKING_STATUS_CANCELED = 3;
	const BOOKING_STATUS_NEEDS_ATTENTION = 4;  // paid booking whose slot was lost during checkout

	public static $field_specifications = array(
	    'bkn_booking_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'bkn_provider' => array('type'=>'varchar(32)', 'default'=>'native'),
	    'bkn_external_uri' => array('type'=>'varchar(255)'),
	    'bkn_bkt_booking_type_id' => array('type'=>'int8'),
	    'bkn_usr_user_id_booked' => array('type'=>'int8'),   // host
	    'bkn_usr_user_id_client' => array('type'=>'int8'),   // invitee
	    'bkn_pro_product_id' => array('type'=>'int4'),
	    'bkn_notes' => array('type'=>'text'),
	    'bkn_start_time' => array('type'=>'timestamp(6)'),
	    'bkn_end_time' => array('type'=>'timestamp(6)'),
	    'bkn_start_time_local' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	    'bkn_end_time_local' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	    'bkn_tzdata_version' => array('type'=>'varchar(10)', 'is_nullable'=>true),
	    'bkn_status' => array('type'=>'int4', 'zero_on_create'=>true),
	    'bkn_location' => array('type'=>'text'),
	    'bkn_invitee_timezone' => array('type'=>'varchar(64)'),
	    'bkn_action_token' => array('type'=>'varchar(64)'),
	    'bkn_hold_expires_time' => array('type'=>'timestamp(6)'),
	    'bkn_canceled_by' => array('type'=>'varchar(16)'),   // invitee | host | admin | system
	    'bkn_cancel_reason' => array('type'=>'text'),
	    'bkn_is_no_show' => array('type'=>'bool', 'default'=>false),
	    'bkn_utm_source' => array('type'=>'varchar(255)'),
	    'bkn_utm_medium' => array('type'=>'varchar(255)'),
	    'bkn_utm_campaign' => array('type'=>'varchar(255)'),
	    'bkn_utm_content' => array('type'=>'varchar(255)'),
	    'bkn_utm_term' => array('type'=>'varchar(255)'),
	    'bkn_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'bkn_delete_time' => array('type'=>'timestamp(6)'),
	    'bkn_update_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();

	/** A high-entropy, URL-safe token for invitee cancel/reschedule links. */
	static function make_action_token() {
		return bin2hex(random_bytes(20));
	}

	/** Resolve a booking by its invitee action token. */
	static function GetByToken($token) {
		if (!$token) { return false; }
		$results = new MultiBooking(array('action_token' => $token, 'deleted' => false));
		$results->load();
		return count($results) ? $results->get(0) : false;
	}

	/** Resolve a booking by an external provider's URI (idempotent webhook ingestion). */
	static function GetByExternalUri($uri) {
		if (!$uri) { return false; }
		$results = new MultiBooking(array('external_uri' => $uri, 'deleted' => false));
		$results->load();
		return count($results) ? $results->get(0) : false;
	}

	function is_active_booking() {
		$s = (int)$this->get('bkn_status');
		return $s === self::BOOKING_STATUS_BOOKED || $s === self::BOOKING_STATUS_CREATED;
	}

	/**
	 * Whether this row occupies the host's time right now: a confirmed booking,
	 * or a paid hold whose expiry is still in the future. Slot availability and
	 * the per-day/per-week caps both answer through this one predicate, so they
	 * cannot drift into disagreeing about what a hold is.
	 */
	function occupies_host_time() {
		$s = (int)$this->get('bkn_status');
		if ($s === self::BOOKING_STATUS_BOOKED) { return true; }
		if ($s === self::BOOKING_STATUS_CREATED) {
			$exp = $this->get('bkn_hold_expires_time');
			return $exp && $exp > gmdate('Y-m-d H:i:s');
		}
		return false;
	}

	function authenticate_write($data) {
		// Host or staff may write; invitee acts through the action token, not auth.
		if ($this->get('bkn_usr_user_id_booked') != $data['current_user_id']
			&& (int)$data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiBooking extends SystemMultiBase {
	protected static $model_class = 'Booking';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id_client'])) {
            $filters['bkn_usr_user_id_client'] = [$this->options['user_id_client'], PDO::PARAM_INT];
        }

        if (isset($this->options['user_id_booked'])) {
            $filters['bkn_usr_user_id_booked'] = [$this->options['user_id_booked'], PDO::PARAM_INT];
        }

        if (isset($this->options['booking_type_id'])) {
            $filters['bkn_bkt_booking_type_id'] = [$this->options['booking_type_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['product_id'])) {
            $filters['bkn_pro_product_id'] = [$this->options['product_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['status'])) {
            $filters['bkn_status'] = [$this->options['status'], PDO::PARAM_INT];
        }

        if (isset($this->options['action_token'])) {
            $filters['bkn_action_token'] = [$this->options['action_token'], PDO::PARAM_STR];
        }

        if (isset($this->options['external_uri'])) {
            $filters['bkn_external_uri'] = [$this->options['external_uri'], PDO::PARAM_STR];
        }

        if (isset($this->options['start_after'])) {
            $dblink = DbConnector::get_instance()->get_db_link();
            $filters['bkn_start_time'] = ">= " . $dblink->quote($this->options['start_after']);
        }

        if (isset($this->options['deleted'])) {
            $filters['bkn_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('bkn_bookings', $filters, $this->order_by, $only_count, $debug);
    }

}
