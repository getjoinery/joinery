<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/groups_class.php'));
	require_once(PathHelper::getIncludePath('/data/subscription_tiers_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	// Get user ID from query string
	if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
		header("Location: /admin/admin_users");
		exit();
	}

	$user_id = intval($_GET['user_id']);
	$user = new User($user_id, TRUE);

	if (!$user->key) {
		header("Location: /admin/admin_users");
		exit();
	}

	// Handle form submission
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (isset($_POST['tier_id'])) {
			try {
				if ($_POST['tier_id'] === '0') {
					// Remove user from all tiers
					$tier_groups = new MultiGroup(['grp_category' => 'subscription_tier']);
					$tier_groups->load();

					foreach ($tier_groups as $group) {
						$group->remove_member($user_id);
					}
					$_SESSION['admin_flash_message'] = 'User tier removed successfully';
				} else {
					// Add user to new tier
					$tier = new SubscriptionTier($_POST['tier_id'], TRUE);
					$tier->addUser(
						$user_id,
						'manual',
						'admin',
						null,
						$session->get_user_id()
					);
					$_SESSION['admin_flash_message'] = 'User tier updated successfully';
				}

				header("Location: /admin/admin_user?usr_user_id=" . $user_id);
				exit();

			} catch (Exception $e) {
				$error_message = 'Error updating tier: ' . $e->getMessage();
			}
		}
	}

	// Get current tier
	$current_tier = SubscriptionTier::GetUserTier($user_id);
	$current_tier_id = $current_tier ? $current_tier->key : 0;

	// Get all available tiers
	$all_tiers = MultiSubscriptionTier::GetAllActive();

	$page = new AdminPage();
	$page->admin_header(array(
		'title' => 'Change User Subscription Tier',
		'no_search' => 1
	));

	?>

	<h2>Change Subscription Tier</h2>
	<p>User: <strong><?php echo htmlspecialchars($user->get('usr_fname') . ' ' . $user->get('usr_lname')); ?></strong>
	   (<?php echo htmlspecialchars($user->get('usr_email')); ?>)</p>

	<?php if (isset($error_message)): ?>
		<div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
	<?php endif; ?>

	<form method="post" action="/admin/admin_tier_edit?user_id=<?php echo $user_id; ?>">
		<div class="form-group">
			<label for="tier_id">Select Tier:</label>
			<select name="tier_id" id="tier_id" class="form-control" style="max-width: 400px;">
				<option value="0" <?php echo $current_tier_id === 0 ? 'selected' : ''; ?>>
					Free (No tier)
				</option>
				<?php foreach ($all_tiers as $tier): ?>
					<option value="<?php echo $tier->key; ?>"
							<?php echo $current_tier_id === $tier->key ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars($tier->get('sbt_display_name')); ?>
						(Level <?php echo $tier->get('sbt_tier_level'); ?>)
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="form-group">
			<button type="submit" class="btn btn-primary">Update Tier</button>
			<a href="/admin/admin_user?usr_user_id=<?php echo $user_id; ?>" class="btn btn-default">Cancel</a>
		</div>

		<?php if ($current_tier): ?>
			<div class="alert alert-info">
				Current tier: <strong><?php echo htmlspecialchars($current_tier->get('sbt_display_name')); ?></strong>
				(Level <?php echo $current_tier->get('sbt_tier_level'); ?>)
			</div>
		<?php else: ?>
			<div class="alert alert-info">
				Current tier: <strong>Free (No active tier)</strong>
			</div>
		<?php endif; ?>
	</form>

	<?php
	$page->admin_footer();
?>