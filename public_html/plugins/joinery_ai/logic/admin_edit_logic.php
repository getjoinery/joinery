<?php

function admin_joinery_ai_edit_logic(array $input): LogicResult {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelRegistry.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ActionRegistry.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/TaintGate.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobRegistry.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeSchedule.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(10);

    // Resolve which recipe we're editing (or that we're creating new)
    if (isset($input['edit_primary_key_value']) && $input['edit_primary_key_value']) {
        $recipe = new Recipe($input['edit_primary_key_value'], TRUE);
    } elseif (isset($input['rcp_recipe_id']) && $input['rcp_recipe_id']) {
        $recipe = new Recipe($input['rcp_recipe_id'], TRUE);
    } else {
        $recipe = new Recipe(NULL);
        // Pre-fill defaults so the form shows sensible values for a new recipe.
        // Default time of '07:00:00' is meant as the admin's local time, but
        // the column stores UTC, so convert before set.
        $settings = Globalvars::get_instance();
        // Use today as the DST-reference date so save and display stay in sync
        // (the platform's display path uses today's DateTime). 2000-01-01 would
        // anchor in winter and drift by 1h in summer.
        $default_local_time = '07:00:00';
        $today = gmdate('Y-m-d');
        $default_utc_time = LibraryFunctions::convert_time(
            $today . ' ' . $default_local_time, $session->get_timezone(), 'UTC', 'H:i:s'
        );
        // A new recipe arrives on MANUALLY ONLY (rcp_enabled false), the same
        // posture a seeded one arrives in: running something by itself is a
        // choice someone makes, not a default they have to notice and undo.
        // The frequency/day/time below are prefills for the moment they do —
        // picking Daily or Weekly then lands on Monday 07:00 local rather than
        // on midnight UTC.
        $recipe->set('rcp_schedule_frequency', 'weekly');
        $recipe->set('rcp_schedule_day_of_week', 1);
        $recipe->set('rcp_schedule_time', $default_utc_time);
        $recipe->set('rcp_delivery_dashboard', true);
        $recipe->set('rcp_enabled', false);
        // Prefill from the field spec so the form shows the same defaults
        // save() would apply — the spec is the single source for these.
        $recipe->set('rcp_max_iterations', Recipe::$field_specifications['rcp_max_iterations']['default']);
        $recipe->set('rcp_max_tokens', Recipe::$field_specifications['rcp_max_tokens']['default']);
        $recipe->set('rcp_monthly_token_cap', Recipe::$field_specifications['rcp_monthly_token_cap']['default']);
    }

    // joinery-validate.js calls form.submit() directly, which strips the
    // submitter button — so isset($input['btn_submit']) is unreliable.
    // POST is the only signal we need on this admin-only page.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

        // Soft delete handler
        if (isset($input['btn_delete']) && $recipe->key) {
            $recipe->soft_delete();
            return LogicResult::redirect('/admin/joinery_ai');
        }

        // A saved recipe's SHAPE is fixed: its mode, and for a pipeline recipe
        // its job. The editor renders both as static text once saved, but that
        // is presentation — the guarantee has to hold against the posted value,
        // because an edited hidden input is all it takes otherwise.
        //
        // What flipping the job would do is not cosmetic. The mail jobs take
        // the same `mailbox_aliases` config, so validation passes, and
        // aip_recipe_item_log is keyed per job — repoint a triage recipe at the
        // security scan and every already-triaged message reads as already
        // scanned, so the scan silently processes nothing. Flipping pipeline to
        // agent additionally leaves the sealed-source cloud gate, which only
        // examines pipeline recipes.
        //
        // Make a different recipe instead; that is what the shape describes.
        if ($recipe->key) {
            foreach (['rcp_mode' => 'mode', 'rcp_pipeline_job' => 'job'] as $field => $label) {
                if (!array_key_exists($field, $input)) continue;
                $posted = trim((string)$input[$field]);
                $stored = trim((string)$recipe->get($field));
                if ($posted !== $stored) {
                    return LogicResult::error(
                        'A saved recipe cannot change its ' . $label . '. Create a new recipe instead.',
                        ['recipe' => $recipe, 'session' => $session]
                    );
                }
            }
        }

        // Shape fields are writable at CREATE only. On a saved recipe the guard
        // above gives feedback, but the stored value must not depend on it — a
        // posted value never reaches set() here, so neither a differing nor an
        // empty rcp_mode/rcp_pipeline_job can alter a saved recipe's shape.
        $shape_fields = $recipe->key ? [] : ['rcp_mode', 'rcp_pipeline_job'];

        $simple_fields = array_merge($shape_fields, [
            'rcp_name',
            'rcp_prompt',
            'rcp_schedule_day_of_week',
            'rcp_model',
            'rcp_min_tier',
            'rcp_trust_floor',
            'rcp_min_context',
            'rcp_temperature',
            'rcp_top_p',
            'rcp_thinking_level',
            'rcp_delivery_email',
            'rcp_max_iterations',
            'rcp_max_tokens',
            'rcp_monthly_token_cap',
            'rcp_workspace',
        ]);
        // Blank means INHERIT, not zero. The requirement columns are overrides:
        // NULL is how a recipe says "whatever my job asks for", and writing a
        // materialised value would freeze the floor at save time and stop a
        // later release from ever raising it.
        $null_when_blank = ['rcp_schedule_day_of_week', 'rcp_temperature', 'rcp_top_p',
            'rcp_min_tier', 'rcp_trust_floor', 'rcp_min_context'];
        foreach ($simple_fields as $f) {
            if (array_key_exists($f, $input)) {
                $value = $input[$f];
                if (in_array($f, $null_when_blank, true) && $value === '') {
                    $value = null;
                }
                $recipe->set($f, $value);
            }
        }

        // The timeinput widget posts a normalized 24h "HH:MM" string in the
        // admin's local timezone via its hidden input (kept in sync by the
        // shared outputTimeInputJavaScript handler). Convert to UTC for storage.
        $time_local = trim($input['rcp_schedule_time'] ?? '');
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time_local)) {
            if (substr_count($time_local, ':') === 1) $time_local .= ':00';
            $today = gmdate('Y-m-d');
            $utc_time = LibraryFunctions::convert_time(
                $today . ' ' . $time_local, $session->get_timezone(), 'UTC', 'H:i:s'
            );
            $recipe->set('rcp_schedule_time', $utc_time);
        } else {
            $recipe->set('rcp_schedule_time', null);
        }

        // Checkboxes — absent = false. rcp_enabled is NOT one: it is written by
        // the Runs control below, which owns the manual/automatic decision.
        $recipe->set('rcp_delivery_dashboard', !empty($input['rcp_delivery_dashboard']));

        // Allowed tools — checkboxes post as `rcp_allowed_tools[]`. Absent
        // means no tools selected. query_model is never user-facing here;
        // the runner derives it from rcp_allowed_models. Strip it defensively
        // so old recipes don't carry forward the now-redundant entry.
        $tools_post = $input['rcp_allowed_tools'] ?? [];
        if (!is_array($tools_post)) $tools_post = [];
        $tool_list = array_values(array_filter(array_map('strval', $tools_post), 'strlen'));
        $tool_list = array_values(array_filter($tool_list, fn($t) => $t !== 'query_model'));
        $recipe->set('rcp_allowed_tools', $tool_list);

        // Allowed models — same pattern as tools. Filter out stale references
        // (model classes that no longer resolve) on save so the persisted
        // list stays clean. The run-start staleness check is the safety net;
        // this is the convenience cleanup at the moment the admin is editing.
        $models_post = $input['rcp_allowed_models'] ?? [];
        if (!is_array($models_post)) $models_post = [];
        $model_list = array_values(array_filter(array_map('strval', $models_post), 'strlen'));
        $live_models = ModelRegistry::all();
        $model_list = array_values(array_filter($model_list, fn($m) => isset($live_models[$m])));
        $recipe->set('rcp_allowed_models', $model_list);

        // Allowed actions — same pattern. Strip entries that aren't live AND
        // agent-exposed; a non-exposed action would only be refused at invoke
        // time, so it has no business on the allow-list.
        $actions_post = $input['rcp_allowed_actions'] ?? [];
        if (!is_array($actions_post)) $actions_post = [];
        $action_list = array_values(array_filter(array_map('strval', $actions_post), 'strlen'));
        $live_actions = ActionRegistry::all();
        $action_list = array_values(array_filter($action_list, fn($a) =>
            isset($live_actions[$a]) && ActionRegistry::isAgentCallable($live_actions[$a]['descriptor'])));
        $recipe->set('rcp_allowed_actions', $action_list);

        // Pipeline source config — only the selected job's own prefixed
        // fields are read back (see views/admin/edit.php for the
        // srccfg_{job_id}_{field} naming); other jobs' hidden fields, even if
        // present in the POST body, are never mistaken for this job's config.
        $pipeline_job_id = (string)($input['rcp_pipeline_job'] ?? '');
        $pipeline_job = $pipeline_job_id !== '' ? PipelineJobRegistry::get($pipeline_job_id) : null;
        $source_config = [];
        if ($pipeline_job !== null) {
            foreach ($pipeline_job->configDescriptor()['input'] ?? [] as $field => $spec) {
                $posted_name = "srccfg_{$pipeline_job_id}_{$field}";
                if (array_key_exists($posted_name, $input)) {
                    $source_config[$field] = $input[$posted_name];
                }
            }
        }
        $recipe->set('rcp_source_config', $source_config);

        // --- Runs: the one scheduling control ---------------------------------
        //
        // The form asks one question — when should this run by itself — and the
        // answer lands in two columns. `rcp_enabled` is the manual/automatic
        // bit, load-bearing far beyond this page (the dispatcher and the drain
        // filter on it, the pending reaper reads it, the AI panel refuses a
        // toggle while it is false), so Manually only IS enabled false, and
        // switching TO it still cancels queued and in-flight runs (below).
        // Any other answer is an automatic one and names its frequency.
        //
        // The stored frequency is deliberately left alone on Manually only: it
        // is what the day/time fields prefill from when someone picks a clock
        // option again. The retired value 'none' is never written back.
        $ran_automatically_before = (bool)$recipe->get('rcp_enabled');
        $runs = trim((string)($input['rcp_runs'] ?? ''));
        if ($runs !== '') {
            if ($runs === RecipeSchedule::FREQ_MANUAL) {
                $recipe->set('rcp_enabled', false);
            } else {
                if ($runs === RecipeSchedule::FREQ_ARRIVAL) {
                    // A job switch must not be able to strand a value that means
                    // nothing: only a job with an arrival concept can offer it.
                    $arrival_label = null;
                    if ($pipeline_job !== null) {
                        try { $arrival_label = $pipeline_job->arrivalLabel(); } catch (Throwable $e) {}
                    }
                    if ($arrival_label === null || trim((string)$arrival_label) === '') {
                        return LogicResult::error(
                            'This recipe\'s work does not arrive on its own, so it cannot run on '
                            . 'arrival. Choose Hourly, Daily or Weekly instead — or Manually only.',
                            ['recipe' => $recipe, 'session' => $session]
                        );
                    }
                } elseif (!RecipeSchedule::isClockFrequency($runs)) {
                    return LogicResult::error(
                        'That is not a schedule this recipe can run on.',
                        ['recipe' => $recipe, 'session' => $session]
                    );
                }
                $recipe->set('rcp_enabled', true);
                $recipe->set('rcp_schedule_frequency', $runs);
            }
        }

        // Tainted-writes opt-in. The save-time gate below verifies this is
        // set if the recipe is tainted-capable.
        $recipe->set('rcp_allow_tainted_writes', !empty($input['rcp_allow_tainted_writes']));

        // Owner: dropdown post or fall back to current admin for new recipes.
        $owner_post = (int)($input['rcp_owner_user_id'] ?? 0);
        if ($owner_post > 0) {
            $recipe->set('rcp_owner_user_id', $owner_post);
        } elseif (!$recipe->get('rcp_owner_user_id')) {
            $recipe->set('rcp_owner_user_id', $session->get_user_id());
        }

        // Save-time taint gate. A tainted-capable recipe must explicitly
        // opt in via rcp_allow_tainted_writes. The check fires here so the
        // admin sees the trade-off in plain language at the moment they're
        // configuring scope, instead of a mid-run failure. Pipeline mode has
        // no tool/model allow-list surface — tainted-capability instead comes
        // from the job's own untrustedDigest() declaration.
        if ((string)$recipe->get('rcp_mode') === Recipe::MODE_PIPELINE) {
            $taint_eval = TaintGate::evaluate([], [], '',
                $pipeline_job !== null && $pipeline_job->untrustedDigest());
        } else {
            $taint_eval = TaintGate::evaluate(
                $tool_list, $model_list, (string)$recipe->get('rcp_workspace')
            );
        }
        if ($taint_eval['tainted_capable'] && !$recipe->get('rcp_allow_tainted_writes')) {
            return LogicResult::error(
                'Standing approval required: ' . TaintGate::explain($taint_eval),
                ['recipe' => $recipe, 'session' => $session]
            );
        }

        // A checkbox posts nothing when unticked, so the thinking requirement
        // has to be read from the form's presence rather than from $input.
        if (array_key_exists('requirement_fields_present', $input)) {
            $recipe->set('rcp_thinking_required', isset($input['rcp_thinking_required']) ? true : null);
        }

        // Sink zero, plus the pin check, in one call: resolve what this recipe
        // WOULD run on. Refused here so the admin sees why while they are
        // choosing, and again at run start so tightening the domain's consent
        // stops an already-saved recipe. A pin below the recipe's own floor is
        // a configuration mistake and is refused at save — that is the point of
        // checking here rather than only at run time.
        require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
        try {
            RecipeVaultScope::resolveForRecipe($recipe);
        } catch (LlmProviderException $e) {
            return LogicResult::error($e->getMessage(), ['recipe' => $recipe, 'session' => $session]);
        }

        $recipe->set('rcp_update_time', gmdate('Y-m-d H:i:s'));

        try {
            $recipe->prepare();
        } catch (SystemBaseException $e) {
            return LogicResult::error($e->getMessage(), ['recipe' => $recipe, 'session' => $session]);
        }
        $recipe->save();

        // CHOOSING Manually only is also a stop button: it cancels queued runs
        // and halts one in progress, so "stop everything" does not mean hunting
        // through the runs view. On the TRANSITION only — a recipe that was
        // already manual has no automatic runs to stop, and firing this on
        // every save of one would kill the Run Now a person had just queued and
        // is waiting on.
        if ($ran_automatically_before && !$recipe->get('rcp_enabled') && $recipe->key) {
            $db = DbConnector::get_instance()->get_db_link();
            $q = $db->prepare("UPDATE rcr_recipe_runs
                              SET rcr_kill_requested = TRUE
                              WHERE rcr_rcp_recipe_id = ?
                                AND rcr_status IN ('pending', 'running')
                                AND rcr_delete_time IS NULL");
            $q->execute([(int)$recipe->key]);
        }

        $recipe->load();

        return LogicResult::redirect('/admin/joinery_ai/edit?rcp_recipe_id=' . $recipe->key . '&saved=1');
    }

    require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeVaultScope.php'));
    $page_vars = [
        'recipe' => $recipe,
        'session' => $session,
        'saved' => !empty($input['saved']),
        // How this recipe's binding will honour whatever the Runs control says.
        // Computed from the SAVED recipe: a binding edited but not yet saved
        // keeps showing the previous classification, which is honest because
        // saving revalidates and re-renders.
        'runs_value' => RecipeSchedule::frequencyOf($recipe),
        'schedule_fact' => joinery_ai_schedule_fact($recipe),
        // What this recipe would run on right now, recomputed on load — so
        // "Automatic" never means "unknowable".
        'resolution' => joinery_ai_resolution_preview($recipe),
    ];

    return LogicResult::render($page_vars);
}

