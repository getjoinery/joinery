<?php
require_once(__DIR__ . '/PathHelper.php');

/**
 * Core Error Handling Components
 * 
 * This file consolidates interfaces and response classes to reduce file count
 * while maintaining clear separation of concerns.
 */

// ================================
// INTERFACES
// ================================

interface ErrorHandlerInterface {
    public function handle(\Throwable $exception, ErrorContext $context): ErrorResponse;
    public function supports(ErrorContext $context): bool;
}

interface ErrorLoggerInterface {
    public function log(\Throwable $exception, ErrorContext $context): void;
}

// ================================
// RESPONSE CLASSES
// ================================

abstract class ErrorResponse {
    protected string $content;
    protected int $statusCode;
    protected array $headers;
    
    public function __construct(string $content = '', int $statusCode = 500, array $headers = []) {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }
    
    abstract public function send(): void;
    
    public function getContent(): string {
        return $this->content;
    }
    
    public function getStatusCode(): int {
        return $this->statusCode;
    }
    
    public function getHeaders(): array {
        return $this->headers;
    }
}

class HtmlResponse extends ErrorResponse {
    public function __construct(string $content = '', int $statusCode = 500) {
        parent::__construct($content, $statusCode, ['Content-Type' => 'text/html']);
    }
    
    public function send(): void {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }
        }
        echo $this->content;
    }
}

class JsonResponse extends ErrorResponse {
    public function __construct(array $data = [], int $statusCode = 500) {
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        parent::__construct($content, $statusCode, ['Content-Type' => 'application/json']);
    }
    
    public function send(): void {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }
        }
        echo $this->content;
    }
}

class CliResponse extends ErrorResponse {
    public function __construct(string $content = '', int $statusCode = 1) {
        parent::__construct($content, $statusCode);
    }
    
    public function send(): void {
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, $this->content);
        } else {
            echo $this->content;
        }
    }
}

// ================================
// LOGGER CLASSES
// ================================

class DatabaseErrorLogger implements ErrorLoggerInterface {
    
    public function log(\Throwable $exception, ErrorContext $context): void {
        try {
            // Use existing GeneralError class for database logging
            PathHelper::requireOnce('data/general_errors_class.php');
            
            $errorData = [
                'exception_type' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'user_id' => $context->getUserId(),
                'request_uri' => $context->getRequestUri(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'timestamp' => $context->getTimestamp()
            ];
            
            // Add exception-specific context
            if ($exception instanceof BaseException) {
                $exceptionContext = $exception->getContext();
                if (!empty($exceptionContext)) {
                    $errorData['context'] = json_encode($exceptionContext);
                }
            }
            
            // Add stack trace for debugging
            $errorData['stack_trace'] = $exception->getTraceAsString();
            
            GeneralError::LogGeneralError(
                $errorData['message'],
                $errorData['file'],
                $errorData['line'],
                $errorData
            );
            
        } catch (\Throwable $e) {
            // If database logging fails, fall back to error_log
            error_log("Database error logging failed: " . $e->getMessage());
            error_log("Original error: " . $exception->getMessage());
        }
    }
}

class FileErrorLogger implements ErrorLoggerInterface {
    
    public function log(\Throwable $exception, ErrorContext $context): void {
        try {
            $timestamp = date('Y-m-d H:i:s', $context->getTimestamp());
            $exceptionType = get_class($exception);
            $message = $exception->getMessage();
            $file = $exception->getFile();
            $line = $exception->getLine();
            $userId = $context->getUserId() ?? 'guest';
            $requestUri = $context->getRequestUri();
            $ipAddress = $context->getIpAddress();
            
            $logEntry = sprintf(
                "[%s] %s: %s in %s:%d (User: %s, IP: %s, URI: %s)\n",
                $timestamp,
                $exceptionType,
                $message,
                $file,
                $line,
                $userId,
                $ipAddress,
                $requestUri
            );
            
            // Add context if available
            if ($exception instanceof BaseException) {
                $exceptionContext = $exception->getContext();
                if (!empty($exceptionContext)) {
                    $logEntry .= "Context: " . json_encode($exceptionContext) . "\n";
                }
            }
            
            // Add stack trace for detailed debugging
            $logEntry .= "Stack trace:\n" . $exception->getTraceAsString() . "\n\n";
            
            // Log to PHP error log
            error_log($logEntry);
            
        } catch (\Throwable $e) {
            // Fallback to basic error logging if our logging fails
            error_log("File error logging failed: " . $e->getMessage());
            error_log("Original error: " . $exception->getMessage());
        }
    }
}

// ================================
// CONTEXT AND MANAGER CLASSES
// ================================

/**
 * Error Context Class
 * 
 * Holds contextual information about the error occurrence
 */
class ErrorContext {
    private array $data;
    
