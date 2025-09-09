<?php
// Plugin routes - will be merged with theme routes in the hybrid system
$routes = [
    'dynamic' => [
        // Main ControlD dashboard
        '/controld' => [
            'view' => 'views/index',
            'plugin_specify' => 'controld'
        ],
        // Profile management routes
        '/profile/device_edit' => [
            'view' => 'views/profile/ctlddevice_edit',
            'plugin_specify' => 'controld'
        ],
        '/profile/filters_edit' => [
            'view' => 'views/profile/ctldfilters_edit', 
            'plugin_specify' => 'controld'
        ],
        '/profile/devices' => [
            'view' => 'views/profile/devices',
            'plugin_specify' => 'controld'
        ],
        '/profile/rules' => [
            'view' => 'views/profile/rules',
            'plugin_specify' => 'controld'
        ],
        '/profile/ctld_activation' => [
            'view' => 'views/profile/ctld_activation',
            'plugin_specify' => 'controld'
        ],
        // Public pages
        '/pricing' => [
            'view' => 'views/pricing',
            'plugin_specify' => 'controld'
        ],
    ],
    'static' => [
        '/controld/assets/*' => [
            'path' => 'plugins/controld/assets/{path}',
            'cache' => 86400
        ]
    ]
];