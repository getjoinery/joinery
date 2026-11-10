<?php
/**
 * MailingListPending — a request to join a mailing list that waits for the
 * address's owner to confirm it (spec email_lists_plugin §10).
 *
 * One row per list asked for. The rows of one request share a token, mailed
 * to the address as a link to /email_lists/confirm; only its sha256 is stored.
 * Subscribers (MailingListRegistrant) hold only confirmed people, so nothing
 * that decides who gets mail ever reads this table.
 *
 * A request from registration (source register) is sent no email: verifying
 * the new account's address confirms it (account.email_verified).
 *
 * @version 1.0
 */
class MailingListPending extends SystemBase {
	public static $prefix = 'mlp';
	public static $tablename = 'mlp_mailing_list_pending';
	public static $access_class = 'personal';
	public static $pkey_column = 'mlp_mailing_list_pending_id';

	protected static $foreign_key_actions = array(
		'mlp_mlt_mailing_list_id' => array('action' => 'cascade'),
		'mlp_usr_user_id' => array('action' => 'cascade'),
	);

	/** Days a confirmation link works, the same as an activation code. */
	const LIFETIME_DAYS = 30;

	public static $field_specifications = array(
		'mlp_mailing_list_pending_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'mlp_email' => array('type'=>'varchar(255)', 'required'=>true, 'index'=>true),
		'mlp_usr_user_id' => array('type'=>'int4', 'index'=>true),
		'mlp_mlt_mailing_list_id' => array('type'=>'int4', 'required'=>true),
		'mlp_first_name' => array('type'=>'varchar(64)'),
		'mlp_last_name' => array('type'=>'varchar(64)'),
		'mlp_token_hash' => array('type'=>'varchar(64)', 'index'=>true),
		'mlp_source' => array('type'=>'varchar(32)', 'required'=>true),
		'mlp_request_time' => array('type'=>'timestamp(6)', 'default'=>'now()', 'index'=>true),
		'mlp_last_sent_time' => array('type'=>'timestamp(6)'),
		'mlp_ip' => array('type'=>'inet'),
		'mlp_visitor_id' => array('type'=>'varchar(64)'),
	);

	/** The live rows a link's token names (not expired). */
	public static function for_token(string $token): array {
		$token = trim($token);
		if ($token === '' || preg_match('/^[0-9a-f]{64}\z/', $token) !== 1) {
			return array();
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT mlp_mailing_list_pending_id FROM mlp_mailing_list_pending
			WHERE mlp_token_hash = ? AND mlp_request_time > now() - (? * interval \'1 day\') ORDER BY mlp_mailing_list_pending_id');
		$q->execute(array(hash('sha256', $token), self::LIFETIME_DAYS));
		$rows = array();
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$rows[] = new MailingListPending((int)$id, TRUE);
		}
		return $rows;
	}
}

class MultiMailingListPending extends SystemMultiBase {
	protected static $model_class = 'MailingListPending';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		// Only requests whose link still works.
		if (!empty($this->options['live'])) {
			$filters['mlp_request_time'] = "> now() - interval '" . (int)MailingListPending::LIFETIME_DAYS . " days'";
		}
		return $this->_get_resultsv2('mlp_mailing_list_pending', $filters, $this->order_by, $only_count, $debug);
	}
}
