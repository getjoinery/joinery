<?php
/**
 * Joinery AI - Recipe Edit
 * URL: /admin/joinery_ai/edit
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/admin_edit_logic.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));

$page_vars = process_logic(admin_joinery_ai_edit_logic(array_merge($_GET, $_POST, $params ?? [])));
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

// Models with configuration issues — surfaces the registry's scan-time
// warnings (writable-without-readable, excluded ∩ writable, etc.) so the
// developer trying to use a missing model sees why it isn't there.
$registry_warnings = ModelRegistry::warnings();
if (!empty($registry_warnings)) {
    echo '<div class="alert alert-warning">';
    echo '<strong>Models with configuration issues</strong>';
    echo '<ul class="mb-0 mt-2">';
    foreach ($registry_warnings as $w) {
        echo '<li><code>' . htmlspecialchars($w['class']) . '</code>: '
           . htmlspecialchars($w['message']) . '</li>';
    }
    echo '</ul></div>';
}

$page->begin_box(['title' => $is_new ? 'Create Recipe' : 'Edit Recipe']);

$formwriter = $page->getFormWriter('form1', [
    'model' => $recipe,
    'edit_primary_key_value' => $recipe->key,
]);

echo $formwriter->begin_form();

// --- Identity ---
$formwriter->textinput('rcp_name', 'Name', ['required' => true]);

// Owner dropdown — list of active permission-10 admins. Recipes don't
// auto-delete on owner deletion/demotion (the broken state is recoverable;
// silent deletion isn't), so transferring ownership is the recovery path.
require_once(PathHelper::getIncludePath('data/users_class.php'));
$db = DbConnector::get_instance()->get_db_link();
$owner_q = $db->prepare("SELECT usr_user_id, usr_first_name, usr_last_name, usr_email
                         FROM usr_users
                         WHERE usr_permission >= 10
                           AND usr_delete_time IS NULL
                         ORDER BY usr_first_name, usr_last_name, usr_email");
$owner_q->execute();
$owner_options = ['' => '— select an owner —'];
foreach ($owner_q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $name = trim(($row['usr_first_name'] ?? '') . ' ' . ($row['usr_last_name'] ?? ''));
    $label = ($name !== '' ? $name : $row['usr_email'])
           . ' (' . $row['usr_email'] . ')';
    $owner_options[(string)$row['usr_user_id']] = $label;
}
$current_owner = (string)($recipe->get('rcp_owner_user_id') ?: $session->get_user_id());
// If the current owner isn't in the active-admin list, show them as inactive
// so the admin investigating can see what to change.
if (!isset($owner_options[$current_owner]) && $current_owner !== '') {
    $stale_user = new User((int)$current_owner, true);
    if ($stale_user->key) {
        $owner_options[$current_owner] = '[INACTIVE] ' . $stale_user->get('usr_email');
    }
}
$formwriter->dropinput('rcp_owner_user_id', 'Owner', [
    'options' => $owner_options,
]);

$formwriter->textarea('rcp_prompt', 'Prompt', [
    'rows' => 12,
    'placeholder' => 'Describe what the recipe should do, what tools to use, what to deliver.',
]);

// --- Schedule ---
// Show/hide day_of_week and time fields based on frequency. timeinput's
// Bootstrap renderer doesn't emit a container id, so we wrap it ourselves
// and target the wrapper. The dropinput already emits {name}_container.
$formwriter->dropinput('rcp_schedule_frequency', 'Schedule Frequency', [
    'options' => [
        'none'   => 'No Schedule',
        'hourly' => 'Hourly',
        'daily'  => 'Daily',
        'weekly' => 'Weekly',
    ],
    'visibility_rules' => [
        'none'   => ['hide' => ['rcp_schedule_day_of_week', 'rcp_schedule_time_wrap']],
        'hourly' => ['hide' => ['rcp_schedule_day_of_week', 'rcp_schedule_time_wrap']],
        'daily'  => ['show' => ['rcp_schedule_time_wrap'], 'hide' => ['rcp_schedule_day_of_week']],
        'weekly' => ['show' => ['rcp_schedule_day_of_week', 'rcp_schedule_time_wrap']],
    ],
]);

$formwriter->dropinput('rcp_schedule_day_of_week', 'Day of Week', [
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

echo '<div id="rcp_schedule_time_wrap">';
$formwriter->timeinput('rcp_schedule_time', 'Time of Day');
echo '</div>';

// --- Model & tools ---
$settings = Globalvars::get_instance();

// Model options come from the active provider, so switching provider re-skins
// the dropdown without touching this view. If the recipe's stored model isn't
// offered by the active provider (provider switched after authoring), append it
// flagged so the value is preserved and the mismatch is visible rather than
// silently overwritten on save.
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));
try {
    $model_options = LlmProviderFactory::build()->models();
} catch (LlmProviderException $e) {
    // Provider isn't configured yet (e.g. local selected with no model, or no
    // API key). Don't crash the edit page — fall back to no preset options; the
    // stored model is still preserved below.
    $model_options = [];
}
$stored_model = (string)$recipe->get('rcp_model');
if ($stored_model !== '' && !isset($model_options[$stored_model])) {
    $model_options[$stored_model] = "$stored_model — unavailable under current provider";
}

$formwriter->dropinput('rcp_model', 'Model', [
    'value'   => $stored_model,
    'options' => $model_options,
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
// query_model is implied by Allowed Models below — never a user-facing
// checkbox. The runner adds it automatically when at least one model is
// checked, and refuses to expose it otherwise.
unset($registry_map['query_model']);
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

// Allowed models — checkboxes against the live ModelRegistry. Recipes can
// only query models that are (a) opted in globally via $ai_readable and
// (b) explicitly checked here. Their schemas get injected into the system
// prompt at run start.
$selected_models = $recipe->get('rcp_allowed_models');
if (is_string($selected_models)) {
    $decoded = json_decode($selected_models, true);
    $selected_models = is_array($decoded) ? $decoded : [];
}
if (!is_array($selected_models)) $selected_models = [];

// Build the "wrapped by" cross-reference: for each model class, which
// mutating actions name a column whose prefix matches the class? The
// descriptor's input schema names target columns by name; we infer the
// model from the column prefix (the field-name convention).
$action_map = ActionRegistry::all();
ksort($action_map);
$model_to_actions = []; // class_name => [action_name, ...]
$action_to_models = []; // action_name => [class_name, ...]
foreach ($action_map as $action_name => $action_info) {
    $d = $action_info['descriptor'];
    if (empty($d['mutates'])) continue;
    $input_schema = isset($d['input']) && is_array($d['input']) ? $d['input'] : [];
    foreach ($input_schema as $field_name => $_spec) {
        // Field naming convention: {prefix}_{...}. Map prefix → class via registry.
        if (!is_string($field_name)) continue;
        if (!preg_match('/^([a-z]+)_/', $field_name, $m)) continue;
        $prefix = $m[1];
        foreach (ModelRegistry::all() as $class_name => $_info) {
            if (!property_exists($class_name, 'prefix')) continue;
            if ($class_name::$prefix === $prefix) {
                $model_to_actions[$class_name][] = $action_name;
                $action_to_models[$action_name][] = $class_name;
            }
        }
    }
}
foreach ($model_to_actions as $k => $v) $model_to_actions[$k] = array_values(array_unique($v));
foreach ($action_to_models as $k => $v) $action_to_models[$k] = array_values(array_unique($v));

$model_map = ModelRegistry::all();
ksort($model_map);
echo '<div class="form-group mb-3">';
echo '<label class="form-label">Allowed Models <span class="text-muted small">('
   . count($model_map) . ' opted in)</span></label>';
echo '<p class="text-muted small mb-2">Models this recipe can query via <code>query_model</code>. '
   . 'Field schemas for the selected models are added to the system prompt automatically. '
   . 'Models with a <code>$ai_writable_fields</code> allowlist also become writable via '
   . '<code>create_model</code> / <code>update_model</code> / <code>delete_model</code>.</p>';
if (empty($model_map)) {
    echo '<p class="text-muted">No models are opted into AI reads. Add '
       . '<code>public static $ai_readable = true;</code> to a data class.</p>';
} else {
    foreach ($model_map as $class_name => $info) {
        $checked = in_array($class_name, $selected_models, true) ? ' checked' : '';
        $desc = htmlspecialchars($info['description'] ?? '');
        $writable = !empty($info['writable_fields']);
        echo '<div class="form-check">';
        echo '<input class="form-check-input" type="checkbox" '
           . 'name="rcp_allowed_models[]" '
           . 'value="' . htmlspecialchars($class_name) . '" '
           . 'id="model_' . htmlspecialchars($class_name) . '"' . $checked . '>';
        echo '<label class="form-check-label" for="model_' . htmlspecialchars($class_name) . '">';
        echo '<strong>' . htmlspecialchars($class_name) . '</strong>';
        if ($writable) {
            echo ' <span class="badge bg-warning text-dark">writable</span>';
        }
        if ($desc !== '') echo '<br><small class="text-muted">' . $desc . '</small>';
        if (!empty($model_to_actions[$class_name])) {
            echo '<br><small class="text-muted">Wrapped by: <code>'
               . htmlspecialchars(implode(', ', $model_to_actions[$class_name]))
               . '</code> (consider granting the action instead of, or alongside, '
               . 'direct write).</small>';
        }
        echo '</label></div>';
    }
}
// Stale model references — entries persisted but no longer resolving.
$stale_models = array_values(array_diff($selected_models, array_keys($model_map)));
if (!empty($stale_models)) {
    echo '<div class="alert alert-warning mt-2 mb-0 py-2">'
       . '<strong>Stale references:</strong> '
       . htmlspecialchars(implode(', ', $stale_models))
       . '. These models no longer exist; they\'ll be dropped on save.</div>';
}
echo '</div>';

// Allowed actions — checkboxes against the live ActionRegistry. Recipes
// can only invoke actions that are explicitly checked here.
$selected_actions = $recipe->get('rcp_allowed_actions');
if (is_string($selected_actions)) {
    $decoded = json_decode($selected_actions, true);
    $selected_actions = is_array($decoded) ? $decoded : [];
}
if (!is_array($selected_actions)) $selected_actions = [];

echo '<div class="form-group mb-3">';
echo '<label class="form-label">Allowed Actions <span class="text-muted small">('
   . count($action_map) . ' registered)</span></label>';
echo '<p class="text-muted small mb-2">Actions (logic-file calls) this recipe can invoke '
   . 'via <code>invoke_action</code>. Each action runs the full validation gauntlet — '
   . 'cross-record invariants, hooks, and external system calls. Enable '
   . '<code>invoke_action</code> in Allowed Tools above to make these reachable.</p>';
if (empty($action_map)) {
    echo '<p class="text-muted">No actions registered. Add a '
       . '<code>{action}_logic_descriptor()</code> function to a logic file to expose it.</p>';
} else {
    foreach ($action_map as $action_name => $info) {
        $checked = in_array($action_name, $selected_actions, true) ? ' checked' : '';
        $d = $info['descriptor'];
        $desc = htmlspecialchars($d['description'] ?? '');
        $mutates = !empty($d['mutates']);
        echo '<div class="form-check">';
        echo '<input class="form-check-input" type="checkbox" '
           . 'name="rcp_allowed_actions[]" '
           . 'value="' . htmlspecialchars($action_name) . '" '
           . 'id="action_' . htmlspecialchars($action_name) . '"' . $checked . '>';
        echo '<label class="form-check-label" for="action_' . htmlspecialchars($action_name) . '">';
        echo '<strong>' . htmlspecialchars($action_name) . '</strong>';
        if ($mutates) {
            echo ' <span class="badge bg-warning text-dark">mutates</span>';
        } else {
            echo ' <span class="badge bg-secondary">read-only</span>';
        }
        if ($desc !== '') echo '<br><small class="text-muted">' . $desc . '</small>';
        if (!empty($action_to_models[$action_name])) {
            echo '<br><small class="text-muted">Mutates: <code>'
               . htmlspecialchars(implode(', ', $action_to_models[$action_name]))
               . '</code></small>';
        }
        echo '</label></div>';
    }
}
$stale_actions = array_values(array_diff($selected_actions, array_keys($action_map)));
if (!empty($stale_actions)) {
    echo '<div class="alert alert-warning mt-2 mb-0 py-2">'
       . '<strong>Stale references:</strong> '
       . htmlspecialchars(implode(', ', $stale_actions))
       . '. These actions no longer exist; they\'ll be dropped on save.</div>';
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

// --- Security ---
$formwriter->checkboxinput('rcp_allow_tainted_writes', 'Allow tainted writes', [
    'value' => 1,
    'checked' => (bool)$recipe->get('rcp_allow_tainted_writes'),
    'help_text' => 'Required if this recipe can perform writes AND either reads '
                 . 'user-generated text (fields marked $ai_untrusted_fields) or '
                 . 'carries LLM-curated state across runs (non-empty workspace). '
                 . 'Confirms the prompt is robust to injection from those sources. '
                 . 'Saving a tainted-capable recipe without this flag is rejected.',
]);

// --- Workspace (advanced) ---
$formwriter->textarea('rcp_workspace', 'Workspace (LLM-curated; edit only when debugging)', [
    'rows' => 8,
]);

$formwriter->submitbutton('btn_submit', $is_new ? 'Create' : 'Save');

if (!$is_new) {
    $formwriter->submitbutton('btn_delete', 'Delete', [
        'class'          => 'btn btn-outline-danger ms-2',
        'onclick'        => "return confirm('Soft-delete this recipe?');",
        'formnovalidate' => true,
    ]);
}

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
