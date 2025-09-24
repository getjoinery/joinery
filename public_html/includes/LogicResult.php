<?php
/**
 * Standard result object for logic functions
 * Provides consistent return format and redirect handling
 *
 * Phase 1: Basic implementation for returns and redirects only
 * Phase 2: Will add error handling and validation support
 */
class LogicResult {
    public $redirect = null;    // URL to redirect to (if any)
    public $data = [];          // Data to pass to view
    public $error = null;       // Error message (if any) - Phase 2

    /**
     * Factory method for creating a redirect result
     * @param string $url The URL to redirect to
     * @param array $data Optional data to pass (not used in redirects typically)
     * @return LogicResult
     */
    public static function redirect($url, $data = []) {
        $result = new self();
        $result->redirect = $url;
        $result->data = $data;
        return $result;
    }

    /**
     * Factory method for creating a render result with data for the view
     * @param array $data Data to pass to the view
     * @return LogicResult
     */
    public static function render($data = []) {
        $result = new self();
        $result->data = $data;
        return $result;
    }

    /**
     * Factory method for creating an error result
     * Note: In Phase 1, errors are still thrown as exceptions
     * This is here for Phase 2 compatibility
     * @param string $message The error message
     * @param array $data Optional data to pass along with error
     * @return LogicResult
     */
    public static function error($message, $data = []) {
        $result = new self();
        $result->error = $message;
        $result->data = $data;
        return $result;
    }
}