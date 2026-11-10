<?php
/**
 * SignupFieldRegistry — fields a plugin adds to the forms that create an
 * account (spec email_lists_plugin G5).
 *
 * Two forms create accounts: the public registration form (/register,
 * context 'register') and the admin add-user form (/admin/admin_user_add,
 * context 'admin_add'). A contributor registers, usually from its serve.php:
 *
 *   SignupFieldRegistry::register('my_plugin_optin', array(
 *       'contexts' => array('register', 'admin_add'),        // optional, both by default
 *       'render'   => function (FormWriterV2Base $fw, string $context, array $input) { ... },
 *       'handle'   => function (User $user, array $input, string $context) { ... },
 *   ));
 *
 * render() adds its inputs to the form (it may add none, for example while
 * the feature is unconfigured). handle() runs once the account exists, with
 * everything the form posted. A signal subscriber cannot do this:
 * account.signup carries no form input.
 *
 * A failing handle() is logged and never undoes the account or stops the
 * other contributors.
 *
 * @version 1.0
 */
class SignupFieldRegistry {

	const CONTEXT_REGISTER  = 'register';
	const CONTEXT_ADMIN_ADD = 'admin_add';

	/** @var array<string,array> id => definition */
	private static $fields = array();

	/** Register a contributor. Idempotent (last wins by id). */
	public static function register(string $id, array $definition): void {
		self::$fields[$id] = $definition;
	}

	/** The contributors for a context, in registration order. */
	private static function forContext(string $context): array {
		$out = array();
		foreach (self::$fields as $id => $def) {
			$contexts = $def['contexts'] ?? array(self::CONTEXT_REGISTER, self::CONTEXT_ADMIN_ADD);
			if (is_array($contexts) && in_array($context, $contexts, true)) {
				$out[$id] = $def;
			}
		}
		return $out;
	}

	/** Let each contributor add its inputs to the form. */
	public static function render($formwriter, string $context, array $input = array()): void {
		foreach (self::forContext($context) as $id => $def) {
			if (isset($def['render']) && is_callable($def['render'])) {
				call_user_func($def['render'], $formwriter, $context, $input);
			}
		}
	}

	/** Hand the new account and the posted form to each contributor. */
	public static function handle(User $user, array $input, string $context): void {
		foreach (self::forContext($context) as $id => $def) {
			if (!isset($def['handle']) || !is_callable($def['handle'])) {
				continue;
			}
			try {
				call_user_func($def['handle'], $user, $input, $context);
			} catch (Throwable $e) {
				error_log('[SignupFieldRegistry] ' . $id . ' failed for user ' . $user->key . ': ' . $e->getMessage());
			}
		}
	}

	/** Clear the registry (tests only). */
	public static function resetCache(): void {
		self::$fields = array();
	}
}
