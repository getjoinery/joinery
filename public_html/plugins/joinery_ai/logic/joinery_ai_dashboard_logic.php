<?php

function joinery_ai_dashboard_logic($get_vars, $post_vars) {
    require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
    require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

    $session = SessionControl::get_instance();
    if (!$session->is_logged_in() || $session->get_permission() < 10) {
        return LogicResult::redirect('/login?return=/joinery_ai');
    }

    $owner_user_id = $session->get_user_id();

    $recipes = new MultiRecipe(
        ['enabled' => true, 'deleted' => false, 'owner_user_id' => $owner_user_id],
        ['rcp_name' => 'ASC']
    );
    $recipes->load();

    // Filter to dashboard-visible recipes and pull latest successful run for each.
    $cards = [];
    if (count($recipes)) {
        $ids = [];
        foreach ($recipes as $r) {
            if (!$r->get('rcp_delivery_dashboard')) continue;
            $ids[] = (int)$r->key;
        }

        $latest_by_recipe = [];
        if ($ids) {
            $db = DbConnector::get_instance()->get_db_link();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // DISTINCT ON to grab the most recent successful run per recipe in one query.
            $sql = "SELECT DISTINCT ON (rcr_rcp_recipe_id)
                       rcr_run_id, rcr_rcp_recipe_id, rcr_started_time, rcr_output
                    FROM rcr_recipe_runs
                    WHERE rcr_rcp_recipe_id IN ($placeholders)
                      AND rcr_status = ?
                      AND rcr_delete_time IS NULL
                    ORDER BY rcr_rcp_recipe_id, rcr_started_time DESC";
            $params = array_merge($ids, [RecipeRun::STATUS_SUCCESS]);
            $q = $db->prepare($sql);
            $q->execute($params);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $latest_by_recipe[(int)$row['rcr_rcp_recipe_id']] = $row;
            }
        }

        foreach ($recipes as $r) {
            if (!$r->get('rcp_delivery_dashboard')) continue;
            $cards[] = [
                'recipe' => $r,
                'latest' => $latest_by_recipe[(int)$r->key] ?? null,
            ];
        }
    }

    return LogicResult::render([
        'cards' => $cards,
        'session' => $session,
    ]);
}
