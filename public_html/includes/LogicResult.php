<?php
/**
 * Standard result object for logic functions
 * Provides consistent return format and redirect handling
 */
class LogicResult {
    public $redirect = null;
    public $data = [];
    public $error = null;
    public $validation_errors = [];

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

    public function hasValidationErrors() {
        return !empty($this->validation_errors);
    }

    public function addValidationError($field, $message) {
        $this->validation_errors[$field] = $message;
        if (!$this->error) {
            $this->error = 'Please correct the errors below';
        }
    }
}