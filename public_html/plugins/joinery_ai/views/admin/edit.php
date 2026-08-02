<?php
/**
 * Joinery AI - Recipe Edit
 * URL: /admin/joinery_ai/edit
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/logic/admin_edit_logic.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));

$page_vars = process_logic(admin_joinery_ai_edit_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$is_new = !$recipe->key;

// Present on the render path; absent when the logic returned an error, so fall
// back rather than letting the ship control disappear mid-correction.
$is_upgrade_server = $is_upgrade_server ?? DeploymentHelper::isUpgradeServer();
$declared_key = (string)$recipe->get('rcp_declared_key');

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

if (!empty($shipped_key)) {
    echo '<div class="alert alert-success">Written to <code>plugins/joinery_ai/recipes.json</code> as <code>'
       . htmlspecialchars($shipped_key) . '</code>. Commit the file to release it.</div>';
}

// A recipe that arrived with the install and hasn't been set up yet. Says so
// plainly, because a disabled seeded recipe and a recipe someone switched off
// look identical and mean opposite things.
if ($declared_key !== '' && !$recipe->get('rcp_enabled')
        && empty(Recipe::decodeSourceConfig($recipe))) {
    echo '<div class="alert alert-info">This recipe shipped with your install and is not set up yet. '
       . 'Choose the mailbox it should work on, pick a model, then enable it.</div>';
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

// --- Mode ---
// Agent: the model drives via a tool loop, one conversation per run.
// Pipeline: PHP drives item selection; the model judges one item per bounded
// exchange.
//
// Settled at creation and shown as a fact afterwards. Mode and job together
// decide what a recipe's stored history MEANS: aip_recipe_item_log is unique on
// (recipe, item_key) and item keys are job-scoped, so changing either on a saved
// recipe silently reinterprets every item it has already processed against a
// different namespace — the new job would treat them all as done and quietly do
// nothing. Changing shape is deliberate: make a new recipe.
$mode_value = (string)$recipe->get('rcp_mode') ?: Recipe::MODE_AGENT;
$mode_labels = [
    Recipe::MODE_AGENT    => 'Agent — model drives a tool loop',
    Recipe::MODE_PIPELINE => 'Pipeline — PHP drives, one item judged per exchange',
];
$shape_locked = !$is_new;
$is_pipeline  = $mode_value === Recipe::MODE_PIPELINE;

if ($shape_locked) {
    echo '<div class="form-group mb-3">';
    echo '<label class="form-label">Mode</label>';
    echo '<p class="mb-0">' . htmlspecialchars($mode_labels[$mode_value] ?? $mode_value) . '</p>';
    echo '<p class="text-muted small mb-0">Fixed when the recipe was created. To run a different '
       . 'kind of work, create a new recipe.</p>';
    echo '<input type="hidden" name="rcp_mode" value="' . htmlspecialchars($mode_value) . '">';
    echo '</div>';
} else {
    // Switching the dropdown swaps which field group below applies — see the
    // two _group containers further down.
    $formwriter->dropinput('rcp_mode', 'Mode', [
        'value' => $mode_value,
        'options' => $mode_labels,
        'visibility_rules' => [
            Recipe::MODE_AGENT    => ['show' => ['rcp_agent_fields_group'], 'hide' => ['rcp_pipeline_fields_group']],
            Recipe::MODE_PIPELINE => ['show' => ['rcp_pipeline_fields_group'], 'hide' => ['rcp_agent_fields_group']],
        ],
    ]);
}

// --- Prompt ---
// A pipeline job ships its own instructions and rcp_prompt only overrides them,
// so an empty box means "use the job's", not "nothing configured". Show the real
// text; Customize turns it into an editable copy. Both halves are rendered and
// the script at the foot of the form decides which one applies, because mode and
// job can both change without a round trip.
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
$job_default_prompts = [];
foreach (PipelineJobRegistry::all() as $jp_id => $jp_class) {
    $job_default_prompts[$jp_id] = (new $jp_class())->defaultPrompt();
}
$prompt_is_custom = trim((string)$recipe->get('rcp_prompt')) !== '';

echo '<div class="form-group mb-3" id="rcp_prompt_builtin_wrap" style="display:none">';
echo '<label class="form-label">Prompt</label>';
echo '<p class="text-muted small mb-2">The instructions this job runs. They ship with the platform '
   . 'and improve with each upgrade.</p>';
echo '<pre class="joai-prompt-builtin" id="rcp_prompt_builtin_text"></pre>';
echo '<button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="rcp_prompt_customize">'
   . 'Customize</button>';
echo '</div>';

echo '<div id="rcp_prompt_edit_wrap">';
$formwriter->textarea('rcp_prompt', 'Prompt', [
    'rows' => 12,
    'placeholder' => 'Describe what the recipe should do, what tools to use, what to deliver.',
    'helptext' => 'Your wording replaces the job\'s built-in instructions entirely — and stops '
                . 'tracking the improvements later upgrades make to them.',
]);
echo '<button type="button" class="btn btn-sm btn-link ps-0" id="rcp_prompt_revert" style="display:none">'
   . 'Use the built-in instructions instead</button>';
echo '</div>';

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
// served by a configured provider (its provider isn't set up), append it
// flagged so the value is preserved and the mismatch is visible rather than
// silently overwritten on save.
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));
try {
    // The model selects the provider, so offer every model from every
    // configured provider — not just the global-default one.
    $model_options = LlmProviderFactory::allModels();
} catch (LlmProviderException $e) {
    // No provider configured yet. Don't crash the edit page — fall back to no
    // preset options; the stored model is still preserved below.
    $model_options = [];
}
$stored_model = (string)$recipe->get('rcp_model');
// A shipped recipe carries no model — the publishing instance's choice means
// nothing here — so resolve the site's own default at the moment it's edited.
if ($stored_model === '') {
    $stored_model = joinery_ai_default_recipe_model($settings);
}
if ($stored_model !== '' && !isset($model_options[$stored_model])) {
    $model_options[$stored_model] = "$stored_model — unavailable (its provider isn't configured)";
}

$formwriter->dropinput('rcp_model', 'Model', [
    'value'   => $stored_model,
    'options' => $model_options,
]);

$rcp_temp = $recipe->get('rcp_temperature');
$formwriter->numberinput('rcp_temperature', 'Temperature', [
    'value' => ($rcp_temp === null ? '' : $rcp_temp),
    'min' => 0, 'max' => 2, 'step' => '0.1',
    'placeholder' => $settings->get_setting('joinery_ai_default_temperature'),
    'helptext' => 'How creative vs. focused the wording is. Blank = the global default.',
]);

$rcp_top_p = $recipe->get('rcp_top_p');
$formwriter->numberinput('rcp_top_p', 'Top-p', [
    'value' => ($rcp_top_p === null ? '' : $rcp_top_p),
    'min' => 0, 'max' => 1, 'step' => '0.05',
    'placeholder' => ($settings->get_setting('joinery_ai_default_top_p') ?: 'default'),
    'helptext' => 'Nucleus-sampling cutoff. Blank = the global default.',
]);

$formwriter->dropinput('rcp_thinking_level', 'Thinking Level', [
    'value' => (string)$recipe->get('rcp_thinking_level') ?: 'off',
    'options' => ['off' => 'Off', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],
    'helptext' => 'How hard the model reasons before answering. Off skips the reasoning pass (fastest).',
]);

// --- Agent-mode fields (tools/models/actions/workspace) ---
// Unused in pipeline mode — a pipeline recipe's only allow-list entry is its
// job (below); the processing log is its only carried state, so there's no
// workspace-poisoning surface to configure.
// With the mode locked there is no dropdown to fire the visibility rules, so the
// group that does not apply is hidden here instead of by script.
echo '<div id="rcp_agent_fields_group_container"'
   . ($shape_locked && $is_pipeline ? ' style="display:none"' : '') . '>';

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
    $hidden_actions = 0;
    foreach ($action_map as $action_name => $info) {
        $d = $info['descriptor'];
        // Only agent-exposed actions (descriptor declares ai_agent) are
        // selectable — anything else would be refused at invoke time.
        if (!ActionRegistry::isAgentCallable($d)) {
            $hidden_actions++;
            continue;
        }
        $checked = in_array($action_name, $selected_actions, true) ? ' checked' : '';
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
    if ($hidden_actions > 0) {
        echo '<p class="text-muted small mt-2 mb-0">' . (int)$hidden_actions
           . ' registered action' . ($hidden_actions === 1 ? '' : 's')
           . ' not shown — not exposed to the AI agent. Add '
           . '<code>ai_agent</code> to the descriptor to make one selectable.</p>';
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

// Workspace (advanced) — LLM-curated state carried between agent-mode runs.
$formwriter->textarea('rcp_workspace', 'Workspace (LLM-curated; edit only when debugging)', [
    'rows' => 8,
]);

echo '</div>'; // #rcp_agent_fields_group_container

// --- Pipeline-mode fields (job + its config) ---
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
require_once(PathHelper::getIncludePath('includes/DescriptorValidator.php'));

echo '<div id="rcp_pipeline_fields_group_container"'
   . ($shape_locked && !$is_pipeline ? ' style="display:none"' : '') . '>';

$job_registry = PipelineJobRegistry::all();
ksort($job_registry);
$selected_job_id = (string)$recipe->get('rcp_pipeline_job');
$stored_source_config = Recipe::decodeSourceConfig($recipe);

$job_options = ['' => '— select a job —'];
$job_visibility_rules = ['' => ['show' => [], 'hide' => []]];
foreach ($job_registry as $job_id => $job_class) {
    /** @var PipelineJobInterface $job_instance */
    $job_instance = new $job_class();
    $job_options[$job_id] = $job_instance->label();
    $group = "job_config_{$job_id}_group";
    $others = array_values(array_diff(
        array_map(fn($id) => "job_config_{$id}_group", array_keys($job_registry)),
        [$group]
    ));
    $job_visibility_rules[$job_id] = ['show' => [$group], 'hide' => $others];
    // Also hide this job's group under every OTHER selection (including '').
    foreach ($job_visibility_rules as $val => &$rule) {
        if ($val === $job_id) continue;
        $rule['hide'][] = $group;
    }
    unset($rule);
}

