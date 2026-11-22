<?php
/**
 * ResendProvider - Resend email service provider, over its HTTP API.
 *
 * Talks to https://api.resend.com directly through ProviderHttp, with the
 * key as Authorization: Bearer. Batch sends go through /emails/batch, up to
 * 100 messages per call; a chunk succeeds or fails as a whole.
 *
 * WHAT THE DOCS SAY (read 2026-11-22):
 *  - Errors carry a `name` (https://resend.com/docs/api-reference/errors).
 *    restricted_api_key is a key limited to sending; invalid_api_key and
 *    missing_api_key are a key Resend will not take. The documented statuses
 *    for these disagree between pages (401, 403, 400), so validation decides
 *    by `name` alone, never by status or message text.
 *  - The send API takes a custom `headers` object
 *    (https://resend.com/docs/dashboard/emails/custom-headers). Nothing in the
 *    docs says Message-ID is refused or replaced, so the pinned Message-ID is
 *    sent as a header. The live check confirms Resend keeps it.
 *  - /emails/batch does not take attachments
 *    (https://resend.com/docs/api-reference/emails/send-batch-emails), so a
 *    batch whose message has attachments is sent one /emails call per
 *    recipient instead of dropping them.
 *  - No Content-ID field: an inline part is sent as a regular attachment and
 *    a marker is logged.
 *
 * @version 2.1 - batch entries never carry the pinned Message-ID (review B15); the per-recipient path for
 *   a batch with attachments retries a 429 (B16); headers is always a JSON object (B20); a display name
 *   is quoted per RFC 5322; a send counts only when the answer parses with an id (a batch: data[])
 * @version 2.0 - on ProviderHttp, no SDK; the pinned Message-ID is sent; an attachment that yields no bytes
 *   fails the send; validation reads the error name, so a send-only key shows as valid (restricted)
 *   (specs/email_provider_sdk_removal.md WP2, B1, B2, B6, B7)
 */

require_once(PathHelper::getComposerAutoloadPath());

class ResendProvider implements EmailServiceProvider, SingleKeyProvider {

    const API_BASE = 'https://api.resend.com';

    public static function getKey(): string {
        return 'resend';
    }

    public static function getLabel(): string {
        return 'Resend';
    }

    /** re_ then the key body. */
    public static function apiKeyPattern(): string {
        return '/^re_[A-Za-z0-9_]{16,}$/';
    }

    /** @var array<string,string> Per-request cache of domain => mechanism. */
    private static $spf_mechanism_cache = [];

