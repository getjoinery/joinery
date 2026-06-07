<?php
/**
 * SendGridProvider - SendGrid email service provider
 *
 * Implements EmailServiceProvider using the SendGrid PHP SDK (v8.x) over the v3 HTTP API.
 * Supports batch sending via the personalizations[] array (up to 1000 per request).
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/InboundEmailProvider.php'));

class SendGridProvider implements EmailServiceProvider, InboundEmailProvider {

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
                'empty_option' => 'Select a region…',
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
            } elseif (isset($attachment['data'])) {
                $sg_attachment = new \SendGrid\Mail\Attachment();
                $sg_attachment->setContent(base64_encode($attachment['data']));
                $sg_attachment->setFilename($attachment['name'] ?: 'attachment');
                $sg_attachment->setDisposition('attachment');
                if (!empty($attachment['type'])) {
                    $sg_attachment->setType($attachment['type']);
                }
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

    // ── InboundEmailProvider ────────────────────────────────────────────

    public static function getInboundSettingsFields(): array {
        return [
            [
                'key' => 'sendgrid_inbound_secret',
                'label' => 'Inbound Parse Shared Secret',
                'type' => 'password',
                'helptext' => 'SendGrid Inbound Parse does not sign its POSTs. Set a secret here and '
                    . 'append it to the Destination URL as ?secret=… so only SendGrid can deliver mail. '
                    . 'Required — inbound is rejected when this is blank.',
            ],
        ];
    }

    public static function isWebhook(): bool {
        return true;
    }

    public static function getSetupChecks(?string $domain = null): array {
        $settings = Globalvars::get_instance();
        $results = [];

        $secret = (string)$settings->get_setting('sendgrid_inbound_secret');
        if ($secret !== '') {
            $results[] = self::makeResult('sendgrid.inbound_secret_set', '', 'plugin', 'SendGrid Inbound Parse secret', 'required', 'pass',
                'A shared secret is configured — inbound POSTs are gated.');
        } else {
            $results[] = self::makeResult('sendgrid.inbound_secret_set', '', 'plugin', 'SendGrid Inbound Parse secret', 'required', 'fail',
                'No shared secret is configured — inbound Parse webhooks will be rejected.',
                'SendGrid Inbound Parse does not HMAC-sign its requests, so a secret is the only thing '
                . 'authenticating the POST.',
                ['text' => 'Set a secret in the inbound provider settings and append it to the SendGrid '
                    . 'Destination URL as ?secret=…']);
        }

        if ($domain) {
            $records = self::getDnsRecords($domain);
            foreach ($records as $rec) {
                $results[] = self::makeResult(
                    'sendgrid.dns.' . strtolower($rec['type']),
                    $domain,
                    'domain',
                    'SendGrid ' . $rec['type'] . ' record',
                    'recommended',
                    'unknown',
                    'Publish: ' . $rec['name'] . ' ' . $rec['type'] . ' ' . $rec['value'],
                    $rec['note'] ?? '',
                    ['text' => 'Publish this record at your DNS provider.',
                     'dns_record' => ['type' => $rec['type'], 'name' => $rec['name'], 'value' => $rec['value']]]
                );
            }
        }

        return $results;
    }

    public static function getDnsRecords(string $domain): array {
        return [
            ['type' => 'MX', 'name' => $domain, 'value' => '10 mx.sendgrid.net',
             'note' => 'Routes inbound mail for ' . $domain . ' to SendGrid Inbound Parse. '
                . 'Add the matching host in SendGrid Settings → Inbound Parse.'],
        ];
    }

    /**
     * Handle a SendGrid Inbound Parse POST.
     *
     * Inbound Parse does NOT sign its requests, so authentication rests entirely
     * on a shared secret carried in the Destination URL (?secret=…). The secret
     * is required: a blank setting rejects everything.
     *
     * Verdicts arrive as top-level form fields (never in a MIME header): the
     * SPF field is already a lowercase token; the dkim field is a string like
     * "{@example.com : pass}". DMARC is not provided (recorded 'none').
     *
     * The raw MIME is the 'email' field (enable "POST the raw, full MIME
     * message" in the Inbound Parse settings). The recipient comes from the
     * 'envelope' JSON, falling back to the 'to' field.
     */
    public function handleInbound(array $post, string $raw_body): ?array {
        $settings = Globalvars::get_instance();
        $secret = (string)$settings->get_setting('sendgrid_inbound_secret');
        $provided = isset($_GET['secret']) ? (string)$_GET['secret'] : '';

        if ($secret === '' || !hash_equals($secret, $provided)) {
            error_log('[SendGridProvider] inbound rejected — missing or invalid shared secret');
            return null;
        }

        $raw_mime = (string)($post['email'] ?? '');
        $recipient = self::extractRecipient($post);

        if ($raw_mime === '' || $recipient === '') {
            error_log('[SendGridProvider] inbound rejected — missing email body or recipient');
            return null;
        }

        $out = [
            'raw_mime' => $raw_mime,
            'recipient' => $recipient,
        ];

        $auth = self::extractAuth($post);
        if ($auth !== null) {
            $out['auth'] = $auth;
        }

        return $out;
    }

    /**
     * Recipient from the envelope JSON ({"to":["a@b.com"],"from":"…"}),
     * falling back to the first address in the 'to' form field.
     */
    private static function extractRecipient(array $post): string {
        $envelope = $post['envelope'] ?? '';
        if ($envelope !== '') {
            $decoded = json_decode((string)$envelope, true);
            if (is_array($decoded) && !empty($decoded['to'][0])) {
                return strtolower(trim((string)$decoded['to'][0]));
            }
        }

        $to = (string)($post['to'] ?? '');
        if ($to !== '' && preg_match('/<([^>]+)>/', $to, $m)) {
            return strtolower(trim($m[1]));
        }
        if ($to !== '' && strpos($to, '@') !== false) {
            // Bare or comma-separated list — take the first address.
            $first = trim(explode(',', $to)[0]);
            return strtolower($first);
        }
        return '';
    }

    /**
     * Map the SPF / dkim form fields to the normalized verdict array. Returns
     * null when neither field is present (router falls back to 'unverified').
     */
    private static function extractAuth(array $post): ?array {
        $has_spf  = array_key_exists('SPF', $post);
        $has_dkim = array_key_exists('dkim', $post);
        if (!$has_spf && !$has_dkim) {
            return null;
        }

        $valid = ['pass', 'fail', 'softfail', 'neutral', 'none', 'temperror', 'permerror'];

        $spf = null;
        if ($has_spf) {
            $token = strtolower(trim((string)$post['SPF']));
            $spf = in_array($token, $valid, true) ? $token : null;
        }

        $dkim = null;
        $dkim_domain = null;
        if ($has_dkim) {
            // e.g. "{@example.com : pass}" — may carry multiple pairs; a pass wins.
            if (preg_match_all('/@([^\s:{}]+)\s*:\s*([A-Za-z]+)/', (string)$post['dkim'], $mm, PREG_SET_ORDER)) {
                foreach ($mm as $pair) {
                    $result = strtolower($pair[2]);
                    if (!in_array($result, $valid, true)) {
                        continue;
                    }
                    if ($dkim === null || $result === 'pass') {
                        $dkim = $result;
                        $dkim_domain = strtolower(rtrim($pair[1], '.'));
                    }
                }
            }
        }

        $auth = [
            'spf'    => $spf,
            'dkim'   => $dkim,
            'dmarc'  => null,
            'source' => 'sendgrid',
        ];
        if ($dkim_domain !== null) {
            $auth['dkim_domain'] = $dkim_domain;
        }
        return $auth;
    }

    private static function makeResult($id, $scope, $layer, $label, $severity, $status, $summary, $detail = '', $fix = null, $recheckable = true): array {
        return [
            'id' => $id, 'scope' => $scope, 'layer' => $layer, 'label' => $label,
            'severity' => $severity, 'status' => $status, 'summary' => $summary,
            'detail' => $detail, 'fix' => $fix, 'recheckable' => $recheckable,
        ];
    }
}
