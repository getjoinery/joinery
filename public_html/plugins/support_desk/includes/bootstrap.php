<?php
/**
 * Support Desk bootstrap — loaded once per request while the plugin is active.
 * Counts the threads waiting on a person beside the admin menu entry.
 *
 * @version 0.1.0
 */
AdminMenuCounts::register('support-desk', array('SupportThread', 'waitingCount'));
