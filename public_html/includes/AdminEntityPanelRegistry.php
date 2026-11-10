<?php
/**
 * AdminEntityPanelRegistry + AdminEntityPanel
 *
 * An admin detail page shows panels of related records that belong to
 * plugins: a user's orders, the campaigns sent to a group. Each panel is
 * registered for one kind of entity (spec email_lists_plugin G4):
 *
 *   'user'  — /admin/admin_user           (entity: User)
 *   'group' — /admin/admin_group_members  (entity: Group)
 *   'event' — event_manager's admin event page (entity: Event)
 *
 * and any kind a plugin's own page names. A panel renders its section for
 * the entity and handles its own POST actions, which the page dispatches
 * before its own. Register from a plugin's serve.php:
 *
 *   AdminEntityPanelRegistry::register('group', new MyGroupPanel());
 *
 * AdminUserPanelRegistry is the 'user' case and registers here.
 *
 * @version 1.0
 */

interface AdminEntityPanel {
	/** Stable id for this panel, unique within its entity type. */
	public function id(): string;
	/**
	 * The panel's HTML for the entity. $context carries what the host page
	 * derived once (for the user page: show_all, list_limit, show_all_url).
	 */
	public function render($entity, AdminPage $page, array $context = array()): string;
	/** POST action names this panel handles. */
	public function actions(): array;
	/** Handle one of this panel's POST actions; returns a LogicResult (typically a redirect). */
	public function handle(string $action, $entity, array $input): LogicResult;
}

class AdminEntityPanelRegistry {

	/** @var array<string,array<string,object>> entity type => id => panel */
	private static $panels = array();

	/**
	 * Register a panel for an entity type. Idempotent (last wins by id). The
	 * panel is an AdminEntityPanel, or for 'user' an AdminUserPanel.
	 */
	public static function register(string $entity_type, object $panel): void {
		if (!$panel instanceof AdminEntityPanel && !$panel instanceof AdminUserPanel) {
			throw new InvalidArgumentException(get_class($panel) . ' is not an admin panel');
		}
		self::$panels[$entity_type][$panel->id()] = $panel;
	}

	/** The panels for an entity type, in registration order. */
	public static function panels(string $entity_type): array {
		return array_values(self::$panels[$entity_type] ?? array());
	}

	/** Every panel's HTML for the entity, in registration order. */
	public static function renderAll(string $entity_type, $entity, AdminPage $page, array $context = array()): string {
		$html = '';
		foreach (self::panels($entity_type) as $panel) {
			$html .= $panel->render($entity, $page, $context);
		}
		return $html;
	}

	/**
	 * Dispatch a POST to whichever of the type's panels owns $input['action'].
	 * Returns the panel's LogicResult, or null if none claims the action.
	 */
	public static function handlePost(string $entity_type, $entity, array $input): ?LogicResult {
		$action = $input['action'] ?? null;
		if ($action === null) {
			return null;
		}
		foreach (self::panels($entity_type) as $panel) {
			if (in_array($action, $panel->actions(), true)) {
				return $panel->handle($action, $entity, $input);
			}
		}
		return null;
	}

	/** Clear one entity type, or every one (tests only). */
	public static function resetCache(?string $entity_type = null): void {
		if ($entity_type === null) {
			self::$panels = array();
		} else {
			unset(self::$panels[$entity_type]);
		}
	}
}
