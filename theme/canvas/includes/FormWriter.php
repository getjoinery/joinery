<?php
// PathHelper is always available - no need to require it
// FormWriterMasterBootstrap is the Bootstrap-based FormWriter
PathHelper::requireOnce('includes/FormWriterMasterBootstrap.php');

// Canvas theme FormWriter extends the Bootstrap FormWriter
// No modifications for now - just inheriting all Bootstrap form functionality
class FormWriter extends FormWriterMasterBootstrap {
    // Inherits all Bootstrap form styling from FormWriterMasterBootstrap
    // Can override specific methods here if needed for Canvas theme customization
}
?>