<?php
/**
 * TargetLister — enumerate objects under a BackupTarget's configured prefix.
 *
 * Uses S3Signer against the target's S3-compatible endpoint. All providers
 * (including Backblaze B2 via its S3-compat endpoint) go through one code
 * path: paginated GET list-objects-v2 with the configured prefix.
 *
 * Return shape: ['success' => bool, 'files' => [...], 'truncated' => bool, 'error' => ?string]
 * Each file: ['key' => full-path, 'size' => bytes, 'modified' => ISO8601 string]
 *
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/S3Signer.php'));

class TargetLister {

	public static function list_files($target, $max = 500) {
		$bucket = $target->get('bkt_bucket');
		$prefix = rtrim($target->get('bkt_path_prefix') ?: 'joinery-backups', '/') . '/';
		$creds = $target->get_credentials();

		if (empty($bucket)) {
			return ['success' => false, 'files' => [], 'truncated' => false, 'error' => 'No bucket configured.'];
		}

		$files = [];
		$continuation = null;
		$truncated = false;

		try {
			while (count($files) < $max) {
				$page_size = min(1000, $max - count($files));
				$params = [
					'list-type' => '2',
					'max-keys' => (string)$page_size,
					'prefix' => $prefix,
				];
				if ($continuation !== null) {
					$params['continuation-token'] = $continuation;
				}

				$response = S3Signer::get($creds, $bucket, '/', $params);

				if ($response['status'] !== 200) {
					$err = S3Signer::extract_error($response['body']) ?: ('HTTP ' . $response['status']);
					return ['success' => false, 'files' => $files, 'truncated' => false, 'error' => $err];
				}

				$xml = simplexml_load_string($response['body']);
				if ($xml === false) {
					return ['success' => false, 'files' => $files, 'truncated' => false, 'error' => 'Could not parse listing XML.'];
				}

				foreach ($xml->Contents as $c) {
					$files[] = [
						'key' => (string)$c->Key,
						'size' => (int)$c->Size,
						'modified' => (string)$c->LastModified,
					];
				}

				$is_truncated = ((string)$xml->IsTruncated) === 'true';
				if (!$is_truncated) break;
				$continuation = (string)$xml->NextContinuationToken;
				if (count($files) >= $max) { $truncated = true; break; }
			}
		} catch (S3SignerException $e) {
			return ['success' => false, 'files' => $files, 'truncated' => false, 'error' => 'Configuration error: ' . $e->getMessage()];
		} catch (Exception $e) {
			return ['success' => false, 'files' => $files, 'truncated' => false, 'error' => $e->getMessage()];
		}

		return ['success' => true, 'files' => $files, 'truncated' => $truncated, 'error' => null];
	}
}
?>
