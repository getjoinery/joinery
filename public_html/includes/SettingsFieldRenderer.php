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
 * @version 1.0
 */
class SettingsFieldRenderer {

	/**
	 * Prefix for the checkbox that wipes a credential. Reserved by
	 * Setting::isReservedName(), so it can never become a row itself.
	 */
	const CLEAR_PREFIX = 'clear__';

	/**
	 * Render one group's fields onto a form.
	 *
	 * @param FormWriterV2Base $form
	 * @param string           $group   Group name from the declarations.
	 * @param array $options {
	 *   @type string|null $source    Restrict to 'core' or one plugin name.
	 *   @type array       $disabled  Field names to render disabled.
	 *   @type array       $skip      Field names this page handles itself.
	 *   @type array       $values    Override stored values, name => value.
	 * }
	 * @return string[] The names actually rendered.
	 */
	public static function renderGroup($form, string $group, array $options = array()): array {
		require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));

		$fields = SettingsDeclarations::forGroup($group, $options['source'] ?? null);
		if (empty($fields)) return array();

		$skip = array_flip($options['skip'] ?? array());
		$fields = array_values(array_filter($fields, function ($d) use ($skip) {
			return !isset($skip[$d['name']]);
		}));

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

	private static function renderHeadedGroup($form, string $group, string $label_source, array $options): array {
		$names = self::namesFor($group, $options['source'] ?? null);
		if (empty($names)) return array();

		echo '<h4>' . htmlspecialchars(SettingsDeclarations::groupLabel($label_source, $group)) . '</h4>';
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
		$has_stored = ((string)$stored !== '');
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
		if (!$has_stored) return;

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

	private static function renderField($form, array $declaration, array $triggers, array $options): void {
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

		if (!empty($declaration['secret'])) {
			self::secretField($form, $name, $label, $value, $field, $declaration);
			return;
		}

		$type = $declaration['type'] ?? 'text';
		switch ($type) {
			case 'checkbox':
				$field['checked'] = ((string)$value === '1');
				$form->checkboxinput($name, $label, $field);
				return;

			case 'select':
				$field['options'] = SettingsDeclarations::resolveOptions($declaration);
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
		$dependants = array();   // trigger => value => [names]

		foreach ($fields as $declaration) {
			if (empty($declaration['show_when']) || !is_array($declaration['show_when'])) continue;
			foreach ($declaration['show_when'] as $trigger => $trigger_value) {
				$dependants[$trigger][(string)$trigger_value][] = $declaration['name'];
				// A credential's Clear box travels with the field it clears.
				// Left out, a hidden credential leaves an orphaned "Clear the
				// stored X" checkbox on screen with no field above it. The
				// generated script skips ids it cannot find, so naming the box
				// when it was not rendered costs nothing.
				if (!empty($declaration['secret'])) {
					$dependants[$trigger][(string)$trigger_value][] = self::CLEAR_PREFIX . $declaration['name'];
				}
			}
		}

		// Index the group so a trigger's full option set is reachable.
		$by_name = array();
		foreach ($all_in_group as $declaration) {
			$by_name[$declaration['name']] = $declaration;
		}

		$rules = array();
		foreach ($dependants as $trigger => $by_value) {
			// Every value the trigger can take needs an entry, not just the
			// ones something depends on — otherwise switching to a value with
			// no dependants leaves the previous value's fields on screen.
			$values = array_keys($by_value);
			if (isset($by_name[$trigger])) {
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
}
