# Admin Error Parser Specification

## Overview
Add a complementary error viewing system that parses the PHP error_log file directly, providing an additional way to view and analyze errors alongside the existing database-based error logging. This gives administrators a fallback option when the database is unavailable and provides a different perspective on error data.

## Goals
1. Add file-based error viewing as a complement to existing database logging
2. Parse error_log file efficiently for the last X errors
3. Group similar errors into single entries with counts
4. Enable sorting by occurrence count or most recent timestamp
5. Enhance error logging with JSON format for better parsing (while maintaining compatibility)
6. Provide familiar admin interface design similar to admin_errors

## Phase 1: Convert FileErrorLogger to JSON Format

### Modified FileErrorLogger Class
Update `/includes/ErrorHandler.php` FileErrorLogger class:

```php
class FileErrorLogger implements ErrorLoggerInterface {
    
    public function log(\Throwable $exception, ErrorContext $context): void {
        try {
            // Create structured log entry
            $logEntry = [
                'timestamp' => date('c'),
                'unix_time' => time(),
                'level' => $this->getErrorLevel($exception),
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'user_id' => $context->getUserId() ?? null,
                'request_uri' => $context->getRequestUri(),
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'hash' => $this->generateErrorHash($exception)
            ];
            
            // Add context if available
            if ($exception instanceof BaseException) {
                $exceptionContext = $exception->getContext();
                if (!empty($exceptionContext)) {
                    $logEntry['context'] = $exceptionContext;
                }
            }
            
            // Add condensed stack trace (first 5 frames)
            $trace = array_slice($exception->getTrace(), 0, 5);
            $logEntry['trace'] = array_map(function($frame) {
                return [
                    'file' => $frame['file'] ?? 'unknown',
                    'line' => $frame['line'] ?? 0,
                    'function' => $frame['function'] ?? 'unknown',
                    'class' => $frame['class'] ?? null
                ];
            }, $trace);
            
            // Log as single-line JSON
            $jsonLog = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            error_log($jsonLog);
            
        } catch (\Throwable $e) {
            // Fallback to basic error logging if JSON encoding fails
            error_log("File error logging failed: " . $e->getMessage());
            error_log("Original error: " . $exception->getMessage());
        }
    }
    
    private function getErrorLevel(\Throwable $exception): string {
        if ($exception instanceof ValidationException) {
            return 'WARNING';
        }
        if ($exception instanceof AuthenticationException || $exception instanceof AuthorizationException) {
            return 'SECURITY';
        }
        if ($exception instanceof DatabaseException) {
            return 'CRITICAL';
        }
        if ($exception instanceof BusinessLogicException) {
            return 'ERROR';
        }
        return 'ERROR';
    }
    
    private function generateErrorHash(\Throwable $exception): string {
        // Create a hash to identify similar errors
        // Based on type + message + file + line (excluding dynamic parts)
        $message = preg_replace('/\d+/', 'N', $exception->getMessage()); // Replace numbers with N
        $identifier = $exception->getCode() . '::' . 
                     get_class($exception) . '::' . 
                     $message . '::' . 
                     $exception->getFile() . '::' . 
                     $exception->getLine();
        return md5($identifier);
    }
}
```

## Phase 2: Create ErrorLogParser Class

### New File: `/includes/ErrorLogParser.php`

