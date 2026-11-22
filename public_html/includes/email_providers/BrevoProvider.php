<?php
/**
 * BrevoProvider - Brevo (formerly Sendinblue) email service provider
 *
 * Talks to the Brevo v3 API directly through ProviderHttp: GET /v3/account
 * validates the key, POST /v3/smtp/email sends. A batch is one request per
 * chunk of 1000 using messageVersions[], so every recipient gets a separate
 * envelope. The credential is the api-key header.
 *
 * RETRIES: 2, on a 429 or a failure before Brevo could have read the
 * request. Never on a 5xx: one that arrives after Brevo took the message
 * would deliver it twice.
 *
 * MESSAGE-ID: not sent. Brevo's send reference says of `headers`: "Standard
 * email headers are not supported"
 * (https://developers.brevo.com/reference/send-transac-email). Brevo assigns
 * its own Message-ID, so the mailbox's stored copy of a message sent here
 * carries an ID the delivered message does not.
 *
 * @version 2.1 - headers is always a JSON object (review B20); a send counts only when the answer parses
 *   and names the message (messageId, or messageIds for a batch)
 * @version 2.0 - on ProviderHttp, no SDK (specs/email_provider_sdk_removal.md WP2): one payload builder for
 *   send and batch (B2); an attachment that yields no bytes fails the send (B6); 5xx no longer retried
 */

class BrevoProvider implements EmailServiceProvider, SingleKeyProvider {

    const API_BASE = 'https://api.brevo.com/v3';

    /** Brevo's messageVersions limit per call. */
    const BATCH_SIZE = 1000;

    public static function getKey(): string {
        return 'brevo';
    }

    public static function getLabel(): string {
        return 'Brevo';
    }

    /** xkeysib- then 64 hex, a dash and 16 characters. */
    public static function apiKeyPattern(): string {
        return '/^xkeysib-[0-9a-f]{64}-[A-Za-z0-9]{16}$/';
    }

