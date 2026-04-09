<?php
if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	die('This script can only be run from the command line.');
}

require_once(__DIR__ . '/../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/ComposerValidator.php'));

$validator = new ComposerValidator();
if (!$validator->installIfNeeded()) {
    echo $validator->getFormattedOutput();
    exit(1);
} else {
    echo $validator->getFormattedOutput();
    exit(0);
}
?>