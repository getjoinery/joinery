<?php
/**
 * MailjetProvider - Mailjet email service provider
 *
 * Implements EmailServiceProvider using the Mailjet APIv3 PHP SDK (^1.5).
 * Uses Send API v3.1 with `Messages` array — up to 50 messages per call,
 * each a separate envelope with per-message status in the response.
 */

require_once(PathHelper::getComposerAutoloadPath());

use Mailjet\Client as MailjetClient;
use Mailjet\Resources as MailjetResources;

class MailjetProvider implements EmailServiceProvider {

    public static function getKey(): string {
        return 'mailjet';
    }

    public static function getLabel(): string {
        return 'Mailjet';
    }

    public static function getSettingsFields(): array {
        return [
            [
                'key' => 'mailjet_api_key',
                'label' => 'Mailjet API Key',
                'type' => 'text',
                'helptext' => 'Public part of the credential pair. Found in Mailjet dashboard → Account → API Keys.',
            ],
            [
                'key' => 'mailjet_api_secret',
                'label' => 'Mailjet API Secret',
                'type' => 'password',
                'helptext' => 'Secret part of the credential pair. Visible in Mailjet only at first issue — store it somewhere safe.',
            ],
            [
                'key' => 'mailjet_sandbox_mode',
                'label' => 'Sandbox Mode (no real delivery)',
                'type' => 'dropdown',
                'options' => [0 => 'Off', 1 => 'On'],
            ],
            [
                'key' => 'mailjet_verified_domain',
                'label' => 'Verified Sender Domain',
                'type' => 'text',
                'helptext' => 'For display only — Mailjet validates the From at send time.',
            ],
        ];
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
            $mj = new MailjetClient($key, $secret, true);
            $response = $mj->get(MailjetResources::$Myprofile);

            if (!$response->success()) {
                $status = $response->getStatus();
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
                return [
                    'success' => false,
                    'label' => 'API Connection Failed',
                    'details' => [],
                    'error' => 'HTTP ' . $status . ': ' . $response->getReasonPhrase(),
                ];
            }

            $data = $response->getData();
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
            $mj = $this->buildClient(
                $settings->get_setting('mailjet_api_key'),
                $settings->get_setting('mailjet_api_secret')
            );

            $msg = $this->buildBaseMessage($message);
            $msg['To'] = $this->mapRecipients($message->getRecipients());
            if ($cc = $this->mapRecipients($message->getCc())) {
                $msg['Cc'] = $cc;
            }
            if ($bcc = $this->mapRecipients($message->getBcc())) {
                $msg['Bcc'] = $bcc;
            }

            $body = ['Messages' => [$msg]];
            if ($settings->get_setting('mailjet_sandbox_mode') == '1') {
                $body['SandboxMode'] = true;
            }

            $response = $mj->post(MailjetResources::$Email, ['body' => $body]);
            if (!$response->success()) {
                error_log('[MailjetProvider] Send failed: HTTP ' . $response->getStatus() . ' ' . json_encode($response->getData()));
                return false;
            }
            return true;
        } catch (\Exception $e) {
            error_log('[MailjetProvider] Send failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $settings = Globalvars::get_instance();
        $sandbox = $settings->get_setting('mailjet_sandbox_mode') == '1';
        $failed = [];

        try {
            $mj = $this->buildClient(
                $settings->get_setting('mailjet_api_key'),
                $settings->get_setting('mailjet_api_secret')
            );

            $chunks = array_chunk($recipients, 50);
            foreach ($chunks as $chunk) {
                $messages = [];
                foreach ($chunk as $email) {
                    $msg = $this->buildBaseMessage($message);
                    $msg['To'] = [['Email' => $email]];
                    $messages[] = $msg;
                }
                $body = ['Messages' => $messages];
                if ($sandbox) {
                    $body['SandboxMode'] = true;
                }

                try {
                    $response = $mj->post(MailjetResources::$Email, ['body' => $body]);
                    if (!$response->success()) {
                        error_log('[MailjetProvider] Batch chunk HTTP ' . $response->getStatus() . ': ' . json_encode($response->getData()));
                        $failed = array_merge($failed, $chunk);
                        continue;
                    }
                    // v3.1 returns ['Messages' => [{Status: 'success'|'error', ...}]]
                    $data = $response->getData();
                    $msg_results = $data['Messages'] ?? [];
                    foreach ($chunk as $idx => $email) {
                        $status = $msg_results[$idx]['Status'] ?? null;
                        if ($status !== 'success') {
                            $failed[] = $email;
                        }
                    }
                } catch (\Exception $e) {
                    error_log('[MailjetProvider] Batch chunk failed: ' . $e->getMessage());
                    $failed = array_merge($failed, $chunk);
                }
            }
        } catch (\Exception $e) {
            error_log('[MailjetProvider] Batch setup failed: ' . $e->getMessage());
            $failed = $recipients;
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
            $msg['Headers'] = $headers;
        }

        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            if (!empty($a['path']) && is_readable($a['path'])) {
                $attachments[] = [
                    'ContentType' => mime_content_type($a['path']) ?: 'application/octet-stream',
                    'Filename' => $a['name'] ?: basename($a['path']),
                    'Base64Content' => base64_encode(file_get_contents($a['path'])),
                ];
            }
        }
        if (!empty($attachments)) {
            $msg['Attachments'] = $attachments;
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

    private function buildClient(string $key, string $secret): MailjetClient {
        return new MailjetClient($key, $secret, true, ['version' => 'v3.1']);
    }
}
