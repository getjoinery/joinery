<?php
// PathHelper is always available - no need to require it
// FormWriterV2Bootstrap is the FormWriter v2 Bootstrap implementation
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

// Canvas theme FormWriter extends the Bootstrap v2 FormWriter
// No modifications for now - just inheriting all Bootstrap form functionality
class FormWriter extends FormWriterV2Bootstrap {
    // Inherits all Bootstrap form styling from FormWriterV2Bootstrap
    // Can override specific methods here if needed for Canvas theme customization
}
?>