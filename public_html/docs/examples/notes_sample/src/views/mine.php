<?php
/** A member's page: the greeting setting, and the member's own unread notifications (an own-rows grant). */
$greeting = (string)Globalvars::get_instance()->get_setting('greeting');
// count_all() is one ask; count() would read every row to count them.
$unread = (new MultiNotification(array('is_read' => false)))->count_all();
echo '<h2>' . htmlspecialchars($greeting) . '</h2>';
echo '<p>You have ' . (int)$unread . ' unread notifications.</p>';