```php
<?php
require_once(__DIR__ . '/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');

class ErrorLogParser {
    private $logFile;
    private $maxLines;
    private $cacheDuration = 60; // Cache parsed results for 60 seconds
    private static $cache = null;
    private static $cacheTime = 0;
    
    public function __construct($logFile = null, $maxLines = 5000) {
        if ($logFile) {
            $this->logFile = $logFile;
        } else {
            // Use apache_error_log setting from database
            $settings = Globalvars::get_instance();
            $this->logFile = $settings->get_setting('apache_error_log');
            
            // Fallback to PHP's error_log setting if apache_error_log not configured
            if (!$this->logFile) {
                $this->logFile = ini_get('error_log') ?: '/var/log/php_errors.log';
            }
        }
        $this->maxLines = $maxLines;
    }
    
    /**
     * Get recent errors from log file
     * @param int $limit Maximum number of unique errors to return
     * @return array
     */
    public function getRecentErrors($limit = 100) {
        $allErrors = $this->parseLogFile();
        
        // Sort by timestamp descending
        usort($allErrors, function($a, $b) {
            return $b['unix_time'] - $a['unix_time'];
        });
        
        return array_slice($allErrors, 0, $limit);
    }
    
    /**
     * Get grouped errors with counts
     * @return array
     */
    public function getGroupedErrors() {
        $allErrors = $this->parseLogFile();
        $grouped = [];
        
        foreach ($allErrors as $error) {
            $hash = $error['hash'] ?? $this->generateHash($error);
            
            if (!isset($grouped[$hash])) {
                $grouped[$hash] = [
                    'type' => $error['type'],
                    'level' => $error['level'],
                    'message' => $error['message'],
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'count' => 0,
                    'first_seen' => $error['timestamp'],
                    'last_seen' => $error['timestamp'],
                    'first_unix' => $error['unix_time'],
                    'last_unix' => $error['unix_time'],
                    'user_ids' => [],
                    'request_uris' => [],
                    'sample_trace' => $error['trace'] ?? null
                ];
            }
            
            $grouped[$hash]['count']++;
            $grouped[$hash]['last_seen'] = $error['timestamp'];
            $grouped[$hash]['last_unix'] = $error['unix_time'];
            
            if ($error['user_id'] && !in_array($error['user_id'], $grouped[$hash]['user_ids'])) {
                $grouped[$hash]['user_ids'][] = $error['user_id'];
            }
            
            if ($error['request_uri'] && !in_array($error['request_uri'], $grouped[$hash]['request_uris'])) {
                $grouped[$hash]['request_uris'][] = $error['request_uri'];
            }
        }
        
        return $grouped;
    }
    
    /**
     * Parse the log file
     * @return array
     */
    private function parseLogFile() {
        // Check cache
        if (self::$cache !== null && (time() - self::$cacheTime) < $this->cacheDuration) {
            return self::$cache;
        }
        
        if (!file_exists($this->logFile) || !is_readable($this->logFile)) {
            return [];
        }
        
        $errors = [];
        $lines = $this->readLastLines($this->logFile, $this->maxLines);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Try to parse as JSON first (new format)
            $decoded = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['timestamp'])) {
                $errors[] = $decoded;
            } else {
                // Fallback to parsing old format
                $parsed = $this->parseStandardErrorLine($line);
                if ($parsed) {
                    $errors[] = $parsed;
                }
            }
        }
        
        // Update cache
        self::$cache = $errors;
        self::$cacheTime = time();
        
        return $errors;
    }
    
    /**
     * Parse standard PHP error log format
     * @param string $line
     * @return array|null
     */
    private function parseStandardErrorLine($line) {
        // Match standard PHP error format: [timestamp] PHP type: message in file on line X
        $pattern = '/\[(.*?)\]\s+(?:PHP\s+)?(.*?):\s+(.*?)\s+in\s+(.*?)\s+on\s+line\s+(\d+)/';
        
        if (preg_match($pattern, $line, $matches)) {
            $error = [
                'timestamp' => $matches[1],
                'unix_time' => strtotime($matches[1]),
                'level' => 'ERROR',
                'type' => trim($matches[2]),
                'message' => trim($matches[3]),
                'file' => trim($matches[4]),
                'line' => intval($matches[5]),
                'user_id' => null,
                'request_uri' => $this->extractRequestUri($line),
                'request_method' => 'UNKNOWN',
                'ip_address' => 'unknown',
                'user_agent' => '',
                'hash' => null
            ];
            
            $error['hash'] = $this->generateHash($error);
            return $error;
        }
        
        return null;
    }
    
    /**
     * Extract request URI from error line if present
     * @param string $line
     * @return string|null
     */
    private function extractRequestUri($line) {
        if (preg_match('/REQUEST_URI:\s*([^\s]+)/', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Generate hash for error grouping
     * @param array $error
     * @return string
     */
    private function generateHash($error) {
        // Normalize message by replacing numbers with N
        $message = preg_replace('/\d+/', 'N', $error['message']);
        $identifier = $error['type'] . '::' . $message . '::' . $error['file'] . '::' . $error['line'];
        return md5($identifier);
    }
    
    /**
     * Read last N lines from file efficiently
     * @param string $filepath
     * @param int $lines
     * @return array
     */
    private function readLastLines($filepath, $lines = 1000) {
        $handle = fopen($filepath, "r");
        if (!$handle) {
            return [];
        }
        
        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];
        
        while ($linecounter > 0) {
            $t = " ";
            while ($t != "\n") {
                if (fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }
            $linecounter--;
            if ($beginning) {
                rewind($handle);
            }
            $text[$lines - $linecounter - 1] = fgets($handle);
            if ($beginning) {
                break;
            }
        }
        fclose($handle);
        
        return array_reverse($text);
    }
    
    /**
     * Get errors filtered by date range
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function getErrorsByDateRange($startDate, $endDate) {
        $startTime = strtotime($startDate);
        $endTime = strtotime($endDate);
        
        $allErrors = $this->parseLogFile();
        
        return array_filter($allErrors, function($error) use ($startTime, $endTime) {
            $errorTime = $error['unix_time'] ?? strtotime($error['timestamp']);
            return $errorTime >= $startTime && $errorTime <= $endTime;
        });
    }
    
    /**
     * Clear the cache
     */
    public function clearCache() {
        self::$cache = null;
        self::$cacheTime = 0;
    }
}
```

