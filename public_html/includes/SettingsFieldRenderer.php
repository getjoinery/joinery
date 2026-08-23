<?php
/**
 * The only code that turns a setting into a form field.
 *
 * A page asks for a group and gets the fields the manifests declare for it,
 * with their labels, types, options, help text, validation and conditional
 * visibility already applied. Two pages that show the same group show the same
 * field, so they cannot drift apart.
 *
 * A page still decides *whether* to render a group, may disable fields inside
 * it, and may print whatever state and explanation belongs around it — that is
 * reasoning about the deployment, which is the page's job. What it may not do
 * is invent a field the manifest does not declare.
 *
 * @version 1.4
 * @changelog 1.4 - field_options learns skip_options and option_labels for
 *   selects, so a page can drop a choice it cannot offer or annotate one —
 *   still narrowing and labeling only, never inventing a choice.
 */
class SettingsFieldRenderer {

	/**
	 * Prefix for the checkbox that wipes a credential. Reserved by
	 * Setting::isReservedName(), so it can never become a row itself.
	 */
	const CLEAR_PREFIX = 'clear__';

	/**
	 * Set while this class is emitting. FormWriterV2Base::registerField() reads
	 * it to tell "the renderer is drawing a declared setting" from "a page is
	 * drawing one behind the renderer's back", which is the rule that keeps two
	 * pages from growing two versions of one field.
	 *
	 * A counter rather than a flag: secretField() is public and is reached both
	 * directly and through renderField(), so the nested call must not clear the
	 * outer one on the way out.
	 */
	private static $emitting = 0;

	/** True while a settings field is being drawn by this class. */
	public static function isEmitting(): bool {
		return self::$emitting > 0;
	}

