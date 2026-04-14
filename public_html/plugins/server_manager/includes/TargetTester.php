<?php
/**
 * TargetTester — probe a BackupTarget's credentials and bucket.
 *
 * Uses S3Signer against the target's S3-compatible endpoint. All providers
 * (including Backblaze B2 via its S3-compat endpoint) go through the same code
 * path: a single signed GET list-objects-v2 with max-keys=1 verifies both
 * credentials and bucket accessibility.
 *
 * @version 3.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/S3Signer.php'));

class TargetTester {

	/**
	 * Test a BackupTarget. Returns ['success' => bool, 'message' => string].
	 */
	public static function test($target) {
		$bucket = $target->get('bkt_bucket');
		$creds = $target->get_credentials();

		if (empty($bucket)) {
			return ['success' => false, 'message' => 'No bucket configured.'];
		}

		try {
			$response = S3Signer::get($creds, $bucket, '/', [
				'list-type' => '2',
				'max-keys' => '1',
			]);
		} catch (S3SignerException $e) {
			return ['success' => false, 'message' => 'Configuration error: ' . $e->getMessage()];
		} catch (Exception $e) {
			return ['success' => false, 'message' => 'Test error: ' . $e->getMessage()];
		}

		if ($response['status'] === 200) {
			return ['success' => true, 'message' => 'Credentials valid and bucket "' . $bucket . '" is accessible.'];
		}
		if ($response['status'] === 403) {
			return ['success' => false, 'message' => 'Access denied (403) — credentials rejected or lack permission on bucket "' . $bucket . '".'];
		}
		if ($response['status'] === 404) {
			return ['success' => false, 'message' => 'Bucket "' . $bucket . '" not found (404).'];
		}
		if ($response['status'] === 301 || $response['status'] === 400) {
			$err = S3Signer::extract_error($response['body']);
			return ['success' => false, 'message' => 'Request rejected (HTTP ' . $response['status'] . '): ' . ($err ?: 'check region/endpoint') . '.'];
		}

		$err = S3Signer::extract_error($response['body']) ?: ('HTTP ' . $response['status']);
		return ['success' => false, 'message' => 'Test failed: ' . $err];
	}
}
?>
