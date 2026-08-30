<?php

/**
 * drive_stat — batch entity fetch, the companion to the change feed.
 *
 * drive_changes deliberately carries id-only rows: the feed stays small and
 * cheap no matter how much a client is behind. This is where a client turns
 * that list of ids back into entities, in one round trip instead of hundreds.
 *
 * Entities the caller cannot see come back under `missing` rather than as an
 * error, because a sync client must be able to tell "this is gone / no longer
 * shared with me" (act on it: remove the local copy) from "the request failed"
 * (act on it: retry later). Those are opposite behaviors and guessing wrong
 * either deletes the user's files or stalls the sync forever.
 *
 * `not_yours` names the ones that still exist and are simply no longer the
 * caller's — an unshare rather than a deletion. It is a subset of `missing`.
 */

if (!defined('DRIVE_STAT_MAX')) {
	define('DRIVE_STAT_MAX', 500);
}

function drive_stat_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
	require_once(PathHelper::getIncludePath('data/folders_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/file_key_grants_class.php'));

	$settings = Globalvars::get_instance();
	$session  = SessionControl::get_instance();
	$user_id  = (int)$session->get_user_id();

	if (!$settings->get_setting('drive_active')) {
		return LogicResult::error('Drive is not enabled.');
	}
	if (!$user_id) {
		return LogicResult::error('You must be signed in to use Drive.');
	}

	$entities = isset($input['entities']) && is_array($input['entities']) ? $input['entities'] : array();
	if (empty($entities)) {
		return LogicResult::error('No entities were requested.');
	}
	if (count($entities) > DRIVE_STAT_MAX) {
		return LogicResult::error('At most ' . DRIVE_STAT_MAX . ' entities may be requested at once.');
	}
	$with_urls = !empty($input['urls']);

	// Normalize and dedupe the request. A client replaying a feed run will ask
	// for the same file several times (created, then renamed, then content);
	// answering once is both correct and cheaper.
	$wanted = array();
	foreach ($entities as $e) {
		if (!is_array($e)) {
			continue;
		}
		$type = isset($e['entity_type']) ? (string)$e['entity_type'] : '';
		$id   = isset($e['entity_id']) ? (int)$e['entity_id'] : 0;
		if ($id <= 0 || ($type !== DriveHelper::ENTITY_FILE && $type !== DriveHelper::ENTITY_FOLDER)) {
			continue;
		}
		$wanted[$type . ':' . $id] = array('entity_type' => $type, 'entity_id' => $id);
	}
	if (empty($wanted)) {
		return LogicResult::error('No valid entities were requested.');
	}

	// Load and access-check first, so the batch helpers below only do work for
	// entities that will actually be in the response.
	$permission = $session->get_permission();
	$loaded = array();
	$missing = array();
	// Gone and no-longer-mine are both invisible, and they are not the same
	// thing. A file that was deleted may still have local edits worth rescuing
	// into a new file; a file the caller was unshared from must simply go. Told
	// only "missing", a client rescues the bytes and leaves a copy of somebody
	// else's file behind under a new name, which is the exact opposite of what
	// unsharing is for. `not_yours` is a SUBSET of `missing`, deliberately: a
	// client that has never heard of it behaves exactly as it did before.
	$not_yours = array();
	foreach ($wanted as $key => $req) {
		$entity = DriveHelper::load_entity($req['entity_type'], $req['entity_id']);
		if (!$entity) {
			$missing[] = $req;
			continue;
		}
		if (!DriveHelper::can_read($req['entity_type'], $entity, $user_id, $permission)) {
			$missing[] = $req;
			$not_yours[] = $req;
			continue;
		}
		$loaded[$key] = array('req' => $req, 'entity' => $entity);
	}

	// Per-file extras in three queries for the whole batch rather than three per
	// file: sizes, content identity, and the caller's wrapped keys.
	$file_ids = array();
	foreach ($loaded as $row) {
		if ($row['req']['entity_type'] === DriveHelper::ENTITY_FILE) {
			$file_ids[] = (int)$row['req']['entity_id'];
		}
	}
	$size_map    = DriveHelper::file_sizes($file_ids);
	$key_map     = FileKeyGrant::wrapped_keys_for_user($file_ids, $user_id);
	$starred_set = DriveHelper::starred_file_ids($user_id);
	DriveHelper::prime_sync_meta($file_ids);

	$items = array();
	foreach ($loaded as $row) {
		if ($row['req']['entity_type'] === DriveHelper::ENTITY_FOLDER) {
			$items[] = DriveHelper::folder_export($row['entity']);
			continue;
		}
		$fid = (int)$row['req']['entity_id'];
		$items[] = DriveHelper::file_export(
			$row['entity'],
			isset($size_map[$fid]) ? $size_map[$fid] : null,
			isset($starred_set[$fid]),
			isset($key_map[$fid]) ? $key_map[$fid] : null,
			$with_urls
		);
	}

	return LogicResult::render(array(
		'ok'        => true,
		'items'     => $items,
		'missing'   => $missing,
		'not_yours' => $not_yours,
	));
}

function drive_stat_logic_descriptor(): array {
	return array(
		'description'      => 'Batch-fetch Drive entities by id — the companion to the id-only change feed. Entities that are gone or no longer visible to the caller are returned under `missing` instead of erroring, so a sync client can tell "deleted / unshared" from "request failed". Those that still exist but are no longer shared with the caller are also listed under `not_yours`, a subset of `missing`. Signed download and thumbnail URLs are omitted unless `urls` is true.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'entities' => array(
				'type'      => 'array',
				'required'  => true,
				'max_items' => DRIVE_STAT_MAX,
				'label'     => 'Entities to fetch',
				'items'     => array(
					'entity_type' => array('type' => 'string', 'required' => true, 'enum' => array('file', 'folder'), 'label' => 'Entity type'),
					'entity_id'   => array('type' => 'int', 'required' => true, 'min' => 1, 'label' => 'Entity id'),
				),
			),
			'urls' => array('type' => 'bool', 'required' => false, 'label' => 'Mint signed download/thumbnail URLs (off by default)'),
		),
	);
}
?>
