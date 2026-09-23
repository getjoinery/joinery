<?php
/**
 * ProtectionLevelPicker — the one place a member is asked how protected
 * something should be.
 *
 * `ProtectionLevel` owns the ladder and its spelling. This owns the *promise*:
 * the words a member reads when choosing a rung. They live here rather than in
 * each service's page because a member who has read "Private" once on their
 * mail should not have to work out whether "Private" means something different
 * on a conversation — and because a promise duplicated across pages is a
 * promise that drifts.
 *
 * A consumer declares which rungs it offers and, when its flavour of a rung
 * needs different words, which service copy to use. A service that has no
 * flavour of its own gets the default wording, which is written to be true
 * everywhere.
 *
 * Rendering goes through FormWriter's card radio, so the control carries the
 * platform's validation styling, CSRF handling and markup like any other field:
 *
 *   ProtectionLevelPicker::render($formwriter, 'protection_level', [
 *       'service' => ProtectionLevelPicker::SERVICE_MESSAGING,
 *       'levels'  => Conversation::LEVELS,
 *       'value'   => $conversation->protection_level(),
 *       'addons'  => [
 *           ProtectionLevelPicker::ADDON_SEALED_EXITS_ONLY => [
 *               'checked' => $conversation->sealed_exits_only(),
 *           ],
 *       ],
 *   ]);
 *
 * Add-ons (specs/protection_levels_platform.md § Add-ons) are the protections
 * a service bolts onto Private at one of its own doors. Each renders as a
 * FormWriter switch under an "Extra protection" heading below the cards, shown
 * only while Private (or Fortress, where offered) is selected, and submitted as
 * `{field}_{key}`. Their copy — a name, what it protects, what it costs —
 * lives here beside the level cards for the same reason the card copy does; a
 * consumer names the key and passes `checked` / `disabled`, and may pass its
 * own `label` / `protects` / `costs` for a key this catalog does not carry.
 * What only one service needs to say about an add-on — a setup step it leads
 * to, why it is unavailable here — goes in `note` (a sentence after the
 * catalog's two) and `link` ([url, label], shown under the switch), never in
 * the catalog sentences. `addons_note` puts one sentence under the heading.
 * The card of the selected level lists the add-ons that are on.
 *
 * A consumer's own `visibility_rules` (level => show/hide lists) are merged
 * into the picker's, so fields outside the picker can follow the level too.
 *
 * The picker echoes its markup, so it belongs in a direct-output form (not one
 * built with FormWriter's deferred_output).
 *
 * @version 1.3.0 - chips read "Private+" when an add-on is on; summaryTitle() names them on hover
 * @version 1.2.0
 * @changelog 1.2.0 - mail card copy; add-on note and link; addons_note;
 *   consumer visibility_rules merged into the picker's
 * @changelog 1.1.0 - three rungs; add-ons render as switches under Private
 */


class ProtectionLevelPicker {

	/** Copy flavours. A service not listed here reads the default wording. */
	const SERVICE_DEFAULT   = 'default';
	const SERVICE_MESSAGING = 'messaging';
	const SERVICE_MAIL      = 'mail';

	/** Add-on keys the catalog carries copy for. */
	const ADDON_RELAY_SEALS_TO_OWNER = 'relay_seals_to_owner';
	const ADDON_SEND_LOCK            = 'send_lock';
	const ADDON_LOCAL_MODELS_ONLY    = 'local_models_only';
	const ADDON_SEALED_EXITS_ONLY    = 'sealed_exits_only';

	/** The heading over the add-on switches. "Add-on" is the internal word. */
	const ADDONS_HEADING = 'Extra protection';

