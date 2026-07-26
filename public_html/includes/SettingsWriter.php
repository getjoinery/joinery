<?php
/**
 * The one path that writes a setting.
 *
 * Every settings page posts through here, so the rules a value is held to no
 * longer depend on which page it arrived from. What may be written comes from
 * the declarations (SettingsDeclarations), not from the rows that happen to
 * exist and not from the fields the page happened to draw.
 *
 * What it enforces:
 *
 *   - Scope. Only declared, non-`managed` names inside the caller's scope. An
 *     undeclared name is a manifest bug and is reported as one.
 *   - Validation. The declared `validation` rule array, run through
 *     FormWriterV2Base::validate() — the platform's validator, unchanged.
 *   - Credentials. A `secret` never carries its stored value into the page, so
 *     an empty submission means "keep". Removal is said out loud, with the
 *     Clear checkbox the renderer puts beside the field.
 *   - The vault gate. A change to a `vault_gated` name needs an open unlock
 *     window; everything else on the page still saves, and the caller is told
 *     what was held back.
 *   - No no-op writes. The settings forms post every field on the page, so
 *     writing unchanged values would re-stamp stg_update_time on a hundred-odd
 *     rows and destroy the only record of when a value actually changed.
 *
 * ── Shadow mode ────────────────────────────────────────────────────────────
 *
 * With ENFORCE_SCOPE off, the writer behaves exactly like the loops it
 * replaces — including creating a row for a submitted name that has none — and
 * logs what it *would* have refused. That log is the evidence for turning
 * enforcement on: the set of names a settings page can submit is not literal in
 * its source (the Email tab builds its fields from each provider's
 * getSettingsFields(), mailbox from the active inbound provider), so no static
 * sweep can answer the question. Exercising the pages can.
 *
 * Enforcement is a constant rather than a setting: a setting that governs
 * settings writes is a circularity nobody wants to debug at 2am. Rollback is
 * reverting the constant.
 *
 * @version 1.0
 */
class SettingsWriter {

	/**
	 * Refuse to write undeclared names, instead of writing them and logging.
	 *
	 * On since 2026-07-26. Shadow mode ran clean across all six settings pages
	 * — General, Email, Plugin Settings, Payment Settings, the mailbox settings
	 * tab and Cloud Storage — with every stored row declared. Rollback is
	 * setting this back to false; nothing else changes with it.
	 */
	const ENFORCE_SCOPE = true;

