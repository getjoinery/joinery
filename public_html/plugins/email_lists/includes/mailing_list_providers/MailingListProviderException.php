<?php
/**
 * Typed exception thrown by MailingListProvider implementations.
 *
 * isRetryable() distinguishes transient errors (rate limits, 5xx, network) from
 * permanent errors (list missing, credentials revoked, 4xx other than 429).
 * Callers use this flag to decide whether to back off and retry, or give up.
 *
 * Caller input errors (malformed email, etc.) should be surfaced as
 * \InvalidArgumentException, not this class.
 */
class MailingListProviderException extends \Exception {
    private bool $retryable;

    public function __construct(string $message, bool $retryable = false,
                                ?\Throwable $previous = null, int $code = 0) {
        parent::__construct($message, $code, $previous);
        $this->retryable = $retryable;
    }

    public function isRetryable(): bool {
        return $this->retryable;
    }
}
