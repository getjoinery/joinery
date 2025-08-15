<?php
// theme/sassa/serve.php - RouteHelper format routes for sassa theme

$routes = [
    'dynamic' => [
        // ControlD plugin routes (moved from plugin)
        '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit'],
        '/profile/filters_edit' => ['view' => 'views/profile/ctldfilters_edit'],
        '/profile/devices' => ['view' => 'views/profile/ctlddevices'],
        '/profile/rules' => ['view' => 'views/profile/ctldrules'],
        '/profile/ctld_activation' => ['view' => 'views/profile/ctld_activation'],
        '/pricing' => ['view' => 'views/pricing'],
        
        // Items plugin model-based route (moved from plugin)
        '/item/{slug}' => [
            'model' => 'Item',
            'model_file' => 'plugins/items/data/items_class',
        ],
        
        // Additional sassa-specific routes
        '/forms_example' => ['view' => 'views/forms_example'],
    ],
    
    'custom' => [
        // Items plugin routes (moved from plugin)
        '/items' => function($params, $settings, $session, $template_directory) {
            if($params[1] && $params[1] != 'tag') return false;
            return ThemeHelper::includeThemeFile('views/items.php');
        },
    ],
];