	/**
	 * Three lines per rung, always in the same order and always answering the
	 * same three questions:
	 *
	 *   1. What does this actually do?
	 *   2. When would I pick it?
	 *   3. What does it cost me?
	 *
	 * The third line is not marketing's to remove. A member choosing protection
	 * is choosing a trade, and a card that hides the trade is how someone locks
	 * themselves out of their own content.
	 */
	protected static function catalog(): array {
		return array(
			self::SERVICE_DEFAULT => array(
				ProtectionLevel::STANDARD => array(
					'The server manages this for you.',
					'Best for everyday things where convenience matters more than secrecy.',
					'Nothing extra to set up. Stored content is not protected at rest.',
				),
				ProtectionLevel::PRIVATE_ => array(
					'Encrypted at rest — a stolen disk or a database dump yields nothing readable.',
					'Best for content worth keeping private, where search and automation must keep working.',
					'You unlock to read it. Lose every way in and the content is gone for good.',
				),
				ProtectionLevel::FORTRESS => array(
					'Only you hold the key — the server never sees the content at all.',
					'Best for the things that are you: identity, money, the irreplaceable.',
					'Nothing on the server can read it, so search, previews and automation stop working.',
				),
			),
			self::SERVICE_MAIL => array(
				ProtectionLevel::STANDARD => array(
					'The server manages this mailbox for you.',
					'Best for club signups, newsletters, and low-stakes addresses.',
					'Nothing extra to set up. Stored mail is not protected at rest.',
				),
				ProtectionLevel::PRIVATE_ => array(
					'Only you can read your stored mail.',
					'Best for mail worth keeping private, where automation must keep working.',
					'You unlock to read. Lose every unlocker and the mail is gone for good.',
				),
			),
			self::SERVICE_MESSAGING => array(
				ProtectionLevel::STANDARD => array(
					'The server manages this conversation for you.',
					'Best for ordinary chat — plans, logistics, anything you would say in a room.',
					'Nothing to set up. Messages are stored as written.',
				),
				ProtectionLevel::PRIVATE_ => array(
					'Messages and attachments are encrypted at rest, readable only while someone in the conversation is here.',
					'Best for a conversation worth protecting that should still feel like a normal chat.',
					'Everyone in it needs protection set up first, and this cannot be undone later.',
				),
			),
		);
	}

	/**
	 * Every add-on's name and its two sentences: what it protects against, and
	 * what it costs (Add-ons rule 3). The same add-on reads the same wherever
	 * it is offered.
	 */
	protected static function addonCatalog(): array {
		return array(
			self::ADDON_RELAY_SEALS_TO_OWNER => array(
				'label'    => 'Seal at the relay',
				'protects' => 'A hacked server can\'t read mail that arrives while you\'re away.',
				'costs'    => 'New mail waits to be processed until you sign in.',
			),
			self::ADDON_SEND_LOCK => array(
				'label'    => 'Only send while I\'m signed in',
				'protects' => 'Nobody can send mail as you, even from a hacked server.',
				'costs'    => 'This domain can only send while you\'re signed in.',
			),
			self::ADDON_LOCAL_MODELS_ONLY => array(
				'label'    => 'Local models only',
				'protects' => 'Nothing in this chat is sent to an outside AI company.',
				'costs'    => 'Only AI models running on your own hardware can answer.',
			),
			self::ADDON_SEALED_EXITS_ONLY => array(
				'label'    => 'Nothing leaves unsealed',
				'protects' => 'No message text appears in notifications or crosses to another server unencrypted.',
				'costs'    => 'Notifications don\'t show the message, and people on servers without encryption can\'t be reached.',
			),
		);
	}

	/**
	 * One add-on's copy: label, protects, costs. A consumer's own values win
	 * over the catalog's, a field at a time.
	 */
	public static function addonCopy(string $key, array $override = array()): array {
		$catalog = self::addonCatalog()[$key] ?? array('label' => $key, 'protects' => '', 'costs' => '');
		foreach (array('label', 'protects', 'costs') as $part) {
			if (isset($override[$part]) && $override[$part] !== '') {
				$catalog[$part] = (string)$override[$part];
			}
		}
		return $catalog;
	}

