<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('billing_logic.php', 'logic'));

$page_vars = process_logic(billing_logic(array_merge($_GET, $_POST, $params ?? [])));

$user                = $page_vars['user'];
$settings            = $page_vars['settings'];
$current_subscription = $page_vars['current_subscription'];
$payment_system      = $page_vars['payment_system'];
$payment_method      = $page_vars['payment_method'];
$current_product     = $page_vars['current_product'];
$current_version     = $page_vars['current_version'];
$alternative_versions = $page_vars['alternative_versions'];
$show_cycle_switcher = $page_vars['show_cycle_switcher'];
$invoices            = $page_vars['invoices'];
$session             = SessionControl::get_instance();

$page = new PublicPage();
$page->public_header([
    'title'         => 'Billing & Payment',
    'is_valid_page' => $is_valid_page ?? false,
]);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">
        <div class="jy-narrow">

            <div class="jy-page-header">
                <div class="jy-page-header-bar">
                    <h1>Billing &amp; Payment</h1>
                    <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                        <ol>
                            <li><a href="/">Home</a></li>
                            <li><a href="/profile">My Profile</a></li>
                            <li class="active">Billing</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php
            if (!empty($page_vars['display_messages'])) {
                foreach ($page_vars['display_messages'] as $msg) {
                    echo PublicPage::alert($msg->message_title, $msg->message, $msg->get_message_class());
                }
            }
            ?>

            <?php if (!empty($page_vars['success_message'])): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($page_vars['success_message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($page_vars['error_message'])): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($page_vars['error_message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <!-- Payment Method -->
            <?php if ($payment_system === 'stripe' && $page_vars['stripe_customer_id']): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="jy-tight">Payment Method</h5>
                </div>
                <div class="card-body">
                    <?php if ($payment_method): ?>
                    <div class="jy-billing-row">
                        <div class="jy-billing-flex">
                            <span class="badge badge-muted jy-billing-cardbrand">
                                <?php echo htmlspecialchars($payment_method['brand'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <div>
                                <div class="jy-billing-bold">&bull;&bull;&bull;&bull; <?php echo htmlspecialchars($payment_method['last4'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="muted text-sm">Expires <?php echo $payment_method['exp_month']; ?>/<?php echo $payment_method['exp_year']; ?></div>
                            </div>
                        </div>
                        <form method="POST" action="/profile/billing">
                            <input type="hidden" name="action" value="update_payment_method">
                            <button type="submit" class="btn btn-outline btn-sm">Update Payment Method</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="jy-billing-row">
                        <span class="muted">No payment method on file.</span>
                        <form method="POST" action="/profile/billing">
                            <input type="hidden" name="action" value="update_payment_method">
                            <button type="submit" class="btn btn-primary btn-sm">Add Payment Method</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($payment_system === 'paypal'): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="jy-tight">Payment Method</h5>
                </div>
                <div class="card-body">
                    <div class="jy-billing-flex">
                        <span class="badge badge-warning jy-billing-paypal">PayPal</span>
                        <div class="muted">
                            Your subscription is managed through PayPal. To update your payment method, visit
                            <a href="https://www.paypal.com/myaccount/autopay/" target="_blank" rel="noopener">PayPal</a>.
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Billing Cycle Switcher -->
            <?php if ($show_cycle_switcher): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="jy-tight">Billing Cycle</h5>
                </div>
                <div class="card-body">
                    <div class="jy-mb-4">
                        <span class="jy-billing-bold">Current:</span>
                        <?php echo htmlspecialchars($current_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?>
                        &mdash; $<?php echo number_format($current_version->get('prv_version_price'), 2); ?>/<?php echo htmlspecialchars($current_version->get('prv_price_type'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <?php foreach ($alternative_versions as $alt): ?>
                    <?php
                        $current_price = floatval($current_version->get('prv_version_price'));
                        $current_type = $current_version->get('prv_price_type');
                        $alt_price = floatval($alt->get('prv_version_price'));
                        $alt_type = $alt->get('prv_price_type');

                        $savings_text = '';
                        $multipliers = array('day' => 365, 'week' => 52, 'month' => 12, 'year' => 1);
                        if (isset($multipliers[$current_type]) && isset($multipliers[$alt_type])) {
                            $current_annual = $current_price * $multipliers[$current_type];
                            $alt_annual = $alt_price * $multipliers[$alt_type];
                            if ($alt_annual < $current_annual) {
                                $pct = round((1 - $alt_annual / $current_annual) * 100);
                                $savings_text = "Save {$pct}%";
                            }
                        }
                    ?>
                    <div class="jy-billing-cycle-opt">
                        <div>
                            <strong><?php echo htmlspecialchars($alt->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            &mdash; $<?php echo number_format($alt_price, 2); ?>/<?php echo htmlspecialchars($alt_type, ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($savings_text): ?>
                            <span class="jy-billing-savings"><?php echo $savings_text; ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="POST" action="/profile/billing" onsubmit="return confirm('Switch to <?php echo htmlspecialchars($alt->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?> billing? Your subscription will be updated and prorated.');">
                            <input type="hidden" name="action" value="change_billing_cycle">
                            <input type="hidden" name="new_version_id" value="<?php echo $alt->key; ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Switch</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php elseif ($payment_system === 'paypal' && $current_subscription && !$current_subscription->get('odi_subscription_cancelled_time')): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="jy-tight">Billing Cycle</h5>
                </div>
                <div class="card-body muted">
                    To change your billing cycle, please cancel your current subscription and re-subscribe with the new billing option on the <a href="/profile/change-tier">subscription management page</a>.
                </div>
            </div>
            <?php endif; ?>

            <!-- Billing History -->
            <?php if (!empty($invoices)): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="jy-tight">Billing History</h5>
                </div>
                <div class="table-wrapper">
                    <table class="styled-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td class="jy-nowrap"><?php echo htmlspecialchars($invoice['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($invoice['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="jy-billing-amt">$<?php echo htmlspecialchars($invoice['amount'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center">
                                    <?php
                                    $status_class = 'badge-muted';
                                    if ($invoice['status'] === 'paid') $status_class = 'badge-success';
                                    elseif ($invoice['status'] === 'open') $status_class = 'badge-warning';
                                    else $status_class = 'badge-error';
                                    ?>
                                    <span class="badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars(ucfirst($invoice['status']), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($invoice['pdf_url']): ?>
                                    <a href="<?php echo htmlspecialchars($invoice['pdf_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="text-sm" title="Download PDF">PDF</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- No billing data -->
            <?php if (!$current_subscription && empty($invoices)): ?>
            <div class="jy-panel jy-billing-empty">
                <div class="jy-billing-empty-icon">&#128179;</div>
                <h4 class="jy-mb-2">No billing information</h4>
                <p class="muted jy-mb-5">You don't have any active subscriptions or past purchases.</p>
                <a href="/products" class="btn btn-primary">Browse Products</a>
            </div>
            <?php endif; ?>

            <!-- Navigation -->
            <div class="jy-billing-nav">
                <a href="/profile" class="muted">&larr; Back to Profile</a>
                <?php if ($current_subscription): ?>
                <a href="/profile/change-tier">Manage Subscription Plan</a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>
</div>
<?php
$page->public_footer(['track' => true]);
?>