if (empty($job_registry)) {
    echo '<p class="text-muted">No pipeline jobs registered. Drop a class implementing '
       . '<code>PipelineJobInterface</code> into <code>plugins/&lt;plugin&gt;/pipeline_jobs/</code>.</p>';
} else {
    if ($shape_locked && isset($job_registry[$selected_job_id])) {
        // Settled, for the item-log reason given at the Mode field above.
        echo '<div class="form-group mb-3">';
        echo '<label class="form-label">Job</label>';
        echo '<p class="mb-0">' . htmlspecialchars($job_options[$selected_job_id]) . '</p>';
        echo '<p class="text-muted small mb-0">What this recipe does. Fixed when it was created — '
           . 'the work already recorded against it is only meaningful for this job.</p>';
        echo '<input type="hidden" name="rcp_pipeline_job" value="'
           . htmlspecialchars($selected_job_id) . '">';
        echo '</div>';
    } else {
        $formwriter->dropinput('rcp_pipeline_job', 'Job', [
            'value' => $selected_job_id,
            'options' => $job_options,
            'visibility_rules' => $job_visibility_rules,
        ]);
    }

    // Per-job config fields, field-name-prefixed so two jobs sharing a field
    // name (e.g. "mailbox_alias") can't collide in $_POST — only the
    // selected job's prefix is read back on save (admin_edit_logic.php).
    // A settled recipe renders only its own job's fields; nothing can switch to
    // another job's group, so rendering them would just be dead hidden inputs.
    foreach ($job_registry as $job_id => $job_class) {
        if ($shape_locked && isset($job_registry[$selected_job_id]) && $job_id !== $selected_job_id) continue;
        $job_instance = new $job_class();
        echo '<div id="job_config_' . htmlspecialchars($job_id) . '_group_container">';
        $descriptor = $job_instance->configDescriptor();
        $inputs = $descriptor['input'] ?? $descriptor;
        $prefixed = ['input' => []];
        foreach ($inputs as $field => $spec) {
            if (!is_array($spec)) continue;
            $spec['value'] = $spec['value']
                ?? (($job_id === $selected_job_id && array_key_exists($field, $stored_source_config))
                    ? $stored_source_config[$field] : null);
            $prefixed['input']["srccfg_{$job_id}_{$field}"] = $spec;
        }
        $formwriter->fromDescriptor($prefixed);
        echo '</div>';
    }
}

