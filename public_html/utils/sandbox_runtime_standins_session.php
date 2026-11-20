<?php
/**
 * sandbox_runtime_standins_session.php - the SessionControl and DbConnector
 * stand-ins SandboxRuntime puts in those singletons, inside the fence
 * (utils/sandbox_runtime.php). Loading this file loads the real classes it
 * extends, and core files they load ask for settings, so it is required only
 * once the Globalvars stand-in is in place. Never by the site itself.
 *
 * @version 1.0
 */

/** Who is viewing, from the envelope. */
class SandboxSession extends SessionControl {

	public function __construct() {
	}

	function get_user_id($initial_user = FALSE) {
		$viewer = SandboxRuntime::viewer();
		return $viewer ? (int)$viewer['id'] : null;
	}

	function is_logged_in() {
		return SandboxRuntime::viewer() !== null;
	}

	function get_permission() {
		$viewer = SandboxRuntime::viewer();
		return $viewer ? (int)($viewer['permission'] ?? 0) : 0;
	}

	function get_timezone($default = NULL) {
		$viewer = SandboxRuntime::viewer();
		if ($viewer && !empty($viewer['timezone'])) {
			return (string)$viewer['timezone'];
		}
		return (string)(SandboxRuntime::$envelope['site']['default_timezone'] ?? ($default ?: 'UTC'));
	}

	/**
	 * Whether the viewer is at $level. Core checked who may reach the page
	 * before the call; this answers, it never redirects.
	 */
	function check_permission($level, $msgtext = NULL) {
		if (!$this->is_logged_in() || !Access::allows((int)$level, $this->get_permission())) {
			throw new SandboxRuntimeException('The viewer is not at level ' . (int)$level . '.');
		}
		return true;
	}
}

/** No database. */
class SandboxDb extends DbConnector {

	const MESSAGE = 'A sandboxed plugin has no database; use a model.';

	public function __construct() {
	}

	public function get_db_link() {
		throw new SandboxRuntimeException(self::MESSAGE);
	}

	public function reconnect() {
		throw new SandboxRuntimeException(self::MESSAGE);
	}

	public function prepare_query($sql) {
		throw new SandboxRuntimeException(self::MESSAGE);
	}

	public function bind_value($name, $value, $type) {
		throw new SandboxRuntimeException(self::MESSAGE);
	}

	public function execute_query() {
		throw new SandboxRuntimeException(self::MESSAGE);
	}

	public function fetch_query_row() {
		throw new SandboxRuntimeException(self::MESSAGE);
	}

	public function set_test_mode() {
		throw new SandboxRuntimeException(self::MESSAGE);
	}

	function _destruct() {
	}
}

/** The PHP session's storage in the fence: none. */
class SandboxSessionHandler implements SessionHandlerInterface {

	public function open(string $path, string $name): bool {
		return true;
	}

	public function close(): bool {
		return true;
	}

	public function read(string $id): string|false {
		return '';
	}

	public function write(string $id, string $data): bool {
		return true;
	}

	public function destroy(string $id): bool {
		return true;
	}

	public function gc(int $max_lifetime): int|false {
		return 0;
	}
}
