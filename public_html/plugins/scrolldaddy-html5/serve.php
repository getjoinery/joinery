<?php
// Plugin routes - will be merged with theme routes in the hybrid system
$routes = [
    'dynamic' => [
        // Main ScrollDaddy dashboard
        '/scrolldaddy' => [
            'view' => 'views/index',
            'plugin_specify' => 'scrolldaddy-html5'
        ],
        // Profile management routes
        '/profile/device_edit' => [
            'view' => 'views/profile/device_edit',
            'plugin_specify' => 'scrolldaddy-html5'
        ],
        '/profile/filters_edit' => [
            'view' => 'views/profile/filters_edit',
            'plugin_specify' => 'scrolldaddy-html5'
        ],
        '/profile/devices' => [
            'view' => 'views/profile/devices',
            'plugin_specify' => 'scrolldaddy-html5'
        ],
        '/profile/rules' => [
            'view' => 'views/profile/rules',
            'plugin_specify' => 'scrolldaddy-html5'
        ],
        '/profile/activation' => [
            'view' => 'views/profile/activation',
            'plugin_specify' => 'scrolldaddy-html5'
        ],
        // Public pages
        '/pricing' => [
            'view' => 'views/pricing',
            'plugin_specify' => 'scrolldaddy-html5'
        ],
    ],
    'static' => [
        '/scrolldaddy/assets/*' => [
            'path' => 'plugins/scrolldaddy-html5/assets/{path}',
            'cache' => 86400
        ]
    ]
];
