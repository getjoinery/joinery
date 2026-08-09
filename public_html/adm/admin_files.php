<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	//$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'file_id', 0, '');
	$filter = LibraryFunctions::fetch_variable('filter', 'default', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$search_criteria = array();
	if($searchterm){
		$search_criteria['filename_like'] = $searchterm;
	}

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	// Files the system made for its own use are never listed here, whatever else
	// is picked — including under "All files". The set is declared once in
	// File::source_catalog(); this page never names a source itself.
	$search_criteria['sources_not'] = File::internal_sources();

	// One `filter` parameter holds one pick, so an Origin choice and a Kind choice
	// REPLACE each other — picking "Images only" widens back to every non-internal
	// origin. They are grouped in the dropdown to show they ask different questions,
	// not to suggest they combine. Combining them needs a second parameter and a
	// second control; see the spec's open items.
	if($filter == 'files'){
		$search_criteria['picture'] = false;
	}
	else if($filter == 'images'){
		$search_criteria['picture'] = true;
	}
	else if($filter == 'default'){
		$search_criteria['sources'] = File::default_view_sources();
	}
	else if(strpos($filter, 'src_') === 0){
		$source = substr($filter, 4);
		// Only a declared, listable source narrows the listing. A hand-typed tag
		// for an internal source falls through to the default view rather than
		// becoming a way around the exclusion above.
		if(isset(File::source_catalog()[$source]) && File::source_is_listable($source)){
			$search_criteria['sources'] = array($source);
		}
		else{
			$search_criteria['sources'] = File::default_view_sources();
		}
	}
	else{
		// 'all' — every non-internal file.
	}

	$files = new MultiFile(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'AND');
	$files->load();	
	$numrecords = $files->count_all();

	// Per-source counts for the browse rail. One grouped query rather than a count
	// per source, and it carries the same deleted-row rule the listing above uses so
	// a rail number always matches what clicking it shows.
	$source_counts = array();
	$dblink = DbConnector::get_instance()->get_db_link();
	$count_sql = "SELECT COALESCE(fil_source, " . $dblink->quote(File::SOURCE_UNCLASSIFIED) . ") AS src, COUNT(*) AS n
	              FROM fil_files";
	if($_SESSION['permission'] < 10){
		$count_sql .= " WHERE fil_delete_time IS NULL";
	}
	$count_sql .= " GROUP BY 1";
	foreach($dblink->query($count_sql)->fetchAll(PDO::FETCH_ASSOC) as $count_row){
		$source_counts[$count_row['src']] = (int)$count_row['n'];
	}

	$default_total = 0;
	foreach(File::default_view_sources() as $default_source){
		$default_total += isset($source_counts[$default_source]) ? $source_counts[$default_source] : 0;
	}
	$listable_total = 0;
	foreach($source_counts as $count_key => $count_value){
		if(File::source_is_listable($count_key)){
			$listable_total += $count_value;
		}
	}

	// Toggle lives at the page header's right edge. Two real buttons rather than one
	// that changes meaning, so the current mode is visible rather than inferred.
	$view_toggle = '<div class="jy-view-toggle" role="group" aria-label="View style">'
		. '<button type="button" class="btn btn-sm btn-soft-default" data-files-view="list" aria-pressed="true" title="List view">'
		. '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">'
		. '<line x1="5" y1="4" x2="14" y2="4"/><line x1="5" y1="8" x2="14" y2="8"/><line x1="5" y1="12" x2="14" y2="12"/>'
		. '<line x1="2" y1="4" x2="2" y2="4"/><line x1="2" y1="8" x2="2" y2="8"/><line x1="2" y1="12" x2="2" y2="12"/></svg>'
		. '<span class="jy-visually-hidden">List view</span></button>'
		. '<button type="button" class="btn btn-sm btn-soft-default" data-files-view="browse" aria-pressed="false" title="Browse view">'
		. '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">'
		. '<rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/>'
		. '<rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>'
		. '<span class="jy-visually-hidden">Browse view</span></button>'
		. '</div>';

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'files',
		'page_title' => 'Files',
		'readable_title' => 'Files',
		'breadcrumbs' => array(
			'Files'=>'',
		),
		'session' => $session,
		'header_action' => $view_toggle,
	)
	);

	$headers = array('Thumb','File', 'File Type', 'Uploaded', 'By');
	$altlinks = array('Upload file'=>'/admin/admin_file_upload');
	$title= 'Files';
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	// Built from the catalog, so declaring a source is all it takes to appear here —
	// this page never lists one by name. Internal sources are absent entirely rather
	// than present-and-excluded: an option that returns nothing is worse than no
	// option at all.
	$origin_options = array('Uploaded files' => 'default', 'All files' => 'all');
	foreach(File::source_catalog() as $source_key => $source_spec){
		if(!empty($source_spec['internal'])){
			continue;
		}
		$origin_options[$source_spec['label']] = 'src_' . $source_key;
	}

	$table_options = array(
		'filteroptions'=>array(
			'Origin' => $origin_options,
			'Kind'   => array('Images only' => 'images', 'Files only' => 'files'),
		),
		'altlinks' => $altlinks,
		'title' => $title,
		//'search_on' => TRUE
	);
	// Wrapper the toggle switches a class on. The rail is a sibling of the card, and
	// CSS puts them side by side in browse mode — so the rows below are emitted ONCE
	// and the two modes are presentation over the same markup, not two renderings.
	echo '<div id="jyFilesBrowser" class="jy-files jy-files-list">';

	// Rail: the dropdown's Origin group in spatial form. Both write the same
	// `filter` parameter, so a link into either lands in the same place.
	echo '<aside class="jy-files-rail" aria-label="Filter by origin">';
	$rail_items = array(
		array('label' => 'Uploaded files', 'value' => 'default', 'count' => $default_total),
		array('label' => 'All files',      'value' => 'all',     'count' => $listable_total),
	);
	foreach(File::source_catalog() as $source_key => $source_spec){
		if(!empty($source_spec['internal'])){
			continue;
		}
		$rail_items[] = array(
			'label' => $source_spec['label'],
			'value' => 'src_' . $source_key,
			// A source with no rows still gets a row of its own, greyed: an empty
			// "Drive" tells you Drive exists and is empty, which is information.
			'count' => isset($source_counts[$source_key]) ? $source_counts[$source_key] : 0,
		);
	}
	foreach($rail_items as $rail_item){
		$is_active = ($filter === $rail_item['value']);
		$rail_class = 'jy-files-rail-item'
			. ($is_active ? ' is-active' : '')
			. ($rail_item['count'] === 0 ? ' is-empty' : '');
		echo '<a href="/admin/admin_files?filter=' . urlencode($rail_item['value']) . '" class="' . $rail_class . '"'
			. ($is_active ? ' aria-current="true"' : '') . '>'
			. '<span class="jy-files-rail-label">' . htmlspecialchars($rail_item['label']) . '</span>'
			. '<span class="jy-files-rail-count">' . (int)$rail_item['count'] . '</span>'
			. '</a>';
	}
	echo '</aside>';

	echo '<div class="jy-files-pane">';
	$page->tableheader($headers, $table_options, $pager);

	foreach($files as $file) {
		$deleted = '';
		if($file->get('fil_delete_time')){
			$deleted = 'DELETED';
		}
		// Not every file has an owner: legacy rows predate the column being filled,
		// and loading a User with no key throws. Ask before constructing — an
		// unowned file is a row to render, not a page to lose.
		$owner_id = (int)$file->get('fil_usr_user_id');
		$owner_name = '—';
		if($owner_id > 0){
			$user = new User($owner_id, TRUE);
			if($user->key){
				$owner_name = $user->display_name();
			}
		}

		$rowvalues = array();
		// One call decides thumbnail-or-icon, and degrades to the icon if the
		// thumbnail turns out not to load — see File::thumbnail_html().
		array_push($rowvalues, $file->thumbnail_html('avatar', 'jy-file-thumb'));
		array_push($rowvalues, '<a href="/admin/admin_file?fil_file_id='.$file->key.'">'.htmlspecialchars($file->get('fil_title')).'</a> '. $deleted);
		array_push($rowvalues, $file->get('fil_type'));

		array_push($rowvalues,  LibraryFunctions::convert_time($file->get('fil_create_time'), "UTC", $session->get_timezone()));
		
		array_push($rowvalues, htmlspecialchars($owner_name));
		$page->disprow($rowvalues);
	}
		
	$page->endtable($pager);
	echo '</div>'; // .jy-files-pane
	echo '</div>'; // #jyFilesBrowser
?>
<script src="/assets/js/file-browser.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/file-browser.js')) ?: '1'; ?>"></script>
<?php

	$page->admin_footer();

?>
