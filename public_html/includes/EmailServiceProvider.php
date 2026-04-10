<?php
/**
 * EmailServiceProvider Interface
 *
 * All email providers must implement this interface. Provider classes live in
 * includes/email_providers/ and are auto-discovered by EmailSender.
 *
 * To add a new provider, create a single file in includes/email_providers/
 * implementing this interface. No other files need modification.
 */
interface EmailServiceProvider {
    /**
     * Return the provider's unique key (e.g., 'mailgun', 'smtp', 'sendgrid').
     * This is the value stored in the email_service / email_fallback_service settings.
     */
    public static function getKey(): string;

    /**
     * Return a human-readable label for admin UI (e.g., 'Mailgun', 'SMTP').
     */
    public static function getLabel(): string;

    /**
     * Return an array of setting field definitions this provider requires.
     * Each entry: ['key' => 'setting_name', 'label' => 'Human Label', 'type' => 'text|password', 'helptext' => '...']
     * Used by the admin settings page to dynamically render fields.
     */
    public static function getSettingsFields(): array;

    /**
     * Validate that this provider's required settings are configured.
     * Returns ['valid' => bool, 'errors' => string[]]
     */
    public static function validateConfiguration(): array;

    /**
     * Send an EmailMessage. Returns true on success, false on failure.
     * Should log errors via error_log() and optionally via the debug logger.
     * Must NOT queue failed emails - the caller (EmailSender) handles that.
     */
    public function send(EmailMessage $message): bool;

    /**
     * Send to multiple recipients efficiently (batch).
     * Default implementation can loop over send(), but providers like Mailgun
     * can override to use native batch APIs.
     *
     * Returns an array:
     *   'success' => bool (true only if ALL recipients succeeded)
     *   'failed_recipients' => string[] (email addresses that failed)
     *
     * The failed_recipients list is used by EmailSender for fallback: only
     * unsent recipients are passed to the fallback provider, avoiding double-sends.
     */
    public function sendBatch(EmailMessage $message, array $recipients): array;
}
