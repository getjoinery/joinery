<?php
/**
 * sandbox_runtime.php - what a sandboxed PHP plugin's code finds in the fence
 * under the names native code uses (specs/sandboxed_php_plugins.md design 4).
 *
 * Loaded by utils/sandbox_php_runner.php, inside the fence, before any of the
 * plugin's code. It is not in includes/, so the site's own class map never
 * sees these classes.
 *
 * Core boots the way the document parser's extractor does: the real
 * PathHelper, and ClassAutoloader::restrictToCore(), so core classes resolve
 * from the code tree alone, with no settings and no database. The three
 * singletons native code reaches for first are then filled with stand-ins
 * before anything asks for them, so their real constructors (which read the
 * config file, start a session, open the database) never run:
 *
 *   Globalvars::get_instance()     SandboxGlobalvars: the plugin's own settings (from
 *                                  the envelope, by their manifest names or their full
 *                                  sbx_{plugin}__{name} names) and four public facts of
 *                                  the site: site_name, webDir, default_timezone,
 *                                  default_email_template. Any
 *                                  other name answers unset, whatever it is called.
 *   SessionControl::get_instance() SandboxSession: who is viewing (id, level, time zone),
 *                                  from the envelope. No cookie, no CSRF secret, no unlock
 *                                  window. The PHP session is one of this process alone,
 *                                  kept nowhere (SandboxSessionHandler): whatever is written
 *                                  to $_SESSION dies with the call.
 *   DbConnector::get_instance()    SandboxDb: every use throws, saying to use a model.
 *
 * The plugin's own classes resolve from its src/includes and src/data, after
 * core's (a class named like a core one never shadows it), by their full
 * names when a file declares a namespace.
 *
 * Core code that catches an exception answers quietly in the fence: a false
 * from such code (PluginHelper::isPluginActive() is false for every plugin
 * here) is not an answer about the site. What core classes
 * need beyond these, and so which work in the fence, is listed in the spec (V5).
 *
 * Nothing here is a wall: the fence is. A plugin may replace any of this in
 * its own process; it gains only what its own asks to core could give it.
 *
 * Mail (sandbox_runtime_mail.php): EmailSender and the template renderer
 * are core's. EmailSender::remote() asks joinery_fence_mail_remote(),
 * defined below, at its first use, however EmailSender's file was loaded;
 * each send and template fill then goes to the site, which runs it with the
 * same classes. A call that sends no mail loads none of it.
 *
 * @version 1.3 - mail: EmailSender::$remote (sandbox_runtime_mail.php, spec sandboxed_plugin_email)
 * @version 1.2 - the model layer (sandbox_runtime_models.php): SystemBase::$remote_store, the plugin's models,
 *                and the classes of the native plugins whose models it was approved for (envelope class_dirs)
 * @version 1.1 - namespaced plugin classes resolve (reviewer2 F2)
 * @version 1.0
 */

/**
 * The fence's mail (EmailSender::remote() asks for it at its first use).
 * Defined only here, in the fence's runtime, which the site never loads.
 */
function joinery_fence_mail_remote(): EmailRemote {
	require_once __DIR__ . '/sandbox_runtime_mail.php';
	return new SandboxRemoteMail();
}

/** What a stand-in throws for something the fence does not have. */
class SandboxRuntimeException extends Exception {}

/** The runtime itself: the envelope, the plugin's src/ and its class index. */
final class SandboxRuntime {

	/** @var array */
	public static $envelope = array();

	/** @var string the plugin's src/, real path */
	public static $src = '';

	/** @var array<string,string> the plugin's classes (lower case, full name) => file */
	private static $index = array();

	/** @var array<string,string> classes of the native plugins whose models it was approved for => file */
	private static $native = array();

	/** @var array<string,string> core's classes (includes/, data/) => file */
	private static $core = array();

