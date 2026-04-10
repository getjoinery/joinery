<?php
require_once(__DIR__ . '/ErrorHandler.php');

/**
 * Error Classes - Exceptions and Handlers
 * 
 * All exceptions and error handlers consolidated into one file for maximum minimalism.
 * Contains: All exception classes + WebErrorHandler, AdminErrorHandler, AjaxErrorHandler, CliErrorHandler
 */

// ================================
// BASE EXCEPTION
// ================================

abstract class BaseException extends Exception {
    protected string $userMessage = 'An error occurred.';
    protected array $context = [];
    protected bool $shouldLog = true;
    protected bool $shouldDisplay = true;
    
    public function __construct(
        string $message = "An error occurred", 
        int $code = 0, 
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }
    
    public function getUserMessage(): string {
        return $this->userMessage;
    }
    
    public function setUserMessage(string $message): void {
        $this->userMessage = $message;
    }
    
    public function getContext(): array {
        return $this->context;
    }
    
    public function setContext(array $context): void {
        $this->context = array_merge($this->context, $context);
    }
    
    public function shouldLog(): bool {
        return $this->shouldLog;
    }
    
    public function shouldDisplay(): bool {
        return $this->shouldDisplay;
    }
}

// ================================
// VALIDATION EXCEPTIONS
// ================================

class ValidationException extends BaseException {
    protected string $userMessage = 'Please check your input and try again.';
    protected array $fieldErrors = [];
    
    public function __construct(
        string $message = "Validation failed", 
        array $fieldErrors = [],
        int $code = 400, 
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->fieldErrors = $fieldErrors;
    }
    
    public function getFieldErrors(): array {
        return $this->fieldErrors;
    }
    
    public function addFieldError(string $field, string $error): void {
        $this->fieldErrors[$field] = $error;
    }
    
    public function hasFieldErrors(): bool {
        return !empty($this->fieldErrors);
    }
}

// ================================
// AUTHENTICATION EXCEPTIONS
// ================================

class AuthenticationException extends BaseException {
    protected string $userMessage = 'Authentication failed. Please log in again.';
    
    public function __construct(
        string $message = "Authentication failed", 
        int $code = 401, 
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
}

// ================================
// AUTHORIZATION EXCEPTIONS  
// ================================

class AuthorizationException extends BaseException {
    protected string $userMessage = 'You do not have permission to perform this action.';
    protected int $requiredPermissionLevel = 0;
    
    public function __construct(
        string $message = "Access denied", 
        int $requiredPermissionLevel = 0,
        int $code = 403, 
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->requiredPermissionLevel = $requiredPermissionLevel;
    }
    
    public function getRequiredPermissionLevel(): int {
        return $this->requiredPermissionLevel;
    }
}

// ================================
// BUSINESS LOGIC EXCEPTIONS
// ================================

class BusinessLogicException extends BaseException {
    protected string $userMessage = 'This action cannot be completed at this time.';
    protected string $businessRule = '';
    
    public function __construct(
        string $message = "Business logic violation", 
        int $code = 422, 
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
    
    public function setBusinessRule(string $rule): void {
        $this->businessRule = $rule;
    }
    
    public function getBusinessRule(): string {
        return $this->businessRule;
    }
}

// ================================
// EXTERNAL SERVICE EXCEPTIONS
// ================================

class ExternalServiceException extends BaseException {
    protected string $userMessage = 'A service is temporarily unavailable. Please try again later.';
    protected string $serviceName = '';
    protected array $serviceContext = [];
    
    public function __construct(
        string $message = "External service error", 
        string $serviceName = '',
        array $serviceContext = [],
        int $code = 503, 
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->serviceName = $serviceName;
        $this->serviceContext = $serviceContext;
    }
    
    public function getServiceName(): string {
        return $this->serviceName;
    }
    
    public function getServiceContext(): array {
        return $this->serviceContext;
    }
}

// ================================
// DATABASE EXCEPTIONS
// ================================

class DatabaseException extends BaseException {
    protected string $userMessage = 'A database error occurred. Please try again.';
    protected bool $shouldDisplay = false; // Don't show DB errors to users
    protected string $query = '';
    protected array $bindings = [];
    
    public function __construct(
        string $message = "Database error", 
        int $code = 500, 
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
    
    public function setQuery(string $query): void {
        $this->query = $query;
    }
    
    public function getQuery(): string {
        return $this->query;
    }
    
    public function setBindings(array $bindings): void {
        $this->bindings = $bindings;
    }
    
    public function getBindings(): array {
        return $this->bindings;
    }
}

// ================================
// FILE SYSTEM EXCEPTIONS
// ================================

class FileSystemException extends BaseException {
    protected string $userMessage = 'A file operation error occurred.';
    protected string $filePath = '';
    protected string $operation = '';
    
