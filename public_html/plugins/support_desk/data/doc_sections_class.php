<?php
/**
 * SupportDocSection — one searchable section of what the support desk knows.
 *
 * Every source lands in this one table and every tool reads it without caring
 * where a row came from (specs/support_chatbot.md, The knowledge index):
 *
 *   doc        a section of a release's docs/*.md or a plugin's docs/*.md
 *   faq        a question of the shipped FAQ (docs/faq/*.md, a plugin's too);
 *              its heading IS the question
 *   page       a public page of this node (page contents), tagged `current`
 *   local_faq  an entry an operator wrote here (SupportFaqEntry), tagged `current`
 *
 * A release's rows are written once by SupportDocIndex and never edited; the
 * `current` rows are rebuilt (pages) or rewritten on save (local FAQ).
 * Search is Postgres full text over sds_search_text, through FullTextSearch so
 * the query expression is exactly the index's.
 *
 * @version 0.1.0
 */

class SupportDocSection extends SystemBase {
	public static $prefix = 'sds';
	public static $tablename = 'sds_doc_sections';
	public static $pkey_column = 'sds_doc_section_id';

	/** The release tag of rows that belong to no release. */
	const CURRENT = 'current';

	const KIND_DOC       = 'doc';
	const KIND_FAQ       = 'faq';
	const KIND_PAGE      = 'page';
	const KIND_LOCAL_FAQ = 'local_faq';

	public static $field_specifications = array(
		'sds_doc_section_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'sds_release'        => array('type' => 'varchar(64)', 'is_nullable' => false),
		'sds_kind'           => array('type' => 'varchar(16)', 'is_nullable' => false),
		// Where the section lives: docs/backups.md, plugins/mailbox/docs/overview.md,
		// docs/faq/mail.md, page/{slug}, faq/local/{id}.
		'sds_path'           => array('type' => 'varchar(500)', 'is_nullable' => false),
		// The heading chain, outermost first, joined with " > ".
		'sds_heading'        => array('type' => 'varchar(1000)', 'is_nullable' => false),
		// Which part of a section split for length (0 for an unsplit one).
		'sds_part'           => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'sds_body'           => array('type' => 'text', 'is_nullable' => false),
		// Heading chain + body: what full text search reads.
		'sds_search_text'    => array('type' => 'text', 'is_nullable' => false),
		// The local FAQ entry or page a `current` row was made from.
		'sds_ref_id'         => array('type' => 'int8', 'is_nullable' => true),
		'sds_create_time'    => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	public static $index_specifications = array(
		array('columns' => array('sds_release', 'sds_path')),
		// FullTextSearch::indexSpec('sds_search_text'); the engine test asserts the two are equal.
		array('columns' => array("to_tsvector('english', sds_search_text)"), 'method' => 'gin'),
	);

	function authenticate_write($data) {
		if (($data['current_user_permission'] ?? 0) < 9) {
			throw new SystemAuthenticationError('The support index is written by the support desk.');
		}
	}
}

class MultiSupportDocSection extends SystemMultiBase {
	protected static $model_class = 'SupportDocSection';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['release'])) {
			$filters['sds_release'] = array($this->options['release'], PDO::PARAM_STR);
		}
		if (isset($this->options['path'])) {
			$filters['sds_path'] = array($this->options['path'], PDO::PARAM_STR);
		}
		if (isset($this->options['kind'])) {
			$filters['sds_kind'] = array($this->options['kind'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('sds_doc_sections', $filters, $this->order_by, $only_count, $debug);
	}
}
