<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

class CapExceededException extends Exception {
    /** @var string  Which cap fired: 'recipe_monthly' or 'global_monthly'. */
    public $which;
    public function __construct(string $which, string $message) {
        $this->which = $which;
        parent::__construct($message);
    }
}

/**
 * Token-budget enforcement for the recipe runner.
 *
 * Two caps are checked, both per calendar month (UTC):
 *   - per-recipe via rcp_monthly_token_cap
 *   - plugin-wide via joinery_ai_global_monthly_token_cap setting
 *
 * Both are deliberately coarse — token count, not dollars; calendar-month
 * buckets, not rolling windows. The point is a hard ceiling that prevents
 * runaway spend, not precise reporting.
 *
 * 80% soft alert: when usage on either cap crosses 80% during a check, send
 * a one-shot email to the recipe owner. Tracked via a stg_settings row
 * keyed to the calendar month (e.g. joinery_ai_alert_2026_04_recipe_42).
 */
class CostGuard {

    /**
     * Throws CapExceededException if either cap is at or above 100%. Returns
     * silently otherwise. Side effect: may queue a soft-alert email if usage
     * just crossed 80% of either cap.
     */
    public static function check(Recipe $recipe): void {
        $month = gmdate('Y_m');
        $month_start = gmdate('Y-m-01 00:00:00');

        // Per-recipe cap.
        $recipe_cap = (int)$recipe->get('rcp_monthly_token_cap');
        if ($recipe_cap > 0) {
            $recipe_used = self::tokensUsedSince($month_start, (int)$recipe->key);
            self::evaluateCap(
                $recipe, $recipe_used, $recipe_cap, $month,
                'recipe_monthly',
                "joinery_ai_alert_{$month}_recipe_" . (int)$recipe->key,
                "Recipe '" . $recipe->get('rcp_name') . "' has used "
                    . $recipe_used . ' of ' . $recipe_cap . ' tokens this month '
                    . '(' . self::pct($recipe_used, $recipe_cap) . '%).'
            );
        }

        // Global cap across all recipes.
        $settings = Globalvars::get_instance();
        $global_cap = (int)$settings->get_setting('joinery_ai_global_monthly_token_cap');
        if ($global_cap > 0) {
            $global_used = self::tokensUsedSince($month_start, null);
            self::evaluateCap(
                $recipe, $global_used, $global_cap, $month,
                'global_monthly',
                "joinery_ai_alert_{$month}_global",
                'Joinery AI plugin total: '
                    . $global_used . ' of ' . $global_cap . ' tokens used this month '
                    . '(' . self::pct($global_used, $global_cap) . '%).'
            );
        }
    }

    /**
     * Throw if usage >= cap; soft-alert if usage >= 80% and no alert sent
     * yet this month for this cap.
     */
    private static function evaluateCap(
        Recipe $recipe, int $used, int $cap, string $month,
        string $which, string $alert_setting_key, string $context_msg
    ): void {
        if ($used >= $cap) {
            throw new CapExceededException(
                $which,
                "monthly_token_cap reached: $used >= $cap. $context_msg"
            );
        }

        if ($used >= (int)($cap * 0.8) && !self::alertAlreadySent($alert_setting_key)) {
            self::sendSoftAlert($recipe, $context_msg, $alert_setting_key);
        }
    }

    private static function tokensUsedSince(string $since_utc, ?int $recipe_id): int {
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "SELECT COALESCE(SUM(rcr_input_tokens) + SUM(rcr_output_tokens), 0)
                FROM rcr_recipe_runs
                WHERE rcr_started_time >= ?
                  AND rcr_status IN (?, ?, ?, ?)
                  AND rcr_delete_time IS NULL";
        $params = [$since_utc, RecipeRun::STATUS_SUCCESS, RecipeRun::STATUS_FAILED,
                   RecipeRun::STATUS_TIMEOUT, RecipeRun::STATUS_RUNNING];
        if ($recipe_id !== null) {
            $sql .= ' AND rcr_rcp_recipe_id = ?';
            $params[] = $recipe_id;
        }
        $q = $db->prepare($sql);
        $q->execute($params);
        return (int)$q->fetchColumn();
    }

    private static function alertAlreadySent(string $setting_key): bool {
        $settings = Globalvars::get_instance();
        return (bool)$settings->get_setting($setting_key);
    }

    /**
     * Insert a stg_settings row to mark the alert as sent for this month, then
     * dispatch the email. The flag is per-month so the alert recurs on the
     * first crossing each calendar month rather than once forever.
     */
    private static function sendSoftAlert(Recipe $recipe, string $msg, string $setting_key): void {
        try {
            $db = DbConnector::get_instance()->get_db_link();
            $q = $db->prepare(
                "INSERT INTO stg_settings (stg_name, stg_value, stg_create_time)
                 VALUES (?, '1', NOW() AT TIME ZONE 'UTC')
                 ON CONFLICT (stg_name) DO UPDATE SET stg_value = '1'"
            );
            $q->execute([$setting_key]);
        } catch (Exception $e) {
            error_log('[joinery_ai CostGuard] Failed to set alert flag: ' . $e->getMessage());
        }

        try {
            self::deliverEmail($recipe, 'Joinery AI: 80% of monthly token cap reached', $msg);
        } catch (Exception $e) {
            error_log('[joinery_ai CostGuard] Failed to send 80% alert email: ' . $e->getMessage());
        }
    }

    private static function deliverEmail(Recipe $recipe, string $subject, string $body): void {
        $owner_id = (int)$recipe->get('rcp_owner_user_id');
        if ($owner_id <= 0) return;

        require_once(PathHelper::getIncludePath('data/users_class.php'));
        require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
        require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

        $user = new User($owner_id, true);
        $to = $recipe->get('rcp_delivery_email') ?: $user->get('usr_email');
        if (!$to) return;

        (new EmailSender())->send(EmailMessage::create($to, $subject, $body));
    }

    private static function pct(int $used, int $cap): int {
        if ($cap <= 0) return 0;
        return (int)round($used / $cap * 100);
    }

}
