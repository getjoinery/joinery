<?php
// FormWriter for Phillyzouk theme - extends Bootstrap form writer
require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));

class FormWriter extends FormWriterV2Bootstrap {
    // Inherits all form styling from FormWriterV2Bootstrap base class
    // Phillyzouk theme uses Bootstrap 5, so default Bootstrap styling applies
    // Can override specific methods here if Phillyzouk-specific form styling needed
}
?>
