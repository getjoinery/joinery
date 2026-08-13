<?php
/**
 * SubscriptionsPanel — the admin user-detail "Subscriptions" panel (store-owned).
 *
 * Read-only: lists the user's active and cancelled subscriptions. Reconciles
 * each active subscription against Stripe during render (as the page always
 * has). Registered from the store's serve.php, so it appears on
 * /admin/admin_user only when the store plugin is active.
 */

require_once(PathHelper::getIncludePath('includes/AdminUserPanelRegistry.php'));

class SubscriptionsPanel implements AdminUserPanel {

	public function id(): string {
		return 'store_subscriptions';
	}

	public function actions(): array {
		return array();
	}

	public function handle(string $action, User $user, array $input): LogicResult {
		return LogicResult::redirect('/admin/admin_user?usr_user_id=' . $user->key);
	}

	public function render(User $user, AdminPage $page, array $context = []): string {
		require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
		require_once(PathHelper::getIncludePath('plugins/store/includes/StripeHelper.php'));
		require_once(PathHelper::getIncludePath('includes/Pager.php'));

		$session = SessionControl::get_instance();

		// Page-level list state, derived once by admin_user_logic and passed in.
		$show_all = !empty($context['show_all']);
		$list_limit = array_key_exists('list_limit', $context) ? $context['list_limit'] : 10;
		$show_all_url = $context['show_all_url'] ?? null;

		$active_subscriptions = new MultiOrderItem(
			array('user_id' => $user->key, 'is_active_subscription' => true),
			array('order_item_id' => 'DESC'),
			$list_limit,
			NULL);
		$num_active_subscriptions = $active_subscriptions->count_all();
		$active_subscriptions->load();
		$active_pager = new Pager(array('numrecords' => $num_active_subscriptions, 'numperpage' => $list_limit ?: $num_active_subscriptions));

		$cancelled_subscriptions = new MultiOrderItem(
			array('user_id' => $user->key, 'is_cancelled_subscription' => true),
			array('order_item_id' => 'DESC'),
			$list_limit,
			NULL);
		$num_cancelled_subscriptions = $cancelled_subscriptions->count_all();
		$cancelled_subscriptions->load();
		$cancelled_pager = new Pager(array('numrecords' => $num_cancelled_subscriptions, 'numperpage' => $list_limit ?: $num_cancelled_subscriptions));

		ob_start();
		?>
		<div class="card mt-3">
			<div class="card-header bg-body-tertiary">
				<h6 class="mb-0"><span class="fas fa-credit-card me-2"></span>Active Subscriptions</h6>
			</div>
			<div class="card-body">
				<?php if ($active_subscriptions->count() > 0): ?>
					<?php foreach ($active_subscriptions as $subscription): ?>
						<?php
						$stripe_helper = new StripeHelper();
						$stripe_helper->update_subscription_in_order_item($subscription);
						$status_words = $subscription->get('odi_subscription_status') ? $subscription->get('odi_subscription_status') : 'active';
						?>
						<div class="mb-3 p-2 bg-body-tertiary rounded">
							<div class="fw-semi-bold">
								<a href="/plugins/store/admin/admin_order?ord_order_id=<?php echo $subscription->get('odi_ord_order_id'); ?>">
									Order <?php echo $subscription->get('odi_ord_order_id'); ?>
								</a> - $<?php echo number_format($subscription->get('odi_price'), 2); ?>/month
							</div>
							<div class="fs-11 text-600 mt-1">
								Status: <span class="text-success"><?php echo htmlspecialchars($status_words); ?></span><br>
								<?php if ($subscription->get('odi_subscription_period_end')): ?>
									Period ends: <?php echo $subscription->get_local('odi_subscription_period_end'); ?><br>
								<?php endif; ?>
								<a href="/profile/orders_recurring_action?order_item_id=<?php echo $subscription->key; ?>" class="text-danger">cancel</a>
							</div>
						</div>
					<?php endforeach; ?>
				<?php else: ?>
					<p class="text-600 mb-0">No active subscriptions</p>
				<?php endif; ?>
			</div>
			<?php echo $active_pager->record_count_info($active_subscriptions->count(), array('show_all_url' => $show_all_url)); ?>
		</div>

		<div class="card mt-3">
			<div class="card-header bg-body-tertiary">
				<h6 class="mb-0"><span class="fas fa-ban me-2"></span>Cancelled Subscriptions</h6>
			</div>
			<div class="card-body">
				<?php if ($cancelled_subscriptions->count() > 0): ?>
					<?php foreach ($cancelled_subscriptions as $subscription): ?>
						<div class="mb-2 p-2 bg-body-tertiary rounded">
							<div class="fw-semi-bold">
								<a href="/plugins/store/admin/admin_order?ord_order_id=<?php echo $subscription->get('odi_ord_order_id'); ?>">
									Order <?php echo $subscription->get('odi_ord_order_id'); ?>
								</a> - $<?php echo number_format($subscription->get('odi_price'), 2); ?>/month
							</div>
							<div class="fs-11 text-600 mt-1">
								Canceled: <?php echo $subscription->get_local('odi_subscription_cancelled_time'); ?>
								<?php if ($subscription->get('odi_subscription_period_end')): ?>
									<br>Last day: <?php echo $subscription->get_local('odi_subscription_period_end'); ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				<?php else: ?>
					<p class="text-600 mb-0">No cancelled subscriptions</p>
				<?php endif; ?>
			</div>
			<?php echo $cancelled_pager->record_count_info($cancelled_subscriptions->count(), array('show_all_url' => $show_all_url)); ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