echo '</div>'; // #rcp_pipeline_fields_group_container

// --- Delivery ---
$formwriter->textinput('rcp_delivery_email', 'Delivery Email (blank = owner email)');

$formwriter->checkboxinput('rcp_delivery_dashboard', 'Show its latest result on the AI dashboard', [
    'value' => 1,
    'checked' => (bool)$recipe->get('rcp_delivery_dashboard'),
    'helptext' => 'Adds a card for this recipe to /joinery_ai showing the output of its most '
                 . 'recent successful run. That page is admin-only. Off means the recipe still '
                 . 'runs; its results just are not shown there.',
]);

$formwriter->checkboxinput('rcp_enabled', 'Run automatically', [
    'value' => 1,
    'checked' => (bool)$recipe->get('rcp_enabled'),
    'helptext' => 'On, the recipe runs by itself — on the schedule above, or while your vault is '
                 . 'unlocked for a job that reads encrypted mail. Off, it only runs when you press '
                 . 'Run Now, and turning it off also stops any run already in progress.',
]);

// --- Limits ---
$formwriter->numberinput('rcp_max_iterations', 'Max Tool-Loop Iterations / Batch Size', [
    'min' => 1, 'max' => 50,
    'helptext' => 'Agent mode: max tool-loop iterations. Pipeline mode: max items processed per run.',
]);
$formwriter->numberinput('rcp_max_tokens', 'Max Tokens Per Run', ['min' => 1000, 'max' => 200000]);
$formwriter->numberinput('rcp_monthly_token_cap', 'Monthly Token Cap', ['min' => 0]);

