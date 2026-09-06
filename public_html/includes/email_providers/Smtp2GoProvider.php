<?php
/**
 * Smtp2GoProvider — SMTP2GO over its v3 HTTP API.
 *
 * One credential does everything: the API key sends mail AND registers the
 * sending domain, so a site is configured by pasting a key rather than by
 * copying an SMTP username and password out of a dashboard. That is why this
 * is an API provider and not a preset pointed at mail.smtp2go.com — the
 * registrar half is what lets guided setup register the domain and read back
 * the records it needs, instead of sending the operator away to do it by hand.
 *
 * SMTP2GO REFUSES TO SEND FROM AN UNVERIFIED SENDER, so registration is not a
 * nicety here: until the domain is added and its records resolve, every send
 * fails. The wizard's DNS stage exists for exactly that gap.
 *
 * THE RECORDS ARE THREE CNAMEs, NOT A TXT KEY. SMTP2GO issues a DKIM record
 * as a CNAME at its own selector, a return-path (VERP) CNAME at a subdomain of
 * the sender's domain, and — only when link tracking is on — a tracking CNAME.
 * Prescribing a TXT record for the DKIM row, or omitting the return-path row,
 * leaves a domain that never verifies while every dashboard says it was added.
 *
 * NO SPF MECHANISM. Because the return path lives on the customer's own
 * subdomain, SPF is evaluated against a record SMTP2GO maintains behind that
 * CNAME and the From domain needs no include: — see getSpfMechanism().
 *
 * Not a RawMessageRelay: /email/mime takes only the encoded message, with no
 * envelope sender and no chosen envelope recipients, so it cannot express what
 * inbound forwarding needs. Forwarding keeps using the SMTP relay.
 *
 * @version 1.0
 */

