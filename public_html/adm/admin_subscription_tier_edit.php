<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_subscription_tier_edit_logic.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

// Process logic
$page_vars = process_logic(admin_subscription_tier_edit_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();

// Display page
$page->admin_header(array(
    'menu-id' => 'subscription-tier-edit',
    'page_title' => ($tier && $tier->key) ? 'Edit Subscription Tier' : 'Create Subscription Tier',
    'readable_title' => ($tier && $tier->key) ? 'Edit Subscription Tier' : 'Create Subscription Tier',
    'breadcrumbs' => array(
        'Subscription Tiers' => '/admin/admin_subscription_tiers',
        (($tier && $tier->key) ? 'Edit Tier' : 'Create Tier') => ''
    )
));
?>

<div class="container-fluid">
    <h1><?php echo ($tier && $tier->key) ? 'Edit Subscription Tier' : 'Create New Subscription Tier'; ?></h1>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php
            // Initialize FormWriter V2
            $formwriter = $page->getFormWriter('admin_tier_edit', 'v2', [
                'model' => $tier,
                'edit_primary_key_value' => ($tier && $tier->key) ? $tier->key : null,
                'validation' => false  // Temporarily disable validation for debugging
            ]);

            $formwriter->begin_form();

            $formwriter->textinput('sbt_name', 'Tier Name', [
                'placeholder' => 'Internal name (e.g., premium)',
                'validation' => ['required' => true, 'maxlength' => 100]
            ]);

            $formwriter->textinput('sbt_display_name', 'Display Name', [
                'placeholder' => 'User-facing name (e.g., Premium Member)',
                'validation' => ['required' => true, 'maxlength' => 100]
            ]);

            $formwriter->textinput('sbt_tier_level', 'Tier Level', [
                'placeholder' => 'Higher number = higher tier (e.g., 30)',
                'validation' => ['required' => true]
            ]);

            $formwriter->textbox('sbt_description', 'Description', [
                'placeholder' => 'Optional description',
                'rows' => 3
            ]);
            ?>

            <!-- Features Section - Keep as raw HTML (dynamic from JSON config) -->
            <div class="card mt-4 mb-4">
                <div class="card-header">
                    <h4>Tier Features & Limits</h4>
                </div>
                <div class="card-body">
                    <?php
                    // Get all available features
                    $available_features = SubscriptionTier::getAllAvailableFeatures();
                    $current_values = $tier ? $tier->getAllFeatures() : array();

                    if (empty($available_features)):
                    ?>
                        <p class="text-muted">No features have been defined. Add feature definitions in /includes/core_tier_features.json or plugin tier_features.json files.</p>
                    <?php else: ?>
                        <div class="row">
                            <?php
                            $col = 0;
                            foreach ($available_features as $key => $definition):
                                $current_value = isset($current_values[$key]) ? $current_values[$key] : $definition['default'];

                                // Start new row every 2 features
                                if ($col % 2 == 0 && $col > 0):
                                    echo '</div><div class="row">';
                                endif;
                            ?>
                                <div class="col-md-6">
                                    <?php if ($definition['type'] === 'boolean'): ?>
                                        <div class="form-check mb-3">
                                            <input type="hidden" name="features[<?php echo htmlspecialchars($key); ?>]" value="0">
                                            <input type="checkbox"
                                                   class="form-check-input"
                                                   id="feature_<?php echo htmlspecialchars($key); ?>"
                                                   name="features[<?php echo htmlspecialchars($key); ?>]"
                                                   value="1"
                                                   <?php echo $current_value ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="feature_<?php echo htmlspecialchars($key); ?>">
                                                <strong><?php echo htmlspecialchars($definition['label']); ?></strong>
                                                <?php if (!empty($definition['description'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($definition['description']); ?></small>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php elseif ($definition['type'] === 'integer'): ?>
                                        <div class="form-group mb-3">
                                            <label for="feature_<?php echo htmlspecialchars($key); ?>">
                                                <strong><?php echo htmlspecialchars($definition['label']); ?></strong>
                                            </label>
                                            <input type="number"
                                                   class="form-control"
                                                   id="feature_<?php echo htmlspecialchars($key); ?>"
                                                   name="features[<?php echo htmlspecialchars($key); ?>]"
                                                   value="<?php echo htmlspecialchars($current_value); ?>"
                                                   <?php if (isset($definition['min'])): ?>min="<?php echo $definition['min']; ?>"<?php endif; ?>
                                                   <?php if (isset($definition['max'])): ?>max="<?php echo $definition['max']; ?>"<?php endif; ?>>
                                            <?php if (!empty($definition['description'])): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($definition['description']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-group mb-3">
                                            <label for="feature_<?php echo htmlspecialchars($key); ?>">
                                                <strong><?php echo htmlspecialchars($definition['label']); ?></strong>
                                            </label>
                                            <input type="text"
                                                   class="form-control"
                                                   id="feature_<?php echo htmlspecialchars($key); ?>"
                                                   name="features[<?php echo htmlspecialchars($key); ?>]"
                                                   value="<?php echo htmlspecialchars($current_value); ?>">
                                            <?php if (!empty($definition['description'])): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($definition['description']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php
                                $col++;
                            endforeach;
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            // Active checkbox for edit mode, hidden field for create mode
            if ($tier && $tier->key) {
                $formwriter->checkboxinput('sbt_is_active', 'Active');
            } else {
                $formwriter->hiddeninput('sbt_is_active', ['value' => '1']);
            }

            $formwriter->submitbutton('submit_button', ($tier && $tier->key) ? 'Update Tier' : 'Create Tier');
            $formwriter->end_form();
            ?>

            <a href="/admin/admin_subscription_tiers" class="btn btn-secondary">Cancel</a>
        </div>
    </div>

    <?php if ($tier && $tier->key): ?>
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
                    <a href="/admin/admin_subscription_tier_members?id=<?php echo $tier->key; ?>"
                       class="btn btn-info">View Members</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$page->admin_footer();
?>
