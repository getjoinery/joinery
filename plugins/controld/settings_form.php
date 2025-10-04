<?php
// ControlD plugin settings
// This file is included within admin_settings.php context
// $formwriter, $settings, and $session are already available

// IMPORTANT: All settings MUST be prefixed with your plugin name
// to avoid conflicts with other plugins and core settings.
// Pattern: {plugin_name}_{setting_name}

echo '<p>Configure your ControlD integration settings below.</p>';
echo $formwriter->textinput("ControlD API Key", 'controld_key', '', 20,
    $settings->get_setting('controld_key'),
    "Get your API key from ControlD dashboard", 255, "");
?>
