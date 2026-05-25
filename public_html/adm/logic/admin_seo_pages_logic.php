<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_seo_pages_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/seo_page_metadata_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();

	// Actions
	if (isset($input['action'])) {
		if ($input['action'] === 'scan_now') {
			try {
				$result = SeoPageMetadata::sync_inventory();
				$summary = sprintf(
					'Scan complete. Inserted: %d entity / %d static. Path updates: %d. Soft-deleted: %d.',
					$result['inserted_entity'], $result['inserted_static'],
					$result['updated_path'], $result['soft_deleted']
				);
				if (!empty($result['errors'])) {
					$summary .= ' Errors: ' . count($result['errors']);
				}
				return LogicResult::redirect('/admin/admin_seo_pages?notice=' . urlencode($summary));
			} catch (\Throwable $e) {
				return LogicResult::redirect('/admin/admin_seo_pages?error=' . urlencode($e->getMessage()));
			}
		}
		if ($input['action'] === 'soft_delete' && !empty($input['spm_id'])) {
			try {
				$row = new SeoPageMetadata((int)$input['spm_id'], TRUE);
				$row->soft_delete();
				return LogicResult::redirect('/admin/admin_seo_pages?notice=' . urlencode('Row soft-deleted.'));
			} catch (\Throwable $e) {
				return LogicResult::redirect('/admin/admin_seo_pages?error=' . urlencode($e->getMessage()));
			}
		}
	}

	$view = LibraryFunctions::fetch_variable_local($input, 'view', 'list');

	if ($view === 'orphans') {
		$orphans = SeoPageMetadata::find_orphans();
		return LogicResult::render(array(
			'session'  => $session,
			'view'     => 'orphans',
			'orphans'  => $orphans,
			'notice'   => $input['notice'] ?? null,
			'error'    => $input['error'] ?? null,
		));
	}

	$numperpage = 50;
	$offset     = LibraryFunctions::fetch_variable_local($input, 'offset', 0);
	$sort       = LibraryFunctions::fetch_variable_local($input, 'sort', 'path');
	$sdirection = LibraryFunctions::fetch_variable_local($input, 'sdirection', 'ASC');
	$searchterm = LibraryFunctions::fetch_variable_local($input, 'searchterm', '');
	$filter     = LibraryFunctions::fetch_variable_local($input, 'filter', 'all');

	$search_criteria = array();
	if ($searchterm !== '') {
		$search_criteria['searchterm'] = $searchterm;
	}
	if ($filter === 'overrides') {
		$search_criteria['has_overrides'] = true;
	} elseif ($filter === 'noindex') {
		$search_criteria['noindex'] = true;
	} elseif ($filter === 'static') {
		$search_criteria['entity_type'] = null;
	}

	$rows = new MultiSeoPageMetadata($search_criteria, array($sort => $sdirection), $numperpage, $offset);
	$numrecords = $rows->count_all();
	$rows->load();

	return LogicResult::render(array(
		'session'    => $session,
		'view'       => 'list',
		'rows'       => $rows,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
		'searchterm' => $searchterm,
		'filter'     => $filter,
		'notice'     => $input['notice'] ?? null,
		'error'      => $input['error'] ?? null,
	));
}
?>
