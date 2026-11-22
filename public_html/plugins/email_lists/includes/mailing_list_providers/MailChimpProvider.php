<?php
/**
 * MailChimpProvider — MailChimp mailing list provider
 *
 * Implements MailingListProvider via AbstractMailingListProvider, talking to
 * the Marketing API 3.0 directly through ProviderHttp.
 *
 * Every call checks the HTTP status. An unsubscribe answered 404 is a success
 * (the address was never on the list); any other failure throws, so a sync
 * never reads a refused or failed call as "the list is empty", and a 429 or
 * 5xx reaches translateException() as a retryable failure.
 *
 * Provider-specific custom merge fields (e.g., MMERGE3) are configured via the
 * mailchimp_default_merge_fields setting (JSON) and applied on every subscribe()
 * call. See spec section "C3" for the rationale.
 *
 * @version 1.2 - the data-center check ends at \z, so a key ending in a newline is refused (review B17)
 * @version 1.1 - talks to the API directly (ProviderHttp), no SDK; every status is checked:
 *   unsubscribe() is true on 2xx and 404 and throws otherwise, getLists() and getSubscribers()
 *   throw on a failure instead of answering empty, and subscribe() failures carry the real
 *   status so a 429 or 5xx is retryable (specs/email_provider_sdk_removal.md WP7, B5)
 * @version 1.0
 */

class MailChimpProvider extends AbstractMailingListProvider {

    public static function getKey(): string {
        return 'mailchimp';
    }

    public static function getLabel(): string {
        return 'MailChimp';
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        if (empty($settings->get_setting('mailchimp_api_key'))) {
            $errors[] = 'MailChimp API key not configured';
        }

        $merge_fields_raw = $settings->get_setting('mailchimp_default_merge_fields');
        if (!empty($merge_fields_raw)) {
            $decoded = json_decode($merge_fields_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $errors[] = 'mailchimp_default_merge_fields is not valid JSON';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('mailchimp_api_key');

        if (empty($api_key)) {
            return [
                'success' => false,
                'label' => 'Not Configured',
                'details' => [],
                'error' => 'Enter API key to validate connection',
            ];
        }

        try {
            $resp = self::call($api_key, 'GET', '/lists', ['count' => 10]);
        } catch (MailingListProviderException $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => $e->getMessage(),
            ];
        }
        if ($resp->status === 401 || $resp->status === 403) {
            return [
                'success' => false,
                'label' => 'API Key Rejected',
                'details' => [],
                'error' => 'MailChimp rejected the API key.',
            ];
        }
        if (!$resp->ok()) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => [],
                'error' => self::describeFailure($resp),
            ];
        }
        $lists_data = $resp->json();
        if (!is_array($lists_data) || !isset($lists_data['lists']) || !is_array($lists_data['lists'])) {
            return [
                'success' => false,
                'label' => 'Invalid API Response',
                'details' => [],
                'error' => 'API key may be invalid or expired',
            ];
        }

        $details = [];
        foreach ($lists_data['lists'] as $list) {
            $details[(string)($list['name'] ?? '')] = ($list['stats']['member_count'] ?? 0) . ' members';
        }

