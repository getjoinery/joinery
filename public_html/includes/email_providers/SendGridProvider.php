<?php
/**
 * SendGridProvider - SendGrid email service provider
 *
 * Implements EmailServiceProvider using the SendGrid PHP SDK (v8.x) over the v3 HTTP API.
 * Supports batch sending via the personalizations[] array (up to 1000 per request).
 */

require_once(PathHelper::getComposerAutoloadPath());

class SendGridProvider implements EmailServiceProvider {

    public static function getKey(): string {
        return 'sendgrid';
    }

    public static function getLabel(): string {
        return 'SendGrid';
    }

    public static function getSettingsFields(): array {
        return [
            [
                'key' => 'sendgrid_api_key',
                'label' => 'SendGrid API Key',
                'type' => 'password',
            ],
            [
                'key' => 'sendgrid_verified_domain',
                'label' => 'Verified Sender Domain (Example: mail.example.com)',
                'type' => 'text',
                'helptext' => 'For display only — SendGrid validates the From at send time. Must be a domain you have verified in SendGrid.',
            ],
            [
                'key' => 'sendgrid_region',
                'label' => 'Region',
                'type' => 'dropdown',
                'options' => [
                    'global' => 'Global (api.sendgrid.com)',
                    'eu' => 'EU (api.eu.sendgrid.com)',
                ],
            ],
            [
                'key' => 'sendgrid_sandbox_mode',
                'label' => 'Sandbox Mode (no real delivery)',
                'type' => 'dropdown',
                'options' => [0 => 'Off', 1 => 'On'],
            ],
            [
                'key' => 'sendgrid_click_tracking',
                'label' => 'Click Tracking (default Off)',
                'type' => 'dropdown',
                'options' => [0 => 'Off', 1 => 'On'],
            ],
        ];
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('sendgrid_api_key'))) {
            $errors[] = 'SendGrid API key not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Live API validation for admin settings panel.
     * Hits GET /v3/user/account to confirm the API key works.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('sendgrid_api_key');
        $region = $settings->get_setting('sendgrid_region') ?: 'global';
        $configured_domain = $settings->get_setting('sendgrid_verified_domain');

        if (empty($api_key)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter API key to validate connection',
            ];
        }

        try {
            $sg = new \SendGrid($api_key);
            if ($region === 'eu') {
                $sg->setDataResidency('eu');
            }

            $response = $sg->client->user()->account()->get();
            $status = $response->statusCode();

            if ($status >= 200 && $status < 300) {
                $body = json_decode($response->body(), true) ?: [];
                $details = [
                    'Region' => $region === 'eu' ? 'EU' : 'Global',
                ];
                if (!empty($body['type'])) {
                    $details['Account Type'] = $body['type'];
                }
                if (isset($body['reputation'])) {
                    $details['Reputation'] = $body['reputation'];
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

            if ($status === 401) {
                $error = 'Authentication failed (401). Check API key.';
                if ($region !== 'eu') {
                    $error .= ' If your account is EU-region, set Region to EU.';
                }
                return [
                    'success' => false,
                    'label' => 'API Key Rejected',
                    'details' => ['Region' => $region === 'eu' ? 'EU' : 'Global'],
                    'error' => $error,
                ];
            }

            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => ['Region' => $region === 'eu' ? 'EU' : 'Global'],
                'error' => 'HTTP ' . $status . ': ' . $response->body(),
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
        $api_key = $settings->get_setting('sendgrid_api_key');

        try {
            $mail = $this->buildBaseMail($message);

            foreach ($message->getRecipients() as $recipient) {
                $personalization = new \SendGrid\Mail\Personalization();
                $personalization->addTo(new \SendGrid\Mail\To($recipient['email'], $recipient['name'] ?? null));
                $mail->addPersonalization($personalization);
            }

            // CC and BCC apply per personalization in SendGrid; add to the first.
            $personalizations = $mail->getPersonalizations();
            if (!empty($personalizations)) {
                $first = $personalizations[0];
                foreach ($message->getCc() as $cc) {
                    $first->addCc(new \SendGrid\Mail\Cc($cc['email'], $cc['name'] ?? null));
                }
                foreach ($message->getBcc() as $bcc) {
                    $first->addBcc(new \SendGrid\Mail\Bcc($bcc['email'], $bcc['name'] ?? null));
                }
            }

            $sg = $this->buildClient($api_key, $settings);
            $response = $sg->send($mail);
            $status = $response->statusCode();

            if ($status >= 200 && $status < 300) {
                return true;
            }

            error_log("[SendGridProvider] Send failed: HTTP " . $status . " " . $response->body());
            return false;
        } catch (\Exception $e) {
            error_log("[SendGridProvider] Send failed: " . $e->getMessage());
            return false;
        }
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('sendgrid_api_key');

        $chunks = array_chunk($recipients, 1000);
        $failed = [];

        foreach ($chunks as $chunk) {
            try {
                $mail = $this->buildBaseMail($message);

                foreach ($chunk as $email) {
                    $personalization = new \SendGrid\Mail\Personalization();
                    $personalization->addTo(new \SendGrid\Mail\To($email, $email));
                    $mail->addPersonalization($personalization);
                }

                $sg = $this->buildClient($api_key, $settings);
                $response = $sg->send($mail);
                $status = $response->statusCode();

                if ($status < 200 || $status >= 300) {
                    error_log("[SendGridProvider] Batch chunk failed: HTTP " . $status . " " . $response->body());
                    $failed = array_merge($failed, $chunk);
                }
            } catch (\Exception $e) {
                error_log("[SendGridProvider] Batch chunk failed: " . $e->getMessage());
                $failed = array_merge($failed, $chunk);
            }
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /**
     * Build a Mail object populated with everything except recipient personalizations.
     * Callers add personalizations and (optionally) CC/BCC afterwards.
     */
    private function buildBaseMail(EmailMessage $message): \SendGrid\Mail\Mail {
        $settings = Globalvars::get_instance();
        $mail = new \SendGrid\Mail\Mail();

        $from = new \SendGrid\Mail\From($message->getFrom(), $message->getFromName() ?: null);
        $mail->setFrom($from);
        $mail->setSubject($message->getSubject());

        if ($message->getHtmlBody()) {
            $mail->addContent('text/html', $message->getHtmlBody());
        }
        if ($message->getTextBody()) {
            $mail->addContent('text/plain', $message->getTextBody());
        }

        if ($replyTo = $message->getReplyTo()) {
            $mail->setReplyTo(new \SendGrid\Mail\ReplyTo($replyTo));
        }

        foreach ($message->getHeaders() as $name => $value) {
            $mail->addGlobalHeader($name, $value);
        }

        foreach ($message->getAttachments() as $attachment) {
            if (!empty($attachment['path']) && is_readable($attachment['path'])) {
                $content = base64_encode(file_get_contents($attachment['path']));
                $sg_attachment = new \SendGrid\Mail\Attachment();
                $sg_attachment->setContent($content);
                $sg_attachment->setFilename($attachment['name'] ?: basename($attachment['path']));
                $sg_attachment->setDisposition('attachment');
                $mail->addAttachment($sg_attachment);
            }
        }

        if ($settings->get_setting('sendgrid_sandbox_mode') == '1') {
            $mail->enableSandBoxMode();
        }

        // Click tracking — explicit on or off based on setting (default off in settings.json)
        $mail->setClickTracking($settings->get_setting('sendgrid_click_tracking') == '1');

        return $mail;
    }

    private function buildClient(string $api_key, Globalvars $settings): \SendGrid {
        $sg = new \SendGrid($api_key);
        if ($settings->get_setting('sendgrid_region') === 'eu') {
            $sg->setDataResidency('eu');
        }
        return $sg;
    }
}
