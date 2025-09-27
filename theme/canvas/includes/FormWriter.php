<?php
// PathHelper is always available - no need to require it
// FormWriterBootstrap is the Bootstrap-based FormWriter
require_once(PathHelper::getIncludePath('includes/FormWriterBootstrap.php'));

// Canvas theme FormWriter extends the Bootstrap FormWriter
// No modifications for now - just inheriting all Bootstrap form functionality
class FormWriter extends FormWriterBootstrap {
    // Inherits all Bootstrap form styling from FormWriterBootstrap
    // Can override specific methods here if needed for Canvas theme customization
}
?>