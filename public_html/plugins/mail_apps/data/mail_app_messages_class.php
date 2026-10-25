<?php
/**
 * MailAppMessage - what the mail apps plugin has to say about one message,
 * beyond what mailbox stores.
 *
 *   mam_is_answered / mam_is_forwarded  IMAP's \Answered and $Forwarded: set by
 *                       a mail program's STORE and by mail_apps/submit when it
 *                       sends a reply or forward. Mailbox does not know this
 *                       plugin exists, so a reply sent from the web reader sets
 *                       neither (specs/mail_apps_plugin.md § Scope).
 *   mam_client_size     the size of the bytes a mail program is served for this
 *   mam_client_format   message, and the MailAppRebuild::FORMAT they were built
 *                       under — fixed at first telling, because RFC822.SIZE is
 *                       asked for in bulk and a program reads the message in
 *                       pieces up to it.
 *   mam_submitted_message_id  the Message-ID a mail program gave a message it
 *                       sent or saved, when Joinery keeps it under another one
 *                       (a minted Message-ID, a draft): how its copy to Sent is
 *                       recognised as the same message, and how a program that
 *                       searches for its draft by Message-ID finds it.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailAppMessageException extends SystemBaseException {}

class MailAppMessage extends SystemBase {
	public static $prefix = 'mam';
	public static $tablename = 'mam_mail_app_messages';
	public static $access_class = 'personal';
	public static $pkey_column = 'mam_mail_app_message_id';

	protected static $foreign_key_actions = array(
		'mam_iem_inbound_email_message_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'mam_mail_app_message_id'          => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mam_iem_inbound_email_message_id' => array('type'=>'int8', 'is_nullable'=>false, 'unique'=>true,
			'foreign_key'=>array('table'=>'iem_inbound_email_messages', 'column'=>'iem_inbound_email_message_id', 'on_delete'=>'CASCADE')),
		'mam_is_answered'                  => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'mam_is_forwarded'                 => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'mam_client_size'                  => array('type'=>'int8', 'is_nullable'=>true),
		'mam_client_format'                => array('type'=>'int2', 'is_nullable'=>true),
		'mam_submitted_message_id'         => array('type'=>'varchar(255)', 'is_nullable'=>true, 'index'=>true),
		'mam_create_time'                  => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	/** Rows are written by the mail apps plugin only, never through the API. */
	function authenticate_write($data) {
		throw new SystemAuthenticationError('Mail app message state is not writable.');
	}
}

class MultiMailAppMessage extends SystemMultiBase {
	protected static $model_class = 'MailAppMessage';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['message_id'])) {
			$filters['mam_iem_inbound_email_message_id'] = array($this->options['message_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('mam_mail_app_messages', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