    public static function getSpfMechanism(string $domain): string
    {
        // Resend publishes per-account DNS records (no fixed shared range), so
        // the mechanism is fetched from its API: the SPF TXT value Resend
        // prescribes for the domain, minus the version/all wrapper. '' when the
        // domain is not registered there or the API is unreachable — callers
        // treat that as nothing to prescribe.
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }
        if (array_key_exists($domain, self::$spf_mechanism_cache)) {
            return self::$spf_mechanism_cache[$domain];
        }
        $mechanism = '';
        $key = trim((string)Globalvars::get_instance()->get_setting('resend_api_key'));
        if ($key !== '') {
            try {
                $list = self::call('GET', '/domains', $key);
                if (!$list->ok()) {
                    throw new RuntimeException(self::describeError($list));
                }
                foreach (self::asList($list->json()['data'] ?? null) as $entry) {
                    $name = strtolower(trim((string)($entry['name'] ?? '')));
                    if ($name !== $domain && $name !== '') {
                        continue;
                    }
                    $detail = self::call('GET', '/domains/' . rawurlencode((string)($entry['id'] ?? '')), $key);
                    if (!$detail->ok()) {
                        throw new RuntimeException(self::describeError($detail));
                    }
                    foreach (self::asList($detail->json()['records'] ?? null) as $r) {
                        if (!is_array($r)
                            || strcasecmp((string)($r['record'] ?? ''), 'SPF') !== 0
                            || strcasecmp((string)($r['type'] ?? ''), 'TXT') !== 0) {
                            continue;
                        }
                        $mechanism = self::mechanismsFromSpfValue((string)($r['value'] ?? ''));
                        break 2;
                    }
                }
            } catch (\Throwable $e) {
                error_log('ResendProvider::getSpfMechanism: ' . $e->getMessage());
            }
        }
        self::$spf_mechanism_cache[$domain] = $mechanism;
        return $mechanism;
    }

    /** The mechanisms of a v=spf1 record, without the version tag or the all term. */
    private static function mechanismsFromSpfValue(string $value): string
    {
        $terms = [];
        foreach (preg_split('/\s+/', trim($value)) as $term) {
            if ($term === '' || stripos($term, 'v=spf1') === 0
                || preg_match('/^[-~?+]?all$/i', $term)) {
                continue;
            }
            $terms[] = $term;
        }
        return implode(' ', $terms);
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
     * Live API validation via GET /api-keys, the cheapest authenticated call.
     * Decided by the error's name alone (B7): a send-only key cannot list
     * keys and answers restricted_api_key, which is still a working key.
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
            $resp = self::call('GET', '/api-keys', (string)$key);
        } catch (ProviderHttpException $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => $e->getMessage(),
            ];
        }

        $details = [];
        if ($resp->ok()) {
            $details['API Keys on Account'] = count(self::asList($resp->json()['data'] ?? null));
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

        $name = (string)($resp->json()['name'] ?? '');
        if ($name === 'restricted_api_key') {
            $details['API Keys on Account'] = '(restricted key — cannot list)';
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
        if ($name === 'invalid_api_key' || $name === 'missing_api_key') {
            return [
                'success' => false,
                'label' => 'API Key Rejected',
                'details' => [],
                'error' => 'Invalid API key. Must be a Resend API key starting with "re_".',
            ];
        }
        return [
            'success' => false,
            'label' => 'API Connection Failed',
            'details' => [],
            'error' => self::describeError($resp),
        ];
    }

    public function send(EmailMessage $message): bool {
        try {
            $payload = $this->buildPayload($message);
        } catch (RuntimeException $e) {
            error_log('[ResendProvider] Send failed: ' . $e->getMessage());
            return false;
        }
        $payload['to'] = $this->formatRecipients($message->getRecipients());
        $cc = $this->formatRecipients($message->getCc());
        if (!empty($cc)) {
            $payload['cc'] = $cc;
        }
        $bcc = $this->formatRecipients($message->getBcc());
        if (!empty($bcc)) {
            $payload['bcc'] = $bcc;
        }
        return $this->sendOne($payload);
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        try {
            // A pinned Message-ID names one message; every entry is its own.
            $base = $this->buildPayload($message, false);
        } catch (RuntimeException $e) {
            error_log('[ResendProvider] Batch send failed: ' . $e->getMessage());
            return ['success' => false, 'failed_recipients' => array_values($recipients)];
        }
        $key = (string)Globalvars::get_instance()->get_setting('resend_api_key');
        $failed = [];

        // /emails/batch takes no attachments: one /emails per recipient keeps them.
        if (!empty($base['attachments'])) {
            foreach ($recipients as $email) {
                $entry = $base;
                $entry['to'] = [$email];
                // Many calls in a row can meet Resend's rate limit: retry a 429.
                if (!$this->sendOne($entry, 2)) {
                    $failed[] = $email;
                }
            }
            return ['success' => empty($failed), 'failed_recipients' => $failed];
        }

        foreach (array_chunk(array_values($recipients), 100) as $chunk) {
            $payload = [];
            foreach ($chunk as $email) {
                $entry = $base;
                $entry['to'] = [$email];
                $payload[] = $entry;
            }
            try {
                $resp = self::call('POST', '/emails/batch', $key, $payload);
                $data = $resp->ok() ? (($resp->json() ?? [])['data'] ?? null) : null;
                if (!is_array($data) || count($data) !== count($chunk)) {
                    error_log('[ResendProvider] Batch chunk failed: ' . self::describeError($resp));
                    $failed = array_merge($failed, $chunk);
                }
            } catch (ProviderHttpException $e) {
                error_log('[ResendProvider] Batch chunk failed: ' . $e->getMessage());
                $failed = array_merge($failed, $chunk);
            }
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /** One POST /emails; true when Resend accepted it. */
    private function sendOne(array $payload, int $retries = 0): bool {
        $key = (string)Globalvars::get_instance()->get_setting('resend_api_key');
        try {
            $resp = self::call('POST', '/emails', $key, $payload, $retries);
        } catch (ProviderHttpException $e) {
            error_log('[ResendProvider] Send failed: ' . $e->getMessage());
            return false;
        }
        $id = $resp->ok() ? (($resp->json() ?? [])['id'] ?? null) : null;
        if (!is_string($id) || $id === '') {
            error_log('[ResendProvider] Send failed: ' . self::describeError($resp));
            return false;
        }
        return true;
    }

    /**
     * Build the Resend payload shared between send() and sendBatch().
     * Caller fills in 'to' (and optionally cc/bcc).
     *
     * @throws RuntimeException when an attachment yields no bytes
     */
    private function buildPayload(EmailMessage $message, bool $pin_message_id = true): array {
        $from = self::formatAddress((string)$message->getFrom(), (string)$message->getFromName());

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

        $headers = [];
        if ($pin_message_id && $message->getMessageId()) {
            $headers['Message-ID'] = (string)$message->getMessageId();
        }
        foreach ($message->getHeaders() as $name => $value) {
            $headers[(string)$name] = (string)$value;
        }
        if (!empty($headers)) {
            // An object always: a map whose only name is "0" would encode as a list.
            $payload['headers'] = (object)$headers;
        }

        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            $content = EmailMessage::attachmentContent($a);
            if (!empty($a['cid'])) {
                // The Resend API has no Content-ID field, so an inline part cannot
                // embed — degrade it to a regular downloadable attachment. Declared
                // provider limitation, logged once so the fallback is visible.
                error_log('[ResendProvider] Inline attachment degraded to regular attachment (no Content-ID support): cid=' . $a['cid']);
            }
            $attachments[] = [
                'filename' => $content['name'],
                'content' => base64_encode($content['bytes']),
            ];
        }
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /** One API call. Throws ProviderHttpException only when no answer came back. */
    private static function call(string $method, string $path, string $key, ?array $json = null, int $retries = 0): ProviderHttpResponse {
        $options = ['headers' => ['Authorization' => 'Bearer ' . trim($key)], 'retries' => $retries];
        if ($json !== null) {
            $options['json'] = $json;
        }
        return ProviderHttp::request($method, self::API_BASE . $path, $options);
    }

    /** Resend's own words for a failed answer, prefixed with the status. */
    private static function describeError(ProviderHttpResponse $resp): string {
        $message = (string)($resp->json()['message'] ?? '');
        return 'Resend answered ' . $resp->status . ($message !== '' ? ': ' . $message : '');
    }

    /** A value as a plain list. */
    private static function asList($value): array {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Convert EmailMessage recipient arrays into Resend's "Name <email>" string format.
     */
    private function formatRecipients(array $list): array {
        $out = [];
        foreach ($list as $r) {
            if (!empty($r['email'])) {
                $out[] = self::formatAddress((string)$r['email'], (string)($r['name'] ?? ''));
            }
        }
        return $out;
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
}
