<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_errors_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/ErrorLogParser.php'));

	$session = SessionControl::get_instance();

	// Get parameters
	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'count', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$view = LibraryFunctions::fetch_variable('view', 'recent', 0, ''); // 'recent', 'grouped', or 'database'

	// Initialize data based on view
	$errors = NULL;
	$file_errors = array();
	$all_errors = array();
	$numrecords = 0;

	if ($view === 'database') {
		// Database errors - original functionality
		require_once(PathHelper::getIncludePath('data/general_errors_class.php'));
		$search_criteria = array();
		$errors = new MultiGeneralError(
			$search_criteria,
			array('err_create_time' => $sdirection),
			$numperpage,
			$offset);
		$numrecords = $errors->count_all();
		$errors->load();
	} else {
		// File-based errors - new functionality
		$parser = new ErrorLogParser();
		$parser->clearCache(); // Ensure fresh data

		if ($view === 'grouped') {
			$all_errors = $parser->getGroupedErrors();

			// Sort grouped errors
			if ($sort === 'count') {
				usort($all_errors, function($a, $b) use ($sdirection) {
					$result = $b['count'] - $a['count'];
					return $sdirection === 'ASC' ? -$result : $result;
				});
			} elseif ($sort === 'time') {
				usort($all_errors, function($a, $b) use ($sdirection) {
					$result = $b['last_unix'] - $a['last_unix'];
					return $sdirection === 'ASC' ? -$result : $result;
				});
			} elseif ($sort === 'type') {
				usort($all_errors, function($a, $b) use ($sdirection) {
					$result = strcmp($a['type'], $b['type']);
					return $sdirection === 'ASC' ? $result : -$result;
				});
			}
		} else {
			// Recent errors view
			$all_errors = $parser->getRecentErrors(200, true); // Get last 200 errors in lightweight mode
		}

		// Paginate file-based results
		$numrecords = count($all_errors);
		$file_errors = array_slice($all_errors, $offset, $numperpage);
	}

	// Get parsing stats for grouped view
	$parsing_stats = array();
	if ($view === 'grouped') {
		$parsing_stats = $parser->getLastParsingStats();
	}

	// Return data for view
	$result = new LogicResult();
	$result->data = array(
		'view' => $view,
		'numperpage' => $numperpage,
		'offset' => $offset,
		'sort' => $sort,
		'sdirection' => $sdirection,
		'numrecords' => $numrecords,
		'errors' => $errors,
		'file_errors' => $file_errors,
		'parsing_stats' => $parsing_stats,
	);

	return $result;
}
?>