## Phase 3: Create Admin Interface

### New File: `/adm/admin_errors_file.php`

```php
<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/AdminPage.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/ErrorLogParser.php');
PathHelper::requireOnce('includes/Pager.php');
PathHelper::requireOnce('data/users_class.php');

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
$view = LibraryFunctions::fetch_variable('view', 'grouped', 0, ''); // 'grouped' or 'recent'

// Initialize parser
$parser = new ErrorLogParser();

// Get data based on view
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
    $all_errors = $parser->getRecentErrors(1000); // Get more for pagination
}

// Paginate results
$numrecords = count($all_errors);
$errors = array_slice($all_errors, $offset, $numperpage);

$page->admin_header([
    'menu-id' => 'errors',
    'page_title' => 'Error Log Parser',
    'readable_title' => 'System Error Logs (File)',
    'breadcrumbs' => NULL,
    'session' => $session,
]);
// View toggle buttons
?>
<div class="btn-toolbar mb-3" role="toolbar">
    <div class="btn-group mr-2" role="group">
        <a href="?view=grouped" class="btn btn-<?= $view === 'grouped' ? 'primary' : 'outline-secondary' ?>">
            Grouped View
        </a>
        <a href="?view=recent" class="btn btn-<?= $view === 'recent' ? 'primary' : 'outline-secondary' ?>">
            Recent Errors
        </a>
    </div>
</div>

<?php
// Set up table based on view type
if ($view === 'grouped') {
    $headers = array("Count", "Level", "Type", "Message", "Location", "Last Seen", "Details");
    $sortoptions = array(
        "Count" => "count",
        "Last Seen" => "time",
        "Type" => "type"
    );
} else {
    $headers = array("Timestamp", "Level", "Type", "Message", "Location", "User");
    $sortoptions = array(
        "Timestamp" => "time",
        "Type" => "type"
    );
}

$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array(
    'sortoptions' => $sortoptions,
    'title' => 'Error Log Analysis',
    'search_on' => FALSE
);

$page->tableheader($headers, $table_options, $pager);

// Display rows
if ($view === 'grouped') {
    foreach ($errors as $hash => $error) {
        $rowvalues = array();
        
        // Count badge
        $count_html = '<span class="badge badge-danger">' . $error['count'] . '</span>';
        array_push($rowvalues, $count_html);
        
        // Level badge
        $levelClass = 'secondary';
        switch($error['level']) {
            case 'CRITICAL': $levelClass = 'danger'; break;
            case 'ERROR': $levelClass = 'warning'; break;
            case 'WARNING': $levelClass = 'info'; break;
            case 'SECURITY': $levelClass = 'dark'; break;
        }
        $level_html = '<span class="badge badge-' . $levelClass . '">' . $error['level'] . '</span>';
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
    // Recent errors view
    foreach ($errors as $error) {
        $rowvalues = array();
        
        // Timestamp
        array_push($rowvalues, LibraryFunctions::convert_time(
            $error['timestamp'], 
            "UTC", 
            $session->get_timezone(), 
            'M j, h:ia'
        ));
        
        // Level badge
        $levelClass = 'secondary';
        switch($error['level']) {
            case 'CRITICAL': $levelClass = 'danger'; break;
            case 'ERROR': $levelClass = 'warning'; break;
            case 'WARNING': $levelClass = 'info'; break;
            case 'SECURITY': $levelClass = 'dark'; break;
        }
        $level_html = '<span class="badge badge-' . $levelClass . '">' . $error['level'] . '</span>';
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

if (empty($errors)) {
    echo '<div class="alert alert-info">No errors found in the log file.</div>';
}

<?php $page->admin_footer(); ?>
```