/**
 * What a recipe would run on right now, as a line for the edit page.
 *
 * This design moves a judgement from per-recipe visible to per-catalog
 * invisible: an operator used to open a recipe and read a model name, and now
 * reads a floor while a file they did not open decides the rest. Fleet-wide
 * cascade is worth that trade only if the resolution is stated back — so it is,
 * on load, before they save.
 *
 * @return array{summary:string, error:string, advisories:string[], requirement:string}
 */
function joinery_ai_resolution_preview(Recipe $recipe): array {
    try {
        $req = RecipeVaultScope::requirementFor($recipe);
    } catch (Throwable $e) {
        return ['summary' => '', 'error' => $e->getMessage(), 'advisories' => [], 'requirement' => ''];
    }
    $error = null;
    $resolution = AiModelResolver::tryResolve($req, $error);
    if ($resolution === null) {
        return ['summary' => '', 'error' => (string)$error, 'advisories' => [],
                'requirement' => $req->describe()];
    }
    return [
        'summary'     => $resolution->summary(),
        'error'       => '',
        'advisories'  => $resolution->advisories(),
        'requirement' => $req->describe(),
    ];
}

/**
 * What this recipe's binding does with the Runs setting, as one plain sentence.
 *
 * Not help text about the options — a statement about THIS recipe. Where the
 * mail it reads is encrypted to its owner, no server-side process can read it,
 * so a due run waits for that person to be signed in with their vault open.
 * Saying so is the difference between a schedule and a promise the system
 * cannot keep (specs/recipe_run_scheduling.md § 2.2).
 */
function joinery_ai_schedule_fact(Recipe $recipe): string {
    if (!RecipeVaultScope::requiresWindow($recipe)) {
        // Nothing sealed: nothing needs explaining, so no line at all. The
        // view omits the paragraph when this is empty.
        return '';
    }
    if (RecipeVaultScope::cronRunnable($recipe)) {
        return 'Some of what this recipe reads is encrypted. The encrypted part runs while '
             . 'you\'re signed in with your vault unlocked; the rest runs on the server.';
    }
    return 'This recipe reads mail only you can unlock, so the server can\'t run it alone. '
         . 'A due run starts the next time you\'re signed in with your vault unlocked; '
         . 'anything that arrives in between waits for you.';
}
