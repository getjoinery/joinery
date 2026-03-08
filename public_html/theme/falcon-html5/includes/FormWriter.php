<?php
// Load the Bootstrap FormWriter v2 implementation (same as falcon theme)
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

// falcon-html5 reuses the Bootstrap FormWriter — our CSS handles Bootstrap form classes
class FormWriter extends FormWriterV2Bootstrap {
    // Override form classes to use our CSS names if needed in future
}
?>
