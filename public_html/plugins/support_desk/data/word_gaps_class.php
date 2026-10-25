<?php
/**
 * SupportWordGap — an observe word the model reached for that a joined site's
 * agent does not offer, counted by word and agent version
 * (specs/support_chatbot.md, The admin page, "Words the site could not read").
 *
 * The count is how the version floor and the running list in
 * specs/agent_recipes_and_vocabulary.md § "Words the fleet has asked for" get
 * their rows from use rather than from memory.
 *
 * @version 0.1.0
 */
class SupportWordGap extends SystemBase {
	public static $prefix = 'swg';
	public static $tablename = 'swg_support_word_gaps';
	public static $access_class = 'content';
	public static $pkey_column = 'swg_support_word_gap_id';

	public static $field_specifications = array(
		'swg_support_word_gap_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'swg_word'                => array('type' => 'varchar(64)', 'is_nullable' => false, 'unique_with' => array('swg_agent_version')),
		'swg_agent_version'       => array('type' => 'varchar(32)', 'is_nullable' => false),
		'swg_count'               => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'swg_first_time'          => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'swg_last_time'           => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

}

class MultiSupportWordGap extends SystemMultiBase {
	protected static $model_class = 'SupportWordGap';

	protected function getMultiResults($only_count = false, $debug = false) {
		return $this->_get_resultsv2('swg_support_word_gaps', array(), $this->order_by, $only_count, $debug);
	}
}