// --- Security ---
// The old label ("Allow tainted writes") named the mechanism, so it told an
// admin nothing about what they were agreeing to. The badge above the checkbox
// answers the first question people actually ask — does this recipe even need
// it? — and mirrors TaintGate exactly (see the script at the foot of the form).
echo '<div id="rcp_taint_state" class="mb-2"></div>';

$formwriter->checkboxinput('rcp_allow_tainted_writes',
    'Let this recipe act on content written by other people', [
    'value' => 1,
    'checked' => (bool)$recipe->get('rcp_allow_tainted_writes'),
    'helptext' => 'Some of what this recipe reads was written by someone else — the body of an '
                 . 'email, a message a member submitted — and anyone can put text in there aimed '
                 . 'at the AI, telling it to do something you did not ask for. Ticking this says '
                 . 'you accept that risk for what this recipe is allowed to change. Recipes that '
                 . 'judge one item at a time can only ever write one fixed field on the item they '
                 . 'were shown, so the worst case is a wrong label or summary on one message. A '
                 . 'recipe with write tools is broader: read what it can reach before agreeing.',
]);

$formwriter->submitbutton('btn_submit', $is_new ? 'Create' : 'Save');

// Ship with new installs — absent, not disabled, on an instance that consumes
// upgrades: there recipes.json is replaced wholesale by the next upgrade, so
// this isn't something the operator is missing out on.
if (!$is_new && $is_upgrade_server) {
    $formwriter->submitbutton('btn_ship_template', 'Ship with new installs', [
        'class'          => 'btn btn-outline-secondary ms-2',
        'onclick'        => "return confirm('Write this recipe into recipes.json so every new install gets it? "
                          . "The owner, mailbox, model, enabled flag and tainted-writes flag are not included.');",
        'formnovalidate' => true,
    ]);
}

