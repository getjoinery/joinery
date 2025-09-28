<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
require_once(PathHelper::getIncludePath('data/change_tracking_class.php'));

// Check permissions
$session = SessionControl::get_instance();
$session->check_permission(5);

$page = new AdminPage();
$formwriter = LibraryFunctions::get_formwriter_object('admin_tier_edit', 'admin');

// Check if we're editing an existing tier
$tier_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$tier = null;
$is_edit = false;

if ($tier_id) {
    $tier = new SubscriptionTier($tier_id, TRUE);
    $is_edit = true;
}

// Handle form submissions
$error_message = null;
if (isset($_POST['action'])) {
    if ($_POST['action'] == 'save') {
        try {
            if ($is_edit) {
                // Update existing tier
                $tier->set('sbt_name', $_POST['sbt_name']);
                $tier->set('sbt_display_name', $_POST['sbt_display_name']);
                $tier->set('sbt_tier_level', $_POST['sbt_tier_level']);
                $tier->set('sbt_description', $_POST['sbt_description']);
                $tier->set('sbt_is_active', isset($_POST['sbt_is_active']) ? true : false);
                $tier->save();

                ChangeTracking::logChange(
                    'subscription_tier',
                    $tier->key,
                    null,
                    'updated',
                    null,
                    $tier->get('sbt_name'),
                    'admin_update',
                    'admin_action',
                    null,
                    $session->get_user_id()
                );
            } else {
                // Create new tier
                $tier = new SubscriptionTier(NULL);
                $tier->set('sbt_name', $_POST['sbt_name']);
                $tier->set('sbt_display_name', $_POST['sbt_display_name']);
                $tier->set('sbt_tier_level', $_POST['sbt_tier_level']);
                $tier->set('sbt_description', $_POST['sbt_description']);
                $tier->set('sbt_is_active', isset($_POST['sbt_is_active']) ? true : false);
                $tier->save();

                ChangeTracking::logChange(
                    'subscription_tier',
                    $tier->key,
                    null,
                    'created',
                    null,
                    $tier->get('sbt_name'),
                    'admin_create',
                    'admin_action',
                    null,
                    $session->get_user_id()
                );
            }

            header('Location: admin_subscription_tiers.php?success=1');
            exit;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

// Display page
$page->admin_header(array(
    'menu-id' => 'subscription-tier-edit',
    'page_title' => $is_edit ? 'Edit Subscription Tier' : 'Create Subscription Tier',
    'readable_title' => $is_edit ? 'Edit Subscription Tier' : 'Create Subscription Tier',
    'breadcrumbs' => array(
        'Subscription Tiers' => '/admin/admin_subscription_tiers',
        ($is_edit ? 'Edit Tier' : 'Create Tier') => ''
    )
));
?>

<div class="container-fluid">
    <h1><?php echo $is_edit ? 'Edit Subscription Tier' : 'Create New Subscription Tier'; ?></h1>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="save">

                <?php echo $formwriter->textinput('Tier Name', 'sbt_name', null, 30,
                    $tier ? $tier->get('sbt_name') : '',
                    'Internal name (e.g., premium)', 100); ?>

                <?php echo $formwriter->textinput('Display Name', 'sbt_display_name', null, 30,
                    $tier ? $tier->get('sbt_display_name') : '',
                    'User-facing name (e.g., Premium Member)', 100); ?>

                <?php echo $formwriter->textinput('Tier Level', 'sbt_tier_level', null, 10,
                    $tier ? $tier->get('sbt_tier_level') : '',
                    'Higher number = higher tier (e.g., 30)', 10); ?>

                <?php echo $formwriter->textbox('Description', 'sbt_description', '', 3, 50,
                    $tier ? $tier->get('sbt_description') : '',
                    'Optional description'); ?>


                <?php if ($is_edit): ?>
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="sbt_is_active" name="sbt_is_active"
                                <?php echo $tier->get('sbt_is_active') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="sbt_is_active">
                                Active
                            </label>
                        </div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="sbt_is_active" value="1">
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">
                    <?php echo $is_edit ? 'Update Tier' : 'Create Tier'; ?>
                </button>
                <a href="admin_subscription_tiers.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>

    <?php if ($is_edit && $tier): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h3>Tier Members</h3>
            </div>
            <div class="card-body">
                <?php
                // Get group members
                $group = new Group($tier->get('sbt_grp_group_id'), TRUE);
                $members = $group->get_member_list();
                $member_count = count($members);
                ?>
                <p>This tier has <strong><?php echo $member_count; ?></strong> member(s).</p>

                <?php if ($member_count > 0): ?>
                    <a href="admin_subscription_tier_members.php?id=<?php echo $tier->key; ?>"
                       class="btn btn-info">View Members</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$page->admin_footer();
?>