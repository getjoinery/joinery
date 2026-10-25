<?php
/**
 * MailAppFolder - one mail-app folder's numbering state, per mailbox.
 *
 *   maf_uidvalidity     what IMAP calls UIDVALIDITY: changes only when the
 *                       folder's numbering starts over, and a mail program
 *                       then downloads the folder again. A label deleted and
 *                       recreated under the same name is a new folder key, so
 *                       a new row and a new value; a change to the message
 *                       format (MailAppRebuild::FORMAT) starts every folder over.
 *   maf_uidnext         the next number this folder hands out.
 *   maf_highest_modseq  the folder's latest change number (CONDSTORE).
 *   maf_format          the MailAppRebuild::FORMAT the numbers were handed out
 *                       under — a message's bytes are pinned to it.
 *
 * Every reconcile takes this row FOR UPDATE, so numbers are handed out one
 * reconcile at a time per folder.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class MailAppFolderException extends SystemBaseException {}

class MailAppFolder extends SystemBase {
	public static $prefix = 'maf';
	public static $tablename = 'maf_mail_app_folders';
	public static $access_class = 'personal';
	public static $pkey_column = 'maf_mail_app_folder_id';

	protected static $foreign_key_actions = array(
		'maf_iea_inbound_email_alias_id' => array('action' => 'cascade'),
	);

	public static $field_specifications = array(
		'maf_mail_app_folder_id'         => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'maf_iea_inbound_email_alias_id' => array('type'=>'int4', 'is_nullable'=>false,
			'unique_with'=>array('maf_folder'),
			'foreign_key'=>array('table'=>'iea_inbound_email_aliases', 'column'=>'iea_inbound_email_alias_id', 'on_delete'=>'CASCADE')),
		'maf_folder'                     => array('type'=>'varchar(64)', 'is_nullable'=>false),
		'maf_uidvalidity'                => array('type'=>'int8', 'is_nullable'=>false),
		'maf_uidnext'                    => array('type'=>'int8', 'is_nullable'=>false, 'default'=>1),
		'maf_highest_modseq'             => array('type'=>'int8', 'is_nullable'=>false, 'default'=>1),
		'maf_format'                     => array('type'=>'int2', 'is_nullable'=>false, 'default'=>1),
		'maf_create_time'                => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	/** Rows are written by the mail apps plugin only, never through the API. */
	function authenticate_write($data) {
		throw new SystemAuthenticationError('Mail app folder state is not writable.');
	}
}

class MultiMailAppFolder extends SystemMultiBase {
	protected static $model_class = 'MailAppFolder';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['alias_id'])) {
			$filters['maf_iea_inbound_email_alias_id'] = array($this->options['alias_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('maf_mail_app_folders', $filters, $this->order_by, $only_count, $debug);
	}
}
?>
