<?php
/**
 * InboundBayesToken - the deployment's spam corpus, one row per token
 * (spam_learning_in_core.md § The Bayes classifier).
 *
 * ibt_token is the first 8 bytes of HMAC-SHA256(mailbox_spam_token_key, token)
 * as a signed bigint: a word, a word pair, a URL host or a meta token, never the
 * text itself. The counts are how many messages taught spam and ham carried the
 * token. Aggregate only: nothing links a row to a message, a mailbox or a time.
 *
 * The reserved row ibt_token = 0 holds the totals (messages taught spam, ham).
 *
 * Read and written only through SpamBayes, in raw SQL: a teaching upserts a
 * whole message's tokens in one statement, the totals row first, so every
 * teaching locks in the same order.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundBayesTokenException extends SystemBaseException {}

class InboundBayesToken extends SystemBase {
	public static $prefix = 'ibt';
	public static $tablename = 'ibt_inbound_bayes_tokens';
	public static $pkey_column = 'ibt_inbound_bayes_token_id';

	public static $field_specifications = array(
		'ibt_inbound_bayes_token_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// The token's hash; unique, and the conflict target every teaching upserts on.
		'ibt_token'          => array('type'=>'int8', 'is_nullable'=>false, 'unique'=>true),
		'ibt_spam_count'     => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'ibt_ham_count'      => array('type'=>'int4', 'is_nullable'=>false, 'default'=>0),
		'ibt_last_seen_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	/** The prune task deletes stale low-count tokens by age. */
	public static $index_specifications = array(
		array('columns' => array('ibt_last_seen_time')),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}
}

class MultiInboundBayesToken extends SystemMultiBase {
	protected static $model_class = 'InboundBayesToken';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('ibt_inbound_bayes_tokens', array(), $this->order_by, $only_count, $debug);
	}
}
?>