    public function __construct(
        string $message = "File operation failed", 
        string $filePath = '',
        string $operation = '',
        int $code = 0, 
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->filePath = $filePath;
        $this->operation = $operation;
    }
    
    public function getFilePath(): string {
        return $this->filePath;
    }
    
    public function getOperation(): string {
        return $this->operation;
    }
}

// ================================
// SYSTEM EXCEPTIONS
// ================================

class SystemException extends BaseException {
    protected string $userMessage = 'A system error occurred. Our team has been notified.';
    protected string $component = '';
    
    public function __construct(
        string $message = "System error", 
        string $component = '',
        int $code = 500, 
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->component = $component;
    }
    
    public function getComponent(): string {
        return $this->component;
    }
}

// ================================
// ERROR HANDLER CLASSES
// ================================

class WebErrorHandler implements ErrorHandlerInterface {
    
    public function handle(\Throwable $exception, ErrorContext $context): ErrorResponse {
        $html = $this->renderErrorPage($exception, $context);
        return new HtmlResponse($html, $this->getStatusCode($exception));
    }
    
    public function supports(ErrorContext $context): bool {
        return !$context->isAjax() && !$context->isCli();
    }
    
    private function renderErrorPage(\Throwable $exception, ErrorContext $context): string {
        $title = $this->getErrorTitle($exception);
        $message = $this->getErrorMessage($exception);
        $showDebug = $this->shouldShowDebug($exception);
        
        $html = '<!DOCTYPE html><html><head>';
        $html .= '<title>' . htmlspecialchars($title) . '</title>';
        $html .= '<meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= $this->getErrorStyles();
        $html .= '</head><body>';
        
        $html .= '<div class="error-container">';
        $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
        $html .= '<p class="error-message">' . htmlspecialchars($message) . '</p>';
        
        if ($showDebug) {
            $html .= $this->renderDebugInfo($exception);
        }
        
        $html .= $this->renderActions($exception, $context);
        $html .= '</div>';
        
        $html .= '</body></html>';
        
        return $html;
    }
    
    private function getErrorTitle(\Throwable $exception): string {
        if ($exception instanceof ValidationException) {
            return 'Input Error';
        }
        
        if ($exception instanceof AuthenticationException) {
            return 'Authentication Required';
        }
        
        if ($exception instanceof AuthorizationException) {
            return 'Access Denied';
        }
        
        return 'An Error Occurred';
    }
    
    private function getErrorMessage(\Throwable $exception): string {
        if ($exception instanceof BaseException && $exception->shouldDisplay()) {
            return $exception->getUserMessage();
        }
        
        if ($this->isProduction()) {
            return 'We apologize for the inconvenience. Our team has been notified.';
        }
        
        return $exception->getMessage();
    }
    
    private function getStatusCode(\Throwable $exception): int {
        if ($exception instanceof ValidationException) {
            return 400;
        }
        
        if ($exception instanceof AuthenticationException) {
            return 401;
        }
        
        if ($exception instanceof AuthorizationException) {
            return 403;
        }
        
        return 500;
    }
    
    private function shouldShowDebug(\Throwable $exception): bool {
        return !$this->isProduction();
    }
    
    private function renderDebugInfo(\Throwable $exception): string {
        $html = '<div class="debug-info">';
        $html .= '<h3>Debug Information</h3>';
        $html .= '<p><strong>Exception:</strong> ' . htmlspecialchars(get_class($exception)) . '</p>';
        $html .= '<p><strong>Message:</strong> ' . htmlspecialchars($exception->getMessage()) . '</p>';
        $html .= '<p><strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . '</p>';
        $html .= '<p><strong>Line:</strong> ' . htmlspecialchars($exception->getLine()) . '</p>';
        
        if ($exception instanceof BaseException) {
            $context = $exception->getContext();
            if (!empty($context)) {
                $html .= '<p><strong>Context:</strong></p>';
                $html .= '<pre>' . htmlspecialchars(print_r($context, true)) . '</pre>';
            }
        }
        
        $html .= '<details><summary>Stack Trace</summary>';
        $html .= '<pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
        $html .= '</details>';
        $html .= '</div>';
        
        return $html;
    }
    
