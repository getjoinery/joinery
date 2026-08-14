<?php
// On-demand fetch runner — invoked in the background by the feed page's
// "Fetch now" button, and usable by hand for debugging. CLI only.
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }

require_once('/var/www/html/joinerytest/public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/tasks/FetchFeedTask.php'));

echo FetchFeedTask::fetch('facebook') . "\n";