	/** Set up the runtime for one call. $tree is the code tree (public_html). */
	public static function boot(array $envelope, string $tree, string $src): void {
		self::$envelope = $envelope;
		self::$src = $src;

		require_once $tree . '/includes/PathHelper.php';
		ClassAutoloader::restrictToCore();

		// Settings first: some core files ask for them as they load.
		require_once __DIR__ . '/sandbox_runtime_standins.php';
		(new ReflectionProperty('Globalvars', 'instance_map'))->setValue(null, array(PathHelper::getRootDir() => new SandboxGlobalvars()));
		require_once __DIR__ . '/sandbox_runtime_standins_session.php';
		(new ReflectionProperty('SessionControl', 'instance'))->setValue(null, new SandboxSession());
		// A session of this process alone, kept nowhere, so code that starts
		// one (FormWriter's token) finds it started: the site's session folder
		// is outside the fence, and nothing written here outlives the call.
		// Through SessionGuard, as every session starts, with this handler in
		// place of its guarded one: nothing is read, so nothing is decoded.
		session_set_save_handler(new SandboxSessionHandler(), true);
		(new ReflectionProperty('SessionGuard', 'installed'))->setValue(null, true);
		@SessionGuard::start(array('use_cookies' => 0, 'use_only_cookies' => 1, 'cache_limiter' => ''));
		(new ReflectionProperty('DbConnector', 'instance'))->setValue(null, new SandboxDb());

		// Core's classes by a scan of its declarations (about 15 ms): core's
		// own loader would tokenize every file to build its map, which in a
		// process that lives for one call costs far more.
		self::$core = array();
		self::scan($tree . '/includes', self::$core);
		self::scan($tree . '/data', self::$core);
		self::$index = array();
		self::$native = array();
		foreach ((array)($envelope['class_dirs'] ?? array()) as $dir) {
			if (is_string($dir) && is_dir($dir)) {
				self::scan($dir, self::$native);
			}
		}
		foreach (array($src . '/includes', $src . '/data') as $dir) {
			self::scan($dir, self::$index);
		}
		// Ahead of core's loader: core's classes first, then the approved
		// native plugins', then the plugin's own, then its models.
		spl_autoload_register(array(__CLASS__, 'load'), true, true);

		// The model layer (design 5): every model reads and writes through core.
		require_once __DIR__ . '/sandbox_runtime_models.php';
		SandboxFenceModels::register((string)($envelope['plugin'] ?? ''), (array)($envelope['models'] ?? array()));
		spl_autoload_register(array('SandboxFenceModels', 'load'));
		SystemBase::$remote_store = new SandboxRemoteStore();

	}

	/** Index the classes declared in the .php files beneath $dir into $index. */
	private static function scan(string $dir, array &$into): void {
		if (!is_dir($dir) || is_link($dir)) {
			return;
		}
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$path = (string)$file;
			if (substr($path, -4) !== '.php' || !is_file($path) || is_link($path)) {
				continue;
			}
			$text = @file_get_contents($path);
			if (!is_string($text)) {
				continue;
			}
			// One namespace per file, as PSR-4 has it: the class is known by
			// its full name.
			$namespace = preg_match('~^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;~m', $text, $ns) ? trim($ns[1], '\\') . '\\' : '';
			if (preg_match_all('~^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)~m', $text, $m)) {
				foreach ($m[1] as $class) {
					$full = strtolower($namespace . $class);
					if (!isset($into[$full])) {
						$into[$full] = $path;
					}
				}
			}
		}
	}

	/** The plugin's autoloader. Class names are case-insensitive, as PHP's are. */
	public static function load(string $class): void {
		$key = strtolower(ltrim($class, '\\'));
		if (isset(self::$core[$key])) {
			require_once self::$core[$key];
		} elseif (isset(self::$native[$key])) {
			require_once self::$native[$key];
		} elseif (isset(self::$index[$key])) {
			require_once self::$index[$key];
		}
	}

	/**
	 * Whether core knows a model by this class: core's, an approved native
	 * plugin's, or one of the plugin's declared models (Sbx_{plugin}_...).
	 */
	public static function isKnownModelClass(string $class): bool {
		$key = strtolower(ltrim($class, '\\'));
		if (isset(self::$core[$key]) || isset(self::$native[$key])) {
			return true;
		}
		return strncmp($key, 'sbx_', 4) === 0 && is_subclass_of($class, 'SandboxRecord');
	}

	/** Whether a class is the plugin's own (declared in its src/). */
	public static function isPluginClass(string $class): bool {
		$key = strtolower(ltrim($class, '\\'));
		return isset(self::$index[$key]) && !isset(self::$native[$key]) && !isset(self::$core[$key]);
	}

	/** The viewer, or null on a call with none (a signal, a schedule). */
	public static function viewer(): ?array {
		$user = self::$envelope['input']['user'] ?? null;
		return is_array($user) && isset($user['id']) ? $user : null;
	}
}
