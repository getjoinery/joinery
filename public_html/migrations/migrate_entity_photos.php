<?php
/**
 * Migration: Populate eph_entity_photos from existing single-FK columns
 *
 * Maps existing entity picture FKs into the new polymorphic entity_photos table.
 * Uses ON CONFLICT DO NOTHING for idempotency (safe to run multiple times).
 *
 * @version 1.0.0
 */
function migrate_entity_photos() {
	$dbconnector = DbConnector::get_instance();
	$dblink = $dbconnector->get_db_link();

	$entity_maps = [
		['type' => 'user',         'table' => 'usr_users',          'pkey' => 'usr_user_id',          'fk' => 'usr_pic_picture_id',  'delete' => 'usr_delete_time'],
		['type' => 'event',        'table' => 'evt_events',         'pkey' => 'evt_event_id',         'fk' => 'evt_fil_file_id',     'delete' => 'evt_delete_time'],
		['type' => 'location',     'table' => 'loc_locations',      'pkey' => 'loc_location_id',      'fk' => 'loc_fil_file_id',     'delete' => 'loc_delete_time'],
		['type' => 'mailing_list', 'table' => 'mlt_mailing_lists',  'pkey' => 'mlt_mailing_list_id',  'fk' => 'mlt_fil_file_id',     'delete' => 'mlt_delete_time'],
	];

	foreach ($entity_maps as $map) {
		$sql = "INSERT INTO eph_entity_photos (eph_entity_type, eph_entity_id, eph_fil_file_id, eph_is_primary, eph_sort_order)
				SELECT :type, {$map['pkey']}, {$map['fk']}, true, 0
				FROM {$map['table']}
				WHERE {$map['fk']} IS NOT NULL AND {$map['delete']} IS NULL
				ON CONFLICT (eph_entity_type, eph_entity_id, eph_fil_file_id) DO NOTHING";
		$q = $dblink->prepare($sql);
		$q->execute(['type' => $map['type']]);
	}
}