	/**
	 * Render one group's fields onto a form.
	 *
	 * @param FormWriterV2Base $form
	 * @param string           $group   Group name from the declarations.
	 * @param array $options {
	 *   @type string|null $source    Restrict to 'core' or one plugin name.
	 *   @type array       $disabled  Field names to render disabled.
	 *   @type array       $only      Render just these names, in manifest order.
	 *   @type array       $skip      Field names this page handles itself.
	 *   @type array       $values    Override stored values, name => value.
	 *   @type array       $field_options  name => extra FormWriter options, for
	 *                                page context around a declared field. Four
	 *                                keys are read here rather than passed on:
	 *                                `helptext_append` adds to the declared help
	 *                                rather than replacing it;
	 *                                `clearable => false` drops a credential's
	 *                                Clear box on a page that cannot honour it;
	 *                                `skip_options` (select only) drops declared
	 *                                choices the page cannot offer — narrowing
	 *                                only, it cannot invent a choice; and
	 *                                `option_labels` (select only, key => label)
	 *                                annotates a declared choice's label for
	 *                                page context, ignored for keys the
	 *                                declaration does not offer.
	 * }
	 * @return string[] The names actually rendered.
	 */
	public static function renderGroup($form, string $group, array $options = array()): array {
		require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));

		$fields = SettingsDeclarations::forGroup($group, $options['source'] ?? null);
		if (empty($fields)) return array();

		$fields = self::selected($fields, $options);

		// show_when is declared on the field that gets hidden; FormWriter wants
		// the rules on the field that does the hiding. Inverting is done over
		// everything the page will render, not just this group — a picker in
		// one box routinely reveals fields in the next one.
		$triggers = $options['triggers'] ?? self::buildVisibilityRules($fields, $fields);

		$rendered = array();
		foreach ($fields as $declaration) {
			self::renderField($form, $declaration, $triggers, $options);
			$rendered[] = $declaration['name'];
		}
		return $rendered;
	}

	/**
	 * Render every group a source declares, in manifest order. This is what the
	 * Plugin Settings tab hands a plugin, and what gives a declared setting a
	 * home without anyone having to remember to give it one.
	 *
	 * @return string[] The names actually rendered.
	 */
	public static function renderSource($form, string $source, array $options = array()): array {
		require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));

		// Everything this page will render, in order: the source's own groups,
		// then any group it mirrors from elsewhere.
		$plan = array();
		foreach (SettingsDeclarations::groupsFor($source) as $group) {
			$plan[] = array('group' => $group, 'source' => $source, 'label_source' => $source);
		}
		foreach (SettingsDeclarations::mirrorGroupsFor($source) as $group) {
			$plan[] = array('group' => $group, 'source' => 'core', 'label_source' => 'core');
		}

		// Build the visibility rules across the whole page before rendering
		// anything, so a picker in one box can reveal fields in a later one —
		// which is exactly what the inbound provider does.
		$everything = array();
		foreach ($plan as $step) {
			foreach (SettingsDeclarations::forGroup($step['group'], $step['source']) as $declaration) {
				$everything[] = $declaration;
			}
		}
		$triggers = self::buildVisibilityRules($everything, $everything);

		$rendered = array();
		foreach ($plan as $step) {
			$rendered = array_merge($rendered, self::renderHeadedGroup(
				$form,
				$step['group'],
				$step['label_source'],
				array('source' => $step['source'], 'triggers' => $triggers) + $options
			));
		}

		return $rendered;
	}

	/**
	 * Render several groups in order, each under its declared heading. This is
	 * how the core settings tabs are built: the tab names the groups it shows,
	 * and the manifest supplies both the heading and the fields.
	 *
	 * @param string[] $groups
	 * @return string[] The names actually rendered.
	 */
	public static function renderGroups($form, array $groups, array $options = array()): array {
		$source = $options['source'] ?? 'core';
		$rendered = array();
		foreach ($groups as $group) {
			$rendered = array_merge($rendered, self::renderHeadedGroup(
				$form, $group, $source, array('source' => $source) + $options
			));
		}
		return $rendered;
	}

	private static function renderHeadedGroup($form, string $group, string $label_source, array $options): array {
		$fields = SettingsDeclarations::forGroup($group, $options['source'] ?? null);
		if (empty(self::selected($fields, $options))) return array();

		$heading = $options['heading_level'] ?? 'h4';
		echo '<' . $heading . '>' . htmlspecialchars(SettingsDeclarations::groupLabel($label_source, $group))
		   . '</' . $heading . '>';
		return self::renderGroup($form, $group, $options);
	}

	/**
	 * The names renderSource() would emit — without rendering.
	 *
	 * The rendered-is-declared check drives this rather than reading page
	 * source: the set of names a settings page emits is not literal in its
	 * source, so a grep-based sweep reports clean and is wrong.
	 *
	 * @return string[]
	 */
	public static function renderSourceNames(string $source): array {
		require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));

		$names = array();
		foreach (SettingsDeclarations::groupsFor($source) as $group) {
			$names = array_merge($names, self::namesFor($group, $source));
		}
		foreach (SettingsDeclarations::mirrorGroupsFor($source) as $group) {
			$names = array_merge($names, self::namesFor($group, 'core'));
		}
		return $names;
	}

	/**
	 * The names this renderer would emit for one group — without rendering.
	 *
	 * @return string[]
	 */
	public static function namesFor(string $group, ?string $source = null): array {
		require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));

		$names = array();
		foreach (SettingsDeclarations::forGroup($group, $source) as $declaration) {
			$names[] = $declaration['name'];
		}
		return $names;
	}

	/**
	 * Render one credential field, plus the checkbox that wipes it.
	 *
	 * A credential never carries its stored value into the page — the field
	 * says only that something is stored, and a blank submission keeps it. That
	 * leaves no way to express "remove this", so a field with something stored
	 * gets a Clear box beside it. The three cases the writer honours:
	 *
	 *   typed a value          → that value is written
	 *   left blank             → the stored value is kept
	 *   left blank + Clear     → the stored value is wiped
	 *
	 * A typed value wins over a ticked Clear box, so pasting a new key after
	 * changing your mind cannot silently throw it away.
	 *
	 * Public because pages that still draw their own credential fields (the
	 * Email tab's provider loop, the store's payment page) need the same
	 * control and the same contract.
	 *
	 * @param FormWriterV2Base $form
	 * @param string $name  Declared setting name.
	 * @param string $label Field label.
	 * @param mixed  $stored Current stored value — used only to decide whether
	 *                       anything is there to clear. Never rendered.
	 * @param array  $field  Extra FormWriter options (helptext, validation, …).
	 * @param array  $declaration The declaration, for `type` and `rows`.
	 */
	public static function secretField($form, string $name, string $label, $stored,
	                                   array $field = array(), array $declaration = array()): void {
		self::$emitting++;
		try {
			self::emitSecretField($form, $name, $label, $stored, $field, $declaration);
		} finally {
			self::$emitting--;
		}
	}

	private static function emitSecretField($form, string $name, string $label, $stored,
	                                        array $field, array $declaration): void {
		$has_stored = ((string)$stored !== '');

		// A page whose save path cannot honour the Clear box suppresses it,
		// rather than showing a control that does nothing. Only a page that
		// writes outside SettingsWriter ever needs this.
		$clearable = !isset($field['clearable']) || $field['clearable'];
		unset($field['clearable']);

		if (!isset($field['placeholder'])) {
			$field['placeholder'] = $has_stored ? '(stored — leave blank to keep)' : '';
		}
		$field['value'] = '';

		// A hand-drawn caller gets the declared rules too, so the browser check
		// on its page matches what SettingsWriter enforces on save.
		if (!isset($field['validation'])) {
			$declared = $declaration ?: (SettingsDeclarations::get($name) ?? array());
			if (!empty($declared['validation'])) $field['validation'] = $declared['validation'];
			if (empty($declaration) && !empty($declared)) $declaration = $declared;
		}

		// Some credentials are genuinely multi-line — a PEM private key, a
		// service-account JSON — and a one-line input is the wrong control. The
		// value is withheld either way.
		if (($declaration['type'] ?? 'password') === 'textarea') {
			$field['rows'] = $declaration['rows'] ?? 4;
			$form->textbox($name, $label, $field);
		} else {
			$field['autocomplete'] = 'new-password';
			$form->passwordinput($name, $label, $field);
		}

		// Nothing stored means nothing to clear, and an unconditional checkbox
		// would invite an admin to tick it and wonder what happened.
		if (!$has_stored || !$clearable) return;

		// The label goes in verbatim apart from a trailing parenthetical — those
		// carry an example value ("(Example: sk_live_xxxx)") that reads as
		// noise on a checkbox. No case folding: lcfirst() turns "Mailgun" into
		// "mailgun".
		$short = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $label));

		$form->checkboxinput(self::CLEAR_PREFIX . $name, 'Clear the stored ' . ($short !== '' ? $short : $label), array(
			'checked'  => false,
			'helptext' => 'Removes the stored value on save. Ignored if you enter a new one above.',
		));
	}

	// ── internals ────────────────────────────────────────────────────────────

	/**
	 * Narrow a group to what this page shows. `only` names the fields to keep
	 * and `skip` the ones to leave out — a page that splits one declared group
	 * across two boxes uses `only` twice rather than duplicating the group.
	 *
	 * Neither can add a field: both filter a set the manifest decided.
	 */
	private static function selected(array $fields, array $options): array {
		if (isset($options['only'])) {
			$only = array_flip($options['only']);
			$fields = array_filter($fields, function ($d) use ($only) {
				return isset($only[$d['name']]);
			});
		}
		if (!empty($options['skip'])) {
			$skip = array_flip($options['skip']);
			$fields = array_filter($fields, function ($d) use ($skip) {
				return !isset($skip[$d['name']]);
			});
		}
		return array_values($fields);
	}

	private static function renderField($form, array $declaration, array $triggers, array $options): void {
		self::$emitting++;
		try {
			self::emitField($form, $declaration, $triggers, $options);
		} finally {
			self::$emitting--;
		}
	}

	private static function emitField($form, array $declaration, array $triggers, array $options): void {
		$settings = Globalvars::get_instance();
		$name     = $declaration['name'];
		$label    = $declaration['label'] ?? $name;

		$value = array_key_exists($name, $options['values'] ?? array())
			? $options['values'][$name]
			: $settings->get_setting($name);

		$field = array();
		if (!empty($declaration['helptext']))   $field['helptext']   = $declaration['helptext'];
		if (!empty($declaration['validation'])) $field['validation'] = $declaration['validation'];
		if (!empty($declaration['help_modal'])) $field['help_modal'] = $declaration['help_modal'];
		if (in_array($name, $options['disabled'] ?? array(), true)) $field['disabled'] = true;
		if (isset($triggers[$name])) $field['visibility_rules'] = $triggers[$name];

		// Page context: a prefix on the field, a note about what the active
		// theme would use, a sort order. This adds to a declared field; it
		// cannot introduce one, and it cannot change what the field is.
		$extra = $options['field_options'][$name] ?? array();
		if (isset($extra['helptext_append'])) {
			$field['helptext'] = trim(($field['helptext'] ?? '') . ' ' . $extra['helptext_append']);
			unset($extra['helptext_append']);
		}
		$skip_options  = (array)($extra['skip_options'] ?? array());
		$option_labels = (array)($extra['option_labels'] ?? array());
		unset($extra['skip_options'], $extra['option_labels']);
		$field = array_merge($field, $extra);

		if (!empty($declaration['secret'])) {
			self::secretField($form, $name, $label, $value, $field, $declaration);
			return;
		}

		$type = $declaration['type'] ?? 'text';
		switch ($type) {
			case 'checkbox':
				// A browser posts nothing for an unticked box, and "absent" is
				// indistinguishable from "not on this page" — so untick would
				// never save. A hidden 0 of the same name, written first, means
				// the box always submits: PHP keeps the later value, which is
				// the 1 the checkbox posts when it is ticked.
				$form->hiddeninput($name, array('value' => '0', 'id' => $name . '_unchecked'));
				$field['checked'] = ((string)$value === '1');
				$form->checkboxinput($name, $label, $field);
				return;

			case 'select':
				// A page may narrow the declared choices or annotate a label,
				// never add a choice the declaration does not offer.
				$choices = SettingsDeclarations::resolveOptions($declaration);
				foreach ($skip_options as $choice) {
					unset($choices[$choice]);
				}
				foreach ($option_labels as $choice => $choice_label) {
					if (isset($choices[$choice])) $choices[$choice] = $choice_label;
				}
				$field['options'] = $choices;
				$field['value'] = $value;
				$field['empty_option'] = $declaration['empty_option'] ?? false;
				$form->dropinput($name, $label, $field);
				return;

			case 'textarea':
				$field['value'] = $value;
				$field['rows'] = $declaration['rows'] ?? 5;
				$form->textbox($name, $label, $field);
				return;

			case 'number':
				$field['value'] = $value;
				// The declared floor and ceiling also drive the browser's own
				// spinner, so the two agree without being written twice.
				if (isset($declaration['validation']['min'])) $field['min'] = $declaration['validation']['min'];
				if (isset($declaration['validation']['max'])) $field['max'] = $declaration['validation']['max'];
				$form->numberinput($name, $label, $field);
				return;

			case 'password':
				$field['placeholder'] = ((string)$value !== '') ? '(stored — leave blank to keep)' : '';
				$form->passwordinput($name, $label, $field);
				return;

			case 'color':
				$field['value'] = $value;
				if (!isset($field['sort'])) $field['sort'] = 'frequency';
				$form->colorpicker($name, $label, $field);
				return;

			default:
				$field['value'] = $value;
				$form->textinput($name, $label, $field);
		}
	}

	/**
	 * Turn every `show_when` in a group into FormWriter visibility_rules keyed
	 * by the field that triggers them.
	 *
	 * A declaration says "show me when mailbox_provider is mailgun". FormWriter
	 * wants mailbox_provider to say "when I am mailgun, show these". Collecting
	 * the whole group first is what lets one trigger control several dependants
	 * and lets one value show some fields while hiding others.
	 *
	 * @return array trigger name => rules array
	 */
	private static function buildVisibilityRules(array $fields, array $all_in_group): array {
		// Index the group first: whether a trigger is a checkbox decides what
		// its rule keys are called, and that has to be known while collecting.
		$by_name = array();
		foreach ($all_in_group as $declaration) {
			$by_name[$declaration['name']] = $declaration;
		}

		$dependants = array();   // trigger => key => [names]

		foreach ($fields as $declaration) {
			if (empty($declaration['show_when']) || !is_array($declaration['show_when'])) continue;
			foreach ($declaration['show_when'] as $trigger => $trigger_value) {
				$key = self::visibilityKey($by_name[$trigger] ?? array(), $trigger_value);
				$dependants[$trigger][$key][] = $declaration['name'];
				// A credential's Clear box travels with the field it clears.
				// Left out, a hidden credential leaves an orphaned "Clear the
				// stored X" checkbox on screen with no field above it. The
				// generated script skips ids it cannot find, so naming the box
				// when it was not rendered costs nothing.
				if (!empty($declaration['secret'])) {
					$dependants[$trigger][$key][] = self::CLEAR_PREFIX . $declaration['name'];
				}
			}
		}

		$rules = array();
		foreach ($dependants as $trigger => $by_value) {
			// Every state the trigger can take needs an entry, not just the ones
			// something depends on — otherwise switching to a state with no
			// dependants leaves the previous state's fields on screen.
			$values = array_keys($by_value);
			if (($by_name[$trigger]['type'] ?? '') === 'checkbox') {
				$values = array('checked', 'unchecked');
			} elseif (isset($by_name[$trigger])) {
				$options = SettingsDeclarations::resolveOptions($by_name[$trigger]);
				if (!empty($options)) $values = array_map('strval', array_keys($options));
			}

			foreach ($values as $value) {
				$show = $by_value[$value] ?? array();
				$hide = array();
				foreach ($by_value as $other_value => $names) {
					if ((string)$other_value === (string)$value) continue;
					foreach ($names as $n) {
						if (!in_array($n, $show, true)) $hide[] = $n;
					}
				}
				$rules[$trigger][$value] = array('show' => $show, 'hide' => array_values(array_unique($hide)));
			}
		}

		return $rules;
	}

	/**
	 * What FormWriter calls the state a show_when describes.
	 *
	 * A select keys on its value, but a checkbox keys on whether it is ticked —
	 * FormWriter rejects a checkbox rule keyed on "1", because a rule written
	 * that way silently never fires. A declaration says show_when: {x: "1"}
	 * either way; the translation belongs here, not in every manifest.
	 */
	private static function visibilityKey(array $trigger_declaration, $value): string {
		if (($trigger_declaration['type'] ?? '') !== 'checkbox') {
			return (string)$value;
		}
		return ((string)$value === '1') ? 'checked' : 'unchecked';
	}
}
