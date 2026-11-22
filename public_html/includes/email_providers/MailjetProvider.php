<?php
/**
 * MailjetProvider - Mailjet email service provider
 *
 * Talks to Mailjet directly through ProviderHttp: GET /v3/REST/myprofile
 * validates the key pair, POST /v3.1/send sends, with a `Messages` array of
 * up to 50 messages per call, each a separate envelope with its own status
 * in the answer. The credential is HTTP basic auth, key and secret.
 *
 * MESSAGE-ID: not sent. Mailjet lists Message-Id among its forbidden headers
 * (https://dev.mailjet.com/docs/email-api/send-api-v31/add-email-headers),
 * so a send that set it could be refused. The mailbox cannot match its
 * stored copy of a message sent here to the delivered one by Message-ID.
 *
 * @version 2.1 - a batch whose message has attachments goes one message per call, with retries, so 50
 *   copies of a file never share one request (review B12); Headers is always a JSON object (B20); a
 *   send counts only when the answer parses with Messages[0].Status success
 * @version 2.0 - on ProviderHttp, no SDK (specs/email_provider_sdk_removal.md WP2); an attachment that
 *   yields no bytes fails the send (B6)
 */

class MailjetProvider implements EmailServiceProvider {

    const PROFILE_URL = 'https://api.mailjet.com/v3/REST/myprofile';
    const SEND_URL = 'https://api.mailjet.com/v3.1/send';

    /** Mailjet's Messages limit per call. */
    const BATCH_SIZE = 50;

    public static function getKey(): string {
        return 'mailjet';
    }

    public static function getLabel(): string {
        return 'Mailjet';
    }

