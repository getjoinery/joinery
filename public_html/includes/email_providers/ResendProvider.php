<?php
/**
 * ResendProvider - Resend email service provider
 *
 * Implements EmailServiceProvider using resend/resend-php v0.x.
 * Native batch sending up to 100 messages per call.
 */

require_once(PathHelper::getComposerAutoloadPath());

class ResendProvider implements EmailServiceProvider {

    public static function getKey(): string {
        return 'resend';
    }

    public static function getLabel(): string {
        return 'Resend';
    }

    public static function getSettingsFields(): array {
        return [
            [
                'key' => 'resend_api_key',
                'label' => 'Resend API Key',
                'type' => 'password',
                'helptext' => 'API key from resend.com → API Keys. Starts with "re_".',
            ],
            [
                'key' => 'resend_verified_domain',
                'label' => 'Verified Sender Domain',
                'type' => 'text',
                'helptext' => 'For display only — Resend validates the From at send time. Domains must be DNS-verified in Resend.',
            ],
        ];
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('resend_api_key'))) {
            $errors[] = 'Resend API key not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Live API validation via apiKeys->list() — cheapest authenticated call.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $key = $settings->get_setting('resend_api_key');
        $configured_domain = $settings->get_setting('resend_verified_domain');

        if (empty($key)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter API key to validate connection',
            ];
        }

        try {
            $resend = \Resend::client($key);
            $keys = $resend->apiKeys->list();

            $details = [];
            // Collection may be iterable or wrap a data array
            $count = 0;
            if (is_object($keys) && method_exists($keys, 'data')) {
                $data = $keys->data();
                $count = is_array($data) ? count($data) : 0;
            } elseif (is_object($keys) && isset($keys->data)) {
                $count = is_array($keys->data) ? count($keys->data) : 0;
            }
            $details['API Keys on Account'] = $count;
            if (!empty($configured_domain)) {
                $details['Verified Domain'] = $configured_domain;
            }

            return [
                'success' => true,
                'label' => 'API Key Valid',
                'details' => $details,
                'error' => null,
            ];
        } catch (\Resend\Exceptions\ErrorException $e) {
            $error_type = method_exists($e, 'getType') ? $e->getType() : null;

            if ($error_type === 'validation_error' || stripos($e->getMessage(), 'API key') !== false) {
                return [
                    'success' => false,
                    'label' => 'API Key Rejected',
                    'details' => [],
                    'error' => 'Invalid API key. Must be a Resend API key starting with "re_".',
                ];
            }
            // A sending-only key returns 403 on apiKeys->list() but is still valid for sending.
            if (stripos($e->getMessage(), 'not allowed') !== false || stripos($e->getMessage(), 'permission') !== false) {
                $details = ['API Keys on Account' => '(restricted key — cannot list)'];
                if (!empty($configured_domain)) {
                    $details['Verified Domain'] = $configured_domain;
                }
                return [
                    'success' => true,
                    'label' => 'API Key Valid (Restricted)',
                    'details' => $details,
                    'error' => null,
                ];
            }
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    public function send(EmailMessage $message): bool {
        $settings = Globalvars::get_instance();

        try {
            $resend = \Resend::client($settings->get_setting('resend_api_key'));
            $payload = $this->buildPayload($message);
            $payload['to'] = $this->formatRecipients($message->getRecipients());

            $cc = $this->formatRecipients($message->getCc());
            if (!empty($cc)) {
                $payload['cc'] = $cc;
            }
            $bcc = $this->formatRecipients($message->getBcc());
            if (!empty($bcc)) {
                $payload['bcc'] = $bcc;
            }

            $resend->emails->send($payload);
            return true;
        } catch (\Exception $e) {
            error_log('[ResendProvider] Send failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $settings = Globalvars::get_instance();
        $failed = [];

        try {
            $resend = \Resend::client($settings->get_setting('resend_api_key'));
            $base = $this->buildPayload($message);
            $chunks = array_chunk($recipients, 100);

            foreach ($chunks as $chunk) {
                $payload = [];
                foreach ($chunk as $email) {
                    $entry = $base;
                    $entry['to'] = [$email];
                    $payload[] = $entry;
                }

                try {
                    $resend->batch->send($payload);
                } catch (\Exception $e) {
                    error_log('[ResendProvider] Batch chunk failed: ' . $e->getMessage());
                    $failed = array_merge($failed, $chunk);
                }
            }
        } catch (\Exception $e) {
            error_log('[ResendProvider] Batch setup failed: ' . $e->getMessage());
            $failed = $recipients;
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /**
     * Build the Resend payload shared between send() and sendBatch().
     * Caller fills in 'to' (and optionally cc/bcc).
     */
    private function buildPayload(EmailMessage $message): array {
        $from = $message->getFromName()
            ? $message->getFromName() . ' <' . $message->getFrom() . '>'
            : $message->getFrom();

        $payload = [
            'from' => $from,
            'subject' => $message->getSubject(),
        ];

        if ($message->getHtmlBody()) {
            $payload['html'] = $message->getHtmlBody();
        }
        if ($message->getTextBody()) {
            $payload['text'] = $message->getTextBody();
        }
        if ($replyTo = $message->getReplyTo()) {
            $payload['reply_to'] = $replyTo;
        }

        $headers = $message->getHeaders();
        if (!empty($headers)) {
            $payload['headers'] = $headers;
        }

        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            if (!empty($a['path']) && is_readable($a['path'])) {
                $attachments[] = [
                    'filename' => $a['name'] ?: basename($a['path']),
                    'content' => base64_encode(file_get_contents($a['path'])),
                ];
            }
        }
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * Convert EmailMessage recipient arrays into Resend's "Name <email>" string format.
     */
    private function formatRecipients(array $list): array {
        $out = [];
        foreach ($list as $r) {
            if (!empty($r['email'])) {
                $out[] = !empty($r['name'])
                    ? $r['name'] . ' <' . $r['email'] . '>'
                    : $r['email'];
            }
        }
        return $out;
    }
}
