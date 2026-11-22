<?php
/**
 * SendGridProvider - SendGrid email service provider, over its v3 HTTP API.
 *
 * Talks to https://api.sendgrid.com (or https://api.eu.sendgrid.com when
 * sendgrid_region is eu) directly through ProviderHttp, with the key as
 * Authorization: Bearer. A send is accepted with 202. Batch sends put one
 * personalization per recipient, up to 1000 per request; a chunk succeeds or
 * fails as a whole.
 *
 * The body follows what the SendGrid SDK sent: text/plain content before
 * text/html (SendGrid refuses the other order), an empty name left out rather
 * than sent as "", click tracking always stated, sandbox mode only when on.
 *
 * MESSAGE-ID (read 2026-11-22): SendGrid's reserved-header list
 * (https://www.twilio.com/docs/sendgrid/api-reference/mail-send/errors) does
 * not name Message-ID, so the pinned Message-ID is sent as a header. Anymail
 * notes SendGrid's smtp-id event field does not always echo it
 * (https://anymail.readthedocs.io/en/stable/esps/sendgrid/); the live check
 * confirms the delivered message carries it.
 *
 * @version 2.1 - a key that may send but not read the account (a "Mail Send" restricted key answers 403 at
 *   /v3/user/account) validates as API Key Valid (Restricted), read from GET /v3/scopes, so
 *   install_mail_provider.php accepts it; batch personalizations never carry the pinned Message-ID
 *   (review B15); headers is always a JSON object (B20). A send stays any 2xx: SendGrid answers 202
 *   with an empty body
 * @version 2.0 - on ProviderHttp, no SDK; the pinned Message-ID is sent; an attachment that yields no bytes
 *   fails the send (specs/email_provider_sdk_removal.md WP2, B1, B2, B6)
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/InboundEmailProvider.php'));

class SendGridProvider implements EmailServiceProvider, InboundEmailProvider, SingleKeyProvider {

    public static function getKey(): string {
        return 'sendgrid';
    }

    public static function getLabel(): string {
        return 'SendGrid';
    }

    /** SG. then two dot-separated base64url parts. */
    public static function apiKeyPattern(): string {
        return '/^SG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}$/';
    }

    public static function getSpfMechanism(string $domain): string
    {
        return 'include:sendgrid.net';
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
            $resp = self::call('GET', '/v3/user/account', (string)$api_key);
        } catch (ProviderHttpException $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => $e->getMessage(),
            ];
        }
        $region_label = $region === 'eu' ? 'EU' : 'Global';

        if ($resp->ok()) {
            $body = $resp->json() ?: [];
            $details = ['Region' => $region_label];
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

        if ($resp->status === 401) {
            $error = 'Authentication failed (401). Check API key.';
            if ($region !== 'eu') {
                $error .= ' If your account is EU-region, set Region to EU.';
            }
            return [
                'success' => false,
                'label' => 'API Key Rejected',
                'details' => ['Region' => $region_label],
                'error' => $error,
            ];
        }
        if ($resp->status === 403) {
            // The key is real but may not read the account: a restricted key.
            // Every key may read its own scopes; one holding mail.send can send.
            $scopes = null;
            try {
                $sresp = self::call('GET', '/v3/scopes', (string)$api_key);
                $scopes = $sresp->ok() ? (($sresp->json() ?? [])['scopes'] ?? null) : null;
            } catch (ProviderHttpException $e) {
                $scopes = null;
            }
            if (is_array($scopes) && in_array('mail.send', $scopes, true)) {
                $details = ['Region' => $region_label, 'Scopes' => count($scopes)];
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
                'label' => 'API Key Rejected',
                'details' => ['Region' => $region_label],
                'error' => 'This API key may not send mail (403). Give it the Mail Send permission in SendGrid.',
            ];
        }

        return [
            'success' => false,
            'label' => 'API Connection Failed',
            'details' => ['Region' => $region_label],
            'error' => self::describeError($resp),
        ];
    }

    public function send(EmailMessage $message): bool {
        try {
            $mail = $this->buildBaseMail($message);
        } catch (RuntimeException $e) {
            error_log('[SendGridProvider] Send failed: ' . $e->getMessage());
            return false;
        }

        $personalizations = [];
        foreach ($message->getRecipients() as $recipient) {
            $personalizations[] = ['to' => [self::address($recipient['email'], $recipient['name'] ?? null)]];
        }
        // CC and BCC apply per personalization in SendGrid; add to the first.
        if (!empty($personalizations)) {
            $cc = [];
            foreach ($message->getCc() as $r) {
                $cc[] = self::address($r['email'], $r['name'] ?? null);
            }
            $bcc = [];
            foreach ($message->getBcc() as $r) {
                $bcc[] = self::address($r['email'], $r['name'] ?? null);
            }
            if ($cc) { $personalizations[0]['cc'] = $cc; }
            if ($bcc) { $personalizations[0]['bcc'] = $bcc; }
        }
        $mail = ['personalizations' => $personalizations] + $mail;

        return $this->post($mail, 'Send failed');
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        try {
            // A pinned Message-ID names one message; every personalization is its own.
            $base = $this->buildBaseMail($message, false);
        } catch (RuntimeException $e) {
            error_log('[SendGridProvider] Batch send failed: ' . $e->getMessage());
            return ['success' => false, 'failed_recipients' => array_values($recipients)];
        }

        $failed = [];
        foreach (array_chunk(array_values($recipients), 1000) as $chunk) {
            $personalizations = [];
            foreach ($chunk as $email) {
                $personalizations[] = ['to' => [self::address($email, $email)]];
            }
            if (!$this->post(['personalizations' => $personalizations] + $base, 'Batch chunk failed')) {
                $failed = array_merge($failed, $chunk);
            }
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /** POST /v3/mail/send; true on a 2xx (SendGrid answers 202). */
    private function post(array $mail, string $what): bool {
        $key = (string)Globalvars::get_instance()->get_setting('sendgrid_api_key');
        try {
            $resp = self::call('POST', '/v3/mail/send', $key, $mail);
        } catch (ProviderHttpException $e) {
            error_log('[SendGridProvider] ' . $what . ': ' . $e->getMessage());
            return false;
        }
        if (!$resp->ok()) {
            error_log('[SendGridProvider] ' . $what . ': ' . self::describeError($resp));
            return false;
        }
        return true;
    }

    /**
     * The mail body with everything except the personalizations, which
     * send() and sendBatch() fill in differently.
     *
     * @throws RuntimeException when an attachment yields no bytes
     */
    private function buildBaseMail(EmailMessage $message, bool $pin_message_id = true): array {
        $settings = Globalvars::get_instance();

        $mail = [
            'from' => self::address((string)$message->getFrom(), $message->getFromName() ?: null),
            'subject' => (string)$message->getSubject(),
        ];
        if ($replyTo = $message->getReplyTo()) {
            $mail['reply_to'] = ['email' => (string)$replyTo];
        }

        // text/plain must come before text/html: SendGrid refuses the other order.
        $content = [];
        if ($message->getTextBody()) {
            $content[] = ['type' => 'text/plain', 'value' => (string)$message->getTextBody()];
        }
        if ($message->getHtmlBody()) {
            $content[] = ['type' => 'text/html', 'value' => (string)$message->getHtmlBody()];
        }
        if ($content) {
            $mail['content'] = $content;
        }

        $headers = [];
        if ($pin_message_id && $message->getMessageId()) {
            $headers['Message-ID'] = (string)$message->getMessageId();
        }
        foreach ($message->getHeaders() as $name => $value) {
            $headers[(string)$name] = (string)$value;
        }
        if ($headers) {
            // An object always: a map whose only name is "0" would encode as a list.
            $mail['headers'] = (object)$headers;
        }

        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $c = EmailMessage::attachmentContent($attachment);
            $entry = [
                'content' => base64_encode($c['bytes']),
                'filename' => $c['name'],
                'type' => $c['type'],
                'disposition' => 'attachment',
            ];
            if (!empty($attachment['cid'])) {
                // Inline (embedded) image: disposition inline + Content-ID so the
                // body's cid: reference resolves in the recipient's client.
                $entry['disposition'] = 'inline';
                $entry['content_id'] = (string)$attachment['cid'];
            }
            $attachments[] = $entry;
        }
        if ($attachments) {
            $mail['attachments'] = $attachments;
        }

        if ($settings->get_setting('sendgrid_sandbox_mode') == '1') {
            $mail['mail_settings'] = ['sandbox_mode' => ['enable' => true]];
        }

        // Click tracking — explicit on or off based on setting (default off in settings.json)
        $mail['tracking_settings'] = ['click_tracking' => [
            'enable' => $settings->get_setting('sendgrid_click_tracking') == '1',
        ]];

        return $mail;
    }

    /** {email, name}, the name left out when empty. */
    private static function address(string $email, ?string $name): array {
        $out = ['email' => $email];
        if ($name !== null && trim($name) !== '') {
            $out['name'] = $name;
        }
        return $out;
    }

    private static function apiBase(): string {
        return Globalvars::get_instance()->get_setting('sendgrid_region') === 'eu'
            ? 'https://api.eu.sendgrid.com'
            : 'https://api.sendgrid.com';
    }

    /** One API call. Throws ProviderHttpException only when no answer came back. */
    private static function call(string $method, string $path, string $key, ?array $json = null): ProviderHttpResponse {
        $options = ['headers' => ['Authorization' => 'Bearer ' . trim($key)]];
        if ($json !== null) {
            $options['json'] = $json;
        }
        return ProviderHttp::request($method, self::apiBase() . $path, $options);
    }

    /** SendGrid's own words for a failed answer (errors[].message), prefixed with the status. */
    private static function describeError(ProviderHttpResponse $resp): string {
        $words = [];
        foreach ((array)($resp->json()['errors'] ?? []) as $err) {
            if (is_array($err) && !empty($err['message'])) {
                $words[] = (string)$err['message'];
            }
        }
        return 'SendGrid answered ' . $resp->status . ($words ? ': ' . implode('; ', $words) : '');
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
            [
                'key' => 'sendgrid_inbound_spam_threshold',
                'label' => 'Inbound spam score threshold',
                'type' => 'text',
                'helptext' => 'When content spam filtering is on, an inbound spam_score at or above this '
                    . 'value is judged spam. SendGrid posts only a SpamAssassin score (no yes/no), so this '
                    . 'cutoff derives the verdict. Default 5.0 (SpamAssassin\'s own default); lower is stricter.',
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

        $spam = self::extractSpam($post);
        if ($spam !== null) {
            $out['spam'] = $spam;
        }

        return $out;
    }

    /**
     * SendGrid's content-spam signal (specs/inbound_email_content_spam_filtering.md).
     * Unlike Mailgun/SES, SendGrid's Inbound Parse posts only a numeric spam_score
     * (its SpamAssassin score) with NO binary verdict, so the binary has to be derived
     * by comparing the score to a threshold. The default (sendgrid_inbound_spam_threshold,
     * 5.0) is SpamAssassin's own canonical required_score — we echo the engine's cutoff
     * rather than invent one, and it is tunable for deployments with a different mail
     * mix. The score is always recorded; the comparison only sets the binary.
     * Returns ['result'=>spam|ham,'score'=>float,'source'=>'sendgrid'] or null.
     */
    private static function extractSpam(array $post): ?array {
        if (!array_key_exists('spam_score', $post)) {
            return null;
        }
        $score = trim((string)$post['spam_score']);
        if ($score === '' || !is_numeric($score)) {
            return null;
        }
        $score = (float)$score;

        $settings = Globalvars::get_instance();
        $threshold = $settings->get_setting('sendgrid_inbound_spam_threshold');
        $threshold = is_numeric($threshold) ? (float)$threshold : 5.0;

        return [
            'result' => ($score >= $threshold) ? 'spam' : 'ham',
            'score'  => $score,
            'source' => 'sendgrid',
        ];
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