	/**
	 * Write the declared settings found in a submitted request.
	 *
	 * @param array $input   The submitted values (POST).
	 * @param array $options {
	 *   @type string      $page   Page identifier, for the refusal log and message. Required.
	 *   @type string|null $source Restrict to 'core' or one plugin name. Null allows both.
	 *   @type array|null  $names  Restrict to this explicit name list. Null means "anything in scope".
	 * }
	 * @return array {
	 *   @type string[] $written       Names whose stored value changed.
	 *   @type string[] $refused       Submitted names that are not writable settings.
	 *   @type string[] $vault_blocked Names held back for want of an unlock window.
	 *   @type array    $errors        name => list of validation messages.
	 *   @type string[] $kept_secrets  Secrets left alone because the field came back blank.
	 *   @type string[] $cleared_secrets Secrets wiped because the field came back blank
	 *                                   with its Clear box ticked.
	 * }
	 */
	public static function write(array $input, array $options = array()): array {
		require_once(PathHelper::getIncludePath('data/settings_class.php'));
		require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
		require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
		require_once(PathHelper::getIncludePath('includes/VaultGatedSettings.php'));
		require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

		$page   = (string)($options['page'] ?? 'unknown');
		$source = $options['source'] ?? null;
		$names  = isset($options['names']) && is_array($options['names'])
			? array_flip($options['names'])
			: null;

		$result = array(
			'written'         => array(),
			'refused'         => array(),
			'vault_blocked'   => array(),
			'errors'          => array(),
			'kept_secrets'    => array(),
			'cleared_secrets' => array(),
		);

		// ── 1. Decide what this request is allowed to write ──────────────────
		//
		// Two different refusals, with two different histories.
		//
		// *Out of scope* — a name belonging to core or to another plugin when
		// the caller named one source. The Plugin Settings tab has always
		// enforced this, and it is what stops a crafted post reaching a
		// sibling's rows. It is enforced unconditionally.
		//
		// *Undeclared* — a name no manifest describes, or one marked `managed`.
		// This is the new rule, and it is the one shadow mode measures before
		// it bites: the set of names a settings page can submit is not literal
		// in its source, so the log is the only honest inventory.
		$candidates  = array();   // name => submitted value
		$undeclared  = array();   // relaxed while ENFORCE_SCOPE is off
		foreach ($input as $name => $value) {
			if (!is_string($name)) continue;

			// Form and request plumbing shares this POST and is never a setting.
			if (Setting::isReservedName($name)) continue;

			$declaration = SettingsDeclarations::get($name);

			// A machine-written value is never editable from a form, however
			// the request got here.
			if ($declaration === null || !empty($declaration['managed'])) {
				$result['refused'][] = $name;
				$undeclared[$name] = $value;
				continue;
			}
			// A deactivated plugin's rows persist but are not writable.
			if ($declaration['_source'] !== 'core' && !PluginHelper::isPluginActive($declaration['_source'])) {
				$result['refused'][] = $name;
				continue;
			}
			// A source may mirror a group declared elsewhere, so a name outside
			// the source is in scope when it belongs to a mirrored group. It is
			// the same field shown in a second place, and a field that renders
			// but cannot save is worse than one that never rendered.
			if ($source !== null && $declaration['_source'] !== $source
					&& !in_array($declaration['_group'], self::mirroredGroups($source), true)) {
				$result['refused'][] = $name;
				continue;
			}
			if ($names !== null && !isset($names[$name])) {
				$result['refused'][] = $name;
				continue;
			}

			$candidates[$name] = $value;
		}

		if (!empty($result['refused'])) {
			self::reportRefusals($page, $result['refused']);
		}

		// Shadow mode: an undeclared name is logged but still written, so
		// landing the writer cannot change what a save does before the log says
		// it is safe.
		//
		// Only for an unscoped save, though. A caller that names a source — the
		// Plugin Settings tab, the two plugin pages — already wrote nothing but
		// its own declarations, so relaxing here would not preserve behaviour,
		// it would undo a boundary that was already holding. Shadow mode
		// measures the pages that auto-created; it does not loosen the ones
		// that never did.
		if (!self::ENFORCE_SCOPE && $source === null && $names === null) {
			foreach ($undeclared as $name => $value) {
				$candidates[$name] = $value;
			}
		}

		if (empty($candidates)) {
			return $result;
		}

		// ── 2. Narrow to what actually changed ───────────────────────────────
		// A settings form posts every field on the page, so most of a save is
		// values re-submitted unchanged. Those are neither written nor
		// validated: validating them would let one stored value that predates
		// its rule veto every save on the page, and the admin would have no way
		// to tell which field was blocking — that failure is invisible and has
		// happened here before.
		$existing = new MultiSetting(array(), NULL, NULL, NULL, NULL);
		$existing->load();

		$stored = array();
		foreach ($existing as $row) {
			$stored[$row->get('stg_name')] = $row;
		}

		$changes = array();
		foreach ($candidates as $name => $value) {
			$current = isset($stored[$name]) ? (string)$stored[$name]->get('stg_value') : null;

			// A credential field renders empty by design
			// (FormWriterV2Base::preparePasswordData), so a blank submission
			// cannot mean "clear this" — there would be no way to tell it from
			// "I did not touch it". The Clear checkbox beside the field is how
			// removal is said out loud.
			//
			//   typed a value       → write it
			//   blank               → keep what is stored
			//   blank + Clear       → wipe it
			//
			// A typed value wins over a ticked Clear box: pasting a new key
			// after changing your mind must not silently throw it away.
			if (SettingsDeclarations::isSecret($name) && (string)$value === '') {
				if (empty($input[SettingsFieldRenderer::CLEAR_PREFIX . $name])) {
					if ($current !== null && $current !== '') $result['kept_secrets'][] = $name;
					continue;
				}
				if ($current === null || $current === '') continue;   // nothing to clear
				$result['cleared_secrets'][] = $name;
				$changes[$name] = '';
				continue;
			}

			if ($current !== null && (string)$value === $current) continue;

			$changes[$name] = $value;
		}

		if (empty($changes)) {
			return $result;
		}

		// ── 3. Validate the changes against the declared rules ───────────────
		// The rules come from the manifest, not from whatever the page drew, so
		// a page that never registered a field still cannot write past its rule.
		$result['errors'] = self::validate($changes);
		if (!empty($result['errors'])) {
			return $result;
		}

		// ── 4. Write ─────────────────────────────────────────────────────────
		$session    = SessionControl::get_instance();
		$acting_uid = (int)$session->get_user_id();
		$acting_has_vault  = ($acting_uid > 0) && (UserEncryptionVault::loadForUser($acting_uid) !== null);
		$vault_window_open = $acting_has_vault && VaultUnlock::isOpen($acting_uid);

		foreach ($changes as $name => $value) {
			// Every entry here is a genuine change, which is exactly what the
			// vault gate covers — re-submitting the same value must not demand
			// an unlock.
			if ($acting_has_vault && !$vault_window_open && self::isVaultGated($name)) {
				$result['vault_blocked'][] = $name;
				continue;
			}

			if (isset($stored[$name])) {
				$row = $stored[$name];
				$row->set('stg_value', $value);
				$row->set('stg_update_time', 'NOW()');
				$row->set('stg_usr_user_id', $acting_uid);
				$row->prepare();
				$row->save();
				$result['written'][] = $name;
				continue;
			}

			// A declared setting whose row is missing — seeded before the
			// declaration existed, or a fresh install that has not seeded yet.
			$new = new Setting(NULL);
			$new->set('stg_name', $name);
			$new->set('stg_value', $value);
			$new->set('stg_usr_user_id', $acting_uid);
			$new->set('stg_group_name', 'general');
			try {
				$new->prepare();
				$new->save();
				$result['written'][] = $name;
			} catch (Exception $e) {
				error_log("SettingsWriter[{$page}]: failed to create '{$name}': " . $e->getMessage());
			}
		}

		return $result;
	}

