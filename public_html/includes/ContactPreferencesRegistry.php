<?php
/**
 * ContactPreferencesRegistry — sections a plugin adds to the member's email
 * preferences page, /profile/contact_preferences (spec email_lists_plugin G6).
 *
 * Core owns the page: who it is for (the signed-in member, or the person a
 * footer link names, proved by its hash), the form, and the API action
 * contact_preferences. Each section is a contributor's:
 *
 *   ContactPreferencesRegistry::register('my_plugin_lists', array(
 *       'render' => function (FormWriterV2Base $fw, User $user, array $context) { ... },
 *       'save'   => function (User $user, array $input, array $context): array { return $messages; },
 *   ));
 *
 * $context['via_link'] is true when the person came through an email
 * footer's link rather than signing in. Such a link travels with every email
 * and any forward of it, so a section offers only what is safe for whoever
 * holds the link to do (stop mail, never start it).
 *
 * save() returns messages for the page, each
 * ['message_title' => ..., 'message' => ..., 'message_type' => 'success'|'warn'|'error'].
 * With no sections registered the page says there is nothing to change and
 * points to notification preferences.
 *
 * @version 1.0
 */
class ContactPreferencesRegistry {

	/** @var array<string,array> id => definition */
	private static $sections = array();

	/** Register a section. Idempotent (last wins by id). */
	public static function register(string $id, array $definition): void {
		self::$sections[$id] = $definition;
	}

	public static function hasSections(): bool {
		return !empty(self::$sections);
	}

	/** Each section adds its inputs to the form. */
	public static function render($formwriter, User $user, array $context = array()): void {
		foreach (self::$sections as $id => $def) {
			if (isset($def['render']) && is_callable($def['render'])) {
				call_user_func($def['render'], $formwriter, $user, $context);
			}
		}
	}

	/**
	 * Each section saves what it was posted. A section that throws is logged
	 * and reported on the page; the others still save.
	 *
	 * @return array messages for the page
	 */
	public static function save(User $user, array $input, array $context = array()): array {
		$messages = array();
		foreach (self::$sections as $id => $def) {
			if (!isset($def['save']) || !is_callable($def['save'])) {
				continue;
			}
			try {
				$got = call_user_func($def['save'], $user, $input, $context);
				if (is_array($got)) {
					$messages = array_merge($messages, $got);
				}
			} catch (Throwable $e) {
				error_log('[ContactPreferencesRegistry] ' . $id . ' failed for user ' . $user->key . ': ' . $e->getMessage());
				$messages[] = array(
					'message_title' => 'Not saved',
					'message'       => 'Part of your preferences could not be saved. Please try again.',
					'message_type'  => 'error',
				);
			}
		}
		return $messages;
	}

	/** Clear the registry (tests only). */
	public static function resetCache(): void {
		self::$sections = array();
	}
}
