<?php
/**
 * SyncPaypalSubscriptions
 *
 * Safety net: checks PayPal subscription status for all active PayPal subscriptions.
 * Catches cancellations or status changes that webhooks may have missed.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class SyncPaypalSubscriptions implements ScheduledTaskInterface {

    public function run(array $config) {
        require_once(PathHelper::getIncludePath('includes/PaypalHelper.php'));
        require_once(PathHelper::getIncludePath('data/order_items_class.php'));

        $synced = 0;
        $changed = 0;
        $errors = 0;

        // Find all order items with a PayPal subscription ID that are still considered active
        $dbconnector = DbConnector::get_instance();
        $dblink = $dbconnector->get_db_link();

        $sql = "SELECT odi_order_item_id, odi_paypal_subscription_id, odi_subscription_status
                FROM odi_order_items
                WHERE odi_paypal_subscription_id IS NOT NULL
                AND odi_paypal_subscription_id != ''
                AND odi_is_subscription = true
                AND (odi_subscription_status IS NULL OR odi_subscription_status IN ('active', 'past_due'))";

        $q = $dblink->prepare($sql);
        $q->execute();
        $items = $q->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return array('status' => 'skipped', 'message' => 'No active PayPal subscriptions to sync');
        }

        try {
            $paypal = new PaypalHelper();
        } catch (Exception $e) {
            return array('status' => 'error', 'message' => 'PayPal not configured: ' . $e->getMessage());
        }

        foreach ($items as $item) {
            $synced++;

            try {
                $details = $paypal->subDetails($item['odi_paypal_subscription_id']);

                if (!$details || isset($details['name']) && $details['name'] === 'RESOURCE_NOT_FOUND') {
                    error_log("SyncPaypalSubscriptions: subscription {$item['odi_paypal_subscription_id']} not found at PayPal");
                    $errors++;
                    continue;
                }

                $paypal_status = strtolower($details['status'] ?? '');
                $current_status = $item['odi_subscription_status'];

                // Map PayPal status to our status
                $status_map = array(
                    'active' => 'active',
                    'approved' => 'active',
                    'cancelled' => 'canceled',
                    'suspended' => 'suspended',
                    'expired' => 'expired',
                );

                $new_status = $status_map[$paypal_status] ?? $paypal_status;

                if ($new_status !== $current_status) {
                    $order_item = new OrderItem($item['odi_order_item_id'], TRUE);
                    $order_item->set('odi_subscription_status', $new_status);

                    if (in_array($paypal_status, ['cancelled', 'expired'])) {
                        if (!$order_item->get('odi_subscription_cancelled_time')) {
                            $order_item->set('odi_subscription_cancelled_time', gmdate('Y-m-d H:i:s'));
                        }
                    }

                    // Update period end if available
                    if (isset($details['billing_info']['next_billing_time'])) {
                        $order_item->set('odi_subscription_period_end',
                            gmdate('Y-m-d H:i:s', strtotime($details['billing_info']['next_billing_time'])));
                    }

                    $order_item->save();
                    $changed++;

                    error_log("SyncPaypalSubscriptions: subscription {$item['odi_paypal_subscription_id']} status changed from $current_status to $new_status");
                }
            } catch (Exception $e) {
                error_log("SyncPaypalSubscriptions: error syncing {$item['odi_paypal_subscription_id']}: " . $e->getMessage());
                $errors++;
            }
        }

        return array(
            'status' => ($errors > 0) ? 'error' : 'success',
            'message' => "Synced $synced subscription(s), $changed changed, $errors error(s)"
        );
    }
}