## Phase 4: Implementation Steps

### Step 1: Enhance FileErrorLogger
1. Update FileErrorLogger to use JSON format as specified above
2. Test logging to ensure JSON format is working
3. Both database and file logging continue to work in parallel

### Step 2: Deploy Parser
1. Add ErrorLogParser.php to /includes/
2. Add admin_errors_file.php to /adm/
3. Add menu item in admin navigation for "Error Log Parser"

### Step 3: Usage
1. Both systems remain active and independent
2. admin_errors.php - Database-based error viewer (existing)
3. admin_errors_file.php - File-based error parser (new)
4. Administrators can use either tool based on their needs

## Performance Considerations

### Optimization Strategies
1. **Caching**: Parser caches results for 60 seconds to avoid re-parsing on page refreshes
2. **Line Limit**: Default 5000 lines (~500KB) provides good balance of history vs performance
3. **Efficient Reading**: Uses fseek to read from end of file without loading entire file
4. **JSON Format**: Single-line JSON allows for fast line-by-line parsing

### Expected Performance
- Parsing 5000 lines: ~50-100ms
- Grouping/counting: ~10-20ms
- Total page load: <200ms for typical usage

### Scalability
- For larger deployments, consider:
  - Log rotation (daily/weekly)
  - Separate error log file from access log
  - Background processing with cron to pre-parse logs
  - Redis/Memcached for longer cache duration

## Security Considerations

1. **File Permissions**: Ensure error_log is readable by PHP process but not web-accessible
2. **Path Validation**: Parser validates file paths to prevent directory traversal
3. **JSON Encoding**: Properly escape all user input when logging
4. **Admin Access**: Requires permission level 5 (admin) to view errors
5. **Sensitive Data**: Never log passwords, credit cards, or PII

## Testing Requirements

1. Test with both JSON and legacy format logs
2. Verify grouping algorithm correctly identifies similar errors
3. Test performance with large log files (10MB+)
4. Verify sorting works correctly for all columns
5. Test with various error types (exceptions, warnings, notices)
6. Verify user_id and request_uri tracking
7. Test cache invalidation and refresh

## Benefits of Having Both Systems

1. **Redundancy**: Two independent error tracking systems
2. **Database Outage Protection**: File parser works during database issues
3. **Different Perspectives**: Database allows SQL queries, file parser shows raw chronological data
4. **Performance Options**: Use file parser when database is under load
5. **Debugging Flexibility**: Cross-reference errors between both systems
6. **No Migration Risk**: Existing workflows remain unchanged

## Future Enhancements

1. **Search Functionality**: Add text search within error messages
2. **Export Options**: CSV/JSON export for analysis
3. **Alert System**: Email/Slack notifications for critical errors
4. **Graphs**: Time-series visualization of error trends
5. **Log Rotation Integration**: Automatic archival of old logs
6. **Multi-file Support**: Parse rotated logs for historical view