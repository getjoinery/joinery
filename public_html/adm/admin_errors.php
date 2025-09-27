<?php

PathHelper::requireOnce('includes/AdminPage.php');

PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/ErrorLogParser.php');
PathHelper::requireOnce('includes/Pager.php');
PathHelper::requireOnce('data/users_class.php');
PathHelper::requireOnce('data/phone_number_class.php');

$session = SessionControl::get_instance();
$session->check_permission(9); // Admin permission level 9
$session->set_return();

$page = new AdminPage();
$settings = Globalvars::get_instance();

// Get parameters
$numperpage = 30;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
$sort = LibraryFunctions::fetch_variable('sort', 'count', 0, '');
$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
$view = LibraryFunctions::fetch_variable('view', 'recent', 0, ''); // 'recent', 'grouped', or 'database'

// Initialize data based on view
if ($view === 'database') {
    // Database errors - original functionality
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

$page->admin_header([
    'menu-id' => 'errors',
    'page_title' => 'System Errors',
    'readable_title' => 'System Error Management',
    'breadcrumbs' => NULL,
    'session' => $session,
]);

// View toggle buttons
?>
<div class="btn-toolbar mb-3" role="toolbar">
    <div class="btn-group mr-2" role="group">
        <a href="?view=recent" class="btn btn-<?= $view === 'recent' ? 'primary' : 'outline-secondary' ?>">
            Recent Errors
        </a>
        <a href="?view=grouped" class="btn btn-<?= $view === 'grouped' ? 'primary' : 'outline-secondary' ?>">
            Grouped View
        </a>
        <a href="?view=database" class="btn btn-<?= $view === 'database' ? 'primary' : 'outline-secondary' ?>">
            Database Errors
        </a>
    </div>
</div>

<?php

// Set up table based on view type
if ($view === 'database') {
    $headers = array("Delete", "User", "Type", "Time", "Error", "File", "Line", "Context");
    $sortoptions = array();
    $title = 'Database Error Log';
} elseif ($view === 'grouped') {
    $headers = array("Count", "Level", "Type", "Message", "Location", "Last Seen", "Details");
    $sortoptions = array(
        "Count" => "count",
        "Last Seen" => "time",
        "Type" => "type"
    );
    $title = 'Grouped File Errors';
} else {
    $headers = array("Timestamp", "Level", "Type", "Message", "Location", "User");
    $sortoptions = array(
        "Timestamp" => "time",
        "Type" => "type"
    );
    $title = 'Recent File Errors';
}

$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array(
    'sortoptions' => $sortoptions,
    'title' => $title,
    'search_on' => FALSE
);

$page->tableheader($headers, $table_options, $pager);

// Display rows based on view
if ($view === 'database') {
    // Database errors - original functionality
    foreach ($errors as $error) {
        $user_name = '';
        if($error->get('err_usr_user_id')) {
            $user = new User($error->get('err_usr_user_id'), TRUE);
            $user_name = $user->display_name();
        }
        
        $rowvalues = array();
        $delete_string = '
        <form action="/admin/admin_errors_delete" method="post">
            <input type="hidden" id="message" name="message" value="'.base64_encode($error->get('err_message')).'">
            <input type="hidden" id="file" name="file" value="'.$error->get('err_file').'">
            <input type="hidden" id="line" name="line" value="'.$error->get('err_line').'">
          <button type="submit" class="btn btn-sm btn-outline-secondary">Delete</button>
        </form>
        ';
        
        array_push($rowvalues, $delete_string);		
        array_push($rowvalues, $user_name);
        array_push($rowvalues, $error->get('err_level'));
        array_push($rowvalues, LibraryFunctions::convert_time($error->get('err_create_time'), "UTC", $session->get_timezone(), 'M j, h:ia'));
        array_push($rowvalues, $error->get('err_message'));
        // Break up long file paths every 3 slashes
        $file_path = $error->get('err_file');
        $file_display = preg_replace('/(\/[^\/]*\/[^\/]*\/[^\/]*)/', '$1<wbr>', htmlspecialchars($file_path));
        $file_display = '<small class="text-monospace" style="max-width: 250px; display: inline-block;">' . $file_display . '</small>';
        array_push($rowvalues, $file_display);		
        array_push($rowvalues, $error->get('err_line'));
        array_push($rowvalues, $error->get('err_context'));

        $page->disprow($rowvalues);
    }
} elseif ($view === 'grouped') {
    // Grouped file errors
    foreach ($file_errors as $hash => $error) {
        $rowvalues = array();
        
        // Count badge - use inline styles to ensure visibility
        $count_html = '<span class="badge" style="background-color: #dc3545; color: white; padding: 3px 8px;">' . $error['count'] . '</span>';
        array_push($rowvalues, $count_html);
        
        // Level badge - use inline styles with different colors for each level
        $levelColor = '#6c757d'; // default secondary color
        switch($error['level']) {
            case 'CRITICAL': $levelColor = '#dc3545'; break; // red
            case 'ERROR': $levelColor = '#fd7e14'; break;    // orange
            case 'WARNING': $levelColor = '#ffc107'; break;  // yellow
            case 'INFO': $levelColor = '#17a2b8'; break;     // teal/info color
            case 'SECURITY': $levelColor = '#212529'; break; // dark
        }
        $level_html = '<span class="badge" style="background-color: ' . $levelColor . '; color: white; padding: 3px 8px;">' . $error['level'] . '</span>';
        array_push($rowvalues, $level_html);
        
        // Type
        array_push($rowvalues, htmlspecialchars($error['type']));
        
        // Message (truncated)
        $message = htmlspecialchars(substr($error['message'], 0, 100));
        if (strlen($error['message']) > 100) $message .= '...';
        array_push($rowvalues, $message);
        
        // Location
        $location = htmlspecialchars(basename($error['file'])) . ':' . $error['line'];
        array_push($rowvalues, '<small class="text-monospace">' . $location . '</small>');
        
        // Last seen
        array_push($rowvalues, LibraryFunctions::convert_time(
            date('Y-m-d H:i:s', $error['last_unix']), 
            "UTC", 
            $session->get_timezone(), 
            'M j, h:ia'
        ));
        
        // Details expandable
        $details_html = '<details><summary>View</summary>';
        $details_html .= '<div style="padding: 10px; background: #f8f9fa; margin-top: 10px;">';
        $details_html .= '<strong>Full Path:</strong> ' . htmlspecialchars($error['file']) . '<br>';
        $details_html .= '<strong>First Seen:</strong> ' . $error['first_seen'] . '<br>';
        $details_html .= '<strong>Last Seen:</strong> ' . $error['last_seen'] . '<br>';
        
        if (!empty($error['user_ids'])) {
            $details_html .= '<strong>Affected Users:</strong> ' . implode(', ', $error['user_ids']) . '<br>';
        }
        
        if (!empty($error['request_uris'])) {
            $details_html .= '<strong>Sample URIs:</strong><br>';
            foreach (array_slice($error['request_uris'], 0, 3) as $uri) {
                $details_html .= '&nbsp;&nbsp;• ' . htmlspecialchars($uri) . '<br>';
            }
        }
        
        if (!empty($error['sample_trace'])) {
            $details_html .= '<strong>Stack Trace:</strong><pre style="font-size: 0.8rem; max-height: 200px; overflow-y: auto;">';
            foreach ($error['sample_trace'] as $i => $frame) {
                $details_html .= '#' . $i . ' ' . htmlspecialchars($frame['file'] ?? 'unknown') . ':' . ($frame['line'] ?? '?');
                if (!empty($frame['function'])) {
                    $details_html .= ' ' . htmlspecialchars($frame['function']) . '()';
                }
                $details_html .= "\n";
            }
            $details_html .= '</pre>';
        }
        
        $details_html .= '</div></details>';
        array_push($rowvalues, $details_html);
        
        $page->disprow($rowvalues);
    }
} else {
    // Recent file errors view
    foreach ($file_errors as $error) {
        $rowvalues = array();
        
        // Timestamp - use unix_time if available, fallback to parsing timestamp
        if ($error['unix_time']) {
            $formatted_time = LibraryFunctions::convert_time(
                date('Y-m-d H:i:s', $error['unix_time']), 
                "UTC", 
                $session->get_timezone(), 
                'M j, h:ia'
            );
        } else {
            // Fallback for timestamps that couldn't be parsed
            $formatted_time = date('M j, h:ia', strtotime($error['timestamp']));
        }
        array_push($rowvalues, $formatted_time);
        
        // Level badge - use inline styles with different colors for each level
        $levelColor = '#6c757d'; // default secondary color
        switch($error['level']) {
            case 'CRITICAL': $levelColor = '#dc3545'; break; // red
            case 'ERROR': $levelColor = '#fd7e14'; break;    // orange
            case 'WARNING': $levelColor = '#ffc107'; break;  // yellow
            case 'INFO': $levelColor = '#17a2b8'; break;     // teal/info color
            case 'SECURITY': $levelColor = '#212529'; break; // dark
        }
        $level_html = '<span class="badge" style="background-color: ' . $levelColor . '; color: white; padding: 3px 8px;">' . $error['level'] . '</span>';
        array_push($rowvalues, $level_html);
        
        // Type
        array_push($rowvalues, htmlspecialchars($error['type']));
        
        // Message
        array_push($rowvalues, htmlspecialchars($error['message']));
        
        // Location
        $location = htmlspecialchars(basename($error['file'])) . ':' . $error['line'];
        array_push($rowvalues, '<small class="text-monospace">' . $location . '</small>');
        
        // User
        if ($error['user_id']) {
            $user_display = 'User #' . $error['user_id'];
            try {
                $user = new User($error['user_id'], TRUE);
                $user_display = $user->display_name();
            } catch (Exception $e) {
                // Keep default display
            }
            array_push($rowvalues, $user_display);
        } else {
            array_push($rowvalues, '<span class="text-muted">Guest</span>');
        }
        
        $page->disprow($rowvalues);
    }
}

$page->endtable($pager);

if (($view !== 'database') && empty($file_errors)) {
    echo '<div class="alert alert-info">No errors found in the log file.</div>';
}

// Show parsing statistics for grouped view
if ($view === 'grouped') {
    $stats = $parser->getLastParsingStats();
    echo '<div class="alert alert-info mt-3">';
    echo '<strong>Parsing Statistics:</strong><br>';
    echo 'Individual errors parsed: ' . number_format($stats['errors_parsed'] ?? 0) . '<br>';
    echo 'Unique error groups created: ' . number_format($stats['groups_created'] ?? 0);
    echo '</div>';
}

$page->admin_footer();
?>

