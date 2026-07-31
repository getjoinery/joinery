<?php
/**
 * ReconcileStripeSubscriptions
 *
 * Periodic backstop that reconciles every active Stripe subscription order item
 * against Stripe. Stripe webhooks are the authoritative real-time path; this
 * task catches anything a webhook missed.
 *
 * Efficiency: instead of one Stripe API request per order item (the old inline
 * per-user maintenance behavior), it pages Stripe's subscription list endpoint
 * (up to 100 per request), keeps only the subscriptions matching the local
 * working set, and applies each via StripeHelper::apply_subscription_to_order_item()
 * — no per-item round-trip. One EventLog summary row is written per run.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class ReconcileStripeSubscriptions implements ScheduledTaskInterface, ScheduledTaskDryRunnable {

    const EVENT_NAME = 'stripe_subscription_reconciliation';
    const PAGE_SIZE = 100;

    public function run(array $config) {
        require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
        require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
        require_once(PathHelper::getIncludePath('data/event_logs_class.php'));
        require_once(PathHelper::getIncludePath('data/users_class.php'));

        $stripe = new StripeHelper();
        if (!$stripe->is_initialized()) {
            return array('status' => 'skipped', 'message' => 'Stripe is not configured on this site');
        }

        // Local working set: paid, not-yet-cancelled subscription order items.
        $wanted = $this->load_wanted_items();
        if (empty($wanted)) {
            return array('status' => 'skipped', 'message' => 'No active Stripe subscriptions to reconcile');
        }

        // Bulk-fetch the matching subscriptions from Stripe in pages.
        try {
            list($subs, $api_calls) = $this->fetch_subscription_map($stripe, array_keys($wanted));
        } catch (Exception $e) {
            error_log('ReconcileStripeSubscriptions: Stripe list failed: ' . $e->getMessage());
            return array('status' => 'error', 'message' => 'Stripe list failed: ' . $e->getMessage());
        }

        $processed = 0;
        $changed = 0;
        $missing = 0;
        $errors = 0;

        foreach ($wanted as $sub_id => $order_item) {
            if (!isset($subs[$sub_id])) {
                // In our local set but not returned by Stripe (different account/mode,
                // or the subscription was deleted). Not a task failure — the old
                // per-user code failed silently here; we surface it as a logged count.
                error_log('ReconcileStripeSubscriptions: subscription ' . $sub_id . ' not found at Stripe (order item ' . $order_item->key . ')');
                $missing++;
                continue;
            }

            $before = $this->snapshot($order_item);
            try {
                $stripe->apply_subscription_to_order_item($order_item, $subs[$sub_id]);
                $processed++;
                if ($this->snapshot($order_item) !== $before) {
                    $changed++;
                }
            } catch (Exception $e) {
                error_log('ReconcileStripeSubscriptions: error applying ' . $sub_id . ': ' . $e->getMessage());
                $errors++;
            }
        }

        $message = "Reconciled $processed subscription(s) in $api_calls Stripe call(s), "
                 . "$changed changed, $missing missing, $errors error(s)";
        // A completed sweep is a success even if some local rows reference
        // subscriptions Stripe no longer has; only per-item apply failures count
        // against it.
        $this->write_event_log($errors === 0, $message);

        return array(
            'status' => ($errors > 0) ? 'error' : 'success',
            'message' => $message,
        );
    }

    public function dryRun(array $config) {
        require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
        require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));

        $stripe = new StripeHelper();
        if (!$stripe->is_initialized()) {
            return array('status' => 'skipped', 'message' => 'Stripe is not configured on this site');
        }

        $wanted = $this->load_wanted_items();
        if (empty($wanted)) {
            return array('status' => 'skipped', 'message' => 'No active Stripe subscriptions to reconcile');
        }

        try {
            list($subs, $api_calls) = $this->fetch_subscription_map($stripe, array_keys($wanted));
        } catch (Exception $e) {
            return array('status' => 'error', 'message' => 'Stripe list failed: ' . $e->getMessage());
        }

        $diffs = array();
        $missing = 0;
        foreach ($wanted as $sub_id => $order_item) {
            if (!isset($subs[$sub_id])) {
                $missing++;
                continue;
            }
            $sub = $subs[$sub_id];
            $new_status = $sub['status'];
            $cur_status = $order_item->get('odi_subscription_status');
            $newly_cancelled = $sub['canceled_at'] && !$order_item->get('odi_subscription_cancelled_time');
            if ($new_status !== $cur_status || $newly_cancelled) {
                $diffs[] = array(
                    'order_item_id' => $order_item->key,
                    'subscription'  => $sub_id,
                    'from'          => $cur_status,
                    'to'            => $new_status . ($newly_cancelled ? ' (cancellation recorded)' : ''),
                );
            }
        }

        $count = count($wanted);
        $message = "Would reconcile $count subscription(s) in $api_calls Stripe call(s); "
                 . count($diffs) . ' would change, ' . $missing . ' missing';

        return array(
            'status'  => 'success',
            'message' => $message,
            'html'    => $this->render_preview($diffs),
        );
    }

    /**
     * Build the local working set: a map of Stripe subscription id => OrderItem
     * for every paid, not-yet-cancelled subscription order item. Items without a
     * Stripe subscription id are skipped (PayPal subscriptions, etc.).
     *
     * @return array<string,OrderItem>
     */
    private function load_wanted_items() {
        $items = new MultiOrderItem(array('is_active_subscription' => true));
        $items->load();

        $wanted = array();
        foreach ($items as $order_item) {
            $sub_id = $order_item->get('odi_stripe_subscription_id');
            if ($sub_id) {
                $wanted[$sub_id] = $order_item;
            }
        }
        return $wanted;
    }

    /**
     * Page Stripe's subscription list endpoint (status=all so cancellations are
     * visible) and return only the subscriptions whose ids are in $wanted_ids,
     * keyed by id. Stops early once every wanted id has been found. Returns
     * [map, api_call_count].
     *
     * @param StripeHelper $stripe
     * @param string[] $wanted_ids
     * @return array{0: array<string,mixed>, 1: int}
     */
    private function fetch_subscription_map(StripeHelper $stripe, array $wanted_ids) {
        $wanted_lookup = array_flip($wanted_ids);
        $found = array();
        $api_calls = 0;
        $starting_after = null;

        do {
            $params = array('status' => 'all', 'limit' => self::PAGE_SIZE);
            if ($starting_after !== null) {
                $params['starting_after'] = $starting_after;
            }

            $page = $stripe->get_subscriptions($params);
            $api_calls++;

            $last_id = null;
            foreach ($page->data as $sub) {
                $last_id = $sub->id;
                if (isset($wanted_lookup[$sub->id])) {
                    $found[$sub->id] = $sub;
                }
            }

            // Stop as soon as we've located every subscription we care about.
            if (count($found) >= count($wanted_ids)) {
                break;
            }

            $has_more = $page->has_more && $last_id !== null;
            $starting_after = $last_id;
        } while ($has_more);

        return array($found, $api_calls);
    }

    /**
     * Snapshot the local subscription fields an apply could change, for
     * before/after comparison.
     */
    private function snapshot($order_item) {
        return array(
            $order_item->get('odi_subscription_status'),
            $order_item->get('odi_subscription_period_end'),
            $order_item->get('odi_subscription_cancelled_time'),
        );
    }

    private function write_event_log($success, $note) {
        $event_log = new EventLog(NULL);
        $event_log->set('evl_event', self::EVENT_NAME);
        $event_log->set('evl_usr_user_id', User::USER_SYSTEM);
        $event_log->set('evl_was_success', $success ? 1 : 0);
        $event_log->set('evl_note', substr($note, 0, 255));
        $event_log->save();
    }

    private function render_preview(array $diffs) {
        if (empty($diffs)) {
            return '<p>No subscription changes would be applied.</p>';
        }
        $html = '<table class="data-table"><thead><tr>'
              . '<th>Order Item</th><th>Subscription</th><th>From</th><th>To</th>'
              . '</tr></thead><tbody>';
        foreach ($diffs as $d) {
            $html .= '<tr>'
                   . '<td>' . htmlspecialchars($d['order_item_id']) . '</td>'
                   . '<td>' . htmlspecialchars($d['subscription']) . '</td>'
                   . '<td>' . htmlspecialchars((string)$d['from']) . '</td>'
                   . '<td>' . htmlspecialchars((string)$d['to']) . '</td>'
                   . '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }
}