    public function __construct(array $data) {
        $this->data = $data;
    }
    
    public function isAjax(): bool {
        return $this->data['is_ajax'] ?? false;
    }
    
    public function isAdmin(): bool {
        return $this->data['is_admin'] ?? false;
    }
    
    public function isCli(): bool {
        return $this->data['is_cli'] ?? false;
    }
    
    public function getUserId(): ?int {
        return $this->data['user_id'] ?? null;
    }
    
    public function getRequestUri(): string {
        return $this->data['request_uri'] ?? '';
    }
    
    public function getIpAddress(): string {
        return $this->data['ip_address'] ?? 'unknown';
    }
    
    public function getTimestamp(): int {
        return $this->data['timestamp'] ?? time();
    }
    
    public function toArray(): array {
        return $this->data;
    }
}

/**
 * Error Manager Class
 * 
 * Central error handling orchestrator
 */
class ErrorManager {
    private static ?ErrorManager $instance = null;
    private array $handlers = [];
    private array $loggers = [];
    private bool $registered = false;
    
    private function __construct() {
        $this->initializeHandlers();
        $this->initializeLoggers();
    }
    
    public static function getInstance(): ErrorManager {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function register(): void {
        if (!$this->registered) {
            set_exception_handler([$this, 'handleException']);
            $this->registered = true;
        }
    }
    
    public function handleException(\Throwable $exception): void {
        try {
            $context = $this->buildContext($exception);
            $handler = $this->selectHandler($context);
            $response = $handler->handle($exception, $context);
            
            $this->logError($exception, $context);
            $response->send();
            
        } catch (\Throwable $handlerException) {
            // Fallback error handling
            $this->handleFallback($handlerException, $exception);
        }
        
        exit;
    }
    
    private function buildContext(\Throwable $exception): ErrorContext {
        return new ErrorContext([
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'is_ajax' => $this->isAjaxRequest(),
            'is_admin' => $this->isAdminRequest(),
            'is_cli' => php_sapi_name() === 'cli',
            'user_id' => $this->getCurrentUserId(),
            'session_id' => session_id(),
            'timestamp' => time(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }
    
    private function selectHandler(ErrorContext $context): ErrorHandlerInterface {
        if ($context->isCli()) {
            return $this->handlers['cli'];
        }
        
        if ($context->isAjax()) {
            return $this->handlers['ajax'];
        }
        
        if ($context->isAdmin()) {
            return $this->handlers['admin'];
        }
        
        return $this->handlers['web'];
    }
    
    private function logError(\Throwable $exception, ErrorContext $context): void {
        foreach ($this->loggers as $logger) {
            try {
                $logger->log($exception, $context);
            } catch (\Throwable $e) {
                // Don't let logging errors break error handling
                error_log("Error logger failed: " . $e->getMessage());
            }
        }
    }
    
    private function initializeHandlers(): void {
        PathHelper::requireOnce('includes/ErrorClasses.php');
        
        $this->handlers = [
            'web' => new WebErrorHandler(),
            'ajax' => new AjaxErrorHandler(),
            'admin' => new AdminErrorHandler(),
            'cli' => new CliErrorHandler()
        ];
    }
    
    private function initializeLoggers(): void {
        // Loggers are now in ErrorHandlingCore.php (already loaded)
        $this->loggers = [
            new DatabaseErrorLogger(),
            new FileErrorLogger()
        ];
    }
    
    private function isAjaxRequest(): bool {
        return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') ||
               (isset($_SERVER['CONTENT_TYPE']) && 
                strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
    }
    
    private function isAdminRequest(): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($uri, '/admin') === 0 || strpos($uri, '/adm') === 0;
    }
    
    private function getCurrentUserId(): ?int {
        try {
            $session = SessionControl::get_instance();
            return $session->get_user_id();
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    private function handleFallback(\Throwable $handlerException, \Throwable $originalException): void {
        error_log("Error handler failed: " . $handlerException->getMessage());
        error_log("Original error: " . $originalException->getMessage());
        
        // Ultra-simple fallback
        if (php_sapi_name() === 'cli') {
            echo "FATAL ERROR: " . $originalException->getMessage() . "\n";
        } else {
            http_response_code(500);
            echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
            echo '<h1>An error occurred</h1>';
            echo '<p>We apologize for the inconvenience.</p>';
            echo '</body></html>';
        }
    }
}