if (!$is_new) {
    $formwriter->submitbutton('btn_delete', 'Delete', [
        'class'          => 'btn btn-outline-danger ms-2',
        'onclick'        => "return confirm('Soft-delete this recipe?');",
        'formnovalidate' => true,
    ]);
}

if (!$is_new && $is_upgrade_server) {
    echo '<p class="text-muted small mt-2 mb-0">Shipping writes <code>plugins/joinery_ai/recipes.json</code>, '
       . 'which is under version control — review the diff and commit it to release the change. '
       . 'A declaration creates the recipe once on each install and never edits or restores it afterwards.</p>';
}

echo $formwriter->end_form();

// Inputs for the live "is this needed?" badge. Emitted from the same sources
// TaintGate reads, so the badge cannot drift from the rule it describes.
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelWriteExecutor.php'));
$taint_untrusted_models = [];
foreach (ModelRegistry::all() as $tm_class => $tm_info) {
    if (!empty($tm_info['untrusted_fields'])) $taint_untrusted_models[] = $tm_class;
}
$taint_untrusted_jobs = [];
foreach (PipelineJobRegistry::all() as $tj_id => $tj_class) {
    $taint_untrusted_jobs[$tj_id] = (new $tj_class())->untrustedDigest();
}
?>
<script>
(function () {
    // Built-in instructions per pipeline job. The job select can change without a
    // round trip, so the text is swapped client-side rather than rendered once.
    // JSON_HEX_TAG: this lands inside a <script> block, so a prompt containing
    // </script> would otherwise close it and spill the rest as markup.
    var PROMPTS = <?php echo json_encode($job_default_prompts,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var PIPELINE = <?php echo json_encode(Recipe::MODE_PIPELINE); ?>;

    var modeEl    = document.querySelector('[name="rcp_mode"]');
    var jobEl     = document.querySelector('[name="rcp_pipeline_job"]');
    var promptEl  = document.querySelector('[name="rcp_prompt"]');
    var readWrap  = document.getElementById('rcp_prompt_builtin_wrap');
    var readText  = document.getElementById('rcp_prompt_builtin_text');
    var editWrap  = document.getElementById('rcp_prompt_edit_wrap');
    var customBtn = document.getElementById('rcp_prompt_customize');
    var revertBtn = document.getElementById('rcp_prompt_revert');
    if (!promptEl || !readWrap || !editWrap) return;

    // A non-empty rcp_prompt IS the customization — no separate flag to persist.
    var customized = <?php echo $prompt_is_custom ? 'true' : 'false'; ?>;

    function builtinFor() {
        var mode = modeEl ? modeEl.value : '';
        var job  = jobEl ? jobEl.value : '';
        if (mode !== PIPELINE || !job) return null;
        return Object.prototype.hasOwnProperty.call(PROMPTS, job) ? PROMPTS[job] : null;
    }

    function sync() {
        var builtin = builtinFor();
        var showRead = (builtin !== null && !customized);
        readWrap.style.display = showRead ? '' : 'none';
        editWrap.style.display = showRead ? 'none' : '';
        if (showRead) readText.textContent = builtin;
        if (revertBtn) revertBtn.style.display = (!showRead && builtin !== null) ? '' : 'none';
    }

    if (customBtn) {
        customBtn.addEventListener('click', function () {
            // Start from the built-in text rather than a blank box: customizing
            // usually means adjusting a line, not writing from scratch.
            var builtin = builtinFor();
            if (builtin !== null && promptEl.value.trim() === '') promptEl.value = builtin;
            customized = true;
            sync();
            promptEl.focus();
        });
    }

    if (revertBtn) {
        revertBtn.addEventListener('click', function () {
            var builtin = builtinFor();
            var edited = promptEl.value.trim() !== '' && promptEl.value !== builtin;
            var revert = function () { promptEl.value = ''; customized = false; sync(); };
            if (!edited) { revert(); return; }
            var msg = 'Discard your prompt and go back to the built-in instructions?';
            if (window.JoineryModal && JoineryModal.confirm) JoineryModal.confirm(msg, revert);
            else if (window.confirm(msg)) revert();
        });
    }

    if (modeEl) modeEl.addEventListener('change', sync);
    if (jobEl)  jobEl.addEventListener('change', sync);
    sync();
})();

(function () {
    // Live mirror of TaintGate::evaluate(). Answers "does this recipe even need
    // that permission?" before the admin has to reason about the checkbox.
    var WRITE_TOOLS      = <?php echo json_encode(ModelWriteExecutor::WRITE_TOOL_NAMES); ?>;
    var UNTRUSTED_MODELS = <?php echo json_encode($taint_untrusted_models); ?>;
    var UNTRUSTED_JOBS   = <?php echo json_encode((object)$taint_untrusted_jobs); ?>;
    var PIPELINE         = <?php echo json_encode(Recipe::MODE_PIPELINE); ?>;

    var box   = document.getElementById('rcp_taint_state');
    var check = document.querySelector('[name="rcp_allow_tainted_writes"]');
    var modeEl = document.querySelector('[name="rcp_mode"]');
    var jobEl  = document.querySelector('[name="rcp_pipeline_job"]');
    var wsEl   = document.querySelector('[name="rcp_workspace"]');
    if (!box || !check) return;

    function checkedValues(name) {
        return Array.prototype.map.call(
            document.querySelectorAll('input[name="' + name + '"]:checked'),
            function (el) { return el.value; });
    }

    function evaluate() {
        var mode = modeEl ? modeEl.value : '';
        if (mode === PIPELINE) {
            var job = jobEl ? jobEl.value : '';
            if (job && UNTRUSTED_JOBS[job]) {
                return { required: true, why: 'This job reads text written by whoever sent the item.' };
            }
            return { required: false, why: 'This job only reads content you control.' };
        }
        var tools = checkedValues('rcp_allowed_tools[]').filter(function (t) {
            return WRITE_TOOLS.indexOf(t) !== -1; });
        if (!tools.length) {
            return { required: false, why: 'This recipe cannot change anything — it has no write tools.' };
        }
        var models = checkedValues('rcp_allowed_models[]').filter(function (m) {
            return UNTRUSTED_MODELS.indexOf(m) !== -1; });
        var ws = wsEl && wsEl.value.trim() !== '';
        if (models.length) {
            return { required: true, why: 'It can write (' + tools.join(', ') + ') and reads records '
                + 'holding text other people wrote: ' + models.join(', ') + '.' };
        }
        if (ws) {
            return { required: true, why: 'It can write (' + tools.join(', ') + ') and carries notes '
                + 'the AI wrote to itself on earlier runs.' };
        }
        return { required: false, why: 'Nothing it reads was written by anyone else.' };
    }

    function render() {
        var state = evaluate();
        var cls, text;
        if (!state.required) {
            cls = 'alert alert-secondary py-2 mb-2';
            text = '<strong>Not needed for this recipe.</strong> ' + state.why
                 + ' Leaving the box below ticked does no harm; it just does not apply.';
        } else if (check.checked) {
            cls = 'alert alert-success py-2 mb-2';
            text = '<strong>Needed, and you have allowed it.</strong> ' + state.why;
        } else {
            cls = 'alert alert-warning py-2 mb-2';
            text = '<strong>Needed before this recipe can run.</strong> ' + state.why
                 + ' Saving is refused until you tick the box below.';
        }
        box.className = cls;
        box.innerHTML = text;
    }

    document.addEventListener('change', function (e) {
        if (!e.target || !e.target.name) return;
        if (['rcp_mode', 'rcp_pipeline_job', 'rcp_allowed_tools[]', 'rcp_allowed_models[]',
             'rcp_workspace', 'rcp_allow_tainted_writes'].indexOf(e.target.name) !== -1) render();
    });
    if (wsEl) wsEl.addEventListener('input', render);
    render();
})();
</script>
<?php

$page->end_box();
$page->admin_footer();
