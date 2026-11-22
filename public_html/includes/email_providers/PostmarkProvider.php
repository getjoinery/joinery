<?php
/**
 * PostmarkProvider - Postmark email service provider
 *
 * Talks to the Postmark HTTP API directly through ProviderHttp: GET /server
 * validates the token, POST /email sends one message, POST /email/batch
 * sends up to 500 per call with a per-recipient answer. The credential is the
 * X-Postmark-Server-Token header.
 *
 * MESSAGE-ID: Postmark replaces a custom Message-ID unless the message also
 * carries the header X-PM-KeepID: true
 * (https://postmarkapp.com/developer/user-guide/send-email-with-smtp; the API
 * guide says a custom Message-ID goes in the Headers list,
 * https://postmarkapp.com/developer/user-guide/send-email-with-api). So a
 * pinned Message-ID is sent together with X-PM-KeepID.
 *
 * @version 2.1 - a batch whose message has attachments goes one /email per recipient, with retries, so
 *   500 copies of a file never share one request (review B12); batch entries never carry the pinned
 *   Message-ID, which belongs to one message (B15); a display name is quoted per RFC 5322, so a comma
 *   in it no longer splits the address; a send counts only when Postmark's answer parses with
 *   ErrorCode 0
 * @version 2.0 - on ProviderHttp, no SDK (specs/email_provider_sdk_removal.md WP2): one payload builder for
 *   send and batch, so batch entries carry headers and attachments (B2); the pinned Message-ID is sent
 *   with X-PM-KeepID (B1); an attachment that yields no bytes fails the send (B6)
 */

class PostmarkProvider implements EmailServiceProvider, SingleKeyProvider {

    const API_BASE = 'https://api.postmarkapp.com';

    /** Postmark's batch limit per call. */
    const BATCH_SIZE = 500;

    public static function getKey(): string {
        return 'postmark';
    }

    public static function getLabel(): string {
        return 'Postmark';
    }

    /** A server token is a UUID. */
    public static function apiKeyPattern(): string {
        return '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    }

    public static function getSpfMechanism(string $domain): string
    {
        return 'include:spf.mtasv.net';
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('postmark_server_token'))) {
            $errors[] = 'Postmark Server Token not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Live API validation via GET /server — returns the Server's name and config.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $token = $settings->get_setting('postmark_server_token');
        $stream = $settings->get_setting('postmark_message_stream') ?: 'outbound';
        $configured_domain = $settings->get_setting('postmark_verified_domain');

        if (empty($token)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter Server Token to validate connection',
            ];
        }