	/**
	 * A level as a chip or badge shows it (Add-ons rule 4): the level's name,
	 * with a trailing "+" when any add-on is on — "Private+". The add-ons
	 * themselves are named in summaryTitle(), the chip's hover text. Standard
	 * never carries add-ons, so its active list is ignored.
	 *
	 * @param string   $level
	 * @param string[] $active_keys add-on keys that are on
	 */
	public static function summary(string $level, array $active_keys = array()): string {
		return self::chipText($level, self::levelTakesAddons($level) ? count($active_keys) : 0);
	}

	/** The hover text naming a chip's add-ons, or '' when none are on. */
	public static function summaryTitle(string $level, array $active_keys = array()): string {
		if (!self::levelTakesAddons($level)) {
			return '';
		}
		$labels = array();
		foreach ($active_keys as $key) {
			$labels[] = self::addonCopy((string)$key)['label'];
		}
		return self::chipTitle($labels);
	}

	/** Chip text from a level and how many add-ons are on: "Private" or "Private+". */
	public static function chipText(string $level, int $addon_count): string {
		return ProtectionLevel::label($level) . ($addon_count > 0 ? '+' : '');
	}

	/** Chip hover text from add-on labels: "Extra protection: A, B", or ''. */
	public static function chipTitle(array $labels): string {
		return $labels ? 'Extra protection: ' . implode(', ', $labels) : '';
	}

	/** Add-ons exist on Private and Fortress; Standard has nothing sealed to guard. */
	public static function levelTakesAddons(string $level): bool {
		return ProtectionLevel::isAtLeast($level, ProtectionLevel::PRIVATE_);
	}

	/**
	 * The three lines for one rung of one service.
	 *
	 * Falls back to the default flavour a line at a time, so a service that
	 * needs its own words for one rung does not have to restate the others.
	 */
	public static function copy(string $level, string $service = self::SERVICE_DEFAULT): array {
		$catalog = self::catalog();
		$level = ProtectionLevel::normalize($level);
		if (isset($catalog[$service][$level])) {
			return $catalog[$service][$level];
		}
		return $catalog[self::SERVICE_DEFAULT][$level]
			?? array('', '', '');
	}

	/**
	 * Which rungs to show, in ladder order, filtered to the ones this platform
	 * knows. A consumer passes its own subset; omitting the option offers the
	 * whole ladder, which almost nothing should do.
	 */
	public static function levels(array $requested = null): array {
		$requested = $requested === null ? ProtectionLevel::ORDER : $requested;
		$out = array();
		foreach (ProtectionLevel::ORDER as $level) {
			if (in_array($level, $requested, true)) {
				$out[] = $level;
			}
		}
		return $out;
	}

