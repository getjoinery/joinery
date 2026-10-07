<?php
/**
 * IncidentSourceTestCloudCleanup — plane:test_cloud_cleanup: the test-account
 * cleanup is on and its last run deleted nothing because the safety catch
 * failed or the account could not be listed
 * (specs/test_cloud_account_and_prod_management.md WP3). Raised on this
 * management node's own node; cleared when a run passes or the task is off.
 *
 * @version 1.0
 */
class IncidentSourceTestCloudCleanup implements IncidentSource {

	const NAME = 'plane:test_cloud_cleanup';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!ReleaseLogWatch::isSelf($node)) {
			return null;
		}
		return TestCloudCleanup::condition(TestCloudCleanup::state(), TestCloudCleanup::taskRow());
	}

	public function cleared_text(ManagedNode $node): string {
		return 'The test-account cleanup runs again, or is turned off.';
	}
}
