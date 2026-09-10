<?php
/**
 * JailProbeParser — a SandboxParserInterface class that reports what the
 * subprocess it runs in can do. Test fixture only: tests/unit/document_text_test.php
 * and tests/security/parser_jail_gate.sh read its answer to prove the fence
 * is the one specs/parser_jail.md describes — the uid, the config file, a
 * socket, a fork, a write into the tree — from inside.
 *
 * Every probe is attempted and its outcome reported; nothing here asserts.
 *
 * @version 1.0
 */
class JailProbeParser implements SandboxParserInterface {

	public static function sandboxParse(string $bytes, array $options): string {
		// A batch caller needs one input that fails: this is it.
		if ($bytes === 'boom') {
			throw new DocumentTextException('boom', DocumentText::FAILED);
		}
		$root = PathHelper::getRootDir();
		$site = PathHelper::getSiteRoot();

		$socket = false;
		try {
			$s = @stream_socket_client('udp://127.0.0.1:9', $errno, $errstr, 1);
			$socket = is_resource($s);
			if ($socket) fclose($s);
		} catch (Throwable $e) {}

		$fork = false;
		try {
			$p = @proc_open(array('/bin/true'), array(), $pipes);
			$fork = is_resource($p);
			if ($fork) proc_close($p);
		} catch (Throwable $e) {}

		$probe = $root . '/jail_probe_' . getmypid();
		$wrote = @file_put_contents($probe, 'x') !== false;
		if ($wrote) @unlink($probe);

		$staged = '/dev/shm/joinery_doctext_probe_' . getmypid();
		$staged_mode = null;
		if (@file_put_contents($staged, 'x') !== false) {
			$staged_mode = sprintf('%04o', @fileperms($staged) & 0777);
			@unlink($staged);
		}

		return json_encode(array(
			'uid'            => function_exists('posix_geteuid') ? posix_geteuid() : null,
			'user'           => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
				? ((posix_getpwuid(posix_geteuid())['name'] ?? '')) : '',
			'config_readable'=> @is_readable($site . '/config/Globalvars_site.php'),
			'socket'         => $socket,
			'fork'           => $fork,
			'wrote_tree'     => $wrote,
			'staged_mode'    => $staged_mode,
			'echo'           => $bytes,
			'option'         => $options['marker'] ?? null,
		));
	}
}
