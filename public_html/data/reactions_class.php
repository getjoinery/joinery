<?php
/**
 * Reaction and MultiReaction classes
 *
 * Generic polymorphic reaction system for any entity type.
 * Supports likes, favorites, bookmarks, passes, and any other reaction type.
 * Uses entity_type + entity_id pattern (same as EntityPhoto, ChangeTracking).
 *
 * @version 1.0
 * @see /specs/implemented/reaction_system_spec.md
 * @see /docs/social_features.md
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ReactionException extends SystemBaseException {}

class Reaction extends SystemBase {
	public static $prefix = 'rct';
	public static $tablename = 'rct_reactions';
	public static $pkey_column = 'rct_reaction_id';

	protected static $foreign_key_actions = [
		'rct_usr_user_id' => ['action' => 'permanent_delete'],
	];

	public static $field_specifications = array(
		'rct_reaction_id'  => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'rct_usr_user_id'  => array('type'=>'int4', 'is_nullable'=>false, 'required'=>true, 'unique_with'=>array('rct_entity_type', 'rct_entity_id')),
		'rct_entity_type'  => array('type'=>'varchar(50)', 'is_nullable'=>false, 'required'=>true),
		'rct_entity_id'    => array('type'=>'int4', 'is_nullable'=>false, 'required'=>true),
		'rct_reaction_type' => array('type'=>'varchar(20)', 'default'=>'like'),
		'rct_create_time'  => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'rct_delete_time'  => array('type'=>'timestamp(6)'),
	);

	/**
	 * Toggle a reaction on/off for a user on an entity.
	 *
	 * If active reaction exists: soft-deletes it (unreact).
	 * If soft-deleted reaction exists: undeletes it (re-react).
	 * If no reaction exists: creates one.
	 *
	 * @param int $user_id
	 * @param string $entity_type e.g. 'user', 'event', 'post', 'product'
	 * @param int $entity_id
	 * @param string $reaction_type e.g. 'like', 'favorite', 'pass', 'bookmark'
	 * @return array ['action' => 'reacted'|'unreacted', 'reaction' => Reaction]
	 */
	public static function toggle($user_id, $entity_type, $entity_id, $reaction_type = 'like') {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();

		// Look for any existing row (including soft-deleted)
		$sql = "SELECT rct_reaction_id, rct_delete_time FROM rct_reactions
				WHERE rct_usr_user_id = ? AND rct_entity_type = ? AND rct_entity_id = ?";
		$q = $dblink->prepare($sql);
		$q->execute([$user_id, $entity_type, $entity_id]);
		$existing = $q->fetch(PDO::FETCH_ASSOC);

		if ($existing) {
			$reaction = new Reaction($existing['rct_reaction_id'], TRUE);

			if ($existing['rct_delete_time'] === null) {
				// Active reaction exists -- remove it
				$reaction->soft_delete();
				return ['action' => 'unreacted', 'reaction' => $reaction];
			} else {
				// Soft-deleted -- re-react by clearing delete_time and updating type/time
				$reaction->set('rct_delete_time', null);
				$reaction->set('rct_reaction_type', $reaction_type);
				$reaction->set('rct_create_time', gmdate('Y-m-d H:i:s'));
				$reaction->save();
				return ['action' => 'reacted', 'reaction' => $reaction];
			}
		}

		// No existing row -- create new
		$reaction = new Reaction(NULL);
		$reaction->set('rct_usr_user_id', $user_id);
		$reaction->set('rct_entity_type', $entity_type);
		$reaction->set('rct_entity_id', $entity_id);
		$reaction->set('rct_reaction_type', $reaction_type);
		$reaction->save();
		return ['action' => 'reacted', 'reaction' => $reaction];
	}

	/**
	 * Check if a user has an active reaction on an entity. Direct SQL for performance.
	 *
	 * @param int $user_id
	 * @param string $entity_type
	 * @param int $entity_id
	 * @return bool
	 */
	public static function has_reacted($user_id, $entity_type, $entity_id) {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "SELECT 1 FROM rct_reactions
				WHERE rct_usr_user_id = ? AND rct_entity_type = ? AND rct_entity_id = ?
				AND rct_delete_time IS NULL LIMIT 1";
		$q = $dblink->prepare($sql);
		$q->execute([$user_id, $entity_type, $entity_id]);
		return (bool)$q->fetchColumn();
	}

	/**
	 * Get total active reaction count for an entity. Direct SQL for performance.
	 *
	 * @param string $entity_type
	 * @param int $entity_id
	 * @return int
	 */
	public static function get_count($entity_type, $entity_id) {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "SELECT COUNT(*) FROM rct_reactions
				WHERE rct_entity_type = ? AND rct_entity_id = ? AND rct_delete_time IS NULL";
		$q = $dblink->prepare($sql);
		$q->execute([$entity_type, $entity_id]);
		return (int)$q->fetchColumn();
	}

	/**
	 * Get all entities a user has reacted to, optionally filtered by entity type and reaction type.
	 *
	 * @param int $user_id
	 * @param string|null $entity_type Filter by entity type, or null for all
	 * @param string $reaction_type Filter by reaction type (default 'like')
	 * @return MultiReaction Loaded collection
	 */
	public static function get_user_reactions($user_id, $entity_type = null, $reaction_type = 'like') {
		$options = ['user_id' => $user_id, 'reaction_type' => $reaction_type, 'deleted' => false];
		if ($entity_type !== null) {
			$options['entity_type'] = $entity_type;
		}
		$reactions = new MultiReaction($options, ['rct_create_time' => 'DESC']);
		$reactions->load();
		return $reactions;
	}

	/**
	 * Render a reaction button for use in any view.
	 *
	 * Outputs an inline button with AJAX toggle behavior. User must be logged in.
	 *
	 * @param string $entity_type
	 * @param int $entity_id
	 * @param array $options Optional: show_count, reaction_type, icon_active, icon_inactive, css_class
	 */
	public static function render_button($entity_type, $entity_id, $options = []) {
		$reaction_type = isset($options['reaction_type']) ? $options['reaction_type'] : 'like';
		$show_count = isset($options['show_count']) ? $options['show_count'] : true;
		$icon_active = isset($options['icon_active']) ? $options['icon_active'] : 'fas fa-heart';
		$icon_inactive = isset($options['icon_inactive']) ? $options['icon_inactive'] : 'far fa-heart';
		$css_class = isset($options['css_class']) ? ' ' . $options['css_class'] : '';

		$session = SessionControl::get_instance();
		$is_active = false;
		$count = 0;

		if ($session->get_user_id()) {
			$is_active = self::has_reacted($session->get_user_id(), $entity_type, $entity_id);
		}
		if ($show_count) {
			$count = self::get_count($entity_type, $entity_id);
		}

		$safe_type = htmlspecialchars($entity_type, ENT_QUOTES, 'UTF-8');
		$safe_id = (int)$entity_id;
		$safe_reaction_type = htmlspecialchars($reaction_type, ENT_QUOTES, 'UTF-8');
		$icon_class = $is_active ? $icon_active : $icon_inactive;
		$active_data = $is_active ? 'true' : 'false';
		$btn_id = 'rct-btn-' . $safe_type . '-' . $safe_id;

		$html = '<button type="button" id="' . $btn_id . '" '
			. 'class="btn btn-reaction' . $css_class . ($is_active ? ' active' : '') . '" '
			. 'data-entity-type="' . $safe_type . '" '
			. 'data-entity-id="' . $safe_id . '" '
			. 'data-reaction-type="' . $safe_reaction_type . '" '
			. 'data-active="' . $active_data . '" '
			. 'data-icon-active="' . htmlspecialchars($icon_active, ENT_QUOTES, 'UTF-8') . '" '
			. 'data-icon-inactive="' . htmlspecialchars($icon_inactive, ENT_QUOTES, 'UTF-8') . '">';
		$html .= '<i class="' . htmlspecialchars($icon_class, ENT_QUOTES, 'UTF-8') . '"></i>';
		if ($show_count) {
			$html .= ' <span class="reaction-count">' . $count . '</span>';
		}
		$html .= '</button>';

		echo $html;

		// Inline JS -- only output once per page
		static $js_output = false;
		if (!$js_output) {
			$js_output = true;
			echo '<script>
document.addEventListener("click", function(e) {
	var btn = e.target.closest(".btn-reaction");
	if (!btn) return;
	e.preventDefault();
	btn.disabled = true;
	var formData = new FormData();
	formData.append("action", "toggle");
	formData.append("entity_type", btn.dataset.entityType);
	formData.append("entity_id", btn.dataset.entityId);
	formData.append("reaction_type", btn.dataset.reactionType);
	fetch("/ajax/reaction_ajax", {
		method: "POST",
		body: formData
	})
	.then(function(r) { return r.json(); })
	.then(function(data) {
		if (data.success) {
			var isActive = (data.action === "reacted");
			btn.dataset.active = isActive ? "true" : "false";
			var icon = btn.querySelector("i");
			if (icon) {
				icon.className = isActive ? btn.dataset.iconActive : btn.dataset.iconInactive;
			}
			if (isActive) { btn.classList.add("active"); } else { btn.classList.remove("active"); }
			var countEl = btn.querySelector(".reaction-count");
			if (countEl && data.count !== undefined) { countEl.textContent = data.count; }
		}
		btn.disabled = false;
	})
	.catch(function() { btn.disabled = false; });
});
</script>';
		}
	}
}

class MultiReaction extends SystemMultiBase {
	protected static $model_class = 'Reaction';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['rct_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['entity_type'])) {
			$filters['rct_entity_type'] = [$this->options['entity_type'], PDO::PARAM_STR];
		}

		if (isset($this->options['entity_id'])) {
			$filters['rct_entity_id'] = [$this->options['entity_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['reaction_type'])) {
			$filters['rct_reaction_type'] = [$this->options['reaction_type'], PDO::PARAM_STR];
		}

		if (isset($this->options['deleted'])) {
			$filters['rct_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		$sorts = [];
		if (!empty($this->order_by)) {
			$sorts = $this->order_by;
		}

		return $this->_get_resultsv2('rct_reactions', $filters, $sorts, $only_count, $debug);
	}
}
