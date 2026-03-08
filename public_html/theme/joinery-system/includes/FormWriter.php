<?php
// Load the Bootstrap FormWriter v2 implementation
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

// joinery-system reuses the Bootstrap FormWriter — our CSS handles Bootstrap form classes
class FormWriter extends FormWriterV2Bootstrap {
    // Override form classes to use our CSS names if needed in future
}
?>
