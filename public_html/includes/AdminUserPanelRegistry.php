<?php
/**
 * AdminUserPanelRegistry + AdminUserPanel
 *
 * The admin user-detail page shows panels of related records — orders,
 * subscriptions, event registrations — each of which belongs to a plugin. A
 * provider contributes one panel: it renders its section for a given user and
 * handles its own POST actions. Core keeps the identity/tier/groups panels
 * inline; store and event_manager register the rest from their serve.php.
 *
 * @version 1.0.0
 */

interface AdminUserPanel {
    /** Stable id for this panel. */
    public function id(): string;
    /** Render the panel HTML for a user. Build via output buffering if using the AdminPage table API. */
    public function render(User $user, AdminPage $page): string;
    /** POST action names this panel handles. */
    public function actions(): array;
    /** Handle one of this panel's POST actions; returns a LogicResult (typically a redirect). */
    public function handle(string $action, User $user, array $input): LogicResult;
}

class AdminUserPanelRegistry {

    /** @var array<string,AdminUserPanel> keyed by id() */
    private static $panels = [];

    /** Register a panel. Idempotent (last-wins by id). */
    public static function register(AdminUserPanel $panel): void {
        self::$panels[$panel->id()] = $panel;
    }

    /** All registered panels, registration order. */
    public static function panels(): array {
        return array_values(self::$panels);
    }

    /**
     * Dispatch a POST to whichever panel owns $input['action'].
     * Returns the panel's LogicResult, or null if no panel claims the action.
     */
    public static function handlePost(User $user, array $input): ?LogicResult {
        $action = $input['action'] ?? null;
        if ($action === null) {
            return null;
        }
        foreach (self::$panels as $panel) {
            if (in_array($action, $panel->actions(), true)) {
                return $panel->handle($action, $user, $input);
            }
        }
        return null;
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$panels = [];
    }
}
