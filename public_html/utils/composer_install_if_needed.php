<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/ComposerValidator.php');

$validator = new ComposerValidator();
if (!$validator->installIfNeeded()) {
    echo $validator->getFormattedOutput();
    exit(1);
} else {
    echo $validator->getFormattedOutput();
    exit(0);
}
?>