	/**
	 * Emit the picker.
	 *
	 * @param object $formwriter a FormWriterV2 instance
	 * @param string $field      the field name to submit under
	 * @param array  $options    service, levels, value, label, required,
	 *                           disabled_values, helptext, visibility_rules,
	 *                           addons_note, addons (key => [checked, disabled,
	 *                           label, protects, costs, note, link] — see the
	 *                           class docblock)
	 */
	public static function render($formwriter, string $field, array $options = array()): void {
		$service = $options['service'] ?? self::SERVICE_DEFAULT;
		$levels  = self::levels($options['levels'] ?? null);
		$value   = ProtectionLevel::normalize($options['value'] ?? ProtectionLevel::STANDARD);
		$addons  = self::normalizeAddons($options['addons'] ?? array());

		$active = array();
		foreach ($addons as $key => $addon) {
			if ($addon['checked']) {
				$active[] = $addon['label'];
			}
		}

		$choices = array();
		$descriptions = array();
		foreach ($levels as $level) {
			$choices[$level] = ProtectionLevel::label($level);
			$descriptions[$level] = self::copy($level, $service);
			// The selected card says which add-ons are on (Add-ons rule 4).
			if ($active && $level === $value && self::levelTakesAddons($level)) {
				$descriptions[$level][] = self::ADDONS_HEADING . ' on: ' . implode(', ', $active) . '.';
			}
		}

		$field_options = array(
			'card'         => true,
			'options'      => $choices,
			'descriptions' => $descriptions,
			'value'        => $value,
			'required'     => $options['required'] ?? true,
		);
		if (!empty($options['disabled_values'])) {
			$field_options['disabled_values'] = $options['disabled_values'];
		}
		if (!empty($options['helptext'])) {
			$field_options['helptext'] = $options['helptext'];
		}

		// The add-on block shows only while a level that takes add-ons is
		// selected; FormWriter's visibility rules do the showing and hiding.
		$block_id = $field . '_addons';
		$rules = array();
		if ($addons) {
			$rules = array('default' => array('hide' => array($block_id)));
			foreach ($levels as $level) {
				$rules[$level] = self::levelTakesAddons($level)
					? array('show' => array($block_id))
					: array('hide' => array($block_id));
			}
		}
		// A consumer's rules for fields outside the picker ride along, merged
		// list by list so neither side's show/hide is lost.
		foreach (($options['visibility_rules'] ?? array()) as $level => $extra) {
			foreach (array('show', 'hide') as $which) {
				if (!empty($extra[$which])) {
					$rules[$level][$which] = array_merge($rules[$level][$which] ?? array(), (array)$extra[$which]);
				}
			}
		}
		if (!empty($rules)) {
			$field_options['visibility_rules'] = $rules;
		}

		$formwriter->radioinput($field, $options['label'] ?? 'Protection', $field_options);

		if (!$addons) {
			return;
		}

		// Hidden from the first paint when the starting level takes none, so
		// the block never flashes before the visibility script runs.
		echo '<div id="' . htmlspecialchars($block_id) . '" class="jy-level-addons"'
			. (self::levelTakesAddons($value) ? '' : ' style="display:none"') . '>';
		echo '<div class="jy-level-addons-heading">' . htmlspecialchars(self::ADDONS_HEADING) . '</div>';
		if (!empty($options['addons_note'])) {
			echo '<p class="jy-level-addons-note">' . htmlspecialchars((string)$options['addons_note']) . '</p>';
		}
		foreach ($addons as $key => $addon) {
			$formwriter->checkboxinput(self::addonFieldName($field, $key), $addon['label'], array(
				'switch'   => true,
				'checked'  => $addon['checked'],
				'disabled' => $addon['disabled'],
				'helptext' => trim($addon['protects'] . ' ' . $addon['costs'] . ' ' . $addon['note']),
			));
			if ($addon['link'] !== null) {
				echo '<p class="jy-level-addon-link"><a href="' . htmlspecialchars($addon['link'][0]) . '">'
					. htmlspecialchars($addon['link'][1]) . '</a></p>';
			}
		}
		echo '</div>';
	}

	/** The submitted name of one add-on's switch. */
	public static function addonFieldName(string $field, string $key): string {
		return $field . '_' . $key;
	}

	/**
	 * Fill each consumer-declared add-on from the catalog. Accepts the named
	 * form (checked / disabled / label / protects / costs) or the positional
	 * one ([label, protects, costs, checked, disabled]).
	 */
	protected static function normalizeAddons(array $addons): array {
		$out = array();
		foreach ($addons as $key => $addon) {
			$key = (string)$key;
			if ($key === '' || !is_array($addon)) {
				continue;
			}
			if (array_key_exists(0, $addon)) {
				$addon = array(
					'label'    => $addon[0] ?? '',
					'protects' => $addon[1] ?? '',
					'costs'    => $addon[2] ?? '',
					'checked'  => $addon[3] ?? false,
					'disabled' => $addon[4] ?? false,
				);
			}
			$copy = self::addonCopy($key, $addon);
			$out[$key] = array(
				'label'    => $copy['label'],
				'protects' => $copy['protects'],
				'costs'    => $copy['costs'],
				'checked'  => !empty($addon['checked']),
				'disabled' => !empty($addon['disabled']),
				'note'     => (string)($addon['note'] ?? ''),
				'link'     => (isset($addon['link'][0], $addon['link'][1]) && $addon['link'][0] !== '')
					? array((string)$addon['link'][0], (string)$addon['link'][1]) : null,
			);
		}
		return $out;
	}
}
