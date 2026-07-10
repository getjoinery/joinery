<?php
/**
 * Tier Gate Prompt
 *
 * Renders a paywall prompt when content is restricted by subscription tier.
 * Call render_tier_gate_prompt($access) where $access is the array returned
 * by SystemBase::authenticate_tier().
 */

function render_tier_gate_prompt($access, $options = []) {
	$settings = Globalvars::get_instance();
	$tier_name = '';
	if ($access['required_tier']) {
		$tier_name = htmlspecialchars($access['required_tier']->get('sbt_display_name'));
	}

	$show_preview = !empty($options['preview_html']);
	?>

	<?php if ($show_preview): ?>
	<div class="tier-gate-preview" style="position: relative; overflow: hidden; max-height: 300px;">
		<?php echo $options['preview_html']; ?>
		<div style="position: absolute; bottom: 0; left: 0; right: 0; height: 120px; background: linear-gradient(transparent, #fff); pointer-events: none;"></div>
	</div>
	<?php endif; ?>

	<div class="tier-gate-prompt" style="text-align: center; padding: 2.5rem 1.5rem; margin: 1.5rem 0; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 12px; border: 1px solid #dee2e6;">
		<div style="font-size: 2rem; margin-bottom: 0.75rem;">&#128274;</div>

		<?php if ($access['reason'] === 'not_logged_in'): ?>
			<h3 style="margin-bottom: 0.5rem; font-size: 1.25rem;">Members-Only Content</h3>
			<p style="color: #6c757d; margin-bottom: 1.5rem;">
				Log in or create an account to access this content.
				<?php if ($tier_name): ?>
				A <strong><?php echo $tier_name; ?></strong> subscription or higher is required.
				<?php endif; ?>
			</p>
			<div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
				<a href="/login" style="display: inline-block; padding: 0.625rem 1.5rem; background: var(--color-primary, #0d6efd); color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">Log In</a>
				<a href="/signup" style="display: inline-block; padding: 0.625rem 1.5rem; background: transparent; color: var(--color-primary, #0d6efd); text-decoration: none; border-radius: 6px; font-weight: 600; border: 2px solid var(--color-primary, #0d6efd);">Sign Up</a>
			</div>

		<?php elseif ($access['reason'] === 'tier_too_low'): ?>
			<h3 style="margin-bottom: 0.5rem; font-size: 1.25rem;">Upgrade to Access</h3>
			<p style="color: #6c757d; margin-bottom: 1.5rem;">
				This content is available to <strong><?php echo $tier_name; ?></strong> members and above.
				<?php
				if ($access['user_level'] > 0) {
					$user_tier = SubscriptionTier::GetUserTier(SessionControl::get_instance()->get_user_id());
					if ($user_tier) {
						echo 'Your current tier: <strong>' . htmlspecialchars($user_tier->get('sbt_display_name')) . '</strong>.';
					}
				} else {
					echo 'You don\'t have an active subscription.';
				}
				?>
			</p>
			<?php
			$tier_upgrade_url = Globalvars::get_instance()->get_setting('tier_upgrade_url');
			// When no explicit upgrade URL is configured, offer the products that
			// grant a higher tier directly (the store seam). If none exist, degrade
			// to the contact message. Once the store is a plugin, an absent
			// TierBilling naturally leaves only the contact fallback.
			$upgrade_products = [];
			// Only reach into the store's billing helper when the store plugin is
			// actually active — its tables (pro_products) exist only then. With the
			// store inactive this leaves the contact-us fallback (or tier_upgrade_url).
			if (empty($tier_upgrade_url) && PluginHelper::isPluginActive('store')) {
				$tier_billing_path = PathHelper::getIncludePath('plugins/store/includes/TierBilling.php');
				if (file_exists($tier_billing_path)) {
					require_once($tier_billing_path);
				}
			}
			if (empty($tier_upgrade_url) && class_exists('TierBilling')) {
				$upgrade_user_id = SessionControl::get_instance()->get_user_id();
				if ($upgrade_user_id) {
					foreach (TierBilling::getUpgradeOptions($upgrade_user_id) as $option) {
						foreach ($option['products'] as $product) {
							$upgrade_products[] = $product;
						}
					}
				}
			}
			?>
			<?php if (!empty($tier_upgrade_url)): ?>
			<div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
				<a href="<?php echo htmlspecialchars($tier_upgrade_url); ?>"
				   style="display: inline-block; padding: 0.625rem 1.5rem; background: var(--color-primary, #0d6efd); color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">
					Upgrade your subscription
				</a>
			</div>
			<?php elseif (!empty($upgrade_products)): ?>
			<div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap;">
				<?php foreach ($upgrade_products as $product): ?>
				<a href="<?php echo htmlspecialchars($product['pro_url']); ?>"
				   style="display: inline-block; padding: 0.625rem 1.5rem; background: var(--color-primary, #0d6efd); color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600;">
					<?php echo htmlspecialchars($product['pro_name']); ?>
				</a>
				<?php endforeach; ?>
			</div>
			<?php else: ?>
			<p style="color: #6c757d; font-size: 0.875rem;">Contact us to learn about upgrading your subscription.</p>
			<?php endif; ?>

		<?php endif; ?>
	</div>
	<?php
}

/**
 * Generate preview HTML for body text content.
 * Returns truncated text wrapped in a div, or empty string if no preview configured.
 */
function get_tier_gate_preview_html($body_text) {
	$settings = Globalvars::get_instance();
	$preview_length = intval($settings->get_setting('tier_gate_preview_length'));

	if ($preview_length <= 0 || empty($body_text)) {
		return '';
	}

	$preview = mb_substr(strip_tags($body_text), 0, $preview_length);
	return '<div class="entry-content"><p>' . htmlspecialchars($preview) . '&hellip;</p></div>';
}
?>