	/**
	 * Run the declared rules for these names through FormWriter's validator.
	 *
	 * @param array $values name => submitted value
	 * @return array name => list of messages (empty when everything passes)
	 */
	public static function validate(array $values): array {
		$rules = array();
		foreach ($values as $name => $unused) {
			$declaration = SettingsDeclarations::get($name);
			if ($declaration === null || empty($declaration['validation'])) continue;
			if (!is_array($declaration['validation'])) continue;
			$rules[$name] = array(
				'rules' => $declaration['validation'],
				'label' => $declaration['label'] ?? $name,
			);
		}
		if (empty($rules)) return array();

		require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
		$validator = new FormWriterV2HTML5('settings_writer', array('csrf' => false));
		foreach ($rules as $name => $spec) {
			$validator->registerValidationField($name, $spec['rules'], $spec['label']);
		}
		$validator->validate($values);
		return $validator->getErrors();
	}

	/**
	 * Turn a write result into the messages an admin sees.
	 *
	 * A refused name is a manifest bug, not admin error, and saying nothing is
	 * how two years of junk rows went unnoticed. Under shadow mode the refusals
	 * were still written, so they are not reported to the admin — only logged.
	 *
	 * @param array  $result     Return value of write().
	 * @param string $page_regex DisplayMessage page pattern, e.g. '~/admin/admin_settings~'.
	 */
	public static function reportTo(array $result, string $page_regex): void {
		$session = SessionControl::get_instance();

		if (!empty($result['vault_blocked'])) {
			$session->save_message(new DisplayMessage(
				'Unlock your vault to change these protected settings, then save again: '
					. htmlspecialchars(implode(', ', $result['vault_blocked'])) . '. '
					. 'Other settings were saved.',
				'Unlock required',
				$page_regex,
				DisplayMessage::MESSAGE_WARNING,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}

		if (self::ENFORCE_SCOPE && !empty($result['refused'])) {
			$session->save_message(new DisplayMessage(
				'These fields were not saved because no manifest declares them: '
					. htmlspecialchars(implode(', ', $result['refused'])) . '. '
					. 'Declare them in settings.json or the owning plugin.json. '
					. 'Everything else on the page was saved.',
				'Undeclared settings',
				$page_regex,
				DisplayMessage::MESSAGE_WARNING,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}

		if (!empty($result['errors'])) {
			$lines = array();
			foreach ($result['errors'] as $name => $messages) {
				$lines[] = $name . ': ' . implode(' ', (array)$messages);
			}
			$session->save_message(new DisplayMessage(
				htmlspecialchars(implode(' | ', $lines)),
				'Nothing saved',
				$page_regex,
				DisplayMessage::MESSAGE_ERROR,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
	}

	// ── internals ────────────────────────────────────────────────────────────

	/**
	 * A setting is vault-gated when its declaration says so, or when the owning
	 * plugin lists it in vaultGatedSettings. Both are read until the manifest
	 * flag is the only source.
	 */
	private static function isVaultGated(string $name): bool {
		return SettingsDeclarations::isVaultGated($name) || VaultGatedSettings::isGated($name);
	}

	/** @var array source => group names it mirrors */
	private static $mirrored = array();

	private static function mirroredGroups(string $source): array {
		if (!isset(self::$mirrored[$source])) {
			self::$mirrored[$source] = SettingsDeclarations::mirrorGroupsFor($source);
		}
		return self::$mirrored[$source];
	}

	private static function reportRefusals(string $page, array $names): void {
		$mode = self::ENFORCE_SCOPE ? 'REFUSED' : 'shadow';
		error_log("SettingsWriter[{$page}] {$mode}: undeclared or unwritable setting name(s) submitted: "
			. implode(', ', $names));
	}
}
