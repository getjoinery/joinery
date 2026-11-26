<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * The Outbox page: every email the site sent on its own behalf, newest
 * first, filtered by address, status, template, record and date
 * (specs/email_outbox.md §6). Read-only, and never the content.
 *
 * @version 1.0
 */
function admin_outbox_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$numperpage = 50;
	$offset = max(0, (int)($input['offset'] ?? 0));

	$filters = array(
		'address'     => trim((string)($input['address'] ?? '')),
		'status'      => trim((string)($input['status'] ?? '')),
		'template'    => trim((string)($input['template'] ?? '')),
		'entity_type' => trim((string)($input['entity_type'] ?? '')),
		'entity_id'   => trim((string)($input['entity_id'] ?? '')),
		'since'       => trim((string)($input['since'] ?? '')),
		'until'       => trim((string)($input['until'] ?? '')),
	);

	$options = array();
	if ($filters['address'] !== '') {
		$options['address'] = $filters['address'];
	}
	if ($filters['status'] !== '' && isset(QueuedEmail::$status_to_text[(int)$filters['status']])) {
		$options['status'] = (int)$filters['status'];
	}
	if ($filters['template'] !== '') {
		$options['equ_template'] = $filters['template'];
	}
	if ($filters['entity_type'] !== '') {
		$options['equ_entity_type'] = $filters['entity_type'];
	}
	if ($filters['entity_id'] !== '' && ctype_digit($filters['entity_id'])) {
		$options['equ_entity_id'] = (int)$filters['entity_id'];
	}
	$day = function ($value) {
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
	};
	if ($day($filters['since']) !== '') {
		$options['since'] = $filters['since'] . ' 00:00:00';
	}
	if ($day($filters['until']) !== '') {
		$options['until'] = gmdate('Y-m-d 00:00:00', strtotime($filters['until'] . ' UTC') + 86400);
	}

	$emails = new MultiQueuedEmail($options, array('equ_queued_email_id' => 'DESC'), $numperpage, $offset);
	$numrecords = $emails->count_all();

	// The templates and record types the outbox holds, for the filters.
	$db = DbConnector::get_instance()->get_db_link();
	$templates = $db->query('SELECT DISTINCT equ_template FROM equ_queued_emails WHERE equ_template IS NOT NULL ORDER BY 1')
		->fetchAll(PDO::FETCH_COLUMN);
	$types = $db->query('SELECT DISTINCT equ_entity_type FROM equ_queued_emails WHERE equ_entity_type IS NOT NULL ORDER BY 1')
		->fetchAll(PDO::FETCH_COLUMN);

	return LogicResult::render(array(
		'emails'     => $emails,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
		'filters'    => $filters,
		'templates'  => $templates,
		'types'      => $types,
	));
}
