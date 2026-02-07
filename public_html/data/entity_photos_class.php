<?php
/**
 * EntityPhoto - Polymorphic photo association for any entity type
 *
 * Links fil_files records to entities (users, events, locations, etc.)
 * via entity_type + entity_id pattern. Supports ordering, primary flag,
 * and captions.
 *
 * @version 1.0.0
 * @see /specs/pictures_refactor_spec.md
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));

class EntityPhoto extends SystemBase {
	public static $prefix = 'eph';
	public static $tablename = 'eph_entity_photos';
	public static $pkey_column = 'eph_entity_photo_id';

	public static $field_specifications = array(
		'eph_entity_photo_id' => array('type'=>'int4', 'is_nullable'=>false, 'serial'=>true),
		'eph_entity_type' => array('type'=>'varchar(50)', 'is_nullable'=>false, 'required'=>true),
		'eph_entity_id' => array('type'=>'int4', 'is_nullable'=>false, 'required'=>true),
		'eph_fil_file_id' => array('type'=>'int4', 'is_nullable'=>false, 'required'=>true, 'unique_with'=>array('eph_entity_type', 'eph_entity_id')),
		'eph_sort_order' => array('type'=>'int2', 'default'=>0),
		'eph_is_primary' => array('type'=>'bool', 'default'=>'false'),
		'eph_caption' => array('type'=>'varchar(255)'),
		'eph_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'eph_delete_time' => array('type'=>'timestamp(6)'),
	);

	/**
	 * Get the primary photo for an entity
	 *
	 * @param string $entity_type Entity type string (e.g. 'user', 'event')
	 * @param int $entity_id Entity primary key
	 * @return EntityPhoto|null
	 */
	public static function get_primary($entity_type, $entity_id) {
		$photos = new MultiEntityPhoto(
			['entity_type' => $entity_type, 'entity_id' => $entity_id, 'is_primary' => true, 'deleted' => false],
			['eph_sort_order' => 'ASC'],
			1
		);
		$photos->load();
		if ($photos->count() > 0) {
			return $photos->get(0);
		}
		return null;
	}

	/**
	 * Override save to enforce per-entity-type photo limits and validate file exists
	 */
	function save($debug = false) {
		// Validate file exists
		$file_id = $this->get('eph_fil_file_id');
		if ($file_id && !$this->key) {
			// Only check on insert
			if (!File::check_if_exists($file_id)) {
				throw new DisplayableUserException('The specified file does not exist.');
			}
		}

		// Enforce photo limit on insert
		if (!$this->key) {
			$entity_type = $this->get('eph_entity_type');
			$entity_id = $this->get('eph_entity_id');

			$settings = Globalvars::get_instance();
			$limits_json = $settings->get_setting('max_entity_photos', true, true);
			if ($limits_json) {
				$limits = json_decode($limits_json, true);
				if (is_array($limits) && isset($limits[$entity_type])) {
					$max = (int) $limits[$entity_type];

					// Check current count
					$existing = new MultiEntityPhoto(
						['entity_type' => $entity_type, 'entity_id' => $entity_id, 'deleted' => false]
					);
					$count = $existing->count_all();

					if ($count >= $max) {
						// Allow admins to bypass
						$session = SessionControl::get_instance();
						if (!$session->is_logged_in() || $session->get_permission() < 5) {
							throw new DisplayableUserException("Maximum of {$max} photos allowed for this item.");
						}
					}
				}
			}
		}

		return parent::save($debug);
	}
}

class MultiEntityPhoto extends SystemMultiBase {
	protected static $model_class = 'EntityPhoto';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['entity_type'])) {
			$filters['eph_entity_type'] = [$this->options['entity_type'], PDO::PARAM_STR];
		}

		if (isset($this->options['entity_id'])) {
			$filters['eph_entity_id'] = [$this->options['entity_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['file_id'])) {
			$filters['eph_fil_file_id'] = [$this->options['file_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['is_primary'])) {
			$filters['eph_is_primary'] = $this->options['is_primary'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['eph_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		$sorts = [];
		if (!empty($this->order_by)) {
			$sorts = $this->order_by;
		}

		return $this->_get_resultsv2('eph_entity_photos', $filters, $sorts, $only_count, $debug);
	}
}
