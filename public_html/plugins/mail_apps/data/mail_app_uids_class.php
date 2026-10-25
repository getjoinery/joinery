<?php
/**
 * MailAppUid - the number (IMAP UID) one message has in one mail-app folder.
 *
 * IMAP numbers each folder's messages, and a message added to a folder must
 * get a number above every number that folder has used. Message ids cannot do
 * that — a message archived and moved back to Inbox, or labelled a year after
 * it arrived, would come back with an old number and a mail program would
 * never fetch it — so every APPEARANCE of a message in a folder gets its own
 * row, numbered from the folder's counter (MailAppFolder::maf_uidnext), and a
 * row is never reused: when the message leaves the folder the row is stamped
 * expunged and its number retired.
 *
 * mau_folder is the folder's key (MailAppFolders): inbox, sent, archive, spam,
 * trash, all, label:{label id}, drafts:{author user id}.
 *
 * mau_flags_told is the flag set the mail programs were last told
 * (MailAppFolders::FLAG_* bits), compared with the truth on every reconcile;
 * a row whose truth differs gets the folder's next change number (mau_modseq)
 * — CONDSTORE without mailbox having to report its changes.
 *
 * A lock notice (specs/mail_apps_plugin.md § Locked behaviour) is a row with
 * mau_is_notice and no message.
 *
 * These numbers are NOT mailbox's ilm_imap_uid / iem_imap_uid: those record
 * numbers on a source server Joinery pulls from. Different direction,
 * different numbers.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailAppUidException extends SystemBaseException {}

class MailAppUid extends SystemBase {
	public static $prefix = 'mau';
	public static $tablename = 'mau_mail_app_uids';
	public static $access_class = 'personal';
	public static $pkey_column = 'mau_mail_app_uid_id';

	protected static $foreign_key_actions = array(
		// A message gone for good keeps its numbers' history (retired, never
		// reused); reconcile reports the number expunged.
		'mau_iem_inbound_email_message_id' => array('action' => 'null'),
		'mau_iea_inbound_email_alias_id'   => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'mau_mail_app_uid_id'              => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mau_iea_inbound_email_alias_id'   => array('type'=>'int4', 'is_nullable'=>false,
			'unique_with'=>array('mau_folder', 'mau_uid'),
			'foreign_key'=>array('table'=>'iea_inbound_email_aliases', 'column'=>'iea_inbound_email_alias_id', 'on_delete'=>'CASCADE')),
		'mau_folder'                       => array('type'=>'varchar(64)', 'is_nullable'=>false),
		'mau_uid'                          => array('type'=>'int8', 'is_nullable'=>false),
		'mau_iem_inbound_email_message_id' => array('type'=>'int8', 'is_nullable'=>true, 'index'=>true,
			'foreign_key'=>array('table'=>'iem_inbound_email_messages', 'column'=>'iem_inbound_email_message_id', 'on_delete'=>'SET NULL')),
		'mau_is_notice'                    => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
		'mau_flags_told'                   => array('type'=>'int2', 'is_nullable'=>false, 'default'=>0),
		'mau_modseq'                       => array('type'=>'int8', 'is_nullable'=>false, 'default'=>1),
		'mau_create_time'                  => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'mau_expunged_time'                => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	/** Rows are written by the mail apps plugin only, never through the API. */
	function authenticate_write($data) {
		throw new SystemAuthenticationError('Mail app message numbers are not writable.');
	}
}

class MultiMailAppUid extends SystemMultiBase {
	protected static $model_class = 'MailAppUid';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['alias_id'])) {
			$filters['mau_iea_inbound_email_alias_id'] = array($this->options['alias_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['folder'])) {
			$filters['mau_folder'] = array($this->options['folder'], PDO::PARAM_STR);
		}
		if (isset($this->options['live'])) {
			$filters['mau_expunged_time'] = $this->options['live'] ? 'IS NULL' : 'IS NOT NULL';
		}
		return $this->_get_resultsv2('mau_mail_app_uids', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
