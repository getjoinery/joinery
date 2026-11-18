<?php
/**
 * Support Desk bootstrap — loaded once per request while the plugin is active.
 * Counts the threads waiting on a person beside the admin menu entry.
 *
 * The ask page is quiet for the setup wizard: a person looking for help is
 * not sent to finish setting up their account first.
 *
 * @version 0.2.0 - /support_desk/ask is quiet for the setup wizard (walkthrough re-run B1)
 * @version 0.1.0
 */
AdminMenuCounts::register('support-desk', array('SupportThread', 'waitingCount'));
SetupSteps::quietOn('/support_desk/ask');
