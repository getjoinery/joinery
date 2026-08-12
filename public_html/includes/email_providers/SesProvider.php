<?php
/**
 * SesProvider - Amazon SES (Simple Email Service) email service provider
 *
 * Implements EmailServiceProvider using aws/aws-sdk-php's SESv2 client.
 * Batch sending is per-recipient since SES has no native non-templated bulk API.
 * Also implements ApiSubmissionRelay via SESv2 sendEmail with Content.Raw so
 * inbound forwarding and the hidden-origin compose path can relay raw MIME
 * through the same AWS credential — over an HTTP API, so the submitting box's
 * IP never enters the delivered Received: chain.
 *
 * Implements DkimRecordSource: GetEmailIdentity reports the Easy DKIM CNAME
 * tokens a sending domain must publish, which drives the mailbox Setup tab's
 * DKIM row. (SES selects the verified identity — and its DKIM keys — from the
 * message's From domain automatically, so raw relays need no path selection.)
 *
 * @version 1.5
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/InboundEmailProvider.php'));

use Aws\SesV2\SesV2Client;
use Aws\Exception\AwsException;

class SesProvider implements EmailServiceProvider, InboundEmailProvider, ApiSubmissionRelay, DkimRecordSource {

    public static function getKey(): string {
        return 'ses';
    }

    public static function getLabel(): string {
        return 'Amazon SES';
    }

    public static function getSpfMechanism(string $domain): string
    {
        return 'include:amazonses.com';
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        // Either explicit static credentials, or rely on role credentials (both blank is OK).
        $access_key = $settings->get_setting('ses_access_key_id');
        $secret_key = $settings->get_setting('ses_secret_access_key');

        if (!empty($access_key) && empty($secret_key)) {
            $errors[] = 'SES access key ID set but secret access key missing';
        }
        if (empty($access_key) && !empty($secret_key)) {
            $errors[] = 'SES secret access key set but access key ID missing';
        }
        if (empty($settings->get_setting('ses_region'))) {
            $errors[] = 'SES region not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Whether the admin has actually entered SES credentials.
     *
     * Distinct from validateConfiguration(): SES treats blank static keys as
     * valid because it can fall back to IAM role / instance credentials at send
     * time. But "region set, keys blank" is indistinguishable from "nothing
     * configured", so the admin validation panel must not fire a live API call
     * in that state. Credentials count as present only when both static keys
     * are supplied.
     */
    public static function hasCredentials(): bool {
        $settings = Globalvars::get_instance();
        return !empty($settings->get_setting('ses_access_key_id'))
            && !empty($settings->get_setting('ses_secret_access_key'));
    }

    /**
     * Live API validation: GetAccount returns sending enablement and quota.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $region = $settings->get_setting('ses_region') ?: 'us-east-1';
        $access_key = $settings->get_setting('ses_access_key_id');
        $secret_key = $settings->get_setting('ses_secret_access_key');
        $configured_domain = $settings->get_setting('ses_verified_domain');

        try {
            $client = self::buildClient($region, $access_key, $secret_key);
            $result = $client->getAccount();

            $details = [
                'Region' => $region,
            ];
            if (!empty($access_key)) {
                $details['Credentials'] = 'Static (Access Key)';
            } else {
                $details['Credentials'] = 'Instance / IAM Role';
            }

            $production = $result->get('ProductionAccessEnabled');
            $details['Production Access'] = $production ? 'Yes' : 'No (Sandbox)';

            $sending = $result->get('SendingEnabled');
            $details['Sending Enabled'] = $sending ? 'Yes' : 'No';

            $quota = $result->get('SendQuota');
            if (is_array($quota)) {
                if (isset($quota['Max24HourSend'], $quota['SentLast24Hours'])) {
                    $details['24h Quota'] = intval($quota['SentLast24Hours']) . ' / ' . intval($quota['Max24HourSend']);
                }
                if (isset($quota['MaxSendRate'])) {
                    $details['Max Send Rate'] = $quota['MaxSendRate'] . '/sec';
                }
            }

            if (!empty($configured_domain)) {
                $details['Verified Domain'] = $configured_domain;
            }

            return [
                'success' => true,
                'label' => 'API Connection Valid',
                'details' => $details,
                'error' => null,
            ];
        } catch (AwsException $e) {
            $code = $e->getAwsErrorCode();
            $details = ['Region' => $region];

            if ($code === 'InvalidClientTokenId' || $code === 'SignatureDoesNotMatch') {
                $error = 'Invalid AWS credentials. Check Access Key ID and Secret Access Key.';
                $label = 'Credentials Rejected';
            } elseif ($code === 'AccessDenied' || $code === 'AccessDeniedException') {
                $error = 'IAM permissions insufficient — need ses:GetAccount and ses:SendEmail.';
                $label = 'Access Denied';
            } elseif (strpos((string) $code, 'Endpoint') !== false) {
                $error = 'Region not reachable — check ses_region.';
                $label = 'Endpoint Unreachable';
            } else {
                $error = $e->getAwsErrorMessage() ?: $e->getMessage();
                $label = 'API Connection Failed';
            }

            return [
                'success' => false,
                'label' => $label,
                'details' => $details,
                'error' => $error,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => ['Region' => $region],
                'error' => $e->getMessage(),
            ];
        }
    }

    public function send(EmailMessage $message): bool {
        $settings = Globalvars::get_instance();

        try {
            $client = self::buildClient(
                $settings->get_setting('ses_region') ?: 'us-east-1',
                $settings->get_setting('ses_access_key_id'),
                $settings->get_setting('ses_secret_access_key')
            );

            $params = $this->buildSendParams($message, $settings);
            $params['Destination'] = $this->buildDestination(
                $message->getRecipients(),
                $message->getCc(),
                $message->getBcc()
            );

            $client->sendEmail($params);
            return true;
        } catch (AwsException $e) {
            error_log('[SesProvider] Send failed: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()));
            return false;
        } catch (\Exception $e) {
            error_log('[SesProvider] Send failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $settings = Globalvars::get_instance();
        $failed = [];

        try {
            $client = self::buildClient(
                $settings->get_setting('ses_region') ?: 'us-east-1',
                $settings->get_setting('ses_access_key_id'),
                $settings->get_setting('ses_secret_access_key')
            );

            $base_params = $this->buildSendParams($message, $settings);

            foreach ($recipients as $email) {
                $params = $base_params;
                $params['Destination'] = ['ToAddresses' => [$email]];

                try {
                    $client->sendEmail($params);
                } catch (AwsException $e) {
                    error_log('[SesProvider] Batch send failed for ' . $email . ': '
                        . ($e->getAwsErrorMessage() ?: $e->getMessage()));
                    $failed[] = $email;
                }
            }
        } catch (\Exception $e) {
            error_log('[SesProvider] Batch setup failed: ' . $e->getMessage());
            $failed = $recipients;
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /**
     * Build the SES SendEmail params shared between send() and sendBatch().
     * Caller fills in 'Destination'.
     */
    private function buildSendParams(EmailMessage $message, Globalvars $settings): array {
        // The Simple content shape cannot carry attachments or custom headers
        // (In-Reply-To/References/Message-Id). When the message needs them, send
        // raw MIME instead — built by the same SmtpMailer mapping every other
        // structured send uses, so attachments (incl. attachData bytes) and
        // threading headers ride along.
        if (!empty($message->getAttachments()) || !empty($message->getHeaders()) || $message->getMessageId()) {
            return $this->buildRawSendParams($message, $settings);
        }

        $from = $message->getFromName()
            ? $message->getFromName() . ' <' . $message->getFrom() . '>'
            : $message->getFrom();

        $body = [];
        if ($message->getHtmlBody()) {
            $body['Html'] = ['Data' => $message->getHtmlBody(), 'Charset' => 'UTF-8'];
        }
        if ($message->getTextBody()) {
            $body['Text'] = ['Data' => $message->getTextBody(), 'Charset' => 'UTF-8'];
        }

        $params = [
            'FromEmailAddress' => $from,
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $message->getSubject(), 'Charset' => 'UTF-8'],
                    'Body' => $body,
                ],
            ],
        ];

        if ($replyTo = $message->getReplyTo()) {
            $params['ReplyToAddresses'] = [$replyTo];
        }

        $config_set = $settings->get_setting('ses_configuration_set');
        if (!empty($config_set)) {
            $params['ConfigurationSetName'] = $config_set;
        }

        return $params;
    }

    /**
     * Build SES params with raw MIME (Content.Raw) for messages that carry
     * attachments or custom headers. The MIME is assembled by SmtpMailer's
     * EmailMessage->PHPMailer mapping — the one place that mapping lives — then
     * handed to SES. FromEmailAddress is omitted so SES uses the MIME From header
     * (matching relayRawMessage()); the caller still fills Destination.
     */
    private function buildRawSendParams(EmailMessage $message, Globalvars $settings): array {
        require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));

        $mailer = new SmtpMailer();
        $mailer->applyMessage($message);
        $mailer->preSend();
        $raw = $mailer->getSentMIMEMessage();

        $params = [
            'Content' => ['Raw' => ['Data' => $raw]],
        ];

        $config_set = $settings->get_setting('ses_configuration_set');
        if (!empty($config_set)) {
            $params['ConfigurationSetName'] = $config_set;
        }

        return $params;
    }

    /**
     * Convert EmailMessage recipient arrays into SES Destination shape.
     */
    private function buildDestination(array $recipients, array $cc, array $bcc): array {
        $destination = [];

        if (!empty($recipients)) {
            $destination['ToAddresses'] = array_map(
                fn($r) => !empty($r['name']) ? $r['name'] . ' <' . $r['email'] . '>' : $r['email'],
                $recipients
            );
        }
        if (!empty($cc)) {
            $destination['CcAddresses'] = array_map(
                fn($r) => !empty($r['name']) ? $r['name'] . ' <' . $r['email'] . '>' : $r['email'],
                $cc
            );
        }
        if (!empty($bcc)) {
            $destination['BccAddresses'] = array_map(
                fn($r) => !empty($r['name']) ? $r['name'] . ' <' . $r['email'] . '>' : $r['email'],
                $bcc
            );
        }

        return $destination;
    }

    /**
     * Build the SES v2 client. If credentials are blank, the SDK auto-discovers
     * via the standard AWS provider chain (env vars, instance metadata, ~/.aws/credentials).
     */
    private static function buildClient(string $region, ?string $access_key, ?string $secret_key): SesV2Client {
        $config = [
            'version' => '2019-09-27',
            'region' => $region,
        ];

        if (!empty($access_key) && !empty($secret_key)) {
            $config['credentials'] = [
                'key' => $access_key,
                'secret' => $secret_key,
            ];
        }

        return new SesV2Client($config);
    }

    // ── DkimRecordSource ────────────────────────────────────────────────

    /**
     * The Easy DKIM CNAME records SES requires for a sending domain identity.
     * A domain verified with BYODKIM has no tokens — that returns 'ok' with no
     * records, meaning nothing is left to publish. NotFoundException means the
     * domain is not an identity in this account/region.
     */
    public static function getDkimStatus(string $domain): array {
        $settings = Globalvars::get_instance();
        try {
            $client = self::buildClient(
                $settings->get_setting('ses_region') ?: 'us-east-1',
                $settings->get_setting('ses_access_key_id'),
                $settings->get_setting('ses_secret_access_key')
            );
            $identity = $client->getEmailIdentity(['EmailIdentity' => $domain]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return ['status' => 'not_registered', 'records' => []];
            }
            error_log('[SesProvider] getDkimStatus(' . $domain . ') failed: '
                . ($e->getAwsErrorMessage() ?: $e->getMessage()));
            return ['status' => 'unreachable', 'records' => []];
        } catch (\Throwable $e) {
            error_log('[SesProvider] getDkimStatus(' . $domain . ') failed: ' . $e->getMessage());
            return ['status' => 'unreachable', 'records' => []];
        }

        $records = [];
        foreach (($identity['DkimAttributes']['Tokens'] ?? []) as $token) {
            $records[] = [
                'type'  => 'CNAME',
                'name'  => $token . '._domainkey.' . $domain,
                'value' => $token . '.dkim.amazonses.com',
            ];
        }
        return ['status' => 'ok', 'records' => $records];
    }

    // ── RawMessageRelay ─────────────────────────────────────────────────

    /**
     * Relay raw MIME via SESv2 sendEmail with Content.Raw.Data, reusing the
     * same AWS credential the send() path uses. FromEmailAddress is omitted so
     * SES uses the From header already in the raw message (the router rewrote
     * it to the verified address). SES owns bounce handling and uses its own
     * verified MAIL FROM domain, so the SRS envelope sender is not honored on
     * this path — that is the documented per-path behavior. Each destination
     * is sent separately for a per-destination result, matching forwardEmail().
     */
    public function relayRawMessage(string $raw_mime, string $envelope_sender, array $destinations): array {
        $settings = Globalvars::get_instance();
        $results = [];

        try {
            $client = self::buildClient(
                $settings->get_setting('ses_region') ?: 'us-east-1',
                $settings->get_setting('ses_access_key_id'),
                $settings->get_setting('ses_secret_access_key')
            );
        } catch (\Exception $e) {
            error_log('[SesProvider] relayRawMessage client setup failed: ' . $e->getMessage());
            foreach ($destinations as $destination) {
                $results[$destination] = false;
            }
            return $results;
        }

        $config_set = $settings->get_setting('ses_configuration_set');

        foreach ($destinations as $destination) {
            $params = [
                'Content' => ['Raw' => ['Data' => $raw_mime]],
                'Destination' => ['ToAddresses' => [$destination]],
            ];
            if (!empty($config_set)) {
                $params['ConfigurationSetName'] = $config_set;
            }

            try {
                $client->sendEmail($params);
                $results[$destination] = true;
            } catch (AwsException $e) {
                error_log('[SesProvider] relayRawMessage failed to ' . $destination . ': '
                    . ($e->getAwsErrorMessage() ?: $e->getMessage()));
                $results[$destination] = false;
            } catch (\Exception $e) {
                error_log('[SesProvider] relayRawMessage failed to ' . $destination . ': ' . $e->getMessage());
                $results[$destination] = false;
            }
        }

        return $results;
    }

    // ── InboundEmailProvider ────────────────────────────────────────────

    public static function getInboundSettingsFields(): array {
        // SNS messages are authenticated by their cryptographic signature
        // (verified against AWS's published certificate), so there is no
        // inbound secret to configure.
        return [];
    }

    public static function isWebhook(): bool {
        return true;
    }

    public static function getSetupChecks(?string $domain = null): array {
        $settings = Globalvars::get_instance();
        $results = [];

        $results[] = self::makeResult('ses.inbound_sns', '', 'plugin', 'SES inbound (SNS)', 'required', 'info',
            'SES delivers inbound mail as SNS notifications; each POST is verified against its AWS signature.',
            'Create an SES receipt rule that publishes to an SNS topic, and subscribe that topic to the '
            . 'inbound webhook URL (?provider=ses). The first POST is a SubscriptionConfirmation, which is '
            . 'confirmed automatically.');

        if ($domain) {
            $records = self::getDnsRecords($domain);
            foreach ($records as $rec) {
                $results[] = self::makeResult(
                    'ses.dns.' . strtolower($rec['type']),
                    $domain,
                    'domain',
                    'SES ' . $rec['type'] . ' record',
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
        $settings = Globalvars::get_instance();
        $region = $settings->get_setting('ses_region') ?: 'us-east-1';
        return [
            ['type' => 'MX', 'name' => $domain, 'value' => '10 inbound-smtp.' . $region . '.amazonaws.com',
             'note' => 'Routes inbound mail for ' . $domain . ' to SES in ' . $region . '. '
                . 'A matching verified domain identity and an active receipt rule set are also required.'],
        ];
    }

    /**
     * Handle an inbound SES message delivered over SNS.
     *
     * SES delivers inbound via SNS, not a multipart webhook — so the payload is
     * the JSON SNS envelope in $raw_body ($post is empty). The envelope is
     * authenticated by its cryptographic signature (verified against AWS's
     * published certificate); a SubscriptionConfirmation is auto-confirmed.
     *
     * The SES "Received" notification carries the verdicts under receipt.*Verdict
     * and the full raw message under 'content'. SES is the only webhook provider
     * that reports a real DMARC verdict.
     *
     * Verdict statuses map: PASS→pass, FAIL→fail, GRAY→none (no policy /
     * insufficient info), PROCESSING_FAILED→null (recorded 'none').
     */
    public function handleInbound(array $post, string $raw_body): ?array {
        if (trim($raw_body) === '') {
            error_log('[SesProvider] inbound rejected — empty SNS body');
            return null;
        }

        $message = json_decode($raw_body, true);
        if (!is_array($message)) {
            error_log('[SesProvider] inbound rejected — body is not a JSON SNS envelope');
            return null;
        }

        if (!self::verifySnsSignature($message)) {
            error_log('[SesProvider] inbound rejected — SNS signature verification failed');
            return null;
        }

        $type = (string)($message['Type'] ?? '');

        // First-contact handshake: confirm the subscription by fetching the URL.
        if ($type === 'SubscriptionConfirmation') {
            $url = (string)($message['SubscribeURL'] ?? '');
            if ($url !== '' && self::isAwsSnsUrl($url)) {
                @file_get_contents($url, false, self::noRedirectContext());
                error_log('[SesProvider] confirmed SNS subscription');
            }
            // Nothing to route — the dispatcher will answer 406; SNS treats the
            // confirmation as complete via the URL fetch above.
            return null;
        }

        if ($type !== 'Notification') {
            error_log('[SesProvider] inbound ignored — unexpected SNS type: ' . $type);
            return null;
        }

        $ses = json_decode((string)($message['Message'] ?? ''), true);
        if (!is_array($ses)) {
            error_log('[SesProvider] inbound rejected — SNS Message is not JSON');
            return null;
        }

        $raw_mime = self::extractRawMime($ses);
        $recipient = self::extractRecipient($ses);

        if ($raw_mime === '' || $recipient === '') {
            error_log('[SesProvider] inbound rejected — missing message content or recipient '
                . '(SNS-published content is required; S3-only delivery is not supported here)');
            return null;
        }

        $out = [
            'raw_mime' => $raw_mime,
            'recipient' => $recipient,
        ];

        $auth = self::extractAuth($ses);
        if ($auth !== null) {
            $out['auth'] = $auth;
        }

        $spam = self::extractSpam($ses);
        if ($spam !== null) {
            $out['spam'] = $spam;
        }

        return $out;
    }

    /**
     * SES's own content-spam signal (specs/inbound_email_content_spam_filtering.md):
     * receipt.spamVerdict.status — PASS → ham, FAIL → spam. SES reports no score, so
     * only the binary verdict is surfaced. Anything else (GRAY / PROCESSING_FAILED /
     * absent) yields no signal (null), so the router falls back to the auth rule.
     * Returns ['result'=>spam|ham, 'source'=>'ses'] or null.
     */
    private static function extractSpam(array $ses): ?array {
        $status = $ses['receipt']['spamVerdict']['status'] ?? null;
        if ($status === null) {
            return null;
        }
        switch (strtoupper(trim((string)$status))) {
            case 'PASS': return ['result' => 'ham',  'source' => 'ses'];
            case 'FAIL': return ['result' => 'spam', 'source' => 'ses'];
            default:     return null;
        }
    }

    /**
     * Verify an SNS message's cryptographic signature with OpenSSL — the SDK's
     * Aws\Sns\MessageValidator is not present in this build, and the algorithm
     * is self-contained: build the canonical string-to-sign from the documented
     * fields, fetch the signing certificate (pinned to an amazonaws.com SNS
     * host), and verify. SignatureVersion 1 = SHA1, 2 = SHA256.
     */
    private static function verifySnsSignature(array $msg): bool {
        $signature_b64 = (string)($msg['Signature'] ?? '');
        $cert_url      = (string)($msg['SigningCertURL'] ?? '');
        if ($signature_b64 === '' || $cert_url === '' || !self::isAwsSnsUrl($cert_url)) {
            return false;
        }

        $string_to_sign = self::buildSnsStringToSign($msg);
        if ($string_to_sign === null) {
            return false;
        }

        $cert = @file_get_contents($cert_url, false, self::noRedirectContext());
        if ($cert === false || $cert === '') {
            error_log('[SesProvider] could not fetch SNS signing certificate');
            return false;
        }
        $pubkey = @openssl_pkey_get_public($cert);
        if ($pubkey === false) {
            return false;
        }

        $version = (string)($msg['SignatureVersion'] ?? '1');
        $algo = ($version === '2') ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        $ok = openssl_verify($string_to_sign, base64_decode($signature_b64), $pubkey, $algo);
        return $ok === 1;
    }

    /**
     * Build the SNS canonical string-to-sign: each signed field as "key\nvalue\n"
     * in the documented order for the message Type. Returns null on an
     * unrecognized type.
     */
    private static function buildSnsStringToSign(array $msg): ?string {
        $type = (string)($msg['Type'] ?? '');
        if ($type === 'Notification') {
            $fields = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
        } elseif ($type === 'SubscriptionConfirmation' || $type === 'UnsubscribeConfirmation') {
            $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        } else {
            return null;
        }

        $out = '';
        foreach ($fields as $f) {
            // Subject is optional; skip it (and any absent field) entirely.
            if (!array_key_exists($f, $msg)) {
                continue;
            }
            $out .= $f . "\n" . $msg[$f] . "\n";
        }
        return $out;
    }

    /**
     * Pin a URL to an AWS SNS host over HTTPS (anti-SSRF): https and a host of
     * the form sns.<region>.amazonaws.com or sns.<region>.amazonaws.com.cn.
     */
    private static function isAwsSnsUrl(string $url): bool {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        return (bool)preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/i', $parts['host']);
    }

    /**
     * A stream context that does NOT follow redirects. The two SNS fetches are
     * host-pinned to sns.<region>.amazonaws.com and signature-gated, so they are
     * not a live SSRF — but an open redirect on a genuine SNS host would escape
     * that pin. file_get_contents follows redirects by default; this turns that
     * off (specs/safe_http_client.md — SES hardening).
     */
    private static function noRedirectContext()
    {
        return stream_context_create([
            'http' => ['follow_location' => 0, 'max_redirects' => 0, 'timeout' => 10],
        ]);
    }

    /**
     * The full raw message lives in the notification's 'content' field. SES
     * encodes it as UTF-8 (raw) by default, or Base64 if configured — detect
     * base64 by the absence of a header colon in the first chunk.
     */
    private static function extractRawMime(array $ses): string {
        $content = (string)($ses['content'] ?? '');
        if ($content === '') {
            return '';
        }
        $head = substr($content, 0, 200);
        if (strpos($head, ':') === false) {
            $decoded = base64_decode($content, true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }
        return $content;
    }

    /**
     * Envelope recipient: receipt.recipients[0], falling back to
     * mail.destination[0].
     */
    private static function extractRecipient(array $ses): string {
        if (!empty($ses['receipt']['recipients'][0])) {
            return strtolower(trim((string)$ses['receipt']['recipients'][0]));
        }
        if (!empty($ses['mail']['destination'][0])) {
            return strtolower(trim((string)$ses['mail']['destination'][0]));
        }
        return '';
    }

    /**
     * Map receipt.{spf,dkim,dmarc}Verdict.status to the normalized verdict array.
     * Returns null when no receipt verdicts are present (router falls back to
     * 'unverified').
     */
    private static function extractAuth(array $ses): ?array {
        $receipt = $ses['receipt'] ?? null;
        if (!is_array($receipt)) {
            return null;
        }

        $map = function ($status) {
            switch (strtoupper(trim((string)$status))) {
                case 'PASS': return 'pass';
                case 'FAIL': return 'fail';
                case 'GRAY': return 'none';
                default:     return null; // PROCESSING_FAILED / unknown → recorded 'none'
            }
        };

        $spf   = isset($receipt['spfVerdict']['status'])   ? $map($receipt['spfVerdict']['status'])   : null;
        $dkim  = isset($receipt['dkimVerdict']['status'])  ? $map($receipt['dkimVerdict']['status'])  : null;
        $dmarc = isset($receipt['dmarcVerdict']['status']) ? $map($receipt['dmarcVerdict']['status']) : null;

        if (!isset($receipt['spfVerdict']) && !isset($receipt['dkimVerdict']) && !isset($receipt['dmarcVerdict'])) {
            return null;
        }

        return [
            'spf'    => $spf,
            'dkim'   => $dkim,
            'dmarc'  => $dmarc,
            'source' => 'ses',
        ];
    }

    private static function makeResult($id, $scope, $layer, $label, $severity, $status, $summary, $detail = '', $fix = null, $recheckable = true): array {
        return [
            'id' => $id, 'scope' => $scope, 'layer' => $layer, 'label' => $label,
            'severity' => $severity, 'status' => $status, 'summary' => $summary,
            'detail' => $detail, 'fix' => $fix, 'recheckable' => $recheckable,
        ];
    }
}
