<?php
// Core files (PathHelper, Globalvars, SessionControl) are guaranteed available
PathHelper::requireOnce('includes/FormWriterTailwind.php');

/**
 * FormWriter implementation for Galactic Tribune theme
 * Uses Tailwind CSS styling via FormWriterTailwind
 */
class FormWriter extends FormWriterTailwind {
    // Uses Tailwind FormWriter functionality
    // Can add theme-specific form methods here if needed
}