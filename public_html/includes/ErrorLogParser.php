<?php
require_once(__DIR__ . '/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));

class ErrorLogParser {
    private $logFile;
    private $maxLines;
    private $cacheDuration = 60; // Cache parsed results for 60 seconds
    private static $cache = null;
    private static $cacheTime = 0;
    private $lastParsingStats = [];
    
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
     * @param bool $lightweight Skip heavy processing for speed
     * @return array
     */
    public function getRecentErrors($limit = 100, $lightweight = false) {
        if ($lightweight) {
            $allErrors = $this->parseLogFile($limit * 3, true); // Read 3x limit, skip heavy processing
        } else {
            $allErrors = $this->parseLogFile();
        }
        
        // Sort by timestamp descending
        usort($allErrors, function($a, $b) {
            return $b['unix_time'] - $a['unix_time'];
        });
        
        return array_slice($allErrors, 0, $limit);
    }
    
    /**
     * Parse log file but keep reading until we get a target number of PHP errors
     * @param int $targetErrors Target number of PHP errors to find
     * @param int $maxLines Maximum lines to read (safety limit)
     * @return array
     */
    public function parseLogFileForTargetErrors($targetErrors = 2000, $maxLines = 50000) {
        if (!file_exists($this->logFile) || !is_readable($this->logFile)) {
            return [];
        }
        
        $errors = [];
        $linesRead = 0;
        $handle = fopen($this->logFile, 'r');
        
        if (!$handle) {
            return [];
        }
        
        // Get file size and start from end
        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);
        $line = '';
        
        // Read backwards until we have enough errors or hit limits
        while ($pos > 0 && count($errors) < $targetErrors && $linesRead < $maxLines) {
            $pos--;
            fseek($handle, $pos);
            $char = fgetc($handle);
            
            if ($char === "\n" || $pos === 0) {
                if (trim($line) !== '') {
                    $parsed = $this->parseStandardErrorLine(strrev($line));
                    if ($parsed) {
                        $errors[] = $parsed;
                    }
                    $linesRead++;
                }
                $line = '';
            } else {
                $line = $char . $line;
            }
        }
        
        fclose($handle);
        
        // Store stats
        $this->lastParsingStats = [
            'lines_read' => $linesRead,
            'errors_parsed' => count($errors),
            'groups_created' => 0
        ];
        
        return array_reverse($errors); // Reverse to get chronological order
    }
    
    /**
     * Get grouped errors with counts
     * @return array
     */
    public function getGroupedErrors() {
        $allErrors = $this->parseLogFile();
        $grouped = [];
        
        // Store stats for debugging
        $this->lastParsingStats = [
            'lines_read' => $this->maxLines,
            'errors_parsed' => count($allErrors),
            'groups_created' => 0
        ];
        
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
        
        $this->lastParsingStats['groups_created'] = count($grouped);
        return $grouped;
    }
    
    /**
     * Get parsing statistics from last operation
     * @return array
     */
    public function getLastParsingStats() {
        return $this->lastParsingStats;
    }
    
    /**
     * Parse the log file
     * @param int $maxLines Override default max lines
     * @param bool $lightweight Skip heavy processing (no cache, less parsing)
     * @return array
     */
    private function parseLogFile($maxLines = null, $lightweight = false) {
        $linesToRead = $maxLines ?? $this->maxLines;
        
        // Skip cache in lightweight mode
        if (!$lightweight) {
            // Check cache
            if (self::$cache !== null && (time() - self::$cacheTime) < $this->cacheDuration) {
                return self::$cache;
            }
        }
        
        if (!file_exists($this->logFile) || !is_readable($this->logFile)) {
            return [];
        }
        
        $errors = [];
        $lines = $this->readLastLines($this->logFile, $linesToRead);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if ($lightweight) {
                // Lightweight mode: simple string matching instead of complex regex
                if (strpos($line, '[php:warn]') !== false && strpos($line, 'PHP Warning:') !== false) {
                    $parsed = $this->parseStandardErrorLine($line);
                    if ($parsed) {
                        $errors[] = $parsed;
                    }
                }
            } else {
                // Full mode: try JSON first, then complex parsing
                $decoded = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['timestamp'])) {
                    $errors[] = $decoded;
                } else {
                    $parsed = $this->parseStandardErrorLine($line);
                    if ($parsed) {
                        $errors[] = $parsed;
                    }
                }
            }
        }
        
        // Update cache only in full mode
        if (!$lightweight) {
            self::$cache = $errors;
            self::$cacheTime = time();
        }
        
        return $errors;
    }
    
    /**
     * Parse standard PHP error log format
     * @param string $line
     * @return array|null
     */
    private function parseStandardErrorLine($line) {
        // Try Apache error log format first: [timestamp] [php:level] [pid] [client IP:port] PHP Warning: message in file on line X
        $apache_pattern = '/\[(.*?)\]\s+\[php:(.*?)\]\s+\[pid\s+\d+\]\s+\[client\s+[^\]]+\]\s+(PHP\s+.*?):\s+(.*?)\s+in\s+(.*?)\s+on\s+line\s+(\d+)/';
        
        if (preg_match($apache_pattern, $line, $matches)) {
            $timestamp = $matches[1];
            $apache_level = trim($matches[2]);
            $php_type = trim($matches[3]);
            $message = trim($matches[4]);
            $file = trim($matches[5]);
            $line_num = intval($matches[6]);
            
            // Convert Apache timestamp to Unix timestamp
            // Apache format: "Wed Aug 20 11:30:39.792566 2025"
            $unix_time = strtotime(preg_replace('/\.\d+/', '', $timestamp));
            
            // Map Apache levels to our levels - this should match the actual log level
            $level = 'ERROR';
            switch (strtolower($apache_level)) {
                case 'warn':
                    $level = 'WARNING';
                    break;
                case 'error':
                    $level = 'ERROR';
                    break;
                case 'crit':
                case 'critical':
                    $level = 'CRITICAL';
                    break;
                case 'alert':
                case 'emerg':
                    $level = 'CRITICAL';
                    break;
                case 'notice':
                    $level = 'INFO';  // Keep notices but label them correctly
                    break;
                default:
                    $level = 'ERROR'; // fallback
            }
            
            $error = [
                'timestamp' => $timestamp,
                'unix_time' => $unix_time,
                'level' => $level,
                'type' => $php_type,
                'message' => $message,
                'file' => $file,
                'line' => $line_num,
                'user_id' => null,
                'request_uri' => $this->extractRequestUri($line),
                'request_method' => 'UNKNOWN',
                'ip_address' => $this->extractIpAddress($line),
                'user_agent' => '',
                'hash' => null
            ];
            
            $error['hash'] = $this->generateHash($error);
            return $error;
        }
        
        // Fallback to standard PHP error format: [timestamp] PHP type: message in file on line X
        $standard_pattern = '/\[(.*?)\]\s+(?:PHP\s+)?(.*?):\s+(.*?)\s+in\s+(.*?)\s+on\s+line\s+(\d+)/';
        
        if (preg_match($standard_pattern, $line, $matches)) {
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
     * Extract IP address from Apache error line
     * @param string $line
     * @return string
     */
    private function extractIpAddress($line) {
        if (preg_match('/\[client\s+([^:]+):/', $line, $matches)) {
            return $matches[1];
        }
        return 'unknown';
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