<?php
/**
 * Bug Reports plugin bootstrap — loaded once per request while the plugin is
 * active. Registers the admin notice that says new reports are waiting.
 *
 * @version 1.0.0
 */
AdminNotices::register('bug_reports_new', array('ReceivedBugReport', 'admin_notice'));
