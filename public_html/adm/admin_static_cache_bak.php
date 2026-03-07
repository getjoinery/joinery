<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/StaticPageCache.php'));

$session = SessionControl::get_instance();
$session->check_permission(9); // Super admin only for cache management
$session->set_return();

$page = new AdminPage();
$settings = Globalvars::get_instance();

// Handle form actions
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($action) {
    $redirect_needed = false;

    switch ($action) {
            case 'enable':
                $result = StaticPageCache::setEnabled(true);
                if ($result) {
                    $message = new DisplayMessage(
                        'Static caching has been enabled.',
                        'Success',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ANNOUNCEMENT,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                } else {
                    // Get the actual cache directory path for better error messaging
                    $cache_dir = PathHelper::getSiteRoot() . '/cache/static_pages/';
                    $message = new DisplayMessage(
                        'Failed to enable caching. Cache directory may not be writable. Check permissions on: ' . $cache_dir,
                        'Error',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ERROR,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                }
                $redirect_needed = true;
                break;

            case 'disable':
                $result = StaticPageCache::setEnabled(false);
                if ($result) {
                    $message = new DisplayMessage(
                        'Static caching has been disabled.',
                        'Success',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ANNOUNCEMENT,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                } else {
                    $message = new DisplayMessage(
                        'Failed to disable caching. Cache directory may not be writable.',
                        'Error',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ERROR,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                }
                $redirect_needed = true;
                break;

            case 'clear_all':
                $count = StaticPageCache::clearAll();
                $message = new DisplayMessage(
                    "Cleared {$count} cached files.",
                    'Success',
                    '/\/admin\/admin_static_cache.*/',
                    DisplayMessage::MESSAGE_ANNOUNCEMENT,
                    DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                    'cache_action',
                    true
                );
                $session->save_message($message);
                $redirect_needed = true;
                break;

            case 'invalidate_url':
                $url = LibraryFunctions::fetch_variable('url', '', 1, '');
                if ($url) {
                    $url_parts = parse_url($url);
                    $path = $url_parts['path'] ?? '/';
                    parse_str($url_parts['query'] ?? '', $params);
                    StaticPageCache::invalidateUrl($path, $params);
                    $message = new DisplayMessage(
                        "Invalidated cache for: {$url}",
                        'Success',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ANNOUNCEMENT,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                } else {
                    $message = new DisplayMessage(
                        'Please provide a URL to invalidate.',
                        'Error',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ERROR,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                }
                $redirect_needed = isset($_GET['action']);
                break;

            case 'diagnose_url':
                $url = LibraryFunctions::fetch_variable('diagnose_url', '', 1, '');
                if ($url) {
                    // Add protocol if missing
                    if (!preg_match('/^https?:\/\//', $url)) {
                        $url = 'http://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($url, '/');
                    }
                    $diagnosis = StaticPageCache::diagnoseCacheability($url, true);

                    // Check for actual cached file and its HTML comment
                    $url_parts = parse_url($url);
                    $path = $url_parts['path'] ?? '/';
                    parse_str($url_parts['query'] ?? '', $params);
                    $cache_file_path = StaticPageCache::checkCache($path, $params);

                    if ($cache_file_path && $cache_file_path !== 'nostatic' && file_exists($cache_file_path)) {
                        // Read first few lines to extract the comment
                        $handle = fopen($cache_file_path, 'r');
                        $first_lines = '';
                        for ($i = 0; $i < 5; $i++) {
                            $line = fgets($handle);
                            if ($line === false) break;
                            $first_lines .= $line;
                        }
                        fclose($handle);

                        // Extract the cached URL from the comment
                        $cached_url = null;
                        if (preg_match('/<!-- Cached: (.+?) -->/', $first_lines, $matches)) {
                            $cached_url = $matches[1];
                        }

                        $diagnosis['cached_file_status'] = 'found';
                        $diagnosis['cached_file_path'] = $cache_file_path;
                        $diagnosis['cached_file_size'] = filesize($cache_file_path);
                        $diagnosis['cached_file_modified'] = filemtime($cache_file_path);
                        $diagnosis['cached_url_comment'] = $cached_url;
                    } else {
                        $diagnosis['cached_file_status'] = 'not_found';
                    }

                    // Test live serving - make actual HTTP request to see if cache is served
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HEADER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                    // Don't send cookies - we want to test anonymous access
                    curl_setopt($ch, CURLOPT_COOKIE, '');

                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                    curl_close($ch);

                    if ($response !== false) {
                        $response_headers = substr($response, 0, $header_size);
                        $response_body = substr($response, $header_size);
                        $response_size = strlen($response_body);

                        // Check for X-Cache header
                        $cache_hit = (strpos($response_headers, 'X-Cache: HIT') !== false);

                        // Extract HTML comment from response
                        $response_comment = null;
                        if (preg_match('/<!-- Cached: (.+?) -->/', substr($response_body, 0, 500), $matches)) {
                            $response_comment = $matches[1];
                        }

                        // Check if sizes match (approximately - allow 1% variance)
                        $cached_file_size = isset($diagnosis['cached_file_size']) ? $diagnosis['cached_file_size'] : 0;
                        $size_match = ($cached_file_size > 0) ? (abs($response_size - $cached_file_size) / $cached_file_size < 0.01) : false;

                        $diagnosis['live_serving_status'] = 'success';
                        $diagnosis['live_http_code'] = $http_code;
                        $diagnosis['live_cache_hit'] = $cache_hit;
                        $diagnosis['live_response_size'] = $response_size;
                        $diagnosis['live_response_comment'] = $response_comment;
                        $diagnosis['live_size_matches'] = $size_match;
                    } else {
                        $diagnosis['live_serving_status'] = 'failed';
                        $diagnosis['live_error'] = 'Could not fetch URL';
                    }

                    // Store in session for display
                    $_SESSION['cache_diagnosis'] = $diagnosis;
                    $redirect_needed = isset($_GET['action']);
                } else {
                    $message = new DisplayMessage(
                        'Please provide a URL to diagnose.',
                        'Error',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ERROR,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                    $redirect_needed = isset($_GET['action']);
                }
                break;

            case 'mark_nostatic':
                $url = LibraryFunctions::fetch_variable('url', '', 1, '');
                if ($url) {
                    $url_parts = parse_url($url);
                    $path = $url_parts['path'] ?? '/';
                    parse_str($url_parts['query'] ?? '', $params);
                    StaticPageCache::markAsNostatic($path, $params);
                    $message = new DisplayMessage(
                        "Marked as non-cacheable: {$url}",
                        'Success',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ANNOUNCEMENT,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                } else {
                    $message = new DisplayMessage(
                        'Please provide a URL to mark as non-cacheable.',
                        'Error',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ERROR,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                }
                $redirect_needed = isset($_GET['action']);
                break;

            case 'delete_from_index':
                $url = LibraryFunctions::fetch_variable('url', '', 1, '');
                if ($url) {
                    $url_parts = parse_url($url);
                    $path = $url_parts['path'] ?? '/';
                    parse_str($url_parts['query'] ?? '', $params);

                    // Remove from index (this also deletes cache file if it exists)
                    StaticPageCache::invalidateUrl($path, $params);
                    $message = new DisplayMessage(
                        "Deleted from index: {$url}",
                        'Success',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ANNOUNCEMENT,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                } else {
                    $message = new DisplayMessage(
                        'Please provide a URL to delete.',
                        'Error',
                        '/\/admin\/admin_static_cache.*/',
                        DisplayMessage::MESSAGE_ERROR,
                        DisplayMessage::MESSAGE_DISPLAY_IN_PAGE,
                        'cache_action',
                        true
                    );
                    $session->save_message($message);
                }
                $redirect_needed = isset($_GET['action']);
                break;
        }

    // Redirect to prevent re-execution on refresh (especially for GET actions)
    if ($redirect_needed || isset($_GET['action'])) {
        header('Location: /admin/admin_static_cache');
        exit();
    }
}

// Get any saved messages
$display_messages = $session->get_messages('/admin/admin_static_cache');

// Get cache statistics
$stats = StaticPageCache::getCacheStats();
$cached_urls = StaticPageCache::getCachedUrls(50);

// Get the index to see all entries (cached and nostatic)
$index_file = PathHelper::getSiteRoot() . '/cache/static_pages/index.json';
$all_urls = [];

if (file_exists($index_file)) {
    $index = json_decode(file_get_contents($index_file), true);

    foreach ($index as $hash => $entry) {
        // Skip the _config entry
        if ($hash === '_config') continue;

        // Handle new array format with URL and status
        if (is_array($entry) && isset($entry['status'])) {
            if ($entry['status'] === 'cached') {
                // For cached entries, get file info
                $cache_file = PathHelper::getSiteRoot() . '/cache/static_pages/' . $hash . '.html';
                if (file_exists($cache_file)) {
                    $all_urls[] = [
                        'url' => $entry['url'],
                        'status' => 'cached',
                        'size' => filesize($cache_file),
                        'modified' => isset($entry['time']) ? $entry['time'] : filemtime($cache_file)
                    ];
                }
            } else {
                // For nostatic entries
                $all_urls[] = [
                    'url' => $entry['url'],
                    'status' => $entry['status'],
                    'size' => 0,
                    'modified' => isset($entry['time']) ? $entry['time'] : time()
                ];
            }
        }
    }
}

// Sort by modified time (most recent first)
usort($all_urls, function($a, $b) {
    return $b['modified'] - $a['modified'];
});

$recent_urls = array_slice($all_urls, 0, 50);

// Display admin page
$page->admin_header(array(
    'menu-id' => 'system-cache',
    'page_title' => 'Static Cache Management',
    'readable_title' => 'Static Cache Management',
    'breadcrumbs' => array(
        'System' => '/admin/admin_system',
        'Cache Management' => '',
    ),
    'session' => $session,
));

// Prepare options for the built-in admin dropdown
$altlinks = array();
if ($stats['enabled']) {
    $altlinks['Disable Cache'] = '/admin/admin_static_cache?action=disable';
} else {
    $altlinks['Enable Cache'] = '/admin/admin_static_cache?action=enable';
}
$altlinks['Clear All Cache'] = '/admin/admin_static_cache?action=clear_all';

$page->begin_box(array('altlinks' => $altlinks));

// Display session messages
if (!empty($display_messages)) {
    foreach ($display_messages as $msg) {
        $alert_class = 'alert-info';
        if ($msg->display_type == DisplayMessage::MESSAGE_ERROR) {
            $alert_class = 'alert-danger';
        } elseif ($msg->display_type == DisplayMessage::MESSAGE_WARNING) {
            $alert_class = 'alert-warning';
        } elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) {
            $alert_class = 'alert-success';
        }
        echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
        if ($msg->message_title) {
            echo '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
        }
        echo htmlspecialchars($msg->message);
        // Standard Bootstrap 5 close button
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
    // Clear messages after displaying
    $session->clear_clearable_messages();
}
?>
<!-- Cache Statistics -->
<div class="row">
    <div class="col-12">
        <h5 class="mb-3">Cache Statistics</h5>
        <div class="row">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Status</h6>
                        <p class="h4 text-dark">
                            <?php if ($stats['enabled']): ?>
                                <span class="badge bg-success text-white">Enabled</span>
                            <?php else: ?>
                                <span class="badge bg-danger text-white">Disabled</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Cached Files</h6>
                        <p class="h4"><?php echo number_format($stats['file_count']); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Cache Size</h6>
                        <p class="h4"><?php echo $stats['total_size_mb']; ?> MB</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6>Non-Cacheable URLs</h6>
                        <p class="h4"><?php echo number_format($stats['nostatic_urls']); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cache Diagnostic Tool -->
<div class="row mt-4">
    <div class="col-12">
        <h5 class="mb-3">Cache Diagnostic Tool</h5>
                <?php
                $formwriter = $page->getFormWriter('diagnose_form', ['action' => '/admin/admin_static_cache']);
                $formwriter->begin_form();
                echo $formwriter->textinput('diagnose_url', 'URL to diagnose', ['placeholder' => '/page/about or https://example.com/page', 'maxlength' => 255, 'required' => true]);
                echo $formwriter->hiddeninput('action', 'diagnose_url');
                echo $formwriter->submitbutton('btn_submit', 'Diagnose URL', ['class' => 'btn btn-secondary']);
                echo $formwriter->end_form();

                // Display diagnosis results if available
                if (isset($_SESSION['cache_diagnosis'])) {
                    $diagnosis = $_SESSION['cache_diagnosis'];
                    unset($_SESSION['cache_diagnosis']); // Clear after display

                    // Determine status badge
                    $status_badge = '';
                    switch($diagnosis['status']) {
                        case 'already_cached':
                            $status_badge = '<span class="badge bg-success">✅ Already Cached</span>';
                            break;
                        case 'marked_nostatic':
                            $status_badge = '<span class="badge bg-warning text-dark">⚠️ Non-Cacheable</span>';
                            break;
                        case 'cacheable':
                            $status_badge = '<span class="badge bg-success">✅ Cacheable</span>';
                            break;
                        case 'partial_check':
                            $status_badge = '<span class="badge bg-info">ℹ️ Partial Check</span>';
                            break;
                        default:
                            $status_badge = '<span class="badge bg-danger">❌ Not Cacheable</span>';
                    }

                    // Determine cache file status badge
                    $file_status_badge = '';
                    if (isset($diagnosis['cached_file_status'])) {
                        if ($diagnosis['cached_file_status'] === 'found') {
                            $file_status_badge = '<span class="badge bg-success">✅ Found</span>';
                        } else {
                            $file_status_badge = '<span class="badge bg-secondary">Not Found</span>';
                        }
                    }

                    ?>
                    <div class="mt-4">
                        <h6 class="mb-3">Diagnosis Results for: <code><?= htmlspecialchars($diagnosis['url']) ?></code></h6>

                        <table class="table table-sm table-borderless">
                            <tbody>
                                <tr>
                                    <td style="width: 40%; font-weight: 500;">Cache Status</td>
                                    <td><?= $status_badge ?></td>
                                </tr>
                                <?php if (isset($diagnosis['cached_file_status'])): ?>
                                    <tr>
                                        <td style="font-weight: 500;">Cached File Status</td>
                                        <td><?= $file_status_badge ?></td>
                                    </tr>
                                    <?php if ($diagnosis['cached_file_status'] === 'found'): ?>
                                        <tr>
                                            <td style="font-weight: 500;">File Size</td>
                                            <td><?= round($diagnosis['cached_file_size'] / 1024, 2) ?> KB</td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight: 500;">Last Modified</td>
                                            <td><?= date('Y-m-d H:i:s', $diagnosis['cached_file_modified']) ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight: 500;">HTML Comment</td>
                                            <td>
                                                <?php if ($diagnosis['cached_url_comment']): ?>
                                                    <span class="badge bg-info text-dark">Found</span>
                                                    <div style="font-family: monospace; font-size: 0.85rem; margin-top: 5px;">
                                                        &lt;!-- Cached: <?= htmlspecialchars($diagnosis['cached_url_comment']) ?> --&gt;
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Not Found</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight: 500;">File Path</td>
                                            <td>
                                                <code style="word-break: break-all; font-size: 0.8rem;">
                                                    <?= htmlspecialchars($diagnosis['cached_file_path']) ?>
                                                </code>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <!-- Live Serving Test Results -->
                                <?php if (isset($diagnosis['live_serving_status'])): ?>
                                    <tr style="border-top: 2px solid #dee2e6; padding-top: 10px;">
                                        <td colspan="2" style="font-weight: 600; padding-bottom: 8px;">Live Serving Test</td>
                                    </tr>
                                    <?php if ($diagnosis['live_serving_status'] === 'success'): ?>
                                        <tr>
                                            <td style="font-weight: 500;">Status</td>
                                            <td>
                                                <?php if ($diagnosis['live_cache_hit']): ?>
                                                    <span class="badge bg-success">✅ Cache Hit (X-Cache: HIT)</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">⚠️ Cache Miss</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight: 500;">HTTP Status</td>
                                            <td><?= $diagnosis['live_http_code'] ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight: 500;">Response Size</td>
                                            <td><?= round($diagnosis['live_response_size'] / 1024, 2) ?> KB</td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight: 500;">Size Match</td>
                                            <td>
                                                <?php if ($diagnosis['live_size_matches']): ?>
                                                    <span class="badge bg-success">✅ Yes</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">⚠️ No</span>
                                                <?php endif; ?>
                                                <?php if (isset($diagnosis['cached_file_size'])): ?>
                                                    (Cached: <?= round($diagnosis['cached_file_size'] / 1024, 2) ?> KB)
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight: 500;">HTML Comment</td>
                                            <td>
                                                <?php if ($diagnosis['live_response_comment']): ?>
                                                    <span class="badge bg-info text-dark">Found</span>
                                                    <div style="font-family: monospace; font-size: 0.85rem; margin-top: 5px;">
                                                        &lt;!-- Cached: <?= htmlspecialchars($diagnosis['live_response_comment']) ?> --&gt;
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Not Found</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="2">
                                                <span class="badge bg-danger">✗ Failed</span>
                                                <span><?= htmlspecialchars($diagnosis['live_error'] ?? 'Unknown error') ?></span>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <tr style="border-top: 2px solid #dee2e6; padding-top: 10px;">
                                    <td style="font-weight: 500;">Analysis</td>
                                    <td>
                                        <ul class="mb-0 ps-3">
                                            <?php foreach ($diagnosis['reasons'] as $reason): ?>
                                                <li style="font-size: 0.9rem;"><?= $reason ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                ?>
    </div>
</div>

<!-- URL Management -->
<div class="row mt-4">
    <div class="col-md-6">
        <h5 class="mb-3">Invalidate Specific URL</h5>
                <?php
                $formwriter = $page->getFormWriter('invalidate_form');

                $formwriter = $page->getFormWriter('invalidate_form', ['action' => '/admin/admin_static_cache']);
                $formwriter->begin_form();
                echo $formwriter->textinput('url', 'URL to invalidate', ['placeholder' => '/page/about?param=value', 'maxlength' => 255, 'required' => true]);
                echo $formwriter->hiddeninput('action', 'invalidate_url');
                echo $formwriter->submitbutton('btn_submit', 'Invalidate Cache', ['class' => 'btn btn-secondary']);
                echo $formwriter->end_form();
                ?>
    </div>

    <div class="col-md-6">
        <h5 class="mb-3">Mark URL as Non-Cacheable</h5>
                <?php
                $formwriter = $page->getFormWriter('nostatic_form', ['action' => '/admin/admin_static_cache']);
                $formwriter->begin_form();
                echo $formwriter->textinput('url', 'URL to exclude', ['placeholder' => '/page/dynamic', 'maxlength' => 255, 'required' => true]);
                echo $formwriter->hiddeninput('action', 'mark_nostatic');
                echo $formwriter->submitbutton('btn_submit', 'Mark as Non-Cacheable', ['class' => 'btn btn-secondary']);
                echo $formwriter->end_form();
                ?>
    </div>
</div>

<!-- Recent URLs List -->
<div class="row mt-4">
    <div class="col-12">
        <h5 class="mb-3">Recent URLs</h5>
        <?php
        // Set up table
        $headers = array("URL", "Status", "Size", "Modified", "Actions");

        $pager = new Pager(array('numrecords' => count($recent_urls), 'numperpage' => 50));
        $table_options = array(
            'title' => 'Recent URLs (showing up to 50)',
            'search_on' => FALSE
        );

        $page->tableheader($headers, $table_options, $pager);

        // Display rows
        foreach ($recent_urls as $item) {
            $rowvalues = array();

            // URL column with link
            if (strpos($item['url'], 'non-cacheable') !== false) {
                // This is our summary row for nostatic entries
                array_push($rowvalues, '<em>' . htmlspecialchars($item['url']) . '</em>');
            } else {
                $url_html = '<a href="' . htmlspecialchars($item['url']) . '" target="_blank">' .
                           htmlspecialchars($item['url']) . '</a>';
                array_push($rowvalues, $url_html);
            }

            // Status column
            $status_badge = '';
            if ($item['status'] === 'cached') {
                $status_badge = '<span class="badge bg-success text-white">Cached</span>';
            } elseif ($item['status'] === 'nostatic') {
                $status_badge = '<span class="badge bg-warning text-dark">Not Cacheable</span>';
            } else {
                $status_badge = '<span class="badge bg-secondary text-white">' . htmlspecialchars($item['status']) . '</span>';
            }
            array_push($rowvalues, $status_badge);

            // Size column
            if ($item['size'] > 0) {
                array_push($rowvalues, round($item['size'] / 1024, 1) . ' KB');
            } else {
                array_push($rowvalues, '-');
            }

            // Modified column
            array_push($rowvalues, date('Y-m-d H:i:s', $item['modified']));

            // Actions column
            if ($item['status'] === 'cached') {
                $action_form = '<div class="btn-group" role="group">
                               <form method="post" style="display: inline;">
                                   <input type="hidden" name="url" value="' . htmlspecialchars($item['url']) . '">
                                   <button type="submit" name="action" value="invalidate_url"
                                           class="btn btn-sm btn-secondary">Invalidate</button>
                               </form>
                               <form method="post" style="display: inline;">
                                   <input type="hidden" name="url" value="' . htmlspecialchars($item['url']) . '">
                                   <button type="submit" name="action" value="delete_from_index"
                                           class="btn btn-sm btn-secondary ms-1">Delete</button>
                               </form>
                               </div>';
                array_push($rowvalues, $action_form);
            } elseif ($item['status'] === 'nostatic') {
                $action_form = '<form method="post" style="display: inline;">
                               <input type="hidden" name="url" value="' . htmlspecialchars($item['url']) . '">
                               <button type="submit" name="action" value="delete_from_index"
                                       class="btn btn-sm btn-secondary">Delete</button>
                               </form>';
                array_push($rowvalues, $action_form);
            } else {
                array_push($rowvalues, '-');
            }

            $page->disprow($rowvalues);
        }

        $page->endtable($pager);
        ?>
    </div>
</div>

<?php
$page->end_box();
$page->admin_footer();
?>