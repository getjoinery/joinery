<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipes_class.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/recipe_runs_class.php'));

/**
 * Context passed to every tool's execute(). Carries the in-flight Recipe
 * and RecipeRun plus owner/timezone so tools can read/write owner-scoped
 * data and append to the run's tool-call trace without reaching for a
 * global session.
 */
class RecipeRunContext {

    /** @var Recipe */
    public $recipe;

    /** @var RecipeRun */
    public $run;

    /** @var int */
    public $owner_user_id;

    /** @var string */
    public $owner_timezone;

    public function __construct(Recipe $recipe, RecipeRun $run) {
        $this->recipe = $recipe;
        $this->run = $run;
        $this->owner_user_id = (int)$recipe->get('rcp_owner_user_id');
        $this->owner_timezone = self::resolveTimezone($this->owner_user_id);
    }

    /**
     * Append a tool-call trace entry to the run's rcr_tool_calls JSON column.
     * Each entry: { name, input, output, started, completed, is_error }.
     * Persisted in-memory until run save; the runner writes once at end.
     */
    public function appendToolCall(array $entry): void {
        $existing = $this->run->get('rcr_tool_calls');
        if (is_string($existing)) {
            $decoded = json_decode($existing, true);
            $existing = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($existing)) {
            $existing = [];
        }
        $existing[] = $entry;
        $this->run->set('rcr_tool_calls', $existing);
    }

    private static function resolveTimezone(int $user_id): string {
        if ($user_id <= 0) {
            $settings = Globalvars::get_instance();
            return $settings->get_setting('default_timezone') ?: 'UTC';
        }
        require_once(PathHelper::getIncludePath('data/users_class.php'));
        $user = new User($user_id, true);
        $tz = $user->get('usr_timezone');
        return $tz ?: 'UTC';
    }

}
