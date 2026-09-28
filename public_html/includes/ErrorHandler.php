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
            require_once(PathHelper::getIncludePath('data/general_errors_class.php'));
            
            $errorLog = new GeneralError(NULL);
            $id = $errorLog->logError(
                $exception,
                $_SESSION ?? [],
                $_REQUEST ?? []
            );

            // The saved row is the error reference the page links to.
            $context->setErrorReference($id, $context->getErrorHash() ?? ErrorReference::hash($exception));
            
        } catch (\Throwable $e) {
            // Fallback logging to file if database fails
            error_log("Database error logging failed: " . $e->getMessage());
            error_log("Original error: " . $exception->getMessage());
        }
    }
}

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
                'hash' => $context->getErrorHash() ?? ErrorReference::hash($exception),
                'error_id' => $context->getErrorId(),
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

    /**
     * The error reference: the err_general_errors row this error was saved
     * as, and its grouping hash. Set while the error is logged, which happens
     * before the response is rendered, so the response can link to it.
     */
    public function setErrorReference(?int $id, string $hash): void {
        $this->data['error_id'] = $id;
        $this->data['error_hash'] = $hash;
    }

    public function getErrorId(): ?int {
        return $this->data['error_id'] ?? null;
    }

    public function getErrorHash(): ?string {
        return $this->data['error_hash'] ?? null;
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
            register_shutdown_function([$this, 'handleFatalError']);
            $this->registered = true;
        }
    }
    
    public function handleException(\Throwable $exception): void {
        try {
            $context = $this->buildContext($exception);

            // Log first: the saved row is the error reference, and the
            // response rendered next links to it.
            $context->setErrorReference(null, ErrorReference::hash($exception));
            $this->logError($exception, $context);
            ErrorReference::recorded($exception, $context->getErrorId(), $context->getErrorHash());

            $handler = $this->selectHandler($context);
            $response = $handler->handle($exception, $context);
            $response->send();

        } catch (\Throwable $handlerException) {
            // Fallback error handling
            $this->handleFallback($handlerException, $exception);
        }

        // Exit with non-zero code so callers (especially exec() in upgrade.php)
        // can detect that an error occurred. exit(0) would mask failures.
        exit(1);
    }

    public function handleFatalError(): void {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            $fatalException = new \ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            );
            $this->handleException($fatalException);
        }
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

        // API requests get the /api/v1 error envelope (error as a string),
        // checked before the ajax handler because API requests also read as
        // ajax and its legacy envelope carries error as an object.
        if (strpos($context->getRequestUri(), '/api/') === 0) {
            return $this->handlers['api'];
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
        require_once(PathHelper::getIncludePath('includes/ErrorClasses.php'));
        
        $this->handlers = [
            'web' => new WebErrorHandler(),
            'ajax' => new AjaxErrorHandler(),
            'api' => new ApiErrorHandler(),
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

// ================================
// ERROR REFERENCE
// ================================

/**
 * ErrorReference — the handle a person uses to report an error.
 *
 * When an error is recorded, the page, JSON envelope or flash message that
 * tells the user about it carries a reference to the recorded row: its id in
 * err_general_errors and a grouping hash that is the same for the same fault
 * on any site. The reference is what the "Report this problem" link points
 * at, so the report page can say exactly which error is being reported.
 *
 * One reference per request: the most recent error recorded in this process.
 * The error handler records it before it renders, which is how the page it
 * renders can link to the row.
 *
 * Report links are offered only to a signed-in member: guests cannot send a
 * report (specs/implemented/bug_reports.md D3), so a link would only lead to a sign-in
 * page.
 *
 * @version 1.1.0 - recorded(): records the reference and notes an automatic problem report
 * @version 1.0.0
 */
class ErrorReference {

	/** Path of the report page. */
	const REPORT_PATH = '/report_problem';

	/** Longest message carried in a report link's query string. */
	const MESSAGE_CAP = 300;

	/** @var array{id: ?int, hash: string}|null */
	private static $current = null;

	/**
	 * The grouping hash: same fault, same hash, on any site. Built from the
	 * error code, the message with digits folded, and the file and line
	 * relative to the site's public_html, so two installs in different
	 * directories group together. Everything in it is stored on the
	 * err_general_errors row, so a report built later from the row computes
	 * the same hash (hashForRow()).
	 */
	public static function hash(\Throwable $exception): string {
		return self::hashFor($exception->getCode(), $exception->getMessage(),
			$exception->getFile(), $exception->getLine());
	}

	/** The grouping hash of a recorded err_general_errors row. */
	public static function hashForRow(GeneralError $row): string {
		return self::hashFor($row->get('err_code'), (string)$row->get('err_message'),
			(string)$row->get('err_file'), $row->get('err_line'));
	}

	/** The grouping hash from its four parts. */
	public static function hashFor($code, string $message, string $file, $line): string {
		return md5((string)$code . '::'
			. preg_replace('/\d+/', 'N', $message) . '::'
			. self::relativeFile($file) . '::'
			. (string)$line);
	}

	/**
	 * A file path relative to public_html when it lies inside it, otherwise
	 * unchanged.
	 */
	public static function relativeFile(string $file): string {
		$root = rtrim(PathHelper::getRootDir(), '/') . '/';
		if (strpos($file, $root) === 0) {
			return substr($file, strlen($root));
		}
		return $file;
	}

	/**
	 * Record an exception in err_general_errors and make it this request's
	 * reference. Returns the saved row id, or NULL when the row could not be
	 * saved (the hash is still recorded, so the reference still groups).
	 */
	public static function log(\Throwable $exception): ?int {
		$user_id = null;
		try {
			$user_id = SessionControl::get_instance()->get_user_id() ?: null;
		} catch (\Throwable $e) {
			// No session: the error is still recorded, unattributed.
		}
		// The same two loggers, in the same order, as an uncaught exception.
		$context = new ErrorContext(array(
			'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
			'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
			'user_id' => $user_id !== null ? intval($user_id) : null,
			'timestamp' => time(),
			'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
		));
		$context->setErrorReference(null, self::hash($exception));
		foreach (array(new DatabaseErrorLogger(), new FileErrorLogger()) as $logger) {
			try {
				$logger->log($exception, $context);
			} catch (\Throwable $e) {
				error_log('Error reference: an error logger failed: ' . $e->getMessage());
			}
		}
		self::recorded($exception, $context->getErrorId(), $context->getErrorHash());
		return $context->getErrorId();
	}

	/**
	 * An error was just recorded: make it this request's reference, and let an
	 * automatic problem report count it (ProblemReport::noteError(), which does
	 * nothing unless the operator switched automatic reports on). Nothing here
	 * may break error handling.
	 */
	public static function recorded(\Throwable $exception, ?int $id, string $hash): void {
		self::record($id, $hash);
		try {
			if (class_exists('ProblemReport')) {
				ProblemReport::noteError($exception, $id);
			}
		} catch (\Throwable $e) {
			error_log('Error reference: an automatic problem report was not noted: ' . $e->getMessage());
		}
	}

	/** Make ($id, $hash) this request's reference. */
	public static function record(?int $id, string $hash): void {
		self::$current = array('id' => $id, 'hash' => $hash);
	}

	/** This request's reference, or NULL when no error has been recorded. */
	public static function current(): ?array {
		return self::$current;
	}

	/** Forget this request's reference. For tests. */
	public static function reset(): void {
		self::$current = null;
	}

	/** Whether the person on this request may send a report. */
	public static function reporterSignedIn(): bool {
		try {
			return intval(SessionControl::get_instance()->get_user_id()) > 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * The report page URL.
	 *
	 * @param int|null    $id      err_general_errors row, when there is one
	 * @param string|null $from    the path and query where the problem showed
	 * @param string|null $message the message the user saw, when there is no row
	 */
	public static function reportUrl(?int $id = null, ?string $from = null, ?string $message = null): string {
		$query = array();
		if ($id) {
			$query['ref'] = $id;
		}
		if ($from !== null && $from !== '') {
			$query['from'] = $from;
		}
		if (!$id && $message !== null && $message !== '') {
			$query['msg'] = mb_substr($message, 0, self::MESSAGE_CAP);
		}
		return self::REPORT_PATH . ($query ? '?' . http_build_query($query) : '');
	}

	/**
	 * The `error_ref` member of a JSON error envelope, or NULL when nothing
	 * was recorded on this request. The report URL is present only for a
	 * signed-in member.
	 */
	public static function forEnvelope(): ?array {
		if (self::$current === null) {
			return null;
		}
		$ref = array(
			'id' => self::$current['id'],
			'hash' => self::$current['hash'],
		);
		if (self::reporterSignedIn()) {
			$ref['report_url'] = self::reportUrl(self::$current['id'], self::requestPath());
		}
		return $ref;
	}

	/**
	 * The HTML "Report a problem" link that follows an error message, or ''
	 * for a guest.
	 *
	 * @param int|null    $id      error row, when there is one
	 * @param string|null $message the message the user saw, when there is none
	 * @param string      $class   CSS class for the link
	 */
	public static function reportLinkHtml(?int $id = null, ?string $message = null, string $class = 'jy-report-link'): string {
		if (!self::reporterSignedIn()) {
			return '';
		}
		$url = self::reportUrl($id, self::requestPath(), $message);
		return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="'
			. htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">Report a problem</a>';
	}

	/** The path and query of this request, or '' on the command line. */
	public static function requestPath(): string {
		return (string)($_SERVER['REQUEST_URI'] ?? '');
	}
}