    protected function renderActions(\Throwable $exception, ErrorContext $context): string {
        $html = '<div class="error-actions">';
        
        if ($exception instanceof AuthenticationException) {
            $html .= '<a href="/login" class="btn btn-primary">Login</a>';
        } else {
            $html .= '<button onclick="history.back()" class="btn btn-secondary">Go Back</button>';
            $html .= '<a href="/" class="btn btn-primary">Home</a>';
        }
        
        // Add contact info if available
        try {
            $settings = Globalvars::get_instance();
            $email = $settings->get_setting('webmaster_email');
            if ($email) {
                $html .= '<p class="contact-info">Need help? Contact <a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a></p>';
            }
        } catch (\Throwable $e) {
            // Ignore if we can't get contact info
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    protected function getErrorStyles(): string {
        return '<style>
            .error-container {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: #333;
                line-height: 1.6;
                max-width: 600px;
                margin: 50px auto;
                background: white;
                padding: 40px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .error-container h1 {
                color: #d32f2f;
                margin-bottom: 20px;
                font-size: 28px;
                font-weight: 600;
            }
            .error-container .error-message {
                font-size: 16px;
                margin-bottom: 30px;
                color: #666;
            }
            .error-container .debug-info {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 4px;
                margin: 20px 0;
                border-left: 4px solid #007bff;
            }
            .error-container .debug-info h3 {
                margin-top: 0;
                color: #007bff;
            }
            .error-container .debug-info pre {
                background: #e9ecef;
                padding: 10px;
                border-radius: 4px;
                overflow-x: auto;
                font-size: 12px;
            }
            .error-container .error-actions {
                margin-top: 30px;
                text-align: center;
            }
            .error-container .btn {
                display: inline-block;
                padding: 10px 20px;
                margin: 0 10px 10px 0;
                border: none;
                border-radius: 4px;
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: background-color 0.2s;
            }
            .error-container .btn-primary {
                background: #007bff;
                color: white;
            }
            .error-container .btn-primary:hover {
                background: #0056b3;
            }
            .error-container .btn-secondary {
                background: #6c757d;
                color: white;
            }
            .error-container .btn-secondary:hover {
                background: #545b62;
            }
            .error-container .contact-info {
                margin-top: 20px;
                font-size: 14px; 
                color: #666;
            }
            .error-container details {
                margin: 10px 0;
            }
            .error-container summary {
                cursor: pointer;
                font-weight: 500;
                color: #007bff;
            }
        </style>';
    }

    protected function isProduction(): bool {
        try {
            $settings = Globalvars::get_instance();
            return !$settings->get_setting('show_errors');
        } catch (\Throwable $e) {
            return true;
        }
    }
}

class AdminErrorHandler extends WebErrorHandler {
    
    public function supports(ErrorContext $context): bool {
        return $context->isAdmin() && !$context->isAjax() && !$context->isCli();
    }
    
    protected function getErrorStyles(): string {
        // Use Bootstrap classes for admin interface consistency
        // Styles scoped to .error-container to avoid overriding page styles on mid-render errors
        return '<style>
            .error-container {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: #333;
                line-height: 1.6;
                max-width: 800px;
                margin: 20px auto;
                background: white;
                padding: 30px;
                border-radius: 0.375rem;
                box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
                border: 1px solid #dee2e6;
            }
            .error-container h1 {
                color: #dc3545;
                margin-bottom: 1rem;
                font-size: 2rem;
                font-weight: 500;
            }
            .error-container .error-message {
                font-size: 1.1rem;
                margin-bottom: 1.5rem;
                color: #6c757d;
            }
            .error-container .debug-info {
                background: #f8f9fa;
                padding: 1rem;
                border-radius: 0.375rem;
                margin: 1rem 0;
                border: 1px solid #e9ecef;
            }
            .error-container .debug-info h3 {
                margin-top: 0;
                color: #495057;
                font-size: 1.25rem;
            }
            .error-container .debug-info pre {
                background: #e9ecef;
                padding: 0.75rem;
                border-radius: 0.375rem;
                overflow-x: auto;
                font-size: 0.875rem;
                margin: 0.5rem 0;
            }
            .error-container .error-actions {
                margin-top: 2rem;
                text-align: left;
            }
            .error-container .btn {
                display: inline-block;
                padding: 0.5rem 1rem;
                margin: 0 0.5rem 0.5rem 0;
                border: 1px solid transparent;
                border-radius: 0.375rem;
                text-decoration: none;
                font-size: 0.875rem;
                font-weight: 400;
                cursor: pointer;
                transition: all 0.15s ease-in-out;
            }
            .error-container .btn-primary {
                background: #0d6efd;
                color: white;
                border-color: #0d6efd;
            }
            .error-container .btn-primary:hover {
                background: #0b5ed7;
                border-color: #0a58ca;
            }
            .error-container .btn-secondary {
                background: #6c757d;
                color: white;
                border-color: #6c757d;
            }
            .error-container .btn-secondary:hover {
                background: #5c636a;
                border-color: #565e64;
            }
            .error-container .contact-info {
                margin-top: 1rem;
                font-size: 0.875rem;
                color: #6c757d;
            }
            .error-container details {
                margin: 0.5rem 0;
            }
            .error-container summary {
                cursor: pointer;
                font-weight: 500;
                color: #0d6efd;
                padding: 0.25rem 0;
            }
            .error-container .alert {
                padding: 0.75rem 1.25rem;
                margin-bottom: 1rem;
                border: 1px solid transparent;
                border-radius: 0.375rem;
            }
            .error-container .alert-danger {
                color: #721c24;
                background-color: #f8d7da;
                border-color: #f5c6cb;
            }
        </style>';
    }
    
    protected function renderActions(\Throwable $exception, ErrorContext $context): string {
        $html = '<div class="error-actions">';
        
        if ($exception instanceof AuthenticationException) {
            $html .= '<a href="/login" class="btn btn-primary">Login to Admin</a>';
        } else {
            $html .= '<button onclick="history.back()" class="btn btn-secondary">Go Back</button>';
            $html .= '<a href="/adm" class="btn btn-primary">Admin Dashboard</a>';
        }
        
        // Admin-specific help
        $html .= '<p class="contact-info mt-3">Admin Error - Check system logs or contact development team</p>';
        
        $html .= '</div>';
        
        return $html;
    }
}

class AjaxErrorHandler implements ErrorHandlerInterface {
    
    public function handle(\Throwable $exception, ErrorContext $context): ErrorResponse {
        $responseData = [
            'success' => false,
            'error' => $this->formatError($exception, $context)
        ];
        
        if ($this->shouldIncludeDebugInfo($exception)) {
            $responseData['debug'] = [
                'type' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'code' => $exception->getCode()
            ];
        }
        
        return new JsonResponse($responseData, $this->getStatusCode($exception));
    }
    
    public function supports(ErrorContext $context): bool {
        return $context->isAjax();
    }
    
    private function formatError(\Throwable $exception, ErrorContext $context): array {
        if ($exception instanceof ValidationException) {
            return [
                'message' => $exception->getUserMessage(),
                'type' => 'validation',
                'fields' => $exception->getFieldErrors()
            ];
        }
        
        if ($exception instanceof AuthenticationException) {
            return [
                'message' => $exception->getUserMessage(),
                'type' => 'authentication',
                'redirect' => '/login'
            ];
        }
        
        if ($exception instanceof AuthorizationException) {
            return [
                'message' => $exception->getUserMessage(),
                'type' => 'authorization'
            ];
        }
        
        return [
            'message' => $this->isProduction() ? 'An error occurred processing your request.' : $exception->getMessage(),
            'type' => 'error'
        ];
    }
    
    private function getStatusCode(\Throwable $exception): int {
        if ($exception instanceof ValidationException) {
            return 400;
        }
        
        if ($exception instanceof AuthenticationException) {
            return 401;
        }
        
        if ($exception instanceof AuthorizationException) {
            return 403;
        }
        
        return 500;
    }
    
    private function shouldIncludeDebugInfo(\Throwable $exception): bool {
        return !$this->isProduction() && !($exception instanceof BaseException && !$exception->shouldDisplay());
    }
    
    private function isProduction(): bool {
        try {
            $settings = Globalvars::get_instance();
            return !$settings->get_setting('show_errors');
        } catch (\Throwable $e) {
            return true; // Default to production mode if we can't determine
        }
    }
}

class CliErrorHandler implements ErrorHandlerInterface {
    
    public function handle(\Throwable $exception, ErrorContext $context): ErrorResponse {
        $message = $this->formatCliError($exception);
        return new CliResponse($message, 1);
    }
    
    public function supports(ErrorContext $context): bool {
        return $context->isCli();
    }
    
    private function formatCliError(\Throwable $exception): string {
        $output = "ERROR: " . $exception->getMessage() . "\n";
        $output .= "File: " . $exception->getFile() . "\n";
        $output .= "Line: " . $exception->getLine() . "\n";
        
        if ($exception instanceof BaseException) {
            $context = $exception->getContext();
            if (!empty($context)) {
                $output .= "Context: " . print_r($context, true) . "\n";
            }
        }
        
        if ($this->shouldShowTrace()) {
            $output .= "\nStack Trace:\n";
            $output .= $exception->getTraceAsString();
        }
        
        return $output;
    }
    
    private function shouldShowTrace(): bool {
        // Show full stack traces in CLI mode for debugging
        return true;
    }
}