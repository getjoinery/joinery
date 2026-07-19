<?php
/**
 * ConnectedMailboxProvider - "send all site email through my connected account".
 *
 * A thin, auto-discovered EmailServiceProvider so a connected mailbox (any IMAP
 * account: Gmail/Microsoft OAuth, or Yahoo/iCloud/Fastmail app password) is a
 * first-class, selectable choice in the active-provider dropdown, matching the
 * unified onboarding (§6). It has no SMTP mechanics of its own: it reads which
 * account from the connected_account_id setting and sends through an SmtpProvider
 * transport configured with SmtpConfig::fromConnectedAccount() — the same single
 * SMTP path the global provider uses.
 *
 * Its only distinct responsibilities are UX (pick the account) and forcing From
 * to that account's address: consumer/provider SMTP rewrites the envelope sender
 * and From to the authenticated identity, so every message ships AS the connected
 * address (the accepted single-identity trade-off, §5). Account health is shared
 * with inbound — a send rejected for missing scope/auth flags iia_needs_reauth so
 * one Reconnect fixes both directions.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
require_once(PathHelper::getIncludePath('includes/SmtpConfig.php'));

class ConnectedMailboxProvider implements EmailServiceProvider {

    const SETTING_ACCOUNT = 'connected_account_id';

    /**
     * Load the InboundImapAccount model lazily — it lives in the inbound_email
     * plugin. Loading it at file scope would fatal provider auto-discovery (and
     * thus all email) if the plugin files were absent. Returns false when the
     * connected-account model is unavailable, so this provider degrades to "no
     * accounts" instead of breaking the rest of the email system.
     */
    private static function accountModelAvailable(): bool {
        if (class_exists('InboundImapAccount')) {
            return true;
        }
        $file = PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php');
        if (is_file($file)) {
            require_once($file);
        }
        return class_exists('InboundImapAccount');
    }

    public static function getKey(): string {
        return 'connected_account';
    }

    public static function getLabel(): string {
        return 'Connected Email Account';
    }

    public static function getSpfIncludeDomain(): string
    {
        // No fixed SPF include: sending identity is not tied to a shared provider range.
        return '';
    }

    /**
     * A dropdown of the connected accounts. Built dynamically (this provider may
     * query the DB) so every account the operator has connected is selectable.
     */
    public static function getSettingsFields(): array {
        $options = array();
        if (!self::accountModelAvailable()) {
            return array(array(
                'key' => self::SETTING_ACCOUNT,
                'label' => 'Account to send through',
                'type' => 'dropdown',
                'options' => $options,
                'empty_option' => true,
                'helptext' => 'The Inbound Email plugin must be installed to connect an account.',
            ));
        }
        try {
            $accounts = new MultiInboundImapAccount(array('deleted' => false));
            $accounts->load();
            foreach ($accounts as $account) {
                $label = $account->get('iia_label') ?: $account->get('iia_username');
                $username = $account->get('iia_username');
                if ($username && $username !== $label) {
                    $label .= ' (' . $username . ')';
                }
                $options[$account->key] = $label;
            }
        } catch (\Throwable $e) {
            // Plugin not installed / table missing — leave the dropdown empty.
            error_log('[ConnectedMailboxProvider] Could not list connected accounts: ' . $e->getMessage());
        }

        return array(
            array(
                'key' => self::SETTING_ACCOUNT,
                'label' => 'Account to send through',
                'type' => 'dropdown',
                'options' => $options,
                'empty_option' => true,
                'helptext' => 'All site email — transactional, notifications, replies, and forwarding — '
                    . 'will be sent as this account address. To send as a hosted alias or to relay '
                    . 'forwarded mail with the original sender intact, use an SMTP host, Mailgun, or SES instead.',
            ),
        );
    }

    /**
     * Valid when an account is selected and authorized to send. Surfaces the
     * proactive "Reconnect to allow sending" case (§4.1): an account connected for
     * IMAP only (e.g. Microsoft without SMTP.Send) is reported as needing a
     * reconnect before it is usable as the outbound provider.
     */
    public static function validateConfiguration(): array {
        $account = self::selectedAccount();
        if (!$account) {
            return array('valid' => false, 'errors' => array('No connected account selected'));
        }

        if (!$account->canSendViaSmtp()) {
            return array('valid' => false, 'errors' => array(
                'The selected account has no SMTP host (generic IMAP) — use a relay-class provider to send for it.'));
        }

        if (!$account->isSendAuthorized()) {
            if ($account->isOAuth()) {
                return array('valid' => false, 'errors' => array(
                    'Reconnect the account to allow sending — its current authorization does not include send permission.'));
            }
            return array('valid' => false, 'errors' => array('The selected account is not fully credentialed to send.'));
        }

        return array('valid' => true, 'errors' => array());
    }

    public function send(EmailMessage $message): bool {
        $account = self::selectedAccount();
        if (!$account) {
            error_log('[ConnectedMailboxProvider] No connected account selected for outbound.');
            return false;
        }

        // Force From to the authenticated identity. Consumer/provider SMTP rewrites
        // it anyway; setting it explicitly keeps headers honest and avoids rejects.
        $settings = Globalvars::get_instance();
        $fromName = $message->getFromName() ?: $settings->get_setting('defaultemailname');
        $message->from($account->get('iia_username'), $fromName);

        // Proactive guard: never attempt a send we know is unauthorized.
        if (!$account->isSendAuthorized()) {
            error_log('[ConnectedMailboxProvider] Account #' . $account->key
                . ' is not authorized to send — reconnect to allow sending.');
            if ($account->isOAuth() && !$account->needsReauth()) {
                $account->markNeedsReauth();
            }
            return false;
        }

        try {
            $config = SmtpConfig::fromConnectedAccount($account);
        } catch (SmtpConfigException $e) {
            error_log('[ConnectedMailboxProvider] ' . $e->getMessage());
            return false;
        }

        $mailer = new SmtpMailer($config);
        $mailer->applyMessage($message);

        if ($mailer->send()) {
            return true;
        }

        self::classifyFailure($account, (string)$mailer->ErrorInfo);
        return false;
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $failed = array();
        foreach ($recipients as $email) {
            $individual = new EmailMessage();
            $individual->to($email)
                       ->subject($message->getSubject());
            if ($message->getHtmlBody()) {
                $individual->html($message->getHtmlBody());
            } else {
                $individual->text($message->getTextBody());
            }
            foreach ($message->getHeaders() as $name => $value) {
                $individual->header($name, $value);
            }
            if ($message->getReplyTo()) {
                $individual->replyTo($message->getReplyTo());
            }
            try {
                if (!$this->send($individual)) {
                    $failed[] = $email;
                }
            } catch (\Exception $e) {
                error_log('[ConnectedMailboxProvider] Batch send failed for ' . $email . ': ' . $e->getMessage());
                $failed[] = $email;
            }
        }
        return array('success' => empty($failed), 'failed_recipients' => $failed);
    }

    // ── Internal ────────────────────────────────────────────────────────────

    /** Load the InboundImapAccount chosen in the connected_account_id setting, or null. */
    public static function selectedAccount(): ?InboundImapAccount {
        if (!self::accountModelAvailable()) {
            return null;
        }
        $id = intval(Globalvars::get_instance()->get_setting(self::SETTING_ACCOUNT));
        if ($id <= 0) {
            return null;
        }
        $account = new InboundImapAccount($id, TRUE);
        return $account->key ? $account : null;
    }

    /**
     * Classify a send failure into the two actionable buckets the spec calls for
     * (§4.1, §9). Auth/scope failures flag the shared account for Reconnect; rate-
     * limit/quota failures record a visible status and a migration nudge. The
     * error string comes from PHPMailer/the SMTP server — never a credential.
     */
    private static function classifyFailure(InboundImapAccount $account, string $error): void {
        $lc = strtolower($error);

        $auth_markers = array('could not authenticate', 'authentication', '5.7.', 'invalid credentials',
            'username and password', 'auth ', 'authenticationfailed');
        foreach ($auth_markers as $marker) {
            if (strpos($lc, $marker) !== false) {
                if ($account->isOAuth()) {
                    $account->markNeedsReauth();
                }
                error_log('[ConnectedMailboxProvider] Send authentication failed for account #' . $account->key
                    . '. If this is a Microsoft 365 account, your tenant may block SMTP AUTH org-wide — '
                    . 'use Mailgun/SES, or have your admin enable SMTP AUTH. Otherwise reconnect the account.');
                return;
            }
        }

        $rate_markers = array('rate', 'quota', 'too many', 'throttl', '4.7.', 'try again later', 'limit exceeded');
        foreach ($rate_markers as $marker) {
            if (strpos($lc, $marker) !== false) {
                $provider_label = $account->getPreset()['label'] ?? 'The connected account';
                $account->recordStatus($provider_label . ' is rate-limiting send — consider a dedicated provider (Mailgun/SES).');
                error_log('[ConnectedMailboxProvider] ' . $provider_label
                    . ' is rate-limiting send for account #' . $account->key
                    . ' — consider switching the active provider to Mailgun/SES.');
                return;
            }
        }

        error_log('[ConnectedMailboxProvider] Send failed for account #' . $account->key . ': ' . $error);
    }
}
