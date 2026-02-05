<?php
/**
 * InboundEmail - Stores inbound emails received via Mailgun webhooks
 *
 * Used for automated testing to verify email content and extract links.
 * Emails are stored when Mailgun forwards them to the inbound webhook endpoint.
 *
 * @see /specs/inbound_email_testing.md
 * @version 1.0.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

// Note: DbConnector, Globalvars, SessionControl are always available - no require needed
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailException extends SystemBaseException {}

class InboundEmail extends SystemBase {
	public static $prefix = 'iem';
	public static $tablename = 'iem_inbound_emails';
	public static $pkey_column = 'iem_inbound_email_id';
	public static $permanent_delete_actions = array();

	public static $field_specifications = array(
		'iem_inbound_email_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'iem_sender' => array('type'=>'varchar(500)'),
		'iem_recipient' => array('type'=>'varchar(500)'),
		'iem_subject' => array('type'=>'varchar(1000)'),
		'iem_body_plain' => array('type'=>'text'),
		'iem_body_html' => array('type'=>'text'),
		'iem_received_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'iem_delete_time' => array('type'=>'timestamp(6)'),
	);
}

class MultiInboundEmail extends SystemMultiBase {
	protected static $model_class = 'InboundEmail';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['recipient'])) {
			$filters['iem_recipient'] = array($this->options['recipient'], PDO::PARAM_STR);
		}

		if (isset($this->options['sender'])) {
			$filters['iem_sender'] = array($this->options['sender'], PDO::PARAM_STR);
		}

		if (isset($this->options['deleted'])) {
			$filters['iem_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('iem_inbound_emails', $filters, $this->order_by, $only_count, $debug);
	}
}

?>