require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Smtp2GoProvider implements EmailServiceProvider, DkimRecordSource, SendingDomainRegistrar {

    /** The regionless endpoint, which serves accounts in every region. */
    const API_BASE = 'https://api.smtp2go.com/v3/';

    /**
     * The endpoints this provider calls, as [path => [what it does, the
     * permission group it sits under in SMTP2GO]].
     *
     * The group names are what the operator actually sees: SMTP2GO's key
     * permissions are listed by group (its own endpoint index at
     * /v3/api/index is the authority), so a report that named only paths
     * would send someone hunting for a checkbox that is not labelled that way.
     */
    const ENDPOINTS_USED = array(
        '/email/send'    => array('send mail', 'Emails'),
        '/email/batch'   => array('send bulk mail in one call', 'Emails'),
        '/domain/add'    => array('register the sending domain', 'Sender Domains'),
        '/domain/view'   => array('read the domain\'s DNS records', 'Sender Domains'),
        '/domain/verify' => array('ask SMTP2GO to re-check DNS', 'Sender Domains'),
    );

    /** @var array<string,string> Per-request cache of domain => state. */
    private static $domain_state = array();
    /** @var array<string,array|null> Per-request cache of domain => response entry. */
    private static $domain_entry = array();

    public static function getKey(): string {
        return 'smtp2go';
    }

    public static function getLabel(): string {
        return 'SMTP2GO';
    }

    /**
     * None — and deliberately, not for want of looking it up.
     *
     * SMTP2GO sends with a return path on a subdomain of the sending domain
     * (the em###### CNAME below), so the SPF check runs against a record
     * SMTP2GO maintains at the far end of that CNAME. Adding include: terms to
     * the From domain's own policy authorizes nothing extra and spends one of
     * the ten DNS lookups an SPF evaluation is allowed. Callers read '' as
     * "nothing to prescribe", which is exactly right here.
     */
    public static function getSpfMechanism(string $domain): string {
        return '';
    }

    public static function validateConfiguration(): array {
        $errors = array();
        if (trim((string)Globalvars::get_instance()->get_setting('smtp2go_api_key')) === '') {
            $errors[] = 'SMTP2GO API key not configured';
        }
        return array('valid' => empty($errors), 'errors' => $errors);
    }

    /**
     * Live validation for the settings panel.
     *
     * /api_keys/permissions is answerable by EVERY key whatever its scope, so
     * it separates the two failures an operator confuses: a key that is wrong
     * (401) from a key that is real but too narrow for part of what this
     * provider does. The second is reported as a working key with a note
     * naming what it cannot do, because sending still works — only guided
     * domain setup is lost.
     */
    public static function validateApiConnection(): array {
        $key = trim((string)Globalvars::get_instance()->get_setting('smtp2go_api_key'));
        if ($key === '') {
            return array('success' => false, 'label' => 'Not Configured', 'details' => array(),
                'error' => 'Enter an API key to validate the connection');
        }

        try {
            $data = self::post('api_keys/permissions', array());
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, '401') !== false || stripos($message, 'unauthor') !== false) {
                return array('success' => false, 'label' => 'API Key Rejected', 'details' => array(),
                    'error' => 'SMTP2GO rejected this API key. Check it was copied whole, and that it is not disabled.');
            }
            return array('success' => false, 'label' => 'API Connection Failed', 'details' => array(),
                'error' => $message);
        }

        // The permission list sits either at the top level or one level in,
        // depending on how the key was issued; read whichever answers.
        $allowed = array();
        foreach (self::asList($data) as $entry) {
            if (is_string($entry)) {
                $allowed[] = $entry;
            } elseif (is_array($entry)) {
                foreach (self::asList($entry['data'] ?? array()) as $inner) {
                    if (is_string($inner)) { $allowed[] = $inner; }
                }
            }
        }

        $missing = array();
        $groups = array();
        // An empty list is a key whose permissions this endpoint did not
        // enumerate, not a key that can do nothing — say nothing rather than
        // accuse a working key.
        if (!empty($allowed)) {
            foreach (self::ENDPOINTS_USED as $path => $spec) {
                if (!in_array($path, $allowed, true)) {
                    $missing[$path] = $spec[0];
                    $groups[$spec[1]] = $spec[1];
                }
            }
        }

        $details = array('Endpoints this key may use' => empty($allowed) ? '(not reported)' : count($allowed));
        if (!empty($missing)) {
            $details['Cannot'] = implode('; ', $missing);
            $details['To fix'] = 'In SMTP2GO under Sending, then API Keys, give this key the '
                . implode(' and ', $groups) . ' permission'
                . (count($groups) > 1 ? 's.' : '.');
        }
        return array(
            'success' => true,
            'label'   => empty($missing) ? 'API Key Valid' : 'API Key Valid (Limited Permissions)',
            'details' => $details,
            'error'   => null,
        );
    }

    // ── Sending ─────────────────────────────────────────────────────────

    public function send(EmailMessage $message): bool {
        $payload = $this->buildPayload($message);
        $payload['to'] = self::formatAddresses($message->getRecipients());
        $cc = self::formatAddresses($message->getCc());
        if (!empty($cc)) { $payload['cc'] = $cc; }
        $bcc = self::formatAddresses($message->getBcc());
        if (!empty($bcc)) { $payload['bcc'] = $bcc; }

        try {
            $data = self::post('email/send', $payload);
        } catch (Throwable $e) {
            error_log('[Smtp2GoProvider] Send failed: ' . $e->getMessage());
            return false;
        }
        return self::sendSucceeded($data);
    }

    /**
     * One call per chunk through /email/batch, which takes up to 1,000
     * complete email objects and answers positionally — so a chunk that
     * partially fails still reports exactly which addresses were not accepted.
     *
     * Each recipient gets their own message rather than sharing a To: line:
     * a bulk send must never show its recipients to each other.
     *
     * A chunk that fails outright falls back to sending its members one at a
     * time. The common cause is a key permitted for /email/send and nothing
     * else, and a narrow key should cost speed, not delivery.
     */
    public function sendBatch(EmailMessage $message, array $recipients): array {
        $base = $this->buildPayload($message);
        $failed = array();

        foreach (array_chunk(array_values($recipients), 500) as $chunk) {
            $emails = array();
            foreach ($chunk as $address) {
                $entry = $base;
                $entry['to'] = array($address);
                $emails[] = $entry;
            }

            try {
                $data = self::post('email/batch', array('emails' => $emails));
            } catch (Throwable $e) {
                error_log('[Smtp2GoProvider] Batch chunk failed, sending individually: ' . $e->getMessage());
                $failed = array_merge($failed, $this->sendIndividually($base, $chunk));
                continue;
            }

            // Positional: entry i answers for recipient i. An entry carrying
            // neither an id nor a schedule is a refusal for that address.
            $results = self::asList($data);
            foreach ($chunk as $i => $address) {
                $result = $results[$i] ?? null;
                $accepted = is_array($result)
                    && (!empty($result['email_id']) || !empty($result['schedule_id']));
                if (!$accepted) {
                    $failed[] = $address;
                }
            }
        }

        return array('success' => empty($failed), 'failed_recipients' => $failed);
    }

    /** One /email/send per address; the addresses that were not accepted. */
    private function sendIndividually(array $base, array $addresses): array {
        $failed = array();
        foreach ($addresses as $address) {
            $payload = $base;
            $payload['to'] = array($address);
            try {
                if (!self::sendSucceeded(self::post('email/send', $payload))) {
                    $failed[] = $address;
                }
            } catch (Throwable $e) {
                error_log('[Smtp2GoProvider] Send to ' . $address . ' failed: ' . $e->getMessage());
                $failed[] = $address;
            }
        }
        return $failed;
    }

    /** Did a send response accept everything it was given? */
    private static function sendSucceeded(array $data): bool {
        $succeeded = (int)($data['succeeded'] ?? 0);
        $failures = (int)($data['failed'] ?? 0);
        if ($failures > 0) {
            error_log('[Smtp2GoProvider] SMTP2GO refused ' . $failures . ' recipient(s): '
                . json_encode($data['failures'] ?? array()));
        }
        return $succeeded > 0 && $failures === 0;
    }

    /**
     * The message body, sender, subject, headers and files — everything but
     * the recipients, which send() and sendBatch() fill in differently.
     */
    private function buildPayload(EmailMessage $message): array {
        $from = $message->getFromName()
            ? $message->getFromName() . ' <' . $message->getFrom() . '>'
            : (string)$message->getFrom();

        $payload = array(
            'sender'  => $from,
            'subject' => (string)$message->getSubject(),
        );
        if ($message->getHtmlBody()) {
            $payload['html_body'] = $message->getHtmlBody();
        }
        if ($message->getTextBody()) {
            $payload['text_body'] = $message->getTextBody();
        }

        // Reply-To, the pinned Message-Id and anything a threaded reply set
        // all travel as custom headers — SMTP2GO has no dedicated field for
        // them, and dropping them would break mail threading.
        $headers = array();
        if ($message->getReplyTo()) {
            $headers[] = array('header' => 'Reply-To', 'value' => (string)$message->getReplyTo());
        }
        if ($message->getMessageId()) {
            $headers[] = array('header' => 'Message-Id', 'value' => (string)$message->getMessageId());
        }
        foreach ($message->getHeaders() as $name => $value) {
            $headers[] = array('header' => (string)$name, 'value' => (string)$value);
        }
        if (!empty($headers)) {
            $payload['custom_headers'] = $headers;
        }

        $attachments = array();
        $inlines = array();
        foreach ($message->getAttachments() as $attachment) {
            $type = (string)($attachment['type'] ?? 'application/octet-stream');
            if (!empty($attachment['path']) && is_readable($attachment['path'])) {
                $blob = file_get_contents($attachment['path']);
                $name = $attachment['name'] ?: basename($attachment['path']);
            } elseif (isset($attachment['data'])) {
                $blob = $attachment['data'];
                $name = $attachment['name'] ?: 'attachment';
            } else {
                continue;
            }
            if (!empty($attachment['cid'])) {
                // SMTP2GO references an inline part by its filename, so the
                // on-wire filename IS the cid the body points at (cid:<cid>).
                $inlines[] = array('filename' => (string)$attachment['cid'],
                    'fileblob' => base64_encode($blob), 'mimetype' => $type);
            } else {
                $attachments[] = array('filename' => $name,
                    'fileblob' => base64_encode($blob), 'mimetype' => $type);
            }
        }
        if (!empty($attachments)) { $payload['attachments'] = $attachments; }
        if (!empty($inlines)) { $payload['inlines'] = $inlines; }

        return $payload;
    }

    /** EmailMessage recipient arrays as SMTP2GO's "Name <email>" strings. */
    private static function formatAddresses(array $list): array {
        $out = array();
        foreach ($list as $entry) {
            if (empty($entry['email'])) { continue; }
            $name = trim((string)($entry['name'] ?? ''));
            $out[] = $name !== '' ? ($name . ' <' . $entry['email'] . '>') : (string)$entry['email'];
        }
        return $out;
    }

    // ── SendingDomainRegistrar ──────────────────────────────────────────

    /**
     * Add $domain as a sender domain. Idempotent: a domain already on the
     * account is reported ok without being re-added, because /domain/add on an
     * existing domain is an error and a re-registration failure would read to
     * the operator as though setup had gone wrong.
     */
    public static function createSendingDomain(string $domain): array {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') {
            return array('status' => 'error', 'error' => 'No sending domain to register.');
        }

        $state = self::getSendingDomainState($domain);
        if ($state === 'active' || $state === 'unverified') {
            return array('status' => 'ok');
        }
        if ($state === '') {
            return array('status' => 'unreachable', 'error' => 'SMTP2GO did not answer.');
        }

        try {
            $data = self::post('domain/add', array('domain' => $domain));
        } catch (Throwable $e) {
            error_log('[Smtp2GoProvider] createSendingDomain(' . $domain . ') failed: ' . $e->getMessage());
            return array('status' => 'error', 'error' => $e->getMessage());
        }
        self::forget($domain);
        // The add response already carries the domain's records, so a caller
        // that asks for them next reads a fresh answer rather than a cached
        // "not registered" from the check above.
        if (self::entryFor($data, $domain) !== null) {
            self::$domain_entry[$domain] = self::entryFor($data, $domain);
            self::$domain_state[$domain] = self::stateOf(self::$domain_entry[$domain]);
        }
        return array('status' => 'ok');
    }

    /**
     * Ask SMTP2GO to check the domain's DNS now and report the fresh state.
     * Without this a just-published record waits for the periodic check.
     */
    public static function verifySendingDomain(string $domain): string {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') { return ''; }
        try {
            $data = self::post('domain/verify', array('domain' => $domain));
        } catch (Throwable $e) {
            error_log('[Smtp2GoProvider] verifySendingDomain(' . $domain . ') failed: ' . $e->getMessage());
            self::forget($domain);
            return self::getSendingDomainState($domain);
        }
        $entry = self::entryFor($data, $domain);
        self::$domain_entry[$domain] = $entry;
        self::$domain_state[$domain] = self::stateOf($entry);
        return self::$domain_state[$domain];
    }

    /**
     * 'active' once SMTP2GO reports both the DKIM and the return-path record
     * resolving, 'unverified' while either is outstanding, 'not_registered'
     * when the account does not have the domain, and '' when the API did not
     * answer — which callers must render as unknown, never as a failure.
     */
    public static function getSendingDomainState(string $domain): string {
        $domain = self::normalizeDomain($domain);
        if ($domain === '') { return ''; }
        if (!array_key_exists($domain, self::$domain_state)) {
            $entry = null;
            $state = '';
            try {
                $entry = self::entryFor(self::post('domain/view', array('domain' => $domain)), $domain);
                $state = self::stateOf($entry);
            } catch (Throwable $e) {
                error_log('[Smtp2GoProvider] getSendingDomainState(' . $domain . ') failed: ' . $e->getMessage());
            }
            self::$domain_entry[$domain] = $entry;
            self::$domain_state[$domain] = $state;
        }
        return self::$domain_state[$domain];
    }

    // ── DkimRecordSource ────────────────────────────────────────────────

    /**
     * The records $domain must publish, read from SMTP2GO's own answer. All
     * three are CNAMEs and all are required for the domain to verify, so all
     * three are reported — a caller that published only the one with
     * _domainkey in its name would wait forever for a domain that never
     * verifies.
     */
    public static function getDkimStatus(string $domain): array {
        $domain = self::normalizeDomain($domain);
        $state = self::getSendingDomainState($domain);
        if ($state === '') {
            return array('status' => 'unreachable', 'records' => array());
        }
        if ($state === 'not_registered') {
            return array('status' => 'not_registered', 'records' => array());
        }
        return array('status' => 'ok',
            'records' => self::recordsOf(self::$domain_entry[$domain] ?? null));
    }

    // ── Reading SMTP2GO's domain answers ────────────────────────────────
    //
    // /domain/add, /domain/view and /domain/verify all answer in one shape,
    // and these three readers are the only place that shape is known. The
    // hosted-provisioning client (Smtp2GoClient) reads its own responses
    // through them, so the platform describes an SMTP2GO domain exactly once.

    /**
     * The entry for $domain in a domain response's data, or null when it is
     * not there. $data is the response's `data` object. An empty $domain takes
     * the first entry, which is what a single-domain answer holds.
     *
     * @return array{domain:array,trackers:array}|null
     */
    public static function entryFor(array $data, string $domain = ''): ?array {
        $domain = self::normalizeDomain($domain);
        $domains = $data['domains'] ?? null;
        if (!is_array($domains)) {
            return null;
        }
        // A single-entry answer is sometimes the object rather than a list.
        if (isset($domains['domain'])) {
            $domains = array($domains);
        }
        foreach ($domains as $entry) {
            if (!is_array($entry)) { continue; }
            $full = self::normalizeDomain((string)($entry['domain']['fulldomain'] ?? ''));
            if ($domain === '' || $full === $domain) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * The state a domain entry describes: 'active', 'unverified', or
     * 'not_registered' for a null entry (the account does not have it).
     *
     * Tracking is not part of the verdict. A tracking CNAME is optional and
     * off by default, and gating verification on it would hold a perfectly
     * good sending domain at 'unverified' for ever.
     */
    public static function stateOf(?array $entry): string {
        if ($entry === null) {
            return 'not_registered';
        }
        $domain = is_array($entry['domain'] ?? null) ? $entry['domain'] : array();
        return (!empty($domain['dkim_verified']) && !empty($domain['rpath_verified']))
            ? 'active' : 'unverified';
    }

    /**
     * The DNS records a domain entry calls for, each as
     * [type, name, value, priority, purpose, note] — the union of what the
     * DkimRecordSource contract reads (type/name/value/purpose) and what
     * DnsRecordPlan takes (priority/note), so one reader serves both.
     *
     * Every record is a CNAME:
     *   {dkim_selector}._domainkey.{domain} → dkim.smtp2go.net    signs the mail
     *   {rpath_selector}.{domain}           → return.smtp2go.net  return path, and
     *                                                             what SPF is checked against
     *   {tracker fulldomain}                → track.smtp2go.net   only when tracking is on
     *
     * A record missing either half is dropped rather than half-published: a
     * malformed record is worse than an absent one, because it looks done.
     */
    public static function recordsOf(?array $entry): array {
        if ($entry === null) {
            return array();
        }
        $domain = is_array($entry['domain'] ?? null) ? $entry['domain'] : array();
        $full = self::normalizeDomain((string)($domain['fulldomain'] ?? ''));
        $records = array();

        $selector = trim((string)($domain['dkim_selector'] ?? ''));
        $dkim = trim((string)($domain['dkim_value'] ?? ''));
        if ($full !== '' && $selector !== '' && $dkim !== '') {
            $records[] = self::record('CNAME', $selector . '._domainkey.' . $full, $dkim, 'DKIM',
                'DKIM — lets SMTP2GO sign mail as ' . $full . '.');
        }

        $rpath_selector = trim((string)($domain['rpath_selector'] ?? ''));
        $rpath = trim((string)($domain['rpath_value'] ?? ''));
        if ($full !== '' && $rpath_selector !== '' && $rpath !== '') {
            $records[] = self::record('CNAME', $rpath_selector . '.' . $full, $rpath, 'Return-Path',
                'Return path — where bounces go, and what SPF is checked against.');
        }

        foreach (self::asList($entry['trackers'] ?? array()) as $tracker) {
            if (!is_array($tracker) || empty($tracker['enabled'])) { continue; }
            $host = self::normalizeDomain((string)($tracker['fulldomain'] ?? ''));
            $target = trim((string)($tracker['cname_value'] ?? ''));
            if ($host !== '' && $target !== '') {
                $records[] = self::record('CNAME', $host, $target, 'Link tracking',
                    'Link tracking — rewrites clicked links through ' . $host . '.');
            }
        }

        return $records;
    }

    /** One record in the shape both consumers read. */
    private static function record(string $type, string $name, string $value,
            string $purpose, string $note): array {
        return array(
            'type'     => $type,
            'name'     => rtrim($name, '.'),
            'value'    => $value,
            'priority' => null,
            'purpose'  => $purpose,
            'note'     => $note,
        );
    }

    // ── Transport ───────────────────────────────────────────────────────

    /**
     * One API call, returning the envelope's `data`. The key travels in a
     * header rather than in the body, so it never lands in a log that records
     * payloads. A non-2xx, or a `data.error`, throws with SMTP2GO's own words.
     */
    private static function post(string $path, array $body): array {
        $key = trim((string)Globalvars::get_instance()->get_setting('smtp2go_api_key'));
        if ($key === '') {
            throw new RuntimeException('No SMTP2GO API key is configured.');
        }
        $http = new Client(array(
            'base_uri'        => self::API_BASE,
            'timeout'         => 30,
            'connect_timeout' => 10,
        ));
        try {
            $response = $http->request('POST', $path, array(
                'headers' => array(
                    'X-Smtp2go-Api-Key' => $key,
                    'Accept'            => 'application/json',
                ),
                'json' => $body,
            ));
        } catch (RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            throw new RuntimeException('SMTP2GO ' . $path . ' failed (' . $status . '): '
                . self::extractError($e), $status);
        }
        $decoded = json_decode((string)$response->getBody(), true);
        $data = (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']))
            ? $decoded['data'] : (is_array($decoded) ? $decoded : array());
        if (!empty($data['error'])) {
            throw new RuntimeException('SMTP2GO ' . $path . ': ' . (string)$data['error']);
        }
        return $data;
    }

    /** SMTP2GO's own words for a failed request, or the transport's. */
    private static function extractError(RequestException $e): string {
        if (!$e->getResponse()) {
            return $e->getMessage();
        }
        $decoded = json_decode((string)$e->getResponse()->getBody(), true);
        foreach (array(array('data', 'error'), array('error')) as $path) {
            $node = $decoded;
            foreach ($path as $key) {
                if (!is_array($node) || !isset($node[$key])) { $node = null; break; }
                $node = $node[$key];
            }
            if (is_string($node) && $node !== '') {
                return $node;
            }
        }
        return $e->getMessage();
    }

    /** A value as a plain list, so a scalar or an object never fans out wrongly. */
    private static function asList($value): array {
        return is_array($value) ? array_values($value) : array();
    }

    private static function normalizeDomain(string $domain): string {
        return strtolower(rtrim(trim($domain), '.'));
    }

    /** Drop a domain's cached answer, so the next read asks SMTP2GO again. */
    private static function forget(string $domain): void {
        unset(self::$domain_state[$domain], self::$domain_entry[$domain]);
    }
}
