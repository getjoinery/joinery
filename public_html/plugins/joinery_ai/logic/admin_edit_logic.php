<?php

function admin_joinery_ai_edit_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));

    $session = SessionControl::get_instance();
    $session->check_permission(10);

    // Resolve which recipe we're editing (or that we're creating new)
    if (isset($post_vars['edit_primary_key_value']) && $post_vars['edit_primary_key_value']) {
        $recipe = new Recipe($post_vars['edit_primary_key_value'], TRUE);
    } elseif (isset($get_vars['rcp_recipe_id']) && $get_vars['rcp_recipe_id']) {
        $recipe = new Recipe($get_vars['rcp_recipe_id'], TRUE);
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
        $recipe->set('rcp_schedule_frequency', 'weekly');
        $recipe->set('rcp_schedule_day_of_week', 1);
        $recipe->set('rcp_schedule_time', $default_utc_time);
        $recipe->set('rcp_model', $settings->get_setting('joinery_ai_default_model') ?: 'claude-haiku-4-5');
        $recipe->set('rcp_delivery_dashboard', true);
        $recipe->set('rcp_enabled', true);
        $recipe->set('rcp_max_iterations', 5);
        $recipe->set('rcp_max_tokens', 5000);
        $recipe->set('rcp_monthly_token_cap', 200000);
    }

    if ($post_vars && isset($post_vars['btn_submit'])) {

        // Soft delete handler
        if (isset($post_vars['btn_delete']) && $recipe->key) {
            $recipe->soft_delete();
            return LogicResult::redirect('/admin/joinery_ai');
        }

        $simple_fields = [
            'rcp_name',
            'rcp_prompt',
            'rcp_schedule_frequency',
            'rcp_schedule_day_of_week',
            'rcp_model',
            'rcp_delivery_email',
            'rcp_max_iterations',
            'rcp_max_tokens',
            'rcp_monthly_token_cap',
            'rcp_workspace',
        ];
        foreach ($simple_fields as $f) {
            if (array_key_exists($f, $post_vars)) {
                $value = $post_vars[$f];
                if ($f === 'rcp_schedule_day_of_week' && $value === '') {
                    $value = null;
                }
                $recipe->set($f, $value);
            }
        }

        // The timeinput widget posts a normalized 24h "HH:MM" string in the
        // admin's local timezone via its hidden input (kept in sync by the
        // shared outputTimeInputJavaScript handler). Convert to UTC for storage.
        $time_local = trim($post_vars['rcp_schedule_time'] ?? '');
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

        // Checkboxes — absent = false
        $recipe->set('rcp_delivery_dashboard', !empty($post_vars['rcp_delivery_dashboard']));
        $recipe->set('rcp_enabled', !empty($post_vars['rcp_enabled']));

        // Allowed tools — checkboxes post as `rcp_allowed_tools[]`. Absent
        // means no tools selected.
        $tools_post = $post_vars['rcp_allowed_tools'] ?? [];
        if (!is_array($tools_post)) $tools_post = [];
        $tool_list = array_values(array_filter(array_map('strval', $tools_post), 'strlen'));
        $recipe->set('rcp_allowed_tools', $tool_list);

        // Owner: single-user v1 — current admin owns the recipe
        if (!$recipe->get('rcp_owner_user_id')) {
            $recipe->set('rcp_owner_user_id', $session->get_user_id());
        }

        $recipe->set('rcp_update_time', gmdate('Y-m-d H:i:s'));

        $recipe->prepare();
        $recipe->save();
        $recipe->load();

        return LogicResult::redirect('/admin/joinery_ai/edit?rcp_recipe_id=' . $recipe->key . '&saved=1');
    }

    $page_vars = [
        'recipe' => $recipe,
        'session' => $session,
        'saved' => !empty($get_vars['saved']),
    ];

    return LogicResult::render($page_vars);
}
