<?php
/**
 * Joinery AI - Recipe Edit
 * URL: /admin/joinery_ai/edit
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/admin_edit_logic.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolRegistry.php'));

$page_vars = process_logic(admin_joinery_ai_edit_logic($_GET, $_POST));
extract($page_vars);

$is_new = !$recipe->key;

$page = new AdminPage();
$page->admin_header([
    'menu-id' => 'joinery-ai-recipes',
    'page_title' => $is_new ? 'New Recipe' : 'Edit Recipe',
    'readable_title' => $is_new ? 'New Recipe' : 'Edit Recipe',
    'breadcrumbs' => [
        'Joinery AI' => '/admin/joinery_ai',
        ($is_new ? 'New Recipe' : 'Edit Recipe') => '',
    ],
    'session' => $session,
]);

if (!empty($saved)) {
    echo '<div class="alert alert-success">Saved.</div>';
}

$page->begin_box(['title' => $is_new ? 'Create Recipe' : 'Edit Recipe']);

$formwriter = $page->getFormWriter('form1', [
    'model' => $recipe,
    'edit_primary_key_value' => $recipe->key,
]);

echo $formwriter->begin_form();

// --- Identity ---
$formwriter->textinput('rcp_name', 'Name', ['required' => true]);

$formwriter->textarea('rcp_prompt', 'Prompt', [
    'rows' => 12,
    'placeholder' => 'Describe what the recipe should do, what tools to use, what to deliver.',
]);

// --- Schedule ---
$formwriter->dropinput('rcp_schedule_frequency', 'Schedule Frequency', [
    'options' => [
        'hourly' => 'Hourly',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
    ],
]);

$formwriter->dropinput('rcp_schedule_day_of_week', 'Day of Week (weekly only)', [
    'options' => [
        '' => '—',
        '0' => 'Sunday',
        '1' => 'Monday',
        '2' => 'Tuesday',
        '3' => 'Wednesday',
        '4' => 'Thursday',
        '5' => 'Friday',
        '6' => 'Saturday',
    ],
]);

$formwriter->timeinput('rcp_schedule_time', 'Time of Day (daily/weekly only)');

// --- Model & tools ---
$settings = Globalvars::get_instance();
$default_model = $settings->get_setting('joinery_ai_default_model') ?: 'claude-sonnet-4-7';

$formwriter->dropinput('rcp_model', 'Model', [
    'options' => [
        'claude-opus-4-7'   => 'Claude Opus 4.7 ($5/$25 per Mtok)',
        'claude-sonnet-4-6' => 'Claude Sonnet 4.6 ($3/$15 per Mtok)',
        'claude-haiku-4-5'  => 'Claude Haiku 4.5 ($1/$5 per Mtok)',
    ],
]);

// Allowed tools — checkboxes against the live tool registry. Drop-in tools
// from any plugin's recipe_tools/ directory show up automatically.
$selected_tools = $recipe->get('rcp_allowed_tools');
if (is_string($selected_tools)) {
    $decoded = json_decode($selected_tools, true);
    $selected_tools = is_array($decoded) ? $decoded : [];
}
if (!is_array($selected_tools)) $selected_tools = [];

$registry_map = RecipeToolRegistry::all();
echo '<div class="form-group mb-3">';
echo '<label class="form-label">Allowed Tools</label>';
if (empty($registry_map)) {
    echo '<p class="text-muted">No tools registered. Drop a class implementing '
       . '<code>RecipeToolInterface</code> into <code>plugins/&lt;plugin&gt;/recipe_tools/</code>.</p>';
} else {
    foreach ($registry_map as $tool_name => $tool_class) {
        $checked = in_array($tool_name, $selected_tools, true) ? ' checked' : '';
        $desc = htmlspecialchars($tool_class::description());
        echo '<div class="form-check">';
        echo '<input class="form-check-input" type="checkbox" '
           . 'name="rcp_allowed_tools[]" '
           . 'value="' . htmlspecialchars($tool_name) . '" '
           . 'id="tool_' . htmlspecialchars($tool_name) . '"' . $checked . '>';
        echo '<label class="form-check-label" for="tool_' . htmlspecialchars($tool_name) . '">';
        echo '<strong>' . htmlspecialchars($tool_name) . '</strong>'
           . '<br><small class="text-muted">' . $desc . '</small>';
        echo '</label></div>';
    }
}
echo '</div>';

// --- Delivery ---
$formwriter->textinput('rcp_delivery_email', 'Delivery Email (blank = owner email)');

$formwriter->checkboxinput('rcp_delivery_dashboard', 'Show on dashboard', [
    'value' => 1,
    'checked' => (bool)$recipe->get('rcp_delivery_dashboard'),
]);

$formwriter->checkboxinput('rcp_enabled', 'Enabled', [
    'value' => 1,
    'checked' => (bool)$recipe->get('rcp_enabled'),
]);

// --- Limits ---
$formwriter->numberinput('rcp_max_iterations', 'Max Tool-Loop Iterations', ['min' => 1, 'max' => 50]);
$formwriter->numberinput('rcp_max_tokens', 'Max Tokens Per Run', ['min' => 1000, 'max' => 200000]);
$formwriter->numberinput('rcp_monthly_token_cap', 'Monthly Token Cap', ['min' => 0]);

// --- Workspace (advanced) ---
$formwriter->textarea('rcp_workspace', 'Workspace (LLM-curated; edit only when debugging)', [
    'rows' => 8,
]);

$formwriter->submitbutton('btn_submit', $is_new ? 'Create' : 'Save');

if (!$is_new) {
    echo '<button type="submit" name="btn_delete" value="1" class="btn btn-outline-danger ms-2" '
       . 'onclick="return confirm(\'Soft-delete this recipe?\');">Delete</button>';
}

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
