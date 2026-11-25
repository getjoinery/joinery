<?php
/**
 * The sample's model: a note, written as a first-party model is. The site
 * reads this file without running it and makes the table sbx_notes_sample__nte_notes
 * from it; the plugin's own code loads it as written.
 */
class Note extends SystemBase {
	public static $prefix = 'nte';
	public static $tablename = 'nte_notes';
	public static $pkey_column = 'nte_note_id';
	public static $access_class = 'content';

	public static $field_specifications = array(
		'nte_note_id'     => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'nte_usr_user_id' => array('type' => 'int8', 'is_nullable' => true, 'index' => true),
		'nte_text'        => array('type' => 'varchar(200)', 'required' => true),
		'nte_pinned'      => array('type' => 'bool', 'default' => false),
		'nte_create_time' => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'nte_delete_time' => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	/** A note goes with the member who wrote it. */
	protected static $foreign_key_actions = array(
		'nte_usr_user_id' => array('action' => 'cascade'),
	);

	/** The note as one line. */
	public function line(): string {
		return ($this->get('nte_pinned') ? '[pinned] ' : '') . (string)$this->get('nte_text');
	}

	/** A plugin's own rule, run in the fence before the save is asked for. */
	function prepare() {
		if (trim((string)$this->get('nte_text')) === '') {
			throw new RuntimeException('A note needs some text.');
		}
	}
}

class MultiNote extends SystemMultiBase {
	protected static $model_class = 'Note';
}