        $count = isset($lists_data['total_items']) ? (int)$lists_data['total_items'] : count($lists_data['lists']);
        return [
            'success' => true,
            'label' => 'Connected — ' . $count . ' list' . ($count == 1 ? '' : 's') . ' found',
            'details' => $details,
            'error' => null,
        ];
    }

    public function subscribe(string $remote_list_id, string $email,
                              string $first_name, string $last_name): string {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address: $email");
        }

        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('mailchimp_api_key');
        if (empty($api_key)) {
            throw new MailingListProviderException(
                'MailChimp API key is not configured', false);
        }

        $merge_fields = [
            'FNAME' => $first_name,
            'LNAME' => $last_name,
        ];
        $defaults = $this->loadDefaultMergeFields();
        $merge_fields = array_merge($merge_fields, $defaults);

        $post_params = [
            'email_address' => $email,
            'status' => 'subscribed',
            'email_type' => 'html',
            'merge_fields' => $merge_fields,
        ];

        // PUT is idempotent — creates or updates by subscriber hash
        $resp = self::call($api_key, 'PUT', self::memberPath($remote_list_id, $email), [], $post_params);
        if (!$resp->ok()) {
            throw self::statusException($resp, 'subscribe');
        }
        $status = $resp->json();
        if (!is_array($status) || empty($status['id'])) {
            throw new MailingListProviderException(
                'MailChimp subscribe returned unexpected response', false);
        }
        return (string)$status['id'];
    }

    public function unsubscribe(string $remote_list_id, string $email): bool {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address: $email");
        }

        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('mailchimp_api_key');
        if (empty($api_key)) {
            throw new MailingListProviderException(
                'MailChimp API key is not configured', false);
        }

        $resp = self::call($api_key, 'PATCH', self::memberPath($remote_list_id, $email), [],
            ['status' => 'unsubscribed']);
        // 404: the address was never on the list, which is already unsubscribed.
        if ($resp->ok() || $resp->status === 404) {
            return true;
        }
        throw self::statusException($resp, 'unsubscribe');
    }

    public function getSubscribers(string $remote_list_id, ?string $cursor = null,
                                   int $limit = 1000): array {
        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('mailchimp_api_key');
        if (empty($api_key)) {
            throw new MailingListProviderException(
                'MailChimp API key is not configured', false);
        }

        $offset = 0;
        if ($cursor !== null && $cursor !== '') {
            if (!ctype_digit($cursor)) {
                throw new \InvalidArgumentException("Invalid cursor: $cursor");
            }
            $offset = (int)$cursor;
        }

        $resp = self::call($api_key, 'GET', '/lists/' . rawurlencode($remote_list_id) . '/members', [
            'count' => $limit,
            'offset' => $offset,
        ]);
        if (!$resp->ok()) {
            throw self::statusException($resp, 'getSubscribers');
        }
        $results = $resp->json() ?? [];

        $subscribers = [];
        $members = (isset($results['members']) && is_array($results['members'])) ? $results['members'] : [];
        foreach ($members as $m) {
            $subscribers[] = [
                'email' => (string)($m['email_address'] ?? ''),
                'status' => self::mapStatus((string)($m['status'] ?? '')),
                'last_changed' => $m['last_changed'] ?? null,
            ];
        }

        // If we got fewer than requested, iteration is done
        $next_cursor = (count($members) < $limit) ? null : (string)($offset + $limit);

        return [
            'subscribers' => $subscribers,
            'next_cursor' => $next_cursor,
        ];
    }

    public function getLists(): array {
        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('mailchimp_api_key');
        if (empty($api_key)) {
            throw new MailingListProviderException(
                'MailChimp API key is not configured', false);
        }

        $resp = self::call($api_key, 'GET', '/lists', ['count' => 1000]);
        if (!$resp->ok()) {
            throw self::statusException($resp, 'getLists');
        }
        $data = $resp->json() ?? [];

        $lists = [];
        $raw_lists = (isset($data['lists']) && is_array($data['lists'])) ? $data['lists'] : [];
        foreach ($raw_lists as $l) {
            $lists[] = [
                'id' => (string)($l['id'] ?? ''),
                'name' => (string)($l['name'] ?? ''),
                'member_count' => $l['stats']['member_count'] ?? 0,
            ];
        }
        return $lists;
    }

    /**
     * Read mailchimp_default_merge_fields setting and decode as array.
     * Returns [] if unset, empty, or invalid JSON.
     */
    private function loadDefaultMergeFields(): array {
        $settings = Globalvars::get_instance();
        $raw = $settings->get_setting('mailchimp_default_merge_fields');
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    /**
     * Map MailChimp's native status vocabulary onto the canonical four values.
     *
     * MailChimp → canonical
     *   subscribed     → subscribed
     *   pending        → pending
     *   unsubscribed   → unsubscribed
     *   cleaned        → bounced
     *   transactional  → unsubscribed (rare; not actually receiving list mail)
     *   archived       → unsubscribed
     */
    private static function mapStatus(string $native): string {
        switch ($native) {
            case 'subscribed':   return 'subscribed';
            case 'pending':      return 'pending';
            case 'cleaned':      return 'bounced';
            case 'unsubscribed':
            case 'transactional':
            case 'archived':
            default:
                return 'unsubscribed';
        }
    }

    /**
     * The API base for a key. The data center is the key's second
     * dash-separated piece (abc123-us6 is us6), as Mailchimp issues them.
     *
     * @throws MailingListProviderException for a key with no data center
     */
    public static function apiBase(string $api_key): string {
        $parts = explode('-', trim($api_key));
        $dc = $parts[1] ?? '';
        if (!preg_match('/^[a-z]+\d+\z/', $dc)) {   // \z: $ would let a trailing newline through
            throw new MailingListProviderException(
                'The MailChimp API key has no data center at its end (it looks like abc123-us6).', false);
        }
        return 'https://' . $dc . '.api.mailchimp.com/3.0';
    }

    /**
     * One API call. A transport failure (no answer at all) throws through
     * translateException(); any HTTP status comes back for the caller to read.
     */
    private static function call(string $api_key, string $method, string $path, array $query = [],
                                 ?array $json = null): ProviderHttpResponse {
        $options = [
            'headers' => ['Authorization' => 'apikey ' . trim($api_key)],
        ];
        if (!empty($query)) {
            $options['query'] = $query;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }
        $url = self::apiBase($api_key) . $path;
        try {
            return ProviderHttp::request($method, $url, $options);
        } catch (ProviderHttpException $e) {
            // The exception's code is a curl error number, not a status.
            throw self::translateException(new \RuntimeException($e->getMessage(), 0, $e), $method . ' ' . $path);
        }
    }

    private static function memberPath(string $remote_list_id, string $email): string {
        return '/lists/' . rawurlencode($remote_list_id) . '/members/' . md5(strtolower(trim($email)));
    }

    /** "MailChimp answered 400: …", with Mailchimp's own detail when it gave one. */
    private static function describeFailure(ProviderHttpResponse $resp): string {
        $body = $resp->json() ?? [];
        $words = trim((string)($body['detail'] ?? ($body['title'] ?? '')));
        return 'MailChimp answered ' . $resp->status . ($words !== '' ? ': ' . $words : '');
    }

    /** A failed status as the exception translateException() reads, the status as its code. */
    private static function statusException(ProviderHttpResponse $resp, string $op): MailingListProviderException {
        return self::translateException(new \RuntimeException(self::describeFailure($resp), $resp->status), $op);
    }

    /**
     * Translate a failed call into a MailingListProviderException with
     * isRetryable() set: a 429 or 5xx status, or a transport failure that
     * names a timeout or a connection, is worth retrying.
     */
    private static function translateException(\Exception $e, string $op): MailingListProviderException {
        $message = $e->getMessage();
        $code = (int)$e->getCode();

        $is_retryable = false;

        if ($code === 429 || $code >= 500) {
            $is_retryable = true;
        } else if ($code === 0 && (stripos($message, 'rate limit') !== false
                || stripos($message, 'timeout') !== false
                || stripos($message, 'timed out') !== false
                || stripos($message, 'resolve') !== false
                || stripos($message, 'connect') !== false
                || stripos($message, 'SSL') !== false)) {
            $is_retryable = true;
        }

        return new MailingListProviderException(
            "MailChimp $op failed: $message",
            $is_retryable,
            $e,
            $code
        );
    }
}
