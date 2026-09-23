<?php
/**
 * AgentVocabulary - what a node's agent can be asked, and what the plane says
 * when it cannot be asked something.
 *
 * specs/agent_recipes_and_vocabulary.md, "Different agent versions across the
 * fleet". Three things live here, so every feature answers them the same way:
 *
 *   - The VERSION FLOOR: the oldest agent this plane supports. A node below it
 *     is offered apply_update and nothing else, and is flagged on the node
 *     list. It is a constant, not a setting: the code that spoke to older
 *     agents is deleted each time the floor rises, so an operator lowering it
 *     would route jobs to agents this plane no longer knows how to talk to.
 *     It rises in a release, deliberately.
 *   - DECLARED WORDS: a plane feature built from several words (the Host
 *     card, staged_rollout, a page_probe gate) names them, and asks
 *     missing_words() once. The node's own reported vocabulary is the only
 *     account (rule 7): a version number is never consulted for a word.
 *   - The STANDARD STATE for a node that lacks a word a feature needs: "needs
 *     a newer agent; update this node", never an error, a blank, or a
 *     half-rendered card.
 *
 * Word contracts never change (rule 11), which is what makes a name in the
 * reported list sufficient: a name means one thing on every agent that
 * reports it.
 *
 * @version 1.0 - WP6 of specs/agent_recipes_and_vocabulary.md.
 */
class AgentVocabulary {

	/**
	 * The oldest agent this plane supports: the agent release that completes
	 * specs/agent_recipes_and_vocabulary.md (owner, 2026-09-23). Every word
	 * that spec adds is in it, and the per-word version table and the
	 * no-vocabulary fallback for agents at 1.10.0 and earlier were deleted
	 * when it was set.
	 */
	const FLOOR = '1.43.0';

	/** The one operation a node below the floor is offered: the way up. */
	const BELOW_FLOOR_OPERATION = 'apply_update';

	/**
	 * The agent version this plane ships in its own agent_dist: the newest a
	 * node can be updated to from here. Null when the plane carries none.
	 */
	public static function newest() {
		static $newest = false;
		if ($newest === false) {
			$newest = null;
			$path = PathHelper::getIncludePath('agent_dist/manifest.json');
			if (is_readable($path)) {
				$m = json_decode((string)file_get_contents($path), true);
				if (is_array($m) && isset($m['version']) && self::is_version($m['version'])) {
					$newest = $m['version'];
				}
			}
		}
		return $newest;
	}

	public static function is_version($v) {
		return is_string($v) && preg_match('/^\d+\.\d+\.\d+$/', $v) === 1;
	}

	/** The node's reported agent version, or '' when it has reported none. */
	public static function version($node) {
		$v = trim((string)$node->get('mgn_agent_version'));
		return self::is_version($v) ? $v : '';
	}

	/**
	 * Whether a node with an agent runs one older than the floor. A node that
	 * reports no version has an agent too old to say, and is below it. A node
	 * with no agent at all is not "below the floor": it has no agent to update.
	 */
	public static function below_floor($node) {
		if (empty($node->get('mgn_agent_public_key'))) {
			return false;
		}
		$v = self::version($node);
		return $v === '' || version_compare($v, self::FLOOR, '<');
	}

	/** The words the node reported at its last claim. Empty when none. */
	public static function reported_words($node) {
		$reported = trim((string)$node->get('mgn_agent_primitives'));
		if ($reported === '') {
			return [];
		}
		return array_values(array_filter(array_map('trim', explode(',', $reported)), 'strlen'));
	}

	/**
	 * The words among $words this node does not offer. Empty means the
	 * feature can be offered on this node. A node below the floor offers
	 * nothing but apply_update, whatever it reports.
	 */
	public static function missing_words($node, array $words) {
		if (self::below_floor($node)) {
			return array_values(array_diff($words, [self::BELOW_FLOOR_OPERATION]));
		}
		return array_values(array_diff($words, self::reported_words($node)));
	}

	/**
	 * The standard state, as a sentence, for a node that lacks words a feature
	 * needs. Used in a thrown refusal and in rendered cards alike.
	 */
	public static function needs_newer_agent_text($node, array $missing) {
		if (empty($node->get('mgn_agent_public_key'))) {
			return 'This node has no paired agent, so nothing can be asked of it; pair it first.';
		}
		$v = self::version($node);
		$running = $v !== '' ? "agent {$v}" : 'an agent that reports no version';
		$what = $missing ? ' (it does not offer ' . implode(', ', $missing) . ')' : '';
		$floor = self::below_floor($node)
			? ' It is below ' . self::FLOOR . ', the oldest agent this management node supports, so apply_update is the only job it is offered.'
			: '';
		return "Needs a newer agent; update this node. It runs {$running}{$what}.{$floor}";
	}

	/** The standard state as a small block of HTML for a card. */
	public static function needs_newer_agent_html($node, array $missing) {
		return '<div class="alert alert-secondary small mb-0 js-needs-newer-agent">'
			. htmlspecialchars(self::needs_newer_agent_text($node, $missing))
			. '</div>';
	}

	/**
	 * How far a node's agent is behind the newest this plane ships, for the
	 * node list: ['version', 'behind' (releases, by minor then patch step),
	 * 'below_floor', 'label']. Null for a node with no agent.
	 */
	public static function spread($node) {
		if (empty($node->get('mgn_agent_public_key'))) {
			return null;
		}
		$v = self::version($node);
		$newest = self::newest();
		$behind = null;
		if ($v !== '' && $newest !== null) {
			$behind = self::steps_behind($v, $newest);
		}
		$below = self::below_floor($node);
		if ($v === '') {
			$label = 'agent version unknown';
		} elseif ($behind === 0 || $behind === null) {
			$label = "agent {$v}";
		} else {
			$label = "agent {$v}, {$behind} behind";
		}
		return ['version' => $v, 'behind' => $behind, 'below_floor' => $below, 'label' => $label];
	}

	/**
	 * Releases between two versions, counted as minor steps (a patch release
	 * counts as one when the minors match). Zero when $v is at or past $newest.
	 */
	public static function steps_behind($v, $newest) {
		if (version_compare($v, $newest, '>=')) {
			return 0;
		}
		[$a1, $a2, $a3] = array_map('intval', explode('.', $v));
		[$b1, $b2, $b3] = array_map('intval', explode('.', $newest));
		if ($a1 !== $b1) {
			return max(1, $b2 + 1);
		}
		if ($a2 !== $b2) {
			return $b2 - $a2;
		}
		return $b3 > $a3 ? 1 : 0;
	}
}
