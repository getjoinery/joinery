<?php
/**
 * SupportFaqEntry — a question and answer an operator of this node wrote, most
 * often from a thread the model could not answer ("Answer once" on the gap
 * report). Knowledge, never a page: it reaches the model only through the
 * index, as path faq/local/{id} tagged `current`, re-indexed on every save and
 * removed when the entry is deleted (specs/support_chatbot.md, FAQs).
 *
 * The release it was written on travels with the answer to the model, so an
 * answer written for an older release reads as such; the FAQ page lists the
 * oldest-written first so stale ones come up for a reread.
 *
 * @version 0.1.0
 */

class SupportFaqEntryException extends SystemBaseException {}

class SupportFaqEntry extends SystemBase {
	public static $prefix = 'sfq';
	public static $tablename = 'sfq_faq_entries';
	public static $pkey_column = 'sfq_faq_entry_id';

	const QUESTION_MAX = 500;
	const ANSWER_MAX = 20000;

	public static $field_specifications = array(
		'sfq_faq_entry_id'          => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'sfq_question'              => array('type' => 'varchar(500)', 'is_nullable' => false, 'required' => true),
		'sfq_answer'                => array('type' => 'text', 'is_nullable' => false, 'required' => true),
		'sfq_topic'                 => array('type' => 'varchar(64)', 'is_nullable' => true),
		'sfq_sth_support_thread_id' => array('type' => 'int8', 'is_nullable' => true),
		'sfq_author_user_id'        => array('type' => 'int8', 'is_nullable' => true),
		'sfq_release'               => array('type' => 'varchar(64)', 'is_nullable' => true),
		// How many turns cited it since it was written (read_doc_section on its path).
		'sfq_cited_count'           => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'sfq_create_time'           => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'sfq_update_time'           => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'sfq_delete_time'           => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	protected static $foreign_key_actions = array(
		'sfq_sth_support_thread_id' => array('action' => 'null'),
		'sfq_author_user_id'        => array('action' => 'null', 'source_table' => 'usr_users'),
	);

	function authenticate_write($data) {
		if (($data['current_user_permission'] ?? 0) < 9) {
			throw new SystemAuthenticationError('FAQ entries are written by the operator.');
		}
	}

	/** The index path of this entry. */
	public function indexPath(): string {
		return 'faq/local/' . (int)$this->key;
	}

	/** Save, then put the entry in the index as it now reads (or take it out, when deleted). */
	function save($debug = false) {
		$question = trim((string)$this->get('sfq_question'));
		$answer = trim((string)$this->get('sfq_answer'));
		if ($question === '' || $answer === '') {
			throw new SupportFaqEntryException('An entry needs a question and an answer.');
		}
		if (mb_strlen($question) > self::QUESTION_MAX || mb_strlen($answer) > self::ANSWER_MAX) {
			throw new SupportFaqEntryException('Keep the question under ' . self::QUESTION_MAX
				. ' characters and the answer under ' . number_format(self::ANSWER_MAX) . '.');
		}
		$this->set('sfq_question', $question);
		$this->set('sfq_answer', $answer);
		if ($this->key) {
			$this->set('sfq_update_time', gmdate('Y-m-d H:i:s'));
		}
		$result = parent::save($debug);
		SupportDocIndex::indexLocalFaq($this);
		return $result;
	}

	function soft_delete() {
		$result = parent::soft_delete();
		SupportDocIndex::removeLocalFaq((int)$this->key);
		return $result;
	}

	function permanent_delete($debug = false) {
		$id = (int)$this->key;
		$result = parent::permanent_delete($debug);
		SupportDocIndex::removeLocalFaq($id);
		return $result;
	}

	/** Count one citation, in one statement. */
	public static function noteCited(int $id): void {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'UPDATE sfq_faq_entries SET sfq_cited_count = sfq_cited_count + 1 WHERE sfq_faq_entry_id = ?');
		$stmt->execute(array($id));
	}
}

class MultiSupportFaqEntry extends SystemMultiBase {
	protected static $model_class = 'SupportFaqEntry';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['thread_id'])) {
			$filters['sfq_sth_support_thread_id'] = array((int)$this->options['thread_id'], PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('sfq_faq_entries', $filters, $this->order_by, $only_count, $debug);
	}
}
