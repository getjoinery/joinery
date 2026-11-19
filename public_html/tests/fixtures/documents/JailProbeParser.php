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
 * @version 1.1 - stages where the extractor does (the fenced run's own folder when
 *   there is one), reads the config file rather than asking access(2), which
 *   Landlock does not answer, and reports /etc/passwd and /dev/shm itself
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

		// Where DocumentText::stage() puts a container: the fenced run's own
		// folder (TMPDIR) under the allowlist fence, /dev/shm otherwise.
		$tmp = getenv('TMPDIR');
		$dir = (is_string($tmp) && strpos($tmp, DocumentText::JAIL_RUN_FOLDER_PREFIX) === 0) ? $tmp : '/dev/shm';
		$staged = $dir . '/joinery_doctext_probe_' . getmypid();
		$staged_mode = null;
		if (@file_put_contents($staged, 'x') !== false) {
			$staged_mode = sprintf('%04o', @fileperms($staged) & 0777);
			@unlink($staged);
		}
		$shm = '/dev/shm/joinery_probe_direct_' . getmypid();
		$shm_writable = @file_put_contents($shm, 'x') !== false;
		if ($shm_writable) @unlink($shm);

		return json_encode(array(
			'uid'            => function_exists('posix_geteuid') ? posix_geteuid() : null,
			'user'           => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
				? ((posix_getpwuid(posix_geteuid())['name'] ?? '')) : '',
			// An open, not is_readable(): access(2) answers by file mode only,
			// and Landlock refuses the open, not the question.
			'config_readable'=> @file_get_contents($site . '/config/Globalvars_site.php', false, null, 0, 1) !== false,
			'passwd_readable'=> @file_get_contents('/etc/passwd', false, null, 0, 1) !== false,
			'shm_writable'   => $shm_writable,
			'staged_in_run_folder' => $dir !== '/dev/shm',
			'socket'         => $socket,
			'fork'           => $fork,
			'wrote_tree'     => $wrote,
			'staged_mode'    => $staged_mode,
			'echo'           => $bytes,
			'option'         => $options['marker'] ?? null,
		));
	}
}
