<?php
/**
 * ReapTestCloud - deletes test servers and volumes past their age on a cloud
 * account marked disposable (specs/test_cloud_account_and_prod_management.md
 * WP3). The work and the safety catch are TestCloudCleanup's.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class ReapTestCloud implements ScheduledTaskInterface {

	public function run(array $config) {
		return TestCloudCleanup::run();
	}
}
