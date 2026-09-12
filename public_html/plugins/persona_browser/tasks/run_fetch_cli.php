<?php
// On-demand fetch runner — invoked in the background by the feed page's
// "Fetch now" button, and usable by hand for debugging. CLI only.
if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }

// A CLI entry point has no autoloader yet, so it boots the platform the way
// the other CLI runners do (plugins/joinery_ai/cli/*): relative to itself.
require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/tasks/FetchFeedTask.php'));

echo FetchFeedTask::fetch('facebook') . "\n";