    public static function getSpfMechanism(string $domain): string
    {
        return 'include:spf.brevo.com';
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('brevo_api_key'))) {
            $errors[] = 'Brevo API key not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Live API validation via GET /v3/account.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $key = $settings->get_setting('brevo_api_key');
        $configured_domain = $settings->get_setting('brevo_verified_domain');

        if (empty($key)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter API key to validate connection',
            ];
        }

        try {
            $resp = self::call('GET', '/account', (string)$key);
        } catch (ProviderHttpException $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => $e->getMessage(),
            ];
        }

        if ($resp->status === 401) {
            return [
                'success' => false,
                'label' => 'API Key Rejected',
                'details' => [],
                'error' => 'Invalid API key. Must be a v3 key starting with "xkeysib-" (not an SMTP relay password).',
            ];
        }
        if ($resp->status === 403) {
            return [
                'success' => false,
                'label' => 'Access Denied',
                'details' => [],
                'error' => 'API key lacks transactional-email scope.',
            ];
        }
        if (!$resp->ok()) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => 'Brevo error ' . $resp->status . ': ' . self::errorText($resp),
            ];
        }

        $account = $resp->json() ?? [];
        $details = [];
        if (!empty($account['email'])) {
            $details['Account Email'] = $account['email'];
        }
        if (!empty($account['companyName'])) {
            $details['Company Name'] = $account['companyName'];
        }
        // plan is a list; the first entry is the active one, as in the API docs.
        $plan = $account['plan'][0] ?? null;
        if (is_array($plan)) {
            $details['Plan'] = $plan['type'] ?? '';
            $details['Credits'] = $plan['credits'] ?? '';
        }
        if (!empty($configured_domain)) {
            $details['Verified Domain'] = $configured_domain;
        }

        return [
            'success' => true,
            'label' => 'API Key Valid',
            'details' => $details,
            'error' => null,
        ];
    }

    public function send(EmailMessage $message): bool {
        $settings = Globalvars::get_instance();

        try {
            $values = $this->buildBaseEmail($message, $settings);
            $values['to'] = $this->mapRecipients($message->getRecipients());
            if ($cc = $this->mapRecipients($message->getCc())) {
                $values['cc'] = $cc;
            }
            if ($bcc = $this->mapRecipients($message->getBcc())) {
                $values['bcc'] = $bcc;
            }
            $resp = self::call('POST', '/smtp/email', (string)$settings->get_setting('brevo_api_key'), $values);
        } catch (RuntimeException $e) {
            error_log('[BrevoProvider] Send failed: ' . $e->getMessage());
            return false;
        }
        if (!self::accepted($resp)) {
            error_log('[BrevoProvider] Send failed: HTTP ' . $resp->status . ' ' . self::errorText($resp));
            return false;
        }
        return true;
    }

    /**
     * A 2xx whose body parses and names what Brevo queued: messageId for a
     * send, messageIds for one with messageVersions.
     */
    private static function accepted(ProviderHttpResponse $resp): bool {
        $body = $resp->ok() ? $resp->json() : null;
        return is_array($body) && (!empty($body['messageId']) || !empty($body['messageIds']));
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $settings = Globalvars::get_instance();
        $recipients = array_values($recipients);
        $key = (string)$settings->get_setting('brevo_api_key');

        try {
            $base = $this->buildBaseEmail($message, $settings);
        } catch (RuntimeException $e) {
            error_log('[BrevoProvider] Batch send failed: ' . $e->getMessage());
            return ['success' => false, 'failed_recipients' => $recipients];
        }

        $failed = [];
        foreach (array_chunk($recipients, self::BATCH_SIZE) as $chunk) {
            $values = $base;
            // Brevo requires `to` even when using messageVersions — set to first recipient.
            $values['to'] = [['email' => $chunk[0]]];
            $versions = [];
            foreach ($chunk as $email_addr) {
                $versions[] = ['to' => [['email' => $email_addr]]];
            }
            $values['messageVersions'] = $versions;

            try {
                $resp = self::call('POST', '/smtp/email', $key, $values);
            } catch (ProviderHttpException $e) {
                error_log('[BrevoProvider] Batch chunk failed: ' . $e->getMessage());
                $failed = array_merge($failed, $chunk);
                continue;
            }
            if (!self::accepted($resp)) {
                error_log('[BrevoProvider] Batch chunk failed: HTTP ' . $resp->status . ' ' . self::errorText($resp));
                $failed = array_merge($failed, $chunk);
            }
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /**
     * Build the subject/body/from/replyTo/headers/attachment half of a send —
     * no recipients, which send() and sendBatch() fill in differently.
     *
     * @return array<string, mixed> the request body so far
     * @throws RuntimeException when an attachment yields no bytes
     */
    private function buildBaseEmail(EmailMessage $message, Globalvars $settings): array {
        $values = [];

        $sender = ['email' => (string)$message->getFrom()];
        if ($message->getFromName()) {
            $sender['name'] = $message->getFromName();
        }
        $values['sender'] = $sender;

        $values['subject'] = (string)$message->getSubject();

        if ($message->getHtmlBody()) {
            $values['htmlContent'] = $message->getHtmlBody();
        }
        if ($message->getTextBody()) {
            $values['textContent'] = $message->getTextBody();
        }

        if ($replyTo = $message->getReplyTo()) {
            $values['replyTo'] = ['email' => (string)$replyTo];
        }

        $headers = $message->getHeaders();
        if ($settings->get_setting('brevo_sandbox_mode') == '1') {
            $headers['X-Sib-Sandbox'] = 'drop';
        }
        if (!empty($headers)) {
            // An object always: a map whose only name is "0" would encode as a list.
            $values['headers'] = (object)$headers;
        }

        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            $content = EmailMessage::attachmentContent($a);
            if (!empty($a['cid'])) {
                // The Brevo API has no Content-ID field, so an inline part cannot
                // embed — degrade it to a regular downloadable attachment. Declared
                // provider limitation, logged once so the fallback is visible.
                error_log('[BrevoProvider] Inline attachment degraded to regular attachment (no Content-ID support): cid=' . $a['cid']);
            }
            $attachments[] = [
                'name' => $content['name'],
                'content' => base64_encode($content['bytes']),
            ];
        }
        if (!empty($attachments)) {
            $values['attachment'] = $attachments;
        }

        return $values;
    }

    /** Recipients as [{email, name}], an empty name left out. */
    private function mapRecipients(array $list): array {
        $out = [];
        foreach ($list as $r) {
            if (!empty($r['email'])) {
                $entry = ['email' => $r['email']];
                if (!empty($r['name'])) {
                    $entry['name'] = $r['name'];
                }
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** One API call with the key; 2 retries on a 429 or a pre-send failure. */
    private static function call(string $method, string $path, string $key, ?array $json = null): ProviderHttpResponse {
        $options = ['headers' => ['api-key' => $key], 'retries' => 2];
        if ($json !== null) {
            $options['json'] = $json;
        }
        return ProviderHttp::request($method, self::API_BASE . $path, $options);
    }

    /** Brevo's own message for a failed call, or the status. */
    private static function errorText(ProviderHttpResponse $resp): string {
        $body = $resp->json();
        if (is_array($body) && !empty($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }
        return 'HTTP ' . $resp->status;
    }
}
