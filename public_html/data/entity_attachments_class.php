<?php
/**
 * EntityAttachment — a file attached to a post, a message, or any other row a
 * member writes, by entity type and id.
 *
 * The bytes are an ordinary File. A consumer gates that File on what it is
 * attached to — fil_access_provider names the consumer's AccessGateProvider,
 * fil_access_ref the entity — so a file is exactly as visible as the thing it
 * was posted on, through core's ordinary file serving. Each consumer stamps its
 * own fil_source tag (declared under fileSources in its plugin.json).
 *
 * The attachment row is the list a page shows (name, type, size, order); the
 * File is what serves. removeAllFor() takes both away when the entity goes.
 *
 * @version 1.0.0
 */

class EntityAttachment extends SystemBase {

	public static $prefix = 'eat';
	public static $tablename = 'eat_entity_attachments';
	public static $pkey_column = 'eat_entity_attachment_id';

	public static $field_specifications = array(
		'eat_entity_attachment_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'eat_entity_type'          => array('type' => 'varchar(50)', 'is_nullable' => false, 'required' => true),
		'eat_entity_id'            => array('type' => 'int8', 'is_nullable' => false, 'required' => true),
		'eat_fil_file_id'          => array('type' => 'int8', 'is_nullable' => false, 'required' => true, 'index' => true),
		'eat_usr_user_id'          => array('type' => 'int4', 'is_nullable' => true),
		'eat_filename'             => array('type' => 'varchar(255)', 'is_nullable' => false, 'required' => true),
		'eat_mime_type'            => array('type' => 'varchar(128)', 'is_nullable' => true),
		'eat_byte_size'            => array('type' => 'int8', 'is_nullable' => false, 'default' => 0),
		'eat_sort_order'           => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'eat_create_time'          => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		'eat_delete_time'          => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	public static $index_specifications = array(
		array('columns' => array('eat_entity_type', 'eat_entity_id', 'eat_sort_order')),
	);

	protected static $foreign_key_actions = array(
		// The attachment row is the File's only meaning; it goes with the File.
		'eat_fil_file_id' => array('action' => 'cascade', 'source_table' => 'fil_files'),
		'eat_usr_user_id' => array('action' => 'set_value', 'value' => User::USER_DELETED),
	);

	public function isImage(): bool {
		return strpos((string)$this->get('eat_mime_type'), 'image/') === 0
			&& File::is_inline_safe_type((string)$this->get('eat_mime_type'));
	}

	/**
	 * An entity's live attachments, in order.
	 *
	 * @return EntityAttachment[]
	 */
	public static function forEntity(string $type, int $id): array {
		$out = array();
		foreach (new MultiEntityAttachment(array('eat_entity_type' => $type, 'eat_entity_id' => $id, 'deleted' => false),
			array('eat_sort_order' => 'ASC', 'eat_entity_attachment_id' => 'ASC')) as $attachment) {
			$out[] = $attachment;
		}
		return $out;
	}

	/**
	 * Every entity's live attachments for a set of ids, keyed by entity id —
	 * one query for a page of posts.
	 *
	 * @param int[] $ids
	 * @return array<int,EntityAttachment[]>
	 */
	public static function forEntities(string $type, array $ids): array {
		$ids = array_values(array_filter(array_map('intval', $ids)));
		$out = array();
		if (!$ids) {
			return $out;
		}
		foreach (new MultiEntityAttachment(array('eat_entity_type' => $type, 'entity_ids' => $ids, 'deleted' => false),
			array('eat_sort_order' => 'ASC', 'eat_entity_attachment_id' => 'ASC')) as $attachment) {
			$out[(int)$attachment->get('eat_entity_id')][] = $attachment;
		}
		return $out;
	}

	/**
	 * Remove an entity's attachments for good: the rows, and the Files under them.
	 */
	public static function removeAllFor(string $type, int $id): void {
		foreach (new MultiEntityAttachment(array('eat_entity_type' => $type, 'eat_entity_id' => $id)) as $attachment) {
			$file_id = (int)$attachment->get('eat_fil_file_id');
			$attachment->permanent_delete();
			if ($file_id > 0 && File::check_if_exists($file_id)) {
				$file = new File($file_id, TRUE);
				$file->permanent_delete();
			}
		}
	}
}

class MultiEntityAttachment extends SystemMultiBase {
	protected static $model_class = 'EntityAttachment';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		// entity_ids => int[]: the attachments of any of these entities.
		if (isset($this->options['entity_ids'])) {
			$ids = array_values(array_filter(array_map('intval', (array)$this->options['entity_ids'])));
			$filters['eat_entity_id'] = $ids ? 'IN (' . implode(',', $ids) . ')' : 'IN (0)';
		}

		return $this->_get_resultsv2('eat_entity_attachments', $filters, $this->order_by, $only_count, $debug);
	}
}