    public static function getSpfMechanism(string $domain): string
    {
        return 'include:spf.mailjet.com';
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('mailjet_api_key'))) {
            $errors[] = 'Mailjet API key not configured';
        }
        if (empty($settings->get_setting('mailjet_api_secret'))) {
            $errors[] = 'Mailjet API secret not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Live API validation via GET /v3/REST/myprofile.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $key = $settings->get_setting('mailjet_api_key');
        $secret = $settings->get_setting('mailjet_api_secret');
        $configured_domain = $settings->get_setting('mailjet_verified_domain');

        if (empty($key) || empty($secret)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter both API key and API secret to validate connection',
            ];
        }

        try {
            $response = ProviderHttp::request('GET', self::PROFILE_URL, ['auth' => [(string)$key, (string)$secret]]);
        } catch (ProviderHttpException $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => $e->getMessage(),
            ];
        }

        $status = $response->status;
        if ($status === 401) {
            return [
                'success' => false,
                'label' => 'Credentials Rejected',
                'details' => [],
                'error' => 'Invalid API key + secret combination. Both halves are required and must match the same credential pair.',
            ];
        }
        if ($status === 403) {
            return [
                'success' => false,
                'label' => 'Access Denied',
                'details' => [],
                'error' => 'Credentials valid but lack required scope.',
            ];
        }
        if (!$response->ok()) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => 'HTTP ' . $status . ': ' . self::errorText($response),
            ];
        }

        $data = ($response->json() ?? [])['Data'] ?? [];
        $profile = is_array($data) && !empty($data[0]) ? $data[0] : [];

        $details = [];
        if (!empty($profile['Email'])) {
            $details['Account Email'] = $profile['Email'];
        }
        if (!empty($profile['CompanyName'])) {
            $details['Company Name'] = $profile['CompanyName'];
        }
        if (!empty($profile['Country'])) {
            $details['Country'] = $profile['Country'];
        }
        if (!empty($configured_domain)) {
            $details['Verified Domain'] = $configured_domain;
        }

        return [
            'success' => true,
            'label' => 'API Credentials Valid',
            'details' => $details,
            'error' => null,
        ];
    }

    public function send(EmailMessage $message): bool {
        try {
            $msg = $this->buildBaseMessage($message);
            $msg['To'] = $this->mapRecipients($message->getRecipients());
            if ($cc = $this->mapRecipients($message->getCc())) {
                $msg['Cc'] = $cc;
            }
            if ($bcc = $this->mapRecipients($message->getBcc())) {
                $msg['Bcc'] = $bcc;
            }
            $response = self::post([$msg]);
        } catch (RuntimeException $e) {
            error_log('[MailjetProvider] Send failed: ' . $e->getMessage());
            return false;
        }
        if (!self::accepted($response, 0)) {
            error_log('[MailjetProvider] Send failed: HTTP ' . $response->status . ' ' . self::errorText($response));
            return false;
        }
        return true;
    }

    /** Message $i of a 2xx answer that parses reads Status success. */
    private static function accepted(ProviderHttpResponse $response, int $i): bool {
        $messages = $response->ok() ? (($response->json() ?? [])['Messages'] ?? null) : null;
        return is_array($messages) && (($messages[$i]['Status'] ?? null) === 'success');
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $recipients = array_values($recipients);
        try {
            $base = $this->buildBaseMessage($message);
        } catch (RuntimeException $e) {
            error_log('[MailjetProvider] Batch send failed: ' . $e->getMessage());
            return ['success' => false, 'failed_recipients' => $recipients];
        }

        $failed = [];
        if (!empty($base['Attachments']) || !empty($base['InlinedAttachments'])) {
            // A batch call would carry a full copy of every file per message:
            // one message per call instead.
            foreach ($recipients as $email) {
                $msg = $base;
                $msg['To'] = [['Email' => $email]];
                try {
                    $response = self::post([$msg], 2);
                } catch (ProviderHttpException $e) {
                    error_log('[MailjetProvider] Send to ' . $email . ' failed: ' . $e->getMessage());
                    $failed[] = $email;
                    continue;
                }
                if (!self::accepted($response, 0)) {
                    error_log('[MailjetProvider] Send to ' . $email . ' failed: HTTP ' . $response->status . ' ' . self::errorText($response));
                    $failed[] = $email;
                }
            }
            return ['success' => empty($failed), 'failed_recipients' => $failed];
        }

        foreach (array_chunk($recipients, self::BATCH_SIZE) as $chunk) {
            $messages = [];
            foreach ($chunk as $email) {
                $msg = $base;
                $msg['To'] = [['Email' => $email]];
                $messages[] = $msg;
            }

            try {
                $response = self::post($messages);
            } catch (ProviderHttpException $e) {
                error_log('[MailjetProvider] Batch chunk failed: ' . $e->getMessage());
                $failed = array_merge($failed, $chunk);
                continue;
            }
            if (!$response->ok()) {
                error_log('[MailjetProvider] Batch chunk HTTP ' . $response->status . ': ' . $response->body);
                $failed = array_merge($failed, $chunk);
                continue;
            }
            // v3.1 answers {Messages: [{Status: 'success'|'error', ...}]}, positionally.
            foreach ($chunk as $idx => $email) {
                if (!self::accepted($response, $idx)) {
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
     * Build a v3.1 Message entry without To/Cc/Bcc.
     */
    private function buildBaseMessage(EmailMessage $message): array {
        $from = ['Email' => $message->getFrom()];
        if ($message->getFromName()) {
            $from['Name'] = $message->getFromName();
        }

        $msg = [
            'From' => $from,
            'Subject' => $message->getSubject(),
        ];
        if ($message->getHtmlBody()) {
            $msg['HTMLPart'] = $message->getHtmlBody();
        }
        if ($message->getTextBody()) {
            $msg['TextPart'] = $message->getTextBody();
        }
        if ($replyTo = $message->getReplyTo()) {
            $msg['ReplyTo'] = ['Email' => $replyTo];
        }
        $headers = $message->getHeaders();
        if (!empty($headers)) {
            // An object always: a map whose only name is "0" would encode as a list.
            $msg['Headers'] = (object)$headers;
        }

        $attachments = [];
        $inlined = [];
        foreach ($message->getAttachments() as $a) {
            $content = EmailMessage::attachmentContent($a);
            $row = [
                'ContentType' => $content['type'],
                'Filename' => $content['name'],
                'Base64Content' => base64_encode($content['bytes']),
            ];
            if (!empty($a['cid'])) {
                // Inline (embedded) image: Mailjet v3.1 carries inline parts in a
                // separate InlinedAttachments array keyed by ContentID (bare token),
                // which the body references as cid:<id>.
                $row['ContentID'] = $a['cid'];
                $inlined[] = $row;
            } else {
                $attachments[] = $row;
            }
        }
        if (!empty($attachments)) {
            $msg['Attachments'] = $attachments;
        }
        if (!empty($inlined)) {
            $msg['InlinedAttachments'] = $inlined;
        }

        return $msg;
    }

    private function mapRecipients(array $list): array {
        $out = [];
        foreach ($list as $r) {
            if (!empty($r['email'])) {
                $entry = ['Email' => $r['email']];
                if (!empty($r['name'])) {
                    $entry['Name'] = $r['name'];
                }
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** One v3.1 send of $messages, SandboxMode following mailjet_sandbox_mode. */
    private static function post(array $messages, int $retries = 0): ProviderHttpResponse {
        $settings = Globalvars::get_instance();
        $body = ['Messages' => $messages];
        if ($settings->get_setting('mailjet_sandbox_mode') == '1') {
            $body['SandboxMode'] = true;
        }
        return ProviderHttp::request('POST', self::SEND_URL, [
            'auth' => [(string)$settings->get_setting('mailjet_api_key'), (string)$settings->get_setting('mailjet_api_secret')],
            'json' => $body,
            'retries' => $retries,
        ]);
    }

    /** Mailjet's own words for a failed call, or the status. */
    private static function errorText(ProviderHttpResponse $resp): string {
        $body = $resp->json();
        foreach (['ErrorMessage', 'message'] as $k) {
            if (is_array($body) && !empty($body[$k]) && is_string($body[$k])) {
                return $body[$k];
            }
        }
        return 'HTTP ' . $resp->status;
    }
}
