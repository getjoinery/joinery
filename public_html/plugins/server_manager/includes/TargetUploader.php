<?php
/**
 * TargetUploader — push files to / delete files from a BackupTarget's bucket.
 *
 * Web-server-side helper — uses S3Signer directly. For node-side uploads (where
 * the backup file lives on the remote node, not the web server), JobCommandBuilder
 * assembles a self-contained uploader script using node_uploader.php + S3Signer.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/S3Signer.php'));

class TargetUploader {

	/**
	 * Upload a local file to the target bucket.
	 * Returns ['success' => bool, 'bytes' => int, 'remote_url' => string, 'error' => ?string].
	 */
	public static function upload($target, $local_path, $remote_key) {
		$bucket = $target->get('bkt_bucket');
		$creds = $target->get_credentials();

		if (!is_file($local_path)) {
			return ['success' => false, 'bytes' => 0, 'remote_url' => '', 'error' => 'Local file not found: ' . $local_path];
		}
		$size = filesize($local_path);

		try {
			$response = S3Signer::put_file($creds, $bucket, '/' . ltrim($remote_key, '/'), $local_path);
		} catch (S3SignerException $e) {
			return ['success' => false, 'bytes' => 0, 'remote_url' => '', 'error' => 'Configuration error: ' . $e->getMessage()];
		} catch (Exception $e) {
			return ['success' => false, 'bytes' => 0, 'remote_url' => '', 'error' => $e->getMessage()];
		}

		if ($response['status'] === 200) {
			return [
				'success' => true,
				'bytes' => $size,
				'remote_url' => rtrim($creds['endpoint'], '/') . '/' . $bucket . '/' . ltrim($remote_key, '/'),
				'error' => null,
			];
		}

		$err = S3Signer::extract_error($response['body']) ?: ('HTTP ' . $response['status']);
		return ['success' => false, 'bytes' => 0, 'remote_url' => '', 'error' => $err];
	}

	/**
	 * Delete a file from the target bucket.
	 * Returns ['success' => bool, 'error' => ?string].
	 */
	public static function delete($target, $remote_key) {
		$bucket = $target->get('bkt_bucket');
		$creds = $target->get_credentials();

		try {
			$response = S3Signer::delete($creds, $bucket, '/' . ltrim($remote_key, '/'));
		} catch (S3SignerException $e) {
			return ['success' => false, 'error' => 'Configuration error: ' . $e->getMessage()];
		} catch (Exception $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}

		if ($response['status'] === 204 || $response['status'] === 200) {
			return ['success' => true, 'error' => null];
		}

		$err = S3Signer::extract_error($response['body']) ?: ('HTTP ' . $response['status']);
		return ['success' => false, 'error' => $err];
	}
}
?>
