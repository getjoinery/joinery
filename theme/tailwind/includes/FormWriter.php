<?php
/**
 * FormWriter for Tailwind theme
 *
 * This file is required for all themes to enable direct FormWriter instantiation.
 * The theme can customize FormWriter behavior by overriding methods here.
 */

require_once(PathHelper::getIncludePath('includes/FormWriterTailwind.php'));

class FormWriter extends FormWriterTailwind {
    // Theme-specific FormWriter customizations can be added here
}
