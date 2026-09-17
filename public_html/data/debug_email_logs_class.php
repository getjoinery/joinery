<?php
/**
 * DebugEmailLog - One line per step the email pipeline took while
 * email_debug_mode is on: which service a message went to, whether it was
 * suppressed by dry-run or redirected by test mode, which fallback ran.
 * Written only by EmailSender::logEmailDebug(); read on the admin Debug Email
 * Logs page. Off by default and never a per-message history — the mailbox
 * message timeline (specs/mailbox_message_timeline.md) is that.
 *
 * @version 2.1 - prefix dbl, table dbl_debug_email_logs: del is DeletionRule's alone
 *   (specs/implemented/shared_prefixes_first_three.md)
 * @version 2.0 - the columns are the ones the sender writes (message, service,
 *   status); the earlier subject/recipient/body trio was never written by anything
 */
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class DebugEmailLogException extends SystemBaseException {}

class DebugEmailLog extends SystemBase {
	public static $prefix = 'dbl';
	public static $tablename = 'dbl_debug_email_logs';
	public static $pkey_column = 'dbl_debug_email_log_id';

	public static $field_specifications = array(
	    'dbl_debug_email_log_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'dbl_message'            => array('type'=>'text'),
	    'dbl_service'            => array('type'=>'varchar(50)'),
	    'dbl_status'             => array('type'=>'varchar(20)'),
	    'dbl_create_time'        => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	/** Remove every debug line. The log is diagnostic scratch; nothing references a row. */
	public static function deleteAll(): void {
		DbConnector::get_instance()->get_db_link()->exec('DELETE FROM dbl_debug_email_logs');
	}
}

class MultiDebugEmailLog extends SystemMultiBase {
	protected static $model_class = 'DebugEmailLog';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['service'])) {
            $filters['dbl_service'] = [$this->options['service'], PDO::PARAM_STR];
        }

        return $this->_get_resultsv2('dbl_debug_email_logs', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
