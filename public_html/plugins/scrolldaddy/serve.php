<?php
// Plugin routes - will be merged with theme routes in the hybrid system
$routes = [
    'dynamic' => [
        // Main ScrollDaddy dashboard
        '/scrolldaddy' => [
            'view' => 'views/index',
            'plugin_specify' => 'scrolldaddy'
        ],
        // Profile management routes
        '/profile/device_edit' => [
            'view' => 'views/profile/device_edit',
            'plugin_specify' => 'scrolldaddy'
        ],
        '/profile/filters_edit' => [
            'view' => 'views/profile/filters_edit',
            'plugin_specify' => 'scrolldaddy'
        ],
        '/profile/devices' => [
            'view' => 'views/profile/devices',
            'plugin_specify' => 'scrolldaddy'
        ],
        '/profile/rules' => [
            'view' => 'views/profile/rules',
            'plugin_specify' => 'scrolldaddy'
        ],
        '/profile/activation' => [
            'view' => 'views/profile/activation',
            'plugin_specify' => 'scrolldaddy'
        ],
        // Public pages
        '/pricing' => [
            'view' => 'views/pricing',
            'plugin_specify' => 'scrolldaddy'
        ],
    ],
    'static' => [
        '/scrolldaddy/assets/*' => [
            'path' => 'plugins/scrolldaddy/assets/{path}',
            'cache' => 86400
        ]
    ]
];