        try {
            $resp = self::call('GET', '/server', (string)$token);
        } catch (ProviderHttpException $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => $e->getMessage(),
            ];
        }
        $body = $resp->json() ?? [];
        $code = isset($body['ErrorCode']) ? (int)$body['ErrorCode'] : null;

        if ($resp->status === 401 || $code === 10) {
            return [
                'success' => false,
                'label' => 'API Key Rejected',
                'details' => [],
                'error' => 'Invalid Server Token. Must be a Server token (per-Server), not an Account token.',
            ];
        }
        if ($resp->status !== 200 || ($code !== null && $code !== 0)) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => 'Postmark error ' . ($code ?? $resp->status) . ': ' . self::errorText($resp),
            ];
        }

        $details = [
            'Server Name' => $body['Name'] ?? '(unknown)',
            'Message Stream' => $stream,
            'Track Opens' => $settings->get_setting('postmark_track_opens') == '1' ? 'On' : 'Off',
            'Track Links' => $settings->get_setting('postmark_track_links') ?: 'None',
        ];
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
        $token = (string)Globalvars::get_instance()->get_setting('postmark_server_token');

        try {
            $payload = $this->buildPayload($message);
            $payload['To'] = $this->joinRecipients($message->getRecipients());
            if ($cc = $this->joinRecipients($message->getCc())) {
                $payload['Cc'] = $cc;
            }
            if ($bcc = $this->joinRecipients($message->getBcc())) {
                $payload['Bcc'] = $bcc;
            }

            $resp = self::call('POST', '/email', $token, $payload);
        } catch (RuntimeException $e) {
            error_log('[PostmarkProvider] Send failed: ' . $e->getMessage());
            return false;
        }

        if (!self::accepted($resp)) {
            error_log('[PostmarkProvider] Send failed: HTTP ' . $resp->status . ' ' . self::errorText($resp));
            return false;
        }
        return true;
    }

    /** A 200 whose body parses with ErrorCode 0: anything else is not a send. */
    private static function accepted(ProviderHttpResponse $resp): bool {
        $body = $resp->json();
        return $resp->status === 200 && is_array($body) && isset($body['ErrorCode']) && (int)$body['ErrorCode'] === 0;
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $token = (string)Globalvars::get_instance()->get_setting('postmark_server_token');
        $recipients = array_values($recipients);

        try {
            // A pinned Message-ID names one message; every entry is its own.
            $base = $this->buildPayload($message, false);
        } catch (RuntimeException $e) {
            error_log('[PostmarkProvider] Batch send failed: ' . $e->getMessage());
            return ['success' => false, 'failed_recipients' => $recipients];
        }

        $failed = [];
        if (!empty($base['Attachments'])) {
            // The batch call would carry a full copy of every attachment per
            // entry: 500 of them in one body. One message per recipient instead.
            foreach ($recipients as $email) {
                $entry = $base;
                $entry['To'] = $email;
                try {
                    $resp = self::call('POST', '/email', $token, $entry, 2);
                } catch (ProviderHttpException $e) {
                    error_log('[PostmarkProvider] Send to ' . $email . ' failed: ' . $e->getMessage());
                    $failed[] = $email;
                    continue;
                }
                if (!self::accepted($resp)) {
                    error_log('[PostmarkProvider] Send to ' . $email . ' failed: HTTP ' . $resp->status . ' ' . self::errorText($resp));
                    $failed[] = $email;
                }
            }
            return ['success' => empty($failed), 'failed_recipients' => $failed];
        }

        foreach (array_chunk($recipients, self::BATCH_SIZE) as $chunk) {
            $batch = [];
            foreach ($chunk as $email) {
                $entry = $base;
                $entry['To'] = $email;
                $batch[] = $entry;
            }

            try {
                $resp = self::call('POST', '/email/batch', $token, $batch);
            } catch (ProviderHttpException $e) {
                error_log('[PostmarkProvider] Batch chunk failed: ' . $e->getMessage());
                $failed = array_merge($failed, $chunk);
                continue;
            }
            $results = $resp->json();
            if ($resp->status !== 200 || !is_array($results) || !array_is_list($results)) {
                error_log('[PostmarkProvider] Batch chunk failed: HTTP ' . $resp->status . ' ' . self::errorText($resp));
                $failed = array_merge($failed, $chunk);
                continue;
            }
            // Positional: entry i answers for recipient i; ErrorCode 0 is success.
            foreach ($chunk as $idx => $email) {
                $r = $results[$idx] ?? null;
                if (!is_array($r) || (int)($r['ErrorCode'] ?? -1) !== 0) {
                    $failed[] = $email;
                }
            }
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /**
     * Everything but the recipients, for send() and every batch entry alike.
     *
     * @throws RuntimeException when an attachment yields no bytes
     */
    private function buildPayload(EmailMessage $message, bool $pin_message_id = true): array {
        $settings = Globalvars::get_instance();

        $from = self::formatAddress((string)$message->getFrom(), (string)$message->getFromName());

        $payload = [
            'From' => $from,
            'Subject' => (string)$message->getSubject(),
            'TrackOpens' => $settings->get_setting('postmark_track_opens') == '1',
            'TrackLinks' => $settings->get_setting('postmark_track_links') ?: 'None',
            'MessageStream' => $settings->get_setting('postmark_message_stream') ?: 'outbound',
        ];
        if ($message->getHtmlBody()) {
            $payload['HtmlBody'] = $message->getHtmlBody();
        }
        if ($message->getTextBody()) {
            $payload['TextBody'] = $message->getTextBody();
        }
        if ($message->getReplyTo()) {
            $payload['ReplyTo'] = (string)$message->getReplyTo();
        }

        // Postmark takes headers as a list of {Name, Value}, not a map.
        $headers = [];
        foreach ($message->getHeaders() as $name => $value) {
            $headers[] = ['Name' => (string)$name, 'Value' => (string)$value];
        }
        if ($pin_message_id && $message->getMessageId()) {
            $headers[] = ['Name' => 'Message-ID', 'Value' => (string)$message->getMessageId()];
            $headers[] = ['Name' => 'X-PM-KeepID', 'Value' => 'true'];
        }
        if (!empty($headers)) {
            $payload['Headers'] = $headers;
        }

        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            $content = EmailMessage::attachmentContent($a);
            $row = [
                'Name' => $content['name'],
                'Content' => base64_encode($content['bytes']),
                'ContentType' => $content['type'],
            ];
            if (!empty($a['cid'])) {
                // Inline (embedded) image: Postmark marks a part inline by giving
                // it a cid:-prefixed ContentID, which the body references as cid:<id>.
                $row['ContentID'] = 'cid:' . $a['cid'];
            }
            $attachments[] = $row;
        }
        if (!empty($attachments)) {
            $payload['Attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * Convert EmailMessage recipient arrays into comma-separated string format.
     */
    private function joinRecipients(array $list): string {
        $parts = [];
        foreach ($list as $r) {
            if (!empty($r['email'])) {
                $parts[] = self::formatAddress((string)$r['email'], (string)($r['name'] ?? ''));
            }
        }
        return implode(',', $parts);
    }

    /**
     * "Name <email>", the name quoted per RFC 5322 when it holds anything
     * but letters, digits, spaces and the atom specials (a comma would
     * otherwise split one address into two).
     */
    private static function formatAddress(string $email, string $name): string {
        $name = trim($name);
        if ($name === '') {
            return $email;
        }
        if (!preg_match("/^[A-Za-z0-9!#$%&'*+\/=?^_`{|}~ -]+\z/", $name)) {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }
        return $name . ' <' . $email . '>';
    }

    /** One API call with the server token. */
    private static function call(string $method, string $path, string $token, ?array $json = null, int $retries = 0): ProviderHttpResponse {
        $options = ['headers' => ['X-Postmark-Server-Token' => $token], 'retries' => $retries];
        if ($json !== null) {
            $options['json'] = $json;
        }
        return ProviderHttp::request($method, self::API_BASE . $path, $options);
    }

    /** Postmark's own Message for a failed call, or the status. */
    private static function errorText(ProviderHttpResponse $resp): string {
        $body = $resp->json();
        if (is_array($body) && !empty($body['Message']) && is_string($body['Message'])) {
            return $body['Message'];
        }
        return 'HTTP ' . $resp->status;
    }
}
