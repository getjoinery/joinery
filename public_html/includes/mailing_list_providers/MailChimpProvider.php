<?php
/**
 * MailChimpProvider — MailChimp mailing list provider
 *
 * Implements MailingListProvider via AbstractMailingListProvider, using the
 * jhut89/mailchimp3php SDK.
 *
 * Provider-specific custom merge fields (e.g., MMERGE3) are configured via the
 * mailchimp_default_merge_fields setting (JSON) and applied on every subscribe()
 * call. See spec section "C3" for the rationale.
 */

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('includes/mailing_list_providers/AbstractMailingListProvider.php'));

use MailchimpAPI\Mailchimp;

class MailChimpProvider extends AbstractMailingListProvider {

    public static function getKey(): string {
        return 'mailchimp';
    }

    public static function getLabel(): string {
        return 'MailChimp';
    }

    public static function getSettingsFields(): array {
        return [
            [
                'key' => 'mailchimp_api_key',
                'label' => 'MailChimp API Key',
                'type' => 'text',
                'helptext' => 'Your MailChimp API key (e.g., abcd1234efgh5678-us21)',
            ],
            [
                'key' => 'mailchimp_default_merge_fields',
                'label' => 'Default Merge Fields (JSON)',
                'type' => 'textarea',
                'helptext' => 'JSON object of MailChimp merge tags applied to every subscribe call. Example: {"MMERGE3":"Yes"}. Leave empty or {} for none.',
            ],
        ];
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
            $mailchimp = new Mailchimp($api_key);
            $lists_response = $mailchimp->lists()->get(['count' => 10]);
            $lists_data = $lists_response->deserialize();

            if (!isset($lists_data->lists)) {
                return [
                    'success' => false,
                    'label' => 'Invalid API Response',
                    'details' => [],
                    'error' => 'API key may be invalid or expired',
                ];
            }

            $details = [];
            foreach ($lists_data->lists as $list) {
                $details[$list->name] = ($list->stats->member_count ?? 0) . ' members';
            }

            $count = isset($lists_data->total_items) ? $lists_data->total_items : count($lists_data->lists);
            return [
                'success' => true,
                'label' => 'Connected — ' . $count . ' list' . ($count == 1 ? '' : 's') . ' found',
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

        try {
            $mailchimp = new Mailchimp($api_key);
            // PUT is idempotent — creates or updates by subscriber hash
            $subscriber_hash = md5($email);
            $return = $mailchimp
                ->lists($remote_list_id)
                ->members($subscriber_hash)
                ->put($post_params);

            $status = $return->deserialize();
            if (!isset($status->id)) {
                throw new MailingListProviderException(
                    'MailChimp subscribe returned unexpected response', false);
            }
            return $status->id;
        } catch (MailingListProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw self::translateException($e, 'subscribe');
        }
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

        try {
            $mailchimp = new Mailchimp($api_key);
            $subscriber_hash = md5($email);
            $mailchimp
                ->lists($remote_list_id)
                ->members($subscriber_hash)
                ->patch(['status' => 'unsubscribed']);
            return true;
        } catch (\Exception $e) {
            throw self::translateException($e, 'unsubscribe');
        }
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

        try {
            $mailchimp = new Mailchimp($api_key);
            $return = $mailchimp
                ->lists($remote_list_id)
                ->members()
                ->get([
                    'count' => $limit,
                    'offset' => $offset,
                ]);
            $results = $return->deserialize();

            $subscribers = [];
            $members = isset($results->members) ? $results->members : [];
            foreach ($members as $m) {
                $subscribers[] = [
                    'email' => $m->email_address,
                    'status' => self::mapStatus($m->status ?? ''),
                    'last_changed' => $m->last_changed ?? null,
                ];
            }

            // If we got fewer than requested, iteration is done
            $next_cursor = (count($members) < $limit) ? null : (string)($offset + $limit);

            return [
                'subscribers' => $subscribers,
                'next_cursor' => $next_cursor,
            ];
        } catch (\Exception $e) {
            throw self::translateException($e, 'getSubscribers');
        }
    }

    public function getLists(): array {
        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('mailchimp_api_key');
        if (empty($api_key)) {
            throw new MailingListProviderException(
                'MailChimp API key is not configured', false);
        }

        try {
            $mailchimp = new Mailchimp($api_key);
            $response = $mailchimp->lists()->get(['count' => 1000]);
            $data = $response->deserialize();

            $lists = [];
            $raw_lists = isset($data->lists) ? $data->lists : [];
            foreach ($raw_lists as $l) {
                $lists[] = [
                    'id' => $l->id,
                    'name' => $l->name,
                    'member_count' => $l->stats->member_count ?? 0,
                ];
            }
            return $lists;
        } catch (\Exception $e) {
            throw self::translateException($e, 'getLists');
        }
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
     * Translate an SDK / network exception into a MailingListProviderException
     * with isRetryable() set appropriately.
     */
    private static function translateException(\Exception $e, string $op): MailingListProviderException {
        $message = $e->getMessage();
        $code = $e->getCode();

        // HTTP status codes are sometimes embedded in the exception code or message.
        $is_retryable = false;

        if ($code === 429 || $code >= 500) {
            $is_retryable = true;
        } else if (preg_match('/\b(429|5\d\d)\b/', $message)) {
            $is_retryable = true;
        } else if (stripos($message, 'rate limit') !== false
                || stripos($message, 'timeout') !== false
                || stripos($message, 'timed out') !== false
                || stripos($message, 'could not resolve') !== false
                || stripos($message, 'connection') !== false) {
            $is_retryable = true;
        }

        return new MailingListProviderException(
            "MailChimp $op failed: $message",
            $is_retryable,
            $e
        );
    }
}
