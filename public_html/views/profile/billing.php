<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('MemberPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('billing_logic.php', 'logic'));

$page_vars = process_logic(billing_logic($_GET, $_POST));

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

$page = new MemberPage();
$page->member_header([
    'title'         => 'Billing & Payment',
    'is_valid_page' => $is_valid_page,
]);
?>

<!-- Breadcrumb -->
<section class="page-title bg-transparent">
    <div class="container">
        <div class="page-title-row">
            <div class="page-title-content">
                <h1>Billing & Payment</h1>
            </div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/">Home</a></li>
                    <li class="breadcrumb-item"><a href="/profile">My Profile</a></li>
                    <li class="breadcrumb-item active">Billing</li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="content-section">
    <div class="container">
        <div style="max-width: 720px; margin: 0 auto;">

            <?php
            // Display session messages
            if (!empty($page_vars['display_messages'])) {
                foreach ($page_vars['display_messages'] as $msg) {
                    echo PublicPage::alert($msg->message_title, $msg->message, $msg->get_message_class());
                }
            }
            ?>

            <?php if (!empty($page_vars['success_message'])): ?>
            <div class="alert alert-success" style="margin-bottom: 1.5rem;">
                <?php echo htmlspecialchars($page_vars['success_message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($page_vars['error_message'])): ?>
            <div class="alert alert-error" style="margin-bottom: 1.5rem;">
                <?php echo htmlspecialchars($page_vars['error_message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>

            <!-- Payment Method -->
            <?php if ($payment_system === 'stripe' && $page_vars['stripe_customer_id']): ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee);">
                    <h5 style="margin: 0;">Payment Method</h5>
                </div>
                <div style="padding: 1.5rem;">
                    <?php if ($payment_method): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="background: #f0f0f0; border-radius: 6px; padding: 0.5rem 0.75rem; font-weight: 700; font-size: 0.8125rem; letter-spacing: 0.05em;">
                                <?php echo htmlspecialchars($payment_method['brand'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div>
                                <div style="font-weight: 600;">&bull;&bull;&bull;&bull; <?php echo htmlspecialchars($payment_method['last4'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div style="font-size: 0.875rem; color: var(--color-muted);">Expires <?php echo $payment_method['exp_month']; ?>/<?php echo $payment_method['exp_year']; ?></div>
                            </div>
                        </div>
                        <form method="POST" action="/profile/billing">
                            <input type="hidden" name="action" value="update_payment_method">
                            <button type="submit" class="btn btn-outline" style="font-size: 0.875rem;">Update Payment Method</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <span style="color: var(--color-muted);">No payment method on file.</span>
                        <form method="POST" action="/profile/billing">
                            <input type="hidden" name="action" value="update_payment_method">
                            <button type="submit" class="btn btn-primary" style="font-size: 0.875rem;">Add Payment Method</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($payment_system === 'paypal'): ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee);">
                    <h5 style="margin: 0;">Payment Method</h5>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div style="background: #ffc439; border-radius: 6px; padding: 0.5rem 0.75rem; font-weight: 700; font-size: 0.8125rem;">PayPal</div>
                        <div style="color: var(--color-muted);">
                            Your subscription is managed through PayPal. To update your payment method, visit
                            <a href="https://www.paypal.com/myaccount/autopay/" target="_blank" rel="noopener">PayPal</a>.
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Billing Cycle Switcher -->
            <?php if ($show_cycle_switcher): ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee);">
                    <h5 style="margin: 0;">Billing Cycle</h5>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="margin-bottom: 1rem;">
                        <span style="font-weight: 600;">Current:</span>
                        <?php echo htmlspecialchars($current_version->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?>
                        &mdash; $<?php echo number_format($current_version->get('prv_version_price'), 2); ?>/<?php echo htmlspecialchars($current_version->get('prv_price_type'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>

                    <?php foreach ($alternative_versions as $alt): ?>
                    <?php
                        // Calculate savings
                        $current_price = floatval($current_version->get('prv_version_price'));
                        $current_type = $current_version->get('prv_price_type');
                        $alt_price = floatval($alt->get('prv_version_price'));
                        $alt_type = $alt->get('prv_price_type');

                        $savings_text = '';
                        // Compare annual costs
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
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: var(--color-light, #f8f9fa); border-radius: 6px; margin-bottom: 0.5rem;">
                        <div>
                            <strong><?php echo htmlspecialchars($alt->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?></strong>
                            &mdash; $<?php echo number_format($alt_price, 2); ?>/<?php echo htmlspecialchars($alt_type, ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($savings_text): ?>
                            <span style="color: #198754; font-weight: 600; margin-left: 0.5rem;"><?php echo $savings_text; ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="POST" action="/profile/billing" onsubmit="return confirm('Switch to <?php echo htmlspecialchars($alt->get('prv_version_name'), ENT_QUOTES, 'UTF-8'); ?> billing? Your subscription will be updated and prorated.');">
                            <input type="hidden" name="action" value="change_billing_cycle">
                            <input type="hidden" name="new_version_id" value="<?php echo $alt->key; ?>">
                            <button type="submit" class="btn btn-primary" style="font-size: 0.875rem;">Switch</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php elseif ($payment_system === 'paypal' && $current_subscription && !$current_subscription->get('odi_subscription_cancelled_time')): ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee);">
                    <h5 style="margin: 0;">Billing Cycle</h5>
                </div>
                <div style="padding: 1.5rem; color: var(--color-muted);">
                    To change your billing cycle, please cancel your current subscription and re-subscribe with the new billing option on the <a href="/profile/change-tier">subscription management page</a>.
                </div>
            </div>
            <?php endif; ?>

            <!-- Billing History -->
            <?php if (!empty($invoices)): ?>
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); margin-bottom: 1.5rem; overflow: hidden;">
                <div style="background: var(--color-light, #f8f9fa); padding: 1rem 1.5rem; border-bottom: 1px solid var(--color-border, #eee);">
                    <h5 style="margin: 0;">Billing History</h5>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--color-border, #eee);">
                                <th style="padding: 0.75rem 1.5rem; text-align: left; font-size: 0.875rem; color: var(--color-muted);">Date</th>
                                <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.875rem; color: var(--color-muted);">Description</th>
                                <th style="padding: 0.75rem 1rem; text-align: right; font-size: 0.875rem; color: var(--color-muted);">Amount</th>
                                <th style="padding: 0.75rem 1rem; text-align: center; font-size: 0.875rem; color: var(--color-muted);">Status</th>
                                <th style="padding: 0.75rem 1.5rem; text-align: center; font-size: 0.875rem; color: var(--color-muted);"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                            <tr style="border-bottom: 1px solid var(--color-border, #eee);">
                                <td style="padding: 0.75rem 1.5rem; font-size: 0.9375rem; white-space: nowrap;"><?php echo htmlspecialchars($invoice['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding: 0.75rem 1rem; font-size: 0.9375rem;"><?php echo htmlspecialchars($invoice['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding: 0.75rem 1rem; text-align: right; font-size: 0.9375rem; font-weight: 600;">$<?php echo htmlspecialchars($invoice['amount'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding: 0.75rem 1rem; text-align: center;">
                                    <span style="display: inline-block; padding: 0.125rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;
                                        <?php if ($invoice['status'] === 'paid'): ?>background: #d4edda; color: #155724;
                                        <?php elseif ($invoice['status'] === 'open'): ?>background: #fff3cd; color: #856404;
                                        <?php else: ?>background: #f8d7da; color: #721c24;<?php endif; ?>">
                                        <?php echo htmlspecialchars(ucfirst($invoice['status']), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem 1.5rem; text-align: center;">
                                    <?php if ($invoice['pdf_url']): ?>
                                    <a href="<?php echo htmlspecialchars($invoice['pdf_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" style="color: var(--color-primary); text-decoration: none; font-size: 0.875rem;" title="Download PDF">
                                        PDF
                                    </a>
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
            <div style="background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); padding: 2.5rem; text-align: center;">
                <div style="font-size: 2.5rem; color: var(--color-muted); margin-bottom: 1rem;">&#128179;</div>
                <h4 style="margin-bottom: 0.5rem;">No billing information</h4>
                <p style="color: var(--color-muted); margin-bottom: 1.5rem;">You don't have any active subscriptions or past purchases.</p>
                <a href="/products" class="btn btn-primary">Browse Products</a>
            </div>
            <?php endif; ?>

            <!-- Navigation -->
            <div style="margin-top: 1rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                <a href="/profile" style="color: var(--color-muted); text-decoration: none; font-size: 0.9375rem;">&larr; Back to Profile</a>
                <?php if ($current_subscription): ?>
                <a href="/profile/change-tier" style="color: var(--color-primary); text-decoration: none; font-size: 0.9375rem;">Manage Subscription Plan</a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</section>

<?php
$page->member_footer(['track' => true]);
?>
